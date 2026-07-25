{{-- One attempt: summary + per-question review. Shared by the single-attempt page (show) and the
     Task 27 per-student detail, which loops it.
     Props: $score, $reviewQuestions (Quiz collection for this attempt's drawn_quiz_ids).
     G7: correct_answer rendered here is server-side (educator view) — never served to a student. --}}
@php
    $pct = $score->total_questions ? round($score->score / $score->total_questions * 100) : 0;
    $answers = $score->student_answer ?? [];
@endphp
<div class="grid">
    <div class="flex items-center justify-between flex-wrap mb-2.5 gap-2">
        <span class="text-xs text-secondary-foreground uppercase">Score</span>
        <span class="text-sm text-mono font-semibold">{{ $score->score }}/{{ $score->total_questions }}</span>
    </div>
    <div class="border-t border-input border-dashed"></div>
    <div class="flex items-center justify-between flex-wrap my-2.5 gap-2">
        <span class="text-xs text-secondary-foreground uppercase">Percentage</span>
        <span class="text-sm text-mono font-semibold">{{ $pct }}%</span>
    </div>
    <div class="border-t border-input border-dashed"></div>
    <div class="flex items-center justify-between flex-wrap my-2.5 gap-2">
        <span class="text-xs text-secondary-foreground uppercase">Result</span>
        <span class="kt-badge kt-badge-outline kt-badge-{{ $score->is_passed ? 'success' : 'destructive' }}">{{ $score->is_passed ? 'Passed' : 'Failed' }}</span>
    </div>
    <div class="border-t border-input border-dashed"></div>
    <div class="flex items-center justify-between flex-wrap mt-2.5 gap-2">
        <span class="text-xs text-secondary-foreground uppercase">Warnings</span>
        <span class="kt-badge kt-badge-outline kt-badge-{{ $score->warning_attempts > 0 ? 'destructive' : 'secondary' }}">{{ $score->warning_attempts }}</span>
    </div>
</div>

<div class="grid gap-3 pb-5">
    <h4 class="text-sm font-semibold text-mono">Per-question review</h4>
    <div class="kt-scrollable-x-auto">
        <table class="kt-table table-auto kt-table-border">
            <thead>
                <tr>
                    <th class="min-w-[260px]">Question</th>
                    <th class="min-w-[140px]">Student Answer</th>
                    <th class="min-w-[140px]">Correct Answer</th>
                    <th class="min-w-[100px]">Result</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($reviewQuestions as $quiz)
                    @php
                        $given = $answers[$quiz->id] ?? ($answers[(string) $quiz->id] ?? null);
                        $correct = $quiz->correct_answer; // server-side only
                        $isCorrect = $given !== null && (string) $given === (string) $correct;
                    @endphp
                    <tr>
                        <td>{{ \Illuminate\Support\Str::limit($quiz->question, 60) }}</td>
                        <td>{{ $given ?? '—' }}</td>
                        <td>{{ $correct }}</td>
                        <td>
                            <span class="kt-badge rounded-full kt-badge-outline kt-badge-{{ $isCorrect ? 'success' : 'destructive' }} gap-1 items-center">
                                <span class="kt-badge-dot size-1.5"></span>{{ $isCorrect ? 'Correct' : 'Wrong' }}
                            </span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
