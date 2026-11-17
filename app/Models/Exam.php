<?php

namespace App\Models;

use App\Enums\ExamStatus;
use App\Enums\ExamType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class Exam extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => ExamType::class,
            'status' => ExamStatus::class,
            'date' => 'date:Y-m-d',
            'class_id' => 'integer',
            'duration_min' => 'integer',
        ];
    }

    public function courseClass(): BelongsTo
    {
        return $this->belongsTo(CourseClass::class, 'class_id');
    }

    /** The tutor who asked for the exam; stays the same if the class later gets another tutor. */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function center(): BelongsTo
    {
        return $this->belongsTo(ExamCenter::class, 'center_id');
    }

    /** Start as an absolute moment; exam times are entered in Damascus time. */
    public function startsAt(): Carbon
    {
        return Carbon::parse($this->date->format('Y-m-d').' '.$this->start_time, config('portal.timezone'));
    }

    public function endsAt(): Carbon
    {
        return $this->startsAt()->addMinutes($this->duration_min);
    }

    public function endTime(): string
    {
        return $this->endsAt()->format('H:i');
    }
}
