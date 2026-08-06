<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Task 32: one uploaded file assigned to several subjects must be ONE material row, not one per
// subject. The pivot carries that fan-out; tbl_learning_materials.subject_id/section_id stay as the
// creation-time primary (same shape as tbl_sections.academic_term_id + tbl_sections_term), mirrored
// into the pivot by LearningMaterial::saved(). The pivot is the truth for visibility and filtering.
//
// Backfill is additive only — every existing row gets exactly one link, so pre-Task-32 duplicate
// rows keep behaving exactly as they do today. Nothing is merged or deleted.
//
// Step-idempotent on purpose: MySQL commits each DDL statement implicitly, so a re-run after a
// partial apply has to be safe.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tbl_learning_material_subject')) {
            Schema::create('tbl_learning_material_subject', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('material_id')->constrained('tbl_learning_materials')->cascadeOnDelete();
                $table->foreignId('subject_id')->constrained('tbl_subjects')->cascadeOnDelete();
                $table->foreignId('section_id')->nullable()->constrained('tbl_sections')->nullOnDelete();
                $table->timestamps();
                $table->unique(['material_id', 'subject_id']);
                $table->index('subject_id');
            });
        }

        // Guard in PHP rather than INSERT IGNORE / ON CONFLICT — those are dialect-specific and
        // this has to run on MySQL and on the sqlite test database alike.
        if (DB::table('tbl_learning_material_subject')->doesntExist()) {
            DB::statement('INSERT INTO tbl_learning_material_subject (material_id, subject_id, section_id, created_at, updated_at)
                SELECT id, subject_id, section_id, created_at, updated_at FROM tbl_learning_materials WHERE subject_id IS NOT NULL');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_learning_material_subject');
    }
};
