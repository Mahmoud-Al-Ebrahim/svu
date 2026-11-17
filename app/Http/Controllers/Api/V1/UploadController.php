<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Upload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Chunked, resumable uploads shared by files, session recordings and e-mail attachments.
 * Flow: POST /uploads → PUT /uploads/{id}/chunks/{n} (in order) → POST /uploads/{id}/complete.
 * Small files can use POST /uploads/direct instead.
 */
class UploadController extends Controller
{
    public function init(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fileName' => ['required', 'string', 'max:255'],
            'size' => ['required', 'integer', 'min:1', 'max:'.config('portal.uploads.max_recording_bytes')],
        ]);
        $upload = Upload::create([
            'user_id' => $request->user()->id,
            'original_name' => basename($data['fileName']),
            'size_bytes' => $data['size'],
            'chunk_size' => config('portal.uploads.chunk_size'),
            'expires_at' => $this->expiry(),
        ]);
        Storage::disk('local')->put($upload->partPath(), '');

        return response()->json($this->state($upload), 201);
    }

    /** Current progress, so an interrupted client knows which chunk to send next. */
    public function show(Request $request, Upload $upload): JsonResponse
    {
        $this->own($request, $upload);

        return response()->json($this->state($upload));
    }

    public function chunk(Request $request, Upload $upload, int $index): JsonResponse
    {
        $this->own($request, $upload);
        $bytes = $request->getContent();

        // The row lock makes a retried piece wait for the first attempt, then get a 409 instead of being appended twice.
        $upload = DB::transaction(function () use ($upload, $index, $bytes) {
            $upload = Upload::whereKey($upload->id)->lockForUpdate()->firstOrFail();
            abort_unless($upload->status === 'open', 409, __('portal.uploads.closed'));
            abort_if($upload->expires_at->isPast(), 410, __('portal.uploads.expired'));

            $expected = intdiv($upload->received_bytes, $upload->chunk_size);
            abort_unless($index === $expected, 409, __('portal.uploads.wrong_chunk', ['n' => $expected]));

            $remaining = $upload->size_bytes - $upload->received_bytes;
            $isLast = $remaining <= $upload->chunk_size;
            $size = strlen($bytes);
            abort_unless($size > 0 && ($isLast ? $size === $remaining : $size === $upload->chunk_size), 422, __('portal.uploads.bad_chunk'));

            $fh = fopen(Storage::disk('local')->path($upload->partPath()), 'ab');
            fwrite($fh, $bytes);
            fclose($fh);
            // Each piece keeps the upload alive, so a long recording upload never expires half-way.
            $upload->increment('received_bytes', $size, ['expires_at' => $this->expiry()]);

            return $upload;
        });

        return response()->json($this->state($upload));
    }

    public function complete(Request $request, Upload $upload): JsonResponse
    {
        $this->own($request, $upload);

        $upload = DB::transaction(function () use ($upload) {
            $upload = Upload::whereKey($upload->id)->lockForUpdate()->firstOrFail();
            abort_unless($upload->status === 'open', 409, __('portal.uploads.closed'));
            abort_if($upload->expires_at->isPast(), 410, __('portal.uploads.expired'));
            abort_unless($upload->received_bytes === $upload->size_bytes, 422, __('portal.uploads.incomplete'));

            $path = Storage::disk('local')->path($upload->partPath());
            $upload->update([
                'status' => 'complete',
                'storage_path' => $upload->partPath(),
                'sha256' => hash_file('sha256', $path),
                'mime' => mime_content_type($path) ?: null,
                'expires_at' => $this->expiry(), // time to fill in and send the form that uses it
            ]);

            return $upload;
        });

        return response()->json($this->state($upload));
    }

    public function direct(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:'.config('portal.uploads.direct_max_kb')]]);
        $file = $request->file('file');
        $upload = Upload::create([
            'user_id' => $request->user()->id,
            'original_name' => basename($file->getClientOriginalName()),
            'size_bytes' => $file->getSize(),
            'received_bytes' => $file->getSize(),
            'chunk_size' => $file->getSize(),
            'status' => 'complete',
            'sha256' => hash_file('sha256', $file->getRealPath()),
            'expires_at' => $this->expiry(),
        ]);
        $path = $file->storeAs('uploads/parts', "{$upload->id}.part", 'local');
        // Sniff the stored bytes; the browser's (and the file name's) idea of the type isn't trusted.
        $upload->update(['storage_path' => $path, 'mime' => mime_content_type(Storage::disk('local')->path($path)) ?: null]);

        return response()->json($this->state($upload), 201);
    }

    private function expiry(): Carbon
    {
        return now()->addHours(config('portal.uploads.expires_hours'));
    }

    private function own(Request $request, Upload $upload): void
    {
        abort_unless($upload->user_id === $request->user()->id, 404);
    }

    private function state(Upload $upload): array
    {
        return [
            'uploadId' => $upload->id,
            'fileName' => $upload->original_name,
            'size' => $upload->size_bytes,
            'received' => $upload->received_bytes,
            'chunkSize' => $upload->chunk_size,
            'nextChunk' => intdiv($upload->received_bytes, $upload->chunk_size),
            'status' => $upload->status,
        ];
    }
}
