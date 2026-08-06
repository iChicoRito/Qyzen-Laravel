{{-- Global submit gate: on any form submit, validate first (native Constraint Validation API).
     If invalid, show app-styled red text below each offending field and DON'T spin/submit.
     Only when all constraints pass do we spin the clicked button. Skip spinner on data-no-spinner.
     noValidate is set on every form so the browser's native bubbles never appear — this JS is the
     single, consistently-styled source of feedback (JS-off degrades to server-side @error). --}}
<script nonce="{{ $cspNonce ?? '' }}" data-ajax-fragment-swap>
    (function () {
        // Suppress native validation bubbles so our JS owns the feedback UI.
        document.querySelectorAll('form').forEach(function (f) { f.noValidate = true; });

        function clearErrors(form) {
            form.querySelectorAll('[data-client-error]').forEach(function (n) { n.remove(); });
        }
        function showErrors(form) {
            var first = null;
            Array.prototype.forEach.call(form.elements, function (el) {
                if (!el.willValidate || el.checkValidity()) return;
                var span = document.createElement('span');
                span.className = 'text-xs text-destructive mt-1';
                span.setAttribute('data-client-error', '');
                span.textContent = el.validationMessage;
                (el.closest('.flex.flex-col') || el.parentElement).appendChild(span);
                if (!first) first = el;
            });
            if (first) first.focus();
        }
        function showServerErrors(form, data) {
            var errors = data && data.errors ? data.errors : {};
            var first = null;
            Object.keys(errors).forEach(function (name) {
                var messages = errors[name] || [];
                var field = form.querySelector('[name="' + name + '"]')
                    || form.querySelector('[name="' + name + '[]"]');
                if (!field) return;
                var span = document.createElement('span');
                span.className = 'text-xs text-destructive mt-1';
                span.setAttribute('data-client-error', '');
                span.textContent = messages[0] || (data && data.message) || 'Invalid value.';
                (field.closest('.flex.flex-col') || field.parentElement).appendChild(span);
                if (!first) first = field;
            });
            if (!first && data && data.message) {
                var note = document.createElement('div');
                note.className = 'kt-alert kt-alert-light text-destructive';
                note.setAttribute('data-client-error', '');
                note.textContent = data.message;
                form.prepend(note);
            }
            if (first) first.focus();
        }

        function spin(form, e) {
            if (form.hasAttribute('data-no-spinner')) return;
            var btn = e.submitter || form.querySelector('button[type=submit],button:not([type])');
            if (!btn || btn.dataset.spinning) return;
            btn.dataset.spinning = '1';
            btn.setAttribute('aria-busy', 'true');
            btn.disabled = true;
            btn.insertAdjacentHTML('beforeend', ' <i class="ki-filled ki-loading animate-spin ms-2"></i>');
        }
        function unspin(form) {
            if (form.hasAttribute('data-no-spinner')) return;
            form.querySelectorAll('button[data-spinning]').forEach(function (btn) {
                btn.disabled = false;
                btn.removeAttribute('aria-busy');
                delete btn.dataset.spinning;
                var i = btn.querySelector('i.ki-loading'); if (i) i.remove();
            });
        }
        function toast(message, variant) {
            if (message && window.KTToast) {
                KTToast.show({ message: message, variant: variant || 'success', appearance: 'outline', dismiss: true });
            }
        }
        function isAjaxForm(form) {
            if (form.hasAttribute('data-native-submit') || form.hasAttribute('data-assessment-modal-form')) return false;
            var method = (form.getAttribute('method') || 'GET').toUpperCase();
            if (method === 'GET' || form.target) return false;
            var action = new URL(form.getAttribute('action') || window.location.href, window.location.href);
            return action.origin === window.location.origin
                && (action.pathname.indexOf('/admin/') === 0 || action.pathname.indexOf('/educator/') === 0);
        }
        function closeModals() {
            document.querySelectorAll('.kt-modal').forEach(function (modal) {
                if (window.KTModal) {
                    var instance = KTModal.getOrCreateInstance(modal);
                    if (instance) instance.hide();
                }
                modal.classList.remove('open', 'show');
            });
            document.querySelectorAll('.kt-modal-backdrop').forEach(function (backdrop) { backdrop.remove(); });
            document.documentElement.classList.remove('overflow-hidden');
            document.body.classList.remove('overflow-hidden');
            document.body.style.overflow = '';
        }
        function reinit(root) {
            if (window.KTDropdown) KTDropdown.init();
            if (window.KTModal) KTModal.init();
            if (window.KTSelect) KTSelect.init();
            if (window.KTTabs) KTTabs.init();
            if (window.initAnnouncementEditors) window.initAnnouncementEditors(root || document);
            if (window.initDateTimePickers) window.initDateTimePickers(root || document);
        }
        function runPageScripts(doc) {
            var nonce = (document.querySelector('script[nonce]') || {}).nonce || '';
            doc.querySelectorAll('script[data-ajax-rerun]').forEach(function (old) {
                var s = document.createElement('script');
                if (nonce) s.setAttribute('nonce', nonce);
                if (old.src) s.src = old.src;
                s.text = old.textContent;
                document.body.appendChild(s);
                if (!old.src) s.remove();
            });
        }
        // Task 32: the data-table keeps its search/filter/sort/page entirely in the query string,
        // but controllers redirect to the bare index — so every create/edit/duplicate wiped the
        // educator's active filters. The modal never navigates, so at submit time the location is
        // still the filtered index: carry its query string onto a bare same-page redirect.
        function keepTableState(url) {
            var target = new URL(url, window.location.href);
            if (!target.search && target.pathname === window.location.pathname) {
                target.search = window.location.search;
            }
            return target.pathname + target.search + target.hash;
        }
        function swapContent(rawUrl) {
            var url = keepTableState(rawUrl);
            return fetch(url, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            }).then(function (r) {
                if (!r.ok) throw new Error('Could not refresh the page.');
                return r.text();
            }).then(function (html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var next = doc.getElementById('content');
                var current = document.getElementById('content');
                if (!next || !current) { window.location.href = url; return; }
                current.innerHTML = next.innerHTML;
                document.title = doc.title || document.title;
                history.pushState(null, '', url);
                closeModals();
                reinit(current);
                runPageScripts(doc);
            });
        }
        function parseResponse(response) {
            return response.text().then(function (text) {
                var data = {};
                try { data = text ? JSON.parse(text) : {}; }
                catch (_) { data = { message: response.ok ? 'Saved.' : 'The server returned an unexpected response.' }; }
                if (!response.ok) throw data;
                return data;
            });
        }
        document.addEventListener('submit', function (e) {
            var form = e.target;
            clearErrors(form);
            if (form.checkValidity && !form.checkValidity()) {
                e.preventDefault();
                e.stopPropagation(); // block submission + any other submit handlers
                showErrors(form);
                return; // invalid → no spinner
            }
            spin(form, e);
        }, true);
        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (!isAjaxForm(form)) return;
            e.preventDefault();
            if (form.dataset.ajaxSubmitting) return;
            form.dataset.ajaxSubmitting = '1';

            var action = new URL(form.getAttribute('action') || window.location.href, window.location.href);
            var token = form.querySelector('input[name="_token"]');
            fetch(action.pathname + action.search + action.hash, {
                method: (form.getAttribute('method') || 'POST').toUpperCase(),
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': token ? token.value : '',
                },
                body: new FormData(form),
                credentials: 'same-origin',
            }).then(parseResponse).then(function (data) {
                toast(data.message || 'Saved.', data.status === 'error' ? 'destructive' : 'success');
                if (data.reload) { window.location.reload(); return; }
                if (data.redirect) return swapContent(data.redirect);
            }).catch(function (data) {
                if (data && data.errors) {
                    showServerErrors(form, data);
                    toast(data.message || 'Please correct the highlighted fields.', 'destructive');
                    return;
                }
                toast((data && data.message) || 'Could not save changes.', 'destructive');
            }).finally(function () {
                delete form.dataset.ajaxSubmitting;
                unspin(form);
            });
        });
        // bfcache back-button: un-stick any spinner left disabled.
        window.addEventListener('pageshow', function () {
            document.querySelectorAll('form').forEach(unspin);
        });
    })();
</script>
