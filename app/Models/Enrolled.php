<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Enrolled extends Model
{
    protected $table = 'tbl_enrolled';

    // D2: admin all / educator ownership / student own enrollments.
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->hasRole('admin')) {
            return $query;
        }

        // Task 31: educator ownership does NOT depend on the term still being active — deactivating
        // a term must not turn historical records into 404s. The active-term gate is a
        // *current-workflow* filter and belongs only on the student's operational lists.
        if ($user->hasRole('educator')) {
            return $query->where($this->qualifyColumn('educator_id'), $user->id);
        }

        return $query
            ->whereHas('subject.section.academicTerm', fn ($term) => $term->where('is_active', true))
            ->where($this->qualifyColumn('student_id'), $user->id);
    }

    protected $fillable = ['student_id', 'educator_id', 'subject_id', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function educator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'educator_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }
}
