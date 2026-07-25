{{-- Task 27: one student's full history for a class — every attempt of every assessment, its
     submitted answers, and the retake grant. Replaces the matrix inside the same modal (Back
     re-loads the matrix). G7: read-only; correct_answer is server-side (educator view) only.
     Exactly ONE submit button lives here on purpose — _modal-loader pins the first submit's row
     as the modal's sticky footer, so a per-attempt form would pin the wrong row. --}}
@php
    $isModal = request()->boolean('modal');
    $classParams = ['subject' => $subject->id, 'section' => $section->id, 'term' => $term->id];
    $initial = strtoupper(mb_substr($student->surname ?: ($student->given_name ?: '?'), 0, 1));
@endphp
@extends($isModal ? 'layouts.fragment' : 'educator.layout')
@section('title', 'Student Scores')
@section('heading', 'Student Scores')
@section('content')
    <div class="kt-card">
        <div class="kt-card-content grid gap-6 py-6">
            {{-- Identity --}}
            <div class="flex items-center gap-3">
                @if ($student->profile_picture)
                    <img class="rounded-full size-11 shrink-0" src="{{ \Illuminate\Support\Facades\Storage::disk('profile_media')->url($student->profile_picture) }}" alt="{{ $student->name }}" />
                @else
                    <span class="inline-flex items-center justify-center rounded-full size-11 shrink-0 bg-primary/10 text-primary text-base font-semibold">{{ $initial }}</span>
                @endif
                <div class="flex flex-col gap-0.5 grow min-w-0">
                    <span class="text-sm text-mono font-semibold">{{ trim(($student->surname ?? '').', '.($student->given_name ?? ''), ', ') }}</span>
                    <span class="text-xs text-secondary-foreground">{{ $student->user_id }} · {{ $subject->subject_code }} · {{ $section->section_name }} · {{ $term->term_name }}</span>
                </div>
                {{-- In-modal the loader intercepts data-modal-url and swaps the body in place;
                     on the standalone page the href just navigates. --}}
                <a href="{{ route('educator.scores.matrix', $classParams) }}" class="kt-btn kt-btn-sm kt-btn-outline shrink-0"
                   data-modal-url="{{ route('educator.scores.matrix', $classParams) }}"
                   data-modal-target="#form_modal"
                   data-modal-title="{{ $subject->subject_code }} · {{ $section->section_name }} · {{ $term->term_name }}">
                    <i class="ki-filled ki-arrow-left"></i> Back to class scores
                </a>
            </div>

            {{-- Attempts, grouped by assessment (newest attempt first within each). --}}
            @php $byAssessment = $attempts->groupBy('assessment_id'); @endphp
            <div class="grid gap-5">
                @foreach ($assessments as $assessment)
                    @php $assessmentAttempts = $byAssessment->get($assessment->id, collect()); @endphp
                    <div class="grid gap-2">
                        <div class="flex items-center justify-between gap-2">
                            <h4 class="text-sm font-semibold text-mono">{{ $assessment->assessment_code }}</h4>
                            <span class="text-xs text-secondary-foreground">{{ $assessmentAttempts->count() }} {{ $assessmentAttempts->count() === 1 ? 'attempt' : 'attempts' }}</span>
                        </div>
                        @forelse ($assessmentAttempts as $attempt)
                            {{-- Native <details>: expandable with no JS (injected scripts never run in the modal). --}}
                            <details class="border border-input rounded-lg px-4 py-3" data-score-row="{{ $attempt->uuid }}">
                                <summary class="flex items-center justify-between gap-2 cursor-pointer text-sm">
                                    <span class="text-mono font-semibold">{{ $attempt->score }}/{{ $attempt->total_questions }}</span>
                                    <span class="flex items-center gap-2">
                                        <span class="kt-badge kt-badge-sm kt-badge-outline kt-badge-{{ $attempt->is_passed ? 'success' : 'destructive' }}">{{ $attempt->is_passed ? 'Passed' : 'Failed' }}</span>
                                        <span class="text-xs text-secondary-foreground">{{ optional($attempt->submitted_at)->format('Y-m-d H:i') ?? '—' }}</span>
                                    </span>
                                </summary>
                                <div class="grid gap-4 pt-4">
                                    @include('educator.scores._attempt', [
                                        'score' => $attempt,
                                        'reviewQuestions' => collect($attempt->drawn_quiz_ids ?? [])->map(fn ($id) => $questions->get($id))->filter(),
                                    ])
                                    <div class="flex justify-end">
                                        {{-- type=button: keeps this out of the sticky-footer selector and
                                             out of the retake form. Handler is the index page's delegated
                                             AJAX delete + Undo toast. --}}
                                        <button type="button" class="kt-btn kt-btn-sm kt-btn-outline text-destructive"
                                                data-score-delete data-score-delete-url="{{ route('educator.scores.destroy', $attempt) }}">
                                            <i class="ki-filled ki-trash"></i> Delete Score
                                        </button>
                                    </div>
                                </div>
                            </details>
                        @empty
                            <p class="text-sm text-secondary-foreground">No submission.</p>
                        @endforelse
                    </div>
                @endforeach
            </div>

            {{-- Grant retake — the single submit in this fragment, pinned as the modal footer. --}}
            <form method="POST" action="{{ route('educator.scores.grant-retake') }}" class="flex flex-wrap gap-2 items-end">@csrf
                <input type="hidden" name="student_id" value="{{ $student->id }}">
                <div class="flex flex-col gap-1">
                    <label class="kt-form-label">Assessment</label>
                    <select name="assessment_id" class="kt-select w-48" required>
                        @foreach ($assessments as $assessment)
                            <option value="{{ $assessment->id }}">{{ $assessment->assessment_code }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex flex-col gap-1">
                    <label class="kt-form-label">Grant retakes</label>
                    <input type="number" name="extra_retake_count" class="kt-input w-32" min="1" value="1">
                </div>
                <button class="kt-btn kt-btn-primary">Grant retake</button>
            </form>
        </div>
        @unless ($isModal)
            <div class="kt-card-footer justify-end">
                <a href="{{ route('educator.scores.index') }}" class="kt-btn kt-btn-outline">Back</a>
            </div>
        @endunless
    </div>
@endsection
