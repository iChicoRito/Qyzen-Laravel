<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Task 29: an announcement can target several subjects. Replaces the single
// tbl_announcements.subject_id FK with a pivot; the old column is backfilled into it and dropped
// so there is exactly one source of truth for "who is this for".
//
// Written step-idempotent on purpose: MySQL commits each DDL statement implicitly, so a failure
// part-way through cannot roll back the earlier steps. Each block re-checks the schema before
// acting, which makes a re-run after a partial apply safe.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tbl_announcement_subject')) {
            Schema::create('tbl_announcement_subject', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('announcement_id')->constrained('tbl_announcements')->cascadeOnDelete();
                $table->foreignId('subject_id')->constrained('tbl_subjects')->cascadeOnDelete();
                $table->unique(['announcement_id', 'subject_id']);
            });
        }

        if (Schema::hasColumn('tbl_announcements', 'subject_id')) {
            // Guard in PHP, not with INSERT IGNORE / ON CONFLICT — those are dialect-specific and
            // this has to run on MySQL and on the sqlite test database alike.
            if (DB::table('tbl_announcement_subject')->doesntExist()) {
                DB::statement('INSERT INTO tbl_announcement_subject (announcement_id, subject_id)
                    SELECT id, subject_id FROM tbl_announcements WHERE subject_id IS NOT NULL');
            }

            Schema::table('tbl_announcements', function (Blueprint $table): void {
                // FK first: it is backed by the (subject_id, is_active) index, and MySQL refuses to
                // drop an index a foreign key still relies on (errno 1553).
                $table->dropForeign(['subject_id']);
                $table->dropIndex(['subject_id', 'is_active']);
                $table->dropColumn('subject_id');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('tbl_announcements', 'subject_id')) {
            Schema::table('tbl_announcements', function (Blueprint $table): void {
                $table->foreignId('subject_id')->nullable()->after('educator_id')->constrained('tbl_subjects')->nullOnDelete();
                $table->index(['subject_id', 'is_active']);
            });

            // Lossy by nature: the pre-Task-29 column holds one subject, so keep the lowest-id pick.
            DB::statement('UPDATE tbl_announcements a SET subject_id = (
                SELECT MIN(p.subject_id) FROM tbl_announcement_subject p WHERE p.announcement_id = a.id)');
        }

        Schema::dropIfExists('tbl_announcement_subject');
    }
};
