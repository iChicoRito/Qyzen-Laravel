<?php

namespace App\Services\Export;

// G8/Task 27: pure function, no DB/FS access. Given the enrollment roster and the submitted
// scores for ONE assessment, resolves each student's best attempt (when retakes exist) and
// emits one row per enrolled student — including non-submitters ("No Submission"). Kept free
// of Eloquent queries so it's unit-testable with plain collections/stubs.
final class ScoreRowBuilder
{
    /**
     * @param  iterable  $roster  Enrolled models for this subject, ->student loaded (id, given_name, surname, user_id)
     * @param  iterable  $scores  Score models for this one assessment, submitted_at not null
     * @param  array  $context  ['subject', 'section', 'assessmentCode', 'academicTerm']
     * @return array{rows: array<int, array>, summary: array{enrolled: int, withSubmission: int, withoutSubmission: int}}
     */
    public static function build(iterable $roster, iterable $scores, ?int $totalQuestions, array $context): array
    {
        $scoresByStudent = collect($scores)->groupBy('student_id');

        // Note: in array-of-criteria form, Collection::sortBy treats a Closure criterion as a
        // two-argument comparator (like usort), not a single-value extractor.
        $ordered = collect($roster)->sortBy([
            [fn ($a, $b) => mb_strtolower($a->student->surname ?? '') <=> mb_strtolower($b->student->surname ?? ''), 'asc'],
            [fn ($a, $b) => mb_strtolower($a->student->given_name ?? '') <=> mb_strtolower($b->student->given_name ?? ''), 'asc'],
        ])->values();

        $rows = $ordered->map(fn ($enrolled) => self::rowFor(
            $enrolled,
            $scoresByStudent->get($enrolled->student_id, collect()),
            $totalQuestions,
            $context,
        ))->all();

        $withSubmission = collect($rows)->filter(fn ($r) => $r['Status'] !== 'No Submission')->count();

        return [
            'rows' => $rows,
            'summary' => [
                'enrolled' => count($rows),
                'withSubmission' => $withSubmission,
                'withoutSubmission' => count($rows) - $withSubmission,
            ],
        ];
    }

    private static function rowFor($enrolled, $attempts, ?int $totalQuestions, array $context): array
    {
        $student = $enrolled->student;

        $base = [
            'Student Name' => trim(($student->surname ?? '').', '.($student->given_name ?? ''), ', '),
            'Student ID' => $student->user_id ?? null,
            'Subject' => $context['subject'] ?? null,
            'Section' => $context['section'] ?? null,
            'Assessment Code' => $context['assessmentCode'] ?? null,
            'Academic Term' => $context['academicTerm'] ?? null,
        ];

        $best = self::bestAttempt($attempts);

        if (! $best) {
            return $base + [
                'Highest Score' => null,
                'Total Questions' => null,
                'Percentage' => null,
                'Status' => 'No Submission',
                'Remark' => 'No Submission',
                'Highest Submitted At' => null,
            ];
        }

        $rowTotal = $best->total_questions ?: $totalQuestions;

        if ($best->score === null) {
            return $base + [
                'Highest Score' => null,
                'Total Questions' => $rowTotal,
                'Percentage' => null,
                'Status' => 'Data Anomaly',
                'Remark' => 'Data Anomaly — submitted with no score',
                'Highest Submitted At' => $best->submitted_at,
            ];
        }

        return $base + [
            'Highest Score' => $best->score,
            'Total Questions' => $rowTotal,
            'Percentage' => self::percentage($best->score, $best->total_questions),
            'Status' => $best->is_passed ? 'Passed' : 'Failed',
            'Remark' => 'Highest submitted score',
            'Highest Submitted At' => $best->submitted_at,
        ];
    }

    // Best attempt = highest raw score, then highest percentage, then most recent (highest id).
    // A submitted-but-null-score row is only ever "best" when it's the student's only attempt —
    // ranked via a tuple so PHP's array<=>array comparison gives a safe, null-proof ordering.
    // Public so the Task 27 scores matrix resolves "best attempt" from this one definition.
    public static function bestAttempt($attempts)
    {
        return collect($attempts)->sortByDesc(fn ($s) => [
            $s->score === null ? 0 : 1,
            $s->score ?? -1,
            self::percentage($s->score, $s->total_questions) ?? -1,
            $s->id,
        ])->first();
    }

    private static function percentage(?int $score, ?int $total): ?float
    {
        if ($score === null || ! $total) {
            return null;
        }

        return round($score / $total * 100);
    }
}
