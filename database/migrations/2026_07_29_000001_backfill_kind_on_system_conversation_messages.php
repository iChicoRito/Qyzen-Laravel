<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Task 30: messages sent before the `kind` column existed still render as plain chat bubbles.
// They are identifiable only by their generated opening phrase — these two sentences are produced
// solely by AssessmentController::toggleExemption/toggleAccess, never typed by a user, so matching
// on the prefix is safe. Both the pre- and post-Task-30 wording are matched ("exempted from
// assessment X" became "exempted from X"), and rows that already carry a kind are left alone.
return new class extends Migration
{
    private const PREFIXES = [
        'assessment_exempted' => ['You have been exempted from %'],
        'assessment_access_granted' => ['You have been granted special access to %'],
    ];

    public function up(): void
    {
        foreach (self::PREFIXES as $kind => $patterns) {
            foreach ($patterns as $pattern) {
                DB::table('tbl_conversation_messages')
                    ->whereNull('kind')
                    ->whereNull('message_deleted_at')
                    ->where('content', 'like', $pattern)
                    ->update(['kind' => $kind]);
            }
        }
    }

    public function down(): void
    {
        DB::table('tbl_conversation_messages')
            ->whereIn('kind', array_keys(self::PREFIXES))
            ->update(['kind' => null]);
    }
};
