@php
    $q = $quiz ?? null;
    $choices = old('choices', $q?->choices ?? ['A' => '', 'B' => '', 'C' => '', 'D' => '']);
    // Task 32: a question can be filed under several subjects. Defaults: the ones it is already
    // shared with (edit), or the subject the educator drilled in from (create).
    $selectedSubjectIds = collect(old('subject_ids', $q?->subjects->pluck('id')->all() ?? array_filter([$selectedSubject ?? null])))
        ->map(fn ($id) => (int) $id);
    $type = old('quiz_type', $q?->quiz_type ?? 'multiple_choice');
    $correct = old('correct_answer', $q?->correct_answer);
    // Identification answers: correct_answer may be a plain string or a JSON array of accepted answers.
    $idAnswers = old('answers');
    if ($idAnswers === null) {
        $decoded = is_string($correct) ? json_decode($correct, true) : null;
        $idAnswers = is_array($decoded) ? $decoded : ($correct !== null && $correct !== '' ? [$correct] : ['']);
    }
    $isMc = $type === 'multiple_choice';
@endphp
<div class="flex flex-col gap-5">
    <div class="flex flex-col gap-1.5">
        <label class="kt-form-label">Choose Subjects</label>
        <details class="rounded-lg border border-border" @if($errors->has('subject_ids') || $selectedSubjectIds->isEmpty()) open @endif>
            <summary class="flex items-center justify-between gap-2 px-4 py-3 cursor-pointer select-none list-none [&::-webkit-details-marker]:hidden">
                <span class="text-sm text-mono" data-subject-summary data-subject-summary-default="Select one or more subjects">
                    {{ $selectedSubjectIds->count() ? $selectedSubjectIds->count().' selected' : 'Select one or more subjects' }}
                </span>
                <i class="ki-filled ki-down text-sm text-muted-foreground"></i>
            </summary>
            <div class="grid grid-cols-1 gap-2.5 p-3 pt-0 max-h-72 overflow-y-auto kt-scrollable-y">
                @foreach ($subjects as $s)
                    <x-checkbox-card
                        name="subject_ids[]"
                        :value="$s->id"
                        :title="$s->subject_name"
                        :desc="$s->subject_code . ' | ' . (optional($s->section)->section_name ?? 'No section')"
                        :checked="$selectedSubjectIds->contains($s->id)"
                        data-subject-option />
                @endforeach
            </div>
        </details>
        @error('subject_ids')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
    </div>

    {{-- Quiz Type: full-width select drives which section shows (delegated JS on [data-quiz-type]). --}}
    <div class="flex flex-col gap-1">
        <label class="kt-form-label">Quiz Type</label>
        <select name="quiz_type" class="kt-select w-full" data-quiz-type>
            <option value="multiple_choice" @selected($isMc)>Multiple Choice</option>
            <option value="identification" @selected(! $isMc)>Identification</option>
        </select>
    </div>

    <div class="flex flex-col gap-1">
        <label class="kt-form-label">Question</label>
        <textarea name="question" class="kt-textarea" rows="2" placeholder="Enter the quiz question" required>{{ old('question', $q?->question) }}</textarea>
        @error('question')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
    </div>

    {{-- Multiple choice: text per choice + a radio to mark the single correct key. --}}
    <div class="flex flex-col gap-2" data-mc-choices @unless($isMc) hidden @endunless>
        <label class="kt-form-label">Choices</label>
        @foreach (['A','B','C','D'] as $key)
            <label class="flex items-center gap-3 rounded-lg border border-border p-3 cursor-pointer">
                <input type="radio" name="correct_answer" value="{{ $key }}" class="kt-radio shrink-0" @checked($correct===$key)>
                <span class="text-sm font-medium text-mono w-16 shrink-0">Choice {{ $key }}</span>
                <input name="choices[{{ $key }}]" class="kt-input" value="{{ $choices[$key] ?? '' }}" placeholder="Enter choice here">
            </label>
        @endforeach
        @error('choices')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
        @error('correct_answer')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
    </div>

    {{-- Identification: one or more accepted answers (repeater). --}}
    <div class="flex flex-col gap-2" data-id-answers @if($isMc) hidden @endif>
        <div class="flex items-center justify-between gap-2">
            <div class="flex flex-col">
                <label class="kt-form-label">Correct Answers</label>
                <span class="text-xs text-secondary-foreground">Add one or more accepted answers.</span>
            </div>
            <button type="button" class="kt-btn kt-btn-sm kt-btn-outline shrink-0" data-repeater-add="#id_answer_rows">
                <i class="ki-filled ki-plus"></i> Add Answer
            </button>
        </div>
        <div id="id_answer_rows" class="flex flex-col gap-2">
            @foreach ($idAnswers as $ans)
                <div class="flex items-center gap-2" data-repeater-row>
                    <input name="answers[]" class="kt-input" value="{{ $ans }}" placeholder="Enter correct answer">
                    <button type="button" class="kt-btn kt-btn-sm kt-btn-icon kt-btn-ghost text-destructive shrink-0" data-repeater-remove title="Remove answer">
                        <i class="ki-filled ki-trash"></i>
                    </button>
                </div>
            @endforeach
        </div>
    </div>

</div>
