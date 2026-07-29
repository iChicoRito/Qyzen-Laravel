<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Quiz extends Model
{
    // Task 13: soft-delete so deleting a bank question leaves the bank/pool (global scope
    // excludes trashed from every read + new draws) but past attempts can still resolve the
    // question text/correct answer for historical review via withTrashed().
    use SoftDeletes;

    protected $table = 'tbl_quizzes';

    // D2: educator ownership / student enrollment (educator+subject). No admin policy
    // in source (questions are never browsed by admin); admins still excluded here.
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        // Task 31: educator ownership does NOT depend on the term still being active — deactivating
        // a term must not turn historical records into 404s. The active-term gate is a
        // *current-workflow* filter and belongs only on the student's operational lists.
        if ($user->hasRole('educator')) {
            return $query->where($this->qualifyColumn('educator_id'), $user->id);
        }

        if ($user->hasRole('student')) {
            return $query
                ->whereHas('subject.section.academicTerm', fn ($term) => $term->where('is_active', true))
                ->whereExists(fn ($q) => $q->selectRaw('1')
                    ->from('tbl_enrolled')
                    ->whereColumn('tbl_enrolled.educator_id', 'tbl_quizzes.educator_id')
                    ->whereColumn('tbl_enrolled.subject_id', 'tbl_quizzes.subject_id')
                    ->where('tbl_enrolled.student_id', $user->id)
                    ->where('tbl_enrolled.is_active', true));
        }

        return $query->whereRaw('1 = 0'); // admin/other: no quiz visibility
    }

    protected $fillable = [
        'subject_id', 'educator_id', 'question', 'quiz_type', 'choices', 'correct_answer', 'batch_label',
    ];

    protected $casts = ['choices' => 'array'];

    // Security invariant: correct_answer must never be serialized to a student.
    // Hiding it from array/JSON output is the model-layer guard (Stage B7 / D3.5 / H6).
    protected $hidden = ['correct_answer'];

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }

    // Task 51: which assessments have this bank question in their eligible pool.
    public function eligibleAssessments(): BelongsToMany
    {
        return $this->belongsToMany(Assessment::class, 'tbl_assessment_question_pool', 'quiz_id', 'assessment_id');
    }
}
