<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Enrolled;
use App\Models\LearningMaterial;
use App\Models\Section;
use App\Models\Subject;
use App\Support\TableQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

// H9: student materials — enrollment-gated list + download. visibleTo restricts to active
// materials for subjects the student is actively enrolled in.
class MaterialController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', LearningMaterial::class);

        $query = LearningMaterial::visibleTo(Auth::user())
            ->select('tbl_learning_materials.*')
            ->leftJoin('tbl_subjects as sort_subjects', 'sort_subjects.id', '=', 'tbl_learning_materials.subject_id')
            ->leftJoin('tbl_sections as sort_sections', 'sort_sections.id', '=', 'tbl_learning_materials.section_id')
            ->with(['subjects:id,subject_code,subject_name,sections_id', 'subjects.section:id,section_name']);
        TableQuery::search($query, $request->query('search'), ['file_name', 'file_extension']);
        // Task 32: one file may be shared across subjects, so filter through the pivot.
        TableQuery::filters($query, $request, [
            'subject' => fn (Builder $q, $value) => $q->whereHas('subjects', fn ($s) => $s->whereKey($value)),
            'section' => fn (Builder $q, $value) => $q->whereHas('subjects', fn ($s) => $s->where('sections_id', $value)),
        ]);
        TableQuery::sort($query, $request, [
            'file' => 'file_name',
            'subject' => function (Builder $q, string $direction): void {
                $q->orderBy('sort_subjects.subject_code', $direction)
                    ->orderBy('sort_subjects.subject_name', $direction)
                    ->orderBy('tbl_learning_materials.id', 'desc');
            },
            'section' => 'sort_sections.section_name',
            'type' => 'file_extension',
            'updated' => 'updated_at',
            'id' => 'id',
        ], 'id', 'desc');

        // Task 32: one row is one file now — nothing left to group by.
        $materials = $query->paginate(TableQuery::perPage($request))->withQueryString();

        $enrolledSubjectIds = Enrolled::visibleTo(Auth::user())->where('student_id', Auth::id())->where('is_active', true)->pluck('subject_id')->unique();
        $filterSubjects = Subject::visibleTo(Auth::user())->whereIn('id', $enrolledSubjectIds)->orderBy('subject_name')->get(['id', 'subject_code', 'subject_name']);
        $enrolledSectionIds = Subject::visibleTo(Auth::user())->whereIn('id', $enrolledSubjectIds)->pluck('sections_id')->unique()->filter();
        $filterSections = Section::visibleTo(Auth::user())->whereIn('id', $enrolledSectionIds)->orderBy('section_name')->get(['id', 'section_name']);

        // A shared file may also be assigned to subjects this student is not enrolled in — the view
        // renders the intersection only, so the list never leaks another class's subject names.
        return view('student.materials.index', compact('materials', 'filterSubjects', 'filterSections', 'enrolledSubjectIds'));
    }

    public function download(LearningMaterial $material)
    {
        $this->authorize('view', $material); // enrollment re-checked

        $diskName = $material->readableStorageDisk();
        abort_unless($diskName, 404);

        return Storage::disk($diskName)->download($material->storage_path, $material->file_name);
    }
}
