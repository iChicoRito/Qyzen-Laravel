{{-- Reusable modal form loader. A trigger with data-modal-url + data-modal-target="#id"
     (+ optional data-modal-title) fetches that URL's form fragment (?modal=1) and injects it into
     the target modal, then opens it. Delegated on document → survives datatable redraws.
     Injected <script> never runs (innerHTML), so all in-modal behavior (repeater) is delegated. --}}
<script nonce="{{ $cspNonce ?? '' }}">
    (function () {
        function reinit() {
            {{-- This bundle has no window.KTMenu; menus/3-dots are KTDropdown. --}}
            if (window.KTDropdown) KTDropdown.init();
            if (window.KTModal) KTModal.init();
            {{-- Injected data-kt-select / data-kt-tabs aren't auto-upgraded (added after DOM ready). --}}
            if (window.KTSelect) KTSelect.init();
            if (window.KTTabs) KTTabs.init();
            {{-- Collapse "N selected" on multi-selects opted in via data-count-summary (after KTSelect built its wrapper). --}}
            setTimeout(bindAllCountSummaries, 0);
            if (window.initAnnouncementEditors && document.querySelector('[data-quill-editor], [data-quill-value]')) {
                window.initAnnouncementEditors(document);
            }
            if (window.initDateTimePickers) {
                window.initDateTimePickers(document);
            }
        }
        document.addEventListener('click', function (e) {
            var trigger = e.target.closest('[data-modal-url]');
            if (!trigger) return;
            e.preventDefault();

            var sel = trigger.getAttribute('data-modal-target') || '#form_modal';
            var modal = document.querySelector(sel);
            if (!modal) return;
            var body = modal.querySelector('[data-modal-body]');
            var titleEl = modal.querySelector('[data-modal-title]');
            if (titleEl && trigger.dataset.modalTitle) titleEl.textContent = trigger.dataset.modalTitle;
            if (body) body.innerHTML = '<div class="p-10 text-center text-secondary-foreground">Loading…</div>';

            var url = trigger.dataset.modalUrl;
            url += (url.indexOf('?') === -1 ? '?' : '&') + 'modal=1';
            fetch(url, { headers: { 'X-Requested-With': 'fetch' }, credentials: 'same-origin' })
                .then(function (r) { return r.text(); })
                .then(function (html) {
                    if (body) body.innerHTML = html;
                    // Let the global submit gate own validation feedback for injected forms too.
                    var mf = body && body.querySelector('form'); if (mf) mf.noValidate = true;
                    // Cancel buttons/links inside the fragment should dismiss, not navigate.
                    modal.querySelectorAll('[data-modal-cancel]').forEach(function (c) {
                        c.setAttribute('data-kt-modal-dismiss', 'true');
                        c.removeAttribute('href');
                        if (c.tagName === 'A') c.setAttribute('type', 'button');
                    });
                    // Pin the form's action row as a sticky footer so it stays visible while the
                    // body scrolls (buttons stay INSIDE the form, so submit still works).
                    var submit = body && body.querySelector('button[type=submit], button:not([type])');
                    var actions = submit && (submit.closest('[data-modal-cancel]') ? null : submit.parentElement);
                    if (actions) {
                        actions.classList.add(
                            'sticky', 'bottom-0', 'bg-background', 'border-t', 'border-border',
                            'py-3', 'mt-4', 'justify-end'
                        );
                        actions.style.marginBottom = '0';
                    }
                    reinit();
                    if (window.KTModal) KTModal.getOrCreateInstance(modal).show();
                })
                .catch(function () {
                    if (body) body.innerHTML = '<div class="p-10 text-center text-destructive">Could not load the form.</div>';
                });
        });

        {{-- Click anywhere on a date/time input opens the native picker (not just the tiny icon). --}}
        document.addEventListener('click', function (e) {
            var f = e.target.closest('input[type=date], input[type=time]');
            if (f && typeof f.showPicker === 'function') { try { f.showPicker(); } catch (_) {} }
        });

        {{-- Quiz type select ⇄ show MC choices vs Identification answers (delegated; works in injected modal). --}}
        document.addEventListener('change', function (e) {
            var sel = e.target.closest('[data-quiz-type]');
            if (!sel) return;
            var form = sel.closest('form') || document;
            var mc = form.querySelector('[data-mc-choices]');
            var id = form.querySelector('[data-id-answers]');
            if (mc) mc.hidden = sel.value !== 'multiple_choice';
            if (id) id.hidden = sel.value === 'multiple_choice';
        });

        document.addEventListener('change', function (e) {
            var global = e.target.closest('[data-global-switch]');
            if (!global) return;
            var form = global.closest('[data-announcement-form]');
            var subject = form && form.querySelector('[data-subject-select]');
            if (!subject) return;
            subject.disabled = global.checked;
            {{-- KTSelect hides the native <select>, so toggling its disabled property alone leaves
                 the visible wrapper fully clickable — drive the instance instead when it exists. --}}
            var ktSelect = window.KTSelect && KTSelect.getInstance ? KTSelect.getInstance(subject) : null;
            if (ktSelect) { global.checked ? ktSelect.disable() : ktSelect.enable(); }
        });

        {{-- Switch with data-reveal="#id" → show/hide the target field when toggled on/off. --}}
        document.addEventListener('change', function (e) {
            var sw = e.target.closest('[data-reveal]');
            if (!sw) return;
            var target = document.querySelector(sw.getAttribute('data-reveal'));
            if (target) target.classList.toggle('hidden', !sw.checked);
        });

        {{-- Bulk quiz upload: render picked files as Metronic "Recent Uploads" rows
             (file-type icon + name + human size). Native input only — no upload library. --}}
        function humanSize(bytes) {
            if (bytes < 1024) return bytes + ' B';
            var kb = bytes / 1024;
            if (kb < 1024) return kb.toFixed(1) + ' KB';
            return (kb / 1024).toFixed(1) + ' MB';
        }
        var FILE_ICON_BASE = @json(asset('metronic-tailwind-html-demos/dist/assets/media/file-types'));
        function fileIcon(name) {
            var ext = (name.split('.').pop() || '').toLowerCase();
            var known = {
                xlsx: 'xls', xls: 'xls', csv: 'xls',
                ppt: 'powerpoint', pptx: 'powerpoint', ppsx: 'powerpoint',
                doc: 'word', docx: 'word',
                rtf: 'text',
            };
            return FILE_ICON_BASE + '/' + (known[ext] || 'text') + '.svg';
        }
        function renderFileList(input) {
            var list = document.querySelector('[data-file-list]');
            if (!list) return;
            if (!input.files.length) {
                list.innerHTML = '<span class="text-xs text-secondary-foreground">' + list.getAttribute('data-empty') + '</span>';
                return;
            }
            list.innerHTML = '';
            Array.prototype.forEach.call(input.files, function (f, i) {
                var row = document.createElement('div');
                row.className = 'flex items-center gap-2.5';
                var img = document.createElement('img');
                img.src = fileIcon(f.name);
                img.className = 'size-8 shrink-0';
                var meta = document.createElement('div');
                meta.className = 'flex flex-col min-w-0 grow';
                var nm = document.createElement('span');
                nm.className = 'text-sm font-medium text-mono truncate';
                nm.textContent = f.name;
                var sz = document.createElement('span');
                sz.className = 'text-xs text-secondary-foreground';
                sz.textContent = humanSize(f.size);
                meta.appendChild(nm); meta.appendChild(sz);
                var del = document.createElement('button');
                del.type = 'button';
                del.className = 'kt-btn kt-btn-sm kt-btn-icon kt-btn-ghost text-destructive shrink-0';
                del.title = 'Remove file';
                del.setAttribute('data-file-remove', i);
                del.innerHTML = '<i class="ki-filled ki-trash"></i>';
                row.appendChild(img); row.appendChild(meta); row.appendChild(del);
                list.appendChild(row);
            });
        }
        document.addEventListener('change', function (e) {
            var input = e.target.closest('input[type=file][name="files[]"]');
            if (!input) return;
            renderFileList(input);
        });
        {{-- Remove one queued file: rebuild the input's FileList via DataTransfer (FileList is read-only). --}}
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-file-remove]');
            if (!btn) return;
            e.preventDefault();
            var input = document.querySelector('input[type=file][name="files[]"]');
            if (!input) return;
            var remove = parseInt(btn.getAttribute('data-file-remove'), 10);
            var dt = new DataTransfer();
            Array.prototype.forEach.call(input.files, function (f, i) { if (i !== remove) dt.items.add(f); });
            input.files = dt.files;
            renderFileList(input);
        });

        {{-- Bulk quiz upload: mirror the selected target assessments below the multi-select. --}}
        document.addEventListener('change', function (e) {
            var sel = e.target.closest('select[data-target-assessments]');
            if (!sel) return;
            var out = document.querySelector('[data-selected-list]');
            if (!out) return;
            var labels = [];
            for (var i = 0; i < sel.options.length; i++) { if (sel.options[i].selected) labels.push(sel.options[i].text); }
            out.textContent = labels.length ? labels.join(' • ') : out.getAttribute('data-empty');
        });

        {{-- Subject <details> picker: reflect the checked count in the summary label. --}}
        document.addEventListener('change', function (e) {
            var opt = e.target.closest('[data-subject-option]');
            if (!opt) return;
            var box = opt.closest('details');
            var summary = box && box.querySelector('[data-subject-summary]');
            if (!summary) return;
            var n = box.querySelectorAll('[data-subject-option]:checked').length;
            summary.textContent = n ? n + ' selected' : summary.getAttribute('data-subject-summary-default');
        });

        {{-- Task 01: exemption list (educator/assessments/exemptions.blade.php) — live search filter,
             select-all (only touches currently-visible rows). Delegated (not a page-local script) so it works both as a full
             page load and injected into the shared modal via innerHTML. --}}
        document.addEventListener('input', function (e) {
            var search = e.target.closest('[data-exempt-search]');
            if (!search) return;
            var scope = search.closest('[data-exempt-list]') || document;
            var term = search.value.trim().toLowerCase();
            var visible = 0;
            scope.querySelectorAll('[data-exempt-row]').forEach(function (row) {
                var match = !term || (row.dataset.exemptName || '').indexOf(term) !== -1;
                row.classList.toggle('hidden', !match);
                if (match) visible++;
            });
            var noMatch = scope.querySelector('[data-exempt-no-match]');
            if (noMatch) noMatch.classList.toggle('hidden', visible !== 0);
        });
        document.addEventListener('change', function (e) {
            var all = e.target.closest('[data-exempt-select-all]');
            if (!all) return;
            var scope = all.closest('[data-exempt-list]') || document;
            scope.querySelectorAll('[data-exempt-row]:not(.hidden) [data-exempt-checkbox]').forEach(function (box) {
                box.checked = all.checked;
            });
        });
        document.addEventListener('submit', function (e) {
            var form = e.target.closest('[data-assessment-modal-form]');
            if (!form) return;
            e.preventDefault();

            var token = form.querySelector('input[name="_token"]');
            var btn = e.submitter || form.querySelector('button[type="submit"]');
            if (btn) btn.disabled = true;

            var actionUrl = new URL(form.getAttribute('action'), window.location.href);
            fetch(actionUrl.pathname + actionUrl.search + actionUrl.hash, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': token ? token.value : '',
                    'Accept': 'application/json',
                },
                body: new FormData(form),
                credentials: 'same-origin',
            }).then(function (r) {
                return r.text().then(function (text) {
                    var data = {};
                    try {
                        data = text ? JSON.parse(text) : {};
                    } catch (_) {
                        data = { message: r.ok ? 'Saved.' : 'The server returned an HTML error page. Please reload and try again.' };
                    }
                    if (!r.ok) throw data;
                    return data;
                });
            }).then(function (data) {
                var action = data.action || '';
                var isAccess = action === 'grant' || action === 'revoke';
                var activeIds = (data.active_student_ids || []).map(String);
                var deadlines = data.access_deadlines || {};

                form.querySelectorAll('[data-exempt-checkbox]').forEach(function (box) {
                    var active = activeIds.indexOf(String(box.value)) !== -1;
                    box.checked = active;
                    var status = box.closest('[data-exempt-row]') && box.closest('[data-exempt-row]').querySelector('[data-exempt-status]');
                    if (!status) return;
                    if (!active) {
                        status.innerHTML = '<span class="text-xs text-secondary-foreground">' + (isAccess ? 'Default' : 'Active') + '</span>';
                        return;
                    }
                    var html = '<span class="kt-badge kt-badge-sm kt-badge-outline ' + (isAccess ? 'kt-badge-info' : 'kt-badge-warning') + '">' + (isAccess ? 'Special Access' : 'Exempted') + '</span>';
                    {{-- Task 24: keep the expiry visible after an AJAX save, not just on reload. --}}
                    var until = deadlines[box.value];
                    if (isAccess && until) {
                        html += '<span class="block text-xs text-secondary-foreground mt-1">Until ' + until + '</span>';
                    }
                    status.innerHTML = html;
                });

                var note = form.querySelector('[data-assessment-modal-message]');
                if (!note) {
                    note = document.createElement('div');
                    note.setAttribute('data-assessment-modal-message', 'true');
                    note.className = 'kt-alert kt-alert-light';
                    form.prepend(note);
                }
                note.textContent = data.message || 'Saved.';
            }).catch(function (data) {
                var note = form.querySelector('[data-assessment-modal-message]');
                if (!note) {
                    note = document.createElement('div');
                    note.setAttribute('data-assessment-modal-message', 'true');
                    form.prepend(note);
                }
                note.className = 'kt-alert kt-alert-light';
                note.textContent = (data && data.message) || 'Could not save changes.';
            }).finally(function () {
                if (btn) btn.disabled = false;
            });
        });

        {{-- KTUI multi-select with data-count-summary: collapse the display to "N <noun>s selected"
             when 2+ are picked; keep the real label for a single selection. KTUI writes the joined
             labels into its values container ([data-kt-select-combobox-values]) and re-renders on every
             change, so we watch that container with a MutationObserver and overwrite it — no event/timing
             guesswork. Guard with a flag so our own write doesn't retrigger the observer. --}}
        function countSummaryText(sel) {
            var n = 0;
            for (var i = 0; i < sel.options.length; i++) { if (sel.options[i].selected) n++; }
            if (n < 2) return null; // 0 → placeholder, 1 → real label: leave KTUI's output
            var noun = sel.getAttribute('data-count-summary') || 'item';
            return n + ' ' + noun + 's selected';
        }
        function attachCountSummary(sel) {
            if (sel._countSummaryBound) return;
            var wrap = sel.nextElementSibling;
            if (!wrap || !wrap.classList.contains('kt-select-wrapper')) {
                wrap = sel.parentElement && sel.parentElement.querySelector('.kt-select-wrapper');
            }
            var target = wrap && (wrap.querySelector('[data-kt-select-combobox-values]')
                || wrap.querySelector('[data-kt-text-container]')
                || wrap.querySelector('[data-kt-select-display]'));
            if (!target) return;
            sel._countSummaryBound = true;
            var writing = false;
            var apply = function () {
                var text = countSummaryText(sel);
                if (text === null) return;            // let KTUI's placeholder/single label stand
                if (target.textContent === text && target.children.length === 0) return;
                writing = true;
                target.textContent = text;
                writing = false;
            };
            new MutationObserver(function () { if (!writing) apply(); }).observe(target, { childList: true, subtree: true, characterData: true });
            apply();
        }
        {{-- Bind after KTUI has built its wrapper (it inits on our reinit()/DOM ready). --}}
        function bindAllCountSummaries() {
            document.querySelectorAll('select[data-count-summary]').forEach(attachCountSummary);
        }

        {{-- CSP-safe form repeater (bulk permissions). Delegated so it works on injected DOM. --}}
        function renumberRepeater(list) {
            list.querySelectorAll('[data-repeater-row]').forEach(function (row, i) {
                var label = row.querySelector('[data-repeater-index]');
                if (label) label.textContent = i + 1;
                row.querySelectorAll('input, select, textarea').forEach(function (el) {
                    if (el.name) el.name = el.name.replace(/\[\d+\]/, '[' + i + ']');
                });
            });
        }
        document.addEventListener('click', function (e) {
            var add = e.target.closest('[data-repeater-add]');
            if (add) {
                e.preventDefault();
                var list = document.querySelector(add.getAttribute('data-repeater-add'));
                if (!list) return;
                var rows = list.querySelectorAll('[data-repeater-row]');
                var clone = rows[rows.length - 1].cloneNode(true);
                clone.querySelectorAll('input').forEach(function (el) { el.value = ''; });
                clone.querySelectorAll('select').forEach(function (el) { el.selectedIndex = 0; });
                list.appendChild(clone);
                renumberRepeater(list);
                return;
            }
            var rm = e.target.closest('[data-repeater-remove]');
            if (rm) {
                e.preventDefault();
                var row = rm.closest('[data-repeater-row]');
                var container = row && row.parentElement;
                if (container && container.querySelectorAll('[data-repeater-row]').length > 1) {
                    row.remove();
                    renumberRepeater(container);
                }
            }
        });
    })();
</script>
