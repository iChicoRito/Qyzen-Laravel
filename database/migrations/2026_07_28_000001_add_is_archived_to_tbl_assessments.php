<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Task 29: archive assessments without touching their scores. Deliberately a boolean rather
// than soft deletes — a global soft-delete scope would silently drop archived assessments out
// of Score::visibleTo (which joins through assessment.academicTerm), the score matrix and the
// exports, i.e. it would hide student score history. A flag can only ever leak a stale row.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_assessments', function (Blueprint $table): void {
            $table->boolean('is_archived')->default(false)->after('is_active')->index();
        });
    }

    public function down(): void
    {
        Schema::table('tbl_assessments', function (Blueprint $table): void {
            $table->dropIndex(['is_archived']);
            $table->dropColumn('is_archived');
        });
    }
};
