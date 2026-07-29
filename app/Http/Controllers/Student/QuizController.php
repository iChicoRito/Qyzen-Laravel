<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\AcademicTerm;
use App\Models\Assessment;
use App\Models\Quiz;
use App\Models\Score;
use App\Models\Section;
use App\Models\StudentAssessmentExemption;
use App\Models\Subject;
use App\Services\AssessmentAvailabilityService;
use App\Services\QuizGradingService;
use App\Support\TableQuery;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

// H2–H6: student quiz engine. Enrollment-gated (visibleTo) + schedule + attempt gates.
// correct_answer NEVER reaches the client — take views select only id/question/type/choices,
// and grading is server-side (QuizGradingService).
class QuizController extends Controller
{
    public function __construct(
        private AssessmentAvailabilityService $availability,
        private QuizGradingService $grading,
    ) {}

    // Wraps the enrollment-gate check with diagnostic logging — the 403 this throws is
    // legitimate (enrollment was revoked/changed), but production support had no way to see
    // why it fired mid-exam. Logged here once, at the single choke point every quiz action
    // re-checks through.
    private function authorizeQuizAccess(Assessment $assessment): void
    {
        // Task 29: an archived assessment is off the list, so it must also be unreachable by
        // direct URL. Score history is untouched — that reads through Score, not through here.
        if ($assessment->is_archived) {
            throw new AuthorizationException('This assessment has been archived.');
        }

        try {
            $this->authorize('view', $assessment);
        } catch (AuthorizationException $e) {
            Log::warning('Student quiz access denied', [
                'student_id' => Auth::id(),
                'assessment_id' => $assessment->id,
                'route' => request()->route()?->getName(),
            ]);

            throw $e;
        }
    }

    // H2: enrolled assessments + availability badge + can-take.
    public function index(Request $request): View
    {
        $user = Auth::user();

        $query = Assessment::visibleTo($user)->notArchived()
            ->with(['subject:id,subject_code,subject_name', 'section:id,section_name', 'academicTerm:id,term_name']);
        TableQuery::search($query, $request->query('search'), [
            'assessment_code',
            fn (Builder $q, string $term) => $q->orWhereHas('subject', fn ($s) => $s
                ->where('subject_code', 'like', "%{$term}%")
                ->orWhere('subject_name', 'like', "%{$term}%")),
            fn (Builder $q, string $term) => $q->orWhereHas('section', fn ($s) => $s->where('section_name', 'like', "%{$term}%")),
        ]);
        TableQuery::filters($query, $request, ['subject' => 'subject_id', 'section' => 'section_id', 'term' => 'term']);
        TableQuery::sort($query, $request, ['assessment' => 'assessment_code', 'id' => 'id'], 'id', 'desc');

        $assessments = $query->paginate(TableQuery::perPage($request))->withQueryString();
        $exemptionReasons = StudentAssessmentExemption::where('student_id', $user->id)
            ->where('is_active', true)
            ->whereIn('assessment_id', $assessments->getCollection()->pluck('id'))
            ->pluck('reason', 'assessment_id');
        $assessments->setCollection($assessments->getCollection()->map(function (Assessment $a) use ($user) {
            $av = $this->availability->summarize($a, $user->id);
            $a->setAttribute('availability', $av);
            [$label, $color] = $this->displayStatus($av['badge'], $a->pool_size);
            // Task 21: a finished, no-retake-left attempt reads "Already Taken" (was
            // mislabelled "Available"). Takes precedence — it can never be retaken.
            if ($av['submitted_attempts'] > 0 && $av['remaining'] === 0) {
                [$label, $color] = ['Already Taken', 'secondary'];
            }
            $a->setAttribute('status_label', $label);
            $a->setAttribute('status_color', $color);
            // Can only start when takeable AND questions exist (take() redirects on empty).
            $a->setAttribute('startable', $av['can_take'] && $a->pool_size > 0);

            return $a;
        }));
        $assessments->getCollection()->each(function (Assessment $a) use ($exemptionReasons): void {
            $a->setAttribute('exemption_reason', $exemptionReasons->get($a->id));
        });

        $subjects = Subject::visibleTo($user)->orderBy('subject_name')->get(['id', 'subject_code', 'subject_name']);
        $sections = Section::visibleTo($user)->orderBy('section_name')->get(['id', 'section_name']);
        $terms = AcademicTerm::whereIn('id', Assessment::visibleTo($user)->notArchived()->select('term'))->orderBy('term_name')->get(['id', 'term_name']);
        $tab = $request->query('tab', 'pending'); // pending | finished

        return view('student.assessments.index', compact('assessments', 'subjects', 'sections', 'terms', 'tab'));
    }

    /**
     * Student-friendly card status. Time gates take precedence, then question readiness.
     *
     * @return array{0:string,1:string} [label, kt-badge color]
     */
    private function displayStatus(string $badge, int $questionCount): array
    {
        return match (true) {
            $badge === 'Exempted' => ['Exempted', 'secondary'],
            $badge === 'Inactive' => ['Not Ready Yet', 'secondary'],
            $badge === 'Upcoming' => ['Starts Soon', 'warning'],
            $badge === 'Expired' => ['No Longer Available', 'secondary'],
            $badge === 'Schedule issue' => ['Not Ready Yet', 'secondary'],
            $questionCount === 0 => ['Not Ready Yet', 'secondary'],
            $badge === 'Special Access' => ['Special Access', 'info'],
            $badge === 'Reopened' => ['Reopened', 'info'],
            default => ['Available', 'success'],
        };
    }

    // H3: take-quiz session load — eligibility + draft restore + stable shuffle + server timer.
    public function take(Assessment $assessment): Response|RedirectResponse
    {
        try {
            $this->authorizeQuizAccess($assessment);
        } catch (AuthorizationException) {
            // Never dead-end a student mid-exam on the raw framework 403 page — send them
            // somewhere they can act on, with an explanation. The denial itself is already
            // logged inside authorizeQuizAccess().
            return redirect()->route('student.assessments.index')
                ->with('status', 'You no longer have access to this assessment. Please contact your teacher if this seems wrong.');
        }

        // Post-submit lock: a finished attempt can't be reloaded. If no attempt remains, send the
        // student to their latest result (or the list) rather than back into the quiz.
        $summary = $this->availability->summarize($assessment, Auth::id());
        if (! $summary['can_take']) {
            $latest = Score::where('assessment_id', $assessment->id)
                ->where('student_id', Auth::id())
                ->whereIn('status', ['passed', 'failed', 'submitted'])
                ->orderByDesc('submitted_at')->first();

            return $latest
                ? redirect()->route('student.scores.show', $latest)
                    ->with('status', 'You have already completed this assessment.')
                : redirect()->route('student.assessments.index')
                    ->with('status', 'This assessment is not available to take right now.');
        }

        // Don't anchor an attempt (and burn a pool draw) for an assessment with no eligible
        // questions at all — bail before creating any Score row.
        if ($assessment->effectivePoolSize() === 0) {
            return redirect()->route('student.assessments.index')
                ->with('status', 'This assessment has no questions yet.');
        }

        // Anchor the attempt: ensure the in-progress row exists so taken_at (the true start
        // for the timer) is fixed on first screen load, before any autosave. This is also where
        // the pool draw is pinned (Task 51) — must resolve before loading questions below.
        $draft = Score::where('assessment_id', $assessment->id)
            ->where('student_id', Auth::id())
            ->where('status', 'in_progress')->first()
            ?? $this->grading->startAttempt(Auth::user(), $assessment);

        // Questions WITHOUT correct_answer (model hides it; we also select explicit columns).
        // Only the pinned drawn subset for this attempt — never the full eligible pool.
        $questions = Quiz::whereIn('id', $draft->drawn_quiz_ids ?? [])
            ->get(['id', 'question', 'quiz_type', 'choices']);

        if ($questions->isEmpty()) {
            return redirect()->route('student.assessments.index')
                ->with('status', 'This assessment has no questions yet.');
        }

        // Task 21: shuffle a fresh order on every load when is_shuffle is on. Answers are keyed by
        // question id (name="answers[{id}]") and MC values are the choice key, so reordering the
        // display never breaks draft-answer restore or grading.
        if ($assessment->is_shuffle) {
            $questions = $questions->shuffle();
            $questions->each(function (Quiz $q) {
                if ($q->quiz_type === 'multiple_choice' && is_array($q->choices)) {
                    $keys = array_keys($q->choices);
                    shuffle($keys);
                    $q->setAttribute('choices', array_replace(array_flip($keys), $q->choices));
                }
            });
        }

        // Server-authoritative remaining time: resume from real elapsed since taken_at, not a
        // fresh countdown each load. Time keeps running while the student is away.
        $timeLimitSec = ((int) $assessment->time_limit) * 60;
        $remainingSeconds = $timeLimitSec > 0
            ? (int) max(0, $timeLimitSec - $draft->taken_at->diffInSeconds(now()))
            : 0;

        $assessment->load([
            'subject:id,subject_code,subject_name',
            'section:id,section_name',
            'educator:id,given_name,surname',
            'academicTerm:id,term_name',
        ]);

        $hintsRemaining = $assessment->allow_hint
            ? max(0, $assessment->hint_count - $draft->hints_used)
            : 0;

        // no-store so the Back button can't re-show a finished quiz from cache/bfcache — it
        // refetches and hits the can_take gate above.
        return response()->view('student.take-quiz', [
            'assessment' => $assessment,
            'questions' => $questions,
            'draftAnswers' => $draft->student_answer ?? [],
            'warnings' => $draft->warning_attempts ?? 0,
            'remainingSeconds' => $remainingSeconds,
            'hintsRemaining' => $hintsRemaining,
        ])->withHeaders([
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    // H4: autosave draft (debounced fetch). Returns minimal JSON, never correct_answer.
    public function saveDraft(Request $request, Assessment $assessment)
    {
        $this->authorizeQuizAccess($assessment);

        if (! $this->availability->summarize($assessment, Auth::id())['can_take']) {
            return response()->json(['message' => 'This attempt is no longer eligible.'], 422);
        }

        $data = $request->validate([
            'answers' => ['array'],
            'warnings' => ['nullable', 'integer', 'min:0'],
        ]);

        $score = $this->grading->saveDraft(Auth::user(), $assessment, $data['answers'] ?? [], (int) ($data['warnings'] ?? 0));
        if (! $score) {
            return response()->json(['message' => 'This attempt is no longer eligible.'], 422);
        }

        return response()->json(['saved' => true, 'at' => now()->toTimeString()]);
    }

    public function warning(Request $request, Assessment $assessment): JsonResponse
    {
        $this->authorizeQuizAccess($assessment);

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
            'answers' => ['array'],
        ]);

        $summary = $this->availability->summarize($assessment, Auth::id());
        $latest = Score::where('assessment_id', $assessment->id)
            ->where('student_id', Auth::id())
            ->whereIn('status', ['passed', 'failed', 'submitted'])
            ->orderByDesc('submitted_at')
            ->first();

        if (! $summary['can_take']) {
            if ($latest) {
                return response()->json([
                    'warnings' => (int) $latest->warning_attempts,
                    'limit' => (int) $assessment->cheating_attempts,
                    'forced' => true,
                    'redirect_url' => route('student.scores.show', $latest),
                    'message' => 'Warning limit reached. Your assessment has been submitted.',
                ]);
            }

            return response()->json(['message' => 'This attempt is no longer eligible.'], 422);
        }

        $score = $this->grading->recordWarning(Auth::user(), $assessment, $data['answers'] ?? []);
        $limit = (int) $assessment->cheating_attempts;
        $forced = in_array($score->status, ['passed', 'failed', 'submitted'], true);

        return response()->json([
            'warnings' => (int) $score->warning_attempts,
            'limit' => $limit,
            'forced' => $forced,
            'redirect_url' => $forced ? route('student.scores.show', $score) : null,
            'message' => $forced
                ? 'Warning limit reached. Your assessment has been submitted.'
                : 'Warning recorded.',
        ]);
    }

    // Task 02: resolve a hint mini-game attempt. `outcome` (won|lost|skipped) is client-reported
    // (all games are self-contained, no secret quiz data involved). Exactly one hint credit is
    // deducted per call; the answer is only revealed when outcome === 'won'.
    public function hint(Request $request, Assessment $assessment)
    {
        $this->authorizeQuizAccess($assessment);

        $summary = $this->availability->summarize($assessment, Auth::id());
        if (! $summary['can_take']) {
            return response()->json(['message' => 'This attempt is no longer eligible.'], 422);
        }

        $data = $request->validate([
            'quiz_id' => ['required', 'integer'],
            'outcome' => ['required', 'in:won,lost,skipped'],
        ]);

        $score = Score::where('assessment_id', $assessment->id)
            ->where('student_id', Auth::id())
            ->where('status', 'in_progress')->first();

        // Only a question actually drawn for this attempt can be hinted.
        if (! $score || ! in_array((int) $data['quiz_id'], $score->drawn_quiz_ids ?? [], true)) {
            return response()->json(['message' => 'Question not found in this attempt.'], 422);
        }

        $quiz = Quiz::where('id', $data['quiz_id'])->first(['id', 'quiz_type', 'choices', 'correct_answer']);
        if (! $quiz) {
            return response()->json(['message' => 'Question not found in this attempt.'], 422);
        }

        $won = $data['outcome'] === 'won';
        $result = $this->grading->revealHint(Auth::user(), $assessment, $quiz, $won);
        if ($result === null) {
            return response()->json(['message' => 'No hints remaining.'], 422);
        }

        $remaining = max(0, $assessment->hint_count - $score->fresh()->hints_used);

        return response()->json(['hint' => $result['answer'], 'remaining' => $remaining, 'won' => $won]);
    }

    // H6 ⚠ submit → server-side grading. correct_answer is loaded server-side in the service only.
    public function submit(Request $request, Assessment $assessment): JsonResponse|RedirectResponse
    {
        $this->authorizeQuizAccess($assessment);

        // Re-validate eligibility server-side (don't trust the client's gate).
        $summary = $this->availability->summarize($assessment, Auth::id());
        if (! $summary['can_take']) {
            if ($request->expectsJson()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'This attempt is no longer eligible.',
                ], 422);
            }

            return redirect()->route('student.assessments.index')
                ->with('status', 'This attempt is no longer eligible.');
        }

        $data = $request->validate([
            'answers' => ['array'],
            'warnings' => ['nullable', 'integer', 'min:0'],
        ]);

        $score = $this->grading->grade(Auth::user(), $assessment, $data['answers'] ?? [], (int) ($data['warnings'] ?? 0));

        if ($request->expectsJson()) {
            return response()->json([
                'status' => 'success',
                'message' => 'Submitted and graded.',
                'redirect_url' => route('student.scores.show', $score),
            ]);
        }

        return redirect()->route('student.scores.show', $score)->with('status', 'Submitted and graded.');
    }
}
