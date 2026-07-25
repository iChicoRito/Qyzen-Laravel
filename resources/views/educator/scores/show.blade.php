{{-- G7: attempt detail. correct_answer rendered here is server-side (educator view) — never served
     to a student. Read-only; educator may grant a retake. Fragment inside the shared modal under ?modal=1.
     Layout mirrors demo1 public-profile/teams.html team card. --}}
@php $isModal = request()->boolean('modal'); @endphp
@extends($isModal ? 'layouts.fragment' : 'educator.layout')
@section('title', 'Attempt Detail')
@section('heading', 'Attempt Detail')
@section('content')
    @include('admin._status')
    <div class="kt-card">
        <div class="kt-card-content grid gap-7 py-7.5">
            {{-- Centered identity --}}
            <div class="grid place-items-center gap-4">
                <div class="flex justify-center items-center size-14 rounded-full ring-1 ring-input bg-accent">
                    <i class="ki-filled ki-medal-star text-2xl text-muted-foreground"></i>
                </div>
                <div class="grid place-items-center">
                    <span class="text-base font-medium text-mono mb-px">{{ optional($score->student)->name }}</span>
                    <span class="text-sm text-secondary-foreground text-center">{{ optional($score->student)->user_id }} · {{ optional($score->assessment)->assessment_code }}</span>
                </div>
            </div>

            {{-- Summary rows + per-question review (shared with the per-student detail) --}}
            @include('educator.scores._attempt', ['score' => $score, 'reviewQuestions' => $reviewQuestions])

            {{-- Grant retake --}}
            <form method="POST" action="{{ route('educator.scores.grant-retake') }}" class="flex gap-2 items-end">@csrf
                <input type="hidden" name="assessment_id" value="{{ $score->assessment_id }}">
                <input type="hidden" name="student_id" value="{{ $score->student_id }}">
                <div class="flex flex-col gap-1">
                    <label class="kt-form-label">Grant retakes</label>
                    <input type="number" name="extra_retake_count" class="kt-input w-40" min="1" value="1">
                </div>
                <button class="kt-btn kt-btn-primary">Grant retake</button>
            </form>
        </div>
        @unless ($isModal)
            {{-- In-modal, closing is via the modal header ✕ (the grant-retake form is auto-pinned as
                 the sticky action row by _modal-loader, so a second footer button would clash). --}}
            <div class="kt-card-footer justify-end">
                <a href="{{ route('educator.scores.index') }}" class="kt-btn kt-btn-outline">Back</a>
            </div>
        @endunless
    </div>
@endsection
