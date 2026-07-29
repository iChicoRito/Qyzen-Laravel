<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Announcement;
use App\Models\Enrolled;
use App\Models\Notification;
use App\Models\Role;
use App\Models\Section;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AnnouncementTest extends TestCase
{
    use RefreshDatabase;

    private User $educator;

    private User $otherEducator;

    private User $student;

    private User $otherStudent;

    private Subject $subject;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'educator', 'student'] as $name) {
            Role::create(['name' => $name, 'description' => $name, 'is_active' => true]);
        }

        $this->educator = $this->makeUser('educator');
        $this->otherEducator = $this->makeUser('educator');
        $this->student = $this->makeUser('student');
        $this->otherStudent = $this->makeUser('student');

        $year = AcademicYear::create(['year' => '2025 - 2026']);
        $term = AcademicTerm::create([
            'term_name' => 'Prelim', 'semester' => '1st Semester', 'academic_year_id' => $year->id,
        ]);
        $section = Section::create([
            'educator_id' => $this->educator->id, 'academic_term_id' => $term->id,
            'section_name' => 'A1', 'is_active' => true,
        ]);
        $this->subject = Subject::create([
            'educator_id' => $this->educator->id, 'sections_id' => $section->id,
            'subject_code' => 'M1', 'subject_name' => 'Math', 'is_active' => true,
        ]);

        Enrolled::create([
            'student_id' => $this->student->id, 'educator_id' => $this->educator->id,
            'subject_id' => $this->subject->id, 'is_active' => true,
        ]);
        Enrolled::create([
            'student_id' => $this->otherStudent->id, 'educator_id' => $this->educator->id,
            'subject_id' => $this->subject->id, 'is_active' => false,
        ]);
    }

    public function test_educator_can_create_edit_and_delete_announcement(): void
    {
        Storage::fake('announcement-images');

        $response = $this->actingAs($this->educator)->post(route('educator.announcements.store'), [
            'title' => 'Exam update',
            'description' => 'Read this first.',
            'body' => '<p>Hello <script>alert(1)</script><strong>class</strong></p>',
            'subject_ids' => [$this->subject->id],
            'is_global' => '0',
            'images' => [UploadedFile::fake()->create('notice.jpg', 10, 'image/jpeg')],
        ]);

        $response->assertRedirect(route('educator.announcements.index'));
        $announcement = Announcement::firstOrFail();
        $this->assertSame('<p>Hello <strong>class</strong></p>', $announcement->body);
        $this->assertCount(1, $announcement->images);
        Storage::disk('announcement-images')->assertExists($announcement->images[0]['path']);

        $this->actingAs($this->educator)->put(route('educator.announcements.update', $announcement), [
            'title' => 'Updated exam', 'description' => '', 'body' => '<p>Updated</p>',
            'subject_ids' => [$this->subject->id], 'is_global' => '0',
        ])->assertRedirect(route('educator.announcements.index'));
        $this->assertSame('Updated exam', $announcement->fresh()->title);

        $path = $announcement->fresh()->images[0]['path'];
        $this->actingAs($this->educator)
            ->delete(route('educator.announcements.destroy', $announcement))
            ->assertRedirect(route('educator.announcements.index'));
        $this->assertDatabaseMissing('tbl_announcements', ['id' => $announcement->id]);
        Storage::disk('announcement-images')->assertMissing($path);
    }

    public function test_announcement_editor_binding_is_delegated_for_modal_fragments(): void
    {
        $response = $this->actingAs($this->educator)->get(route('educator.announcements.index'))->assertOk();

        $response->assertSee('initAnnouncementEditors', false)
            ->assertSee('data-quill-editor', false)
            ->assertSee('data-quill-value', false)
            ->assertDontSee('document.execCommand', false)
            ->assertDontSee('data-editor-command', false);
    }

    public function test_announcement_rich_text_formatting_is_preserved_for_students(): void
    {
        $body = '<h2>Plan</h2><p><strong>Bold</strong> <em>Italic</em> <u>Underline</u> <s>Strike</s></p><ol><li>First</li></ol><blockquote>Quote</blockquote><pre>Code</pre>';

        $this->actingAs($this->educator)->post(route('educator.announcements.store'), [
            'title' => 'Formatted notice',
            'body' => $body,
            'subject_ids' => [$this->subject->id],
            'is_global' => '0',
        ])->assertRedirect(route('educator.announcements.index'));

        $announcement = Announcement::latest('id')->firstOrFail();
        $this->assertStringContainsString('<s>Strike</s>', $announcement->body);
        $this->assertStringContainsString('<blockquote>Quote</blockquote>', $announcement->body);
        $this->assertStringContainsString('<pre>Code</pre>', $announcement->body);

        $this->actingAs($this->student)->get(route('student.announcements.index'))
            ->assertOk()
            ->assertSee('announcement-body ql-editor', false)
            ->assertSee('<h2>Plan</h2>', false)
            ->assertSee('<strong>Bold</strong>', false)
            ->assertSee('<em>Italic</em>', false)
            ->assertSee('<u>Underline</u>', false)
            ->assertSee('<s>Strike</s>', false)
            ->assertSee('<blockquote>Quote</blockquote>', false)
            ->assertSee('<pre>Code</pre>', false);
    }

    public function test_announcement_targeting_notifications_and_student_cards_are_scoped(): void
    {
        $subjectAnnouncement = $this->createAnnouncement(['subject_ids' => [$this->subject->id]]);
        $globalAnnouncement = $this->createAnnouncement(['subject_ids' => [], 'is_global' => true, 'title' => 'Global notice']);

        $this->assertDatabaseHas('tbl_notifications', [
            'recipient_user_id' => $this->student->id,
            'event_type' => 'announcement_created',
        ]);
        $this->assertSame(2, Notification::where('recipient_user_id', $this->student->id)->where('event_type', 'announcement_created')->count());
        $this->assertSame(0, Notification::where('recipient_user_id', $this->otherStudent->id)->where('event_type', 'announcement_created')->count());

        $response = $this->actingAs($this->student)->get(route('student.announcements.index'));
        $response->assertOk()->assertSee($subjectAnnouncement->title)->assertSee($globalAnnouncement->title);
        $response->assertSee('New announcement: '.$subjectAnnouncement->title);
        $response->assertSee('>Educator<', false)
            ->assertSee('data-announcement-author-row', false)
            ->assertSee('data-announcement-timestamp', false);
        preg_match_all('/<article\b.*?<\/article>/s', $response->getContent(), $cards);
        $cardHtml = implode('', $cards[0]);
        $this->assertStringNotContainsString('ki-dots-vertical', $cardHtml);
        $this->assertStringNotContainsString('ki-heart', $cardHtml);
        $this->assertStringNotContainsString('ki-message-text', $cardHtml);

        $this->actingAs($this->otherStudent)->get(route('student.announcements.index'))
            ->assertOk()->assertDontSee($subjectAnnouncement->title)->assertDontSee($globalAnnouncement->title);
    }

    public function test_student_announcements_use_a_static_timeline_and_card_feed(): void
    {
        $older = $this->createAnnouncement(['title' => 'Older timeline announcement']);
        $older->update(['created_at' => now()->subMinute()]);
        $newer = $this->createAnnouncement(['title' => 'Newest timeline announcement']);
        Notification::forRecipient($this->student->id)
            ->where('event_type', 'announcement_created')
            ->whereJsonContains('metadata->announcement_id', $older->id)
            ->update(['is_read' => true, 'read_at' => now()]);

        $response = $this->actingAs($this->student)->get(route('student.announcements.index'))->assertOk();
        $response->assertSee('id="announcement_layout"', false)
            ->assertSee('@media (min-width: 768px)', false)
            ->assertSee('grid-template-columns: minmax(0, 1fr) minmax(260px, 32%)', false)
            ->assertSee('id="announcement_timeline"', false)
            ->assertDontSee('lg:col-span-', false)
            ->assertSee($newer->title)
            ->assertSee($older->title)
            ->assertSee('data-announcement-timeline-item="'.$newer->id.'" data-announcement-new="true"', false)
            ->assertSee('data-announcement-timeline-item="'.$older->id.'" data-announcement-new="false"', false)
            ->assertSee('>New<', false)
            ->assertDontSee('data-announcement-select', false)
            ->assertDontSee('data-announcement-detail', false);

        preg_match_all('/<article\b.*?<\/article>/s', $response->getContent(), $cards);
        $this->assertCount(2, $cards[0]);
    }

    public function test_educator_announcements_can_sort_by_subject_with_pagination(): void
    {
        $this->createAnnouncement(['title' => 'Sortable announcement']);

        $this->actingAs($this->educator)
            ->get(route('educator.announcements.index', [
                'direction' => 'asc', 'sort' => 'subject', 'per_page' => 10,
            ]))
            ->assertOk()
            ->assertSee('Sortable announcement');
    }

    public function test_educator_ownership_and_upload_validation_are_enforced(): void
    {
        $announcement = Announcement::create([
            'educator_id' => $this->educator->id,
            'title' => 'Owned', 'body' => '<p>Body</p>', 'is_global' => false, 'is_active' => true,
        ]);
        $announcement->subjects()->attach($this->subject->id);

        $this->actingAs($this->otherEducator)->get(route('educator.announcements.edit', $announcement))->assertForbidden();
        $this->actingAs($this->otherEducator)->delete(route('educator.announcements.destroy', $announcement))->assertForbidden();

        $this->actingAs($this->educator)->post(route('educator.announcements.store'), [
            'title' => 'Bad', 'body' => '<p>Body</p>', 'subject_ids' => [$this->subject->id],
            'is_global' => '0', 'images' => [UploadedFile::fake()->create('bad.pdf', 10, 'application/pdf')],
        ])->assertSessionHasErrors('images.0');

        $this->actingAs($this->educator)->post(route('educator.announcements.store'), [
            'title' => 'Too large', 'body' => '<p>Body</p>', 'subject_ids' => [$this->subject->id],
            'is_global' => '0', 'images' => [UploadedFile::fake()->create('large.jpg', 10241, 'image/jpeg')],
        ])->assertSessionHasErrors('images.0');
    }

    public function test_announcement_images_are_private_and_enrollment_gated(): void
    {
        Storage::fake('announcement-images');
        $this->actingAs($this->educator)->post(route('educator.announcements.store'), [
            'title' => 'Image notice', 'body' => '<p>Body</p>', 'subject_ids' => [$this->subject->id],
            'is_global' => '0', 'images' => [UploadedFile::fake()->create('notice.png', 10, 'image/png')],
        ])->assertRedirect();
        $announcement = Announcement::latest('id')->firstOrFail();

        $this->actingAs($this->student)->get(route('student.announcements.image', [$announcement, 0]))->assertOk();
        $this->actingAs($this->otherStudent)->get(route('student.announcements.image', [$announcement, 0]))->assertForbidden();
    }

    public function test_announcement_images_fall_back_to_legacy_local_storage(): void
    {
        Storage::fake('announcement-images');
        Storage::fake('local')->put('announcements/legacy.jpg', 'legacy-image');
        $announcement = Announcement::create([
            'educator_id' => $this->educator->id,
            'title' => 'Legacy image',
            'body' => '<p>Legacy</p>',
            'is_global' => false,
            'is_active' => true,
            'images' => [[
                'path' => 'announcements/legacy.jpg',
                'name' => 'legacy.jpg',
                'mime' => 'image/jpeg',
            ]],
        ]);
        $announcement->subjects()->attach($this->subject->id);

        $response = $this->actingAs($this->student)
            ->get(route('student.announcements.image', [$announcement, 0]))
            ->assertOk();

        $this->assertSame('legacy-image', file_get_contents($response->baseResponse->getFile()->getPathname()));
    }

    public function test_command_migrates_legacy_images_idempotently_and_reports_missing_files(): void
    {
        Storage::fake('announcement-images');
        Storage::fake('local')->put('announcements/legacy.jpg', 'legacy-image');
        Announcement::create([
            'educator_id' => $this->educator->id,
            'title' => 'Migration',
            'body' => '<p>Migration</p>',
            'is_global' => false,
            'is_active' => true,
            'images' => [
                ['path' => 'announcements/legacy.jpg', 'name' => 'legacy.jpg', 'mime' => 'image/jpeg'],
                ['path' => 'announcements/missing.jpg', 'name' => 'missing.jpg', 'mime' => 'image/jpeg'],
            ],
        ]);

        $this->assertSame(0, Artisan::call('announcements:migrate-image-storage'));
        Storage::disk('announcement-images')->assertExists('announcements/legacy.jpg');
        $this->assertStringContainsString('Copied 1 image(s); 0 already present; 1 missing.', Artisan::output());

        $this->assertSame(0, Artisan::call('announcements:migrate-image-storage'));
        $this->assertStringContainsString('Copied 0 image(s); 1 already present; 1 missing.', Artisan::output());
    }

    // Task 29: several subjects per announcement, on both create and edit.
    public function test_announcement_can_target_multiple_subjects_and_is_seen_once(): void
    {
        $secondSubject = Subject::create([
            'educator_id' => $this->educator->id, 'sections_id' => $this->subject->sections_id,
            'subject_code' => 'S2', 'subject_name' => 'Science', 'is_active' => true,
        ]);
        $thirdSubject = Subject::create([
            'educator_id' => $this->educator->id, 'sections_id' => $this->subject->sections_id,
            'subject_code' => 'H3', 'subject_name' => 'History', 'is_active' => true,
        ]);
        // The student is enrolled in BOTH targets — the feed must not double up.
        Enrolled::create([
            'student_id' => $this->student->id, 'educator_id' => $this->educator->id,
            'subject_id' => $secondSubject->id, 'is_active' => true,
        ]);
        // otherStudent is enrolled (actively) in a subject that is NOT targeted.
        Enrolled::create([
            'student_id' => $this->otherStudent->id, 'educator_id' => $this->educator->id,
            'subject_id' => $thirdSubject->id, 'is_active' => true,
        ]);

        $announcement = $this->createAnnouncement([
            'title' => 'Two-subject notice',
            'subject_ids' => [$this->subject->id, $secondSubject->id],
        ]);

        $this->assertEqualsCanonicalizing(
            [$this->subject->id, $secondSubject->id],
            $announcement->subjects->pluck('id')->all()
        );
        $this->assertSame(1, Notification::where('recipient_user_id', $this->student->id)
            ->where('event_type', 'announcement_created')->count());

        $response = $this->actingAs($this->student)->get(route('student.announcements.index'))->assertOk();
        preg_match_all('/data-announcement-timeline-item="'.$announcement->id.'"/', $response->getContent(), $hits);
        $this->assertCount(1, $hits[0]);

        $this->actingAs($this->otherStudent)->get(route('student.announcements.index'))
            ->assertOk()->assertDontSee('Two-subject notice');

        // Edit preselects both, and saving a narrowed set replaces the targets.
        $this->actingAs($this->educator)->get(route('educator.announcements.edit', $announcement))
            ->assertOk()
            ->assertSee('value="'.$this->subject->id.'" selected', false)
            ->assertSee('value="'.$secondSubject->id.'" selected', false)
            // The target picker is the KTUI enhanced-tags select, not a bare browser multi-select.
            ->assertSee('data-kt-select="true"', false)
            ->assertSee('data-kt-select-multiple="true"', false)
            ->assertSee('data-kt-select-tags="true"', false)
            ->assertSee('data-kt-select-enable-search="true"', false);

        $this->actingAs($this->educator)->put(route('educator.announcements.update', $announcement), [
            'title' => 'Two-subject notice', 'description' => '', 'body' => '<p>Updated</p>',
            'subject_ids' => [$secondSubject->id], 'is_global' => '0',
        ])->assertRedirect(route('educator.announcements.index'));
        $this->assertSame([$secondSubject->id], $announcement->fresh()->subjects->pluck('id')->all());
    }

    public function test_announcement_requires_at_least_one_target_unless_global(): void
    {
        $this->actingAs($this->educator)->post(route('educator.announcements.store'), [
            'title' => 'No target', 'body' => '<p>Body</p>', 'is_global' => '0',
        ])->assertSessionHasErrors('subject_ids');

        $this->actingAs($this->educator)->post(route('educator.announcements.store'), [
            'title' => 'Someone else subject', 'body' => '<p>Body</p>', 'is_global' => '0',
            'subject_ids' => [$this->foreignSubject()->id],
        ])->assertSessionHasErrors('subject_ids.0');
    }

    private function foreignSubject(): Subject
    {
        $year = AcademicYear::create(['year' => '2026 - 2027']);
        $term = AcademicTerm::create(['term_name' => 'Prelim', 'semester' => '1st Semester', 'academic_year_id' => $year->id]);
        $section = Section::create([
            'educator_id' => $this->otherEducator->id, 'academic_term_id' => $term->id,
            'section_name' => 'B1', 'is_active' => true,
        ]);

        return Subject::create([
            'educator_id' => $this->otherEducator->id, 'sections_id' => $section->id,
            'subject_code' => 'X9', 'subject_name' => 'Foreign', 'is_active' => true,
        ]);
    }

    private function createAnnouncement(array $overrides = []): Announcement
    {
        $this->actingAs($this->educator)->post(route('educator.announcements.store'), array_merge([
            'title' => 'Class announcement', 'body' => '<p>Announcement body</p>',
            'subject_ids' => [$this->subject->id], 'is_global' => '0',
        ], $overrides))->assertRedirect();

        return Announcement::latest('id')->firstOrFail();
    }

    private function makeUser(string $type): User
    {
        $user = User::factory()->create(['user_type' => $type, 'email_verified_at' => now()]);
        $user->roles()->attach(Role::where('name', $type)->value('id'));

        return $user;
    }
}
