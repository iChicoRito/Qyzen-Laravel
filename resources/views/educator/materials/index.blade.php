@extends('educator.layout')
@section('title', 'Materials')
@section('heading', 'Learning Materials')
@section('toolbar')
    {{-- Task 13: bulk delete. Checkboxes in the AJAX-swapped table submit via form="materials_bulk_form". --}}
    <form id="materials_bulk_form" method="POST" action="{{ route('educator.materials.bulk-destroy') }}"
          data-confirm="Delete the selected materials? This cannot be undone."
          data-confirm-title="Delete selected materials?">
        @csrf @method('DELETE')
        <button type="submit" class="kt-btn kt-btn-sm kt-btn-outline kt-btn-destructive" data-material-bulk-delete disabled>
            Bulk delete <span data-material-bulk-count>0</span>
        </button>
    </form>
    <button type="button" class="kt-btn kt-btn-sm kt-btn-primary"
            data-modal-url="{{ route('educator.materials.create') }}" data-modal-target="#form_modal" data-modal-title="Upload materials">Upload</button>
@endsection
@section('content')
    @include('admin._status')
    @php
        $fileIcon = fn (string $ext) => match (strtolower($ext)) {
            'pdf' => 'pdf.svg',
            'ppt', 'pptx', 'ppsx' => 'powerpoint.svg',
            'doc', 'docx' => 'word.svg',
            'rtf' => 'text.svg',
            default => 'text.svg',
        };
    @endphp
    <x-data-table id="materials_table" search-placeholder="Search materials" :paginator="$materials">
        <x-slot:filters>
            <select data-filter="subject" class="kt-select w-40">
                <option value="">All subjects</option>
                @foreach ($filterSubjects as $sub)
                    <option value="{{ $sub->id }}">{{ $sub->subject_code }} — {{ $sub->subject_name }}</option>
                @endforeach
            </select>
            <select data-filter="section" class="kt-select w-32">
                <option value="">All sections</option>
                @foreach ($filterSections as $sec)
                    <option value="{{ $sec->id }}">{{ $sec->section_name }}</option>
                @endforeach
            </select>
            <select data-filter="term" class="kt-select w-32">
                <option value="">All terms</option>
                @foreach ($filterTerms as $term)
                    <option value="{{ $term->id }}">{{ $term->term_name }}</option>
                @endforeach
            </select>
            <select data-filter="status" class="kt-select w-36">
                <option value="">All statuses</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
            </select>
        </x-slot:filters>
        <x-slot:head>
            <thead>
                <tr>
                    <th class="w-[40px]"><input type="checkbox" class="kt-checkbox kt-checkbox-sm" data-material-select-all aria-label="Select all materials on this page"></th>
                    <th class="min-w-[200px]" data-sort="file"><span class="kt-table-col"><span class="kt-table-col-label">File</span><span class="kt-table-col-sort"></span></span></th>
                    <th class="min-w-[120px]" data-sort="subject"><span class="kt-table-col"><span class="kt-table-col-label">Subject</span><span class="kt-table-col-sort"></span></span></th>
                    <th class="min-w-[110px]" data-sort="section"><span class="kt-table-col"><span class="kt-table-col-label">Section</span><span class="kt-table-col-sort"></span></span></th>
                    <th class="min-w-[90px]" data-sort="type"><span class="kt-table-col"><span class="kt-table-col-label">Type</span><span class="kt-table-col-sort"></span></span></th>
                    <th class="min-w-[100px]" data-sort="size"><span class="kt-table-col"><span class="kt-table-col-label">Size</span><span class="kt-table-col-sort"></span></span></th>
                    <th class="min-w-[110px]" data-sort="status"><span class="kt-table-col"><span class="kt-table-col-label">Status</span><span class="kt-table-col-sort"></span></span></th>
                    <th class="w-[60px]"></th>
                </tr>
            </thead>
        </x-slot:head>
        @forelse ($materials as $m)
            <tr>
                <td><input type="checkbox" class="kt-checkbox kt-checkbox-sm" name="ids[]" value="{{ $m->id }}" form="materials_bulk_form" data-material-select aria-label="Select material"></td>
                <td class="text-mono font-medium text-sm">
                    <div class="flex items-center gap-2.5">
                        <img class="size-5 shrink-0" alt="{{ strtoupper($m->file_extension) }} file"
                             src="{{ asset('metronic-tailwind-html-demos/dist/assets/media/file-types/'.$fileIcon($m->file_extension)) }}">
                        <span>{{ $m->file_name }}</span>
                    </div>
                </td>
                {{-- Task 32: one file is shared across every subject it was assigned to. --}}
                <td>
                    <div class="flex flex-wrap gap-1">
                        @foreach ($m->subjects as $sub)
                            <span data-filter-value="subject" data-filter-key="{{ $sub->id }}" hidden></span>
                            <span class="kt-badge kt-badge-sm kt-badge-outline">{{ $sub->subject_name }}</span>
                        @endforeach
                    </div>
                </td>
                <td>
                    @foreach ($m->subjects->pluck('sections_id')->unique() as $sectionId)
                        <span data-filter-value="section" data-filter-key="{{ $sectionId }}" hidden></span>
                    @endforeach
                    {{ $m->subjects->pluck('section.section_name')->filter()->unique()->implode(', ') ?: '—' }}
                </td>
                <td>{{ strtoupper($m->file_extension) }}</td>
                <td class="text-secondary-foreground">{{ $m->file_size ? number_format($m->file_size / 1024, 1).' KB' : '—' }}</td>
                <td>
                    <span data-filter-value="status" data-filter-key="{{ $m->is_active ? 'active' : 'inactive' }}" hidden></span>
                    <span class="kt-badge rounded-full kt-badge-outline kt-badge-{{ $m->is_active ? 'success' : 'destructive' }} gap-1 items-center">
                        <span class="kt-badge-dot size-1.5"></span>{{ $m->is_active ? 'Active' : 'Inactive' }}
                    </span>
                </td>
                <td class="text-center">
                    <x-table-actions
                        :edit-modal="route('educator.materials.edit', $m)"
                        edit-modal-title="Edit material"
                        :delete="route('educator.materials.destroy', $m)"
                        confirm="Delete this file? This cannot be undone.">
                        <div class="kt-menu-item">
                            <a class="kt-menu-link" href="{{ route('educator.materials.download', $m) }}">
                                <span class="kt-menu-icon"><i class="ki-filled ki-exit-down"></i></span>
                                <span class="kt-menu-title">Download</span>
                            </a>
                        </div>
                    </x-table-actions>
                </td>
            </tr>
        @empty
            <tr><td colspan="8" class="text-center text-secondary-foreground py-5">No materials.</td></tr>
        @endforelse
    </x-data-table>

    <x-modal id="form_modal" width="640px" />
@endsection

@push('scripts')
<script nonce="{{ $cspNonce ?? '' }}" data-ajax-rerun>
(function () {
    // Delegated on document so it survives the data-table's AJAX row swaps (page/filter/sort).
    // Selection is page-scoped: after a swap the fresh rows start unchecked.
    document.addEventListener('change', function (event) {
        if (!event.target.matches('[data-material-select], [data-material-select-all]')) return;
        var root = document.getElementById('materials_table_form');
        var boxes = root ? root.querySelectorAll('[data-material-select]') : [];
        if (event.target.matches('[data-material-select-all]')) {
            boxes.forEach(function (box) { box.checked = event.target.checked; });
        }
        var selected = Array.from(boxes).filter(function (box) { return box.checked; }).length;
        var bulkButton = document.querySelector('[data-material-bulk-delete]');
        if (!bulkButton) return;
        bulkButton.disabled = selected === 0;
        bulkButton.querySelector('[data-material-bulk-count]').textContent = selected;
    }, true);
    document.getElementById('materials_bulk_form').addEventListener('submit', function (event) {
        if (!document.querySelector('[name="ids[]"]:checked')) event.preventDefault();
    });
})();
</script>
@endpush
