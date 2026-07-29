@extends('educator.layout')
@section('title', 'Archived Assessments')
@section('heading', 'Archived Assessments')
@section('toolbar')
    <form id="assessment_restore_form" method="POST" action="{{ route('educator.assessments.archived.restore') }}">
        @csrf
        @method('PATCH')
        <button type="submit" class="kt-btn kt-btn-sm kt-btn-outline" data-archived-restore disabled>
            Restore selected <span data-archived-restore-count>0</span>
        </button>
    </form>
@endsection
@section('content')
    @include('admin._status')

    <x-data-table id="archived_assessments_table" search-placeholder="Search archived assessments" :paginator="$assessments">
        <x-slot:head>
            <thead>
                <tr>
                    <th class="w-[40px]"><input type="checkbox" class="kt-checkbox kt-checkbox-sm" data-archived-select-all aria-label="Select all archived assessments on this page"></th>
                    <th class="min-w-[120px]" data-sort="code"><span class="kt-table-col"><span class="kt-table-col-label">Code</span><span class="kt-table-col-sort"></span></span></th>
                    <th class="min-w-[170px]">Subject</th>
                    <th class="min-w-[140px]">Section</th>
                    <th class="min-w-[120px]">Term</th>
                    <th class="min-w-[140px]" data-sort="scores"><span class="kt-table-col"><span class="kt-table-col-label">Scores kept</span><span class="kt-table-col-sort"></span></span></th>
                    <th class="min-w-[200px]">Window</th>
                </tr>
            </thead>
        </x-slot:head>
        @forelse ($assessments as $a)
            <tr>
                <td><input type="checkbox" class="kt-checkbox kt-checkbox-sm" name="assessment_ids[]" value="{{ $a->id }}" form="assessment_restore_form" data-archived-assessment-select aria-label="Select archived assessment {{ $a->assessment_code }}"></td>
                <td class="text-mono font-medium text-sm">{{ $a->assessment_code }}</td>
                <td>
                    @if ($a->subject)
                        <div class="flex flex-col gap-1">
                            <span>{{ $a->subject->subject_name }}</span>
                            <span class="text-xs text-secondary-foreground">{{ $a->subject->subject_code }}</span>
                        </div>
                    @else
                        <span class="text-secondary-foreground">-</span>
                    @endif
                </td>
                <td>{{ $a->section?->section_name ?? '-' }}</td>
                <td>{{ $a->academicTerm?->term_name ?? '-' }}</td>
                <td class="text-secondary-foreground">{{ $a->scores_count }} score(s) retained</td>
                <td class="text-secondary-foreground">{{ $a->start_date?->format('Y-m-d') }} → {{ $a->end_date?->format('Y-m-d') }}</td>
            </tr>
        @empty
            <tr><td colspan="7" class="text-center text-secondary-foreground py-5">No archived assessments.</td></tr>
        @endforelse
    </x-data-table>
@endsection

@push('scripts')
<script nonce="{{ $cspNonce ?? '' }}" data-ajax-rerun>
(function () {
    if (window.qyzenArchivedAssessmentRestoreBound) return;
    window.qyzenArchivedAssessmentRestoreBound = true;

    function syncArchivedRestoreState() {
        var selected = document.querySelectorAll('[data-archived-assessment-select]:checked').length;
        var button = document.querySelector('[data-archived-restore]');
        var count = document.querySelector('[data-archived-restore-count]');
        if (!button || !count) return;
        button.disabled = selected === 0;
        count.textContent = selected;
    }

    document.addEventListener('change', function (event) {
        if (event.target.matches('[data-archived-assessment-select], [data-archived-select-all]')) {
            if (event.target.matches('[data-archived-select-all]')) {
                document.querySelectorAll('[data-archived-assessment-select]').forEach(function (box) {
                    box.checked = event.target.checked;
                });
            }
            syncArchivedRestoreState();
        }
    }, true);

    document.addEventListener('submit', function (event) {
        var form = event.target.closest('#assessment_restore_form');
        if (!form) return;
        if (!document.querySelector('[data-archived-assessment-select]:checked')) event.preventDefault();
    });

    syncArchivedRestoreState();
})();
</script>
@endpush
