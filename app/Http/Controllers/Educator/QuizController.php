<?php

namespace App\Http\Controllers\Educator;

use App\Exports\QuizUploadTemplateExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreQuizRequest;
use App\Http\Requests\UpdateQuizRequest;
use App\Imports\QuizzesImport;
use App\Models\Assessment;
use App\Models\Quiz;
use App\Models\Section;
use App\Models\Subject;
use App\Support\TableQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;

// Task 51: educator question bank. Questions are scoped to educator+subject, reusable across
// any number of assessments via each assessment's pool config (AssessmentQuestionPoolController).
// correct_answer is server-only ($hidden on the model); never sent to a student.
class QuizController extends Controller
{
    private const SINGLE_BATCH_PREFIX = '__single__:';

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Quiz::class);

        $selectedSection = $request->query('section');
        $selectedSubject = $request->query('subject');
        $selectedAssessment = $request->query('assessment');

        // Task 32: every subject/section predicate reads the tbl_quiz_subject pivot, so a question
        // shared across subjects is found under each of them.
        $query = Quiz::visibleTo(Auth::user())
            ->with(['subjects:id,subject_code,subject_name,sections_id', 'subjects.section:id,section_name', 'eligibleAssessments:id,assessment_code']);
        TableQuery::search($query, $request->query('search'), [
            'question',
            fn (Builder $q, string $term) => $q->orWhereHas('subjects', fn ($s) => $s
                ->where('subject_code', 'like', "%{$term}%")
                ->orWhere('subject_name', 'like', "%{$term}%")),
        ]);
        TableQuery::filters($query, $request, [
            'section' => fn (Builder $q, string $value) => $q->whereHas('subjects', fn ($s) => $s->where('sections_id', $value)),
            'subject' => fn (Builder $q, string $value) => $q->whereHas('subjects', fn ($s) => $s->whereKey($value)
                ->when($selectedSection, fn ($s) => $s->where('sections_id', $selectedSection))),
            'assessment' => fn (Builder $q, string $value) => $q->whereHas('eligibleAssessments', fn ($a) => $a
                ->where('assessment_code', $value)
                ->when($selectedSection, fn ($a) => $a->whereHas('subject', fn ($s) => $s->where('sections_id', $selectedSection)))
                ->when($selectedSubject, fn ($a) => $a->where('subject_id', $selectedSubject))),
            'batch' => fn (Builder $q, string $value) => $q->where('batch_label', $value)
                ->when($selectedSection, fn (Builder $q) => $q->whereHas('subjects', fn ($s) => $s->where('sections_id', $selectedSection)))
                ->when($selectedSubject, fn (Builder $q) => $q->whereHas('subjects', fn ($s) => $s->whereKey($selectedSubject)))
                ->when($selectedAssessment, fn (Builder $q) => $q->whereHas('eligibleAssessments', fn ($a) => $a->where('assessment_code', $selectedAssessment))),
        ]);
        TableQuery::sort($query, $request, ['question' => 'question', 'id' => 'id'], 'id', 'desc');

        $quizzes = $query->paginate(TableQuery::perPage($request))->withQueryString();
        $filterSections = $this->sectionOptions();
        $filterSubjects = $this->subjectOptions($selectedSection);
        // Task 32: the upload modal must offer every subject regardless of the active section
        // filter — a shared upload deliberately spans sections.
        $uploadSubjects = $this->subjectOptions();
        $filterAssessments = $this->assessmentFilterOptions($selectedSection, $selectedSubject);
        $batches = $this->batchOptions($selectedSection, $selectedSubject, $selectedAssessment);

        return view('educator.quizzes.index', compact('quizzes', 'filterSections', 'filterSubjects', 'uploadSubjects', 'filterAssessments', 'batches'));
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Quiz::class);

        return view('educator.quizzes.form', [
            'subjects' => $this->subjectOptions(),
            'selectedSubject' => $request->query('subject_id'),
        ]);
    }

    public function store(StoreQuizRequest $request): RedirectResponse
    {
        $this->authorize('create', Quiz::class);

        $data = $request->validated();
        $subjects = Subject::visibleTo(Auth::user())->whereKey($data['subject_ids'])->get();
        abort_if($subjects->isEmpty(), 404);
        $quiz = $this->makeQuiz($subjects, $data);
        $this->syncAssessments($quiz, $data['assessment_ids'] ?? []);

        return redirect()->route('educator.quizzes.index')->with('status', 'Question added to the bank.');
    }

    public function edit(Quiz $quiz): View
    {
        $this->authorize('update', $quiz);

        return view('educator.quizzes.edit', [
            'quiz' => $quiz,
            'subjects' => $this->subjectOptions(),
        ]);
    }

    public function update(UpdateQuizRequest $request, Quiz $quiz): RedirectResponse
    {
        $this->authorize('update', $quiz);

        $data = $request->validated();
        $subjects = Subject::visibleTo(Auth::user())->whereKey($data['subject_ids'])->get();
        abort_if($subjects->isEmpty(), 404);
        $quiz->update([
            'subject_id' => $subjects->first()->id,
            'question' => $data['question'],
            'quiz_type' => $data['quiz_type'],
            'choices' => $data['quiz_type'] === 'multiple_choice' ? $data['choices'] : null,
            'correct_answer' => $this->correctAnswerFrom($data),
        ]);
        // sync(), not syncWithoutDetaching(): unticking a subject here must actually unshare it.
        // The saved() mirror above has already re-added the primary, which is in this list anyway.
        $quiz->subjects()->sync($subjects->pluck('id')->all());
        if (array_key_exists('assessment_ids', $data)) {
            $this->syncAssessments($quiz, $data['assessment_ids'] ?? []);
        }

        return redirect()->route('educator.quizzes.index')->with('status', 'Question updated.');
    }

    public function destroy(Quiz $quiz): RedirectResponse
    {
        $this->authorize('delete', $quiz);

        $quiz->delete();

        return redirect()->route('educator.quizzes.index')->with('status', 'Question deleted.');
    }

    public function bulkDelete(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'quiz_ids' => ['required', 'array', 'min:1'],
            'quiz_ids.*' => ['integer', Rule::exists('tbl_quizzes', 'id')],
        ]);

        $quizzes = Quiz::visibleTo(Auth::user())->whereKey($data['quiz_ids'])->get();

        DB::transaction(function () use ($quizzes): void {
            foreach ($quizzes as $quiz) {
                $this->authorize('delete', $quiz);
                $quiz->delete();
            }
        });

        return redirect()->route('educator.quizzes.index')->with('status', 'Selected questions deleted.');
    }

    public function archive(Request $request): RedirectResponse
    {
        $this->authorize('viewAny', Quiz::class);

        $data = $request->validate([
            'quiz_ids' => ['nullable', 'array', 'min:1'],
            'quiz_ids.*' => ['integer', Rule::exists('tbl_quizzes', 'id')],
            'batch_labels' => ['nullable', 'array', 'min:1'],
            'batch_labels.*' => ['string'],
        ]);

        if (blank($data['quiz_ids'] ?? null) && blank($data['batch_labels'] ?? null)) {
            throw ValidationException::withMessages([
                'batch_labels' => 'Please choose at least one question batch to archive.',
            ]);
        }

        $selected = Quiz::visibleTo(Auth::user())->whereKey($data['quiz_ids'] ?? [])->get();
        $batchLabels = collect($data['batch_labels'] ?? [])
            ->merge($selected->pluck('batch_label')->filter())
            ->filter(fn ($label) => filled($label))
            ->map(fn ($label) => trim((string) $label))
            ->unique()
            ->values();
        $singleIds = $selected->whereNull('batch_label')->pluck('id')->values();

        [$batchCount, $questionCount] = DB::transaction(function () use ($batchLabels, $singleIds): array {
            $toArchive = Quiz::visibleTo(Auth::user())
                ->where(function (Builder $query) use ($batchLabels, $singleIds): void {
                    if ($batchLabels->isNotEmpty()) {
                        $query->whereIn('batch_label', $batchLabels->all());
                    }

                    if ($singleIds->isNotEmpty()) {
                        if ($batchLabels->isNotEmpty()) {
                            $query->orWhereIn('id', $singleIds->all());
                        } else {
                            $query->whereIn('id', $singleIds->all());
                        }
                    }
                })
                ->get();

            foreach ($toArchive as $quiz) {
                $this->authorize('delete', $quiz);
                $quiz->delete();
            }

            return [$batchLabels->count() + $singleIds->count(), $toArchive->count()];
        });

        return redirect()->route('educator.quizzes.index')
            ->with('status', "{$batchCount} batch(es) archived ({$questionCount} question(s)).");
    }

    public function archived(Request $request): View
    {
        $this->authorize('viewAny', Quiz::class);

        // ponytail: archived batches are grouped and filtered in PHP; move this to an aggregate
        // query only if archived question volume gets large enough to matter.
        $quizzes = Quiz::onlyTrashed()
            ->visibleTo(Auth::user())
            ->with(['subjects:id,subject_code,subject_name,sections_id', 'subjects.section:id,section_name', 'eligibleAssessments:id,assessment_code'])
            ->orderByDesc('deleted_at')
            ->orderByDesc('id')
            ->get();

        $selectedSection = (string) $request->query('section', '');
        $selectedSubject = (string) $request->query('subject', '');
        $selectedBatch = (string) $request->query('batch', '');
        $search = trim((string) $request->query('search', ''));

        // Task 32: a question may sit under several subjects, so "is this quiz in section X /
        // subject Y" is an any-of test over the pivot, not a comparison with one column.
        $inSection = fn (Quiz $quiz, string $sectionId) => $quiz->subjects->contains(fn ($s) => (string) $s->sections_id === $sectionId);
        $inSubject = fn (Quiz $quiz, string $subjectId) => $quiz->subjects->contains(fn ($s) => (string) $s->id === $subjectId);

        $filterSections = $quizzes->flatMap->subjects
            ->pluck('section')
            ->filter()
            ->unique('id')
            ->sortBy('section_name')
            ->values();
        $filterSubjects = $quizzes
            ->when($selectedSection !== '', fn ($items) => $items->filter(fn (Quiz $quiz) => $inSection($quiz, $selectedSection)))
            ->flatMap->subjects
            ->filter()
            ->unique('id')
            ->sortBy('subject_name')
            ->values();
        $filterBatches = $quizzes
            ->when($selectedSection !== '', fn ($items) => $items->filter(fn (Quiz $quiz) => $inSection($quiz, $selectedSection)))
            ->when($selectedSubject !== '', fn ($items) => $items->filter(fn (Quiz $quiz) => $inSubject($quiz, $selectedSubject)))
            ->filter(fn (Quiz $quiz) => filled($quiz->batch_label))
            ->groupBy('batch_label')
            ->map(fn ($items, string $label) => ['label' => $label, 'count' => $items->count()])
            ->sortByDesc('count')
            ->values();

        $groups = $quizzes
            ->filter(function (Quiz $quiz) use ($selectedSection, $selectedSubject, $selectedBatch, $search, $inSection, $inSubject): bool {
                if ($selectedSection !== '' && ! $inSection($quiz, $selectedSection)) {
                    return false;
                }

                if ($selectedSubject !== '' && ! $inSubject($quiz, $selectedSubject)) {
                    return false;
                }

                if ($selectedBatch !== '' && (string) $quiz->batch_label !== $selectedBatch) {
                    return false;
                }

                if ($search === '') {
                    return true;
                }

                $haystack = strtolower(implode(' ', array_filter([
                    $quiz->batch_label,
                    $quiz->question,
                    $quiz->subjects->pluck('subject_code')->implode(' '),
                    $quiz->subjects->pluck('subject_name')->implode(' '),
                    $quiz->subjects->pluck('section.section_name')->filter()->implode(' '),
                    $quiz->eligibleAssessments->pluck('assessment_code')->implode(' '),
                ])));

                return str_contains($haystack, strtolower($search));
            })
            ->groupBy(fn (Quiz $quiz) => $this->archiveBatchKey($quiz))
            ->map(function ($quizzes, string $key): array {
                /** @var Quiz $first */
                $first = $quizzes->first();

                return [
                    'key' => $key,
                    'label' => $first->batch_label ?? 'Single Question',
                    'count' => $quizzes->count(),
                    'subjects' => $first->subjects,
                    'sections' => $first->subjects->pluck('section')->filter()->unique('id')->values(),
                    'deleted_at' => $quizzes->max('deleted_at'),
                    'assessments' => $quizzes->flatMap(fn (Quiz $quiz) => $quiz->eligibleAssessments->pluck('assessment_code'))->unique()->values(),
                ];
            })
            ->sortByDesc(fn (array $group) => optional($group['deleted_at'])->getTimestamp() ?? 0)
            ->values();

        $page = max((int) $request->query('page', 1), 1);
        $perPage = TableQuery::perPage($request);
        $groups = new LengthAwarePaginator(
            $groups->forPage($page, $perPage)->values(),
            $groups->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return view('educator.quizzes.archived', compact('groups', 'filterSections', 'filterSubjects', 'filterBatches'));
    }

    public function restoreArchived(Request $request): RedirectResponse
    {
        $this->authorize('viewAny', Quiz::class);

        $data = $request->validate([
            'batch_labels' => ['required', 'array', 'min:1'],
            'batch_labels.*' => ['required', 'string'],
        ]);

        [$batchCount, $questionCount] = DB::transaction(function () use ($data): array {
            $selectedKeys = collect($data['batch_labels']);
            $toRestore = Quiz::onlyTrashed()
                ->visibleTo(Auth::user())
                ->get()
                ->filter(fn (Quiz $quiz) => $selectedKeys->contains($this->archiveBatchKey($quiz)));

            foreach ($toRestore as $quiz) {
                $this->authorize('restore', $quiz);
                $quiz->restore();
            }

            return [$selectedKeys->count(), $toRestore->count()];
        });

        return redirect()->route('educator.quizzes.archived')
            ->with('status', "{$batchCount} batch(es) restored ({$questionCount} question(s)).");
    }

    // G6 bulk upload.
    public function uploadTemplate()
    {
        $this->authorize('create', Quiz::class);

        return Excel::download(new QuizUploadTemplateExport, 'quiz-upload-template.xlsx');
    }

    public function upload(Request $request): RedirectResponse
    {
        $this->authorize('create', Quiz::class);

        // Task 32: one file may be filed under several subjects at once. A subject belongs to
        // exactly one section, so picking subjects across sections IS the multi-section case.
        $request->validate([
            'subject_ids' => ['required', 'array', 'min:1'],
            'subject_ids.*' => ['integer', Rule::exists('tbl_subjects', 'id')->where('educator_id', Auth::id())],
            'assessment_ids' => ['nullable', 'array'],
            'assessment_ids.*' => [Rule::exists('tbl_assessments', 'id')->where('educator_id', Auth::id())],
            'files' => ['required', 'array', 'min:1'],
            'files.*' => ['required', 'file', 'mimes:xlsx,xls,csv'],
        ], [
            'subject_ids.required' => 'Please choose at least one subject for these questions.',
            'files.required' => 'Please choose at least one file to upload.',
            'files.*.mimes' => 'Wrong file format: :input is not an Excel/CSV file. Only .xlsx, .xls or .csv are accepted.',
            'files.*.file' => 'The upload was not a valid file.',
        ]);

        $subjects = Subject::visibleTo(Auth::user())->whereKey($request->input('subject_ids'))->get();
        abort_if($subjects->isEmpty(), 404);
        $subjectIds = $subjects->pluck('id')->all();
        $primary = $subjects->first();
        $assessmentIds = array_filter((array) $request->input('assessment_ids', []));
        $files = $request->file('files');
        $uploadedAt = now()->format('M j, Y g:i A');

        // All-or-nothing: validate every row of every file FIRST. If ANY row is bad (wrong
        // format, blank, missing/invalid columns), reject the whole upload — nothing is saved.
        // The file is parsed ONCE regardless of how many subjects it is shared with.
        $errors = [];
        $rows = [];
        foreach ($files as $file) {
            $batchLabel = "Upload: {$file->getClientOriginalName()} · {$uploadedAt}";
            $import = new QuizzesImport($primary, $file->getClientOriginalName(), $batchLabel);
            Excel::import($import, $file);
            $errors = array_merge($errors, $import->errors());
            $rows = array_merge($rows, $import->validRows());
        }

        if ($errors !== []) {
            // Surfaced as a validation-error toast; nothing was written.
            throw ValidationException::withMessages(['files' => $errors]);
        }

        $created = DB::transaction(function () use ($rows, $subjectIds, $assessmentIds) {
            // One row per question, not one per (question × subject) — sharing lives in the pivot.
            $quizIds = [];
            foreach ($rows as $row) {
                $quiz = Quiz::create($row); // create() applies the choices array cast + timestamps
                $quiz->subjects()->sync($subjectIds);
                $quizIds[] = $quiz->id;
            }
            foreach (Assessment::whereKey($assessmentIds)->get() as $assessment) {
                $assessment->eligibleQuizzes()->syncWithoutDetaching($quizIds);
            }

            return count($rows);
        });

        $n = count($subjectIds);

        return redirect()->route('educator.quizzes.index')
            ->with('status', $n === 1
                ? "Uploaded {$created} question(s) to the bank."
                : "Uploaded {$created} question(s) to the bank, shared across {$n} subjects.");
    }

    private function sectionOptions()
    {
        return Section::visibleTo(Auth::user())
            ->orderBy('section_name')->get(['id', 'section_name']);
    }

    private function subjectOptions(?string $sectionId = null)
    {
        return Subject::visibleTo(Auth::user())
            ->when($sectionId, fn ($q) => $q->where('sections_id', $sectionId))
            ->with('section:id,section_name')
            ->orderBy('subject_name')->get(['id', 'subject_code', 'subject_name', 'sections_id']);
    }

    private function assessmentFilterOptions(?string $sectionId = null, ?string $subjectId = null)
    {
        return Assessment::visibleTo(Auth::user())->notArchived()
            ->when($sectionId, fn ($q) => $q->whereHas('subject', fn ($s) => $s->where('sections_id', $sectionId)))
            ->when($subjectId, fn ($q) => $q->where('subject_id', $subjectId))
            ->select('assessment_code')
            ->distinct()
            ->orderBy('assessment_code')
            ->pluck('assessment_code');
    }

    // Distinct batch labels for this educator's questions, newest first, for the bank's filter dropdown.
    private function batchOptions(?string $sectionId = null, ?string $subjectId = null, ?string $assessmentCode = null)
    {
        return Quiz::visibleTo(Auth::user())
            ->when($sectionId, fn ($q) => $q->whereHas('subjects', fn ($s) => $s->where('sections_id', $sectionId)))
            ->when($subjectId, fn ($q) => $q->whereHas('subjects', fn ($s) => $s->whereKey($subjectId)))
            ->when($assessmentCode, fn ($q) => $q->whereHas('eligibleAssessments', fn ($a) => $a->where('assessment_code', $assessmentCode)))
            ->whereNotNull('batch_label')
            ->selectRaw('batch_label, COUNT(*) as question_count, MAX(id) as max_id')
            ->groupBy('batch_label')
            ->orderByDesc('max_id')
            ->get();
    }

    private function syncAssessments(Quiz $quiz, array $assessmentIds): void
    {
        $quiz->eligibleAssessments()->sync(array_filter($assessmentIds));
    }

    private function archiveBatchKey(Quiz $quiz): string
    {
        return $quiz->batch_label ?? self::SINGLE_BATCH_PREFIX.$quiz->getKey();
    }

    /** @param  Collection<int, Subject>  $subjects */
    private function makeQuiz(Collection $subjects, array $data): Quiz
    {
        $quiz = Quiz::create([
            'subject_id' => $subjects->first()->id,
            'educator_id' => Auth::id(),
            'question' => $data['question'],
            'quiz_type' => $data['quiz_type'],
            'choices' => $data['quiz_type'] === 'multiple_choice' ? $data['choices'] : null,
            'correct_answer' => $this->correctAnswerFrom($data),
            'batch_label' => 'Manual · '.now()->format('M j, Y g:i A'),
        ]);
        $quiz->subjects()->sync($subjects->pluck('id')->all());

        return $quiz;
    }

    // MC: the picked choice key. Identification: one accepted answer stored plain,
    // multiple stored as a JSON array (QuizGradingService accepts either form).
    private function correctAnswerFrom(array $data): string
    {
        if ($data['quiz_type'] === 'multiple_choice') {
            return $data['correct_answer'];
        }

        $answers = array_values(array_filter(
            array_map('trim', $data['answers'] ?? []),
            fn ($a) => $a !== ''
        ));

        return count($answers) === 1 ? $answers[0] : json_encode($answers);
    }
}
