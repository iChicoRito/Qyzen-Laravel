<?php

namespace App\Http\Controllers\Educator;

use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Models\Enrolled;
use App\Models\Score;
use App\Models\Section;
use App\Models\Subject;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

// G1: educator dashboard — ownership-scoped (educator_id) quiz analytics + assessment calendar.
class DashboardController extends Controller
{
    public function index(): View
    {
        $user = auth()->user();
        $today = Carbon::today();

        // --- Metric cards ---
        $sectionCount = Section::visibleTo($user)->count();
        $subjectCount = Subject::visibleTo($user)->count();
        $studentCount = Enrolled::visibleTo($user)->where('is_active', true)
            ->distinct('student_id')->count('student_id');
        $pendingCount = Assessment::visibleTo($user)->notArchived()->where('is_active', true)
            ->whereDate('end_date', '>=', $today)->count();

        // --- Area: quiz-activity trend (submitted scores per ISO week) ---
        $trend = Score::weeklyTrend(Score::visibleTo($user));
        $trendLabels = $trend['labels'];
        $trendData = $trend['data'];

        // --- Table: sections → subjects / enrollment / avg score ---
        // Qualify columns explicitly: the tbl_subjects join makes educator_id/is_active ambiguous,
        // so the unqualified visibleTo() predicate can't be reused here. Same ownership filter.
        $enrollBySection = Enrolled::query()
            ->where('tbl_enrolled.educator_id', $user->id)
            ->where('tbl_enrolled.is_active', true)
            ->join('tbl_subjects', 'tbl_subjects.id', '=', 'tbl_enrolled.subject_id')
            ->selectRaw('tbl_subjects.sections_id AS sid, COUNT(DISTINCT tbl_enrolled.student_id) AS c')
            ->groupBy('tbl_subjects.sections_id')->pluck('c', 'sid');
        $avgBySection = Score::visibleTo($user)->where('total_questions', '>', 0)
            ->selectRaw('section_id, AVG(score * 100.0 / total_questions) AS avg_pct')
            ->groupBy('section_id')->pluck('avg_pct', 'section_id');
        $sectionTable = Section::visibleTo($user)->with('subjects:id,sections_id,subject_name')
            ->orderBy('section_name')->get()->map(fn ($s) => [
                'name' => $s->section_name,
                'subjects' => $s->subjects->pluck('subject_name')->join(', ') ?: '—',
                'enrolled' => (int) ($enrollBySection[$s->id] ?? 0),
                'avg' => isset($avgBySection[$s->id]) ? round((float) $avgBySection[$s->id], 1) : null,
            ]);

        $calendarAssessments = Assessment::visibleTo($user)->notArchived()->with('subject:id,subject_name')
            ->whereNotNull('start_date')->whereNotNull('end_date')->get();
        $calendarEvents = $calendarAssessments->map->calendarEvent()->values();

        $nextAssessment = Assessment::visibleTo($user)->notArchived()->with('subject:id,subject_name')
            ->whereDate('start_date', '>=', $today)->orderBy('start_date')->first();

        $heatmapMonth = now()->startOfMonth();
        $heatmapEnd = $heatmapMonth->copy()->endOfMonth();
        $assessmentCounts = Assessment::visibleTo($user)->notArchived()
            ->whereBetween('created_at', [$heatmapMonth->copy()->startOfDay(), $heatmapEnd->copy()->endOfDay()])
            ->get(['created_at'])
            ->groupBy(fn (Assessment $assessment) => $assessment->created_at->format('Y-m-d'))
            ->map->count();

        $heatmapWeeks = collect();
        $cursor = $heatmapMonth->copy()->startOfWeek();
        $lastWeek = $heatmapEnd->copy()->endOfWeek();
        while ($cursor->lessThanOrEqualTo($lastWeek)) {
            $heatmapWeeks->push($cursor->copy());
            $cursor->addWeek();
        }

        $weekdayRows = collect([
            0 => 'Sun',
            1 => 'Mon',
            2 => 'Tue',
            3 => 'Wed',
            4 => 'Thu',
            5 => 'Fri',
            6 => 'Sat',
        ]);

        $assessmentHeatmap = $assessmentCounts->isEmpty() ? [] : $weekdayRows->map(function (string $label, int $weekday) use ($heatmapWeeks, $heatmapMonth, $heatmapEnd, $assessmentCounts) {
            return [
                'name' => $label,
                'data' => $heatmapWeeks->map(function (Carbon $weekStart) use ($weekday, $heatmapMonth, $heatmapEnd, $assessmentCounts) {
                    $date = $weekStart->copy()->addDays($weekday);
                    if ($date->lt($heatmapMonth) || $date->gt($heatmapEnd)) {
                        return 0;
                    }

                    return (int) ($assessmentCounts[$date->format('Y-m-d')] ?? 0);
                })->all(),
            ];
        })->values()->all();

        $heatmapLabels = $heatmapWeeks->map(fn (Carbon $weekStart) => $weekStart->format('M j'))->all();

        return view('educator.dashboard', compact(
            'sectionCount', 'subjectCount', 'studentCount', 'pendingCount',
            'trendLabels', 'trendData',
            'sectionTable', 'calendarEvents', 'nextAssessment',
            'assessmentHeatmap', 'heatmapLabels',
        ));
    }
}
