<?php

namespace App\Support;

use App\Models\Upload;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Turns finished uploads into stored files in two steps, so a request that uses several uploads is all-or-nothing:
 *   1. check() every upload — no side effects, so a refused file leaves the others untouched;
 *   2. claim() each one inside the caller's transaction — the file is moved at once and moved back if the
 *      transaction rolls back, so a committed record always points at a file that exists.
 */
class Uploads
{
    /** Content types refused whatever the file name says (programs, scripts, web pages). */
    private const BLOCKED_MIMES = [
        'application/x-dosexec', 'application/x-msdownload', 'application/x-msdos-program',
        'application/vnd.microsoft.portable-executable', 'application/x-executable', 'application/x-elf',
        'application/x-sharedlib', 'application/x-pie-executable', 'application/x-mach-binary',
        'application/x-sh', 'text/x-shellscript', 'application/x-php', 'text/x-php', 'application/x-httpd-php',
        'text/html', 'application/javascript', 'text/javascript',
    ];

    /** @param  list<string>  $extensions  allowed extensions */
    public static function check(string $uploadId, User $user, array $extensions, int $maxBytes, string $field = 'uploadId'): Upload
    {
        $upload = Upload::whereKey($uploadId)->where('user_id', $user->id)->where('status', 'complete')
            ->where('expires_at', '>', now())->first();
        if (! $upload) {
            throw ValidationException::withMessages([$field => __('portal.uploads.not_ready')]);
        }

        $ext = strtolower(pathinfo($upload->original_name, PATHINFO_EXTENSION));
        if (! in_array($ext, $extensions, true)) {
            throw ValidationException::withMessages([$field => __('portal.uploads.bad_type', ['types' => implode(', ', $extensions)])]);
        }
        if ($upload->size_bytes > $maxBytes) {
            throw ValidationException::withMessages([$field => __('portal.uploads.too_big', ['mb' => intdiv($maxBytes, 1024 * 1024)])]);
        }
        if (in_array(strtolower((string) $upload->mime), self::BLOCKED_MIMES, true)) {
            throw ValidationException::withMessages([$field => __('portal.uploads.blocked_content')]);
        }

        return $upload;
    }

    /**
     * Marks a checked upload as used and moves its file into $dir. Call it inside a transaction: if the transaction
     * rolls back, the file is moved back and the upload can be used again. A failed move throws, which rolls the
     * transaction back, so no record is ever saved for a file that is not there.
     *
     * @return array{path: string, name: string, size: int, mime: ?string, sha256: ?string}
     */
    public static function claim(Upload $upload, string $dir, string $field = 'uploadId'): array
    {
        $from = $upload->storage_path;
        $target = trim($dir, '/').'/'.Str::uuid().'.'.strtolower(pathinfo($upload->original_name, PATHINFO_EXTENSION));

        // Conditional update: if two requests (or the expiry purge) race for the same upload, only one wins.
        $won = Upload::whereKey($upload->id)->where('status', 'complete')->where('expires_at', '>', now())
            ->update(['status' => 'consumed', 'storage_path' => $target]);
        if (! $won) {
            throw ValidationException::withMessages([$field => __('portal.uploads.not_ready')]);
        }
        $disk = Storage::disk('local');
        if (! $disk->move($from, $target)) {
            throw new RuntimeException("Could not move upload {$upload->id} to {$target}.");
        }
        DB::afterRollBack(fn () => $disk->move($target, $from));

        return [
            'path' => $target,
            'name' => $upload->original_name,
            'size' => (int) $upload->size_bytes,
            'mime' => $upload->mime,
            'sha256' => $upload->sha256,
        ];
    }
}
