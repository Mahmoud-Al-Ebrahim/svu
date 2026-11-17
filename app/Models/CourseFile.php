<?php

namespace App\Models;

use App\Enums\FileKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CourseFile extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'kind' => FileKind::class,
            'visible' => 'boolean',
            'offering_id' => 'integer',
            'class_id' => 'integer',
            'session_id' => 'integer',
            'uploaded_by' => 'integer',
            'size_bytes' => 'integer',
            'download_count' => 'integer',
        ];
    }

    public function offering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class, 'offering_id');
    }

    public function courseClass(): BelongsTo
    {
        return $this->belongsTo(CourseClass::class, 'class_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ClassSession::class, 'session_id');
    }
}
