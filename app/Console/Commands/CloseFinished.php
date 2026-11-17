<?php

namespace App\Console\Commands;

use App\Support\Lifecycle;
use Illuminate\Console\Command;

/** Moves sessions and exams whose end time has passed to their "held" status. */
class CloseFinished extends Command
{
    protected $signature = 'portal:close-finished';

    protected $description = 'Mark ended sessions as notReported and ended approved exams as done';

    public function handle(): int
    {
        $closed = Lifecycle::closeEnded();
        $this->info("Closed {$closed['sessions']} sessions and {$closed['exams']} exams.");

        return self::SUCCESS;
    }
}
