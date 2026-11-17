<?php

namespace App\Console\Commands;

use App\Models\CourseFile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Deleting a file only hides it (soft delete), so a mistake can be undone from the database. After
 * `portal.uploads.keep_deleted_days` the record is removed for good and the bytes are deleted from disk.
 */
class PurgeDeletedFiles extends Command
{
    protected $signature = 'portal:purge-deleted-files';

    protected $description = 'Permanently remove files that were deleted more than the configured number of days ago';

    public function handle(): int
    {
        $cutoff = now()->subDays(config('portal.uploads.keep_deleted_days'));
        $purged = 0;

        CourseFile::onlyTrashed()->where('deleted_at', '<', $cutoff)->chunkById(200, function ($files) use (&$purged) {
            foreach ($files as $file) {
                Storage::disk($file->storage_disk)->delete($file->storage_path);
                $file->forceDelete();
                $purged++;
            }
        });
        $this->info("Purged {$purged} deleted files.");

        return self::SUCCESS;
    }
}
