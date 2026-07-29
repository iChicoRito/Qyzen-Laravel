<?php

namespace App\Http\Controllers\Educator;

use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Models\Enrolled;
use App\Models\Score;
use App\Models\StudentPresence;
use App\Support\TableQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

// G11: realtime monitoring, request/response (live updates deferred to Stage I). View-only:
// per assessment, count enrolled/online/answering/finished. Manual refresh = page reload.
class MonitoringController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Assessment::class);

        $user = Auth::user();
        $onlineThreshold = now()->subSeconds(60); // 60s-stale = offline (matches source heuristic)

        $query = Assessment::visibleTo($user)
            ->notArchived()
            ->where('tbl_assessments.is_active', true)
            ->select('tbl_assessments.*')
            ->leftJoin('tbl_subjects as sort_subjects', 'sort_subjects.id', '=', 'tbl_assessments.subject_id')
            ->leftJoin('tbl_sections as sort_sections', 'sort_sections.id', '=', 'tbl_assessments.section_id')
            ->with(['subject:id,subject_code', 'section:id,section_name', 'academicTerm:id,term_name'])
            ->selectSub(
                Enrolled::visibleTo($user)
                    ->selectRaw('count(*)')
                    ->whereColumn('tbl_enrolled.educator_id', 'tbl_assessments.educator_id')
                    ->whereColumn('tbl_enrolled.subject_id', 'tbl_assessments.subject_id')
                    ->where('tbl_enrolled.is_active', true),
                'enrolled_count'
            )
            ->selectSub(
                StudentPresence::query()
                    ->selectRaw('count(*)')
                    ->join('tbl_enrolled as sort_enrolled', 'sort_enrolled.student_id', '=', 'tbl_student_presence.student_id')
                    ->whereColumn('sort_enrolled.educator_id', 'tbl_assessments.educator_id')
                    ->whereColumn('sort_enrolled.subject_id', 'tbl_assessments.subject_id')
                    ->where('sort_enrolled.is_active', true)
                    ->where('tbl_student_presence.last_seen_at', '>=', $onlineThreshold),
                'online_count'
            )
            ->selectSub(
                Score::visibleTo($user)
                    ->selectRaw('count(*)')
                    ->join('tbl_enrolled as sort_enrolled', 'sort_enrolled.student_id', '=', 'tbl_scores.student_id')
                    ->whereColumn('sort_enrolled.educator_id', 'tbl_assessments.educator_id')
                    ->whereColumn('sort_enrolled.subject_id', 'tbl_assessments.subject_id')
                    ->where('sort_enrolled.is_active', true)
                    ->whereColumn('tbl_scores.assessment_id', 'tbl_assessments.id')
                    ->where('tbl_scores.status', 'in_progress'),
                'answering_count'
            )
            ->selectSub(
                Score::visibleTo($user)
                    ->selectRaw('count(*)')
                    ->join('tbl_enrolled as sort_enrolled', 'sort_enrolled.student_id', '=', 'tbl_scores.student_id')
                    ->whereColumn('sort_enrolled.educator_id', 'tbl_assessments.educator_id')
                    ->whereColumn('sort_enrolled.subject_id', 'tbl_assessments.subject_id')
                    ->where('sort_enrolled.is_active', true)
                    ->whereColumn('tbl_scores.assessment_id', 'tbl_assessments.id')
                    ->whereIn('tbl_scores.status', ['submitted', 'passed', 'failed']),
                'finished_count'
            );
        TableQuery::search($query, $request->query('search'), [
            'assessment_code',
            fn (Builder $q, string $term) => $q->orWhereHas('subject', fn ($s) => $s->where('subject_code', 'like', "%{$term}%")),
            fn (Builder $q, string $term) => $q->orWhereHas('section', fn ($s) => $s->where('section_name', 'like', "%{$term}%")),
        ]);
        TableQuery::sort($query, $request, [
            'assessment' => 'assessment_code',
            'subject' => function (Builder $q, string $direction): void {
                $q->orderBy('sort_subjects.subject_code', $direction)
                    ->orderBy('sort_subjects.subject_name', $direction)
                    ->orderBy('tbl_assessments.id', 'desc');
            },
            'section' => 'sort_sections.section_name',
            'enrolled' => 'enrolled_count',
            'online' => 'online_count',
            'answering' => 'answering_count',
            'finished' => 'finished_count',
            'id' => 'id',
        ], 'id', 'desc');

        $assessments = $query->paginate(TableQuery::perPage($request))->withQueryString();
        $assessments->setCollection($assessments->getCollection()->map(function (Assessment $a) {
            return [
                'assessment' => $a,
                'enrolled' => (int) $a->enrolled_count,
                'online' => (int) $a->online_count,
                'answering' => (int) $a->answering_count,
                'finished' => (int) $a->finished_count,
            ];
        }));

        return view('educator.monitoring.index', compact('assessments'));
    }
}
