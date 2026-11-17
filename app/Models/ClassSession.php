<?php

namespace App\Models;

use App\Enums\SessionKind;
use App\Enums\SessionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ClassSession extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'kind' => SessionKind::class,
            'status' => SessionStatus::class,
            'starts_at' => 'datetime',
            'reminded_at' => 'datetime',
            'reported_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'paid_at' => 'datetime',
            'notify_students' => 'boolean',
            'record_auto' => 'boolean',
            'class_id' => 'integer',
            'duration_min' => 'integer',
        ];
    }

    public function courseClass(): BelongsTo
    {
        return $this->belongsTo(CourseClass::class, 'class_id');
    }

    /** The tutor who scheduled or uploaded the session; stays the same if the class later gets another tutor. */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function files(): HasMany
    {
        return $this->hasMany(CourseFile::class, 'session_id');
    }

    public function endsAt(): Carbon
    {
        return $this->starts_at->copy()->addMinutes($this->duration_min);
    }

    /**
     * Sessions overlapping [start, start + minutes). Sessions are at most a few hours long, so candidates
     * are narrowed in SQL to the surrounding day and the exact overlap is checked in PHP (portable across DBs).
     *
     * @param  Builder<self>  $scope  e.g. one class, or all classes of a tutor
     * @return Collection<int, self>
     */
    public static function overlapping(Builder $scope, Carbon $start, int $minutes, ?int $exceptId = null)
    {
        $end = $start->copy()->addMinutes($minutes);

        return $scope
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->where('starts_at', '<', $end)
            ->where('starts_at', '>', $start->copy()->subDay())
            ->get()
            ->filter(fn (self $s) => $s->endsAt()->gt($start))
            ->values();
    }
}
