<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class AnnouncementController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Announcement::class);
        $announcements = Announcement::visibleTo(Auth::user())
            ->with(['educator:id,given_name,surname,profile_picture', 'subjects:id,subject_code,subject_name'])
            ->latest()->paginate(10);
        $newAnnouncementIds = Notification::forRecipient(Auth::id())
            ->where('event_type', 'announcement_created')
            ->where('is_read', false)
            ->get(['metadata'])
            ->pluck('metadata')
            ->map(fn (?array $metadata): ?int => isset($metadata['announcement_id']) ? (int) $metadata['announcement_id'] : null)
            ->filter()
            ->all();

        return view('student.announcements.index', compact('announcements', 'newAnnouncementIds'));
    }

    public function image(Announcement $announcement, int $image)
    {
        $this->authorize('view', $announcement);
        $item = $announcement->images[$image] ?? null;
        abort_unless(is_array($item) && isset($item['path']), 404);
        $disk = Announcement::readableImageDisk($item['path']);
        abort_unless($disk, 404);

        return response()->file(Storage::disk($disk)->path($item['path']), [
            'Content-Type' => $item['mime'] ?? 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="'.addslashes($item['name'] ?? 'announcement-image').'"',
        ]);
    }
}
