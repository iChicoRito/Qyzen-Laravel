<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Task 32: one uploaded question-bank file shared across several subjects must produce ONE row per
// question, not one per question per subject. Same shape as tbl_learning_material_subject —
// tbl_quizzes.subject_id stays the creation-time primary, mirrored into the pivot by Quiz::saved(),
// and the pivot is the truth for visibility and filtering.
//
// Backfill is additive only: every existing question gets exactly one link.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tbl_quiz_subject')) {
            Schema::create('tbl_quiz_subject', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('quiz_id')->constrained('tbl_quizzes')->cascadeOnDelete();
                $table->foreignId('subject_id')->constrained('tbl_subjects')->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['quiz_id', 'subject_id']);
                $table->index('subject_id');
            });
        }

        // Soft-deleted questions are linked too: the bank's archive/restore reads through the same
        // scopes, so a restored question must still resolve its subject.
        if (DB::table('tbl_quiz_subject')->doesntExist()) {
            DB::statement('INSERT INTO tbl_quiz_subject (quiz_id, subject_id, created_at, updated_at)
                SELECT id, subject_id, created_at, updated_at FROM tbl_quizzes WHERE subject_id IS NOT NULL');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_quiz_subject');
    }
};
