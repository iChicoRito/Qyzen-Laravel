@extends('educator.layout')
@section('title', 'Assessments')
@section('heading', 'Assessments')
@section('toolbar')
    <form id="assessment_bulk_form" method="POST" action="{{ route('educator.assessments.archive') }}">
        @csrf
        @method('PATCH')
        <button type="submit" class="kt-btn kt-btn-sm kt-btn-outline" data-assessment-bulk disabled
                data-confirm="Archive the selected assessment(s)? Student scores are kept and stay visible on the Scores pages."
                data-confirm-title="Archive assessments">
            Archive selected <span data-assessment-bulk-count>0</span>
        </button>
    </form>
    <button type="button" class="kt-btn kt-btn-sm kt-btn-primary"
            data-modal-url="{{ route('educator.assessments.create') }}" data-modal-target="#form_modal" data-modal-title="Add assessment">Add assessment</button>
@endsection
@section('content')
    @include('admin._status')
    <x-data-table id="assessments_table" search-placeholder="Search assessments" :paginator="$assessments">
        <x-slot:filters>
            <select data-filter="subject" data-depends-on="section" class="kt-select w-36">
                <option value="">All subjects</option>
                @foreach ($filterSubjects as $s)<option value="{{ $s->id }}">{{ $s->subject_code }} — {{ $s->subject_name }}</option>@endforeach
            </select>
            <select data-filter="section" class="kt-select w-32">
                <option value="">All sections</option>
                @foreach ($filterSections as $s)<option value="{{ $s->id }}">{{ $s->section_name }}</option>@endforeach
            </select>
            <select data-filter="assessment" data-depends-on="subject" class="kt-select w-40">
                <option value="">All assessment codes</option>
                @foreach ($filterAssessments as $assessmentCode)<option value="{{ $assessmentCode }}">{{ $assessmentCode }}</option>@endforeach
            </select>
            <select data-filter="status" class="kt-select w-32">
                <option value="">All statuses</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
            </select>
        </x-slot:filters>
        <x-slot:head>
            <thead>
                <tr>
                    <th class="w-[40px]"><input type="checkbox" class="kt-checkbox kt-checkbox-sm" data-assessment-select-all aria-label="Select all assessments on this page"></th>
                    <th class="min-w-[120px]" data-sort="code"><span class="kt-table-col"><span class="kt-table-col-label">Code</span><span class="kt-table-col-sort"></span></span></th>
                    <th class="min-w-[120px]" data-sort="subject"><span class="kt-table-col"><span class="kt-table-col-label">Subject</span><span class="kt-table-col-sort"></span></span></th>
                    <th class="min-w-[140px]" data-sort="section"><span class="kt-table-col"><span class="kt-table-col-label">Section</span><span class="kt-table-col-sort"></span></span></th>
                    <th class="min-w-[120px]" data-sort="term"><span class="kt-table-col"><span class="kt-table-col-label">Term</span><span class="kt-table-col-sort"></span></span></th>
                    <th class="min-w-[200px]" data-sort="window"><span class="kt-table-col"><span class="kt-table-col-label">Window</span><span class="kt-table-col-sort"></span></span></th>
                    <th class="min-w-[110px]" data-sort="status"><span class="kt-table-col"><span class="kt-table-col-label">Status</span><span class="kt-table-col-sort"></span></span></th>
                    <th class="w-[60px]"></th>
                </tr>
            </thead>
        </x-slot:head>
        @forelse ($assessments as $a)
            <tr>
                <td><input type="checkbox" class="kt-checkbox kt-checkbox-sm" name="assessment_ids[]" value="{{ $a->id }}" form="assessment_bulk_form" data-assessment-select aria-label="Select assessment {{ $a->assessment_code }}"></td>
                <td class="text-mono font-medium text-sm"><span data-filter-value="assessment" data-filter-key="{{ $a->assessment_code }}" hidden></span>{{ $a->assessment_code }}</td>
                <td><span data-filter-value="subject" data-filter-key="{{ $a->subject_id }}" hidden></span>{{ optional($a->subject)->subject_name }}</td>
                <td><span data-filter-value="section" data-filter-key="{{ $a->section_id }}" hidden></span>{{ optional($a->section)->section_name }}</td>
                <td>{{ optional($a->academicTerm)->term_name }}</td>
                <td class="text-secondary-foreground">{{ $a->start_date?->format('Y-m-d') }} → {{ $a->end_date?->format('Y-m-d') }}</td>
                <td>
                    <span data-filter-value="status" data-filter-key="{{ $a->is_active ? 'active' : 'inactive' }}" hidden></span>
                    <span class="kt-badge rounded-full kt-badge-outline kt-badge-{{ $a->is_active ? 'success' : 'warning' }} gap-1 items-center">
                        <span class="kt-badge-dot size-1.5"></span>{{ $a->is_active ? 'Active' : 'Inactive' }}
                    </span>
                </td>
                <td class="text-center">
                    <x-table-actions
                        :edit-modal="route('educator.assessments.edit', $a)"
                        edit-modal-title="Edit assessment"
                        :delete="route('educator.assessments.destroy', $a)"
                        confirm="Delete this assessment? Bank questions are not deleted.">
                        <div class="kt-menu-item">
                            <a class="kt-menu-link" href="{{ route('educator.assessments.pool.edit', $a) }}">
                                <span class="kt-menu-icon"><i class="ki-filled ki-questionnaire-tablet"></i></span>
                                <span class="kt-menu-title">Question Pool</span>
                            </a>
                        </div>
                        <div class="kt-menu-item">
                            <a class="kt-menu-link" href="#" data-modal-url="{{ route('educator.assessments.duplicate', $a, false) }}" data-modal-target="#form_modal" data-modal-title="Duplicate assessment">
                                <span class="kt-menu-icon"><i class="ki-filled ki-copy"></i></span>
                                <span class="kt-menu-title">Duplicate</span>
                            </a>
                        </div>
                        <div class="kt-menu-item">
                            <a class="kt-menu-link" href="#" data-modal-url="{{ route('educator.assessments.exemptions', $a, false) }}" data-modal-target="#form_modal" data-modal-title="Manage exemptions">
                                <span class="kt-menu-icon"><i class="ki-filled ki-user-tick"></i></span>
                                <span class="kt-menu-title">Manage Exemptions</span>
                            </a>
                        </div>
                        <div class="kt-menu-item">
                            <a class="kt-menu-link" href="#" data-modal-url="{{ route('educator.assessments.access', $a, false) }}" data-modal-target="#form_modal" data-modal-title="Manage special access">
                                <span class="kt-menu-icon"><i class="ki-filled ki-time"></i></span>
                                <span class="kt-menu-title">Manage Special Access</span>
                            </a>
                        </div>
                    </x-table-actions>
                </td>
            </tr>
        @empty
            <tr><td colspan="8" class="text-center text-secondary-foreground py-5">No assessments.</td></tr>
        @endforelse
    </x-data-table>

    <x-modal id="form_modal" width="900px" />
@endsection

@push('scripts')
<script nonce="{{ $cspNonce ?? '' }}" data-ajax-rerun>
(function () {
    if (window.qyzenAssessmentArchiveBound) return;
    window.qyzenAssessmentArchiveBound = true;

    function syncAssessmentBulkState() {
        var selected = document.querySelectorAll('[data-assessment-select]:checked').length;
        var button = document.querySelector('[data-assessment-bulk]');
        var count = document.querySelector('[data-assessment-bulk-count]');
        if (!button || !count) return;
        button.disabled = selected === 0;
        count.textContent = selected;
    }

    document.addEventListener('change', function (event) {
        if (event.target.matches('[data-assessment-select], [data-assessment-select-all]')) {
            if (event.target.matches('[data-assessment-select-all]')) {
                document.querySelectorAll('[data-assessment-select]').forEach(function (box) {
                    box.checked = event.target.checked;
                });
            }
            syncAssessmentBulkState();
        }
    }, true);

    document.addEventListener('submit', function (event) {
        var form = event.target.closest('#assessment_bulk_form');
        if (!form) return;
        if (!document.querySelector('[data-assessment-select]:checked')) event.preventDefault();
    });

    syncAssessmentBulkState();
})();
</script>
@endpush
