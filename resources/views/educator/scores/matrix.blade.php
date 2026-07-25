{{-- Task 27: class score matrix — students down the side, ONE COLUMN PER ASSESSMENT generated
     from $assessments (never a fixed quiz list). Fragment inside the shared modal under ?modal=1.
     Read-only; the row menu opens that student's detail in the same modal. --}}
@php
    $isModal = request()->boolean('modal');
    $classParams = ['subject' => $subject->id, 'section' => $section->id, 'term' => $term->id];
@endphp
@extends($isModal ? 'layouts.fragment' : 'educator.layout')
@section('title', 'Class Scores')
@section('heading', 'Class Scores')
@section('content')
    <div class="kt-card">
        <div class="kt-card-content grid gap-5 py-5">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex flex-col gap-0.5">
                    <span class="text-sm text-mono font-semibold">{{ $subject->subject_code }} — {{ $subject->subject_name }}</span>
                    <span class="text-xs text-secondary-foreground">{{ $section->section_name }} · {{ $term->term_name }}</span>
                </div>
                <span class="text-xs text-secondary-foreground">{{ $students->count() }} {{ $students->count() === 1 ? 'student' : 'students' }} · {{ $assessments->count() }} {{ $assessments->count() === 1 ? 'assessment' : 'assessments' }}</span>
            </div>

            <div class="kt-scrollable-x-auto pb-5">
                <table class="kt-table table-auto kt-table-border">
                    <thead>
                        <tr>
                            <th class="min-w-[220px]">Student</th>
                            @foreach ($assessments as $assessment)
                                <th class="min-w-[110px]">{{ $assessment->assessment_code }}</th>
                            @endforeach
                            <th class="w-[60px]"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($students as $student)
                            <tr>
                                <td>
                                    <div class="flex flex-col gap-0.5">
                                        <span class="text-sm text-mono font-semibold">{{ trim(($student->surname ?? '').', '.($student->given_name ?? ''), ', ') ?: '—' }}</span>
                                        <span class="text-xs text-secondary-foreground">{{ $student->user_id ?? '—' }}</span>
                                    </div>
                                </td>
                                @foreach ($assessments as $assessment)
                                    @php $cell = $cells[$student->id][$assessment->id] ?? null; @endphp
                                    <td>
                                        @if ($cell && $cell['best'])
                                            <div class="flex flex-col gap-0.5">
                                                <span class="text-sm text-mono font-semibold">{{ $cell['best']->score ?? 0 }}/{{ $cell['best']->total_questions ?? 0 }}</span>
                                                <span class="text-xs text-secondary-foreground">{{ $cell['attempts'] }} {{ $cell['attempts'] === 1 ? 'attempt' : 'attempts' }}</span>
                                            </div>
                                        @else
                                            <span class="text-secondary-foreground">—</span>
                                        @endif
                                    </td>
                                @endforeach
                                <td class="text-center">
                                    <x-table-actions
                                        :view-modal="route('educator.scores.student', $classParams + ['student' => $student->id])"
                                        view-modal-title="{{ trim(($student->surname ?? '').', '.($student->given_name ?? ''), ', ') }}" />
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="{{ $assessments->count() + 2 }}" class="text-center text-secondary-foreground py-5">No students enrolled in this subject yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @unless ($isModal)
            <div class="kt-card-footer justify-end">
                <a href="{{ route('educator.scores.index') }}" class="kt-btn kt-btn-outline">Back</a>
            </div>
        @endunless
    </div>
@endsection
