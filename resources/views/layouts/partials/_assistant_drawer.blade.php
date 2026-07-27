{{-- Task 28: educator AI academic assistant. Structure mirrors the messaging chat drawer above
     (same KTUI data-kt-drawer contract, same csrf-hidden-input + fetch conventions). Rendered
     only for educators — the endpoint itself is gated by role:educator, this just hides the UI. --}}
<!-- AI Assistant -->
<button class="relative kt-btn kt-btn-ghost kt-btn-icon size-9 rounded-full hover:bg-primary/10 hover:[&_i]:text-primary" data-kt-drawer-toggle="#assistant_drawer" id="assistant_btn" title="Academic assistant">
 <i class="ki-filled ki-technology-4 text-lg"></i>
</button>
<!--Assistant Drawer-->
<div class="hidden kt-drawer kt-drawer-end card flex flex-col max-w-[90%] w-[450px] top-5 bottom-5 end-5 rounded-xl border border-border" data-kt-drawer="true" data-kt-drawer-container="body" id="assistant_drawer">
 {{-- Same reason as the chat drawer: the pre-purged Metronic bundle omits min-h-0 /
      overflow-y-auto, and KTUI sets an inline display on open. --}}
 <style nonce="{{ $cspNonce ?? '' }}">
  #assistant_drawer:not(.hidden){display:flex !important;}
  #assistant_drawer .assistant-scroll{min-height:0;overflow-y:auto;}
  #assistant_drawer .assistant-bubble{word-break:break-word;}
  {{-- Markdown replies render as real elements, so pre-wrap must NOT apply to them: the parser
       already produced the block structure and pre-wrap would double every gap. Plain-text
       bubbles (fallbacks, the user's own message) keep it. --}}
  #assistant_drawer .assistant-bubble:not(:has(p,ul,ol,table)){white-space:pre-wrap;}
  {{-- The Metronic bundle ships a purged Tailwind build with preflight, so lists and tables come
       through unstyled. These are the minimum rules to make a grade table legible in a 450px drawer. --}}
  #assistant_drawer .assistant-bubble p{margin:0 0 .5rem;}
  #assistant_drawer .assistant-bubble p:last-child{margin-bottom:0;}
  #assistant_drawer .assistant-bubble ul,#assistant_drawer .assistant-bubble ol{margin:.25rem 0 .5rem;padding-inline-start:1.25rem;}
  #assistant_drawer .assistant-bubble ul{list-style:disc;}
  #assistant_drawer .assistant-bubble ol{list-style:decimal;}
  #assistant_drawer .assistant-bubble li{margin:.125rem 0;}
  #assistant_drawer .assistant-bubble h3,#assistant_drawer .assistant-bubble h4{font-weight:600;margin:.25rem 0;}
  #assistant_drawer .assistant-bubble code{font-family:ui-monospace,monospace;font-size:.875em;}
  #assistant_drawer .assistant-bubble blockquote{border-inline-start:2px solid currentColor;opacity:.8;padding-inline-start:.5rem;margin:.25rem 0;}
  #assistant_drawer .assistant-bubble table{width:100%;border-collapse:collapse;margin:.25rem 0;font-size:.8125rem;display:block;overflow-x:auto;}
  #assistant_drawer .assistant-bubble th,#assistant_drawer .assistant-bubble td{border:1px solid rgb(0 0 0 / .15);padding:.2rem .4rem;text-align:start;white-space:nowrap;}
  #assistant_drawer .assistant-bubble th{font-weight:600;}
  {{-- Composer: field and button share a row, so text can never run under the button. Every
       property is spelled out because the purged Tailwind bundle cannot be relied on here. --}}
  #assistant_drawer .assistant-composer{display:flex;align-items:flex-end;gap:.5rem;}
  #assistant_drawer .assistant-composer textarea{
   flex:1 1 auto;min-width:0;                 /* min-width:0 lets the flex item actually shrink */
   resize:none;overflow-y:auto;
   min-height:2.75rem;max-height:8rem;        /* grows to ~4 lines, then scrolls */
   padding:.625rem .75rem;line-height:1.35;font-size:.875rem;font-family:inherit;
   border:1px solid var(--color-input,rgb(0 0 0 / .18));border-radius:.5rem;
   background:transparent;color:inherit;
  }
  #assistant_drawer .assistant-composer textarea:focus{outline:none;border-color:var(--color-primary,#2563eb);}
  #assistant_drawer .assistant-composer button{flex:0 0 auto;margin-bottom:.125rem;}
  {{-- Alerts are status banners, not chat turns: full width, no bubble max-width. --}}
  #assistant_drawer .assistant-alert-row{display:block;width:100%;}
  #assistant_drawer .assistant-alert-row .kt-alert{width:100%;}
  #assistant_drawer .assistant-alert-row .kt-alert-description{white-space:normal;}
  {{-- KTUI tints the alert icon only through `.kt-alert-icon > svg`, and we render a keenicons
       <i> glyph rather than an SVG — so without these the icon inherits the plain foreground and
       every variant looks identical. Colours are copied verbatim from the bundle's own
       .kt-alert-light.kt-alert-* rules (note warning/info/success are literal palette tokens;
       only --primary and --destructive exist as semantic variables). The title takes the same
       colour so the variant reads at a glance. --}}
  #assistant_drawer .kt-alert-light .kt-alert-title{font-weight:600;}
  #assistant_drawer .kt-alert-light.kt-alert-primary .kt-alert-icon > i,
  #assistant_drawer .kt-alert-light.kt-alert-primary .kt-alert-title{color:var(--primary);}
  #assistant_drawer .kt-alert-light.kt-alert-destructive .kt-alert-icon > i,
  #assistant_drawer .kt-alert-light.kt-alert-destructive .kt-alert-title{color:var(--destructive);}
  #assistant_drawer .kt-alert-light.kt-alert-warning .kt-alert-icon > i,
  #assistant_drawer .kt-alert-light.kt-alert-warning .kt-alert-title{color:var(--color-yellow-500);}
  #assistant_drawer .kt-alert-light.kt-alert-info .kt-alert-icon > i,
  #assistant_drawer .kt-alert-light.kt-alert-info .kt-alert-title{color:var(--color-violet-500);}
  #assistant_drawer .kt-alert-light.kt-alert-success .kt-alert-icon > i,
  #assistant_drawer .kt-alert-light.kt-alert-success .kt-alert-title{color:var(--color-green-500);}
  {{-- Description stays neutral: the colour is the signal, the message still has to be readable. --}}
  #assistant_drawer .kt-alert-light .kt-alert-description{color:var(--muted-foreground);}
 </style>
 <div class="flex items-center justify-between gap-2.5 text-sm text-mono font-semibold px-5 py-3.5">
  <span class="flex items-center gap-2">
   Academic assistant
   <span class="kt-badge kt-badge-sm kt-badge-outline">Your classes only</span>
  </span>
  <span class="flex items-center gap-1.5">
   <button class="kt-btn kt-btn-sm kt-btn-icon kt-btn-dim shrink-0" type="button" id="assistant_clear" title="Clear conversation">
    <i class="ki-filled ki-eraser"></i>
   </button>
   <button class="kt-btn kt-btn-sm kt-btn-icon kt-btn-dim shrink-0" data-kt-drawer-dismiss="true">
    <i class="ki-filled ki-cross"></i>
   </button>
  </span>
 </div>
 <div class="border-b border-b-border"></div>
 <input type="hidden" id="assistant_csrf_token" value="{{ csrf_token() }}">

 <div class="grow assistant-scroll px-5 py-4 flex flex-col gap-3" id="assistant_log">
  <div class="text-sm text-secondary-foreground assistant-bubble">Ask me about your students, subjects, sections, assessments, quizzes, scores, grades, and enrollments. I answer from your own records only.</div>
 </div>

 <div class="border-t border-t-border px-5 py-3">
  {{-- The button sits BESIDE the field, not on top of it. The previous version overlaid an
       absolutely-positioned button and reserved room with `pe-20`, but the Metronic bundle is a
       pre-purged Tailwind build that omits utilities the demo never used — the padding silently
       did nothing and long questions ran underneath the button. Layout lives in the scoped CSS
       above for the same reason. --}}
  <div class="assistant-composer">
   <textarea id="assistant_input" rows="1" maxlength="1000" autocomplete="off"
             placeholder="e.g. How did my class do on Quiz 3?"></textarea>
   <button class="kt-btn kt-btn-primary kt-btn-sm" type="button" id="assistant_send">Ask</button>
  </div>
 </div>

 <script nonce="{{ $cspNonce ?? '' }}">
  (function () {
   var messageUrl = '{{ route('educator.assistant.message') }}';
   var resetUrl = '{{ route('educator.assistant.reset') }}';
   var token = document.getElementById('assistant_csrf_token').value;
   var log = document.getElementById('assistant_log');
   var input = document.getElementById('assistant_input');
   var send = document.getElementById('assistant_send');
   var clear = document.getElementById('assistant_clear');
   var busy = false;

   function headers(extra) {
    return Object.assign({ 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json', 'X-CSRF-TOKEN': token }, extra || {});
   }

   // The user's own message is ALWAYS textContent — nothing an educator types is ever parsed as
   // markup. Assistant replies use the server-rendered `html` field, which has been through
   // AssistantGuard::renderHtml (tag allowlist, every attribute stripped). If that field is
   // missing for any reason, we fall back to textContent rather than rendering raw text as HTML.
   function bubble(role, text, html) {
    var wrap = document.createElement('div');
    wrap.className = role === 'user' ? 'flex justify-end' : 'flex justify-start';
    var el = document.createElement('div');
    el.className = 'assistant-bubble text-sm rounded-lg px-3 py-2 max-w-[85%] ' +
     (role === 'user' ? 'bg-primary text-primary-foreground' : 'bg-muted text-mono');
    if (role !== 'user' && html) { el.innerHTML = html; } else { el.textContent = text; }
    wrap.appendChild(el);
    log.appendChild(wrap);
    log.scrollTop = log.scrollHeight;
    return el;
   }

   function fill(el, text, html) {
    if (html) { el.innerHTML = html; } else { el.textContent = text; }
    log.scrollTop = log.scrollHeight;
   }

   // Every non-answer outcome renders as a KTUI alert rather than a reply bubble, so a limit or a
   // refusal never reads like something the assistant "said". Keys are the `status` values
   // App\Services\Ai\AssistantService can return, plus two the client raises on its own —
   // AssistantAlertCoverageTest fails if the server gains a status with no entry here.
   var ALERTS = {
    blocked:          { variant: 'warning',     icon: 'ki-shield-cross',  title: 'Outside what I can answer' },
    rate_limited:     { variant: 'warning',     icon: 'ki-time',          title: 'Usage limit reached' },
    unavailable:      { variant: 'destructive', icon: 'ki-cross-circle',  title: 'Assistant unavailable' },
    not_configured:   { variant: 'destructive', icon: 'ki-cross-circle',  title: 'Assistant not configured' },
    tool_loop:        { variant: 'info',        icon: 'ki-information-2', title: 'Could not complete that' },
    tool_fan_out:     { variant: 'info',        icon: 'ki-information-2', title: 'Could not complete that' },
    exhausted_rounds: { variant: 'info',        icon: 'ki-information-2', title: 'Could not complete that' },
    throttled:        { variant: 'info',        icon: 'ki-time',          title: 'Slow down a moment' },
    network:          { variant: 'destructive', icon: 'ki-cross-circle',  title: 'Connection problem' }
   };

   var alertSeq = 0;

   // Built with DOM nodes and textContent throughout — the copy here is fixed server-side
   // constants, but this path must never become a way to render markup either.
   function renderAlert(wrap, status, text) {
    var spec = ALERTS[status] || ALERTS.unavailable;
    var id = 'assistant_alert_' + (++alertSeq);

    var alert = document.createElement('div');
    alert.className = 'kt-alert kt-alert-light kt-alert-' + spec.variant;
    alert.id = id;

    var icon = document.createElement('div');
    icon.className = 'kt-alert-icon';
    var i = document.createElement('i');
    i.className = 'ki-filled ' + spec.icon;
    icon.appendChild(i);

    var content = document.createElement('div');
    content.className = 'kt-alert-content';
    var title = document.createElement('div');
    title.className = 'kt-alert-title';
    title.textContent = spec.title;
    var desc = document.createElement('div');
    desc.className = 'kt-alert-description';
    desc.textContent = text;
    content.appendChild(title);
    content.appendChild(desc);

    // No dismiss button: these alerts sit inline in the conversation log as a record of what
    // happened, the same way a reply does. There is nothing to clear — the next question pushes
    // them up, and the eraser in the header clears the whole thread.
    alert.appendChild(icon);
    alert.appendChild(content);

    // An alert is a status banner, not a chat turn: full width, no bubble chrome.
    wrap.className = 'assistant-alert-row';
    wrap.innerHTML = '';
    wrap.appendChild(alert);
    log.scrollTop = log.scrollHeight;
   }

   function setBusy(state) {
    busy = state;
    send.disabled = state;
    input.disabled = state;
   }

   // Grow the field to fit what has been typed, up to the max-height in the CSS above, so a long
   // question stays readable instead of scrolling out of sight.
   function autoGrow() {
    input.style.height = 'auto';
    input.style.height = Math.min(input.scrollHeight, 128) + 'px';
   }

   function ask() {
    var text = (input.value || '').trim();
    if (busy || text.length < 2) { return; }

    bubble('user', text);
    input.value = '';
    autoGrow();
    setBusy(true);
    var pending = bubble('assistant', 'Checking your records...');

    fetch(messageUrl, {
     method: 'POST',
     headers: headers({ 'Content-Type': 'application/json' }),
     body: JSON.stringify({ message: text })
    }).then(function (r) {
     // 429 is the per-educator throttle; 422 is the length/format validation. Both are surfaced
     // as alerts, so they carry a status the ALERTS map understands.
     if (r.status === 429) {
      return { status: 'throttled', reply: 'You are sending questions too quickly. Please wait a moment before asking again.' };
     }
     if (r.status === 422) {
      return { status: 'blocked', reply: 'That message is too long. Keep questions under 1000 characters.' };
     }
     return r.json().catch(function () {
      return { status: 'unavailable', reply: 'The assistant is unavailable right now.' };
     });
    }).then(function (data) {
     var status = (data && data.status) ? data.status : 'unavailable';
     var text = (data && data.reply) ? data.reply : 'The assistant is unavailable right now.';

     if (status === 'ok') {
      fill(pending, text, data && data.html);
     } else {
      renderAlert(pending.parentNode, status, text);
     }
    }).catch(function () {
     // No auto-retry: a retry loop here would burn the shared daily request allowance.
     renderAlert(pending.parentNode, 'network', 'Could not reach the assistant. Check your connection and try again.');
    }).finally(function () {
     setBusy(false);
     input.focus();
    });
   }

   send.addEventListener('click', ask);
   input.addEventListener('input', autoGrow);
   // Enter sends; Shift+Enter is a newline, which is why this is a textarea and not an input.
   input.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); ask(); }
   });

   clear.addEventListener('click', function () {
    fetch(resetUrl, { method: 'DELETE', headers: headers() }).finally(function () {
     log.innerHTML = '';
     bubble('assistant', 'Conversation cleared. Ask me about your students, classes, assessments, or scores.');
    });
   });
  })();
 </script>
</div>
<!--End of Assistant Drawer-->
<!-- End of AI Assistant -->
