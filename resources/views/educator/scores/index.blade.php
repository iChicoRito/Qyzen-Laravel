@extends('educator.layout')
@section('title', 'Scores')
@section('heading', 'Scores')
@section('toolbar')
    <button type="button" class="kt-btn kt-btn-sm kt-btn-secondary" data-kt-modal-toggle="#score_upload_modal">Upload offline scores</button>
    <button type="button" class="kt-btn kt-btn-sm kt-btn-outline" data-kt-modal-toggle="#export_modal">Download Grades</button>
@endsection
@section('content')
    @include('admin._status')
    <x-data-table id="scores_table" search-placeholder="Search subjects, sections, terms" :paginator="$classes">
        <x-slot:filters>
            <select data-filter="subject" data-depends-on="section" class="kt-select w-40">
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
                @foreach ($filterTerms as $t)
                    <option value="{{ $t->id }}">{{ $t->term_name }}</option>
                @endforeach
            </select>
        </x-slot:filters>
        <x-slot:head>
            <thead>
                <tr>
                    <th class="min-w-[240px]" data-sort="subject"><span class="kt-table-col"><span class="kt-table-col-label">Subject</span><span class="kt-table-col-sort"></span></span></th>
                    <th class="min-w-[140px]" data-sort="section"><span class="kt-table-col"><span class="kt-table-col-label">Section</span><span class="kt-table-col-sort"></span></span></th>
                    <th class="min-w-[140px]" data-sort="term"><span class="kt-table-col"><span class="kt-table-col-label">Term</span><span class="kt-table-col-sort"></span></span></th>
                    <th class="w-[60px]"></th>
                </tr>
            </thead>
        </x-slot:head>
        {{-- Task 27: one row per class (subject × section × term). Student scores live in the
             matrix modal opened from this row's menu. --}}
        @forelse ($classes as $class)
            @php $classParams = ['subject' => $class->subject_id, 'section' => $class->section_id, 'term' => $class->term]; @endphp
            <tr>
                <td>
                    <span data-filter-value="subject" data-filter-key="{{ $class->subject_id }}" hidden></span>
                    <div class="flex flex-col gap-0.5">
                        <span class="text-sm text-mono font-semibold">{{ $class->subject_code }}</span>
                        <span class="text-xs text-secondary-foreground">{{ $class->subject_name }} · {{ $class->assessments_count }} {{ $class->assessments_count === 1 ? 'assessment' : 'assessments' }}</span>
                    </div>
                </td>
                <td>
                    <span data-filter-value="section" data-filter-key="{{ $class->section_id }}" hidden></span>
                    {{ $class->section_name }}
                </td>
                <td class="text-secondary-foreground">
                    <span data-filter-value="term" data-filter-key="{{ $class->term }}" hidden></span>
                    {{ $class->term_name }}
                </td>
                <td class="text-center">
                    <x-table-actions
                        :view-modal="route('educator.scores.matrix', $classParams)"
                        view-modal-title="{{ $class->subject_code }} · {{ $class->section_name }} · {{ $class->term_name }}" />
                </td>
            </tr>
        @empty
            <tr><td colspan="4" class="text-center text-secondary-foreground py-5">No classes with assessments yet.</td></tr>
        @endforelse
    </x-data-table>

    @include('educator.scores._export_modal')

    @php
        $twoLine = fn(string $v1, string $v2) =>
            '<div class=\"flex items-center justify-between gap-2\">'
            . '<div class=\"flex flex-col gap-0.5\">'
            . '<span class=\"text-sm font-medium\">{{text}}</span>'
            . '<span class=\"text-xs text-secondary-foreground\">{{' . $v2 . '}}</span>'
            . '</div>'
            . '<svg viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\" class=\"size-3.5 shrink-0 hidden text-primary kt-select-option-selected:block\">'
            . '<path d=\"M20 6 9 17l-5-5\"/></svg></div>';
    @endphp
    <div class="kt-modal" data-kt-modal="true" id="score_upload_modal">
        <div class="kt-modal-content top-[15%]" style="width: 100%; max-width: min(92vw, 500px);">
            <form method="POST" action="{{ route('educator.scores.upload') }}" enctype="multipart/form-data">
                @csrf
                <div class="kt-modal-header">
                    <h3 class="kt-modal-title">Upload offline scores</h3>
                    <button type="button" class="kt-btn kt-btn-sm kt-btn-icon kt-btn-ghost shrink-0" data-kt-modal-dismiss="true">
                        <i class="ki-filled ki-cross"></i>
                    </button>
                </div>
                <div class="kt-modal-body flex flex-col gap-3">
                    <div class="flex items-center justify-between gap-3">
                        <label class="kt-form-label">Template</label>
                        <a href="{{ route('educator.scores.upload.template') }}" class="kt-btn kt-btn-sm kt-btn-outline shrink-0">
                            <i class="ki-filled ki-cloud-download"></i> Download
                        </a>
                    </div>
                    <div class="flex flex-col gap-1">
                        <label class="kt-form-label">Term</label>
                        <select id="upload_term_sel" name="term_id" class="kt-select" required
                            data-kt-select="true"
                            data-kt-select-placeholder="Select term">
                            <option value="">Select term</option>
                        </select>
                        @error('term_id')<span class="text-xs text-destructive">{{ $message }}</span>@enderror
                    </div>
                    {{-- Section filter (not posted — narrows assessment list) --}}
                    <div class="flex flex-col gap-1">
                        <label class="kt-form-label">Section <span class="text-secondary-foreground font-normal">(optional filter)</span></label>
                        <select id="upload_section_sel" class="kt-select"
                            data-kt-select="true"
                            data-kt-select-config='{"optionTemplate":"{{ $twoLine('text','term') }}"}'
                            data-kt-select-enable-search="true"
                            data-kt-select-placeholder="All sections">
                            <option value="">All sections</option>
                        </select>
                    </div>
                    {{-- Assessment — posts assessment_uuid --}}
                    <div class="flex flex-col gap-1">
                        <label class="kt-form-label">Assessment</label>
                        <select id="upload_assessment_sel" name="assessment_uuid" class="kt-select" required
                            data-kt-select="true"
                            data-kt-select-config='{"optionTemplate":"{{ $twoLine('text','description') }}"}'
                            data-kt-select-enable-search="true"
                            data-kt-select-placeholder="Select assessment">
                            <option value="">Select assessment</option>
                        </select>
                        @error('assessment_uuid')<span class="text-xs text-destructive">{{ $message }}</span>@enderror
                    </div>
                    <p class="text-sm text-secondary-foreground">Columns: student_id, score. Question count comes from the selected assessment.</p>
                    <input type="file" name="file" accept=".xlsx,.xls,.csv" class="kt-input" required>
                    @error('file')<span class="text-xs text-destructive">{{ $message }}</span>@enderror
                </div>
                <div class="kt-modal-footer justify-end">
                    <button type="submit" class="kt-btn kt-btn-primary">Upload</button>
                </div>
            </form>
        </div>
    </div>

    @push('scripts')
    <script nonce="{{ $cspNonce ?? '' }}" data-ajax-rerun>
    (function () {
        if (window.qyzenScoreDeleteBound) return;
        window.qyzenScoreDeleteBound = true;

        var token = @json(csrf_token());

        function parseResponse(response) {
            return response.text().then(function (text) {
                var data = {};
                try { data = text ? JSON.parse(text) : {}; } catch (_) { data = {}; }
                if (!response.ok) throw data;
                return data;
            });
        }

        function showError(data) {
            if (window.KTToast) {
                KTToast.show({
                    message: (data && data.message) || 'Could not delete the score.',
                    variant: 'destructive',
                    appearance: 'outline',
                    dismiss: true,
                });
            }
        }

        function restoreScore(data, row, rowParent, rowNext) {
            return fetch(data.restore_url, {
                method: 'PATCH',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': token,
                    'Accept': 'application/json',
                },
                credentials: 'same-origin',
            }).then(parseResponse).then(function (result) {
                if (row && rowParent) {
                    rowParent.insertBefore(row, rowNext && rowNext.parentNode === rowParent ? rowNext : null);
                }
                if (window.KTToast) {
                    KTToast.show({
                        message: (result && result.message) || 'Score restored.',
                        variant: 'success',
                        appearance: 'outline',
                        dismiss: true,
                    });
                }
            }).catch(showError);
        }

        function deleteScore(button) {
            if (button.disabled) return;
            button.disabled = true;

            var row = button.closest('[data-score-row]');
            var rowParent = row && row.parentNode;
            var rowNext = row && row.nextElementSibling;
            fetch(button.dataset.scoreDeleteUrl, {
                method: 'DELETE',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': token,
                    'Accept': 'application/json',
                },
                credentials: 'same-origin',
            }).then(parseResponse).then(function (data) {
                if (row) row.remove();
                if (!window.KTToast) return;

                var remaining = 5;
                var toast = null;
                var countdown = window.setInterval(function () {
                    remaining -= 1;
                    var action = toast && toast.element.querySelector('[data-kt-toast-action]');
                    if (action) action.textContent = 'Undo (' + Math.max(remaining, 0) + ')';
                    if (remaining <= 0) window.clearInterval(countdown);
                }, 1000);

                toast = KTToast.show({
                    message: data.message || 'Score moved to Archived Scores.',
                    variant: 'success',
                    appearance: 'outline',
                    dismiss: true,
                    duration: 5000,
                    progress: true,
                    pauseOnHover: false,
                    action: {
                        label: 'Undo (5)',
                        className: 'kt-btn kt-btn-sm kt-btn-outline',
                        onClick: function () {
                            window.clearInterval(countdown);
                            return restoreScore(data, row, rowParent, rowNext);
                        },
                    },
                    onAutoClose: function () {
                        window.clearInterval(countdown);
                    },
                });
            }).catch(showError).finally(function () {
                button.disabled = false;
            });
        }

        document.addEventListener('click', function (event) {
            var button = event.target.closest('[data-score-delete]');
            if (!button) return;
            event.preventDefault();
            deleteScore(button);
        });
    })();
    </script>
    <script nonce="{{ $cspNonce ?? '' }}" data-ajax-rerun>
    (function () {
        var opts = window.__exportOptions || [];
        if (!opts.length) return;

        var termSel = document.getElementById('upload_term_sel');
        var secSel = document.getElementById('upload_section_sel');
        var assSel = document.getElementById('upload_assessment_sel');

        var seenTerms = {};
        opts.forEach(function (o) {
            if (!o.termId || seenTerms[o.termId]) return;
            seenTerms[o.termId] = true;
            var el = document.createElement('option');
            el.value = o.termId;
            el.textContent = o.termLabel || 'Untitled term';
            termSel.appendChild(el);
        });

        // Unique sections
        var seen = {};
        opts.forEach(function (o) {
            if (seen[o.sectionId]) return;
            seen[o.sectionId] = true;
            var el = document.createElement('option');
            el.value = o.sectionId;
            el.textContent = o.sectionLabel;
            el.setAttribute('data-kt-select-option', JSON.stringify({ term: o.termLabel || '' }));
            secSel.appendChild(el);
        });

        function fillAssessments(sectionId) {
            while (assSel.options.length > 1) assSel.remove(1);
            opts.forEach(function (o) {
                if (!termSel.value || String(o.termId) !== String(termSel.value)) return;
                if (sectionId && String(o.sectionId) !== String(sectionId)) return;
                var el = document.createElement('option');
                el.value = o.uuid;
                el.textContent = o.assessmentCode;
                el.setAttribute('data-kt-select-option', JSON.stringify({ description: o.subjectLabel || '' }));
                assSel.appendChild(el);
            });
        }

        termSel.addEventListener('change', function () {
            assSel.value = '';
            fillAssessments(secSel.value || null);
        });
        secSel.addEventListener('change', function () {
            assSel.value = '';
            fillAssessments(secSel.value || null);
        });
    })();
    </script>
    @endpush

    {{-- Wide: the class matrix grows a column per assessment. --}}
    <x-modal id="form_modal" width="1100px" />
@endsection
