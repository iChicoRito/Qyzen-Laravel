<?php

namespace App\Services\Ai;

use App\Models\Assessment;
use App\Models\Enrolled;
use App\Models\Score;
use App\Models\Subject;
use App\Models\User;
use App\Services\Export\ScoreRowBuilder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Task 28: the complete set of database operations the model is allowed to request.
 *
 * Three rules hold for every method here, and they are the actual security boundary:
 *
 *  1. The educator comes from the constructor (the authenticated session), never from the model's
 *     arguments, the request body, or the prompt. There is no parameter that can change whose
 *     data is read.
 *  2. Every id the model supplies is resolved through `Model::visibleTo($educator)` — the same
 *     scope the educator's own pages use. Another educator's id resolves to nothing, and returns
 *     the identical "not found" shape a genuinely missing id returns, so the assistant cannot be
 *     used to probe for the existence of records outside this educator's scope.
 *  3. Results are capped and column-limited. No method can return correct_answer, student_answer,
 *     emails, or a whole table.
 */
class EducatorDataTools
{
    private const MAX_STUDENTS = 60;

    private const MAX_ASSESSMENTS = 40;

    private const MAX_SCORES = 100;

    private const MAX_NAME_MATCHES = 5;

    /** Statuses that represent a real attempt (mirrors QuizGradingService::TERMINAL_STATUSES). */
    private const SUBMITTED = ['submitted', 'passed', 'failed'];

    public function __construct(private User $educator) {}

    /**
     * JSON-schema definitions handed to the provider. `additionalProperties: false` plus
     * integer-typed ids is what stops the model from smuggling a free-text filter into a query.
     *
     * @return array<int, array<string, mixed>>
     */
    public function definitions(): array
    {
        return [
            // Descriptions are terse on purpose: these schemas are re-sent on every round trip and
            // come straight out of the shared 8,000 tokens/minute allowance.
            $this->tool('list_classes',
                'This educator\'s classes this term: subject, section, per-class enrolled count, plus total_distinct_students. Call first to get ids. For "how many students do I have", use total_distinct_students — never add up the per-class counts.',
                [], []),

            $this->tool('list_students',
                'Students enrolled in a subject.',
                ['subject_id' => ['type' => 'integer']],
                ['subject_id']),

            // NOTE: no maxLength/minimum/maximum anywhere in these schemas. Groq validates tool
            // arguments server-side and returns a 400 that kills the whole question rather than
            // clamping — a live run died on `limit: 1000` when the educator asked for "all".
            // Bounds are enforced in PHP instead (see call()), which is the only place that can
            // be trusted anyway. The descriptions state the caps so the model asks sensibly.
            $this->tool('find_student',
                'Match a typed name to student ids. Give the name as the educator wrote it; word order does not matter. Returns all matches so you can ask which was meant.',
                ['name' => ['type' => 'string']],
                ['name']),

            $this->tool('list_assessments',
                'Assessments for a subject, optionally one section.',
                [
                    'subject_id' => ['type' => 'integer'],
                    'section_id' => ['type' => 'integer'],
                ],
                ['subject_id']),

            $this->tool('get_student_scores',
                'One student\'s best attempt per assessment: score, total questions, percentage, pass/fail.',
                [
                    'student_id' => ['type' => 'integer'],
                    'subject_id' => ['type' => 'integer'],
                    'assessment_id' => ['type' => 'integer'],
                ],
                ['student_id']),

            $this->tool('get_class_summary',
                'Class aggregate: submissions, average percentage, pass rate, highest and lowest.',
                [
                    'subject_id' => ['type' => 'integer'],
                    'assessment_id' => ['type' => 'integer'],
                ],
                ['subject_id']),

            $this->tool('get_class_scores',
                'Ranked scores for a class in one call — use this for "top scorers", "who is struggling", "best in X". Omit assessment_id to rank by average across all assessments.',
                [
                    'subject_id' => ['type' => 'integer'],
                    'assessment_id' => ['type' => 'integer'],
                    'order' => ['type' => 'string', 'enum' => ['highest', 'lowest']],
                    'limit' => ['type' => 'integer', 'description' => 'How many students to return, 1-20. Values above 20 are reduced to 20.'],
                ],
                ['subject_id']),
        ];
    }

    public function isAllowed(string $name): bool
    {
        return in_array($name, [
            'list_classes', 'list_students', 'find_student', 'list_assessments',
            'get_student_scores', 'get_class_summary', 'get_class_scores',
        ], true);
    }

    /**
     * Dispatch one allowlisted call. Arguments are coerced and validated here, on the server,
     * before they reach a query — the model's JSON is untrusted input like any other.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function call(string $name, array $args): array
    {
        if (! $this->isAllowed($name)) {
            return $this->notFound();
        }

        try {
            return match ($name) {
                'list_classes' => $this->listClasses(),
                'list_students' => $this->listStudents($this->intArg($args, 'subject_id')),
                'find_student' => $this->findStudent($this->stringArg($args, 'name')),
                'list_assessments' => $this->listAssessments(
                    $this->intArg($args, 'subject_id'),
                    $this->optionalIntArg($args, 'section_id'),
                ),
                'get_student_scores' => $this->getStudentScores(
                    $this->intArg($args, 'student_id'),
                    $this->optionalIntArg($args, 'subject_id'),
                    $this->optionalIntArg($args, 'assessment_id'),
                ),
                'get_class_summary' => $this->getClassSummary(
                    $this->intArg($args, 'subject_id'),
                    $this->optionalIntArg($args, 'assessment_id'),
                ),
                'get_class_scores' => $this->getClassScores(
                    $this->intArg($args, 'subject_id'),
                    $this->optionalIntArg($args, 'assessment_id'),
                    ($args['order'] ?? 'highest') === 'lowest' ? 'lowest' : 'highest',
                    // Default 5: a "top scorers" answer is a short table, and every extra row is
                    // completion tokens the per-minute allowance has to pay for.
                    min(20, max(1, $this->optionalIntArg($args, 'limit') ?? 5)),
                ),
            };
        } catch (ModelNotFoundException|\InvalidArgumentException) {
            // Unauthorized and genuinely-absent collapse into one indistinguishable answer.
            return $this->notFound();
        }
    }

    // ---------------------------------------------------------------- retrieval

    /** @return array<string, mixed> */
    private function listClasses(): array
    {
        $subjects = Subject::visibleTo($this->educator)
            ->with(['section:id,section_name,academic_term_id', 'section.academicTerm:id,term_name,semester'])
            ->withCount(['enrollments as enrolled_count' => fn ($q) => $q->where('tbl_enrolled.is_active', true)])
            ->orderBy('subject_name')
            ->limit(self::MAX_ASSESSMENTS)
            ->get(['id', 'subject_code', 'subject_name', 'sections_id', 'educator_id']);

        return [
            // Summing the per-class counts double-counts anyone enrolled in two of this
            // educator's subjects — a live test had a model report 265 (enrollment rows) and
            // another 225 (bad arithmetic) when the true headcount was 227. The distinct figure
            // is computed in SQL and handed over directly so the model never has to add up.
            'total_distinct_students' => Enrolled::visibleTo($this->educator)
                ->where('tbl_enrolled.is_active', true)
                ->distinct('tbl_enrolled.student_id')
                ->count('tbl_enrolled.student_id'),
            'classes' => $subjects->map(fn (Subject $s) => [
                'subject_id' => $s->id,
                'subject_code' => $s->subject_code,
                'subject_name' => $s->subject_name,
                'section_id' => $s->section?->id,
                'section_name' => $s->section?->section_name,
                'term' => $s->section?->academicTerm?->term_name,
                'enrolled_students' => (int) $s->enrolled_count,
            ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function listStudents(int $subjectId): array
    {
        $subject = $this->subject($subjectId);

        $enrolled = Enrolled::visibleTo($this->educator)
            ->where('tbl_enrolled.subject_id', $subject->id)
            ->where('tbl_enrolled.is_active', true)
            ->with('student:id,given_name,surname,user_id')
            ->limit(self::MAX_STUDENTS + 1)
            ->get();

        $truncated = $enrolled->count() > self::MAX_STUDENTS;

        return [
            'subject' => $subject->subject_name,
            'students' => $enrolled->take(self::MAX_STUDENTS)
                ->pluck('student')
                ->filter()
                ->map(fn (User $s) => $this->studentRow($s))
                ->values()->all(),
            'truncated' => $truncated,
        ];
    }

    /** @return array<string, mixed> */
    private function findStudent(string $name): array
    {
        $term = trim($name);

        if (mb_strlen($term) < 2) {
            return ['matches' => [], 'note' => 'Search term too short.'];
        }

        // Names are split across two columns ("JOHN HARVEY" + "PAQUERRA"), so matching the whole
        // phrase against each column separately can never find a student searched by full name —
        // which is exactly how an educator types one. Every word must match SOMEWHERE on the
        // record instead: word order and which column holds which part stop mattering.
        $words = array_slice(preg_split('/[\s,]+/u', $term, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 5);

        if ($words === []) {
            return ['matches' => [], 'note' => 'Search term too short.'];
        }

        $matches = Enrolled::visibleTo($this->educator)
            ->where('tbl_enrolled.is_active', true)
            ->whereHas('student', function ($q) use ($words) {
                foreach ($words as $word) {
                    $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $word).'%';

                    $q->where(fn ($w) => $w
                        ->where('given_name', 'like', $like)
                        ->orWhere('surname', 'like', $like)
                        ->orWhere('user_id', 'like', $like));
                }
            })
            ->with(['student:id,given_name,surname,user_id', 'subject:id,subject_name,sections_id', 'subject.section:id,section_name'])
            ->limit(self::MAX_STUDENTS)
            ->get();

        // One row per student, carrying the classes they appear in — that context is what lets the
        // model ask a useful clarifying question instead of guessing between two similar names.
        $byStudent = $matches->filter(fn (Enrolled $e) => $e->student)->groupBy('student_id');

        return [
            'match_count' => $byStudent->count(),
            'matches' => $byStudent->take(self::MAX_NAME_MATCHES)->map(fn ($rows) => $this->studentRow($rows->first()->student) + [
                'classes' => $rows->map(fn (Enrolled $e) => trim(($e->subject?->subject_name ?? '').' — '.($e->subject?->section?->section_name ?? '')))->unique()->values()->all(),
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function listAssessments(int $subjectId, ?int $sectionId): array
    {
        $subject = $this->subject($subjectId);

        $assessments = Assessment::visibleTo($this->educator)
            ->where('tbl_assessments.subject_id', $subject->id)
            ->when($sectionId, fn ($q) => $q->where('tbl_assessments.section_id', $sectionId))
            ->orderBy('assessment_code')
            ->limit(self::MAX_ASSESSMENTS)
            ->get(['id', 'assessment_code', 'section_id', 'start_date', 'end_date', 'pool_size', 'is_active']);

        return [
            'subject' => $subject->subject_name,
            'assessments' => $assessments->map(fn (Assessment $a) => [
                'assessment_id' => $a->id,
                'assessment_code' => $a->assessment_code,
                'section_id' => $a->section_id,
                'questions' => $a->pool_size,
                'opens' => $a->start_date?->toDateString(),
                'closes' => $a->end_date?->toDateString(),
                'active' => (bool) $a->is_active,
            ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function getStudentScores(int $studentId, ?int $subjectId, ?int $assessmentId): array
    {
        $student = $this->student($studentId);

        $scores = Score::visibleTo($this->educator)
            ->where('tbl_scores.student_id', $student->id)
            ->whereIn('tbl_scores.status', self::SUBMITTED)
            ->when($subjectId, fn ($q) => $q->where('tbl_scores.subject_id', $subjectId))
            ->when($assessmentId, fn ($q) => $q->where('tbl_scores.assessment_id', $assessmentId))
            ->with(['assessment:id,assessment_code,subject_id', 'subject:id,subject_name'])
            ->limit(self::MAX_SCORES)
            ->get(['id', 'assessment_id', 'subject_id', 'score', 'total_questions', 'status', 'is_passed', 'submitted_at']);

        // One row per assessment: the best attempt, resolved by the same definition the score
        // export and the class matrix use, so the assistant can never disagree with those pages.
        $results = $scores->groupBy('assessment_id')
            ->map(fn ($attempts) => [
                'best' => ScoreRowBuilder::bestAttempt($attempts),
                'attempts' => $attempts->count(),
            ])
            ->filter(fn ($row) => $row['best'] !== null)
            ->map(fn ($row) => [
                'assessment' => $row['best']->assessment?->assessment_code,
                'subject' => $row['best']->subject?->subject_name,
                'score' => $row['best']->score,
                'total_questions' => $row['best']->total_questions,
                'percentage' => $this->percentage($row['best']->score, $row['best']->total_questions),
                'result' => $row['best']->score === null ? 'no score recorded' : ($row['best']->is_passed ? 'passed' : 'failed'),
                'attempts' => $row['attempts'],
                'submitted_at' => $row['best']->submitted_at?->toDateTimeString(),
            ])
            ->values()->all();

        return [
            'student' => $this->studentRow($student),
            'results' => $results,
            'note' => $results === [] ? 'This student has no submitted attempts matching that filter.' : null,
        ];
    }

    /** @return array<string, mixed> */
    private function getClassSummary(int $subjectId, ?int $assessmentId): array
    {
        $subject = $this->subject($subjectId);

        $scores = Score::visibleTo($this->educator)
            ->where('tbl_scores.subject_id', $subject->id)
            ->whereIn('tbl_scores.status', self::SUBMITTED)
            ->when($assessmentId, fn ($q) => $q->where('tbl_scores.assessment_id', $assessmentId))
            ->limit(self::MAX_SCORES * 5)
            ->get(['id', 'student_id', 'assessment_id', 'score', 'total_questions', 'is_passed']);

        $best = $scores->groupBy(fn (Score $s) => $s->student_id.':'.$s->assessment_id)
            ->map(fn ($attempts) => ScoreRowBuilder::bestAttempt($attempts))
            ->filter(fn (?Score $s) => $s !== null && $s->score !== null);

        $percentages = $best->map(fn (Score $s) => $this->percentage($s->score, $s->total_questions))->filter(fn ($p) => $p !== null);

        $enrolledCount = Enrolled::visibleTo($this->educator)
            ->where('tbl_enrolled.subject_id', $subject->id)
            ->where('tbl_enrolled.is_active', true)
            ->count();

        return [
            'subject' => $subject->subject_name,
            'enrolled_students' => $enrolledCount,
            'graded_attempts' => $best->count(),
            'students_without_submission' => max(0, $enrolledCount - $best->pluck('student_id')->unique()->count()),
            'average_percentage' => $percentages->isEmpty() ? null : round($percentages->avg(), 1),
            'pass_rate_percentage' => $best->isEmpty() ? null : round($best->where('is_passed', true)->count() / $best->count() * 100, 1),
            'highest_percentage' => $percentages->max(),
            'lowest_percentage' => $percentages->min(),
        ];
    }

    /**
     * Ranked class scores in ONE call.
     *
     * This exists because "who are the top scorers in X?" previously needed three tool rounds
     * (list_classes, list_assessments, get_class_summary) and ran out of rounds before answering.
     * Ranking here also keeps the model out of the arithmetic — it receives an ordered list and a
     * percentage per student, and never has to sort or average anything itself.
     *
     * Students with no submission are excluded by design; get_class_summary reports how many
     * those are.
     *
     * @return array<string, mixed>
     */
    private function getClassScores(int $subjectId, ?int $assessmentId, string $order, int $limit): array
    {
        $subject = $this->subject($subjectId);

        // Resolving the assessment through visibleTo means another educator's assessment id is
        // indistinguishable from a missing one, exactly as everywhere else.
        $assessment = $assessmentId ? Assessment::visibleTo($this->educator)
            ->where('tbl_assessments.subject_id', $subject->id)
            ->findOrFail($assessmentId) : null;

        $scores = Score::visibleTo($this->educator)
            ->where('tbl_scores.subject_id', $subject->id)
            ->whereIn('tbl_scores.status', self::SUBMITTED)
            ->when($assessment, fn ($q) => $q->where('tbl_scores.assessment_id', $assessment->id))
            ->with('student:id,given_name,surname,user_id')
            ->limit(self::MAX_SCORES * 5)
            ->get(['id', 'student_id', 'assessment_id', 'score', 'total_questions', 'is_passed']);

        // Best attempt per student per assessment, then one row per student.
        $ranked = $scores->groupBy(fn (Score $s) => $s->student_id.':'.$s->assessment_id)
            ->map(fn ($attempts) => ScoreRowBuilder::bestAttempt($attempts))
            ->filter(fn (?Score $s) => $s !== null && $s->score !== null && $s->student)
            ->groupBy('student_id')
            ->map(function ($best) {
                $percentages = $best->map(fn (Score $s) => $this->percentage($s->score, $s->total_questions))
                    ->filter(fn ($p) => $p !== null);

                $first = $best->first();

                return [
                    'student' => trim(($first->student->given_name ?? '').' '.($first->student->surname ?? '')),
                    'student_number' => $first->student->user_id,
                    // Single assessment: the raw score is the meaningful figure. Across several:
                    // the average percentage, computed here so the model never averages anything.
                    'score' => $best->count() === 1 ? $first->score : null,
                    'total_questions' => $best->count() === 1 ? $first->total_questions : null,
                    'percentage' => $percentages->isEmpty() ? null : round($percentages->avg(), 1),
                    'assessments_counted' => $best->count(),
                    'passed' => $best->where('is_passed', true)->count(),
                ];
            })
            ->filter(fn ($row) => $row['percentage'] !== null)
            ->values();

        $sorted = $order === 'lowest'
            ? $ranked->sortBy('percentage')
            : $ranked->sortByDesc('percentage');

        return [
            'subject' => $subject->subject_name,
            'assessment' => $assessment?->assessment_code,
            'scope' => $assessment ? 'one assessment' : 'average across all assessments in this subject',
            'order' => $order,
            'students_ranked' => $ranked->count(),
            'showing' => min($limit, $ranked->count()),
            'rankings' => $sorted->take($limit)->values()->all(),
        ];
    }

    // ---------------------------------------------------------------- resolution helpers

    /** Resolves through visibleTo, so another educator's subject id throws exactly like a missing one. */
    private function subject(int $id): Subject
    {
        return Subject::visibleTo($this->educator)->findOrFail($id);
    }

    /**
     * A student is "resolvable" only through an active enrollment owned by this educator. There is
     * deliberately no User::find() here — that would read the whole users table.
     */
    private function student(int $id): User
    {
        $enrolled = Enrolled::visibleTo($this->educator)
            ->where('tbl_enrolled.student_id', $id)
            ->with('student:id,given_name,surname,user_id')
            ->firstOrFail();

        if (! $enrolled->student) {
            throw new ModelNotFoundException;
        }

        return $enrolled->student;
    }

    /** @return array<string, mixed> */
    private function studentRow(User $student): array
    {
        return [
            'student_id' => $student->id,
            'name' => trim(($student->given_name ?? '').' '.($student->surname ?? '')),
            'student_number' => $student->user_id,
        ];
    }

    private function percentage(?int $score, ?int $total): ?float
    {
        if ($score === null || ! $total) {
            return null;
        }

        return round($score / $total * 100);
    }

    /** @return array<string, mixed> */
    private function notFound(): array
    {
        return [
            'error' => 'not_found',
            'message' => 'No matching record exists in this educator\'s data.',
        ];
    }

    // ---------------------------------------------------------------- argument coercion

    /** @param array<string, mixed> $args */
    private function intArg(array $args, string $key): int
    {
        $value = $args[$key] ?? null;

        if (! is_numeric($value) || (int) $value <= 0) {
            throw new \InvalidArgumentException("Invalid {$key}.");
        }

        return (int) $value;
    }

    /** @param array<string, mixed> $args */
    private function optionalIntArg(array $args, string $key): ?int
    {
        if (! array_key_exists($key, $args) || $args[$key] === null || $args[$key] === '') {
            return null;
        }

        return $this->intArg($args, $key);
    }

    /** @param array<string, mixed> $args */
    private function stringArg(array $args, string $key): string
    {
        $value = $args[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException("Invalid {$key}.");
        }

        // Truncate rather than reject: an over-long search term is the model being verbose, not an
        // attack, and failing the whole lookup over it just wastes a round trip.
        return mb_substr(AssistantGuard::normalize($value), 0, 60);
    }

    /**
     * @param  array<string, array<string, mixed>>  $properties
     * @param  array<int, string>  $required
     * @return array<string, mixed>
     */
    private function tool(string $name, string $description, array $properties, array $required): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $name,
                'description' => $description,
                'parameters' => [
                    'type' => 'object',
                    'properties' => (object) $properties,
                    'required' => $required,
                    'additionalProperties' => false,
                ],
            ],
        ];
    }
}
