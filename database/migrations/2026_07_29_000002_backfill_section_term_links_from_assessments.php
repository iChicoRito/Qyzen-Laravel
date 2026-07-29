<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Task 31: tbl_sections_term is the record of which terms a section is taught in, but nothing ever
 * wrote to it when an assessment was filed under a term. Rolling PRELIM → MIDTERM therefore left
 * every section linked to PRELIM only, and deactivating PRELIM hid the new term's assessments and
 * scores along with the old term's.
 *
 * Backfills two things, both idempotent:
 *  - every (section, term) pair implied by an existing assessment;
 *  - the legacy tbl_sections.academic_term_id, for any section that has no pivot row at all.
 *
 * Assessment::saved() keeps the link current from here on.
 */
return new class extends Migration
{
    public function up(): void
    {
        $pairs = DB::table('tbl_assessments')
            ->select('section_id', 'term')
            ->whereNotNull('section_id')
            ->whereNotNull('term')
            ->distinct()
            ->get()
            ->map(fn ($row) => ['section_id' => $row->section_id, 'academic_term_id' => $row->term]);

        $legacy = DB::table('tbl_sections')
            ->select('id as section_id', 'academic_term_id')
            ->whereNotNull('academic_term_id')
            ->get()
            ->map(fn ($row) => ['section_id' => $row->section_id, 'academic_term_id' => $row->academic_term_id]);

        $rows = $pairs->concat($legacy)
            ->unique(fn (array $r) => $r['section_id'].':'.$r['academic_term_id'])
            ->values();

        // Only insert pairs whose section and term both still exist — the pivot has FKs both ways.
        $sections = DB::table('tbl_sections')->pluck('id')->flip();
        $terms = DB::table('tbl_academic_term')->pluck('id')->flip();
        $existing = DB::table('tbl_sections_term')
            ->get()
            ->map(fn ($r) => $r->section_id.':'.$r->academic_term_id)
            ->flip();

        $insert = $rows
            ->filter(fn (array $r) => isset($sections[$r['section_id']], $terms[$r['academic_term_id']]))
            ->reject(fn (array $r) => isset($existing[$r['section_id'].':'.$r['academic_term_id']]))
            ->values()
            ->all();

        foreach (array_chunk($insert, 500) as $chunk) {
            DB::table('tbl_sections_term')->insert($chunk);
        }
    }

    public function down(): void
    {
        // Irreversible by design: the pre-backfill state cannot be told apart from links an
        // educator has legitimately created since.
    }
};
