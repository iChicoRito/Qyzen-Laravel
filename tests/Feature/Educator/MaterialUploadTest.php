<?php

namespace Tests\Feature\Educator;

use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\LearningMaterial;
use App\Models\Role;
use App\Models\Section;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// Task 29: multi-subject upload shares one storage object; orphan cleanup only removes it
// once no row references it anymore; unsupported extensions are rejected.
class MaterialUploadTest extends TestCase
{
    use RefreshDatabase;

    private User $edu;

    private AcademicTerm $term;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'educator', 'student'] as $name) {
            Role::create(['name' => $name, 'description' => $name, 'is_active' => true]);
        }

        $this->edu = User::factory()->create(['user_type' => 'educator', 'email_verified_at' => now()]);
        $this->edu->roles()->attach(Role::where('name', 'educator')->value('id'));

        $year = AcademicYear::create(['year' => '2025 - 2026']);
        $this->term = AcademicTerm::create(['term_name' => 'Prelim', 'semester' => '1st Semester', 'academic_year_id' => $year->id]);

        Storage::fake('local');
    }

    public function test_uploading_to_two_subjects_stores_the_file_once_and_creates_two_rows(): void
    {
        [$subjectA, $subjectB] = [$this->subject(), $this->subject()];

        $this->actingAs($this->edu)->post(route('educator.materials.store'), [
            'subject_ids' => [$subjectA->id, $subjectB->id],
            'files' => [UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf')],
        ])->assertRedirect(route('educator.materials.index'));

        $rows = LearningMaterial::where('educator_id', $this->edu->id)->get();
        $this->assertCount(2, $rows);
        $this->assertSame($rows[0]->storage_path, $rows[1]->storage_path);
        Storage::disk('learning-materials')->assertExists($rows[0]->storage_path);
    }

    public function test_deleting_one_of_two_referencing_rows_keeps_the_file_until_the_last_is_gone(): void
    {
        [$subjectA, $subjectB] = [$this->subject(), $this->subject()];

        $this->actingAs($this->edu)->post(route('educator.materials.store'), [
            'subject_ids' => [$subjectA->id, $subjectB->id],
            'files' => [UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf')],
        ]);
        [$first, $second] = LearningMaterial::where('educator_id', $this->edu->id)->get();
        $path = $first->storage_path;

        $this->actingAs($this->edu)->delete(route('educator.materials.destroy', $first));
        Storage::disk('learning-materials')->assertExists($path);

        $this->actingAs($this->edu)->delete(route('educator.materials.destroy', $second));
        Storage::disk('learning-materials')->assertMissing($path);
    }

    public function test_bulk_delete_removes_only_selected_rows_and_gcs_orphaned_files(): void
    {
        $subject = $this->subject();
        // Two separate uploads → two distinct files, one row each.
        foreach (['a.pdf', 'b.pdf'] as $name) {
            $this->actingAs($this->edu)->post(route('educator.materials.store'), [
                'subject_ids' => [$subject->id],
                'files' => [UploadedFile::fake()->create($name, 100, 'application/pdf')],
            ]);
        }
        [$first, $second] = LearningMaterial::where('educator_id', $this->edu->id)->orderBy('id')->get();

        $this->actingAs($this->edu)
            ->delete(route('educator.materials.bulk-destroy'), ['ids' => [$first->id]])
            ->assertRedirect(route('educator.materials.index'));

        $this->assertNull(LearningMaterial::find($first->id));
        $this->assertNotNull(LearningMaterial::find($second->id));
        Storage::disk('learning-materials')->assertMissing($first->storage_path);
        Storage::disk('learning-materials')->assertExists($second->storage_path);
    }

    public function test_bulk_delete_keeps_a_file_still_shared_by_a_surviving_row(): void
    {
        [$subjectA, $subjectB] = [$this->subject(), $this->subject()];
        // One upload to two subjects → two rows sharing one physical file.
        $this->actingAs($this->edu)->post(route('educator.materials.store'), [
            'subject_ids' => [$subjectA->id, $subjectB->id],
            'files' => [UploadedFile::fake()->create('shared.pdf', 100, 'application/pdf')],
        ]);
        [$first, $second] = LearningMaterial::where('educator_id', $this->edu->id)->orderBy('id')->get();

        $this->actingAs($this->edu)->delete(route('educator.materials.bulk-destroy'), ['ids' => [$first->id]]);

        $this->assertNull(LearningMaterial::find($first->id));
        Storage::disk('learning-materials')->assertExists($second->storage_path); // survivor still references it
    }

    public function test_bulk_delete_ignores_another_educators_materials(): void
    {
        $other = User::factory()->create(['user_type' => 'educator', 'email_verified_at' => now()]);
        $other->roles()->attach(Role::where('name', 'educator')->value('id'));
        $section = Section::create(['educator_id' => $other->id, 'academic_term_id' => $this->term->id,
            'section_name' => 'S'.uniqid(), 'is_active' => true]);
        $otherSubject = Subject::create(['educator_id' => $other->id, 'sections_id' => $section->id,
            'subject_code' => 'CS'.rand(100, 999), 'subject_name' => 'Other', 'is_active' => true]);
        $this->actingAs($other)->post(route('educator.materials.store'), [
            'subject_ids' => [$otherSubject->id],
            'files' => [UploadedFile::fake()->create('theirs.pdf', 100, 'application/pdf')],
        ]);
        $theirs = LearningMaterial::where('educator_id', $other->id)->firstOrFail();

        // Our educator tries to bulk-delete their row — the where(educator_id) filter drops it.
        $this->actingAs($this->edu)->delete(route('educator.materials.bulk-destroy'), ['ids' => [$theirs->id]]);

        $this->assertNotNull(LearningMaterial::find($theirs->id));
    }

    public function test_unsupported_extension_is_rejected(): void
    {
        $subject = $this->subject();

        $this->actingAs($this->edu)->post(route('educator.materials.store'), [
            'subject_ids' => [$subject->id],
            'files' => [UploadedFile::fake()->create('virus.exe', 100)],
        ])->assertSessionHasErrors('files.0');

        $this->assertSame(0, LearningMaterial::count());
    }

    // Task 29: materials carry no term column — the filter walks subject → section → term.
    public function test_materials_index_filters_by_academic_term(): void
    {
        $otherTerm = AcademicTerm::create([
            'term_name' => 'Midterm', 'semester' => '1st Semester',
            'academic_year_id' => $this->term->academic_year_id,
        ]);
        $prelimSubject = $this->subject();
        $midtermSubject = $this->subject($otherTerm);

        foreach ([[$prelimSubject, 'prelim-notes.pdf'], [$midtermSubject, 'midterm-notes.pdf']] as [$subject, $name]) {
            $this->actingAs($this->edu)->post(route('educator.materials.store'), [
                'subject_ids' => [$subject->id],
                'files' => [UploadedFile::fake()->create($name, 100, 'application/pdf')],
            ])->assertRedirect(route('educator.materials.index'));
        }

        $this->actingAs($this->edu)->get(route('educator.materials.index'))
            ->assertOk()
            ->assertSee('data-filter="term"', false)
            ->assertSee('prelim-notes.pdf')
            ->assertSee('midterm-notes.pdf');

        $this->actingAs($this->edu)->get(route('educator.materials.index', ['term' => $this->term->id]))
            ->assertOk()
            ->assertSee('prelim-notes.pdf')
            ->assertDontSee('midterm-notes.pdf');
    }

    private function subject(?AcademicTerm $term = null): Subject
    {
        $section = Section::create([
            'educator_id' => $this->edu->id, 'academic_term_id' => ($term ?? $this->term)->id,
            'section_name' => 'Section'.uniqid(), 'is_active' => true,
        ]);

        return Subject::create([
            'educator_id' => $this->edu->id, 'sections_id' => $section->id,
            'subject_code' => 'CS'.rand(100, 999), 'subject_name' => 'Info Management', 'is_active' => true,
        ]);
    }
}
