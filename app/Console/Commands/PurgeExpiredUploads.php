<?php

namespace App\Console\Commands;

use App\Models\Upload;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/** Deletes uploads that were never finished or never used before they expired. */
class PurgeExpiredUploads extends Command
{
    protected $signature = 'portal:purge-uploads';

    protected $description = 'Delete expired, unconsumed uploads';

    public function handle(): int
    {
        $purged = 0;
        foreach (Upload::whereIn('status', ['open', 'complete'])->where('expires_at', '<', now())->get() as $upload) {
            // Delete the row only if it is still expired and unused: a request claiming it right now wins instead.
            $deleted = Upload::whereKey($upload->id)->whereIn('status', ['open', 'complete'])->where('expires_at', '<', now())->delete();
            if ($deleted) {
                Storage::disk('local')->delete($upload->storage_path ?? $upload->partPath());
                $purged++;
            }
        }
        $this->info("Purged {$purged} uploads.");

        return self::SUCCESS;
    }
}
