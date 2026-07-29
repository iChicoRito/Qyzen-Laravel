<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Assessment extends Model
{
    protected $table = 'tbl_assessments';

    // Task 19: opaque route key — URLs bind by the random uuid, never the sequential id.
    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    // D2: admin all / educator ownership / student enrollment (active tbl_enrolled
    // matching educator+subject). NOTE: no admin RLS policy on assessments in source,
    // but admin retains full app-layer access per the admin Policy.
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
            ->whereHas('academicTerm', fn ($term) => $term->where('is_active', true))
            ->whereExists(fn ($q) => $q->selectRaw('1')
                ->from('tbl_enrolled')
                ->whereColumn('tbl_enrolled.educator_id', 'tbl_assessments.educator_id')
                ->whereColumn('tbl_enrolled.subject_id', 'tbl_assessments.subject_id')
                ->where('tbl_enrolled.student_id', $user->id)
                ->where('tbl_enrolled.is_active', true));
    }

    // Task 29: archiving is a listing concern only. visibleTo deliberately does NOT apply these —
    // score reads, exports and student score history must still resolve an archived assessment.
    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('is_archived'), false);
    }

    public function scopeOnlyArchived(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('is_archived'), true);
    }

    protected $fillable = [
        'educator_id', 'subject_id', 'section_id', 'assessment_code', 'time_limit',
        'cheating_attempts', 'is_shuffle', 'is_active', 'is_archived', 'start_date', 'end_date',
        'start_time', 'end_time', 'term', 'allow_review', 'allow_hint', 'hint_count',
        'allow_retake', 'retake_count', 'pool_size',
    ];

    protected $casts = [
        'cheating_attempts' => 'integer',
        'is_shuffle' => 'boolean',
        'is_active' => 'boolean',
        'is_archived' => 'boolean',
        'start_date' => 'date',
        'end_date' => 'date',
        'allow_review' => 'boolean',
        'allow_hint' => 'boolean',
        'hint_count' => 'integer',
        'allow_retake' => 'boolean',
        'retake_count' => 'integer',
        'pool_size' => 'integer',
    ];

    public function educator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'educator_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class, 'section_id');
    }

    public function academicTerm(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class, 'term');
    }

    // Calendar event colours, keyed by subject so each subject reads as a distinct hue (prevents the
    // "everything is one blue" confusion). Fixed hexes — Metronic exposes only --primary/--destructive
    // as bare tokens, so the demo itself falls back to hardcoded colours; these read well light + dark.
    private const CALENDAR_COLORS = ['#3b82f6', '#22c55e', '#f59e0b', '#8b5cf6', '#ef4444', '#06b6d4', '#ec4899'];

    // Dashboard calendars: map an assessment window to a FullCalendar event. Uses start/end
    // datetimes so a multi-day window renders without the all-day exclusive-end off-by-one.
    public function calendarEvent(): array
    {
        $start = $this->start_date?->toDateString();
        $end = $this->end_date?->toDateString();
        $color = self::CALENDAR_COLORS[$this->subject_id % count(self::CALENDAR_COLORS)];

        return [
            'title' => $this->assessment_code ?: 'Assessment',
            'start' => $start ? ($this->start_time ? $start.'T'.$this->start_time : $start) : null,
            'end' => $end ? ($this->end_time ? $end.'T'.$this->end_time : $end) : null,
            'color' => $color, // per-subject hue for both the mini-calendar dots and the full block events
            // FullCalendar copies unknown keys into event.extendedProps. subtitle is the second line;
            // uuid builds the calendar-page detail-modal URL (opaque route key).
            'subtitle' => $this->relationLoaded('subject') ? $this->subject?->subject_name : null,
            'uuid' => $this->uuid,
        ];
    }

    // Task 51: the eligible bank-question set this assessment may randomly draw from.
    // Named distinctly from the old quizzes() (removed) so every call site was forced to be
    // touched deliberately rather than silently changing meaning.
    public function eligibleQuizzes(): BelongsToMany
    {
        return $this->belongsToMany(Quiz::class, 'tbl_assessment_question_pool', 'assessment_id', 'quiz_id')
            ->withTimestamps();
    }

    // How many questions a student will actually get: pool_size capped by what's eligible, so a
    // stale/misconfigured pool (e.g. a question deleted after save) never under/over-draws silently.
    public function effectivePoolSize(): int
    {
        return min($this->pool_size, $this->eligibleQuizzes()->count());
    }

    public function scores(): HasMany
    {
        return $this->hasMany(Score::class, 'assessment_id');
    }
}
