<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A course given in one term — what the frontend calls a `Course`. */
class CourseOffering extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class, 'term_code');
    }

    public function classes(): HasMany
    {
        return $this->hasMany(CourseClass::class, 'offering_id')->orderBy('code');
    }
}
