<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Assessment;
use App\Models\Enrolled;
use App\Models\LearningMaterial;
use App\Models\Permission;
use App\Models\Quiz;
use App\Models\Role;
use App\Models\Score;
use App\Models\Section;
use App\Models\Subject;
use App\Models\User;
use App\Services\NotificationAuthorizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// D6: authorization test matrix — the Stage D gate. For each resource x role x action,
// allowed cases pass and forbidden cases are denied. Mirrors the RLS policies in
// docs/architecture/LIVE_SCHEMA_EXPORT.sql.
class AuthorizationMatrixTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $eduA;

    private User $eduB;

    private User $student;       // enrolled with eduA

    private User $otherStudent;  // enrolled with eduB

    private Subject $subjectA;

    private Section $sectionA;

    private Assessment $assessmentA;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'educator', 'student'] as $name) {
            Role::create(['name' => $name, 'description' => $name, 'is_active' => true]);
        }
        // permission used by section/subject educator gates
        $this->seedPermissions(['sections:view', 'subjects:view']);

        $this->admin = $this->makeUser('admin', 'admin');
        $this->eduA = $this->makeUser('educator', 'educator');
        $this->eduB = $this->makeUser('educator', 'educator');
        $this->student = $this->makeUser('student', 'student');
        $this->otherStudent = $this->makeUser('student', 'student');

        $year = AcademicYear::create(['year' => '2025 - 2026']);
        $term = AcademicTerm::create(['term_name' => 'Prelim', 'semester' => '1st Semester', 'academic_year_id' => $year->id]);
        $this->sectionA = Section::create(['educator_id' => $this->eduA->id, 'academic_term_id' => $term->id, 'section_name' => 'A1']);
        $this->subjectA = Subject::create(['educator_id' => $this->eduA->id, 'sections_id' => $this->sectionA->id, 'subject_code' => 'M1', 'subject_name' => 'Math']);
        $this->assessmentA = Assessment::create([
            'educator_id' => $this->eduA->id, 'subject_id' => $this->subjectA->id, 'section_id' => $this->sectionA->id,
            'assessment_code' => 'Q1', 'time_limit' => '30', 'term' => $term->id,
            'start_date' => '2026-07-01', 'end_date' => '2026-07-02', 'start_time' => '08:00', 'end_time' => '09:00',
        ]);

        Enrolled::create(['student_id' => $this->student->id, 'educator_id' => $this->eduA->id, 'subject_id' => $this->subjectA->id, 'is_active' => true]);
        // otherStudent enrolled elsewhere (with eduB) — should never see eduA's data
        $sb = Subject::create(['educator_id' => $this->eduB->id, 'sections_id' => $this->sectionA->id, 'subject_code' => 'M2', 'subject_name' => 'Sci']);
        Enrolled::create(['student_id' => $this->otherStudent->id, 'educator_id' => $this->eduB->id, 'subject_id' => $sb->id, 'is_active' => true]);
    }

    public function test_assessment_visibility_scope(): void
    {
        $this->assertTrue(Assessment::visibleTo($this->admin)->whereKey($this->assessmentA->id)->exists(), 'admin sees all');
        $this->assertTrue(Assessment::visibleTo($this->eduA)->whereKey($this->assessmentA->id)->exists(), 'owner educator sees own');
        $this->assertFalse(Assessment::visibleTo($this->eduB)->whereKey($this->assessmentA->id)->exists(), 'other educator blocked');
        $this->assertTrue(Assessment::visibleTo($this->student)->whereKey($this->assessmentA->id)->exists(), 'enrolled student sees');
        $this->assertFalse(Assessment::visibleTo($this->otherStudent)->whereKey($this->assessmentA->id)->exists(), 'non-enrolled student blocked');
    }

    public function test_section_and_subject_scopes(): void
    {
        $this->assertTrue(Section::visibleTo($this->eduA)->whereKey($this->sectionA->id)->exists());
        $this->assertFalse(Section::visibleTo($this->eduB)->whereKey($this->sectionA->id)->exists());
        $this->assertTrue(Section::visibleTo($this->student)->whereKey($this->sectionA->id)->exists());
        $this->assertFalse(Section::visibleTo($this->otherStudent)->whereKey($this->sectionA->id)->exists());

        $this->assertTrue(Subject::visibleTo($this->student)->whereKey($this->subjectA->id)->exists());
        $this->assertFalse(Subject::visibleTo($this->otherStudent)->whereKey($this->subjectA->id)->exists());
    }

    public function test_score_scope_student_sees_only_own(): void
    {
        $score = Score::create([
            'student_id' => $this->student->id, 'educator_id' => $this->eduA->id, 'assessment_id' => $this->assessmentA->id,
            'subject_id' => $this->subjectA->id, 'section_id' => $this->sectionA->id, 'student_answer' => [],
        ]);

        $this->assertTrue(Score::visibleTo($this->student)->whereKey($score->id)->exists());
        $this->assertTrue(Score::visibleTo($this->eduA)->whereKey($score->id)->exists(), 'owning educator sees');
        $this->assertFalse(Score::visibleTo($this->otherStudent)->whereKey($score->id)->exists());
        $this->assertFalse(Score::visibleTo($this->eduB)->whereKey($score->id)->exists());
        $this->assertTrue(Score::visibleTo($this->admin)->whereKey($score->id)->exists());
    }

    public function test_section_policy_requires_permission_for_educator(): void
    {
        $this->assertTrue($this->eduA->can('view', $this->sectionA), 'educator with sections:view + ownership');
        $this->assertFalse($this->student->can('update', $this->sectionA));
        $this->assertTrue($this->admin->can('delete', $this->sectionA));
        $this->assertFalse($this->eduB->can('update', $this->sectionA), 'non-owner educator denied');
    }

    public function test_assessment_policy(): void
    {
        $this->assertTrue($this->eduA->can('update', $this->assessmentA));
        $this->assertFalse($this->eduB->can('update', $this->assessmentA));
        $this->assertTrue($this->student->can('view', $this->assessmentA));
        $this->assertFalse($this->otherStudent->can('view', $this->assessmentA));
    }

    public function test_quiz_scope_hides_from_non_enrolled_and_correct_answer_hidden(): void
    {
        $quiz = Quiz::create([
            'subject_id' => $this->subjectA->id, 'educator_id' => $this->eduA->id,
            'question' => '2+2', 'quiz_type' => 'multiple_choice',
            'choices' => ['A' => '3', 'B' => '4'], 'correct_answer' => 'B',
        ]);

        $this->assertTrue(Quiz::visibleTo($this->eduA)->whereKey($quiz->id)->exists());
        $this->assertTrue(Quiz::visibleTo($this->student)->whereKey($quiz->id)->exists());
        $this->assertFalse(Quiz::visibleTo($this->otherStudent)->whereKey($quiz->id)->exists());
        $this->assertFalse(Quiz::visibleTo($this->admin)->whereKey($quiz->id)->exists(), 'no admin quiz visibility');

        // security invariant: correct_answer never serialized
        $this->assertArrayNotHasKey('correct_answer', $quiz->toArray());
    }

    public function test_notification_emit_rules(): void
    {
        $auth = new NotificationAuthorizer;

        // educator -> enrolled student, allowed event
        $this->assertTrue($auth->canEmit($this->eduA, 'assessment_created', $this->student->id, ['subject_id' => $this->subjectA->id]));
        // educator cannot emit quiz_submitted
        $this->assertFalse($auth->canEmit($this->eduA, 'quiz_submitted', $this->student->id, ['subject_id' => $this->subjectA->id]));
        // educator cannot emit to a non-enrolled student
        $this->assertFalse($auth->canEmit($this->eduA, 'assessment_created', $this->otherStudent->id, ['subject_id' => $this->subjectA->id]));

        // student -> assessment's educator, quiz_submitted only
        $this->assertTrue($auth->canEmit($this->student, 'quiz_submitted', $this->eduA->id, ['assessment_id' => $this->assessmentA->id]));
        $this->assertFalse($auth->canEmit($this->student, 'assessment_created', $this->eduA->id, ['assessment_id' => $this->assessmentA->id]));
        // non-enrolled student cannot emit about this assessment
        $this->assertFalse($auth->canEmit($this->otherStudent, 'quiz_submitted', $this->eduA->id, ['assessment_id' => $this->assessmentA->id]));
    }

    public function test_learning_material_scope(): void
    {
        $mat = LearningMaterial::create([
            'educator_id' => $this->eduA->id, 'subject_id' => $this->subjectA->id, 'section_id' => $this->sectionA->id,
            'storage_path' => 'x/y.pdf', 'file_name' => 'y.pdf', 'file_extension' => 'pdf', 'mime_type' => 'application/pdf', 'is_active' => true,
        ]);

        $this->assertTrue(LearningMaterial::visibleTo($this->eduA)->whereKey($mat->id)->exists());
        $this->assertTrue(LearningMaterial::visibleTo($this->student)->whereKey($mat->id)->exists());
        $this->assertFalse(LearningMaterial::visibleTo($this->otherStudent)->whereKey($mat->id)->exists());
    }

    // Task 31: deactivating a term hides everything filed under it from educators and students
    // alike. The rows are not deleted, and the owning educator gets a redirect + notice rather
    // than an error page — see test_inactive_term_redirects_educator_instead_of_erroring.
    public function test_inactive_term_hides_related_resources_from_educators_and_students(): void
    {
        $quiz = Quiz::create([
            'subject_id' => $this->subjectA->id, 'educator_id' => $this->eduA->id,
            'question' => '2+2', 'quiz_type' => 'multiple_choice',
            'choices' => ['A' => '3', 'B' => '4'], 'correct_answer' => 'B',
        ]);
        $score = Score::create([
            'student_id' => $this->student->id, 'educator_id' => $this->eduA->id, 'assessment_id' => $this->assessmentA->id,
            'subject_id' => $this->subjectA->id, 'section_id' => $this->sectionA->id, 'student_answer' => [],
        ]);
        $material = LearningMaterial::create([
            'educator_id' => $this->eduA->id, 'subject_id' => $this->subjectA->id, 'section_id' => $this->sectionA->id,
            'storage_path' => 'x/y.pdf', 'file_name' => 'y.pdf', 'file_extension' => 'pdf', 'mime_type' => 'application/pdf', 'is_active' => true,
        ]);

        $this->sectionA->academicTerm->update(['is_active' => false]);

        $this->assertFalse(Section::visibleTo($this->eduA)->whereKey($this->sectionA->id)->exists());
        $this->assertFalse(Section::visibleTo($this->student)->whereKey($this->sectionA->id)->exists());
        $this->assertFalse(Subject::visibleTo($this->eduA)->whereKey($this->subjectA->id)->exists());
        $this->assertFalse(Subject::visibleTo($this->student)->whereKey($this->subjectA->id)->exists());
        $this->assertFalse(Assessment::visibleTo($this->eduA)->whereKey($this->assessmentA->id)->exists());
        $this->assertFalse(Assessment::visibleTo($this->student)->whereKey($this->assessmentA->id)->exists());
        $this->assertFalse(Quiz::visibleTo($this->eduA)->whereKey($quiz->id)->exists());
        $this->assertFalse(Quiz::visibleTo($this->student)->whereKey($quiz->id)->exists());
        $this->assertFalse(Score::visibleTo($this->eduA)->whereKey($score->id)->exists());
        $this->assertFalse(Score::visibleTo($this->student)->whereKey($score->id)->exists());
        $this->assertFalse(LearningMaterial::visibleTo($this->eduA)->whereKey($material->id)->exists());
        $this->assertFalse(LearningMaterial::visibleTo($this->student)->whereKey($material->id)->exists());

        // The rows still exist — hidden, not deleted.
        $this->assertNotNull(Score::find($score->id));
        $this->assertNotNull(Assessment::find($this->assessmentA->id));

        $this->assertTrue(Assessment::visibleTo($this->admin)->whereKey($this->assessmentA->id)->exists());
        $this->assertTrue(Score::visibleTo($this->admin)->whereKey($score->id)->exists());
    }

    // ---- helpers ----

    private function makeUser(string $type, string $roleName): User
    {
        $user = User::factory()->create(['user_type' => $type]);
        $user->roles()->attach(Role::where('name', $roleName)->value('id'));

        return $user;
    }

    private function seedPermissions(array $strings): void
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
}
