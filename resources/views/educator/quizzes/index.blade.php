@extends('educator.layout')
@section('title', 'Question Bank')
@section('heading', 'Question Bank')
@section('toolbar')
    <form id="quiz_bulk_form" method="POST" action="{{ route('educator.quizzes.bulk') }}"
          data-confirm="Delete the selected questions? This cannot be undone."
          data-confirm-title="Delete selected questions?">
        @csrf @method('DELETE')
        <button type="submit" class="kt-btn kt-btn-sm kt-btn-outline kt-btn-destructive" data-quiz-bulk-delete disabled>
            Bulk delete <span data-quiz-bulk-count>0</span>
        </button>
    </form>
    <button type="button" class="kt-btn kt-btn-sm kt-btn-outline" data-kt-modal-toggle="#kt_quiz_archive" @disabled($batches->isEmpty())>
        Archive batches
    </button>
    <button type="button" class="kt-btn kt-btn-sm kt-btn-secondary" data-kt-modal-toggle="#kt_quiz_upload">
        <i class="ki-filled ki-cloud-upload"></i> Upload File
    </button>
    <button type="button" class="kt-btn kt-btn-sm kt-btn-primary"
            data-modal-url="{{ route('educator.quizzes.create') }}" data-modal-target="#form_modal" data-modal-title="Add question">Add question</button>
@endsection
@section('content')
    @include('admin._status')
    <x-data-table id="quizzes_table" search-placeholder="Search questions" :paginator="$quizzes">
        <x-slot:filters>
            <select data-filter="section" class="kt-select w-40">
                <option value="">All sections</option>
                @foreach ($filterSections as $section)<option value="{{ $section->id }}">{{ $section->section_name }}</option>@endforeach
            </select>
            <select data-filter="subject" class="kt-select w-48">
                <option value="">All subjects</option>
                @foreach ($filterSubjects as $s)<option value="{{ $s->id }}">{{ $s->subject_code }} — {{ $s->subject_name }} ({{ optional($s->section)->section_name ?? 'No section' }})</option>@endforeach
            </select>
            <select data-filter="assessment" class="kt-select w-40">
                <option value="">All assessment codes</option>
                @foreach ($filterAssessments as $assessmentCode)<option value="{{ $assessmentCode }}">{{ $assessmentCode }}</option>@endforeach
            </select>
            <select data-filter="batch" class="kt-select w-56">
                <option value="">All batches</option>
                @foreach ($batches as $batch)<option value="{{ $batch->batch_label }}">{{ $batch->batch_label }}</option>@endforeach
            </select>
        </x-slot:filters>
        <x-slot:head>
            <thead>
                <tr>
                    <th class="w-[40px]"><input type="checkbox" class="kt-checkbox kt-checkbox-sm" data-quiz-select-all aria-label="Select all questions on this page"></th>
                    <th class="min-w-[240px]" data-sort="question"><span class="kt-table-col"><span class="kt-table-col-label">Question</span><span class="kt-table-col-sort"></span></span></th>
                    <th class="min-w-[140px]">Type</th>
                    <th class="min-w-[160px]">Subject</th>
                    <th class="min-w-[160px]">Answer</th>
                    <th class="min-w-[160px]">Used In</th>
                    <th class="min-w-[200px]">Batch</th>
                    <th class="w-[60px]"></th>
                </tr>
            </thead>
        </x-slot:head>
        @forelse ($quizzes as $q)
            <tr>
                <td><input type="checkbox" class="kt-checkbox kt-checkbox-sm" name="quiz_ids[]" value="{{ $q->id }}" form="quiz_bulk_form" data-quiz-select aria-label="Select question"></td>
                <td class="text-mono text-sm">{{ \Illuminate\Support\Str::limit($q->question, 70) }}</td>
                <td>
                    <span class="kt-badge kt-badge-outline">{{ $q->quiz_type === 'multiple_choice' ? 'Multiple Choice' : 'Identification' }}</span>
                </td>
                {{-- Task 32: a question may be shared across subjects. --}}
                <td>
                    <div class="flex flex-wrap gap-1">
                        @forelse ($q->subjects as $s)
                            <span class="kt-badge kt-badge-sm kt-badge-outline">{{ $s->subject_name }}@if($s->section)<span class="text-xs text-secondary-foreground ms-1">({{ $s->section->section_name }})</span>@endif</span>
                        @empty
                            <span class="text-secondary-foreground">-</span>
                        @endforelse
                    </div>
                </td>
                <td class="text-secondary-foreground text-sm">
                    @if ($q->quiz_type === 'multiple_choice')
                        {{ $q->correct_answer }}. {{ $q->choices[$q->correct_answer] ?? '' }}
                    @else
                        @php $ans = json_decode($q->correct_answer, true); @endphp
                        {{ is_array($ans) ? implode(', ', $ans) : $q->correct_answer }}
                    @endif
                </td>
                <td>
                    @forelse ($q->eligibleAssessments as $a)
                        <span class="kt-badge kt-badge-sm kt-badge-outline">{{ $a->assessment_code }}</span>
                    @empty
                        <span class="text-xs text-secondary-foreground">Not used yet</span>
                    @endforelse
                </td>
                <td class="text-xs text-secondary-foreground">{{ $q->batch_label ?? '—' }}</td>
                <td class="text-center">
                    <x-table-actions
                        :edit-modal="route('educator.quizzes.edit', $q)" edit-modal-title="Edit question"
                        :delete="route('educator.quizzes.destroy', $q)" confirm="Delete this question?" />
                </td>
            </tr>
        @empty
            <tr><td colspan="8" class="text-center text-secondary-foreground py-5">No questions yet. Use "Add question" or Bulk upload.</td></tr>
        @endforelse
    </x-data-table>

    <x-modal id="form_modal" width="640px" />

    <x-modal id="kt_quiz_archive" title="Archive Question Batches" width="960px">
        <form method="POST" action="{{ route('educator.quizzes.archive') }}" class="flex flex-col gap-4">
            @csrf @method('DELETE')

            <div class="flex flex-col gap-1">
                <label class="kt-form-label">Question batches</label>
                <label class="kt-input">
                    <i class="ki-filled ki-magnifier"></i>
                    <input type="text" placeholder="Search batches..." data-archive-batch-search @disabled($batches->isEmpty())>
                </label>
                <div class="flex items-center justify-between gap-3 text-xs text-secondary-foreground mt-1">
                    <span>Select one or more uploaded batches.</span>
                    <button type="button" class="kt-btn kt-btn-xs kt-btn-outline" data-archive-batch-select-all @disabled($batches->isEmpty())>
                        Select visible
                    </button>
                </div>
                <div class="border border-border rounded-xl overflow-hidden bg-background" style="max-height: 52vh;">
                    <div class="kt-scrollable-y" style="max-height: 52vh;" data-archive-batch-list>
                        @forelse ($batches as $batch)
                            <label class="flex items-start gap-3 px-4 py-3 border-b border-border last:border-b-0 cursor-pointer" data-archive-batch-option>
                                <input type="checkbox" class="kt-checkbox kt-checkbox-sm mt-1 shrink-0" name="batch_labels[]" value="{{ $batch->batch_label }}" @checked(in_array($batch->batch_label, old('batch_labels', []), true))>
                                <span class="flex flex-col gap-1 min-w-0">
                                    <span class="font-medium break-words">{{ $batch->batch_label }}</span>
                                    <span class="text-xs text-secondary-foreground">{{ $batch->question_count }} question{{ $batch->question_count === 1 ? '' : 's' }}</span>
                                </span>
                            </label>
                        @empty
                            <div class="px-4 py-6 text-sm text-secondary-foreground">No active batches are available to archive.</div>
                        @endforelse
                    </div>
                </div>
                <p class="text-sm text-secondary-foreground hidden" data-archive-batch-empty>No matching batches.</p>
                <p class="text-xs text-secondary-foreground">
                    Select one or more uploaded batches. Archiving moves every question in those batches to Archived Questions.
                </p>
            </div>

            <div class="flex justify-end gap-2 mt-1 sticky bottom-0 bg-background border-t border-border py-3">
                <button type="button" class="kt-btn kt-btn-outline" data-kt-modal-dismiss="true">Cancel</button>
                <button type="submit" class="kt-btn kt-btn-primary" data-archive-batch-submit
                        data-confirm="Archive the selected batches? Every question in the same batch will move to Archived Questions."
                        data-confirm-title="Archive selected batches?" @disabled($batches->isEmpty())>
                    Archive selected batches <span data-archive-batch-count>0</span>
                </button>
            </div>
        </form>
    </x-modal>

    {{-- Bulk upload: static form in the x-modal slot (same pattern as admin permissions' perm_add_modal). --}}
    <x-modal id="kt_quiz_upload" title="Upload Quiz Files" width="640px">
        <form method="POST" action="{{ route('educator.quizzes.upload') }}" enctype="multipart/form-data" class="flex flex-col gap-4">
            @csrf
            {{-- Template --}}
            <div class="flex items-center justify-between gap-3">
                <label class="kt-form-label">Template</label>
                <a href="{{ route('educator.quizzes.upload.template') }}" class="kt-btn kt-btn-sm kt-btn-outline shrink-0">
                    <i class="ki-filled ki-cloud-download"></i> Download
                </a>
            </div>

            {{-- Task 32: target subjects. One file uploaded for several subjects stays ONE set of
                 questions, shared through tbl_quiz_subject — no duplicate rows per destination.
                 A subject belongs to exactly one section, so picking across sections covers the
                 multi-section case without a second control. --}}
            <div class="flex flex-col gap-1.5">
                <label class="kt-form-label">Target Subjects</label>
                <details class="rounded-lg border border-border" open>
                    <summary class="flex items-center justify-between gap-2 px-4 py-3 cursor-pointer select-none list-none [&::-webkit-details-marker]:hidden">
                        <span class="text-sm text-mono" data-subject-summary data-subject-summary-default="Select one or more subjects">Select one or more subjects</span>
                        <i class="ki-filled ki-down text-sm text-muted-foreground"></i>
                    </summary>
                    <div class="grid grid-cols-1 gap-2.5 p-3 pt-0 max-h-60 overflow-y-auto kt-scrollable-y">
                        @foreach ($uploadSubjects as $s)
                            <x-checkbox-card
                                name="subject_ids[]"
                                :value="$s->id"
                                :title="$s->subject_name"
                                :desc="$s->subject_code . ' | ' . (optional($s->section)->section_name ?? 'No section')"
                                data-subject-option />
                        @endforeach
                    </div>
                </details>
            </div>

            {{-- Files --}}
            <div class="flex flex-col gap-1">
                <label class="kt-form-label">Quiz Files</label>
                <input id="quiz_files" type="file" name="files[]" multiple accept=".xlsx,.xls,.csv" class="kt-input" required>
                {{-- Queued files render as Metronic "Recent Uploads" rows (see _modal-loader). --}}
                <div class="grid gap-2.5 mt-1" data-file-list data-empty="No files selected.">
                    <span class="text-xs text-secondary-foreground">No files selected.</span>
                </div>
            </div>

            <div class="flex justify-end gap-2 mt-1 sticky bottom-0 bg-background border-t border-border py-3">
                <button type="button" class="kt-btn kt-btn-outline" data-kt-modal-dismiss="true">Cancel</button>
                <button type="submit" class="kt-btn kt-btn-primary">
                    <i class="ki-filled ki-cloud-upload"></i> Upload Quizzes
                </button>
            </div>
        </form>
    </x-modal>
@endsection

@push('scripts')
<script nonce="{{ $cspNonce ?? '' }}" data-ajax-rerun>
(function () {
    document.addEventListener('change', function (event) {
        var select = event.target.closest('select[data-filter]');
        if (select) {
            var next = {section: ['subject', 'assessment', 'batch'], subject: ['assessment', 'batch'], assessment: ['batch']}[select.dataset.filter] || [];
            next.forEach(function (name) {
                var child = document.querySelector('select[data-filter="' + name + '"]');
                if (child) child.value = '';
            });
        }

        if (event.target.matches('[data-quiz-select], [data-quiz-select-all]')) {
            var root = document.getElementById('quizzes_table_form');
            var boxes = root ? root.querySelectorAll('[data-quiz-select]') : [];
            if (event.target.matches('[data-quiz-select-all]')) boxes.forEach(function (box) { box.checked = event.target.checked; });
            var selected = Array.from(boxes).filter(function (box) { return box.checked; }).length;
            var bulkButton = document.querySelector('[data-quiz-bulk-delete]');
            bulkButton.disabled = selected === 0;
            bulkButton.querySelector('[data-quiz-bulk-count]').textContent = selected;
        }
    }, true);
    document.getElementById('quiz_bulk_form').addEventListener('submit', function (event) {
        if (!document.querySelector('[name="quiz_ids[]"]:checked')) event.preventDefault();
    });

    function syncArchiveBatchModal() {
        var modal = document.getElementById('kt_quiz_archive');
        if (!modal) return;
        var search = modal.querySelector('[data-archive-batch-search]');
        var rows = Array.from(modal.querySelectorAll('[data-archive-batch-option]'));
        var query = search ? search.value.trim().toLowerCase() : '';
        var visible = 0;

        rows.forEach(function (row) {
            var text = row.textContent.toLowerCase();
            var match = query === '' || text.indexOf(query) !== -1;
            row.classList.toggle('hidden', !match);
            if (match) visible += 1;
        });

        var empty = modal.querySelector('[data-archive-batch-empty]');
        if (empty) empty.classList.toggle('hidden', visible !== 0);

        var selected = modal.querySelectorAll('input[name="batch_labels[]"]:checked').length;
        var submit = modal.querySelector('[data-archive-batch-submit]');
        var count = modal.querySelector('[data-archive-batch-count]');
        if (submit) submit.disabled = selected === 0;
        if (count) count.textContent = selected;
    }

    document.addEventListener('input', function (event) {
        if (event.target.matches('[data-archive-batch-search]')) syncArchiveBatchModal();
    });

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-archive-batch-select-all]');
        if (!button) return;
        var modal = document.getElementById('kt_quiz_archive');
        if (!modal) return;
        modal.querySelectorAll('[data-archive-batch-option]').forEach(function (row) {
            if (!row.classList.contains('hidden')) {
                var checkbox = row.querySelector('input[name="batch_labels[]"]');
                if (checkbox) checkbox.checked = true;
            }
        });
        syncArchiveBatchModal();
    });

    document.addEventListener('change', function (event) {
        if (event.target.matches('#kt_quiz_archive input[name="batch_labels[]"]')) syncArchiveBatchModal();
    });

    syncArchiveBatchModal();
})();
</script>
@endpush
