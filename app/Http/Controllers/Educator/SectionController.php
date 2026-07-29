<?php

namespace App\Http\Controllers\Educator;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSectionRequest;
use App\Http\Requests\UpdateSectionRequest;
use App\Models\AcademicTerm;
use App\Models\Section;
use App\Support\TableQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

// G2: educator sections. Ownership-gated (visibleTo); section↔term M:N replaced on update.
class SectionController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Section::class);

        $query = Section::visibleTo(Auth::user())
            ->with('terms:id,term_name,semester')
            ->withCount('terms');
        TableQuery::search($query, $request->query('search'), ['section_name']);
        TableQuery::filters($query, $request, ['status' => 'is_active']);
        TableQuery::sort($query, $request, ['section' => 'section_name', 'terms' => 'terms_count', 'status' => 'is_active', 'id' => 'id'], 'id', 'desc');

        $sections = $query->paginate(TableQuery::perPage($request))->withQueryString();

        return view('educator.sections.index', compact('sections'));
    }

    public function create(): View
    {
        $this->authorize('create', Section::class);

        return view('educator.sections.create', ['terms' => AcademicTerm::with('year')->where('is_active', true)->get()]);
    }

    public function store(StoreSectionRequest $request): RedirectResponse
    {
        $this->authorize('create', Section::class);

        $data = $request->validated();
        DB::transaction(function () use ($data) {
            $section = Section::create([
                'educator_id' => Auth::id(),
                'academic_term_id' => $data['academic_term_ids'][0], // primary term (legacy column)
                'section_name' => $data['section_name'],
                'is_active' => $data['is_active'],
            ]);
            $section->terms()->sync($data['academic_term_ids']);
        });

        return redirect()->route('educator.sections.index')->with('status', 'Section created.');
    }

    public function edit(Section $section): View
    {
        $this->authorize('update', $section);

        $section->load('terms:id');

        return view('educator.sections.edit', [
            'section' => $section,
            'terms' => AcademicTerm::with('year')->where('is_active', true)->get(),
        ]);
    }

    public function update(UpdateSectionRequest $request, Section $section): RedirectResponse
    {
        $this->authorize('update', $section);

        $data = $request->validated();
        DB::transaction(function () use ($section, $data) {
            $section->update([
                'academic_term_id' => $data['academic_term_ids'][0],
                'section_name' => $data['section_name'],
                'is_active' => $data['is_active'],
            ]);
            $section->terms()->sync($data['academic_term_ids']); // replace term links
        });

        return redirect()->route('educator.sections.index')->with('status', 'Section updated.');
    }

    public function destroy(Section $section): RedirectResponse
    {
        $this->authorize('delete', $section);

        DB::transaction(function () use ($section) {
            $section->terms()->detach(); // cascade to tbl_sections_term
            $section->delete();
        });

        return redirect()->route('educator.sections.index')->with('status', 'Section deleted.');
    }
}
