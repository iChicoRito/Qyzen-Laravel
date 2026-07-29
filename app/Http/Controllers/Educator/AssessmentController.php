<?php

namespace App\Http\Controllers\Educator;

use App\Events\ConversationActivity;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAssessmentRequest;
use App\Http\Requests\UpdateAssessmentRequest;
use App\Models\AcademicTerm;
use App\Models\Assessment;
use App\Models\Enrolled;
use App\Models\Section;
use App\Models\StudentAssessmentAccess;
use App\Models\StudentAssessmentExemption;
use App\Models\Subject;
use App\Models\User;
use App\Services\ConversationService;
use App\Services\NotificationService;
use App\Support\TableQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

// G5: educator assessments. status inactive→active is the publish trigger → notify enrolled students.
class AssessmentController extends Controller
{
    // Task 24: selectable special-access durations, in hours. 0 = no expiry (the pre-Task-24
    // behavior, where a grant lived until it was used). Deliberately hour-based, not "end of
    // day" — APP_TIMEZONE is UTC and this app renders raw UTC, so a civil-day rule would expire
    // at 8am local.
    private const ACCESS_DURATIONS = [0, 1, 6, 24, 48, 72];

    private const ACCESS_DURATION_DEFAULT = 24;

    public function __construct(
        private NotificationService $notifications,
        private ConversationService $conversations,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Assessment::class);

        $selectedSubject = $request->query('subject');
        $selectedSection = $request->query('section');
        $query = Assessment::visibleTo(Auth::user())->notArchived()
            ->with(['subject:id,subject_code,subject_name', 'section:id,section_name', 'academicTerm:id,term_name']);
        TableQuery::search($query, $request->query('search'), [
            'assessment_code',
            fn (Builder $q, string $term) => $q->orWhereHas('subject', fn ($s) => $s->where('subject_code', 'like', "%{$term}%")->orWhere('subject_name', 'like', "%{$term}%")),
            fn (Builder $q, string $term) => $q->orWhereHas('section', fn ($s) => $s->where('section_name', 'like', "%{$term}%")),
        ]);
        TableQuery::filters($query, $request, ['subject' => 'subject_id', 'section' => 'section_id', 'assessment' => 'assessment_code', 'status' => 'is_active']);
        TableQuery::sort($query, $request, [
            'code' => 'assessment_code',
            'subject' => function (Builder $q, string $direction): void {
                $q->leftJoin('tbl_subjects as sort_subjects', 'sort_subjects.id', '=', 'tbl_assessments.subject_id')
                    ->select('tbl_assessments.*')
                    ->orderBy('sort_subjects.subject_name', $direction)
                    ->orderBy('tbl_assessments.id', 'desc');
            },
            'section' => function (Builder $q, string $direction): void {
                $q->leftJoin('tbl_sections as sort_sections', 'sort_sections.id', '=', 'tbl_assessments.section_id')
                    ->select('tbl_assessments.*')
                    ->orderBy('sort_sections.section_name', $direction)
                    ->orderBy('tbl_assessments.id', 'desc');
            },
            'term' => function (Builder $q, string $direction): void {
                $q->leftJoin('tbl_academic_term as sort_terms', 'sort_terms.id', '=', 'tbl_assessments.term')
                    ->select('tbl_assessments.*')
                    ->orderBy('sort_terms.term_name', $direction)
                    ->orderBy('tbl_assessments.id', 'desc');
            },
            'window' => function (Builder $q, string $direction): void {
                $q->orderBy('start_date', $direction)
                    ->orderBy('end_date', $direction)
                    ->orderBy('id', 'desc');
            },
            'status' => 'is_active',
            'id' => 'id',
        ], 'id', 'desc');

        $assessments = $query->paginate(TableQuery::perPage($request))->withQueryString();

        $filterSubjects = Subject::visibleTo(Auth::user())
            ->when($selectedSection, fn ($q) => $q->where('sections_id', $selectedSection))
            ->orderBy('subject_code')->get(['id', 'subject_code', 'subject_name', 'sections_id']);
        $filterSections = Section::visibleTo(Auth::user())->orderBy('section_name')->get(['id', 'section_name']);
        $filterAssessments = Assessment::visibleTo(Auth::user())->notArchived()
            ->when($selectedSection, fn ($q) => $q->whereHas('subject', fn ($s) => $s->where('sections_id', $selectedSection)))
            ->when($selectedSubject, fn ($q) => $q->where('subject_id', $selectedSubject))
            ->select('assessment_code')
            ->distinct()
            ->orderBy('assessment_code')
            ->pluck('assessment_code');

        return view('educator.assessments.index', compact('assessments', 'filterSubjects', 'filterSections', 'filterAssessments'));
    }

    public function create(): View
    {
        $this->authorize('create', Assessment::class);

        return view('educator.assessments.create', $this->formData());
    }

    public function store(StoreAssessmentRequest $request): RedirectResponse
    {
        $this->authorize('create', Assessment::class);

        $data = $request->validated();
        $shouldNotifyStudents = $request->shouldNotifyStudents();
        $subjectIds = $data['subject_ids'];
        unset($data['subject_ids']);

        // One assessment per selected subject; section_id derived from each subject's own section.
        $subjects = Subject::visibleTo(Auth::user())->whereKey($subjectIds)->get(['id', 'sections_id']);
        foreach ($subjects as $subject) {
            $assessment = Assessment::create($data + [
                'educator_id' => Auth::id(),
                'subject_id' => $subject->id,
                'section_id' => $subject->sections_id,
            ]);

            // Publish-on-create: if created already active, notify enrolled students.
            if ($assessment->is_active && $shouldNotifyStudents) {
                $this->notifyEnrolled($assessment, 'assessment_created', 'New assessment published');
            }
        }

        $n = $subjects->count();

        return redirect()->route('educator.assessments.index')
            ->with('status', $n === 1 ? 'Assessment created.' : "{$n} assessments created.");
    }

    public function edit(Assessment $assessment): View
    {
        $this->authorize('update', $assessment);

        return view('educator.assessments.edit', ['assessment' => $assessment] + $this->formData());
    }

    public function duplicate(Assessment $assessment): View
    {
        $this->authorize('update', $assessment);

        $duplicate = $assessment->replicate();
        $duplicate->assessment_code = substr((string) $assessment->assessment_code, 0, 250).' Copy';

        return view('educator.assessments.duplicate', ['assessment' => $duplicate, 'sourceAssessment' => $assessment] + $this->formData());
    }

    public function storeDuplicate(UpdateAssessmentRequest $request, Assessment $assessment): JsonResponse|RedirectResponse
    {
        $this->authorize('update', $assessment);

        $data = $request->validated();
        $shouldNotifyStudents = $request->shouldNotifyStudents();
        $subjectId = (int) $data['subject_ids'][0];
        unset($data['subject_ids']);

        $subject = Subject::visibleTo(Auth::user())->whereKey($subjectId)->firstOrFail(['id', 'sections_id']);

        $newAssessment = DB::transaction(function () use ($assessment, $data, $subject) {
            $newAssessment = Assessment::create($data + [
                'educator_id' => Auth::id(),
                'subject_id' => $subject->id,
                'section_id' => $subject->sections_id,
                'pool_size' => $assessment->pool_size,
            ]);

            $newAssessment->eligibleQuizzes()->sync(
                $assessment->eligibleQuizzes()->pluck('tbl_quizzes.id')
            );

            return $newAssessment;
        });

        if ($newAssessment->is_active && $shouldNotifyStudents) {
            $this->notifyEnrolled($newAssessment, 'assessment_created', 'New assessment published');
        }

        $message = 'Assessment duplicated.';

        if ($request->expectsJson()) {
            return response()->json([
                'status' => 'success',
                'message' => $message,
                'redirect' => route('educator.assessments.index', [], false),
            ]);
        }

        return redirect()->route('educator.assessments.index')->with('status', $message);
    }

    public function update(UpdateAssessmentRequest $request, Assessment $assessment): RedirectResponse
    {
        $this->authorize('update', $assessment);

        $wasActive = $assessment->is_active;
        $data = $request->validated();
        $shouldNotifyStudents = $request->shouldNotifyStudents();
        $subjectId = (int) $data['subject_ids'][0];
        unset($data['subject_ids']);
        $subject = Subject::visibleTo(Auth::user())->whereKey($subjectId)->firstOrFail(['id', 'sections_id']);

        // Update the current row only; section follows the selected subject.
        $assessment->update($data + ['subject_id' => $subject->id, 'section_id' => $subject->sections_id]);

        // Publish trigger: inactive → active fires assessment_created; otherwise assessment_updated.
        if (! $wasActive && $assessment->is_active && $shouldNotifyStudents) {
            $this->notifyEnrolled($assessment, 'assessment_created', 'New assessment published');
        } elseif ($shouldNotifyStudents) {
            $this->notifyEnrolled($assessment, 'assessment_updated', 'Assessment updated');
        }

        return redirect()->route('educator.assessments.index')
            ->with('status', 'Assessment updated.');
    }

    public function destroy(Assessment $assessment): RedirectResponse
    {
        $this->authorize('delete', $assessment);

        $this->notifyEnrolled($assessment, 'assessment_deleted', 'Assessment removed');
        // Task 51: FK cascade only removes this assessment's pool-eligibility pivot rows — bank
        // questions are shared/reusable and survive.
        $assessment->delete();

        return redirect()->route('educator.assessments.index')->with('status', 'Assessment deleted.');
    }

    // Task 29: batch archive. Only flips is_archived — scores, submissions, retakes, exemptions
    // and the question pool are all left alone, so an archived assessment still resolves from
    // the score matrix, the exports and the student's own score history.
    public function archive(Request $request): RedirectResponse
    {
        $count = $this->setArchived($request, true);

        return redirect()->route('educator.assessments.index')
            ->with('status', $count.' assessment(s) archived. Student scores were not affected.');
    }

    public function restoreArchived(Request $request): RedirectResponse
    {
        $count = $this->setArchived($request, false);

        return redirect()->route('educator.assessments.archived')
            ->with('status', $count.' assessment(s) restored.');
    }

    public function archived(Request $request): View
    {
        $this->authorize('viewAny', Assessment::class);

        $query = Assessment::visibleTo(Auth::user())->onlyArchived()
            ->with(['subject:id,subject_code,subject_name', 'section:id,section_name', 'academicTerm:id,term_name'])
            ->withCount('scores');
        TableQuery::search($query, $request->query('search'), [
            'assessment_code',
            fn (Builder $q, string $term) => $q->orWhereHas('subject', fn ($s) => $s->where('subject_code', 'like', "%{$term}%")->orWhere('subject_name', 'like', "%{$term}%")),
            fn (Builder $q, string $term) => $q->orWhereHas('section', fn ($s) => $s->where('section_name', 'like', "%{$term}%")),
        ]);
        TableQuery::sort($query, $request, [
            'code' => 'assessment_code',
            'scores' => 'scores_count',
            'id' => 'id',
        ], 'id', 'desc');

        return view('educator.assessments.archived', [
            'assessments' => $query->paginate(TableQuery::perPage($request))->withQueryString(),
        ]);
    }

    private function setArchived(Request $request, bool $archived): int
    {
        $data = $request->validate([
            'assessment_ids' => ['required', 'array', 'min:1'],
            'assessment_ids.*' => ['integer'],
        ]);

        $assessments = Assessment::visibleTo(Auth::user())
            ->whereKey($data['assessment_ids'])
            ->where('is_archived', ! $archived)
            ->get();

        return DB::transaction(function () use ($assessments, $archived): int {
            foreach ($assessments as $assessment) {
                $this->authorize('update', $assessment);
                $assessment->update(['is_archived' => $archived]);
            }

            return $assessments->count();
        });
    }

    // Task 01: per-student "cannot take this quiz" exemption (e.g. an absent student).
    public function exemptions(Assessment $assessment): View
    {
        $this->authorize('update', $assessment);

        $students = Enrolled::where('educator_id', Auth::id())
            ->where('subject_id', $assessment->subject_id)
            ->where('is_active', true)
            ->with('student:id,given_name,surname,user_id')
            ->get();

        $exemptStudentIds = StudentAssessmentExemption::where('assessment_id', $assessment->id)
            ->where('is_active', true)
            ->pluck('student_id')->all();

        return view('educator.assessments.exemptions', compact('assessment', 'students', 'exemptStudentIds'));
    }

    public function toggleExemption(Request $request, Assessment $assessment): JsonResponse|RedirectResponse
    {
        $this->authorize('update', $assessment);

        // Only students actually enrolled in this assessment's subject can be exempted — a
        // stray/forged id in the bulk payload is silently dropped, not a hard error.
        $requestedAction = $request->input('action');
        $isFinalState = $requestedAction === null;
        $action = in_array($requestedAction, ['exempt', 'unexempt'], true) ? $requestedAction : 'exempt';
        $eligibleStudentIds = Enrolled::where('educator_id', Auth::id())
            ->where('subject_id', $assessment->subject_id)
            ->pluck('student_id');
        $selectedStudentIds = collect($request->input('student_ids', []))
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id);

        // The modal pre-checks already-exempted students, so a save that only unchecks someone
        // still re-submits the others. Diffing against what's already active is what separates
        // "a new exemption is being created" from "nothing changed for this student" — the
        // latter must not demand a reason, re-notify, or overwrite the original reason.
        $alreadyExemptIds = StudentAssessmentExemption::where('assessment_id', $assessment->id)
            ->where('is_active', true)
            ->pluck('student_id');
        $newlyExemptedIds = $selectedStudentIds
            ->intersect($eligibleStudentIds)
            ->diff($alreadyExemptIds)
            ->values();

        $validator = Validator::make($request->all(), [
            'student_ids' => ['nullable', 'array'],
            'student_ids.*' => ['integer'],
            'reason' => ['nullable', 'string', 'max:255', Rule::requiredIf(fn () => $action === 'exempt' && $newlyExemptedIds->isNotEmpty())],
        ]);
        if ($validator->fails()) {
            if ($request->ajax() || $request->expectsJson()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Please correct the highlighted fields.',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $validator->validate();
        }
        $data = $validator->validated();

        $studentIds = $isFinalState
            ? $eligibleStudentIds
            : $eligibleStudentIds->intersect($selectedStudentIds)->values();

        foreach ($studentIds as $studentId) {
            if ($action === 'exempt') {
                $attributes = ['is_active' => $isFinalState ? $selectedStudentIds->contains($studentId) : true];
                // Only a new exemption carries a reason — re-saving must leave an existing one alone.
                if ($newlyExemptedIds->contains($studentId)) {
                    $attributes['reason'] = $data['reason'] ?? null;
                }
                StudentAssessmentExemption::updateOrCreate([
                    'educator_id' => Auth::id(), 'student_id' => $studentId, 'assessment_id' => $assessment->id,
                ], $attributes);
            } elseif (! $isFinalState || $selectedStudentIds->contains($studentId)) {
                StudentAssessmentExemption::where([
                    'educator_id' => Auth::id(), 'student_id' => $studentId, 'assessment_id' => $assessment->id,
                ])->update(['is_active' => false]);
            }
        }

        if ($action === 'exempt' && $newlyExemptedIds->isNotEmpty()) {
            // Task 30: one statement, one "Reason:" label, one reason — and the label is dropped
            // entirely when no reason was given, rather than trailing an empty "Reason: ".
            $reason = trim((string) ($data['reason'] ?? ''));
            $exemptionMessage = 'You have been exempted from '.$assessment->assessment_code.'.'
                .($reason !== '' ? ' Reason: '.$reason : '');

            $this->notifications->emitToMany(Auth::user(), 'assessment_exempted', $newlyExemptedIds->all(), [
                'subject_id' => $assessment->subject_id,
                'assessment_id' => $assessment->id,
                'section_id' => $assessment->section_id,
                'title' => 'Assessment exemption',
                'message' => $exemptionMessage,
                'link_path' => route('student.assessments.index'),
                'metadata' => ['reason' => $reason !== '' ? $reason : null],
            ]);

            // Also dropped into the private thread, tagged so it renders as a KTUI alert rather
            // than an ordinary chat bubble.
            foreach ($newlyExemptedIds as $studentId) {
                $conversation = $this->conversations->findOrCreateConversation(
                    Auth::user(),
                    User::findOrFail($studentId),
                );
                $this->conversations->sendMessage($conversation, Auth::user(), $exemptionMessage, 'assessment_exempted');
                broadcast(new ConversationActivity((int) $studentId, $conversation->id));
            }
        }

        $activeStudentIds = $action === 'exempt'
            ? $newlyExemptedIds
            : $studentIds;

        // A final-state save mixes new exemptions and un-exemptions in one submit, so a count of
        // either alone would misreport it ("0 student(s) exempted." for a pure uncheck).
        $verb = $action === 'exempt' ? 'exempted' : 'un-exempted';
        $message = $isFinalState
            ? 'Exemptions updated.'
            : $activeStudentIds->count().' student(s) '.$verb.'.';

        if ($request->expectsJson()) {
            return response()->json([
                'status' => 'success',
                'message' => $message,
                'action' => $action,
                'affected_student_ids' => $activeStudentIds->all(),
                'active_student_ids' => StudentAssessmentExemption::where('assessment_id', $assessment->id)->whereIn('student_id', $eligibleStudentIds)->where('is_active', true)->pluck('student_id')->all(),
            ]);
        }

        // The exemptions view is a modal-only fragment (no page layout) — redirect to the real
        // index page like every other modal-form submission in this app, not back into the
        // fragment route, which would render bare/unstyled on a full browser navigation.
        return redirect()->route('educator.assessments.index')
            ->with('status', $message);
    }

    public function access(Assessment $assessment): View
    {
        $this->authorize('update', $assessment);

        $students = Enrolled::where('educator_id', Auth::id())
            ->where('subject_id', $assessment->subject_id)
            ->where('is_active', true)
            ->with('student:id,given_name,surname,user_id')
            ->get();

        $accessGrants = StudentAssessmentAccess::where('assessment_id', $assessment->id)
            ->where('is_active', true)
            ->get()->keyBy('student_id');
        $accessStudentIds = $accessGrants->keys()->all();
        $accessDurations = self::ACCESS_DURATIONS;
        $accessDurationDefault = self::ACCESS_DURATION_DEFAULT;

        return view('educator.assessments.access', compact(
            'assessment', 'students', 'accessStudentIds', 'accessGrants', 'accessDurations', 'accessDurationDefault',
        ));
    }

    public function toggleAccess(Request $request, Assessment $assessment): JsonResponse|RedirectResponse
    {
        $this->authorize('update', $assessment);

        $validator = Validator::make($request->all(), [
            'student_ids' => ['nullable', 'array'],
            'student_ids.*' => ['integer'],
            'duration_hours' => ['nullable', 'integer', Rule::in(self::ACCESS_DURATIONS)],
        ]);
        if ($validator->fails()) {
            if ($request->ajax() || $request->expectsJson()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Please correct the highlighted fields.',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $validator->validate();
        }
        $data = $validator->validated();

        // Re-saving an already-granted student re-arms their attempt (see
        // AssessmentAvailabilityService) — resetting the clock on the same save is consistent
        // with that: a re-grant restarts both the attempt and the countdown.
        $durationHours = (int) ($data['duration_hours'] ?? self::ACCESS_DURATION_DEFAULT);
        $expiresAt = $durationHours > 0 ? now()->addHours($durationHours) : null;

        $requestedAction = $request->input('action');
        $isFinalState = $requestedAction === null;
        $action = in_array($requestedAction, ['grant', 'revoke'], true) ? $requestedAction : 'grant';
        $eligibleStudentIds = Enrolled::where('educator_id', Auth::id())
            ->where('subject_id', $assessment->subject_id)
            ->pluck('student_id');
        $selectedStudentIds = collect($data['student_ids'] ?? [])->map(fn ($id) => (int) $id);
        $studentIds = $isFinalState
            ? $eligibleStudentIds
            : $eligibleStudentIds->intersect($selectedStudentIds)->values();

        foreach ($studentIds as $studentId) {
            if ($action === 'grant') {
                StudentAssessmentAccess::updateOrCreate([
                    'educator_id' => Auth::id(), 'student_id' => $studentId, 'assessment_id' => $assessment->id,
                ], ['is_active' => $isFinalState ? $selectedStudentIds->contains($studentId) : true, 'expires_at' => $expiresAt]);
            } elseif (! $isFinalState || $selectedStudentIds->contains($studentId)) {
                StudentAssessmentAccess::where([
                    'educator_id' => Auth::id(), 'student_id' => $studentId, 'assessment_id' => $assessment->id,
                ])->update(['is_active' => false]);
            }
        }

        $activeStudentIds = $isFinalState
            ? $selectedStudentIds->intersect($eligibleStudentIds)->values()
            : $studentIds;

        if ($action === 'grant' && $activeStudentIds->isNotEmpty()) {
            $accessMessage = 'You have been granted special access to '.$assessment->assessment_code.'.'
                .($expiresAt ? ' This access expires on '.$expiresAt->format('M j, Y g:i A').'.' : '');

            $this->notifications->emitToMany(Auth::user(), 'assessment_access_granted', $activeStudentIds->all(), [
                'subject_id' => $assessment->subject_id,
                'assessment_id' => $assessment->id,
                'section_id' => $assessment->section_id,
                'title' => 'Assessment special access',
                'message' => $accessMessage,
                'link_path' => route('student.assessments.index'),
            ]);

            foreach ($activeStudentIds as $studentId) {
                $conversation = $this->conversations->findOrCreateConversation(
                    Auth::user(),
                    User::findOrFail($studentId),
                );
                $this->conversations->sendMessage($conversation, Auth::user(), $accessMessage, 'assessment_access_granted');
                broadcast(new ConversationActivity((int) $studentId, $conversation->id));
            }
        }

        $verb = $action === 'grant' ? 'granted special access for' : 'revoked special access for';
        $message = $activeStudentIds->count().' student(s) '.$verb.'.';

        if ($request->expectsJson()) {
            $liveGrants = StudentAssessmentAccess::where('assessment_id', $assessment->id)
                ->whereIn('student_id', $eligibleStudentIds)
                ->where('is_active', true)
                ->get();

            return response()->json([
                'status' => 'success',
                'message' => $message,
                'action' => $action,
                'affected_student_ids' => $activeStudentIds->all(),
                'active_student_ids' => $liveGrants->pluck('student_id')->all(),
                // The modal re-renders each status cell from this response, so it needs the
                // deadlines too — otherwise the expiry the educator just set vanishes until reload.
                'access_deadlines' => $liveGrants->filter(fn ($g) => $g->expires_at !== null)
                    ->mapWithKeys(fn ($g) => [$g->student_id => $g->expires_at->format('M j, g:i A')])
                    ->all(),
            ]);
        }

        return redirect()->route('educator.assessments.index')
            ->with('status', $message);
    }

    private function notifyEnrolled(Assessment $assessment, string $event, string $title): void
    {
        $studentIds = Enrolled::where('educator_id', Auth::id())
            ->where('subject_id', $assessment->subject_id)
            ->where('is_active', true)
            ->pluck('student_id')->all();

        $this->notifications->emitToMany(Auth::user(), $event, $studentIds, [
            'subject_id' => $assessment->subject_id, 'assessment_id' => $assessment->id,
            'section_id' => $assessment->section_id, 'title' => $title,
            'link_path' => route('student.assessments.index'),
        ]);
    }

    private function formData(): array
    {
        return [
            'subjects' => Subject::visibleTo(Auth::user())->with('section:id,section_name')->orderBy('subject_code')->get(),
            'sections' => Section::visibleTo(Auth::user())->orderBy('section_name')->get(),
            'terms' => AcademicTerm::with('year')->where('is_active', true)->get(),
        ];
    }
}
