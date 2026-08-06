<?php

namespace App\Http\Controllers\Educator;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMaterialRequest;
use App\Http\Requests\UpdateMaterialRequest;
use App\Models\AcademicTerm;
use App\Models\Enrolled;
use App\Models\LearningMaterial;
use App\Models\Section;
use App\Models\Subject;
use App\Services\NotificationService;
use App\Support\TableQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

// G9: learning materials. Files stored on the 'local' disk; download via a signed temporary
// URL after an access check (ports the source's Supabase 60s signed URLs).
class MaterialController extends Controller
{
    private const DISK = LearningMaterial::PRIVATE_DISK;

    public function __construct(private NotificationService $notifications) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', LearningMaterial::class);

        $query = LearningMaterial::visibleTo(Auth::user())
            ->with(['subjects:id,subject_code,subject_name,sections_id', 'subjects.section:id,section_name']);
        TableQuery::search($query, $request->query('search'), ['file_name', 'file_extension']);
        // Task 29/32: materials have no term column — they inherit it through subject → section →
        // academic term. Every filter here reads the tbl_learning_material_subject pivot, not the
        // primary subject_id/section_id columns, so a file shared across subjects is found under
        // each of them. The term filter must also read the tbl_sections_term PIVOT (not the legacy
        // tbl_sections.academic_term_id scalar): a section rolled from PRELIM into MIDTERM keeps
        // academic_term_id = PRELIM, so the old closure matched zero sections and every term
        // returned "No materials" — the same chain inActiveTerm() gates on.
        TableQuery::filters($query, $request, [
            'subject' => fn (Builder $q, $value) => $q->whereHas('subjects', fn ($s) => $s->whereKey($value)),
            'section' => fn (Builder $q, $value) => $q->whereHas('subjects', fn ($s) => $s->where('sections_id', $value)),
            'status' => 'is_active',
            'term' => fn (Builder $q, $value) => $q->whereHas('subjects.section.terms', fn ($t) => $t->whereKey($value)),
        ]);
        TableQuery::sort($query, $request, [
            'file' => 'file_name',
            'subject' => function (Builder $q, string $direction): void {
                $q->leftJoin('tbl_subjects as sort_subjects', 'sort_subjects.id', '=', 'tbl_learning_materials.subject_id')
                    ->select('tbl_learning_materials.*')
                    ->orderBy('sort_subjects.subject_code', $direction)
                    ->orderBy('sort_subjects.subject_name', $direction)
                    ->orderBy('tbl_learning_materials.id', 'desc');
            },
            'section' => function (Builder $q, string $direction): void {
                $q->leftJoin('tbl_sections as sort_sections', 'sort_sections.id', '=', 'tbl_learning_materials.section_id')
                    ->select('tbl_learning_materials.*')
                    ->orderBy('sort_sections.section_name', $direction)
                    ->orderBy('tbl_learning_materials.id', 'desc');
            },
            'type' => 'file_extension',
            'size' => 'file_size',
            'status' => 'is_active',
            'id' => 'id',
        ], 'id', 'desc');

        // Task 32: one row is one file now, so there is nothing left to group by.
        $materials = $query->paginate(TableQuery::perPage($request))->withQueryString();

        $filterSubjects = Subject::visibleTo(Auth::user())->orderBy('subject_code')->get(['id', 'subject_code', 'subject_name']);
        $filterSections = Section::visibleTo(Auth::user())->orderBy('section_name')->get(['id', 'section_name']);
        $filterTerms = AcademicTerm::where('is_active', true)->orderBy('term_name')->get(['id', 'term_name']);

        return view('educator.materials.index', compact('materials', 'filterSubjects', 'filterSections', 'filterTerms'));
    }

    public function create(): View
    {
        $this->authorize('create', LearningMaterial::class);

        return view('educator.materials.create', [
            'subjects' => Subject::visibleTo(Auth::user())->orderBy('subject_code')->get(),
        ]);
    }

    public function store(StoreMaterialRequest $request): RedirectResponse
    {
        $this->authorize('create', LearningMaterial::class);

        $data = $request->validated();
        $subjects = Subject::visibleTo(Auth::user())->whereKey($data['subject_ids'])->get();

        // Task 32: one file is ONE material row, shared with every selected subject through the
        // pivot. It used to be one row per (file × subject), which duplicated the record for every
        // destination even though the stored object was already shared.
        $links = $subjects->mapWithKeys(fn (Subject $subject) => [
            $subject->id => ['section_id' => $subject->sections_id],
        ])->all();
        $primary = $subjects->first();

        $files = $request->file('files');
        foreach ($files as $file) {
            $path = $file->store('learning-materials/'.Auth::id(), self::DISK);
            $material = LearningMaterial::create([
                'educator_id' => Auth::id(),
                'subject_id' => $primary->id,
                'section_id' => $primary->sections_id,
                'storage_bucket' => self::DISK,
                'storage_path' => $path,
                'file_name' => $file->getClientOriginalName(),
                'file_extension' => $file->getClientOriginalExtension(),
                'mime_type' => $file->getClientMimeType(),
                'file_size' => $file->getSize(),
                'is_active' => true,
            ]);
            $material->subjects()->sync($links);
        }

        // One message per student per subject (authorization checks enrollment per subject_id),
        // carrying every uploaded file's real name/extension/size so the notification can render
        // one attachment card per file (not one notification per file).
        $count = count($files);
        $fileList = array_map(fn ($f) => [
            'file_name' => $f->getClientOriginalName(),
            'file_extension' => $f->getClientOriginalExtension(),
            'file_size' => $f->getSize(),
        ], $files);
        foreach ($subjects as $subject) {
            $this->notifications->emitToMany(Auth::user(), 'learning_material_uploaded', $this->enrolledStudentIds($subject->id), [
                'subject_id' => $subject->id, 'section_id' => $subject->sections_id,
                'title' => $count === 1 ? 'uploaded 1 attachment' : "uploaded $count attachments",
                'link_path' => route('student.materials.index'),
                'metadata' => ['file_count' => $count, 'files' => $fileList],
            ]);
        }

        return redirect()->route('educator.materials.index')->with('status', 'Material(s) uploaded.');
    }

    public function edit(LearningMaterial $material): View
    {
        $this->authorize('update', $material);

        return view('educator.materials.edit', compact('material'));
    }

    public function update(UpdateMaterialRequest $request, LearningMaterial $material): RedirectResponse
    {
        $this->authorize('update', $material);

        $material->update($request->validated()); // metadata only; storage object untouched

        return redirect()->route('educator.materials.index')->with('status', 'Material updated.');
    }

    public function destroy(LearningMaterial $material): RedirectResponse
    {
        $this->authorize('delete', $material);

        // Capture recipients + context before the row (and its pivot links) are gone. Task 32:
        // every linked subject is notified, not just the primary.
        $targets = $material->subjects->map(fn (Subject $subject) => [
            'subject_id' => $subject->id,
            'section_id' => $subject->pivot->section_id ?? $subject->sections_id,
        ]);

        // Orphan cleanup: a pre-Task-32 upload may still have sibling rows sharing this storage
        // object — only delete it from storage once no row references it anymore.
        $stillReferenced = LearningMaterial::where('id', '!=', $material->id)
            ->where('storage_path', $material->storage_path)
            ->exists();
        $material->delete();
        if (! $stillReferenced) {
            Storage::disk($material->storageDisk())->delete($material->storage_path);
        }

        foreach ($targets as $target) {
            $this->notifications->emitToMany(Auth::user(), 'learning_material_deleted', $this->enrolledStudentIds($target['subject_id']), [
                'subject_id' => $target['subject_id'], 'section_id' => $target['section_id'],
                'title' => 'Learning material removed',
                'link_path' => route('student.materials.index'),
            ]);
        }

        return redirect()->route('educator.materials.index')->with('status', 'Material deleted.');
    }

    // Task 13: bulk delete selected materials in one action. Batch-aware version of destroy()'s
    // orphan guard — rows are deleted first, then each distinct storage_path is GC'd only once no
    // surviving row references it, so a file shared across subjects isn't dropped while still in use.
    public function bulkDestroy(Request $request): RedirectResponse
    {
        $this->authorize('viewAny', LearningMaterial::class);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', Rule::exists('tbl_learning_materials', 'id')],
        ]);

        $materials = LearningMaterial::with('subjects')->where('educator_id', Auth::id())->whereKey($data['ids'])->get();

        // Recipients + shared paths captured before rows are gone. Task 32: grouped by every
        // linked subject, not just each row's primary.
        $notifyBySubject = $materials
            ->flatMap(fn (LearningMaterial $m) => $m->subjects->map(fn (Subject $s) => [
                'subject_id' => $s->id,
                'section_id' => $s->pivot->section_id ?? $s->sections_id,
            ]))
            ->groupBy('subject_id');
        $paths = $materials->pluck('storage_path')->unique();

        DB::transaction(function () use ($materials): void {
            foreach ($materials as $material) {
                $this->authorize('delete', $material);
                $material->delete();
            }
        });

        foreach ($paths as $path) {
            $stillReferenced = LearningMaterial::where('storage_path', $path)->exists();
            if (! $stillReferenced) {
                Storage::disk(self::DISK)->delete($path);
            }
        }

        foreach ($notifyBySubject as $subjectId => $group) {
            $this->notifications->emitToMany(Auth::user(), 'learning_material_deleted', $this->enrolledStudentIds((int) $subjectId), [
                'subject_id' => (int) $subjectId, 'section_id' => $group->first()['section_id'],
                'title' => 'Learning material removed',
                'link_path' => route('student.materials.index'),
            ]);
        }

        return redirect()->route('educator.materials.index')->with('status', 'Selected materials deleted.');
    }

    // Signed temporary URL target — access re-checked here even though the URL is signed.
    public function download(LearningMaterial $material)
    {
        $this->authorize('view', $material);

        $diskName = $material->readableStorageDisk();
        abort_unless($diskName, 404);

        return Storage::disk($diskName)->download($material->storage_path, $material->file_name);
    }

    /** Build a 60s signed URL for a material (used by views/students later). */
    public static function temporaryUrl(LearningMaterial $material): string
    {
        return URL::temporarySignedRoute('educator.materials.download', now()->addSeconds(60), ['material' => $material->id]);
    }

    private function enrolledStudentIds(int $subjectId): array
    {
        return Enrolled::where('educator_id', Auth::id())
            ->where('subject_id', $subjectId)
            ->where('is_active', true)
            ->pluck('student_id')->all();
    }
}
