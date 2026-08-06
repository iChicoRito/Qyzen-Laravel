<?php

namespace App\Http\Controllers\Educator;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateQuestionPoolRequest;
use App\Models\Assessment;
use App\Models\Enrolled;
use App\Models\Quiz;
use App\Services\NotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

// Task 51: an assessment's pool config — which bank questions are eligible to be drawn, and how
// many (N) to draw per attempt. A sub-action on Assessment, gated by AssessmentPolicy::update.
class AssessmentQuestionPoolController extends Controller
{
    public function __construct(private NotificationService $notifications) {}

    public function edit(Assessment $assessment): View
    {
        $this->authorize('update', $assessment);

        // Task 13: batch is a server-side filter applied to the WHOLE bank before pagination,
        // so a selected batch finds matching questions on any page (not just the current one).
        $batch = request()->query('batch');
        $filtered = Quiz::visibleTo(Auth::user())
            ->when($batch, fn ($q) => $q->where('batch_label', $batch));
        // Every id matching the current filter across ALL pages — lets "Select All" pick the whole
        // matching set, not just the ~10 rendered on this page.
        $allFilteredIds = (clone $filtered)->orderBy('id')->pluck('id')->all();
        $bankQuestions = $filtered
            ->with(['subjects:id,subject_code,subject_name,sections_id', 'subjects.section:id,section_name', 'eligibleAssessments:id,assessment_code'])
            ->orderBy('id')->get();
        // Unfiltered total drives the "show the filter controls" gate + the summary denominator,
        // so filtering down to <=8 questions never hides the batch dropdown (which would trap the user).
        $bankTotal = Quiz::visibleTo(Auth::user())->count();
        $eligibleIds = $assessment->eligibleQuizzes()->pluck('tbl_quizzes.id')->all();
        $pendingIds = Quiz::visibleTo(Auth::user())
            ->whereKey((array) request()->query('selected', []))
            ->pluck('id')->all();
        $eligibleIds = array_values(array_unique(array_merge($eligibleIds, $pendingIds)));
        $batches = Quiz::visibleTo(Auth::user())->whereNotNull('batch_label')
            ->distinct()->orderBy('batch_label')->pluck('batch_label');

        return view('educator.assessments.pool', compact('assessment', 'bankQuestions', 'bankTotal', 'allFilteredIds', 'eligibleIds', 'batches'));
    }

    public function update(UpdateQuestionPoolRequest $request, Assessment $assessment): RedirectResponse
    {
        $this->authorize('update', $assessment);

        $data = $request->validated();
        $assessment->eligibleQuizzes()->sync($data['eligible_quiz_ids'] ?? []);
        $assessment->update(['pool_size' => $data['pool_size']]);

        $this->notifyEnrolled($assessment);

        return redirect()->route('educator.assessments.pool.edit', $assessment)
            ->with('status', 'Question pool updated.');
    }

    private function notifyEnrolled(Assessment $assessment): void
    {
        $studentIds = Enrolled::where('educator_id', Auth::id())
            ->where('subject_id', $assessment->subject_id)
            ->where('is_active', true)
            ->pluck('student_id')->all();

        $this->notifications->emitToMany(Auth::user(), 'quiz_updated', $studentIds, [
            'subject_id' => $assessment->subject_id, 'assessment_id' => $assessment->id,
            'section_id' => $assessment->section_id, 'title' => 'Assessment questions were updated',
            'link_path' => route('student.assessments.index'),
        ]);
    }
}
