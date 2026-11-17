<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\FileKind;
use App\Http\Controllers\Controller;
use App\Http\Resources\FileResource;
use App\Models\ClassSession;
use App\Models\CourseClass;
use App\Models\CourseFile;
use App\Models\CourseOffering;
use App\Support\Audit;
use App\Support\Uploads;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Feature 4: upload and download course files. */
class FileController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'term' => ['nullable', 'exists:terms,code'],
            'offering' => ['nullable', 'integer'],
            'class' => ['nullable', 'integer'],
            'kind' => ['nullable', Rule::enum(FileKind::class)],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $userId = $request->user()->id;

        $files = CourseFile::query()
            // Files of offerings where I teach at least one class: course-wide files, plus files of my own classes.
            ->whereIn('offering_id', CourseClass::where('tutor_id', $userId)->select('offering_id'))
            ->where(fn ($q) => $q->where(fn ($w) => $this->courseWide($w, $userId))->orWhereIn('class_id', CourseClass::where('tutor_id', $userId)->select('id')))
            ->when($request->query('term'), fn ($q, $t) => $q->whereHas('offering', fn ($o) => $o->where('term_code', $t)))
            ->when($request->query('offering'), fn ($q, $o) => $q->where('offering_id', $o))
            // A class view shows that class's files plus the files shared with every class of the same course.
            ->when($request->query('class'), fn ($q, $c) => $q
                ->where('offering_id', CourseClass::whereKey($c)->select('offering_id'))
                ->where(fn ($w) => $w->where(fn ($cw) => $this->courseWide($cw, $userId))->orWhere('class_id', $c)))
            ->when($request->query('kind'), fn ($q, $k) => $q->where('kind', $k))
            ->when($request->query('q'), fn ($q, $s) => $q->where(fn ($w) => $w->where('title', 'like', "%{$s}%")->orWhere('original_name', 'like', "%{$s}%")))
            ->with('courseClass')
            ->latest();

        return FileResource::collection($this->paginate($files, $request));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'offeringId' => ['required', 'integer', 'exists:course_offerings,id'],
            'classId' => ['nullable', 'integer'],
            'sessionId' => ['nullable', 'integer'],
            'visible' => ['boolean'],
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.uploadId' => ['required', 'uuid', 'distinct'],
            'items.*.title' => ['nullable', 'string', 'max:255'],
        ]);
        $offering = CourseOffering::findOrFail($data['offeringId']);
        $myClasses = CourseClass::where('offering_id', $offering->id)->where('tutor_id', $request->user()->id)->pluck('id');
        abort_if($myClasses->isEmpty(), 403, __('portal.not_your_class'));

        $classId = isset($data['classId']) ? (int) $data['classId'] : null;
        if ($classId !== null && ! $myClasses->contains($classId)) {
            throw ValidationException::withMessages(['classId' => __('portal.not_your_class')]);
        }
        $sessionId = isset($data['sessionId']) ? (int) $data['sessionId'] : null;
        if ($sessionId !== null) {
            $session = ClassSession::whereKey($sessionId)->whereIn('class_id', $myClasses)->first();
            if (! $session || ($classId !== null && $session->class_id !== $classId)) {
                throw ValidationException::withMessages(['sessionId' => __('portal.files.bad_session')]);
            }
            // A session belongs to one class, so its files do too (not to every class of the course).
            $classId = $session->class_id;
        }

        // Check every file before storing any, so one refused file doesn't leave the others half-saved.
        $uploads = collect($data['items'])->map(fn (array $item, int $i) => Uploads::check(
            $item['uploadId'], $request->user(),
            config('portal.uploads.file_extensions'), config('portal.uploads.max_file_bytes'), "items.{$i}.uploadId",
        ));

        $files = DB::transaction(fn () => collect($data['items'])->map(function (array $item, int $i) use ($request, $offering, $classId, $sessionId, $data, $uploads) {
            $stored = Uploads::claim($uploads[$i], "files/{$offering->id}", "items.{$i}.uploadId");

            return CourseFile::create([
                'offering_id' => $offering->id,
                'class_id' => $classId,
                'session_id' => $sessionId,
                'title' => ($item['title'] ?? null) ?: pathinfo($stored['name'], PATHINFO_FILENAME),
                'original_name' => $stored['name'],
                'storage_path' => $stored['path'],
                'mime' => $stored['mime'],
                'size_bytes' => $stored['size'],
                'sha256' => $stored['sha256'],
                'kind' => FileKind::fromFileName($stored['name']),
                'visible' => $data['visible'] ?? true,
                'uploaded_by' => $request->user()->id,
            ]);
        }));
        $files->each(fn ($f) => Audit::log('file.uploaded', $f));

        return response()->json(FileResource::collection($files->each->load('courseClass')), 201);
    }

    public function update(Request $request, CourseFile $file): FileResource
    {
        $this->authorizeFile($request, $file);
        abort_unless($file->uploaded_by === $request->user()->id, 403, __('portal.files.not_uploader'));
        $data = $request->validate([
            'visible' => ['sometimes', 'boolean'],
            'title' => ['sometimes', 'string', 'max:255'],
        ]);
        $file->update($data);
        Audit::log('file.updated', $file, $data);

        return new FileResource($file->load('courseClass'));
    }

    public function destroy(Request $request, CourseFile $file): JsonResponse
    {
        $this->authorizeFile($request, $file);
        abort_unless($file->uploaded_by === $request->user()->id, 403, __('portal.files.not_uploader'));
        $file->delete();
        Audit::log('file.deleted', $file);

        return response()->json(null, 204);
    }

    /** Redirects to a short-lived signed URL; the file itself is never in the public web root. */
    public function download(Request $request, CourseFile $file): RedirectResponse
    {
        $this->authorizeFile($request, $file);
        $file->increment('download_count');

        return redirect()->away(URL::temporarySignedRoute(
            'files.raw', now()->addMinutes(config('portal.download_url_minutes')), ['file' => $file->id],
        ));
    }

    /** Target of the signed URL: streams the file under its original name. The signature is the authorization. */
    public function raw(CourseFile $file): StreamedResponse
    {
        return Storage::disk($file->storage_disk)->download($file->storage_path, $file->original_name);
    }

    /**
     * Files for "all classes" of a course. Normally every tutor of the course sees them (as at SVU); during the
     * doctors' trial each tutor works in private demo classes, so they are limited to their uploader.
     */
    private function courseWide($query, int $userId)
    {
        return $query->whereNull('class_id')->when(! config('portal.files.share_course_wide'), fn ($q) => $q->where('uploaded_by', $userId));
    }

    private function authorizeFile(Request $request, CourseFile $file): void
    {
        $mine = CourseClass::where('tutor_id', $request->user()->id)->where('offering_id', $file->offering_id);
        $allowed = $file->class_id === null
            ? $mine->exists() && (config('portal.files.share_course_wide') || $file->uploaded_by === $request->user()->id)
            : $mine->whereKey($file->class_id)->exists();
        abort_unless($allowed, 403, __('portal.not_your_class'));
    }
}
