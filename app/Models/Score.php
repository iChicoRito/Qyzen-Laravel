<?php

namespace App\Models;

use App\Models\Concerns\HasAcademicTerm;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class Score extends Model
{
    use HasAcademicTerm;
    use SoftDeletes;

    protected $table = 'tbl_scores';

    // Dashboard "quiz activity" trend: submitted-count per ISO week, grouped in PHP so it works on
    // both MySQL (app) and SQLite (tests) — DATE_FORMAT/strftime differ across drivers.
    // ponytail: grouping in PHP over the taken_at column; push into SQL only if the score volume
    //   ever makes the pluck heavy (add a cache first).
    public static function weeklyTrend(Builder $query): array
    {
        $byWeek = [];
        foreach ($query->whereNotNull('taken_at')->orderBy('taken_at')->pluck('taken_at') as $t) {
            $key = Carbon::parse($t)->format('o-\WW'); // e.g. 2026-W27
            $byWeek[$key] = ($byWeek[$key] ?? 0) + 1;
        }

        return ['labels' => array_keys($byWeek), 'data' => array_values($byWeek)];
    }

    // A score inherits its assessment's term test (both the assessment's own term and its
    // section's), rather than naming a path of its own.
    public function scopeInActiveTerm(Builder $query): Builder
    {
        return $query->whereHas('assessment', fn (Builder $a) => $a->inActiveTerm());
    }

    // Task 19: opaque route key — URLs bind by the random uuid, never the sequential id.
    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    // D2: admin all / educator ownership / student own scores only.
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->hasRole('admin')) {
            return $query;
        }

        // Task 31: deactivating a term hides its records. Delegates to Assessment so a score is
        // hidden by BOTH the assessment's own term and its section's term — the two can disagree.
        $query->inActiveTerm();

        if ($user->hasRole('educator')) {
            return $query->where($this->qualifyColumn('educator_id'), $user->id);
        }

        return $query->where($this->qualifyColumn('student_id'), $user->id);
    }

    protected $fillable = [
        'student_id', 'educator_id', 'assessment_id', 'subject_id', 'section_id',
        'score', 'total_questions', 'student_answer', 'warning_attempts',
        'status', 'is_passed', 'taken_at', 'submitted_at', 'drawn_quiz_ids', 'hints_used',
    ];

    protected $casts = [
        'score' => 'integer',
        'total_questions' => 'integer',
        'student_answer' => 'array',
        'warning_attempts' => 'integer',
        'is_passed' => 'boolean',
        'taken_at' => 'datetime',
        'submitted_at' => 'datetime',
        'drawn_quiz_ids' => 'array',
        'hints_used' => 'integer',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class, 'assessment_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class, 'section_id');
    }
}
