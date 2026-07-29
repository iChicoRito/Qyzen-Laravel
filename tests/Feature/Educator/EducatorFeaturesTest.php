<?php

namespace Tests\Feature\Educator;

use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Assessment;
use App\Models\Conversation;
use App\Models\Enrolled;
use App\Models\Notification;
use App\Models\Permission;
use App\Models\Quiz;
use App\Models\Role;
use App\Models\Score;
use App\Models\Section;
use App\Models\StudentAssessmentAccess;
use App\Models\StudentAssessmentExemption;
use App\Models\Subject;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Support\Facades\Hash;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as SpreadsheetWriter;
use Tests\TestCase;

// Stage G: educator feature tests. The critical concern is the OWNERSHIP GATE — educator A must
// never see or mutate educator B's data (no RLS; the visibleTo scope + policies are the only guard).
class EducatorFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private User $eduA;

    private User $eduB;

    private User $student;

    private AcademicTerm $term;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'educator', 'student'] as $name) {
            Role::create(['name' => $name, 'description' => $name, 'is_active' => true]);
        }
        // Section/Subject policies are permission-gated for educators (Stage D) — grant them.
        $this->seedEducatorPermissions(['sections:view', 'sections:create', 'sections:update', 'sections:delete',
            'subjects:view', 'subjects:create', 'subjects:update', 'subjects:delete']);

        $this->eduA = $this->makeUser('educator', 'educator');
        $this->eduB = $this->makeUser('educator', 'educator');
        $this->student = $this->makeUser('student', 'student');

        $year = AcademicYear::create(['year' => '2025 - 2026']);
        $this->term = AcademicTerm::create(['term_name' => 'Prelim', 'semester' => '1st Semester', 'academic_year_id' => $year->id]);
    }

    // ---- gate ----

    public function test_non_educator_is_bounced_from_educator_routes(): void
    {
        foreach (['educator.dashboard', 'educator.sections.index', 'educator.assessments.index', 'educator.scores.index'] as $route) {
            $this->actingAs($this->student)->get(route($route))->assertStatus(302);
        }
    }

    public function test_educator_can_soft_delete_own_score_without_password_confirmation(): void
    {
        $this->eduA->forceFill(['password' => Hash::make('password')])->save();
        $subject = $this->subject($this->eduA);
        $assessment = Assessment::create($this->assessmentModelData($subject));
        $score = Score::create([
            'student_id' => $this->student->id, 'educator_id' => $this->eduA->id,
            'assessment_id' => $assessment->id, 'subject_id' => $subject->id,
            'section_id' => $subject->sections_id, 'score' => 4, 'total_questions' => 5,
            'status' => 'failed', 'is_passed' => false, 'student_answer' => [],
        ]);

        $this->actingAs($this->eduA)
            ->deleteJson(route('educator.scores.destroy', $score))
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertNotNull($score->fresh()->deleted_at);
        $this->assertNull(Score::find($score->id));
        $this->assertNotNull(Score::withTrashed()->find($score->id));
    }

    public function test_score_delete_rejects_cross_owner(): void
    {
        $subject = $this->subject($this->eduA);
        $assessment = Assessment::create($this->assessmentModelData($subject));
        $score = Score::create([
            'student_id' => $this->student->id, 'educator_id' => $this->eduA->id,
            'assessment_id' => $assessment->id, 'subject_id' => $subject->id,
            'section_id' => $subject->sections_id, 'score' => 4, 'total_questions' => 5,
            'status' => 'failed', 'is_passed' => false, 'student_answer' => [],
        ]);

        $this->actingAs($this->eduA)
            ->deleteJson(route('educator.scores.destroy', $score))
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertNotNull($score->fresh()->deleted_at);

        $this->actingAs($this->eduB)
            ->deleteJson(route('educator.scores.destroy', $score))
            ->assertForbidden();
    }

    public function test_deleted_scores_are_owner_scoped_and_restorable(): void
    {
        $subjectA = $this->subject($this->eduA);
        $assessmentA = Assessment::create($this->assessmentModelData($subjectA));
        $scoreA = Score::create([
            'student_id' => $this->student->id, 'educator_id' => $this->eduA->id,
            'assessment_id' => $assessmentA->id, 'subject_id' => $subjectA->id,
            'section_id' => $subjectA->sections_id, 'score' => 4, 'total_questions' => 5,
            'status' => 'failed', 'is_passed' => false, 'student_answer' => [],
        ]);

        $subjectB = $this->subject($this->eduB);
        $assessmentB = Assessment::create(array_merge($this->assessmentModelData($subjectB), ['assessment_code' => 'Q2']));
        $scoreB = Score::create([
            'student_id' => $this->student->id, 'educator_id' => $this->eduB->id,
            'assessment_id' => $assessmentB->id, 'subject_id' => $subjectB->id,
            'section_id' => $subjectB->sections_id, 'score' => 3, 'total_questions' => 5,
            'status' => 'failed', 'is_passed' => false, 'student_answer' => [],
        ]);
        $scoreA->delete();
        $scoreB->delete();

        $this->actingAs($this->eduA)
            ->get(route('educator.scores.deleted'))
            ->assertOk()
            ->assertSee('Q1')
            ->assertDontSee('Q2');

        $this->actingAs($this->eduA)
            ->patchJson(route('educator.scores.restore', $scoreA))
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertNull($scoreA->fresh()->deleted_at);
        $this->assertNotNull($scoreB->fresh()->deleted_at);
        $this->actingAs($this->eduA)->get(route('educator.scores.index'))->assertSee('Q1');
    }

    public function test_scores_sidebar_marks_only_the_current_child_active(): void
    {
        foreach ([
            'educator.scores.index' => 'Scores',
            'educator.scores.deleted' => 'Archived Scores',
        ] as $route => $activeChild) {
            $html = $this->actingAs($this->eduA)->get(route($route))->assertOk()->getContent();

            $this->assertSame(1, substr_count($html, 'class="kt-menu-item active"'));
            $this->assertSame(1, substr_count($html, 'class="kt-menu-item show"'));
            $this->assertStringContainsString('>'.$activeChild.'</span>', $html);
        }
    }

    // Task 29: the binding constraint — archiving must never touch a recorded score.
    public function test_archiving_an_assessment_preserves_every_student_score(): void
    {
        $subject = $this->subject($this->eduA);
        $assessment = Assessment::create($this->assessmentModelData($subject) + ['is_active' => true]);
        $score = Score::create([
            'student_id' => $this->student->id, 'educator_id' => $this->eduA->id,
            'assessment_id' => $assessment->id, 'subject_id' => $subject->id,
            'section_id' => $subject->sections_id, 'score' => 4, 'total_questions' => 5,
            'status' => 'passed', 'is_passed' => true, 'student_answer' => [],
        ]);
        Enrolled::create([
            'student_id' => $this->student->id, 'educator_id' => $this->eduA->id,
            'subject_id' => $subject->id, 'is_active' => true,
        ]);

        $this->actingAs($this->eduA)
            ->patch(route('educator.assessments.archive'), ['assessment_ids' => [$assessment->id]])
            ->assertRedirect(route('educator.assessments.index'));

        $this->assertTrue($assessment->fresh()->is_archived);
        // The score row, its assessment link, and its readability all survive.
        $this->assertSame(1, Score::where('assessment_id', $assessment->id)->count());
        $this->assertNull($score->fresh()->deleted_at);
        $this->assertNotNull(Score::find($score->id)->assessment);
        $this->actingAs($this->eduA)->get(route('educator.scores.index'))->assertOk()->assertSee('Q1');
        $this->actingAs($this->student)->get(route('student.scores.index'))->assertOk()->assertSee('Q1');

        // ...but it is gone from the active lists, for educator and student alike.
        $this->actingAs($this->eduA)->get(route('educator.assessments.index'))->assertOk()->assertDontSee('Q1');
        $this->actingAs($this->student)->get(route('student.assessments.index'))->assertOk()->assertDontSee('Q1');

        $this->actingAs($this->eduA)->get(route('educator.assessments.archived'))
            ->assertOk()->assertSee('Q1')->assertSee('1 score(s) retained');

        $this->actingAs($this->eduA)
            ->patch(route('educator.assessments.archived.restore'), ['assessment_ids' => [$assessment->id]])
            ->assertRedirect(route('educator.assessments.archived'));
        $this->assertFalse($assessment->fresh()->is_archived);
        $this->actingAs($this->eduA)->get(route('educator.assessments.index'))->assertOk()->assertSee('Q1');
    }

    public function test_assessment_archive_is_owner_scoped(): void
    {
        $foreign = Assessment::create(array_merge(
            $this->assessmentModelData($this->subject($this->eduB)),
            ['educator_id' => $this->eduB->id, 'assessment_code' => 'ZZTOP']
        ));

        $this->actingAs($this->eduA)
            ->patch(route('educator.assessments.archive'), ['assessment_ids' => [$foreign->id]])
            ->assertRedirect();

        $this->assertFalse($foreign->fresh()->is_archived);
    }

    public function test_assessments_sidebar_marks_only_the_current_child_active(): void
    {
        foreach ([
            'educator.assessments.index' => 'Assessments',
            'educator.assessments.archived' => 'Archived Assessments',
        ] as $route => $activeChild) {
            $html = $this->actingAs($this->eduA)->get(route($route))->assertOk()->getContent();

            $this->assertSame(1, substr_count($html, 'class="kt-menu-item active"'));
            $this->assertSame(1, substr_count($html, 'class="kt-menu-item show"'));
            $this->assertStringContainsString('>'.$activeChild.'</span>', $html);
        }
    }

    public function test_educator_can_archive_and_restore_question_batches(): void
    {
        $subject = $this->subject($this->eduA);
        $batch = 'Upload: batch-a.xlsx · Jul 23, 2026 10:00 AM';
        $first = Quiz::create([
            'subject_id' => $subject->id, 'educator_id' => $this->eduA->id,
            'question' => 'Batch A - 1', 'quiz_type' => 'identification',
            'correct_answer' => 'one', 'batch_label' => $batch,
        ]);
        $second = Quiz::create([
            'subject_id' => $subject->id, 'educator_id' => $this->eduA->id,
            'question' => 'Batch A - 2', 'quiz_type' => 'identification',
            'correct_answer' => 'two', 'batch_label' => $batch,
        ]);

        $foreignSubject = $this->subject($this->eduB);
        $foreign = Quiz::create([
            'subject_id' => $foreignSubject->id, 'educator_id' => $this->eduB->id,
            'question' => 'Foreign batch row', 'quiz_type' => 'identification',
            'correct_answer' => 'three', 'batch_label' => $batch,
        ]);

        $this->actingAs($this->eduA)
            ->delete('/educator/quizzes/archive', ['quiz_ids' => [$first->id]])
            ->assertRedirect(route('educator.quizzes.index'));

        $this->assertNull(Quiz::find($first->id));
        $this->assertNull(Quiz::find($second->id));
        $this->assertNotNull(Quiz::find($foreign->id));

        $this->actingAs($this->eduA)
            ->get('/educator/quizzes/archived')
            ->assertOk()
            ->assertSee('Archived Questions')
            ->assertSee('Restore selected', false)
            ->assertSee('data-filter="section"', false)
            ->assertSee('data-filter="subject"', false)
            ->assertSee('data-filter="batch"', false)
            ->assertSee($batch)
            ->assertSee('2');

        $this->actingAs($this->eduA)
            ->patch('/educator/quizzes/archived/restore', ['batch_labels' => [$batch]])
            ->assertRedirect('/educator/quizzes/archived');

        $this->assertNotNull(Quiz::find($first->id));
        $this->assertNotNull(Quiz::find($second->id));
        $this->assertNotNull(Quiz::find($foreign->id));
    }

    public function test_deleting_newest_attempt_keeps_older_active_attempt_and_counts(): void
    {
        $subject = $this->subject($this->eduA);
        $assessment = Assessment::create($this->assessmentModelData($subject));
        $older = Score::create([
            'student_id' => $this->student->id, 'educator_id' => $this->eduA->id,
            'assessment_id' => $assessment->id, 'subject_id' => $subject->id,
            'section_id' => $subject->sections_id, 'score' => 2, 'total_questions' => 5,
            'status' => 'failed', 'is_passed' => false, 'student_answer' => [],
            'submitted_at' => now()->subHour(),
        ]);
        $newer = Score::create([
            'student_id' => $this->student->id, 'educator_id' => $this->eduA->id,
            'assessment_id' => $assessment->id, 'subject_id' => $subject->id,
            'section_id' => $subject->sections_id, 'score' => 5, 'total_questions' => 5,
            'status' => 'passed', 'is_passed' => true, 'student_answer' => [],
            'submitted_at' => now(),
        ]);
        $newer->delete();

        $this->actingAs($this->eduA)
            ->get(route('educator.scores.matrix', [
                'subject' => $subject->id, 'section' => $subject->sections_id, 'term' => $this->term->id,
            ]))
            ->assertSee('2/5')
            ->assertSee('1 attempt')
            ->assertDontSee('5/5');

        $this->assertNotNull($older->fresh());
    }

    // Task 31: a stale link to the educator's OWN record in a deactivated term must not render an
    // error page — it redirects with a notice. Another educator's record still 403s (covered by
    // the cross-educator tests above), and a record that does not exist still 404s.
    public function test_inactive_term_redirects_educator_instead_of_erroring(): void
    {
        $subject = $this->subject($this->eduA);
        $assessment = Assessment::create($this->assessmentModelData($subject));
        $score = Score::create([
            'student_id' => $this->student->id, 'educator_id' => $this->eduA->id,
            'assessment_id' => $assessment->id, 'subject_id' => $subject->id,
            'section_id' => $subject->sections_id, 'score' => 4, 'total_questions' => 5,
            'status' => 'failed', 'is_passed' => false, 'student_answer' => [],
        ]);

        $this->term->update(['is_active' => false]);

        $this->actingAs($this->eduA)
            ->get(route('educator.scores.show', $score))
            ->assertRedirect(route('educator.dashboard'))
            ->assertSessionHas('status');

        $this->actingAs($this->eduA)
            ->get(route('educator.assessments.edit', $assessment))
            ->assertRedirect(route('educator.dashboard'));

        // Ownership still wins: educator B gets the error, not the soft notice.
        $this->actingAs($this->eduB)
            ->get(route('educator.scores.show', $score))
            ->assertForbidden();
    }

    // Task 31: the term guard asks only "is this row's term active?". A soft-deleted score in a
    // live term is archived, not term-hidden, and must stay restorable.
    public function test_archived_score_in_an_active_term_is_not_treated_as_term_hidden(): void
    {
        $subject = $this->subject($this->eduA);
        $assessment = Assessment::create($this->assessmentModelData($subject));
        $score = Score::create([
            'student_id' => $this->student->id, 'educator_id' => $this->eduA->id,
            'assessment_id' => $assessment->id, 'subject_id' => $subject->id,
            'section_id' => $subject->sections_id, 'score' => 4, 'total_questions' => 5,
            'status' => 'failed', 'is_passed' => false, 'student_answer' => [],
        ]);
        $score->delete();

        $this->assertTrue($score->termIsActive());

        $this->actingAs($this->eduA)
            ->patchJson(route('educator.scores.restore', $score))
            ->assertOk();
    }

    // Task 31: a section is taught in whatever terms tbl_sections_term says, not whatever the
    // legacy academic_term_id column happens to hold. Carrying a section from PRELIM into MIDTERM
    // must survive PRELIM being deactivated.
    public function test_section_stays_visible_while_any_of_its_linked_terms_is_active(): void
    {
        $subject = $this->subject($this->eduA);
        $section = Section::find($subject->sections_id);

        $live = AcademicTerm::create([
            'term_name' => 'MIDTERM', 'semester' => '1st Semester',
            'academic_year_id' => $this->term->academic_year_id, 'is_active' => true,
        ]);
        $section->terms()->syncWithoutDetaching([$live->id]);   // now taught in both
        $this->term->update(['is_active' => false]);            // legacy column still points here

        $this->assertTrue(Section::visibleTo($this->eduA)->whereKey($section->id)->exists());
        $this->assertTrue(Subject::visibleTo($this->eduA)->whereKey($subject->id)->exists());

        // Drop the surviving link and it disappears like anything else in a dead term.
        $section->terms()->detach($live->id);
        $this->assertFalse(Section::visibleTo($this->eduA)->whereKey($section->id)->exists());
    }

    // Task 31: filing an assessment under a term is what tells the app the section is taught in
    // that term. Without this, a term rollover strands every section on the old term.
    public function test_saving_an_assessment_links_its_section_to_that_term(): void
    {
        $subject = $this->subject($this->eduA);
        $section = Section::find($subject->sections_id);

        $live = AcademicTerm::create([
            'term_name' => 'MIDTERM', 'semester' => '1st Semester',
            'academic_year_id' => $this->term->academic_year_id, 'is_active' => true,
        ]);
        $this->assertNotContains($live->id, $section->terms()->pluck('tbl_academic_term.id')->all());

        Assessment::create(array_merge($this->assessmentModelData($subject), ['term' => $live->id]));

        $this->assertContains($live->id, $section->terms()->pluck('tbl_academic_term.id')->all());

        // And the consequence that matters: deactivating the old term no longer hides the new
        // term's work.
        $this->term->update(['is_active' => false]);
        $this->assertTrue(Section::visibleTo($this->eduA)->whereKey($section->id)->exists());
        $this->assertSame(1, Assessment::visibleTo($this->eduA)->where('term', $live->id)->count());
    }

    // Task 31 regression: the reported failure. An assessment filed under an ACTIVE term while its
    // section was linked only to an INACTIVE one used to list on /educator/scores and then 404 when
    // previewed, because the index and the preview tested different terms. New saves can no longer
    // create that shape (Assessment::saved links the section), but pre-backfill rows can still hold
    // it, so the detach below reproduces it deliberately. Index and preview must now agree that it
    // is hidden, and the preview must degrade to a notice rather than an error.
    public function test_assessment_in_active_term_on_inactive_term_section_is_hidden_everywhere(): void
    {
        $subject = $this->subject($this->eduA);
        $assessment = Assessment::create($this->assessmentModelData($subject));
        Score::create([
            'student_id' => $this->student->id, 'educator_id' => $this->eduA->id,
            'assessment_id' => $assessment->id, 'subject_id' => $subject->id,
            'section_id' => $subject->sections_id, 'score' => 3, 'total_questions' => 5,
            'status' => 'passed', 'is_passed' => true, 'student_answer' => [],
            'submitted_at' => now(),
        ]);

        $liveTerm = AcademicTerm::create([
            'term_name' => 'MIDTERM', 'semester' => '1st Semester',
            'academic_year_id' => $this->term->academic_year_id, 'is_active' => true,
        ]);
        $assessment->update(['term' => $liveTerm->id]);
        // Legacy shape: the section is NOT taught in the assessment's term.
        Section::find($subject->sections_id)->terms()->detach($liveTerm->id);
        $this->term->update(['is_active' => false]);

        $classParams = [
            'subject' => $subject->id, 'section' => $subject->sections_id, 'term' => $liveTerm->id,
        ];

        // Not listed.
        $this->actingAs($this->eduA)
            ->get(route('educator.scores.index'))
            ->assertOk()
            ->assertDontSee($subject->subject_code);

        // Previewed anyway (stale page): a notice, not a 404.
        $this->actingAs($this->eduA)
            ->get(route('educator.scores.matrix', $classParams))
            ->assertOk()
            ->assertSee('inactive term')
            ->assertDontSee('3/5');

        $this->actingAs($this->eduA)
            ->get(route('educator.scores.student', $classParams + ['student' => $this->student->id]))
            ->assertOk()
            ->assertSee('inactive term');

        // A term that genuinely does not exist still 404s.
        $this->actingAs($this->eduA)
            ->get(route('educator.scores.matrix', ['subject' => $subject->id, 'section' => $subject->sections_id, 'term' => 999999]))
            ->assertNotFound();

        // Another educator's ids still 404 rather than leaking the notice.
        $this->actingAs($this->eduB)
            ->get(route('educator.scores.matrix', $classParams))
            ->assertNotFound();
    }

    // Task 13: deleting a bank question must never remove a recorded score, and the historical
    // per-question review must still resolve the (now soft-deleted) question.
    public function test_deleting_bank_question_preserves_scores_and_review(): void
    {
        $subject = $this->subject($this->eduA);
        $assessment = Assessment::create($this->assessmentModelData($subject));
        $quiz = Quiz::create([
            'subject_id' => $subject->id, 'educator_id' => $this->eduA->id,
            'question' => 'What is 2+2?', 'quiz_type' => 'multiple_choice',
            'choices' => ['A' => '3', 'B' => '4'], 'correct_answer' => 'B',
        ]);
        $score = Score::create([
            'student_id' => $this->student->id, 'educator_id' => $this->eduA->id,
            'assessment_id' => $assessment->id, 'subject_id' => $subject->id,
            'section_id' => $subject->sections_id, 'score' => 1, 'total_questions' => 1,
            'status' => 'passed', 'is_passed' => true,
            'student_answer' => [$quiz->id => 'B'], 'drawn_quiz_ids' => [$quiz->id],
        ]);

        // Delete the question (soft delete) — and confirm bulk "delete all" is just as safe.
        $this->actingAs($this->eduA)->delete(route('educator.quizzes.bulk'), ['quiz_ids' => [$quiz->id]])
            ->assertRedirect(route('educator.quizzes.index'));

        // Score survives; question is gone from the bank but recoverable for review.
        $this->assertDatabaseHas('tbl_scores', ['id' => $score->id, 'score' => 1, 'is_passed' => true]);
        $this->assertNull(Quiz::find($quiz->id));
        $this->assertNotNull(Quiz::withTrashed()->find($quiz->id));

        // Educator attempt-detail still renders the deleted question text (via withTrashed).
        $this->actingAs($this->eduA)->get(route('educator.scores.show', $score))
            ->assertOk()->assertSee('What is 2+2?');
    }

    // Task 13: batch filter must span the whole bank, so a batch whose questions sit on a later
    // pagination page still surfaces when selected.
    public function test_pool_batch_filter_finds_questions_across_pages(): void
    {
        $subject = $this->subject($this->eduA);
        $assessment = Assessment::create($this->assessmentModelData($subject));
        // 12 "Batch A" questions fill page 1 (per_page 10) and spill to page 2.
        for ($i = 0; $i < 12; $i++) {
            Quiz::create([
                'subject_id' => $subject->id, 'educator_id' => $this->eduA->id,
                'question' => "ALPHA question {$i}", 'quiz_type' => 'identification',
                'correct_answer' => 'x', 'batch_label' => 'Batch A',
            ]);
        }
        // One "Batch B" question, created last → highest id → would land on page 2 unfiltered.
        Quiz::create([
            'subject_id' => $subject->id, 'educator_id' => $this->eduA->id,
            'question' => 'BETA question unique', 'quiz_type' => 'identification',
            'correct_answer' => 'y', 'batch_label' => 'Batch B',
        ]);

        // Filtering by Batch B surfaces its question (not page-local) and hides Batch A entirely.
        $this->actingAs($this->eduA)->get(route('educator.assessments.pool.edit', $assessment).'?batch='.urlencode('Batch B'))
            ->assertOk()
            ->assertSee('BETA question unique')
            ->assertDontSee('ALPHA question 0');
    }

    public function test_educator_can_view_own_lists(): void
    {
        foreach (['educator.dashboard', 'educator.sections.index', 'educator.subjects.index', 'educator.enrollment.index', 'educator.assessments.index', 'educator.quizzes.index', 'educator.scores.index', 'educator.materials.index', 'educator.chats.index', 'educator.monitoring.index'] as $route) {
            $this->actingAs($this->eduA)->get(route($route))->assertOk();
        }
    }

    // ---- G2 sections (ownership + name-per-term uniqueness) ----

    public function test_educator_creates_section_and_cannot_edit_others(): void
    {
        $this->actingAs($this->eduA)->post(route('educator.sections.store'), [
            'section_name' => 'A1', 'academic_term_ids' => [$this->term->id], 'is_active' => '1',
        ])->assertRedirect(route('educator.sections.index'));

        $section = Section::where('section_name', 'A1')->firstOrFail();
        $this->assertSame($this->eduA->id, $section->educator_id);

        // eduB cannot edit eduA's section (policy denies → 403)
        $this->actingAs($this->eduB)->get(route('educator.sections.edit', $section))->assertForbidden();
        $this->actingAs($this->eduB)->delete(route('educator.sections.destroy', $section))->assertForbidden();
    }

    // J4: cross-tenant re-audit — an educator must not reach another educator's assessment/quiz
    // edit pages through the built routes (not just at the model-scope layer).
    public function test_educator_cannot_reach_another_educators_assessment_or_quiz(): void
    {
        $subject = $this->subject($this->eduA);
        $assessment = Assessment::create($this->assessmentModelData($subject));
        $quiz = Quiz::create([
            'subject_id' => $subject->id, 'educator_id' => $this->eduA->id,
            'question' => '2+2', 'quiz_type' => 'multiple_choice',
            'choices' => ['A' => '3', 'B' => '4'], 'correct_answer' => 'B',
        ]);

        $this->actingAs($this->eduB)->get(route('educator.assessments.edit', $assessment))->assertForbidden();
        $this->actingAs($this->eduB)->delete(route('educator.assessments.destroy', $assessment))->assertForbidden();
        $this->actingAs($this->eduB)->get(route('educator.quizzes.edit', $quiz))->assertForbidden();
        $this->actingAs($this->eduB)->delete(route('educator.quizzes.destroy', $quiz))->assertForbidden();
    }

    public function test_section_name_unique_per_term_per_educator(): void
    {
        $this->actingAs($this->eduA)->post(route('educator.sections.store'), [
            'section_name' => 'A1', 'academic_term_ids' => [$this->term->id], 'is_active' => '1',
        ])->assertRedirect();

        $this->actingAs($this->eduA)->post(route('educator.sections.store'), [
            'section_name' => 'A1', 'academic_term_ids' => [$this->term->id], 'is_active' => '1',
        ])->assertSessionHasErrors('section_name');

        // but eduB may reuse the same name (scoped per educator)
        $this->actingAs($this->eduB)->post(route('educator.sections.store'), [
            'section_name' => 'A1', 'academic_term_ids' => [$this->term->id], 'is_active' => '1',
        ])->assertRedirect();
    }

    // ---- G3 subjects (one row per section) ----

    public function test_subject_creates_one_row_per_section(): void
    {
        $s1 = $this->section($this->eduA, 'S1');
        $s2 = $this->section($this->eduA, 'S2');

        $this->actingAs($this->eduA)->post(route('educator.subjects.store'), [
            'subject_code' => 'MATH101', 'subject_name' => 'Math', 'section_ids' => [$s1->id, $s2->id], 'is_active' => '1',
        ])->assertRedirect();

        $this->assertSame(2, Subject::where('subject_code', 'MATH101')->count());
    }

    // Task 3: SubjectController::update() used to delete-then-recreate every row in the group,
    // even for a pure rename that didn't change the section list. Every subject-linked table
    // (tbl_assessments, tbl_quizzes, tbl_scores, tbl_enrolled) cascadeOnDelete()s off
    // tbl_subjects.id, so that destroyed every assessment/quiz/score/enrollment tied to the old
    // row. A rename with an unchanged section list must keep the same Subject id and leave every
    // dependent row intact.
    public function test_subject_rename_with_unchanged_sections_preserves_ids_and_dependents(): void
    {
        $section = $this->section($this->eduA, 'Sec1');
        $subject = Subject::create([
            'educator_id' => $this->eduA->id, 'sections_id' => $section->id,
            'subject_code' => 'MATH101', 'subject_name' => 'Math', 'is_active' => true,
        ]);
        $originalId = $subject->id;

        $assessment = Assessment::create($this->assessmentModelData($subject));
        $enrollment = Enrolled::create([
            'student_id' => $this->student->id, 'educator_id' => $this->eduA->id,
            'subject_id' => $subject->id, 'is_active' => true,
        ]);
        $score = Score::create([
            'student_id' => $this->student->id, 'educator_id' => $this->eduA->id,
            'assessment_id' => $assessment->id, 'subject_id' => $subject->id,
            'section_id' => $section->id, 'student_answer' => [],
        ]);
        $quiz = Quiz::create([
            'subject_id' => $subject->id, 'educator_id' => $this->eduA->id,
            'question' => '2+2', 'quiz_type' => 'identification', 'correct_answer' => '4',
        ]);

        // Rename only — same section list, new code/name.
        $this->actingAs($this->eduA)->put(route('educator.subjects.update', $subject), [
            'subject_code' => 'MATH101-A', 'subject_name' => 'Mathematics',
            'section_ids' => [$section->id], 'row_ids' => [$subject->id], 'is_active' => '1',
        ])->assertRedirect(route('educator.subjects.index'));

        $subject->refresh();
        $this->assertSame($originalId, $subject->id);
        $this->assertSame('MATH101-A', $subject->subject_code);
        $this->assertSame('Mathematics', $subject->subject_name);
        $this->assertSame(1, Subject::where('educator_id', $this->eduA->id)->count());

        // Dependent rows must still exist, still pointing at the SAME subject id — not recreated.
        $this->assertDatabaseHas('tbl_assessments', ['id' => $assessment->id, 'subject_id' => $originalId]);
        $this->assertDatabaseHas('tbl_enrolled', ['id' => $enrollment->id, 'subject_id' => $originalId]);
        $this->assertDatabaseHas('tbl_scores', ['id' => $score->id, 'subject_id' => $originalId]);
        $this->assertDatabaseHas('tbl_quizzes', ['id' => $quiz->id, 'subject_id' => $originalId]);
    }

    // Guard against Eloquent's keyBy() silently dropping a row on a sections_id collision: if a
    // forged request supplies row_ids spanning two different subject groups that happen to share
    // a section, keyBy('sections_id') would keep only the last one, stranding the other untouched.
    // The controller must reject that outright rather than risk it.
    public function test_subject_update_rejects_row_ids_spanning_multiple_groups(): void
    {
        $sharedSection = $this->section($this->eduA, 'Shared');
        $mathSubject = Subject::create([
            'educator_id' => $this->eduA->id, 'sections_id' => $sharedSection->id,
            'subject_code' => 'MATH101', 'subject_name' => 'Math', 'is_active' => true,
        ]);
        $scienceSubject = Subject::create([
            'educator_id' => $this->eduA->id, 'sections_id' => $sharedSection->id,
            'subject_code' => 'SCI101', 'subject_name' => 'Science', 'is_active' => true,
        ]);

        $this->actingAs($this->eduA)->put(route('educator.subjects.update', $mathSubject), [
            'subject_code' => 'MATH101-A', 'subject_name' => 'Mathematics',
            'section_ids' => [$sharedSection->id],
            'row_ids' => [$mathSubject->id, $scienceSubject->id], 'is_active' => '1',
        ])->assertStatus(422);

        // Neither group was touched.
        $this->assertSame('MATH101', $mathSubject->fresh()->subject_code);
        $this->assertSame('SCI101', $scienceSubject->fresh()->subject_code);
    }

    // Companion to the rename test above: removing a section from the group must still delete
    // that section's row and cascade its dependent data — proving the diff-based fix didn't
    // remove the legitimate cascade path for actual removals.
    public function test_subject_update_removing_a_section_still_cascades_that_sections_data(): void
    {
        $keptSection = $this->section($this->eduA, 'Kept');
        $droppedSection = $this->section($this->eduA, 'Dropped');
        $keptSubject = Subject::create([
            'educator_id' => $this->eduA->id, 'sections_id' => $keptSection->id,
            'subject_code' => 'MATH101', 'subject_name' => 'Math', 'is_active' => true,
        ]);
        $droppedSubject = Subject::create([
            'educator_id' => $this->eduA->id, 'sections_id' => $droppedSection->id,
            'subject_code' => 'MATH101', 'subject_name' => 'Math', 'is_active' => true,
        ]);
        $keptId = $keptSubject->id;

        $droppedAssessment = Assessment::create($this->assessmentModelData($droppedSubject));
        $droppedEnrollment = Enrolled::create([
            'student_id' => $this->student->id, 'educator_id' => $this->eduA->id,
            'subject_id' => $droppedSubject->id, 'is_active' => true,
        ]);

        // Edit the group down to just the kept section.
        $this->actingAs($this->eduA)->put(route('educator.subjects.update', $keptSubject), [
            'subject_code' => 'MATH101', 'subject_name' => 'Math',
            'section_ids' => [$keptSection->id],
            'row_ids' => [$keptSubject->id, $droppedSubject->id], 'is_active' => '1',
        ])->assertRedirect(route('educator.subjects.index'));

        // Kept row survives with its original id.
        $this->assertSame($keptId, $keptSubject->fresh()->id);
        $this->assertDatabaseHas('tbl_subjects', ['id' => $keptId]);

        // Dropped row and its dependents are gone (intentional cascade, not a bug).
        $this->assertDatabaseMissing('tbl_subjects', ['id' => $droppedSubject->id]);
        $this->assertDatabaseMissing('tbl_assessments', ['id' => $droppedAssessment->id]);
        $this->assertDatabaseMissing('tbl_enrolled', ['id' => $droppedEnrollment->id]);
    }

    // ---- G4 enrollment + scope ----

    public function test_enrollment_pairs_and_scope(): void
    {
        $subject = $this->subject($this->eduA);

        $this->actingAs($this->eduA)->post(route('educator.enrollment.store'), [
            'student_ids' => [$this->student->id], 'subject_ids' => [$subject->id], 'is_active' => '1',
        ])->assertRedirect();

        $enr = Enrolled::firstOrFail();
        $this->assertSame($this->eduA->id, $enr->educator_id);
        // eduB cannot see eduA's enrollment via the scope
        $this->assertFalse(Enrolled::visibleTo($this->eduB)->whereKey($enr->id)->exists());
    }

    // Task 01: bulk "Unenroll All Students" removes only the target subject's rows, scoped to
    // the owning educator — must not touch another subject's enrollments or another educator's.
    public function test_unenroll_all_removes_only_target_subjects_students_scoped_to_owner(): void
    {
        $subjectA1 = $this->subject($this->eduA);
        $subjectA2 = $this->subject($this->eduA);
        $otherStudent = $this->makeUser('student', 'student');

        Enrolled::create(['student_id' => $this->student->id, 'educator_id' => $this->eduA->id, 'subject_id' => $subjectA1->id, 'is_active' => true]);
        Enrolled::create(['student_id' => $otherStudent->id, 'educator_id' => $this->eduA->id, 'subject_id' => $subjectA1->id, 'is_active' => true]);
        $untouched = Enrolled::create(['student_id' => $this->student->id, 'educator_id' => $this->eduA->id, 'subject_id' => $subjectA2->id, 'is_active' => true]);

        $this->actingAs($this->eduA)
            ->post(route('educator.enrollment.subject.unenrollAll', $subjectA1))
            ->assertRedirect(route('educator.enrollment.index'));

        $this->assertSame(0, Enrolled::where('subject_id', $subjectA1->id)->count());
        $this->assertTrue(Enrolled::whereKey($untouched->id)->exists());

        // eduB may not bulk-unenroll a subject they don't own.
        $subjectB = $this->subject($this->eduB);
        Enrolled::create(['student_id' => $this->student->id, 'educator_id' => $this->eduB->id, 'subject_id' => $subjectB->id, 'is_active' => true]);
        $this->actingAs($this->eduA)
            ->post(route('educator.enrollment.subject.unenrollAll', $subjectB))
            ->assertForbidden();
        $this->assertSame(1, Enrolled::where('subject_id', $subjectB->id)->count());
    }

    // ---- G5 assessments: publish-on-activate notifies enrolled students ----

    public function test_activating_assessment_notifies_enrolled_students(): void
    {
        $subject = $this->subject($this->eduA);
        $section = Section::find($subject->sections_id);
        Enrolled::create(['student_id' => $this->student->id, 'educator_id' => $this->eduA->id, 'subject_id' => $subject->id, 'is_active' => true]);

        // create inactive (no notification yet)
        $this->actingAs($this->eduA)->post(route('educator.assessments.store'), $this->assessmentPayload($subject, $section, false))->assertRedirect();
        $this->assertDatabaseCount('tbl_notifications', 0);

        // flip to active → publish + notify enrolled student
        $assessment = Assessment::firstOrFail();
        $this->actingAs($this->eduA)->put(route('educator.assessments.update', $assessment), $this->assessmentPayload($subject, $section, true))->assertRedirect();
        $this->assertDatabaseHas('tbl_notifications', [
            'recipient_user_id' => $this->student->id, 'event_type' => 'assessment_created',
        ]);
    }

    public function test_assessment_can_be_published_without_notifying_students(): void
    {
        $subject = $this->subject($this->eduA);
        $section = Section::findOrFail($subject->sections_id);
        Enrolled::create(['student_id' => $this->student->id, 'educator_id' => $this->eduA->id, 'subject_id' => $subject->id, 'is_active' => true]);

        $payload = $this->assessmentPayload($subject, $section, false);
        unset($payload['is_active']);
        $payload['publish_mode'] = 'active_silent';

        $this->actingAs($this->eduA)
            ->post(route('educator.assessments.store'), $payload)
            ->assertRedirect(route('educator.assessments.index'));

        $assessment = Assessment::where('subject_id', $subject->id)->latest('id')->firstOrFail();
        $this->assertTrue($assessment->is_active);
        $this->assertSame(0, Notification::where('recipient_user_id', $this->student->id)
            ->where('event_type', 'assessment_created')
            ->count());
    }

    // Task 01: bulk exempt/un-exempt students from an assessment (e.g. absent). Ownership is
    // checked via the assessment update policy; only actually-enrolled students are affected.
    public function test_educator_can_bulk_exempt_and_unexempt_students(): void
    {
        $subject = $this->subject($this->eduA);
        $otherStudent = $this->makeUser('student', 'student');
        Enrolled::create(['student_id' => $this->student->id, 'educator_id' => $this->eduA->id, 'subject_id' => $subject->id, 'is_active' => true]);
        Enrolled::create(['student_id' => $otherStudent->id, 'educator_id' => $this->eduA->id, 'subject_id' => $subject->id, 'is_active' => true]);
        $assessment = Assessment::create($this->assessmentModelData($subject));

        $this->actingAs($this->eduA)
            ->post(route('educator.assessments.exemptions.toggle', $assessment), [
                'student_ids' => [$this->student->id, $otherStudent->id], 'action' => 'exempt',
                'reason' => 'Absent during the assessment window',
            ])
            ->assertRedirect(route('educator.assessments.index'));
        $this->assertDatabaseHas('tbl_student_assessment_exemptions', [
            'assessment_id' => $assessment->id, 'student_id' => $this->student->id, 'is_active' => 1,
        ]);
        $this->assertDatabaseHas('tbl_student_assessment_exemptions', [
            'assessment_id' => $assessment->id, 'student_id' => $otherStudent->id, 'is_active' => 1,
        ]);

        // un-exempting one of them leaves the other exempted.
        $this->actingAs($this->eduA)
            ->post(route('educator.assessments.exemptions.toggle', $assessment), [
                'student_ids' => [$this->student->id], 'action' => 'unexempt',
            ])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();
        $this->assertDatabaseHas('tbl_student_assessment_exemptions', [
            'assessment_id' => $assessment->id, 'student_id' => $this->student->id, 'is_active' => 0,
        ]);
        $this->assertDatabaseHas('tbl_student_assessment_exemptions', [
            'assessment_id' => $assessment->id, 'student_id' => $otherStudent->id, 'is_active' => 1,
        ]);

        // eduB cannot manage exemptions on eduA's assessment.
        $this->actingAs($this->eduB)
            ->post(route('educator.assessments.exemptions.toggle', $assessment), [
                'student_ids' => [$this->student->id], 'action' => 'exempt',
                'reason' => 'Unauthorized attempt',
            ])
            ->assertForbidden();
        $this->actingAs($this->eduB)->get(route('educator.assessments.exemptions', $assessment))->assertForbidden();
    }

    // A student id not actually enrolled in the assessment's subject (forged/stale payload)
    // must be silently ignored, not create a stray exemption row.
    public function test_bulk_exemption_ignores_students_not_enrolled_in_the_subject(): void
    {
        $subject = $this->subject($this->eduA);
        Enrolled::create(['student_id' => $this->student->id, 'educator_id' => $this->eduA->id, 'subject_id' => $subject->id, 'is_active' => true]);
        $assessment = Assessment::create($this->assessmentModelData($subject));
        $notEnrolled = $this->makeUser('student', 'student');

        $this->actingAs($this->eduA)
            ->post(route('educator.assessments.exemptions.toggle', $assessment), [
                'student_ids' => [$this->student->id, $notEnrolled->id], 'action' => 'exempt',
                'reason' => 'Absent during the assessment window',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('tbl_student_assessment_exemptions', [
            'assessment_id' => $assessment->id, 'student_id' => $this->student->id,
            'reason' => 'Absent during the assessment window', 'is_active' => 1,
        ]);
        $this->assertDatabaseMissing('tbl_student_assessment_exemptions', [
            'assessment_id' => $assessment->id, 'student_id' => $notEnrolled->id,
        ]);
    }

    public function test_exemption_toggle_returns_json_for_modal_ajax_submission(): void
    {
        $subject = $this->subject($this->eduA);
        Enrolled::create(['student_id' => $this->student->id, 'educator_id' => $this->eduA->id, 'subject_id' => $subject->id, 'is_active' => true]);
        $assessment = Assessment::create($this->assessmentModelData($subject));

        $this->actingAs($this->eduA)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])
            ->post(route('educator.assessments.exemptions.toggle', $assessment), [
                'student_ids' => [$this->student->id],
                'action' => 'exempt',
                'reason' => 'Absent during the assessment window',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('action', 'exempt')
            ->assertJsonPath('affected_student_ids', [$this->student->id]);

        $this->assertDatabaseHas('tbl_student_assessment_exemptions', [
            'assessment_id' => $assessment->id,
            'student_id' => $this->student->id,
            'is_active' => 1,
            'reason' => 'Absent during the assessment window',
        ]);
    }

    public function test_exemption_save_accepts_empty_selection_for_fetch_submission(): void
    {
        $subject = $this->subject($this->eduA);
        $assessment = Assessment::create($this->assessmentModelData($subject));

        $this->actingAs($this->eduA)
            ->postJson(route('educator.assessments.exemptions.toggle', $assessment), [
                'action' => 'exempt',
                'reason' => '',
            ])
            ->assertOk()
            ->assertJsonPath('affected_student_ids', []);
    }

    public function test_exempting_a_student_sends_the_assessment_and_reason(): void
    {
        $subject = $this->subject($this->eduA);
        Enrolled::create(['student_id' => $this->student->id, 'educator_id' => $this->eduA->id, 'subject_id' => $subject->id, 'is_active' => true]);
        $assessment = Assessment::create(array_merge($this->assessmentModelData($subject), ['assessment_code' => 'MIDTERM']));

        $this->actingAs($this->eduA)
            ->post(route('educator.assessments.exemptions.toggle', $assessment), [
                'student_ids' => [$this->student->id], 'action' => 'exempt', 'reason' => 'Medical absence',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('tbl_notifications', [
            'recipient_user_id' => $this->student->id,
            'event_type' => 'assessment_exempted',
            'title' => 'Assessment exemption',
            'message' => 'You have been exempted from MIDTERM. Reason: Medical absence',
            'assessment_id' => $assessment->id,
        ]);
    }

    // Task 29: the exemption message still lands in the private thread, but tagged as a system
    // alert so it renders as a KTUI alert rather than an ordinary chat bubble.
    public function test_exempting_students_starts_private_chats_tagged_as_alerts(): void
    {
        $subject = $this->subject($this->eduA);
        $otherStudent = $this->makeUser('student', 'student');
        $unselectedStudent = $this->makeUser('student', 'student');
        Enrolled::create(['student_id' => $this->student->id, 'educator_id' => $this->eduA->id, 'subject_id' => $subject->id, 'is_active' => true]);
        Enrolled::create(['student_id' => $otherStudent->id, 'educator_id' => $this->eduA->id, 'subject_id' => $subject->id, 'is_active' => true]);
        $assessment = Assessment::create(array_merge($this->assessmentModelData($subject), ['assessment_code' => 'MIDTERM']));

        $this->actingAs($this->eduA)
            ->post(route('educator.assessments.exemptions.toggle', $assessment), [
                'student_ids' => [$this->student->id, $otherStudent->id, $unselectedStudent->id],
                'action' => 'exempt',
                'reason' => 'Medical absence',
            ])
            ->assertRedirect();

        $this->assertDatabaseCount('tbl_conversations', 2);
        foreach ([$this->student, $otherStudent] as $student) {
            $conversation = Conversation::where('educator_id', $this->eduA->id)
                ->where('student_id', $student->id)->firstOrFail();

            $this->assertDatabaseHas('tbl_conversation_messages', [
                'conversation_id' => $conversation->id,
                'sender_user_id' => $this->eduA->id,
                'content' => 'You have been exempted from MIDTERM. Reason: Medical absence',
                'kind' => 'assessment_exempted',
            ]);
        }
        $this->assertDatabaseMissing('tbl_conversations', ['student_id' => $unselectedStudent->id]);
    }

    public function test_exemption_form_accepts_a_reason(): void
    {
        $subject = $this->subject($this->eduA);
        Enrolled::create(['student_id' => $this->student->id, 'educator_id' => $this->eduA->id, 'subject_id' => $subject->id, 'is_active' => true]);
        $assessment = Assessment::create($this->assessmentModelData($subject));

        $this->actingAs($this->eduA)
            ->get(route('educator.assessments.exemptions', ['assessment' => $assessment, 'modal' => 1]))
            ->assertOk()
            ->assertSee('name="reason"', false)
            ->assertSee('action="'.route('educator.assessments.exemptions.toggle', $assessment, false).'"', false)
            ->assertSee('data-assessment-modal-form', false)
            ->assertSee('data-no-spinner', false)
            ->assertSee('data-exempt-status', false)
            ->assertSee('Why is this student being exempted?', false);
    }

    // Task 01: the exemptions list pre-checks an already-exempted student's checkbox (mirroring
    // the "Exempted" badge), so Save Changes preserves the active state.
    public function test_exemptions_list_pre_checks_already_exempted_students(): void
    {
        $subject = $this->subject($this->eduA);
        $exemptedStudent = $this->student;
        $notExemptedStudent = $this->makeUser('student', 'student');
        Enrolled::create(['student_id' => $exemptedStudent->id, 'educator_id' => $this->eduA->id, 'subject_id' => $subject->id, 'is_active' => true]);
        Enrolled::create(['student_id' => $notExemptedStudent->id, 'educator_id' => $this->eduA->id, 'subject_id' => $subject->id, 'is_active' => true]);
        $assessment = Assessment::create($this->assessmentModelData($subject));
        StudentAssessmentExemption::create([
            'educator_id' => $this->eduA->id, 'student_id' => $exemptedStudent->id,
            'assessment_id' => $assessment->id, 'is_active' => true,
        ]);

        $html = $this->actingAs($this->eduA)
            ->get(route('educator.assessments.exemptions', ['assessment' => $assessment, 'modal' => 1]))
            ->assertOk()->getContent();

        $dom = new \DOMDocument;
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);

        $exemptedBox = $xpath->query("//input[@value='{$exemptedStudent->id}']")->item(0);
        $notExemptedBox = $xpath->query("//input[@value='{$notExemptedStudent->id}']")->item(0);

        $this->assertNotNull($exemptedBox);
        $this->assertTrue($exemptedBox->hasAttribute('checked'));
        $this->assertNotNull($notExemptedBox);
        $this->assertFalse($notExemptedBox->hasAttribute('checked'));
    }

    public function test_educator_can_grant_and_revoke_special_access_for_enrolled_students(): void
    {
        $subject = $this->subject($this->eduA);
        $otherStudent = $this->makeUser('student', 'student');
        Enrolled::create(['student_id' => $this->student->id, 'educator_id' => $this->eduA->id, 'subject_id' => $subject->id, 'is_active' => true]);
        Enrolled::create(['student_id' => $otherStudent->id, 'educator_id' => $this->eduA->id, 'subject_id' => $subject->id, 'is_active' => true]);
        $assessment = Assessment::create($this->assessmentModelData($subject));

        $this->actingAs($this->eduA)
            ->get(route('educator.assessments.access', ['assessment' => $assessment, 'modal' => 1]))
            ->assertOk()
            ->assertSee('Manage special access')
            ->assertSee('action="'.route('educator.assessments.access.toggle', $assessment, false).'"', false)
            ->assertSee('data-assessment-modal-form', false)
            ->assertSee('data-no-spinner', false)
            ->assertSee('data-exempt-status', false)
            ->assertSee((string) $this->student->user_id)
            ->assertSee((string) $otherStudent->user_id);

        $this->actingAs($this->eduA)
            ->post(route('educator.assessments.access.toggle', $assessment), [
                'student_ids' => [$this->student->id, $otherStudent->id],
                'action' => 'grant',
            ])
            ->assertRedirect(route('educator.assessments.index'));

        $this->assertDatabaseHas('tbl_student_assessment_access', [
            'assessment_id' => $assessment->id,
            'student_id' => $this->student->id,
            'is_active' => 1,
        ]);
        $this->assertDatabaseHas('tbl_student_assessment_access', [
            'assessment_id' => $assessment->id,
            'student_id' => $otherStudent->id,
            'is_active' => 1,
        ]);

        $this->actingAs($this->eduA)
            ->post(route('educator.assessments.access.toggle', $assessment), [
                'student_ids' => [$this->student->id],
                'action' => 'revoke',
            ])
            ->assertRedirect(route('educator.assessments.index'));

        $this->assertDatabaseHas('tbl_student_assessment_access', [
            'assessment_id' => $assessment->id,
            'student_id' => $this->student->id,
            'is_active' => 0,
        ]);
        $this->assertDatabaseHas('tbl_student_assessment_access', [
            'assessment_id' => $assessment->id,
            'student_id' => $otherStudent->id,
            'is_active' => 1,
        ]);
    }

    public function test_assessment_index_uses_host_relative_access_and_exemption_modal_urls(): void
    {
        $subject = $this->subject($this->eduA);
        $assessment = Assessment::create($this->assessmentModelData($subject));

        $this->actingAs($this->eduA)
            ->get(route('educator.assessments.index'))
            ->assertOk()
            ->assertSee('data-modal-url="'.route('educator.assessments.exemptions', $assessment, false).'"', false)
            ->assertSee('data-modal-url="'.route('educator.assessments.access', $assessment, false).'"', false);
    }

    public function test_special_access_toggle_returns_json_for_modal_ajax_submission(): void
    {
        $subject = $this->subject($this->eduA);
        Enrolled::create(['student_id' => $this->student->id, 'educator_id' => $this->eduA->id, 'subject_id' => $subject->id, 'is_active' => true]);
        $assessment = Assessment::create($this->assessmentModelData($subject));

        $this->actingAs($this->eduA)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])
            ->post(route('educator.assessments.access.toggle', $assessment), [
                'student_ids' => [$this->student->id],
                'action' => 'grant',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('action', 'grant')
            ->assertJsonPath('affected_student_ids', [$this->student->id]);

        $this->assertTrue(StudentAssessmentAccess::where([
            'assessment_id' => $assessment->id,
            'student_id' => $this->student->id,
            'is_active' => 1,
        ])->exists());
    }

    // Task 24: special access used to last forever until used. The duration is now picked per grant.
    public function test_granting_special_access_stamps_the_selected_duration(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-16 09:00:00'));
        $subject = $this->subject($this->eduA);
        Enrolled::create(['student_id' => $this->student->id, 'educator_id' => $this->eduA->id, 'subject_id' => $subject->id, 'is_active' => true]);
        $assessment = Assessment::create($this->assessmentModelData($subject));

        $this->actingAs($this->eduA)
            ->post(route('educator.assessments.access.toggle', $assessment), [
                'student_ids' => [$this->student->id], 'action' => 'grant', 'duration_hours' => 24,
            ])
            ->assertRedirect();

        $grant = StudentAssessmentAccess::where('assessment_id', $assessment->id)
            ->where('student_id', $this->student->id)->firstOrFail();
        $this->assertTrue(now()->addHours(24)->equalTo($grant->expires_at));

        Carbon::setTestNow();
    }

    public function test_granting_special_access_with_no_expiry_leaves_expires_at_null(): void
    {
        $subject = $this->subject($this->eduA);
        Enrolled::create(['student_id' => $this->student->id, 'educator_id' => $this->eduA->id, 'subject_id' => $subject->id, 'is_active' => true]);
        $assessment = Assessment::create($this->assessmentModelData($subject));

        $this->actingAs($this->eduA)
            ->post(route('educator.assessments.access.toggle', $assessment), [
                'student_ids' => [$this->student->id], 'action' => 'grant', 'duration_hours' => 0,
            ])
            ->assertRedirect();

        $this->assertNull(StudentAssessmentAccess::where('assessment_id', $assessment->id)
            ->where('student_id', $this->student->id)->value('expires_at'));
    }

    public function test_special_access_modal_renders_the_duration_select(): void
    {
        $subject = $this->subject($this->eduA);
        Enrolled::create(['student_id' => $this->student->id, 'educator_id' => $this->eduA->id, 'subject_id' => $subject->id, 'is_active' => true]);
        $assessment = Assessment::create($this->assessmentModelData($subject));

        $this->actingAs($this->eduA)
            ->get(route('educator.assessments.access', ['assessment' => $assessment, 'modal' => 1]))
            ->assertOk()
            ->assertSee('name="duration_hours"', false)
            ->assertSee('How long should this access last?', false)
            ->assertSee('No expiry — until used', false);
    }

    // The modal rebuilds each status cell from this response, so the deadline has to travel with it.
    public function test_special_access_json_carries_the_deadline_for_the_modal_resync(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-16 09:00:00'));
        $subject = $this->subject($this->eduA);
        Enrolled::create(['student_id' => $this->student->id, 'educator_id' => $this->eduA->id, 'subject_id' => $subject->id, 'is_active' => true]);
        $assessment = Assessment::create($this->assessmentModelData($subject));

        $this->actingAs($this->eduA)
            ->postJson(route('educator.assessments.access.toggle', $assessment), [
                'student_ids' => [$this->student->id], 'action' => 'grant', 'duration_hours' => 24,
            ])
            ->assertOk()
            ->assertJsonPath('access_deadlines.'.$this->student->id, 'Jul 17, 9:00 AM');

        // A no-expiry grant contributes no deadline entry.
        $this->actingAs($this->eduA)
            ->postJson(route('educator.assessments.access.toggle', $assessment), [
                'student_ids' => [$this->student->id], 'action' => 'grant', 'duration_hours' => 0,
            ])
            ->assertOk()
            ->assertJsonPath('access_deadlines', []);

        Carbon::setTestNow();
    }

    public function test_special_access_rejects_an_unsupported_duration(): void
    {
        $subject = $this->subject($this->eduA);
        Enrolled::create(['student_id' => $this->student->id, 'educator_id' => $this->eduA->id, 'subject_id' => $subject->id, 'is_active' => true]);
        $assessment = Assessment::create($this->assessmentModelData($subject));

        $this->actingAs($this->eduA)
            ->postJson(route('educator.assessments.access.toggle', $assessment), [
                'student_ids' => [$this->student->id], 'action' => 'grant', 'duration_hours' => 9999,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('duration_hours');
    }

    public function test_granting_special_access_notifies_and_messages_the_student(): void
    {
        $subject = $this->subject($this->eduA);
        Enrolled::create(['student_id' => $this->student->id, 'educator_id' => $this->eduA->id, 'subject_id' => $subject->id, 'is_active' => true]);
        $assessment = Assessment::create(array_merge($this->assessmentModelData($subject), ['assessment_code' => 'MIDTERM']));

        $this->actingAs($this->eduA)
            ->post(route('educator.assessments.access.toggle', $assessment), [
                'student_ids' => [$this->student->id],
                'action' => 'grant',
                'duration_hours' => 0,
            ])
            ->assertRedirect();

        // duration_hours=0 is the no-expiry grant, so the message carries no deadline.
        $message = 'You have been granted special access to MIDTERM.';
        $this->assertDatabaseHas('tbl_notifications', [
            'recipient_user_id' => $this->student->id,
            'event_type' => 'assessment_access_granted',
            'title' => 'Assessment special access',
            'message' => $message,
            'assessment_id' => $assessment->id,
        ]);

        $conversation = Conversation::where('educator_id', $this->eduA->id)
            ->where('student_id', $this->student->id)->firstOrFail();
        $this->assertDatabaseHas('tbl_conversation_messages', [
            'conversation_id' => $conversation->id,
            'sender_user_id' => $this->eduA->id,
            'content' => $message,
            'kind' => 'assessment_access_granted',
        ]);
    }

    public function test_special_access_save_accepts_empty_selection_for_fetch_submission(): void
    {
        $subject = $this->subject($this->eduA);
        $assessment = Assessment::create($this->assessmentModelData($subject));

        $this->actingAs($this->eduA)
            ->postJson(route('educator.assessments.access.toggle', $assessment), [
                'action' => 'grant',
            ])
            ->assertOk()
            ->assertJsonPath('affected_student_ids', []);
    }

    public function test_special_access_toggle_ignores_non_enrolled_students_and_other_educators(): void
    {
        $subject = $this->subject($this->eduA);
        $notEnrolled = $this->makeUser('student', 'student');
        Enrolled::create(['student_id' => $this->student->id, 'educator_id' => $this->eduA->id, 'subject_id' => $subject->id, 'is_active' => true]);
        $assessment = Assessment::create($this->assessmentModelData($subject));

        $this->actingAs($this->eduA)
            ->post(route('educator.assessments.access.toggle', $assessment), [
                'student_ids' => [$this->student->id, $notEnrolled->id],
                'action' => 'grant',
            ])
            ->assertRedirect(route('educator.assessments.index'));

        $this->assertDatabaseHas('tbl_student_assessment_access', [
            'assessment_id' => $assessment->id,
            'student_id' => $this->student->id,
            'is_active' => 1,
        ]);
        $this->assertDatabaseMissing('tbl_student_assessment_access', [
            'assessment_id' => $assessment->id,
            'student_id' => $notEnrolled->id,
        ]);

        $this->actingAs($this->eduB)
            ->get(route('educator.assessments.access', ['assessment' => $assessment, 'modal' => 1]))
            ->assertForbidden();
        $this->actingAs($this->eduB)
            ->post(route('educator.assessments.access.toggle', $assessment), [
                'student_ids' => [$this->student->id],
                'action' => 'grant',
            ])
            ->assertForbidden();
    }

    public function test_creating_assessment_with_multiple_subjects_makes_one_each(): void
    {
        $subjectA = $this->subject($this->eduA);
        $subjectB = $this->subject($this->eduA);

        $payload = $this->assessmentPayload($subjectA, Section::find($subjectA->sections_id), false);
        $payload['subject_ids'] = [$subjectA->id, $subjectB->id];

        $this->actingAs($this->eduA)->post(route('educator.assessments.store'), $payload)->assertRedirect();

        $this->assertDatabaseHas('tbl_assessments', ['subject_id' => $subjectA->id, 'section_id' => $subjectA->sections_id]);
        $this->assertDatabaseHas('tbl_assessments', ['subject_id' => $subjectB->id, 'section_id' => $subjectB->sections_id]);
    }

    public function test_educator_can_duplicate_assessment_with_question_pool_without_touching_original(): void
    {
        $subject = $this->subject($this->eduA);
        $assessment = Assessment::create($this->assessmentModelData($subject));
        $quiz = Quiz::create([
            'subject_id' => $subject->id, 'educator_id' => $this->eduA->id,
            'question' => 'Original pool question', 'quiz_type' => 'identification',
            'correct_answer' => 'yes',
        ]);
        $assessment->eligibleQuizzes()->sync([$quiz->id]);
        $assessment->update(['pool_size' => 1]);

        $this->actingAs($this->eduA)
            ->get(route('educator.assessments.duplicate', $assessment))
            ->assertOk()
            ->assertSee('Q1 Copy');

        $payload = $this->assessmentPayload($subject, Section::find($subject->sections_id), false);
        $payload['assessment_code'] = 'Q1 Copy';
        $payload['time_limit'] = '45';

        $this->actingAs($this->eduA)
            ->post(route('educator.assessments.duplicate.store', $assessment), $payload)
            ->assertRedirect(route('educator.assessments.index'));

        $assessment->refresh();
        $duplicate = Assessment::where('assessment_code', 'Q1 Copy')->firstOrFail();

        $this->assertNotSame($assessment->id, $duplicate->id);
        $this->assertSame('30', $assessment->time_limit);
        $this->assertSame('45', $duplicate->time_limit);
        $this->assertSame(1, $duplicate->pool_size);
        $this->assertSame([$quiz->id], $duplicate->eligibleQuizzes()->pluck('tbl_quizzes.id')->all());
    }

    public function test_educator_cannot_duplicate_another_educators_assessment(): void
    {
        $subject = $this->subject($this->eduA);
        $subjectB = $this->subject($this->eduB);
        $assessment = Assessment::create($this->assessmentModelData($subject));

        $this->actingAs($this->eduB)
            ->get(route('educator.assessments.duplicate', $assessment))
            ->assertForbidden();

        $this->actingAs($this->eduB)
            ->post(route('educator.assessments.duplicate.store', $assessment), $this->assessmentPayload($subjectB, Section::find($subjectB->sections_id), false))
            ->assertForbidden();
    }

    public function test_assessments_index_sorts_by_subject_on_the_server(): void
    {
        $alpha = Subject::create([
            'educator_id' => $this->eduA->id,
            'sections_id' => $this->section($this->eduA, 'Alpha Section')->id,
            'subject_code' => 'A100',
            'subject_name' => 'Alpha Subject',
            'is_active' => true,
        ]);
        $zulu = Subject::create([
            'educator_id' => $this->eduA->id,
            'sections_id' => $this->section($this->eduA, 'Zulu Section')->id,
            'subject_code' => 'Z100',
            'subject_name' => 'Zulu Subject',
            'is_active' => true,
        ]);
        Assessment::create($this->assessmentModelData($zulu));
        Assessment::create(array_merge($this->assessmentModelData($alpha), ['assessment_code' => 'A2']));

        $this->actingAs($this->eduA)
            ->get(route('educator.assessments.index', ['sort' => 'subject', 'direction' => 'asc']))
            ->assertOk()
            ->assertSee('data-sort="subject"', false)
            ->assertSeeInOrder(['Alpha Subject', 'Zulu Subject']);
    }

    public function test_assessments_index_exposes_an_assessment_code_filter(): void
    {
        $subject = $this->subject($this->eduA);
        Assessment::create($this->assessmentModelData($subject));
        Assessment::create(array_merge($this->assessmentModelData($subject), ['assessment_code' => 'X2']));

        $this->actingAs($this->eduA)
            ->get(route('educator.assessments.index'))
            ->assertOk()
            ->assertSee('data-filter="assessment"', false)
            ->assertSee('All assessment codes', false);
    }

    public function test_exemptions_modal_hides_the_footer_close_button(): void
    {
        $subject = $this->subject($this->eduA);
        $student = $this->student;
        Enrolled::create(['student_id' => $student->id, 'educator_id' => $this->eduA->id, 'subject_id' => $subject->id, 'is_active' => true]);
        $assessment = Assessment::create($this->assessmentModelData($subject));

        $html = $this->actingAs($this->eduA)
            ->get(route('educator.assessments.exemptions', ['assessment' => $assessment, 'modal' => 1]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('data-modal-cancel', $html);
        $this->assertStringNotContainsString('Close', $html);
        $this->assertStringContainsString('Save Changes', $html);
        $this->assertStringNotContainsString('Exempt Selected', $html);
        $this->assertStringNotContainsString('Un-exempt Selected', $html);
    }

    public function test_exemptions_modal_renders_students_in_a_table(): void
    {
        $subject = $this->subject($this->eduA);
        $student = $this->student;
        Enrolled::create(['student_id' => $student->id, 'educator_id' => $this->eduA->id, 'subject_id' => $subject->id, 'is_active' => true]);
        $assessment = Assessment::create($this->assessmentModelData($subject));

        $html = $this->actingAs($this->eduA)
            ->get(route('educator.assessments.exemptions', ['assessment' => $assessment, 'modal' => 1]))
            ->assertOk()
            ->getContent();

        $dom = new \DOMDocument;
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);

        $this->assertSame(1, $xpath->query('//table[contains(@class, "kt-table")]')->length);
        $this->assertSame(1, $xpath->query('//tbody/tr')->length);
        $this->assertSame(1, $xpath->query('//input[@data-exempt-select-all]')->length);
    }

    public function test_exemption_save_applies_checked_and_unchecked_state_and_accepts_empty_selection(): void
    {
        $subject = $this->subject($this->eduA);
        $checked = $this->student;
        $unchecked = $this->makeUser('student', 'student');
        Enrolled::create(['student_id' => $checked->id, 'educator_id' => $this->eduA->id, 'subject_id' => $subject->id, 'is_active' => true]);
        Enrolled::create(['student_id' => $unchecked->id, 'educator_id' => $this->eduA->id, 'subject_id' => $subject->id, 'is_active' => true]);
        $assessment = Assessment::create($this->assessmentModelData($subject));
        StudentAssessmentExemption::create([
            'educator_id' => $this->eduA->id, 'student_id' => $unchecked->id,
            'assessment_id' => $assessment->id, 'reason' => 'Existing', 'is_active' => true,
        ]);

        $this->actingAs($this->eduA)
            ->postJson(route('educator.assessments.exemptions.toggle', $assessment), [
                'student_ids' => [$checked->id], 'reason' => 'Absent',
            ])
            ->assertOk()
            ->assertJsonPath('affected_student_ids', [$checked->id]);

        $this->assertDatabaseHas('tbl_student_assessment_exemptions', [
            'assessment_id' => $assessment->id, 'student_id' => $checked->id, 'is_active' => 1,
        ]);
        $this->assertDatabaseHas('tbl_student_assessment_exemptions', [
            'assessment_id' => $assessment->id, 'student_id' => $unchecked->id, 'is_active' => 0,
        ]);

        $this->actingAs($this->eduA)
            ->postJson(route('educator.assessments.exemptions.toggle', $assessment), [])
            ->assertOk()
            ->assertJsonPath('affected_student_ids', []);
    }

    // Task 24: the modal pre-checks exempted students, so unchecking one still re-submits the
    // others. That creates no new exemption, so it must not demand a reason.
    public function test_unchecking_a_student_needs_no_reason_and_leaves_the_others_untouched(): void
    {
        $subject = $this->subject($this->eduA);
        $stays = $this->student;
        $removed = $this->makeUser('student', 'student');
        foreach ([$stays, $removed] as $student) {
            Enrolled::create(['student_id' => $student->id, 'educator_id' => $this->eduA->id, 'subject_id' => $subject->id, 'is_active' => true]);
        }
        $assessment = Assessment::create($this->assessmentModelData($subject));
        StudentAssessmentExemption::create([
            'educator_id' => $this->eduA->id, 'student_id' => $stays->id,
            'assessment_id' => $assessment->id, 'reason' => 'Medical absence', 'is_active' => true,
        ]);
        StudentAssessmentExemption::create([
            'educator_id' => $this->eduA->id, 'student_id' => $removed->id,
            'assessment_id' => $assessment->id, 'reason' => 'Family emergency', 'is_active' => true,
        ]);

        // Final-state save: $removed unchecked, $stays still checked, reason box left empty.
        $this->actingAs($this->eduA)
            ->postJson(route('educator.assessments.exemptions.toggle', $assessment), [
                'student_ids' => [$stays->id], 'reason' => '',
            ])
            ->assertOk()
            ->assertJsonPath('affected_student_ids', [])
            ->assertJsonPath('active_student_ids', [$stays->id]);

        $this->assertDatabaseHas('tbl_student_assessment_exemptions', [
            'assessment_id' => $assessment->id, 'student_id' => $removed->id, 'is_active' => 0,
            'reason' => 'Family emergency',
        ]);
        // The still-checked student keeps their original reason — not overwritten by the empty box.
        $this->assertDatabaseHas('tbl_student_assessment_exemptions', [
            'assessment_id' => $assessment->id, 'student_id' => $stays->id, 'is_active' => 1,
            'reason' => 'Medical absence',
        ]);
        // Nobody was newly exempted, so nobody gets notified or messaged.
        $this->assertDatabaseCount('tbl_notifications', 0);
        $this->assertDatabaseCount('tbl_conversation_messages', 0);
    }

    // Task 24: re-saving an unchanged set must not re-notify students who were already exempt.
    public function test_resaving_an_unchanged_exemption_set_does_not_duplicate_notifications(): void
    {
        $subject = $this->subject($this->eduA);
        Enrolled::create(['student_id' => $this->student->id, 'educator_id' => $this->eduA->id, 'subject_id' => $subject->id, 'is_active' => true]);
        $assessment = Assessment::create($this->assessmentModelData($subject));

        $this->actingAs($this->eduA)
            ->postJson(route('educator.assessments.exemptions.toggle', $assessment), [
                'student_ids' => [$this->student->id], 'reason' => 'Medical absence',
            ])->assertOk();

        $this->assertDatabaseCount('tbl_notifications', 1);
        $this->assertDatabaseCount('tbl_conversation_messages', 1);

        // Same save again — the student is already exempt, so nothing new happens.
        $this->actingAs($this->eduA)
            ->postJson(route('educator.assessments.exemptions.toggle', $assessment), [
                'student_ids' => [$this->student->id], 'reason' => 'Medical absence',
            ])
            ->assertOk()
            ->assertJsonPath('affected_student_ids', []);

        $this->assertDatabaseCount('tbl_notifications', 1);
        $this->assertDatabaseCount('tbl_conversation_messages', 1);
    }

    // Task 24 constraint: the reason stays required for a genuinely new exemption.
    public function test_reason_is_still_required_when_a_new_student_is_checked(): void
    {
        $subject = $this->subject($this->eduA);
        $alreadyExempt = $this->student;
        $newlyChecked = $this->makeUser('student', 'student');
        foreach ([$alreadyExempt, $newlyChecked] as $student) {
            Enrolled::create(['student_id' => $student->id, 'educator_id' => $this->eduA->id, 'subject_id' => $subject->id, 'is_active' => true]);
        }
        $assessment = Assessment::create($this->assessmentModelData($subject));
        StudentAssessmentExemption::create([
            'educator_id' => $this->eduA->id, 'student_id' => $alreadyExempt->id,
            'assessment_id' => $assessment->id, 'reason' => 'Medical absence', 'is_active' => true,
        ]);

        $this->actingAs($this->eduA)
            ->postJson(route('educator.assessments.exemptions.toggle', $assessment), [
                'student_ids' => [$alreadyExempt->id, $newlyChecked->id], 'reason' => '',
            ])
            ->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonValidationErrors('reason');

        $this->assertDatabaseMissing('tbl_student_assessment_exemptions', [
            'assessment_id' => $assessment->id, 'student_id' => $newlyChecked->id,
        ]);
    }

    public function test_access_modal_uses_save_changes_and_access_save_applies_unchecked_state(): void
    {
        $subject = $this->subject($this->eduA);
        $checked = $this->student;
        $unchecked = $this->makeUser('student', 'student');
        foreach ([$checked, $unchecked] as $student) {
            Enrolled::create(['student_id' => $student->id, 'educator_id' => $this->eduA->id, 'subject_id' => $subject->id, 'is_active' => true]);
        }
        $assessment = Assessment::create($this->assessmentModelData($subject));
        StudentAssessmentAccess::create([
            'educator_id' => $this->eduA->id, 'student_id' => $unchecked->id,
            'assessment_id' => $assessment->id, 'is_active' => true,
        ]);

        $this->actingAs($this->eduA)
            ->get(route('educator.assessments.access', ['assessment' => $assessment, 'modal' => 1]))
            ->assertOk()
            ->assertSee('Save Changes')
            ->assertDontSee('Grant Selected')
            ->assertDontSee('Revoke Selected');

        $this->actingAs($this->eduA)
            ->postJson(route('educator.assessments.access.toggle', $assessment), ['student_ids' => [$checked->id]])
            ->assertOk()
            ->assertJsonPath('affected_student_ids', [$checked->id]);

        $this->assertDatabaseHas('tbl_student_assessment_access', [
            'assessment_id' => $assessment->id, 'student_id' => $checked->id, 'is_active' => 1,
        ]);
        $this->assertDatabaseHas('tbl_student_assessment_access', [
            'assessment_id' => $assessment->id, 'student_id' => $unchecked->id, 'is_active' => 0,
        ]);
    }

    public function test_quiz_bank_page_exposes_section_subject_code_and_batch_filters(): void
    {
        $subject = $this->subject($this->eduA);
        $assessment = Assessment::create($this->assessmentModelData($subject));

        $this->actingAs($this->eduA)
            ->get(route('educator.quizzes.index'))
            ->assertOk()
            ->assertSee('data-filter="section"', false)
            ->assertSee('data-filter="subject"', false)
            ->assertSee('data-filter="assessment"', false)
            ->assertSee('data-filter="batch"', false)
            ->assertDontSee('data-filter="type"', false);

        $this->assertTrue($assessment->exists);
    }

    public function test_quiz_bank_filters_narrow_options_by_section_subject_and_assessment(): void
    {
        $sectionA = $this->section($this->eduA, 'Section A');
        $sectionB = $this->section($this->eduA, 'Section B');

        $subjectA = Subject::create([
            'educator_id' => $this->eduA->id, 'sections_id' => $sectionA->id,
            'subject_code' => 'A101', 'subject_name' => 'Alpha', 'is_active' => true,
        ]);
        $subjectB = Subject::create([
            'educator_id' => $this->eduA->id, 'sections_id' => $sectionB->id,
            'subject_code' => 'B202', 'subject_name' => 'Beta', 'is_active' => true,
        ]);

        $assessmentA = Assessment::create(array_merge($this->assessmentModelData($subjectA), ['assessment_code' => 'A-Quiz']));
        $assessmentB = Assessment::create(array_merge($this->assessmentModelData($subjectB), ['assessment_code' => 'B-Quiz']));

        Quiz::create([
            'subject_id' => $subjectA->id, 'educator_id' => $this->eduA->id,
            'question' => 'A question', 'quiz_type' => 'identification', 'correct_answer' => 'yes',
            'batch_label' => 'Batch A',
        ])->eligibleAssessments()->sync([$assessmentA->id]);
        Quiz::create([
            'subject_id' => $subjectB->id, 'educator_id' => $this->eduA->id,
            'question' => 'B question', 'quiz_type' => 'identification', 'correct_answer' => 'yes',
            'batch_label' => 'Batch B',
        ])->eligibleAssessments()->sync([$assessmentB->id]);

        $sectionHtml = $this->actingAs($this->eduA)
            ->get(route('educator.quizzes.index', ['section' => $sectionA->id]))
            ->assertOk()
            ->getContent();
        $dom = new \DOMDocument;
        @$dom->loadHTML($sectionHtml);
        $xpath = new \DOMXPath($dom);
        $subjectSelect = $xpath->query("//select[@data-filter='subject']")->item(0);
        $assessmentSelect = $xpath->query("//select[@data-filter='assessment']")->item(0);
        $this->assertNotNull($subjectSelect);
        $this->assertNotNull($assessmentSelect);
        $this->assertStringContainsString('A101', $subjectSelect->textContent);
        $this->assertStringNotContainsString('B202', $subjectSelect->textContent);
        $this->assertStringContainsString('A-Quiz', $assessmentSelect->textContent);
        $this->assertStringNotContainsString('B-Quiz', $assessmentSelect->textContent);

        $subjectHtml = $this->actingAs($this->eduA)
            ->get(route('educator.quizzes.index', ['subject' => $subjectA->id]))
            ->assertOk()
            ->getContent();
        @$dom->loadHTML($subjectHtml);
        $xpath = new \DOMXPath($dom);
        $assessmentSelect = $xpath->query("//select[@data-filter='assessment']")->item(0);
        $this->assertNotNull($assessmentSelect);
        $this->assertStringContainsString('A-Quiz', $assessmentSelect->textContent);
        $this->assertStringNotContainsString('B-Quiz', $assessmentSelect->textContent);

        $assessmentHtml = $this->actingAs($this->eduA)
            ->get(route('educator.quizzes.index', ['assessment' => 'A-Quiz']))
            ->assertOk()
            ->getContent();
        @$dom->loadHTML($assessmentHtml);
        $xpath = new \DOMXPath($dom);
        $batchSelect = $xpath->query("//select[@data-filter='batch']")->item(0);
        $this->assertNotNull($batchSelect);
        $this->assertStringContainsString('Batch A', $batchSelect->textContent);
        $this->assertStringNotContainsString('Batch B', $batchSelect->textContent);
    }

    public function test_quiz_bank_filter_chain_rejects_mismatched_parent_filters(): void
    {
        $sectionA = $this->section($this->eduA, 'Chain A');
        $sectionB = $this->section($this->eduA, 'Chain B');
        $subjectA = Subject::create([
            'educator_id' => $this->eduA->id, 'sections_id' => $sectionA->id,
            'subject_code' => 'CHAIN-A', 'subject_name' => 'Chain Alpha', 'is_active' => true,
        ]);
        $assessment = Assessment::create(array_merge($this->assessmentModelData($subjectA), ['assessment_code' => 'CHAIN-Q']));
        $quiz = Quiz::create([
            'subject_id' => $subjectA->id, 'educator_id' => $this->eduA->id,
            'question' => 'Chain question', 'quiz_type' => 'identification', 'correct_answer' => 'yes',
            'batch_label' => 'Chain batch',
        ]);
        $quiz->eligibleAssessments()->sync([$assessment->id]);

        $this->actingAs($this->eduA)
            ->get(route('educator.quizzes.index', ['section' => $sectionB->id, 'subject' => $subjectA->id]))
            ->assertOk()
            ->assertDontSee('Chain question');
    }

    public function test_quiz_bank_bulk_delete_deletes_only_selected_owned_questions(): void
    {
        $subjectA = $this->subject($this->eduA);
        $subjectB = $this->subject($this->eduB);
        $owned = Quiz::create([
            'subject_id' => $subjectA->id, 'educator_id' => $this->eduA->id,
            'question' => 'Delete me', 'quiz_type' => 'identification', 'correct_answer' => 'yes',
        ]);
        $kept = Quiz::create([
            'subject_id' => $subjectA->id, 'educator_id' => $this->eduA->id,
            'question' => 'Keep me', 'quiz_type' => 'identification', 'correct_answer' => 'yes',
        ]);
        $foreign = Quiz::create([
            'subject_id' => $subjectB->id, 'educator_id' => $this->eduB->id,
            'question' => 'Foreign question', 'quiz_type' => 'identification', 'correct_answer' => 'yes',
        ]);

        $this->actingAs($this->eduA)
            ->delete(route('educator.quizzes.bulk'), ['quiz_ids' => [$owned->id, $foreign->id]])
            ->assertRedirect(route('educator.quizzes.index'));

        // Task 13: questions are soft-deleted (scores that reference them must stay reviewable),
        // so the row is retained with deleted_at set and gone from normal bank queries.
        $this->assertSoftDeleted('tbl_quizzes', ['id' => $owned->id]);
        $this->assertNull(Quiz::find($owned->id));
        $this->assertDatabaseHas('tbl_quizzes', ['id' => $kept->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('tbl_quizzes', ['id' => $foreign->id, 'deleted_at' => null]);
    }

    public function test_quiz_bank_bulk_delete_validates_ids_and_renders_current_page_controls(): void
    {
        $subject = $this->subject($this->eduA);
        $quiz = Quiz::create([
            'subject_id' => $subject->id, 'educator_id' => $this->eduA->id,
            'question' => 'Selectable question', 'quiz_type' => 'identification', 'correct_answer' => 'yes',
        ]);

        $this->actingAs($this->eduA)
            ->delete(route('educator.quizzes.bulk'), ['quiz_ids' => ['not-an-id']])
            ->assertSessionHasErrors('quiz_ids.0');
        $this->assertDatabaseHas('tbl_quizzes', ['id' => $quiz->id]);

        $this->actingAs($this->eduA)
            ->get(route('educator.quizzes.index'))
            ->assertOk()
            ->assertSee('data-quiz-select-all', false)
            ->assertSee('data-quiz-select', false)
            ->assertSee(route('educator.quizzes.bulk'), false)
            ->assertSee('Bulk delete', false)
            ->assertSee('data-kt-modal-toggle="#kt_quiz_archive"', false)
            ->assertSee('name="batch_labels[]"', false)
            ->assertSee('Archive selected batches', false);
    }

    public function test_pool_page_shows_questions_from_other_subjects(): void
    {
        $subjectA = $this->subject($this->eduA);
        $subjectB = $this->subject($this->eduA);
        $assessment = Assessment::create($this->assessmentModelData($subjectA));

        $crossSubjectQuiz = Quiz::create([
            'subject_id' => $subjectB->id, 'educator_id' => $this->eduA->id,
            'question' => 'Cross subject pool question', 'quiz_type' => 'identification',
            'correct_answer' => 'yes',
        ]);

        $html = $this->actingAs($this->eduA)
            ->get(route('educator.assessments.pool.edit', $assessment))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Cross subject pool question', $html);
        $this->assertStringContainsString($subjectB->subject_code, $html);
        $this->assertStringContainsString('Question Pool', $html);
        $this->assertStringContainsString('Not used elsewhere', $html);
        $this->assertTrue($crossSubjectQuiz->exists);
    }

    public function test_pool_page_shows_all_bank_questions_without_dropping_selected_ids(): void
    {
        $subject = $this->subject($this->eduA);
        $assessment = Assessment::create($this->assessmentModelData($subject));
        Quiz::create([
            'subject_id' => $subject->id, 'educator_id' => $this->eduA->id,
            'question' => 'First pool question', 'quiz_type' => 'identification', 'correct_answer' => 'yes',
        ]);
        for ($i = 2; $i <= 11; $i++) {
            $second = Quiz::create([
                'subject_id' => $subject->id, 'educator_id' => $this->eduA->id,
                'question' => "Pool question {$i}", 'quiz_type' => 'identification', 'correct_answer' => 'yes',
            ]);
        }
        $response = $this->actingAs($this->eduA)->get(route('educator.assessments.pool.edit', $assessment, false).'?per_page=10&selected%5B%5D='.$second->id);

        $response->assertOk()
            ->assertSee('name="eligible_quiz_ids[]" value="'.$second->id.'"', false)
            ->assertSee('style="height: 60vh; max-height: 60vh; overflow-y: auto;"', false)
            ->assertSee('data-pool-filter-subject', false)
            ->assertSee('data-pool-filter-section', false)
            ->assertSee('data-pool-subject-section', false)
            ->assertDontSee('data-pool-pagination', false);
        $this->assertCount(11, $response->viewData('bankQuestions'));
    }

    public function test_pool_page_batch_filter_includes_batches_beyond_the_first_page(): void
    {
        $subject = $this->subject($this->eduA);
        $assessment = Assessment::create($this->assessmentModelData($subject));
        for ($i = 1; $i <= 10; $i++) {
            Quiz::create([
                'subject_id' => $subject->id, 'educator_id' => $this->eduA->id,
                'question' => "Pool question {$i}", 'quiz_type' => 'identification', 'correct_answer' => 'yes',
                'batch_label' => 'Page 1 batch',
            ]);
        }
        Quiz::create([
            'subject_id' => $subject->id, 'educator_id' => $this->eduA->id,
            'question' => 'Pool question 11', 'quiz_type' => 'identification', 'correct_answer' => 'yes',
            'batch_label' => 'Page 2 batch',
        ]);

        $response = $this->actingAs($this->eduA)->get(route('educator.assessments.pool.edit', $assessment));

        $response->assertOk()->assertSee('Page 2 batch');
    }

    public function test_question_creation_and_navbar_do_not_render_disabled_controls(): void
    {
        $this->actingAs($this->eduA)->get(route('educator.quizzes.create'))
            ->assertOk()
            ->assertDontSee('Also Add To These Assessments')
            ->assertDontSee('name="assessment_ids[]"', false);

        $html = $this->actingAs($this->eduA)->get(route('educator.dashboard'))->assertOk()->getContent();
        $dom = new \DOMDocument;
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);
        $this->assertSame(0, $xpath->query('//button[@data-kt-modal-toggle="#search_modal"]')->length);
    }

    public function test_editing_assessment_rejects_multiple_subjects(): void
    {
        $subjectA = $this->subject($this->eduA);
        $subjectB = $this->subject($this->eduA);
        $assessment = Assessment::create($this->assessmentModelData($subjectA));

        $payload = $this->assessmentPayload($subjectA, Section::find($subjectA->sections_id), false);
        $payload['subject_ids'] = [$subjectA->id, $subjectB->id];

        $this->actingAs($this->eduA)
            ->put(route('educator.assessments.update', $assessment), $payload)
            ->assertSessionHasErrors('subject_ids');

        $this->assertSame(1, Assessment::count());
        $this->assertSame($subjectA->id, $assessment->fresh()->subject_id);
    }

    public function test_editing_assessment_can_replace_subject_with_one_section_only(): void
    {
        $subjectA = $this->subject($this->eduA);
        $subjectB = $this->subject($this->eduA);
        $assessment = Assessment::create($this->assessmentModelData($subjectA));

        $payload = $this->assessmentPayload($subjectB, Section::find($subjectB->sections_id), false);
        $payload['subject_ids'] = [$subjectB->id];

        $this->actingAs($this->eduA)->put(route('educator.assessments.update', $assessment), $payload)->assertRedirect();

        $assessment->refresh();
        $this->assertSame(1, Assessment::count());
        $this->assertSame($subjectB->id, $assessment->subject_id);
        $this->assertSame($subjectB->sections_id, $assessment->section_id);
    }

    // ---- G6 quizzes: correct_answer never serialized ----

    public function test_quiz_correct_answer_is_hidden_in_serialization(): void
    {
        $subject = $this->subject($this->eduA);
        $quiz = Quiz::create([
            'subject_id' => $subject->id, 'educator_id' => $this->eduA->id,
            'question' => '2+2', 'quiz_type' => 'multiple_choice',
            'choices' => ['A' => '3', 'B' => '4'], 'correct_answer' => 'B',
        ]);

        $this->assertArrayNotHasKey('correct_answer', $quiz->toArray());
        // but readable server-side
        $this->assertSame('B', $quiz->correct_answer);
    }

    public function test_store_mc_quiz_uses_radio_key_as_correct_answer(): void
    {
        $subject = $this->subject($this->eduA);

        $this->actingAs($this->eduA)->post(route('educator.quizzes.store'), [
            'subject_id' => $subject->id, 'question' => '2+2?', 'quiz_type' => 'multiple_choice',
            'choices' => ['A' => '3', 'B' => '4', 'C' => '', 'D' => ''], 'correct_answer' => 'B',
        ])->assertRedirect();

        $quiz = Quiz::firstOrFail();
        $this->assertSame('B', $quiz->correct_answer);
        $this->assertSame('3', $quiz->choices['A']);
        $this->assertSame('4', $quiz->choices['B']);
    }

    public function test_store_identification_quiz_accepts_multiple_answers(): void
    {
        $subject = $this->subject($this->eduA);

        // one answer → stored plain
        $this->actingAs($this->eduA)->post(route('educator.quizzes.store'), [
            'subject_id' => $subject->id, 'question' => 'Capital of PH?', 'quiz_type' => 'identification',
            'answers' => ['Manila'],
        ])->assertRedirect();
        $this->assertSame('Manila', Quiz::latest('id')->first()->correct_answer);

        // multiple answers → stored as JSON array
        $this->actingAs($this->eduA)->post(route('educator.quizzes.store'), [
            'subject_id' => $subject->id, 'question' => 'A primary color?', 'quiz_type' => 'identification',
            'answers' => ['red', 'blue', ''],
        ])->assertRedirect();
        $this->assertSame(['red', 'blue'], json_decode(Quiz::latest('id')->first()->correct_answer, true));
    }

    public function test_quiz_create_route_is_modal_only_and_returns_a_fragment(): void
    {
        $this->subject($this->eduA);

        $this->actingAs($this->eduA)
            ->get('/educator/quizzes/create')
            ->assertNotFound();

        $this->actingAs($this->eduA)
            ->get(route('educator.quizzes.create', ['modal' => 1]))
            ->assertOk()
            ->assertSee('Choose Subject', false)
            ->assertSee('Create', false);
    }

    // Task 51: a bank question is reusable — attaching it to multiple assessments' pools no
    // longer duplicates the row, it links the same quiz id into each assessment's eligible set.
    public function test_bank_question_can_be_attached_to_multiple_assessment_pools(): void
    {
        $subject = $this->subject($this->eduA);
        $a1 = Assessment::create($this->assessmentModelData($subject));
        $a2 = Assessment::create(['assessment_code' => 'Q2'] + $this->assessmentModelData($subject));

        $this->actingAs($this->eduA)->post(route('educator.quizzes.store'), [
            'subject_id' => $subject->id, 'question' => 'Shared?', 'quiz_type' => 'identification',
            'answers' => ['yes'],
        ])->assertRedirect();

        $quiz = Quiz::where('question', 'Shared?')->firstOrFail();
        $this->assertSame(1, Quiz::where('question', 'Shared?')->count()); // one row, not duplicated

        foreach ([$a1, $a2] as $assessment) {
            $this->actingAs($this->eduA)->put(route('educator.assessments.pool.update', $assessment), [
                'eligible_quiz_ids' => [$quiz->id], 'pool_size' => 1,
            ])->assertRedirect();
        }

        $this->assertDatabaseHas('tbl_assessment_question_pool', ['assessment_id' => $a1->id, 'quiz_id' => $quiz->id]);
        $this->assertDatabaseHas('tbl_assessment_question_pool', ['assessment_id' => $a2->id, 'quiz_id' => $quiz->id]);
    }

    public function test_store_quiz_can_attach_to_assessments_from_another_subject(): void
    {
        $subject = $this->subject($this->eduA);
        $otherSubject = $this->subject($this->eduA);
        $otherAssessment = Assessment::create($this->assessmentModelData($otherSubject));

        $this->actingAs($this->eduA)->post(route('educator.quizzes.store'), [
            'subject_id' => $subject->id, 'question' => 'Cross subject?', 'quiz_type' => 'identification',
            'answers' => ['yes'], 'assessment_ids' => [$otherAssessment->id],
        ])->assertRedirect();

        $quiz = Quiz::where('question', 'Cross subject?')->firstOrFail();
        $this->assertDatabaseHas('tbl_assessment_question_pool', [
            'assessment_id' => $otherAssessment->id,
            'quiz_id' => $quiz->id,
        ]);
    }

    // Task 51 follow-up: creating a question can immediately attach it to one or more
    // assessments' pools, skipping the separate trip to each assessment's Question Pool screen.
    public function test_store_quiz_can_attach_to_assessments_pool_immediately(): void
    {
        $subject = $this->subject($this->eduA);
        $a1 = Assessment::create($this->assessmentModelData($subject));
        $a2 = Assessment::create(['assessment_code' => 'Q2'] + $this->assessmentModelData($subject));

        $this->actingAs($this->eduA)->post(route('educator.quizzes.store'), [
            'subject_id' => $subject->id, 'question' => 'Tagged at creation?', 'quiz_type' => 'identification',
            'answers' => ['yes'], 'assessment_ids' => [$a1->id, $a2->id],
        ])->assertRedirect();

        $quiz = Quiz::where('question', 'Tagged at creation?')->firstOrFail();
        $this->assertDatabaseHas('tbl_assessment_question_pool', ['assessment_id' => $a1->id, 'quiz_id' => $quiz->id]);
        $this->assertDatabaseHas('tbl_assessment_question_pool', ['assessment_id' => $a2->id, 'quiz_id' => $quiz->id]);
    }

    public function test_bulk_upload_can_attach_questions_to_assessments_from_another_subject(): void
    {
        $subject = $this->subject($this->eduA);
        $otherSubject = $this->subject($this->eduA);
        $wrongAssessment = Assessment::create($this->assessmentModelData($otherSubject));

        $file = $this->quizUploadFile('cross-upload.xlsx', [
            ['Cross upload?', 'identification', '', '', '', '', 'yes'],
        ]);

        $this->actingAs($this->eduA)->post(route('educator.quizzes.upload'), [
            'subject_id' => $subject->id,
            'assessment_ids' => [$wrongAssessment->id],
            'files' => [$file],
        ])->assertRedirect();

        $quiz = Quiz::where('question', 'Cross upload?')->firstOrFail();
        $this->assertDatabaseHas('tbl_assessment_question_pool', [
            'assessment_id' => $wrongAssessment->id,
            'quiz_id' => $quiz->id,
        ]);
    }

    // Task 51 follow-up: every question gets an auto-labeled creation batch (manual add vs.
    // which upload file it came from), so the bank list can be filtered by "where did this come from".
    public function test_manually_created_and_bulk_uploaded_questions_get_distinct_batch_labels(): void
    {
        $subject = $this->subject($this->eduA);

        $this->actingAs($this->eduA)->post(route('educator.quizzes.store'), [
            'subject_id' => $subject->id, 'question' => 'Manual batch?', 'quiz_type' => 'identification',
            'answers' => ['yes'],
        ])->assertRedirect();

        $manual = Quiz::where('question', 'Manual batch?')->firstOrFail();
        $this->assertStringStartsWith('Manual · ', $manual->batch_label);

        $file = $this->quizUploadFile('batch-quiz.xlsx', [
            ['2+2?', 'multiple_choice', '3', '4', '5', '6', 'B'],
        ]);

        $this->actingAs($this->eduA)->post(route('educator.quizzes.upload'), [
            'subject_id' => $subject->id,
            'files' => [$file],
        ])->assertRedirect();

        $uploaded = Quiz::where('question', '2+2?')->firstOrFail();
        $this->assertStringStartsWith('Upload: batch-quiz.xlsx · ', $uploaded->batch_label);
        $this->assertNotSame($manual->batch_label, $uploaded->batch_label);
    }

    public function test_pool_size_cannot_exceed_eligible_question_count(): void
    {
        $subject = $this->subject($this->eduA);
        $assessment = Assessment::create($this->assessmentModelData($subject));
        $quiz = Quiz::create([
            'subject_id' => $subject->id, 'educator_id' => $this->eduA->id,
            'question' => 'Q', 'quiz_type' => 'identification', 'correct_answer' => 'a',
        ]);

        $this->actingAs($this->eduA)->put(route('educator.assessments.pool.update', $assessment), [
            'eligible_quiz_ids' => [$quiz->id], 'pool_size' => 5,
        ])->assertSessionHasErrors('pool_size');

        $this->assertSame(0, $assessment->fresh()->pool_size);
    }

    public function test_bulk_upload_imports_each_file_into_the_target_subject(): void
    {
        $subject = $this->subject($this->eduA);

        $file = $this->quizUploadFile('quiz.xlsx', [
            ['2+2?', 'multiple_choice', '3', '4', '5', '6', 'B'],
            ['Capital of PH?', 'identification', '', '', '', '', 'Manila'],
        ]);

        $this->actingAs($this->eduA)->post(route('educator.quizzes.upload'), [
            'subject_id' => $subject->id,
            'files' => [$file],
        ])->assertRedirect();

        $this->assertSame(2, Quiz::where('subject_id', $subject->id)->count());
    }

    public function test_bulk_upload_is_all_or_nothing_when_any_row_is_invalid(): void
    {
        $subject = $this->subject($this->eduA);

        // Row 2 is valid, row 3 is invalid (bad quiz_type) → whole upload must be rejected.
        $csv = "question,quiz_type,choice_a,choice_b,choice_c,choice_d,correct_answer\n"
            ."2+2?,multiple_choice,3,4,5,6,B\n"
            ."Broken?,nonsense,,,,,X\n";
        $file = File::createWithContent('quiz.csv', $csv);

        $this->actingAs($this->eduA)->post(route('educator.quizzes.upload'), [
            'subject_id' => $subject->id,
            'files' => [$file],
        ])->assertSessionHasErrors('files');

        // Nothing saved — not even the valid row.
        $this->assertSame(0, Quiz::where('subject_id', $subject->id)->count());
    }

    public function test_bulk_upload_rejects_wrong_file_type_with_format_message(): void
    {
        $subject = $this->subject($this->eduA);

        $bad = File::createWithContent('notes.pdf', '%PDF-1.4 fake');

        $this->actingAs($this->eduA)->post(route('educator.quizzes.upload'), [
            'subject_id' => $subject->id,
            'files' => [$bad],
        ])->assertSessionHasErrors('files.0');

        $this->assertSame(0, Quiz::where('subject_id', $subject->id)->count());
    }

    public function test_bulk_upload_rejects_right_filetype_with_wrong_columns(): void
    {
        $subject = $this->subject($this->eduA);

        // A real XLSX, but not the template — missing required columns.
        $file = $this->quizUploadFile('contacts.xlsx', [
            ['Juan', 'juan@example.com'],
        ], ['name', 'email']);

        $response = $this->actingAs($this->eduA)->post(route('educator.quizzes.upload'), [
            'subject_id' => $subject->id,
            'files' => [$file],
        ])->assertSessionHasErrors('files');

        $this->assertStringContainsString('missing required column', collect(
            session('errors')->get('files'))->implode(' '));
        $this->assertSame(0, Quiz::where('subject_id', $subject->id)->count());
    }

    // ---- G7 scores: task 27 — index is a CLASS list, student scores live in the matrix modal ----

    public function test_scores_index_lists_classes_with_filters_and_stays_scoped(): void
    {
        $subject = $this->subject($this->eduA);
        $section = Section::find($subject->sections_id);
        Assessment::create($this->assessmentModelData($subject));

        // A second class of eduA's, excluded by the subject filter below.
        $otherSubject = $this->subject($this->eduA);
        Assessment::create(array_merge($this->assessmentModelData($otherSubject), ['assessment_code' => 'OTHERQ']));

        // eduB's class must never leak into eduA's view (visibleTo).
        $subjectB = $this->subject($this->eduB);
        Assessment::create([
            'educator_id' => $this->eduB->id, 'subject_id' => $subjectB->id, 'section_id' => $subjectB->sections_id,
            'assessment_code' => 'ZZTOP', 'time_limit' => '30', 'term' => $this->term->id,
            'start_date' => '2026-07-01', 'end_date' => '2026-07-02', 'start_time' => '08:00', 'end_time' => '09:00',
        ]);

        $res = $this->actingAs($this->eduA)->get(route('educator.scores.index', [
            'subject' => $subject->id,
            'per_page' => 10,
        ]))->assertOk();

        // Subject / Section / Term columns + the row's matrix action.
        $res->assertSee($subject->subject_code)
            ->assertSee($section->section_name)
            ->assertSee($this->term->term_name)
            ->assertSee('1 assessment')
            ->assertSee(route('educator.scores.matrix', [
                'subject' => $subject->id, 'section' => $section->id, 'term' => $this->term->id,
            ]));
        $res->assertSee('data-filter="subject"', false)
            ->assertSee('data-filter="section"', false)
            ->assertSee('data-filter="term"', false)
            ->assertSee('Search subjects, sections, terms');
        // Per-student columns moved into the modal.
        $res->assertDontSee('Best score')->assertDontSee('Latest submission');
        // Assert on the row's matrix link, not the subject code — every one of this educator's
        // subject codes also appears in the export modal's inlined options JSON.
        $res->assertDontSee(route('educator.scores.matrix', [   // server-side filter, not client hiding
            'subject' => $otherSubject->id, 'section' => $otherSubject->sections_id, 'term' => $this->term->id,
        ]));
        $res->assertDontSee('ZZTOP');                          // eduB's class never shown
    }

    public function test_scores_matrix_builds_one_column_per_assessment_and_shows_attempts(): void
    {
        $subject = $this->subject($this->eduA);
        $section = Section::find($subject->sections_id);
        $this->student->update(['given_name' => 'Ann', 'surname' => 'Zamora']);
        $first = Assessment::create($this->assessmentModelData($subject));
        $second = Assessment::create(array_merge($this->assessmentModelData($subject), ['assessment_code' => 'QUIZTWO']));

        Score::create([
            'student_id' => $this->student->id, 'educator_id' => $this->eduA->id, 'assessment_id' => $first->id,
            'subject_id' => $subject->id, 'section_id' => $section->id, 'score' => 8, 'total_questions' => 10,
            'student_answer' => [], 'status' => 'passed', 'is_passed' => true, 'submitted_at' => now(),
        ]);
        // An offline backfill can be inserted later while representing an earlier attempt.
        Score::create([
            'student_id' => $this->student->id, 'educator_id' => $this->eduA->id, 'assessment_id' => $first->id,
            'subject_id' => $subject->id, 'section_id' => $section->id, 'score' => 6, 'total_questions' => 10,
            'student_answer' => [], 'status' => 'failed', 'is_passed' => false, 'submitted_at' => now()->subDay(),
        ]);

        $res = $this->actingAs($this->eduA)->get(route('educator.scores.matrix', [
            'subject' => $subject->id, 'section' => $section->id, 'term' => $this->term->id,
        ]))->assertOk();

        // Columns come from the assessment list — both codes are headers, nothing hard-coded.
        $res->assertSee('Q1')->assertSee('QUIZTWO')
            ->assertSee('Zamora, Ann')                              // one Student column…
            ->assertSee($this->student->user_id)                    // …with the student number beneath
            ->assertDontSee('Given Name')
            ->assertSee('8/10')          // best of the two attempts, not the latest
            ->assertSee('2 attempts')
            ->assertSee(route('educator.scores.student', [
                'subject' => $subject->id, 'section' => $section->id, 'term' => $this->term->id, 'student' => $this->student->id,
            ]));

        // The un-taken second assessment renders an empty cell for this student.
        $this->assertStringContainsString('—', $res->getContent());
    }

    public function test_scores_matrix_resolves_best_attempt_by_percentage(): void
    {
        $subject = $this->subject($this->eduA);
        $section = Section::find($subject->sections_id);
        $assessment = Assessment::create($this->assessmentModelData($subject));
        foreach ([[8, 10], [7, 7]] as [$score, $total]) {
            Score::create([
                'student_id' => $this->student->id, 'educator_id' => $this->eduA->id, 'assessment_id' => $assessment->id,
                'subject_id' => $subject->id, 'section_id' => $section->id, 'score' => $score, 'total_questions' => $total,
                'student_answer' => [], 'status' => 'passed', 'is_passed' => true, 'submitted_at' => now(),
            ]);
        }

        $this->actingAs($this->eduA)->get(route('educator.scores.matrix', [
            'subject' => $subject->id, 'section' => $section->id, 'term' => $this->term->id,
        ]))->assertOk()->assertSee('8/10');  // higher raw score wins the tie-break, as the export does
    }

    public function test_scores_matrix_lists_enrolled_students_by_surname_including_non_submitters(): void
    {
        $subject = $this->subject($this->eduA);
        $section = Section::find($subject->sections_id);
        Assessment::create($this->assessmentModelData($subject));

        $this->student->update(['given_name' => 'Ann', 'surname' => 'Zamora']);
        $early = $this->makeUser('student', 'student');
        $early->update(['given_name' => 'Ben', 'surname' => 'Alpha']);
        foreach ([$this->student, $early] as $enrollee) {
            Enrolled::create([
                'student_id' => $enrollee->id, 'educator_id' => $this->eduA->id,
                'subject_id' => $subject->id, 'is_active' => true,
            ]);
        }

        $html = $this->actingAs($this->eduA)->get(route('educator.scores.matrix', [
            'subject' => $subject->id, 'section' => $section->id, 'term' => $this->term->id,
        ]))->assertOk()->getContent();

        // Enrolled non-submitters still get a row, ordered by surname.
        $this->assertLessThan(strpos($html, 'Zamora'), strpos($html, 'Alpha'));
    }

    public function test_scores_matrix_and_student_detail_reject_another_educators_class(): void
    {
        $subjectB = $this->subject($this->eduB);
        $params = [
            'subject' => $subjectB->id, 'section' => $subjectB->sections_id, 'term' => $this->term->id,
        ];

        $this->actingAs($this->eduA)->get(route('educator.scores.matrix', $params))->assertNotFound();
        $this->actingAs($this->eduA)
            ->get(route('educator.scores.student', $params + ['student' => $this->student->id]))
            ->assertNotFound();
    }

    public function test_scores_student_detail_shows_answers_and_retake_grant(): void
    {
        $subject = $this->subject($this->eduA);
        $section = Section::find($subject->sections_id);
        $assessment = Assessment::create($this->assessmentModelData($subject));
        $quiz = Quiz::create([
            'educator_id' => $this->eduA->id, 'subject_id' => $subject->id, 'question' => 'Capital of France?',
            'quiz_type' => 'identification', 'correct_answer' => 'Paris', 'is_active' => true,
        ]);
        $score = Score::create([
            'student_id' => $this->student->id, 'educator_id' => $this->eduA->id, 'assessment_id' => $assessment->id,
            'subject_id' => $subject->id, 'section_id' => $section->id, 'score' => 0, 'total_questions' => 1,
            'drawn_quiz_ids' => [$quiz->id], 'student_answer' => [$quiz->id => 'Lyon'],
            'status' => 'failed', 'is_passed' => false, 'submitted_at' => now(),
        ]);

        $this->actingAs($this->eduA)->get(route('educator.scores.student', [
            'subject' => $subject->id, 'section' => $section->id, 'term' => $this->term->id, 'student' => $this->student->id,
        ]))->assertOk()
            ->assertSee('Capital of France?')
            ->assertSee('Lyon')                                              // submitted answer
            ->assertSee('Paris')                                             // correct answer (educator view)
            ->assertSee('Grant retake')                                      // grant preserved
            ->assertSee(route('educator.scores.destroy', $score), false)     // delete attempt preserved
            ->assertSee(route('educator.scores.matrix', [
                'subject' => $subject->id, 'section' => $section->id, 'term' => $this->term->id,
            ]));                                                             // back to the matrix
    }

    public function test_sections_index_does_not_nest_modal_or_row_forms_inside_query_controls(): void
    {
        $this->section($this->eduA, 'Alpha');

        $response = $this->actingAs($this->eduA)
            ->get(route('educator.sections.index'))
            ->assertOk();

        $dom = new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        $xpath = new \DOMXPath($dom);

        $tableRoot = $xpath->query('//*[@id="sections_table_form"]')->item(0);
        $this->assertNotNull($tableRoot, 'sections table root should render');
        $this->assertSame('div', strtolower($tableRoot->nodeName), 'query controls root should not itself be a form');
        $this->assertSame(0, $xpath->query('//*[@id="form_modal"]/ancestor::form')->length, 'shared modal must stay outside any ancestor form');
    }

    public function test_subjects_index_does_not_nest_modal_inside_ancestor_forms(): void
    {
        $this->subject($this->eduA);

        $response = $this->actingAs($this->eduA)
            ->get(route('educator.subjects.index'))
            ->assertOk();

        $dom = new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        $xpath = new \DOMXPath($dom);

        $tableRoot = $xpath->query('//*[@id="subjects_table_form"]')->item(0);
        $this->assertNotNull($tableRoot);
        $this->assertSame('div', strtolower($tableRoot->nodeName));
        $this->assertSame(0, $xpath->query('//*[@id="form_modal"]/ancestor::form')->length);
    }

    // ---- helpers ----

    private function makeUser(string $type, string $roleName): User
    {
        $user = User::factory()->create(['user_type' => $type, 'email_verified_at' => now()]);
        $user->roles()->attach(Role::where('name', $roleName)->value('id'));

        return $user;
    }

    private function seedEducatorPermissions(array $strings): void
    {
        $eduRole = Role::where('name', 'educator')->first();
        foreach ($strings as $s) {
            [$resource, $action] = explode(':', $s);
            $perm = Permission::create([
                'name' => $s, 'resource' => $resource, 'action' => $action,
                'description' => $s, 'module' => $resource, 'is_active' => true, 'permission_string' => $s,
            ]);
            $eduRole->permissions()->attach($perm->id);
        }
    }

    private function section(User $edu, string $name): Section
    {
        $s = Section::create(['educator_id' => $edu->id, 'academic_term_id' => $this->term->id, 'section_name' => $name, 'is_active' => true]);
        $s->terms()->sync([$this->term->id]);

        return $s;
    }

    private function subject(User $edu): Subject
    {
        $section = $this->section($edu, 'Sec'.uniqid());

        return Subject::create(['educator_id' => $edu->id, 'sections_id' => $section->id, 'subject_code' => 'C'.rand(100, 999), 'subject_name' => 'Subj', 'is_active' => true]);
    }

    private function assessmentPayload(Subject $subject, Section $section, bool $active): array
    {
        return [
            // subject_ids[] drives create (one assessment per subject); subject_id drives edit.
            'assessment_code' => 'Q1', 'subject_ids' => [$subject->id], 'subject_id' => $subject->id, 'term' => $this->term->id,
            'time_limit' => '30', 'cheating_attempts' => 0, 'is_shuffle' => '0', 'allow_review' => '0',
            'allow_retake' => '0', 'retake_count' => 0, 'allow_hint' => '0', 'hint_count' => 0,
            'is_active' => $active ? '1' : '0',
            'start_date' => '2026-07-01', 'end_date' => '2026-07-02', 'start_time' => '08:00', 'end_time' => '09:00',
        ];
    }

    private function assessmentModelData(Subject $subject): array
    {
        return [
            'educator_id' => $this->eduA->id, 'subject_id' => $subject->id, 'section_id' => $subject->sections_id,
            'assessment_code' => 'Q1', 'time_limit' => '30', 'term' => $this->term->id,
            'start_date' => '2026-07-01', 'end_date' => '2026-07-02', 'start_time' => '08:00', 'end_time' => '09:00',
        ];
    }

    /**
     * @param  array<int, string>  $rows
     * @param  array<int, string>  $headers
     */
    private function quizUploadFile(string $name, array $rows, array $headers = ['question', 'quiz_type', 'choice_a', 'choice_b', 'choice_c', 'choice_d', 'correct_answer']): File
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Quiz Upload Template');
        $sheet->setCellValue('A1', 'Qyzen Quiz Upload Template');
        foreach (array_values($headers) as $i => $header) {
            $sheet->setCellValue(chr(65 + $i).'2', $header);
        }
        foreach ($rows as $rowIndex => $row) {
            foreach (array_values($row) as $i => $value) {
                $sheet->setCellValue(chr(65 + $i).(3 + $rowIndex), $value);
            }
        }

        $tmp = tempnam(sys_get_temp_dir(), 'quiz-upload').'.xlsx';
        (new SpreadsheetWriter($spreadsheet))->save($tmp);
        $bytes = file_get_contents($tmp);
        @unlink($tmp);

        return File::createWithContent($name, $bytes === false ? '' : $bytes);
    }
}
