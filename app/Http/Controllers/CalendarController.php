<?php

namespace App\Http\Controllers;

use App\Models\Assessment;
use Illuminate\View\View;

// Shared calendar page for all three portals. Assessment::visibleTo() already scopes by role
// (admin all / educator own / student enrolled), so one action serves every role — it just
// renders the role-matched view so the correct sidebar layout wraps it.
class CalendarController extends Controller
{
    public function index(): View
    {
        $user = auth()->user();

        $events = Assessment::visibleTo($user)->notArchived()
            ->with('subject:id,subject_name', 'section:id,section_name')
            ->whereNotNull('start_date')->whereNotNull('end_date')
            ->get()->map->calendarEvent()->values();

        return view($this->role($user).'.calendar', compact('events'));
    }

    // Assessment detail fragment for the calendar event-click modal (loaded via _modal-loader,
    // ?modal=1). Scoped to the same visibility as the calendar itself — any event shown is viewable.
    public function show(Assessment $assessment): View
    {
        $user = auth()->user();
        abort_unless(Assessment::visibleTo($user)->whereKey($assessment->getKey())->exists(), 404);

        $assessment->load('subject:id,subject_name', 'section:id,section_name', 'academicTerm:id,term_name');
        $role = $this->role($user);

        return view('calendar.event', [
            'assessment' => $assessment,
            'layout' => $role.'.layout',
            'calendarRoute' => route($role.'.calendar'),
        ]);
    }

    private function role($user): string
    {
        return $user->hasRole('admin') ? 'admin' : ($user->hasRole('educator') ? 'educator' : 'student');
    }
}
