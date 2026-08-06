<?php

namespace App\Models;

use App\Models\Concerns\HasAcademicTerm;
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
    use HasAcademicTerm;
    use SoftDeletes;

    protected $table = 'tbl_quizzes';

    // Task 32: the tbl_quiz_subject pivot is the truth — one uploaded question may be shared with
    // several subjects, and that is what the upload/edit forms sync. The subject_id column is only
    // the creation-time primary and must NOT drive visibility or filtering.
    protected string $academicTermPath = 'subjects.section.terms';

    // ...which only holds if the pivot is never empty. Questions are also created by seeders and
    // tests that set subject_id and nothing else, so mirror the primary into the pivot on every
    // save. Callers that then sync() an explicit subject list still win.
    protected static function booted(): void
    {
        static::saved(function (self $quiz): void {
            if ($quiz->subject_id) {
                $quiz->subjects()->syncWithoutDetaching([$quiz->subject_id]);
            }
        });
    }

    // D2: educator ownership / student enrollment (educator+subject). No admin policy
    // in source (questions are never browsed by admin); admins still excluded here.
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        // Task 31: deactivating a term hides its records from educators and students alike.
        $query->inActiveTerm();

        if ($user->hasRole('educator')) {
            return $query->where($this->qualifyColumn('educator_id'), $user->id);
        }

        if ($user->hasRole('student')) {
            // Task 32: enrollment is matched against the pivot, so a question shared across
            // subjects reaches every one it was assigned to.
            return $query->whereExists(fn ($q) => $q->selectRaw('1')
                ->from('tbl_enrolled')
                ->join('tbl_quiz_subject', 'tbl_quiz_subject.subject_id', '=', 'tbl_enrolled.subject_id')
                ->whereColumn('tbl_enrolled.educator_id', 'tbl_quizzes.educator_id')
                ->whereColumn('tbl_quiz_subject.quiz_id', 'tbl_quizzes.id')
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

    // The creation-time primary. Read subjects() when you need "which subjects is this question
    // filed under".
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }

    // Task 32: every subject this one question is shared with.
    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class, 'tbl_quiz_subject', 'quiz_id', 'subject_id')
            ->withTimestamps();
    }

    // Task 51: which assessments have this bank question in their eligible pool.
    public function eligibleAssessments(): BelongsToMany
    {
        return $this->belongsToMany(Assessment::class, 'tbl_assessment_question_pool', 'quiz_id', 'assessment_id');
    }
}
