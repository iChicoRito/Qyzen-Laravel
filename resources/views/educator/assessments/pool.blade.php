{{-- Task 51: question pool config for one assessment — pick eligible bank questions + draw
     size N. Full page (not a modal fragment): the checkbox-card grid needs room to breathe. --}}
@extends('educator.layout')
@section('title', 'Question Pool')
@section('heading', 'Question Pool — ' . $assessment->assessment_code)
@section('content')
    @include('admin._status')
    <div class="kt-card">
        <div class="kt-card-content p-5">
            <form method="POST" action="{{ route('educator.assessments.pool.update', $assessment) }}" class="flex flex-col gap-5">
                @csrf
                @method('PUT')

                @if ($bankQuestions->isEmpty())
                    <div class="kt-alert kt-alert-warning">
                        No bank questions yet.
                        <a href="{{ route('educator.quizzes.create', ['subject_id' => $assessment->subject_id]) }}" class="kt-link">Add one</a>.
                    </div>
                @endif

                @php $selectedIds = old('eligible_quiz_ids', $eligibleIds); @endphp
                <div class="flex flex-col gap-1.5">
                    <div class="flex items-center justify-between gap-2">
                        <label class="kt-form-label">Eligible Questions</label>
                        @if ($bankQuestions->isNotEmpty())
                            <div class="flex gap-2">
                                <button type="button" class="kt-btn kt-btn-sm kt-btn-outline" data-pool-select-all>Select all ({{ count($allFilteredIds) }})</button>
                                <button type="button" class="kt-btn kt-btn-sm kt-btn-outline" data-pool-select-none>Select None</button>
                            </div>
                        @endif
                    </div>
                    @if ($bankTotal > 8)
                        @php
                            // Task 32: a bank question may be filed under several subjects.
                            $filterSubjects = $bankQuestions->flatMap->subjects->filter()->unique('id')->sortBy('subject_code');
                            $filterSections = $bankQuestions->flatMap->subjects->pluck('section')->filter()->unique('id')->sortBy('section_name');
                        @endphp
                        <div class="flex flex-wrap items-center gap-2">
                            <label class="kt-input grow min-w-52">
                                <i class="ki-filled ki-magnifier"></i>
                                <input type="text" placeholder="Search questions in this bank…" data-pool-search autocomplete="off">
                            </label>
                            <select class="kt-select w-40" data-pool-filter-type>
                                <option value="">All types</option>
                                <option value="multiple_choice">Multiple Choice</option>
                                <option value="identification">Identification</option>
                            </select>
                            {{-- Task 13: batch filters server-side across the whole bank (navigate on change,
                                 carrying selections) so matches on any page show, not just the current one. --}}
                            <select class="kt-select w-56" data-pool-filter-batch>
                                <option value="">All batches</option>
                                @foreach ($batches as $b)<option value="{{ $b }}" @selected(request('batch') === $b)>{{ $b }}</option>@endforeach
                            </select>
                            <select class="kt-select w-48" data-pool-filter-subject>
                                <option value="">All subjects</option>
                                @foreach ($filterSubjects as $subject)<option value="{{ $subject->id }}" data-pool-subject-section="{{ $subject->sections_id }}">{{ $subject->subject_code }} — {{ $subject->subject_name }}</option>@endforeach
                            </select>
                            <select class="kt-select w-40" data-pool-filter-section>
                                <option value="">All sections</option>
                                @foreach ($filterSections as $section)<option value="{{ $section->id }}">{{ $section->section_name }}</option>@endforeach
                            </select>
                        </div>
                    @endif
                    <span class="text-xs text-secondary-foreground" data-pool-summary>
                        {{ count($selectedIds) }} of {{ $bankTotal }} selected
                    </span>
                    @php $visibleIds = $bankQuestions->pluck('id')->all(); @endphp
                    @foreach (array_diff($selectedIds, $visibleIds) as $selectedId)
                        <input type="hidden" name="eligible_quiz_ids[]" value="{{ $selectedId }}">
                    @endforeach
                    <div class="grid grid-cols-1 gap-2.5 overflow-y-auto kt-scrollable-y" style="height: 60vh; max-height: 60vh; overflow-y: auto;">
                        @foreach ($bankQuestions as $q)
                            @php
                                $usedIn = $q->eligibleAssessments->where('id', '!=', $assessment->id)->pluck('assessment_code');
                                $desc = $q->quiz_type === 'multiple_choice' ? 'Multiple Choice' : 'Identification';
                                $origin = $q->subjects->map(function ($s) {
                                    $label = trim($s->subject_code.' — '.$s->subject_name);

                                    return $s->section ? $label.' ('.$s->section->section_name.')' : $label;
                                })->implode(', ');
                                $desc .= $origin !== '' ? ' · '.$origin : '';
                                $desc .= $usedIn->isNotEmpty() ? ' · Also used in: '.$usedIn->join(', ') : ' · Not used elsewhere';
                            @endphp
                            <x-checkbox-card
                                name="eligible_quiz_ids[]"
                                :value="$q->id"
                                :title="\Illuminate\Support\Str::limit($q->question, 80)"
                                :desc="$desc"
                                :checked="in_array($q->id, $selectedIds)"
                                data-pool-option
                                data-pool-question-text="{{ mb_strtolower($q->question) }}"
                                data-pool-question-type="{{ $q->quiz_type }}"
                                {{-- Space-delimited: one question may belong to several subjects/sections. --}}
                                data-pool-question-subject="{{ $q->subjects->pluck('id')->implode(' ') }}"
                                data-pool-question-section="{{ $q->subjects->pluck('sections_id')->unique()->implode(' ') }}"
                                data-pool-question-batch="{{ $q->batch_label }}" />
                        @endforeach
                        <span class="text-xs text-secondary-foreground px-1 hidden" data-pool-no-match>No questions match your filters.</span>
                    </div>
                    @error('eligible_quiz_ids')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                </div>

                <div class="flex flex-col gap-1 max-w-xs">
                    <label class="kt-form-label">Draw Size (N)</label>
                    <input type="number" name="pool_size" class="kt-input" min="0"
                           value="{{ old('pool_size', $assessment->pool_size) }}" required>
                    <span class="text-xs text-secondary-foreground">How many questions each student randomly gets per attempt.</span>
                    @error('pool_size')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                </div>

                <div class="flex gap-2">
                    <button class="kt-btn kt-btn-primary">Save Pool</button>
                    <a href="{{ route('educator.assessments.index') }}" class="kt-btn kt-btn-outline">Back</a>
                </div>
            </form>
        </div>
    </div>

    @push('scripts')
    <script nonce="{{ $cspNonce ?? '' }}" data-ajax-rerun>
        (function () {
            var summary = document.querySelector('[data-pool-summary]');
            if (!summary) return;
            var form = summary.closest('form');
            var bankTotal = {{ $bankTotal }};

            // A selected id is either a ticked on-page checkbox or an off-page hidden input; count both.
            function countSelected() {
                return form.querySelectorAll('input[data-pool-option]:checked').length
                    + form.querySelectorAll('input[type="hidden"][name="eligible_quiz_ids[]"]').length;
            }
            function updateSummary() {
                summary.textContent = countSelected() + ' of ' + bankTotal + ' selected';
            }

            form.querySelectorAll('input[data-pool-option]').forEach(function (box) {
                box.addEventListener('change', updateSummary);
            });

            // Select All picks every question currently visible after the active filters.
            var selectAll = document.querySelector('[data-pool-select-all]');
            var selectNone = document.querySelector('[data-pool-select-none]');
            var boxes = form.querySelectorAll('input[data-pool-option]');
            if (selectAll) {
                selectAll.addEventListener('click', function () {
                    boxes.forEach(function (box) {
                        var label = box.closest('label');
                        if (!label || !label.classList.contains('hidden')) box.checked = true;
                    });
                    updateSummary();
                });
            }
            if (selectNone) {
                selectNone.addEventListener('click', function () {
                    form.querySelectorAll('input[data-pool-option]').forEach(function (b) { b.checked = false; });
                    form.querySelectorAll('input[type="hidden"][name="eligible_quiz_ids[]"]').forEach(function (h) { h.remove(); });
                    updateSummary();
                });
            }

            // Search + Type stay client-side; Batch is server-side.
            var search = document.querySelector('[data-pool-search]');
            var typeFilter = document.querySelector('[data-pool-filter-type]');
            var batchFilter = document.querySelector('[data-pool-filter-batch]');
            var subjectFilter = document.querySelector('[data-pool-filter-subject]');
            var sectionFilter = document.querySelector('[data-pool-filter-section]');
            var noMatch = document.querySelector('[data-pool-no-match]');

            function applyFilters() {
                var term = search ? search.value.trim().toLowerCase() : '';
                var type = typeFilter ? typeFilter.value : '';
                var subject = subjectFilter ? subjectFilter.value : '';
                var section = sectionFilter ? sectionFilter.value : '';
                var visible = 0;
                // Task 32: the subject/section datasets are space-delimited id lists — a question
                // may be shared across subjects — so this is an any-of test, not equality.
                function has(list, value) {
                    return (list || '').split(' ').indexOf(value) !== -1;
                }
                boxes.forEach(function (box) {
                    var label = box.closest('label');
                    var match = (!term || (box.dataset.poolQuestionText || '').indexOf(term) !== -1)
                        && (!type || box.dataset.poolQuestionType === type)
                        && (!subject || has(box.dataset.poolQuestionSubject, subject))
                        && (!section || has(box.dataset.poolQuestionSection, section));
                    if (label) label.classList.toggle('hidden', !match);
                    if (match) visible++;
                });
                if (noMatch) noMatch.classList.toggle('hidden', visible !== 0);
            }

            function updateLinkedFilters(source) {
                if (!subjectFilter || !sectionFilter) return;

                if (source === sectionFilter) {
                    Array.from(subjectFilter.options).forEach(function (option) {
                        option.hidden = !!sectionFilter.value && option.value !== ''
                            && option.dataset.poolSubjectSection !== sectionFilter.value;
                    });
                    if (subjectFilter.selectedOptions[0] && subjectFilter.selectedOptions[0].hidden) subjectFilter.value = '';
                }

                if (source === subjectFilter) {
                    var option = subjectFilter.options[subjectFilter.selectedIndex];
                    var sectionId = option ? option.dataset.poolSubjectSection : '';
                    Array.from(sectionFilter.options).forEach(function (section) {
                        section.hidden = !!sectionId && section.value !== '' && section.value !== sectionId;
                    });
                    sectionFilter.value = sectionId || '';
                }
            }
            if (search) search.addEventListener('input', applyFilters);
            if (typeFilter) typeFilter.addEventListener('change', applyFilters);
            if (subjectFilter) subjectFilter.addEventListener('change', function () {
                updateLinkedFilters(subjectFilter);
                applyFilters();
            });
            if (sectionFilter) sectionFilter.addEventListener('change', function () {
                updateLinkedFilters(sectionFilter);
                applyFilters();
            });

            // Carry selections through batch changes so changing the filter never drops ticked questions.
            function urlWithSelections(base) {
                var url = new URL(base, window.location.origin);
                url.searchParams.delete('selected[]');
                var seen = {};
                form.querySelectorAll('input[data-pool-option]:checked, input[type="hidden"][name="eligible_quiz_ids[]"]').forEach(function (el) {
                    if (!seen[el.value]) { seen[el.value] = 1; url.searchParams.append('selected[]', el.value); }
                });
                return url;
            }

            // Task 13: batch filters the whole bank server-side; selections ride along as selected[].
            if (batchFilter) {
                batchFilter.addEventListener('change', function () {
                    var url = urlWithSelections(window.location.href);
                    if (batchFilter.value) url.searchParams.set('batch', batchFilter.value);
                    else url.searchParams.delete('batch');
                    window.location.href = url.toString();
                });
            }
        })();
    </script>
    @endpush
@endsection
