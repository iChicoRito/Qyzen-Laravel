<?php

use App\Http\Controllers\Admin\AcademicTermController;
use App\Http\Controllers\Admin\AcademicYearController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\PermissionController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\AccountActivationController;
use App\Http\Controllers\Auth\ForcePasswordChangeController;
use App\Http\Controllers\Auth\OAuthController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\Educator\AnnouncementController as EducatorAnnouncementController;
use App\Http\Controllers\Educator\AssessmentController;
use App\Http\Controllers\Educator\AssessmentQuestionPoolController;
use App\Http\Controllers\Educator\ChatController;
use App\Http\Controllers\Educator\DashboardController as EducatorDashboardController;
use App\Http\Controllers\Educator\EnrollmentController;
use App\Http\Controllers\Educator\MaterialController;
use App\Http\Controllers\Educator\MonitoringController;
use App\Http\Controllers\Educator\QuizController;
use App\Http\Controllers\Educator\ScoreController;
use App\Http\Controllers\Educator\SectionController;
use App\Http\Controllers\Educator\SubjectController;
use App\Http\Controllers\MessagingController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Student\AnnouncementController as StudentAnnouncementController;
use App\Http\Controllers\Student\ChatController as StudentChatController;
use App\Http\Controllers\Student\DashboardController as StudentDashboardController;
use App\Http\Controllers\Student\MaterialController as StudentMaterialController;
use App\Http\Controllers\Student\QuizController as StudentQuizController;
use App\Http\Controllers\Student\ScoreController as StudentScoreController;
use App\Http\Controllers\Student\SubjectController as StudentSubjectController;
use App\Http\Middleware\RedirectInactiveTermRecords;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return Auth::check() ? redirect()->route('dashboard.redirect') : view('welcome');
});

// C3: Google OAuth (Socialite)
Route::get('/oauth/{provider}/redirect', [OAuthController::class, 'redirect'])->name('oauth.redirect');
Route::get('/oauth/{provider}/callback', [OAuthController::class, 'callback'])->name('oauth.callback');
Route::get('/account/activate/{user}', AccountActivationController::class)->name('account.activate');
Route::post('/account/activate/{user}', [AccountActivationController::class, 'verify'])->middleware('throttle:verification-codes');
Route::get('/profile-media/{path}', [ProfileController::class, 'media'])->where('path', '.*');

Route::middleware('auth')->group(function () {
    Route::get('/password/force-change', [ForcePasswordChangeController::class, 'edit'])->name('password.force.edit');
    Route::put('/password/force-change', [ForcePasswordChangeController::class, 'update'])->name('password.force.update');
});

// C5: post-login bounce to the role dashboard (admin > educator > student).
Route::get('/dashboard', fn () => redirect(Auth::user()->dashboardPath()))
    ->middleware(['auth', 'verified'])->name('dashboard.redirect');

// Stage H — Student features + quiz engine. Enrollment-gated; quiz actions add schedule +
// attempt gates. H6 server-side grading is the core invariant (correct_answer never client-side).
Route::middleware(['auth', 'verified', 'role:student'])
    ->prefix('student')->name('student.')->group(function () {
        Route::get('/dashboard', [StudentDashboardController::class, 'index'])->name('dashboard');
        Route::get('/calendar', [CalendarController::class, 'index'])->name('calendar');
        Route::get('subjects', [StudentSubjectController::class, 'index'])->name('subjects.index');
        Route::get('announcements', [StudentAnnouncementController::class, 'index'])->name('announcements.index');

        // H2 assessment list.
        Route::get('assessments', [StudentQuizController::class, 'index'])->name('assessments.index');

        // H3–H6 take-quiz: load, autosave draft, submit (server-side grading).
        Route::get('take-quiz/{assessment}', [StudentQuizController::class, 'take'])->name('take-quiz');
        Route::post('take-quiz/{assessment}/draft', [StudentQuizController::class, 'saveDraft'])
            ->middleware('throttle:quiz-writes')->name('take-quiz.draft');
        Route::post('take-quiz/{assessment}/warning', [StudentQuizController::class, 'warning'])
            ->middleware('throttle:quiz-writes')->name('take-quiz.warning');
        Route::post('take-quiz/{assessment}/submit', [StudentQuizController::class, 'submit'])
            ->middleware('throttle:quiz-writes')->name('take-quiz.submit');
        Route::post('take-quiz/{assessment}/hint', [StudentQuizController::class, 'hint'])
            ->middleware('throttle:quiz-writes')->name('take-quiz.hint');

        // H7 result/review + H8 scores history (own only).
        Route::get('scores', [StudentScoreController::class, 'index'])->name('scores.index');
        Route::get('scores/{score}', [StudentScoreController::class, 'show'])->name('scores.show');

        // H9 materials (enrollment-gated) + H10 chats (request/response).
        Route::get('materials', [StudentMaterialController::class, 'index'])->name('materials.index');
        Route::get('materials/{material}/download', [StudentMaterialController::class, 'download'])->name('materials.download');
        Route::get('chats', [StudentChatController::class, 'index'])->name('chats.index');
        Route::get('chats/{chat}', [StudentChatController::class, 'show'])->name('chats.show');
        Route::post('chats/{chat}/messages', [StudentChatController::class, 'sendMessage'])->name('chats.messages.send');
    });

// H11 — shared profile (all roles). Self-service column lock enforced in the Form Request.
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');
    // Email is switched by picking a Google account (never typed) — sets the intent, then Socialite.
    Route::post('/profile/email/google', [OAuthController::class, 'changeEmailRedirect'])->name('profile.email.google');

    // Calendar event-detail fragment (all roles; visibility scoped in the controller). Shared so the
    // sidebar Calendar page opens details in the standard fetch-loaded modal.
    Route::get('/calendar/assessments/{assessment}', [CalendarController::class, 'show'])->name('calendar.assessment');

    // Task 25 — notification bell read/delivery (owner-scoped; polling JSON, no live transport yet).
    // Shared group: the `role` middleware takes one role, and owner-scoping already isolates recipients.
    // read-all precedes {notification}/read so the literal segment isn't captured as an id.
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
    Route::delete('/notifications', [NotificationController::class, 'destroyAll'])->name('notifications.destroy-all');
    Route::get('/notifications/{notification}/open', [NotificationController::class, 'open'])->name('notifications.open');
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
    Route::get('/announcements/{announcement}/images/{image}', [StudentAnnouncementController::class, 'image'])
        ->whereNumber(['announcement', 'image'])->name('student.announcements.image');

    // Task 30 — private 1:1 student/educator messaging. Shared group: enrollment (subject-
    // agnostic) is the access boundary, re-checked by ConversationPolicy on every action.
    Route::get('/messaging/contacts', [MessagingController::class, 'contacts'])->name('messaging.contacts');
    Route::get('/messaging/conversations', [MessagingController::class, 'conversations'])->name('messaging.conversations');
    Route::post('/messaging/conversations', [MessagingController::class, 'store'])->name('messaging.conversations.store');
    Route::get('/messaging/conversations/{conversation}', [MessagingController::class, 'show'])->name('messaging.conversations.show');
    Route::post('/messaging/conversations/{conversation}/read', [MessagingController::class, 'markRead'])->name('messaging.conversations.read');
    Route::post('/messaging/conversations/{conversation}/messages', [MessagingController::class, 'sendMessage'])->name('messaging.messages.send');
    Route::put('/messaging/messages/{message}', [MessagingController::class, 'updateMessage'])->name('messaging.messages.update');
    Route::delete('/messaging/messages/{message}', [MessagingController::class, 'destroyMessage'])->name('messaging.messages.destroy');
});

// Stage F — Admin features. One route group per role (CONVENTIONS.md); every action behind
// a D-Policy via $this->authorize() in the controller.
Route::middleware(['auth', 'verified', 'role:admin'])
    ->prefix('admin')->name('admin.')->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('/calendar', [CalendarController::class, 'index'])->name('calendar');

        // F3/F4 user-import + bulk routes must precede the {user} resource binding.
        Route::get('users/import/template', [UserController::class, 'importTemplate'])->name('users.import.template');
        Route::post('users/import', [UserController::class, 'import'])->name('users.import');
        Route::get('users/imports/timeline', [UserController::class, 'importTimeline'])->name('users.imports.timeline');
        Route::delete('users/imports', [UserController::class, 'clearImportHistory'])->name('users.imports.clear');
        Route::get('users/imports/{userImport}/report', [UserController::class, 'downloadImportReport'])->name('users.import.report');
        Route::get('users/imports/{userImport}/credentials', [UserController::class, 'downloadImportCredentials'])->name('users.import.credentials');
        Route::get('users/imports/{userImport}', [UserController::class, 'showImport'])->name('users.imports.show');
        Route::post('users/{user}/resend-verification', [UserController::class, 'resendVerification'])->name('users.resend-verification');
        Route::put('users/{user}/verification', [UserController::class, 'updateVerification'])->name('users.verification');
        Route::resource('users', UserController::class)->except(['edit', 'update']);
        Route::get('users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
        Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');

        Route::resource('roles', RoleController::class);

        // F6 permissions: bulk store instead of single create.
        Route::get('permissions', [PermissionController::class, 'index'])->name('permissions.index');
        Route::post('permissions', [PermissionController::class, 'store'])->name('permissions.store');
        Route::get('permissions/{permission}', [PermissionController::class, 'show'])->name('permissions.show');
        Route::get('permissions/{permission}/edit', [PermissionController::class, 'edit'])->name('permissions.edit');
        Route::put('permissions/{permission}', [PermissionController::class, 'update'])->name('permissions.update');
        Route::delete('permissions/{permission}', [PermissionController::class, 'destroy'])->name('permissions.destroy');

        Route::resource('academic-years', AcademicYearController::class);
        Route::resource('academic-terms', AcademicTermController::class);

        Route::get('settings', [SettingController::class, 'index'])->name('settings.index');
        Route::put('settings', [SettingController::class, 'update'])->name('settings.update');
        Route::get('settings/database/download', [SettingController::class, 'downloadDatabase'])->name('settings.database.download');
    });

// Stage G — Educator features. Ownership-gated: every list query carries visibleTo, every
// route a D-Policy. role:educator middleware bounces non-educators. Task 31:
// RedirectInactiveTermRecords turns a stale link to the educator's own inactive-term record into
// a notice instead of letting it render (or error).
Route::middleware(['auth', 'verified', 'role:educator', RedirectInactiveTermRecords::class])
    ->prefix('educator')->name('educator.')->group(function () {
        Route::get('/dashboard', [EducatorDashboardController::class, 'index'])->name('dashboard');
        Route::get('/calendar', [CalendarController::class, 'index'])->name('calendar');

        Route::resource('sections', SectionController::class)->except('show');
        Route::resource('subjects', SubjectController::class)->except('show');

        // G4 enrollment: bulk import routes precede the resource binding.
        Route::get('enrollment/download', [EnrollmentController::class, 'download'])->name('enrollment.download');
        Route::get('enrollment/import/template', [EnrollmentController::class, 'importTemplate'])->name('enrollment.import.template');
        Route::post('enrollment/import', [EnrollmentController::class, 'import'])->name('enrollment.import');
        Route::delete('enrollment/imports', [EnrollmentController::class, 'clearImportHistory'])->name('enrollment.imports.clear');
        Route::get('enrollment/imports/timeline', [EnrollmentController::class, 'importTimeline'])->name('enrollment.imports.timeline');
        Route::get('enrollment/imports/{enrollmentImport}/report', [EnrollmentController::class, 'downloadImportReport'])->name('enrollment.import.report');
        Route::patch('enrollment/imports/{enrollmentImport}/cancel', [EnrollmentController::class, 'cancelImport'])->name('enrollment.imports.cancel');
        Route::get('enrollment/imports/{enrollmentImport}', [EnrollmentController::class, 'showImport'])->name('enrollment.imports.show');
        Route::get('enrollment/subject/{subject}', [EnrollmentController::class, 'showSubject'])->name('enrollment.subject');
        Route::post('enrollment/subject/{subject}/unenroll-all', [EnrollmentController::class, 'unenrollAllForSubject'])->name('enrollment.subject.unenrollAll');
        Route::get('enrollment/student/{user}', [EnrollmentController::class, 'showStudent'])->name('enrollment.student');
        Route::resource('enrollment', EnrollmentController::class)->except('show')->parameters(['enrollment' => 'enrolled']);

        Route::get('assessments/{assessment}/duplicate', [AssessmentController::class, 'duplicate'])->name('assessments.duplicate');
        Route::post('assessments/{assessment}/duplicate', [AssessmentController::class, 'storeDuplicate'])->name('assessments.duplicate.store');
        // Task 29: archiving (scores are preserved — this only flips is_archived). Declared before
        // the resource, and the resource's {assessment} is uuid-constrained, so "archive"/"archived"
        // are not swallowed by the wildcard.
        Route::patch('assessments/archive', [AssessmentController::class, 'archive'])->name('assessments.archive');
        Route::get('assessments/archived', [AssessmentController::class, 'archived'])->name('assessments.archived');
        Route::patch('assessments/archived/restore', [AssessmentController::class, 'restoreArchived'])->name('assessments.archived.restore');
        Route::resource('assessments', AssessmentController::class)->except('show')->whereUuid('assessment');

        // Task 51: question pool config — which bank questions are eligible + draw size N.
        Route::get('assessments/{assessment}/pool', [AssessmentQuestionPoolController::class, 'edit'])->name('assessments.pool.edit');
        Route::put('assessments/{assessment}/pool', [AssessmentQuestionPoolController::class, 'update'])->name('assessments.pool.update');

        // Task 01: per-student "cannot take this quiz" exemption (e.g. absent).
        Route::get('assessments/{assessment}/exemptions', [AssessmentController::class, 'exemptions'])->name('assessments.exemptions');
        Route::post('assessments/{assessment}/exemptions/toggle', [AssessmentController::class, 'toggleExemption'])->name('assessments.exemptions.toggle');
        Route::get('assessments/{assessment}/access', [AssessmentController::class, 'access'])->name('assessments.access');
        Route::post('assessments/{assessment}/access/toggle', [AssessmentController::class, 'toggleAccess'])->name('assessments.access.toggle');

        // G6 quizzes (now the question bank): bulk upload.
        Route::get('quizzes/upload/template', [QuizController::class, 'uploadTemplate'])->name('quizzes.upload.template');
        Route::post('quizzes/upload', [QuizController::class, 'upload'])->name('quizzes.upload');
        Route::delete('quizzes/archive', [QuizController::class, 'archive'])->name('quizzes.archive');
        Route::get('quizzes/archived', [QuizController::class, 'archived'])->name('quizzes.archived');
        Route::patch('quizzes/archived/restore', [QuizController::class, 'restoreArchived'])->name('quizzes.archived.restore');
        Route::get('quizzes/form', [QuizController::class, 'create'])->name('quizzes.create');
        Route::delete('quizzes/bulk', [QuizController::class, 'bulkDelete'])->name('quizzes.bulk');
        Route::resource('quizzes', QuizController::class)->except(['show', 'create'])->whereNumber('quiz');

        // G7 scores (read-only) + grant retake.
        Route::get('scores', [ScoreController::class, 'index'])->name('scores.index');
        // G8/Task 27 export — registered above the {score} wildcard so these literal-prefix
        // routes aren't shadowed by it.
        Route::get('scores/export/preview/{assessment}', [ScoreController::class, 'exportPreview'])->name('scores.export.preview');
        Route::get('scores/export/{assessment}', [ScoreController::class, 'export'])->name('scores.export');
        Route::get('scores/export-bulk/run', [ScoreController::class, 'exportBulk'])->name('scores.export-bulk');
        Route::get('scores/upload/template', [ScoreController::class, 'uploadTemplate'])->name('scores.upload.template');
        Route::post('scores/upload', [ScoreController::class, 'upload'])->name('scores.upload');
        Route::get('scores/deleted', [ScoreController::class, 'deleted'])->name('scores.deleted');
        // Task 27: class scores matrix + per-student detail, both modal fragments off the index
        // row menu. Query params (not path segments) so they don't collide with {score}.
        Route::get('scores/matrix', [ScoreController::class, 'matrix'])->name('scores.matrix');
        Route::get('scores/student', [ScoreController::class, 'studentDetail'])->name('scores.student');
        Route::patch('scores/{score}/restore', [ScoreController::class, 'restore'])->withTrashed()->name('scores.restore');
        Route::delete('scores/{score}', [ScoreController::class, 'destroy'])->withTrashed()->name('scores.destroy');
        Route::get('scores/{score}', [ScoreController::class, 'show'])->name('scores.show');
        Route::post('scores/retake', [ScoreController::class, 'grantRetake'])->name('scores.grant-retake');

        // G9 materials + signed download.
        Route::get('materials/{material}/download', [MaterialController::class, 'download'])->name('materials.download');
        // Task 13: bulk delete — declared before the resource so {material} doesn't shadow "bulk".
        Route::delete('materials/bulk', [MaterialController::class, 'bulkDestroy'])->name('materials.bulk-destroy');
        Route::resource('materials', MaterialController::class)->except('show');
        Route::resource('announcements', EducatorAnnouncementController::class)->except('show');

        // G10 group chats (request/response).
        Route::get('chats', [ChatController::class, 'index'])->name('chats.index');
        Route::post('chats', [ChatController::class, 'store'])->name('chats.store');
        Route::get('chats/{chat}', [ChatController::class, 'show'])->name('chats.show');
        Route::delete('chats/{chat}', [ChatController::class, 'destroy'])->name('chats.destroy');
        Route::post('chats/{chat}/messages', [ChatController::class, 'sendMessage'])->name('chats.messages.send');

        // G11 monitoring (request/response).
        Route::get('monitoring', [MonitoringController::class, 'index'])->name('monitoring.index');
    });
