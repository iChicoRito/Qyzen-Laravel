<?php

namespace App\Http\Controllers\Educator;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAnnouncementRequest;
use App\Http\Requests\UpdateAnnouncementRequest;
use App\Models\Announcement;
use App\Models\Enrolled;
use App\Models\Subject;
use App\Services\NotificationService;
use App\Support\TableQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class AnnouncementController extends Controller
{
    public function __construct(private NotificationService $notifications) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Announcement::class);
        $query = Announcement::visibleTo(Auth::user())->with('subjects:id,subject_code,subject_name,sections_id');
        TableQuery::search($query, $request->query('search'), ['title', 'description']);
        TableQuery::sort($query, $request, [
            // Task 29: targets are many-to-many now, so a join would duplicate rows — order by
            // the alphabetically-first target instead.
            'title' => 'title', 'subject' => fn ($q, $direction) => $q
                ->orderBy(Subject::query()->selectRaw('MIN(tbl_subjects.subject_name)')
                    ->join('tbl_announcement_subject', 'tbl_announcement_subject.subject_id', '=', 'tbl_subjects.id')
                    ->whereColumn('tbl_announcement_subject.announcement_id', 'tbl_announcements.id'), $direction)
                ->orderBy('tbl_announcements.id', 'desc'),
            'status' => 'is_active', 'created' => 'created_at', 'id' => 'id',
        ], 'id', 'desc');

        return view('educator.announcements.index', [
            'announcements' => $query->paginate(TableQuery::perPage($request))->withQueryString(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Announcement::class);

        return view('educator.announcements.create', ['subjects' => $this->subjects()]);
    }

    public function store(StoreAnnouncementRequest $request): RedirectResponse
    {
        $this->authorize('create', Announcement::class);
        $data = $request->validated();
        $data['educator_id'] = Auth::id();
        $data['is_global'] = (bool) $data['is_global'];
        $subjectIds = $this->targetSubjectIds($data);
        $files = $request->file('images', []);
        unset($data['images'], $data['subject_ids']);
        $announcement = Announcement::create($data + ['images' => $this->storeImages($files)]);
        $announcement->subjects()->sync($subjectIds);
        $this->notifyStudents($announcement);

        return redirect()->route('educator.announcements.index')->with('status', 'Announcement created.');
    }

    public function edit(Announcement $announcement): View
    {
        $this->authorize('update', $announcement);

        return view('educator.announcements.edit', [
            'announcement' => $announcement->load('subjects:id'), 'subjects' => $this->subjects(),
        ]);
    }

    public function update(UpdateAnnouncementRequest $request, Announcement $announcement): RedirectResponse
    {
        $this->authorize('update', $announcement);
        $data = $request->validated();
        $data['is_global'] = (bool) $data['is_global'];
        $subjectIds = $this->targetSubjectIds($data);
        unset($data['images'], $data['subject_ids']);

        if ($request->hasFile('images')) {
            $oldImages = $announcement->images ?? [];
            $data['images'] = $this->storeImages($request->file('images'));
            $this->deleteImages($oldImages);
        }

        $announcement->update($data);
        $announcement->subjects()->sync($subjectIds);

        return redirect()->route('educator.announcements.index')->with('status', 'Announcement updated.');
    }

    public function destroy(Announcement $announcement): RedirectResponse
    {
        $this->authorize('delete', $announcement);
        $this->deleteImages($announcement->images ?? []);
        $announcement->delete();

        return redirect()->route('educator.announcements.index')->with('status', 'Announcement deleted.');
    }

    // A global announcement has no subject targets — the switch wins over whatever the (hidden)
    // multi-select still held.
    private function targetSubjectIds(array $data): array
    {
        return $data['is_global'] ? [] : array_values(array_unique($data['subject_ids'] ?? []));
    }

    private function subjects()
    {
        return Subject::visibleTo(Auth::user())->with('section:id,section_name')->orderBy('subject_code')->get();
    }

    private function storeImages(array $files): array
    {
        return array_map(function ($file): array {
            return [
                'path' => $file->store('announcements/'.Auth::id(), Announcement::PRIVATE_DISK),
                'name' => $file->getClientOriginalName(),
                'mime' => $file->getClientMimeType(),
            ];
        }, $files);
    }

    private function deleteImages(array $images): void
    {
        $paths = array_filter(array_column($images, 'path'));
        Storage::disk(Announcement::PRIVATE_DISK)->delete($paths);
        Storage::disk('local')->delete($paths);
    }

    private function notifyStudents(Announcement $announcement): void
    {
        $announcement->loadMissing('subjects:id,sections_id');
        $subjects = $announcement->subjects;
        $query = Enrolled::visibleTo(Auth::user())->where('educator_id', $announcement->educator_id)->where('is_active', true);
        if (! $announcement->is_global) {
            $query->whereIn('subject_id', $subjects->pluck('id'));
        }

        // pluck()->unique() so a student enrolled in two targeted subjects gets one notification.
        $this->notifications->emitToMany(Auth::user(), 'announcement_created', $query->pluck('student_id')->unique()->values()->all(), [
            'subject_id' => $subjects->first()?->id,
            'section_id' => $subjects->first()?->sections_id,
            'title' => 'New announcement: '.$announcement->title,
            'message' => $announcement->description ?: $announcement->title,
            'link_path' => route('student.announcements.index', [], false),
            'metadata' => ['announcement_id' => $announcement->id],
        ]);
    }
}
