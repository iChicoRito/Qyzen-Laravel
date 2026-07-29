<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Models\Enrolled;
use App\Models\Score;
use App\Models\Section;
use App\Models\StudentPresence;
use App\Models\Subject;
use App\Models\User;
use App\Models\UserImport;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

// F1: admin dashboard — institution-wide quiz analytics + assessment calendar.
class DashboardController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', User::class);

        $today = Carbon::today();

        // --- Metric cards ---
        $educatorCount = User::where('user_type', 'educator')->count();
        $studentCount = User::where('user_type', 'student')->count();
        $sectionCount = Section::count();
        $subjectCount = Subject::count();
        $systemAvg = (float) Score::where('total_questions', '>', 0)
            ->selectRaw('AVG(score * 100.0 / total_questions) AS a')->value('a');

        // --- Per-educator aggregates (bar chart + table) ---
        $sectionsByEd = Section::selectRaw('educator_id, COUNT(*) c')->groupBy('educator_id')->pluck('c', 'educator_id');
        $studentsByEd = Enrolled::where('is_active', true)
            ->selectRaw('educator_id, COUNT(DISTINCT student_id) c')->groupBy('educator_id')->pluck('c', 'educator_id');
        $avgByEd = Score::where('total_questions', '>', 0)
            ->selectRaw('educator_id, AVG(score * 100.0 / total_questions) a')->groupBy('educator_id')->pluck('a', 'educator_id');

        $educators = User::where('user_type', 'educator')->orderBy('given_name')->get();
        $educatorTable = $educators->map(fn ($e) => [
            'name' => $e->name,
            'sections' => (int) ($sectionsByEd[$e->id] ?? 0),
            'students' => (int) ($studentsByEd[$e->id] ?? 0),
            'avg' => isset($avgByEd[$e->id]) ? round((float) $avgByEd[$e->id], 1) : null,
        ]);
        // ponytail: single-series bar (avg % per educator) — a stack segmented by section is sparse
        //   (each section belongs to one educator), so it reads worse. Switch to stacked only if
        //   a cross-educator segment (e.g. pass/fail) is wanted.
        $barCategories = $educatorTable->pluck('name')->values();
        $barData = $educatorTable->pluck('avg')->map(fn ($v) => $v ?? 0)->values();

        // --- Line: institution-wide quiz activity per ISO week ---
        $trend = Score::weeklyTrend(Score::query());
        $trendLabels = $trend['labels'];
        $trendData = $trend['data'];

        // --- Recent activity ---
        $recentUsers = User::latest()->take(6)->get(['id', 'given_name', 'surname', 'user_type', 'created_at']);
        $flaggedCount = Score::where('warning_attempts', '>', 0)->count();
        $flaggedRecent = Score::where('warning_attempts', '>', 0)
            ->with('student:id,given_name,surname', 'assessment:id,assessment_code')
            ->latest('submitted_at')->take(5)->get();

        // --- Right panel ---
        $calendarEvents = Assessment::notArchived()->with('subject:id,subject_name')
            ->whereNotNull('start_date')->whereNotNull('end_date')
            ->get()->map->calendarEvent()->values();
        $activeToday = StudentPresence::whereDate('last_seen_at', $today)->count();
        $pendingApprovals = User::where('is_active', false)->count();
        $latestImport = UserImport::latest()->first();

        return view('admin.dashboard', compact(
            'educatorCount', 'studentCount', 'sectionCount', 'subjectCount', 'systemAvg',
            'barCategories', 'barData', 'trendLabels', 'trendData',
            'educatorTable', 'recentUsers', 'flaggedCount', 'flaggedRecent',
            'calendarEvents', 'activeToday', 'pendingApprovals', 'latestImport',
        ));
    }
}
