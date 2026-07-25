<?php

namespace App\Http\Controllers\Educator;

use App\Exports\ScoreUploadTemplateExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\ExportScoresBulkRequest;
use App\Http\Requests\GrantRetakeRequest;
use App\Imports\OfflineScoresImport;
use App\Models\AcademicTerm;
use App\Models\Assessment;
use App\Models\Enrolled;
use App\Models\Quiz;
use App\Models\Score;
use App\Models\Section;
use App\Models\StudentAssessmentRetake;
use App\Models\Subject;
use App\Services\Export\ScoreRowBuilder;
use App\Services\NotificationService;
use App\Services\OfflineScoreUploadService;
use App\Services\ScoreExportService;
use App\Support\TableQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// G7: scores are READ-ONLY for educators (they grade nothing manually — grading is server-side
// at submit, Stage H6). Educator may grant retakes and view per-attempt detail. G8: export.
class ScoreController extends Controller
{
    public function __construct(
        private NotificationService $notifications,
        private ScoreExportService $exporter,
        private OfflineScoreUploadService $offlineUploads,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Score::class);

        // Task 27: the index is a CLASS list — one row per subject/section/term that has at least
        // one assessment (so a class with no submissions yet still opens to show its roster).
        // Per-student scores moved into the matrix modal (@matrix). Assessment::visibleTo carries
        // the same ownership + active-term gate Score::visibleTo does, so nothing widens here.
        $query = Assessment::visibleTo(Auth::user())
            ->join('tbl_subjects', 'tbl_subjects.id', '=', 'tbl_assessments.subject_id')
            ->join('tbl_sections', 'tbl_sections.id', '=', 'tbl_assessments.section_id')
            ->join('tbl_academic_term', 'tbl_academic_term.id', '=', 'tbl_assessments.term')
            ->select([
                'tbl_assessments.subject_id',
                'tbl_assessments.section_id',
                'tbl_assessments.term',
                'tbl_subjects.subject_code',
                'tbl_subjects.subject_name',
                'tbl_sections.section_name',
                'tbl_academic_term.term_name',
            ])
            // Every non-aggregate select must be grouped (MySQL ONLY_FULL_GROUP_BY).
            ->groupBy(
                'tbl_assessments.subject_id',
                'tbl_assessments.section_id',
                'tbl_assessments.term',
                'tbl_subjects.subject_code',
                'tbl_subjects.subject_name',
                'tbl_sections.section_name',
                'tbl_academic_term.term_name',
            )
            ->selectRaw('count(*) as assessments_count');
        TableQuery::search($query, $request->query('search'), [
            'tbl_subjects.subject_code',
            'tbl_subjects.subject_name',
            'tbl_sections.section_name',
            'tbl_academic_term.term_name',
        ]);
        TableQuery::filters($query, $request, [
            'subject' => 'tbl_assessments.subject_id',
            'section' => 'tbl_assessments.section_id',
            'term' => 'tbl_assessments.term',
        ]);
        TableQuery::sort($query, $request, [
            'subject' => function (Builder $q, string $direction): void {
                $q->orderBy('tbl_subjects.subject_code', $direction)
                    ->orderBy('tbl_subjects.subject_name', $direction);
            },
            'section' => 'tbl_sections.section_name',
            'term' => 'tbl_academic_term.term_name',
        ], 'subject');

        // paginate() is grouped-query safe: Laravel wraps a grouped query in a subquery for the
        // count instead of counting per group.
        $classes = $query->paginate(TableQuery::perPage($request))->withQueryString();

        // Task 27: this educator's assessments, flattened for the export modal's cascading
        // Subject → Section → Assessment selects — rendered inline so opening the modal needs
        // no extra round trip.
        $exportOptions = Assessment::visibleTo(Auth::user())
            ->with([
                'subject:id,subject_code,subject_name',
                'section:id,section_name',
                'academicTerm:id,term_name,semester,academic_year_id',
                'academicTerm.year:id,year',
            ])
            ->get()
            ->map(fn (Assessment $a) => [
                'uuid' => $a->uuid,
                'assessmentCode' => $a->assessment_code,
                'subjectId' => $a->subject_id,
                'subjectLabel' => trim(($a->subject?->subject_code ?? '').' — '.($a->subject?->subject_name ?? ''), ' —'),
                'sectionId' => $a->section_id,
                'sectionLabel' => $a->section?->section_name,
                'termId' => $a->term,
                'termLabel' => $a->academicTerm?->term_name,
                'semester' => $a->academicTerm?->semester,
                'academicYear' => $a->academicTerm?->year?->year,
            ])
            ->values();

        $selectedSection = $request->query('section');
        $filterSubjects = Subject::visibleTo(Auth::user())
            ->when($selectedSection, fn ($q) => $q->where('sections_id', $selectedSection))
            ->orderBy('subject_code')->get(['id', 'subject_code', 'subject_name']);
        $filterSections = Section::visibleTo(Auth::user())->orderBy('section_name')->get(['id', 'section_name']);
        $filterTerms = AcademicTerm::where('is_active', true)->orderBy('term_name')->get(['id', 'term_name']);

        return view('educator.scores.index', compact('classes', 'exportOptions', 'filterSubjects', 'filterSections', 'filterTerms'));
    }

    // Task 27: the class score matrix — students down the side, ONE COLUMN PER ASSESSMENT built
    // from the assessment list itself (never a fixed set of quiz names). Modal fragment.
    public function matrix(Request $request): View
    {
        $this->authorize('viewAny', Score::class);

        [$subject, $section, $term, $assessments] = $this->classContext($request);

        $scores = Score::visibleTo(Auth::user())
            ->whereIn('assessment_id', $assessments->pluck('id'))
            ->whereIn('tbl_scores.status', ['submitted', 'passed', 'failed'])
            ->with('student:id,given_name,surname,user_id,profile_picture')
            ->get();

        $students = $this->classStudents($subject, $scores);

        // cells[studentId][assessmentId] = ['best' => Score, 'attempts' => int]
        $cells = [];
        foreach ($scores->groupBy('student_id') as $studentId => $studentScores) {
            foreach ($studentScores->groupBy('assessment_id') as $assessmentId => $attempts) {
                $cells[$studentId][$assessmentId] = [
                    // One definition of "best attempt", shared with the xlsx export.
                    'best' => ScoreRowBuilder::bestAttempt($attempts),
                    'attempts' => $attempts->count(),
                ];
            }
        }

        return view('educator.scores.matrix', compact('subject', 'section', 'term', 'assessments', 'students', 'cells'));
    }

    // Task 27: one student's full history for a class — every attempt of every assessment, with
    // the per-question review and the retake grant. Replaces the matrix inside the same modal.
    public function studentDetail(Request $request): View
    {
        $this->authorize('viewAny', Score::class);

        [$subject, $section, $term, $assessments] = $this->classContext($request);
        $studentId = (int) $request->validate(['student' => ['required', 'integer']])['student'];

        $scores = Score::visibleTo(Auth::user())
            ->whereIn('assessment_id', $assessments->pluck('id'))
            ->whereIn('tbl_scores.status', ['submitted', 'passed', 'failed'])
            ->with('student:id,given_name,surname,user_id,profile_picture')
            ->latest('submitted_at')
            ->get();

        // 404 rather than render an empty shell for a student outside this class.
        $student = $this->classStudents($subject, $scores)->firstWhere('id', $studentId);
        abort_unless($student, 404);

        $attempts = $scores->where('student_id', $studentId)->values();

        // correct_answer is loaded SERVER-SIDE here (educator view) — never serialized to a
        // student. withTrashed: a question deleted from the bank after the attempt must still
        // resolve so historical review stays whole (Task 13).
        $questions = Quiz::withTrashed()
            ->whereIn('id', $attempts->flatMap(fn (Score $s) => $s->drawn_quiz_ids ?? [])->unique())
            ->get()
            ->keyBy('id');

        return view('educator.scores.student', compact('subject', 'section', 'term', 'assessments', 'student', 'attempts', 'questions'));
    }

    /**
     * Resolve + authorize a subject/section/term class context from query params, with its
     * assessments. Every lookup runs through visibleTo, so another educator's ids 404.
     *
     * @return array{0: Subject, 1: Section, 2: AcademicTerm, 3: \Illuminate\Support\Collection}
     */
    private function classContext(Request $request): array
    {
        $data = $request->validate([
            'subject' => ['required', 'integer'],
            'section' => ['required', 'integer'],
            'term' => ['required', 'integer'],
        ]);

        $user = Auth::user();
        $subject = Subject::visibleTo($user)->findOrFail($data['subject']);
        $section = Section::visibleTo($user)->findOrFail($data['section']);
        $term = AcademicTerm::where('is_active', true)->findOrFail($data['term']);

        $assessments = Assessment::visibleTo($user)
            ->where('subject_id', $subject->id)
            ->where('section_id', $section->id)
            ->where('term', $term->id)
            ->orderBy('assessment_code')
            ->get(['id', 'uuid', 'assessment_code']);

        return [$subject, $section, $term, $assessments];
    }

    /**
     * Class roster, surname-first: enrolled students plus anyone who already has a score here
     * (a student unenrolled after submitting must not silently vanish from the matrix).
     */
    private function classStudents(Subject $subject, $scores)
    {
        return Enrolled::visibleTo(Auth::user())
            ->where('subject_id', $subject->id)
            ->where('is_active', true)
            ->with('student:id,given_name,surname,user_id,profile_picture')
            ->get()
            ->pluck('student')
            ->merge($scores->pluck('student'))
            ->filter()
            ->unique('id')
            ->sortBy([
                [fn ($a, $b) => mb_strtolower($a->surname ?? '') <=> mb_strtolower($b->surname ?? ''), 'asc'],
                [fn ($a, $b) => mb_strtolower($a->given_name ?? '') <=> mb_strtolower($b->given_name ?? ''), 'asc'],
            ])
            ->values();
    }

    public function destroy(Request $request, Score $score): JsonResponse|RedirectResponse
    {
        $this->authorize('delete', $score);
        abort_unless(! $score->trashed(), 404);
        $score->delete();

        if ($request->expectsJson()) {
            return response()->json([
                'status' => 'success',
                'message' => 'Score moved to Archived Scores.',
                'restore_url' => route('educator.scores.restore', $score, false),
            ]);
        }

        return redirect()->route('educator.scores.index')->with('status', 'Score archived.');
    }

    public function deleted(Request $request): View
    {
        $this->authorize('viewAny', Score::class);

        $scores = Score::onlyTrashed()
            ->visibleTo(Auth::user())
            ->with([
                'student:id,given_name,surname,user_id',
                'assessment:id,assessment_code',
                'subject:id,subject_code,subject_name',
                'section:id,section_name',
            ])
            ->latest('deleted_at')
            ->paginate(TableQuery::perPage($request))
            ->withQueryString();

        return view('educator.scores.deleted', compact('scores'));
    }

    public function restore(Request $request, Score $score): JsonResponse|RedirectResponse
    {
        abort_unless($score->trashed(), 404);
        $this->authorize('restore', $score);
        $score->restore();

        if ($request->expectsJson()) {
            return response()->json([
                'status' => 'success',
                'message' => 'Score restored.',
            ]);
        }

        return redirect()->route('educator.scores.deleted')->with('status', 'Score restored.');
    }

    public function show(Score $score): View
    {
        $this->authorize('view', $score);

        // Attempt detail: per-question correct answer + student answer + isCorrect.
        // correct_answer is loaded SERVER-SIDE here (educator view) — never serialized to a student.
        // Only this attempt's pinned drawn subset — not the assessment's full eligible pool.
        $score->load(['student:id,given_name,surname,user_id']);
        // withTrashed: a question deleted from the bank after this attempt must still resolve
        // here so historical per-question review stays whole (Task 13).
        $reviewQuestions = Quiz::withTrashed()->whereIn('id', $score->drawn_quiz_ids ?? [])->get();

        return view('educator.scores.show', compact('score', 'reviewQuestions'));
    }

    public function grantRetake(GrantRetakeRequest $request): RedirectResponse
    {
        $this->authorize('create', Assessment::class);

        $data = $request->validated();
        StudentAssessmentRetake::updateOrCreate(
            ['educator_id' => Auth::id(), 'student_id' => $data['student_id'], 'assessment_id' => $data['assessment_id']],
            ['extra_retake_count' => $data['extra_retake_count'], 'is_active' => true],
        );

        $this->notifications->emit(Auth::user(), 'retake_updated', (int) $data['student_id'], [
            'assessment_id' => (int) $data['assessment_id'], 'title' => 'Retake granted',
            'subject_id' => Assessment::find($data['assessment_id'])?->subject_id,
            'link_path' => route('student.assessments.index'),
        ]);

        return back()->with('status', 'Retake granted.');
    }

    public function uploadTemplate()
    {
        $this->authorize('import', Score::class);

        return Excel::download(new ScoreUploadTemplateExport, 'offline-score-upload-template.xlsx');
    }

    public function upload(Request $request): RedirectResponse
    {
        $this->authorize('import', Score::class);

        $request->validate([
            'term_id' => ['required', 'integer', 'exists:tbl_academic_term,id'],
            'assessment_uuid' => ['required', 'uuid'],
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv'],
        ]);

        $assessment = Assessment::visibleTo($request->user())
            ->where('uuid', $request->input('assessment_uuid'))
            ->firstOrFail();

        if ((int) $assessment->term !== (int) $request->input('term_id')) {
            throw ValidationException::withMessages([
                'assessment_uuid' => ['The selected assessment does not belong to the selected term.'],
            ]);
        }

        $import = new OfflineScoresImport;
        Excel::import($import, $request->file('file'));

        $created = $this->offlineUploads->import($request->user(), $assessment, $import->rows);

        return redirect()->route('educator.scores.index')->with('status', "Uploaded {$created} offline score(s).");
    }

    // Task 27: preview counts (enrolled/with-submission/without) shown before export.
    public function exportPreview(Assessment $assessment): JsonResponse
    {
        $this->authorize('view', $assessment);

        return response()->json($this->exporter->preview($assessment));
    }

    // G8/Task 27: single-assessment xlsx export — roster-complete, best-attempt resolved, styled.
    public function export(Assessment $assessment)
    {
        $this->authorize('view', $assessment);

        $book = $this->exporter->single($assessment);

        return response()->streamDownload(function () use ($book) {
            (new Xlsx($book))->save('php://output');
        }, "scores-{$assessment->assessment_code}.xlsx", [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    // G8/Task 27: bulk export → zip of TERM/SUBJECT/SECTION.xlsx. type = all|term|semester.
    public function exportBulk(ExportScoresBulkRequest $request)
    {
        $this->authorize('viewAny', Score::class);

        return $this->exporter->bulk(Auth::user(), $request->validated());
    }
}
