<?php

namespace App\Jobs;

use App\Mail\ClassMessageMail;
use App\Models\SentEmail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends a tutor's message in BCC batches so students don't see each other's addresses.
 *
 * Progress is saved after every batch (processed_batches, failed_count). If the worker crashes or times out
 * part-way, the retry skips the batches already handled, so no student gets the message twice. A batch the
 * mail server refuses is counted as failed and the others still go out.
 */
class SendClassEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** Seconds per attempt; a slow mail server must not leave the job hanging. */
    public int $timeout = 300;

    /** Seconds to wait before the second and third attempts. */
    public array $backoff = [30, 120];

    public function __construct(public int $emailId) {}

    public function handle(): void
    {
        $email = SentEmail::with(['sender', 'courseClass', 'attachments'])->find($this->emailId);
        if (! $email || in_array($email->status, ['sent', 'partially_failed', 'failed'], true)) {
            return;
        }
        if ($email->recipient_type === 'class' && ! $email->courseClass) {
            $email->update(['status' => 'failed', 'failed_count' => $email->recipients_count, 'sent_at' => now()]);

            return;
        }

        // A stable order, so batch N contains the same students on a retry.
        $addresses = $email->recipient_type === 'class'
            ? $email->courseClass->students()->orderBy('students.id')->pluck('email')->filter()->values()
            : collect([$email->student_address]);
        if ($email->processed_batches === 0) {
            $email->update(['recipients_count' => $addresses->count()]); // the class roster may have changed since queuing
        }

        foreach ($addresses->chunk(config('portal.email.batch_size'))->values() as $n => $batch) {
            if ($n < $email->processed_batches) {
                continue; // handled by an earlier attempt
            }
            $failed = 0;
            try {
                $pending = $email->recipient_type === 'class' ? Mail::bcc($batch->all()) : Mail::to($batch->all());
                if ($email->cc_sender && $n === 0) {
                    $pending->cc($email->sender->email);
                }
                $pending->send(new ClassMessageMail($email));
            } catch (Throwable $e) {
                $failed = $batch->count();
                Log::warning('Class e-mail batch failed', ['email' => $email->id, 'batch' => $n, 'error' => $e->getMessage()]);
            }
            $email->update(['processed_batches' => $n + 1, 'failed_count' => $email->failed_count + $failed]);
        }

        $email->update([
            'status' => match (true) {
                $email->failed_count === 0 => 'sent',
                $email->failed_count < $email->recipients_count => 'partially_failed',
                default => 'failed',
            },
            'sent_at' => now(),
        ]);
    }

    /** Called when every attempt has failed (crash, timeout): the tutor sees "failed" instead of "sending" forever. */
    public function failed(?Throwable $e): void
    {
        $email = SentEmail::find($this->emailId);
        if (! $email || $email->status !== 'queued') {
            return;
        }
        $handled = $email->processed_batches * config('portal.email.batch_size');
        $unsent = max(0, $email->recipients_count - $handled) + $email->failed_count;
        $email->update([
            'failed_count' => min($unsent, $email->recipients_count),
            'status' => $unsent >= $email->recipients_count ? 'failed' : 'partially_failed',
            'sent_at' => now(),
        ]);
        Log::error('Class e-mail gave up', ['email' => $email->id, 'error' => $e?->getMessage()]);
    }
}
