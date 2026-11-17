<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SentEmail extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'cc_sender' => 'boolean',
            'sent_at' => 'datetime',
            'recipients_count' => 'integer',
            'failed_count' => 'integer',
            'processed_batches' => 'integer',
        ];
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function courseClass(): BelongsTo
    {
        return $this->belongsTo(CourseClass::class, 'class_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(EmailAttachment::class, 'email_id');
    }
}
