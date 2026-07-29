<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Models\Enrolled;
use App\Models\Score;
use App\Models\Subject;
use App\Services\AssessmentAvailabilityService;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

// H1: student dashboard — own-data (student_id) quiz analytics + assessment calendar.
class DashboardController extends Controller
{
    public function index(AssessmentAvailabilityService $availability): View
    {
        $user = auth()->user();
        $today = Carbon::today();

        // --- Metric cards ---
        $overallAvg = (float) Score::visibleTo($user)->where('total_questions', '>', 0)
            ->selectRaw('AVG(score * 100.0 / total_questions) AS a')->value('a');
        $subjectCount = Enrolled::visibleTo($user)->where('is_active', true)->count();
        $submittedQuery = Score::visibleTo($user)->whereIn('status', ['submitted', 'passed', 'failed']);
        $submittedCount = (clone $submittedQuery)->count();
        $passCount = (clone $submittedQuery)->where('is_passed', true)->count();
        $passRate = $submittedCount ? round($passCount / $submittedCount * 100) : 0;

        // --- Visible assessments (enrolled subjects) + availability badges ---
        $assessments = Assessment::visibleTo($user)->notArchived()->with('subject:id,subject_name')->get();
        $badges = $assessments->mapWithKeys(fn ($a) => [
            $a->id => $availability->summarize($a, $user->id)['badge'],
        ]);
        $pendingCount = $badges->filter(
            fn ($b) => in_array($b, ['Available', 'Upcoming', 'Reopened'], true)
        )->count();

        // --- Donut: average score % by subject ---
        $subjects = Subject::visibleTo($user)
            ->with('section:id,section_name', 'educator:id,given_name,surname')
            ->orderBy('subject_name')->get();
        $avgBySubject = Score::visibleTo($user)->where('total_questions', '>', 0)
            ->selectRaw('subject_id, AVG(score * 100.0 / total_questions) AS a')
            ->groupBy('subject_id')->pluck('a', 'subject_id');
        $donutLabels = [];
        $donutData = [];
        foreach ($subjects as $s) {
            if (isset($avgBySubject[$s->id])) {
                $donutLabels[] = $s->subject_name;
                $donutData[] = round((float) $avgBySubject[$s->id], 1);
            }
        }

        // --- Timeline: upcoming assessments (badge-tagged) ---
        $upcoming = $assessments->filter(fn ($a) => $a->end_date && $a->end_date->gte($today))
            ->sortBy('end_date')->take(6)->values();

        // --- Completion rate per subject ---
        $totalBySubject = $assessments->groupBy('subject_id')->map->count();
        $doneBySubject = Score::visibleTo($user)->whereIn('status', ['submitted', 'passed', 'failed'])
            ->selectRaw('subject_id, COUNT(DISTINCT assessment_id) AS c')
            ->groupBy('subject_id')->pluck('c', 'subject_id');
        $completion = $subjects->map(fn ($s) => [
            'name' => $s->subject_name,
            'pct' => ($t = (int) ($totalBySubject[$s->id] ?? 0)) > 0
                ? (int) round(min($t, (int) ($doneBySubject[$s->id] ?? 0)) / $t * 100)
                : 0,
        ]);

        // --- Right panel ---
        $calendarEvents = $assessments->filter(fn ($a) => $a->start_date && $a->end_date)
            ->map->calendarEvent()->values();
        $nextDeadline = $upcoming->first();
        $sectionNames = $subjects->pluck('section.section_name')->filter()->unique()->values();

        return view('student.dashboard', compact(
            'overallAvg', 'subjectCount', 'passRate', 'pendingCount',
            'donutLabels', 'donutData', 'upcoming', 'badges', 'subjects', 'completion',
            'calendarEvents', 'nextDeadline', 'sectionNames',
        ));
    }
}
