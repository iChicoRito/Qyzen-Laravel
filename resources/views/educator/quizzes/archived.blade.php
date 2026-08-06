@extends('educator.layout')
@section('title', 'Archived Questions')
@section('heading', 'Archived Questions')
@section('toolbar')
    <form id="quiz_restore_form" method="POST" action="{{ route('educator.quizzes.archived.restore') }}">
        @csrf
        @method('PATCH')
        <button type="submit" class="kt-btn kt-btn-sm kt-btn-outline" data-archived-restore disabled>
            Restore selected <span data-archived-restore-count>0</span>
        </button>
    </form>
@endsection
@section('content')
    @include('admin._status')

    <x-data-table id="archived_quizzes_table" search-placeholder="Search archived batches or questions" :paginator="$groups">
        <x-slot:filters>
            <select data-filter="section" class="kt-select w-40">
                <option value="">All sections</option>
                @foreach ($filterSections as $section)<option value="{{ $section->id }}">{{ $section->section_name }}</option>@endforeach
            </select>
            <select data-filter="subject" class="kt-select w-48">
                <option value="">All subjects</option>
                @foreach ($filterSubjects as $subject)<option value="{{ $subject->id }}">{{ $subject->subject_code }} - {{ $subject->subject_name }}</option>@endforeach
            </select>
            <select data-filter="batch" class="kt-select w-56">
                <option value="">All batches</option>
                @foreach ($filterBatches as $batch)<option value="{{ $batch['label'] }}">{{ $batch['label'] }} ({{ $batch['count'] }})</option>@endforeach
            </select>
        </x-slot:filters>
        <x-slot:head>
            <thead>
                <tr>
                    <th class="w-[40px]"><input type="checkbox" class="kt-checkbox kt-checkbox-sm" data-archived-select-all aria-label="Select all archived batches on this page"></th>
                    <th class="min-w-[240px]">Batch</th>
                    <th class="min-w-[170px]">Subject</th>
                    <th class="min-w-[140px]">Section</th>
                    <th class="min-w-[140px]">Questions</th>
                    <th class="min-w-[180px]">Used In</th>
                    <th class="min-w-[160px]">Archived</th>
                </tr>
            </thead>
        </x-slot:head>
        @forelse ($groups as $group)
            <tr>
                <td>
                    <input type="checkbox" class="kt-checkbox kt-checkbox-sm" name="batch_labels[]" value="{{ $group['key'] }}" form="quiz_restore_form" data-archived-batch-select aria-label="Select archived batch">
                </td>
                <td>
                    <div class="flex flex-col gap-1">
                        <span class="font-medium">{{ $group['label'] }}</span>
                        <span class="text-xs text-secondary-foreground">{{ $group['count'] }} question(s)</span>
                    </div>
                </td>
                {{-- Task 32: a batch may be shared across subjects. --}}
                <td>
                    <div class="flex flex-wrap gap-1">
                        @forelse ($group['subjects'] as $subject)
                            <span class="kt-badge kt-badge-sm kt-badge-outline" title="{{ $subject->subject_code }}">{{ $subject->subject_name }}</span>
                        @empty
                            <span class="text-secondary-foreground">-</span>
                        @endforelse
                    </div>
                </td>
                <td>{{ $group['sections']->pluck('section_name')->implode(', ') ?: '-' }}</td>
                <td class="text-secondary-foreground">{{ $group['count'] }}</td>
                <td>
                    @forelse ($group['assessments'] as $assessmentCode)
                        <span class="kt-badge kt-badge-sm kt-badge-outline">{{ $assessmentCode }}</span>
                    @empty
                        <span class="text-xs text-secondary-foreground">Not used yet</span>
                    @endforelse
                </td>
                <td class="text-secondary-foreground">{{ optional($group['deleted_at'])->format('Y-m-d H:i') }}</td>
            </tr>
        @empty
            <tr><td colspan="7" class="text-center text-secondary-foreground py-5">No archived questions.</td></tr>
        @endforelse
    </x-data-table>
@endsection

@push('scripts')
<script nonce="{{ $cspNonce ?? '' }}" data-ajax-rerun>
(function () {
    if (window.qyzenArchivedQuizRestoreBound) return;
    window.qyzenArchivedQuizRestoreBound = true;

    function syncArchivedRestoreState() {
        var boxes = document.querySelectorAll('[data-archived-batch-select]');
        var selected = Array.from(boxes).filter(function (box) { return box.checked; }).length;
        var button = document.querySelector('[data-archived-restore]');
        var count = document.querySelector('[data-archived-restore-count]');
        if (!button || !count) return;
        button.disabled = selected === 0;
        count.textContent = selected;
    }

    document.addEventListener('change', function (event) {
        if (event.target.matches('[data-archived-batch-select], [data-archived-select-all]')) {
            if (event.target.matches('[data-archived-select-all]')) {
                document.querySelectorAll('[data-archived-batch-select]').forEach(function (box) {
                    box.checked = event.target.checked;
                });
            }
            syncArchivedRestoreState();
        }
    }, true);

    document.addEventListener('submit', function (event) {
        var form = event.target.closest('#quiz_restore_form');
        if (!form) return;
        if (!document.querySelector('[data-archived-batch-select]:checked')) event.preventDefault();
    });

    syncArchivedRestoreState();
})();
</script>
@endpush
