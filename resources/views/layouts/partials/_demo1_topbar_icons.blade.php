{{-- Verbatim demo1 header topbar icons: search, notifications, chat, apps (index.html:3256-4989). Opens the .flex items-center gap-2.5 topbar wrapper; the User dropdown + wrapper close follow in app.blade.php. Demo content kept as-is per spec. --}}
      <!-- Topbar -->
      <div class="flex items-center gap-2.5">
       {{-- Search icon disabled: the demo search modal is non-functional in this app. --}}
       {{--
       <button class="group kt-btn kt-btn-ghost kt-btn-icon size-9 rounded-full hover:bg-primary/10 hover:[&_i]:text-primary" data-kt-modal-toggle="#search_modal">
        <i class="ki-filled ki-magnifier text-lg group-hover:text-primary"></i>
       </button>
       --}}
       <!-- Notifications -->
       @php $bellTotal = ((int) ($unreadCount ?? 0)) + ((int) ($messageUnreadCount ?? 0)); @endphp
       <button class="relative kt-btn kt-btn-ghost kt-btn-icon size-9 rounded-full hover:bg-primary/10 hover:[&_i]:text-primary" data-kt-drawer-toggle="#notifications_drawer" id="notifications_bell_btn">
        <i class="ki-filled ki-notification-status text-lg">
        </i>
        @if ($bellTotal > 0)
        <span class="absolute top-1 -end-1 flex items-center justify-center size-[18px] rounded-full bg-destructive text-white text-[10px] font-semibold leading-none" id="notifications_bell_dot">{{ $bellTotal > 9 ? '9+' : $bellTotal }}</span>
        @endif
       </button>
       <!--Notifications Drawer-->
       <div class="hidden kt-drawer kt-drawer-end card flex-col max-w-[90%] w-[450px] top-5 bottom-5 end-5 rounded-xl border border-border" data-kt-drawer="true" data-kt-drawer-container="body" id="notifications_drawer">
        <div class="flex items-center justify-between gap-2.5 text-sm text-mono font-semibold px-5 py-2.5 border-b border-b-border" id="notifications_header">
         Notifications
         <button class="kt-btn kt-btn-sm kt-btn-icon kt-btn-dim shrink-0" data-kt-drawer-dismiss="true">
          <i class="ki-filled ki-cross">
          </i>
         </button>
        </div>
        <div class="kt-tabs kt-tabs-line justify-between px-5" data-kt-tabs="true" id="notifications_tabs">
         <div class="flex items-center gap-5">
          <button class="kt-tab-toggle py-3 relative active" data-kt-tab-toggle="#notifications_tab_all">
           All
           @if (($unreadCount ?? 0) > 0)
           <span class="rounded-full bg-destructive size-[5px] absolute top-2 rtl:start-0 end-0 transform translate-y-1/2 translate-x-full" id="notifications_tab_all_dot" data-notification-tab-dot="all"></span>
           @endif
          </button>
          <button class="kt-tab-toggle py-3 relative" data-kt-tab-toggle="#notifications_tab_inbox">
           Inbox
           @if (($messageUnreadCount ?? 0) > 0)
           <span class="rounded-full bg-destructive size-[5px] absolute top-2 rtl:start-0 end-0 transform translate-y-1/2 translate-x-full" id="notifications_tab_inbox_dot" data-notification-tab-dot="inbox"></span>
           @endif
          </button>
          {{-- Team and Following tabs are reserved for a future notification feature. --}}
         </div>
         {{-- The notification settings menu is reserved until its actions are implemented.
         <div class="kt-menu" data-kt-menu="true">
          <div class="kt-menu-item" data-kt-menu-item-offset="0,10px" data-kt-menu-item-placement="bottom-end" data-kt-menu-item-placement-rtl="bottom-start" data-kt-menu-item-toggle="dropdown" data-kt-menu-item-trigger="click|lg:hover">
           <button class="kt-menu-toggle kt-btn kt-btn-icon kt-btn-ghost">
            <i class="ki-filled ki-setting-2">
            </i>
           </button>
           <div class="kt-menu-dropdown kt-menu-default w-full max-w-[175px]" data-kt-menu-dismiss="true">
            <div class="kt-menu-item">
             <a class="kt-menu-link" href="#">
              <span class="kt-menu-icon">
               <i class="ki-filled ki-document">
               </i>
              </span>
              <span class="kt-menu-title">
               View
              </span>
             </a>
            </div>
            <div class="kt-menu-item" data-kt-menu-item-offset="-15px, 0" data-kt-menu-item-placement="right-start" data-kt-menu-item-toggle="dropdown" data-kt-menu-item-trigger="click|lg:hover">
             <div class="kt-menu-link">
              <span class="kt-menu-icon">
               <i class="ki-filled ki-notification-status">
               </i>
              </span>
              <span class="kt-menu-title">
               Export
              </span>
              <span class="kt-menu-arrow">
               <i class="ki-filled ki-right text-xs rtl:transform rtl:rotate-180">
               </i>
              </span>
             </div>
             <div class="kt-menu-dropdown kt-menu-default w-full max-w-[175px]">
              <div class="kt-menu-item">
               <a class="kt-menu-link" href="html/demo1/account/home/settings-sidebar.html">
                <span class="kt-menu-icon">
                 <i class="ki-filled ki-sms">
                 </i>
                </span>
                <span class="kt-menu-title">
                 Email
                </span>
               </a>
              </div>
              <div class="kt-menu-item">
               <a class="kt-menu-link" href="html/demo1/account/home/settings-sidebar.html">
                <span class="kt-menu-icon">
                 <i class="ki-filled ki-message-notify">
                 </i>
                </span>
                <span class="kt-menu-title">
                 SMS
                </span>
               </a>
              </div>
              <div class="kt-menu-item">
               <a class="kt-menu-link" href="html/demo1/account/home/settings-sidebar.html">
                <span class="kt-menu-icon">
                 <i class="ki-filled ki-notification-status">
                 </i>
                </span>
                <span class="kt-menu-title">
                 Push
                </span>
               </a>
              </div>
             </div>
            </div>
            <div class="kt-menu-item">
             <a class="kt-menu-link" href="#">
              <span class="kt-menu-icon">
               <i class="ki-filled ki-pencil">
               </i>
              </span>
              <span class="kt-menu-title">
               Edit
              </span>
             </a>
            </div>
            <div class="kt-menu-item">
             <a class="kt-menu-link" href="#">
              <span class="kt-menu-icon">
               <i class="ki-filled ki-trash">
               </i>
              </span>
              <span class="kt-menu-title">
               Delete
              </span>
             </a>
            </div>
           </div>
          </div>
         </div> --}}
        </div>
        <div class="grow flex flex-col" id="notifications_tab_all">
         <div class="grow kt-scrollable-y-auto" data-kt-scrollable="true" data-kt-scrollable-dependencies="#header" data-kt-scrollable-max-height="auto" data-kt-scrollable-offset="150px">
          <div class="grow flex flex-col" id="notifications_list">
           @include('layouts.partials._notification_items', ['notifications' => $notifications ?? []])
          </div>
         </div>
         <div class="border-b border-b-border">
         </div>
         <div class="grid grid-cols-2 p-5 gap-2.5" id="notifications_all_footer">
          <form action="{{ route('notifications.read-all') }}" method="POST" id="notifications_mark_all_form">
           @csrf
           <button class="kt-btn kt-btn-outline justify-center w-full" type="submit">
            Mark all as read
           </button>
          </form>
          <form action="{{ route('notifications.destroy-all') }}" method="POST" id="notifications_delete_all_form">
           @csrf
           @method('DELETE')
           <button class="kt-btn kt-btn-outline kt-btn-destructive justify-center w-full" type="submit">
            Delete all
           </button>
          </form>
         </div>
         <script nonce="{{ $cspNonce ?? '' }}">
          (function () {
           var indexUrl = '{{ route('notifications.index') }}';
           var readAllUrl = '{{ route('notifications.read-all') }}';
           var deleteAllUrl = '{{ route('notifications.destroy-all') }}';
           var btn = document.getElementById('notifications_bell_btn');
           var form = document.getElementById('notifications_mark_all_form');
           var deleteForm = document.getElementById('notifications_delete_all_form');
           var list = document.getElementById('notifications_list');
           var tabs = document.getElementById('notifications_tabs');
           var badgeCls = 'absolute top-1 -end-1 flex items-center justify-center size-[18px] rounded-full bg-destructive text-white text-[10px] font-semibold leading-none';

           // Shared unread state so the bell shows notifications + messages combined; the chat
           // script (below) updates .msg. qyzenRenderBell is the single writer of the bell badge.
           window.qyzenUnread = window.qyzenUnread || { notif: {{ (int) ($unreadCount ?? 0) }}, msg: {{ (int) ($messageUnreadCount ?? 0) }} };
           window.qyzenRenderBell = function () { setBadge((window.qyzenUnread.notif || 0) + (window.qyzenUnread.msg || 0)); };
           var dismissedTabs = { all: false, inbox: false };

           function renderTabDot(tab, count) {
            var id = 'notifications_tab_' + tab + '_dot';
            var dot = document.getElementById(id);
            if (dismissedTabs[tab] || !count) { if (dot) { dot.remove(); } return; }
            if (!dot) {
             dot = document.createElement('span'); dot.id = id; dot.dataset.notificationTabDot = tab;
             dot.className = 'rounded-full bg-destructive size-[5px] absolute top-2 rtl:start-0 end-0 transform translate-y-1/2 translate-x-full';
             var trigger = tabs && tabs.querySelector('[data-kt-tab-toggle="#notifications_tab_' + tab + '"]');
             if (trigger) { trigger.appendChild(dot); }
            }
           }

           function renderTabDots() { renderTabDot('all', window.qyzenUnread.notif || 0); renderTabDot('inbox', window.qyzenUnread.msg || 0); }
           window.qyzenRenderTabDots = renderTabDots;
           if (tabs) tabs.addEventListener('click', function (e) {
            var trigger = e.target.closest('[data-kt-tab-toggle]');
            var tab = trigger && trigger.getAttribute('data-kt-tab-toggle') === '#notifications_tab_all' ? 'all' : 'inbox';
            if (trigger) { dismissedTabs[tab] = true; renderTabDots(); }
           });

           function setBadge(count) {
            var dot = document.getElementById('notifications_bell_dot');
            if (!count || count < 1) { if (dot) { dot.remove(); } return; }
            if (!dot) {
             dot = document.createElement('span');
             dot.id = 'notifications_bell_dot';
             dot.className = badgeCls;
             if (btn) { btn.appendChild(dot); }
            }
            dot.textContent = count > 9 ? '9+' : String(count);
           }

           function stopLoading(form) {
            form.querySelectorAll('button[data-spinning]').forEach(function (button) {
             button.disabled = false;
             button.removeAttribute('aria-busy');
             delete button.dataset.spinning;
             var icon = button.querySelector('i.ki-loading');
             if (icon) icon.remove();
            });
           }

           if (form) {
            form.addEventListener('submit', function (e) {
             e.preventDefault();
             var token = form.querySelector('input[name=_token]').value;
             fetch(readAllUrl, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': token, 'Accept': 'application/json' } })
              .then(function (r) { if (!r.ok) throw r; return r.json(); })
              .then(function () {
               document.querySelectorAll('[data-kt-notif-item]').forEach(function (el) {
                el.classList.remove('bg-primary/5');
                var d = el.querySelector('.kt-avatar-status');
                if (d) { d.classList.remove('kt-avatar-status-online'); d.classList.add('kt-avatar-status-offline'); }
               });
               window.qyzenUnread.notif = 0; window.qyzenRenderBell(); renderTabDots();
              })
              .catch(function () { form.submit(); })
              .finally(function () { stopLoading(form); });
            });
           }

           if (deleteForm) {
            deleteForm.addEventListener('submit', function (e) {
             e.preventDefault();
             var token = deleteForm.querySelector('input[name=_token]').value;
             fetch(deleteAllUrl, { method: 'DELETE', headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': token, 'Accept': 'application/json' } })
              .then(function (r) { if (!r.ok) throw r; return r.json(); })
              .then(function (data) {
               if (list && typeof data.html === 'string') { list.innerHTML = data.html; }
               window.qyzenUnread.notif = 0; window.qyzenRenderBell(); renderTabDots();
              })
              .catch(function () { deleteForm.submit(); })
              .finally(function () { stopLoading(deleteForm); });
            });
           }

           // ponytail: dumb 30s polling reusing the server-rendered fragment; swap to Reverb only if this ever matters.
           function poll() {
            fetch(indexUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
             .then(function (r) { if (!r.ok) throw r; return r.json(); })
             .then(function (data) {
              if (list && typeof data.html === 'string') { list.innerHTML = data.html; }
              window.qyzenUnread.notif = data.unread_count; window.qyzenRenderBell(); renderTabDots();
             })
             .catch(function () {});
           }
           setInterval(poll, 30000);
          })();
         </script>
        </div>
        <div class="grow flex flex-col hidden" id="notifications_tab_inbox">
         <div class="grow kt-scrollable-y-auto" data-kt-scrollable="true" data-kt-scrollable-dependencies="#header" data-kt-scrollable-max-height="auto" data-kt-scrollable-offset="150px">
          <div class="grow flex flex-col" id="conversations_list">
           @include('layouts.partials._conversation_list_items', ['rows' => $conversations ?? []])
          </div>
         </div>
        </div>
        <div class="grow flex flex-col hidden" id="notifications_tab_team">
         <div class="grow kt-scrollable-y-auto" data-kt-scrollable="true" data-kt-scrollable-dependencies="#header" data-kt-scrollable-max-height="auto" data-kt-scrollable-offset="150px">
          <div class="flex flex-col gap-5 pt-3 pb-4">
           <div class="flex grow gap-2 px-5">
            <div class="kt-avatar size-8">
             <div class="kt-avatar-image">
              <img alt="avatar" src="{{ asset('metronic-tailwind-html-demos/dist/assets/media/avatars/300-15.png') }}"/>
             </div>
             <div class="kt-avatar-indicator -end-2 -bottom-2">
              <div class="kt-avatar-status kt-avatar-status-online size-2.5">
              </div>
             </div>
            </div>
            <div class="flex flex-col gap-3 grow" id="notification_request_10">
             <div class="flex flex-col gap-1">
              <div class="text-sm font-medium mb-px">
               <a class="hover:text-primary text-mono font-semibold" href="#">
                Nova Hawthorne
               </a>
               <span class="text-secondary-foreground">
                sent you an meeting invation
               </span>
              </div>
              <span class="flex items-center text-xs font-medium text-muted-foreground">
               2 days ago
               <span class="rounded-full size-1 bg-mono/30 mx-1.5">
               </span>
               Dev Team
              </span>
             </div>
             <div class="kt-card shadow-none p-2.5 rounded-lg bg-muted/70">
              <div class="flex items-center justify-between flex-wrap gap-2.5">
               <div class="flex items-center gap-2.5">
                <div class="border border-primary/10 rounded-lg">
                 <div class="flex items-center justify-center border-b border-b-primary/10 bg-primary/10 rounded-t-lg">
                  <span class="text-xs text-primary fw-medium p-1.5">
                   Apr
                  </span>
                 </div>
                 <div class="flex items-center justify-center size-9">
                  <span class="fw-semibold text-mono text-md tracking-tight">
                   12
                  </span>
                 </div>
                </div>
                <div class="flex flex-col gap-1.5">
                 <a class="hover:text-primary font-medium text-secondary-foreground text-xs" href="#">
                  Peparation For Release
                 </a>
                 <span class="font-medium text-secondary-foreground text-xs">
                  9:00 PM - 10:00 PM
                 </span>
                </div>
               </div>
               <div class="flex -space-x-2">
                <div class="flex">
                 <img class="hover:z-5 relative shrink-0 rounded-full ring-1 ring-background size-6" src="{{ asset('metronic-tailwind-html-demos/dist/assets/media/avatars/300-4.png') }}"/>
                </div>
                <div class="flex">
                 <img class="hover:z-5 relative shrink-0 rounded-full ring-1 ring-background size-6" src="{{ asset('metronic-tailwind-html-demos/dist/assets/media/avatars/300-1.png') }}"/>
                </div>
                <div class="flex">
                 <img class="hover:z-5 relative shrink-0 rounded-full ring-1 ring-background size-6" src="{{ asset('metronic-tailwind-html-demos/dist/assets/media/avatars/300-2.png') }}"/>
                </div>
                <div class="flex">
                 <span class="hover:z-5 relative inline-flex items-center justify-center shrink-0 rounded-full ring-1 font-semibold leading-none text-2xs size-6 text-white size-6 ring-background bg-green-500">
                  +3
                 </span>
                </div>
               </div>
              </div>
             </div>
             <div class="flex flex-wrap gap-2.5">
              <button class="kt-btn kt-btn-outline kt-btn-sm" data-kt-dismiss="#notification_request_10">
               Decline
              </button>
              <button class="kt-btn kt-btn-mono kt-btn-sm" data-kt-dismiss="#notification_request_10">
               Accept
              </button>
             </div>
            </div>
           </div>
           <div class="border-b border-b-border">
           </div>
           <div class="flex grow gap-2.5 px-5">
            <div class="kt-avatar size-8">
             <div class="kt-avatar-image">
              <img alt="avatar" src="{{ asset('metronic-tailwind-html-demos/dist/assets/media/avatars/300-6.png') }}">
              </img>
             </div>
             <div class="kt-avatar-indicator -end-2 -bottom-2">
              <div class="kt-avatar-status kt-avatar-status-online size-2.5">
              </div>
             </div>
            </div>
            <div class="flex flex-col gap-1">
             <div class="text-sm font-medium mb-px">
              <a class="hover:text-primary text-mono font-semibold" href="#">
               Adrian Vale
              </a>
              <span class="text-secondary-foreground">
               change the due date of
              </span>
              <a class="hover:text-primary text-primary" href="#">
               Marketing
              </a>
              to 13 May
             </div>
             <span class="flex items-center text-xs font-medium text-muted-foreground">
              2 days ago
              <span class="rounded-full size-1 bg-mono/30 mx-1.5">
              </span>
              Marketing
             </span>
            </div>
           </div>
           <div class="border-b border-b-border">
           </div>
           <div class="flex grow gap-2.5 px-5">
            <div class="kt-avatar size-8">
             <div class="kt-avatar-image">
              <img alt="avatar" src="{{ asset('metronic-tailwind-html-demos/dist/assets/media/avatars/300-12.png') }}">
              </img>
             </div>
             <div class="kt-avatar-indicator -end-2 -bottom-2">
              <div class="kt-avatar-status kt-avatar-status-online size-2.5">
              </div>
             </div>
            </div>
            <div class="flex flex-col gap-3.5 grow">
             <div class="flex flex-col gap-1">
              <div class="text-sm font-medium mb-px">
               <a class="hover:text-primary text-mono font-semibold" href="#">
                Skylar Frost
               </a>
               <span class="text-secondary-foreground">
                uploaded 2 attachments
               </span>
              </div>
              <span class="flex items-center text-xs font-medium text-muted-foreground">
               3 days ago
               <span class="rounded-full size-1 bg-mono/30 mx-1.5">
               </span>
               Web Design
              </span>
             </div>
             <div class="kt-card shadow-none flex items-center justify-between flex-row gap-1.5 p-2.5 rounded-lg bg-muted/70">
              <div class="flex items-center gap-1.5">
               <img class="h-6" src="{{ asset('metronic-tailwind-html-demos/dist/assets/media/file-types/word.svg') }}"/>
               <div class="flex flex-col gap-0.5">
                <a class="hover:text-primary font-medium text-secondary-foreground text-xs" href="#">
                 Landing-page.docx
                </a>
                <span class="font-medium text-muted-foreground text-xs">
                 1.9 MB
                </span>
               </div>
              </div>
              <button class="kt-btn kt-btn-sm kt-btn-icon kt-btn-ghost">
               <svg fill="none" height="14" viewbox="0 0 14 14" width="14" xmlns="http://www.w3.org/2000/svg">
                <path clip-rule="evenodd" d="M6.63821 2.60467C4.81926 2.60467 3.32474 3.99623 3.16201 5.77252C3.1386 6.02803 2.92413 6.22253 2.66871 6.22227C1.74915 6.22149 0.976744 6.9868 0.976744 7.90442C0.976744 8.83344 1.72988 9.58657 2.65891 9.58657H3.09302C3.36274 9.58657 3.5814 9.80523 3.5814 10.0749C3.5814 10.3447 3.36274 10.5633 3.09302 10.5633H2.65891C1.19044 10.5633 0 9.37292 0 7.90442C0 6.58614 0.986948 5.48438 2.24496 5.27965C2.62863 3.20165 4.44941 1.62793 6.63821 1.62793C8.26781 1.62793 9.69282 2.50042 10.4729 3.80193C12.3411 3.72829 14 5.2564 14 7.18091C14 8.93508 12.665 10.3769 10.9552 10.5466C10.6868 10.5733 10.4476 10.3773 10.421 10.1089C10.3943 9.84052 10.5903 9.60135 10.8587 9.57465C12.0739 9.45406 13.0233 8.42802 13.0233 7.18091C13.0233 5.74002 11.6905 4.59666 10.2728 4.79968C10.0642 4.82957 9.85672 4.72382 9.76028 4.53181C9.18608 3.38796 8.00318 2.60467 6.63821 2.60467Z" fill="#99A1B7" fill-rule="evenodd">
                </path>
                <path clip-rule="evenodd" d="M6.99909 8.01611L8.28162 9.29864C8.47235 9.48937 8.78158 9.48937 8.97231 9.29864C9.16303 9.10792 9.16303 8.79874 8.97231 8.60802L7.57465 7.2103C7.25675 6.89247 6.74143 6.89247 6.42353 7.2103L5.02585 8.60802C4.83513 8.79874 4.83513 9.10792 5.02585 9.29864C5.21657 9.48937 5.5258 9.48937 5.71649 9.29864L6.99909 8.01611Z" fill="#99A1B7" fill-rule="evenodd">
                </path>
                <path clip-rule="evenodd" d="M7.00009 12.372C7.2698 12.372 7.48846 12.1533 7.48846 11.8836V7.97665C7.48846 7.70694 7.2698 7.48828 7.00009 7.48828C6.73038 7.48828 6.51172 7.70694 6.51172 7.97665V11.8836C6.51172 12.1533 6.73038 12.372 7.00009 12.372Z" fill="#99A1B7" fill-rule="evenodd">
                </path>
               </svg>
              </button>
             </div>
             <div class="kt-card shadow-none flex items-center justify-between flex-row gap-1.5 p-2.5 rounded-lg bg-muted/70">
              <div class="flex items-center gap-1.5">
               <img class="h-6" src="{{ asset('metronic-tailwind-html-demos/dist/assets/media/file-types/svg.svg') }}"/>
               <div class="flex flex-col gap-0.5">
                <a class="hover:text-primary font-medium text-secondary-foreground text-xs" href="#">
                 New-icon.svg
                </a>
                <span class="font-medium text-muted-foreground text-xs">
                 2.3 MB
                </span>
               </div>
              </div>
              <button class="kt-btn kt-btn-sm kt-btn-icon kt-btn-ghost">
               <svg fill="none" height="14" viewbox="0 0 14 14" width="14" xmlns="http://www.w3.org/2000/svg">
                <path clip-rule="evenodd" d="M6.63821 2.60467C4.81926 2.60467 3.32474 3.99623 3.16201 5.77252C3.1386 6.02803 2.92413 6.22253 2.66871 6.22227C1.74915 6.22149 0.976744 6.9868 0.976744 7.90442C0.976744 8.83344 1.72988 9.58657 2.65891 9.58657H3.09302C3.36274 9.58657 3.5814 9.80523 3.5814 10.0749C3.5814 10.3447 3.36274 10.5633 3.09302 10.5633H2.65891C1.19044 10.5633 0 9.37292 0 7.90442C0 6.58614 0.986948 5.48438 2.24496 5.27965C2.62863 3.20165 4.44941 1.62793 6.63821 1.62793C8.26781 1.62793 9.69282 2.50042 10.4729 3.80193C12.3411 3.72829 14 5.2564 14 7.18091C14 8.93508 12.665 10.3769 10.9552 10.5466C10.6868 10.5733 10.4476 10.3773 10.421 10.1089C10.3943 9.84052 10.5903 9.60135 10.8587 9.57465C12.0739 9.45406 13.0233 8.42802 13.0233 7.18091C13.0233 5.74002 11.6905 4.59666 10.2728 4.79968C10.0642 4.82957 9.85672 4.72382 9.76028 4.53181C9.18608 3.38796 8.00318 2.60467 6.63821 2.60467Z" fill="#99A1B7" fill-rule="evenodd">
                </path>
                <path clip-rule="evenodd" d="M6.99909 8.01611L8.28162 9.29864C8.47235 9.48937 8.78158 9.48937 8.97231 9.29864C9.16303 9.10792 9.16303 8.79874 8.97231 8.60802L7.57465 7.2103C7.25675 6.89247 6.74143 6.89247 6.42353 7.2103L5.02585 8.60802C4.83513 8.79874 4.83513 9.10792 5.02585 9.29864C5.21657 9.48937 5.5258 9.48937 5.71649 9.29864L6.99909 8.01611Z" fill="#99A1B7" fill-rule="evenodd">
                </path>
                <path clip-rule="evenodd" d="M7.00009 12.372C7.2698 12.372 7.48846 12.1533 7.48846 11.8836V7.97665C7.48846 7.70694 7.2698 7.48828 7.00009 7.48828C6.73038 7.48828 6.51172 7.70694 6.51172 7.97665V11.8836C6.51172 12.1533 6.73038 12.372 7.00009 12.372Z" fill="#99A1B7" fill-rule="evenodd">
                </path>
               </svg>
              </button>
             </div>
            </div>
           </div>
           <div class="border-b border-b-border">
           </div>
           <div class="flex grow gap-2.5 px-5">
            <div class="kt-avatar size-8">
             <div class="kt-avatar-image">
              <img alt="avatar" src="{{ asset('metronic-tailwind-html-demos/dist/assets/media/avatars/300-21.png') }}">
              </img>
             </div>
             <div class="kt-avatar-indicator -end-2 -bottom-2">
              <div class="kt-avatar-status kt-avatar-status-online size-2.5">
              </div>
             </div>
            </div>
            <div class="flex flex-col gap-3.5">
             <div class="flex flex-col gap-1">
              <div class="text-sm font-medium">
               <a class="hover:text-primary text-mono font-semibold" href="#">
                Selene Silverleaf
               </a>
               <span class="text-secondary-foreground">
                commented on
               </span>
               <a class="hover:text-primary text-primary" href="#">
                SiteSculpt
               </a>
               <span class="text-secondary-foreground">
               </span>
              </div>
              <span class="flex items-center text-xs font-medium text-muted-foreground">
               4 days ago
               <span class="rounded-full size-1 bg-mono/30 mx-1.5">
               </span>
               Manager
              </span>
             </div>
             <div class="kt-card shadow-none flex flex-col gap-2.5 p-3.5 rounded-lg bg-muted/70">
              <div class="text-sm font-semibold text-secondary-foreground mb-px">
               <a class="hover:text-primary text-mono font-semibold" href="#">
                @Cody
               </a>
               <span class="text-secondary-foreground font-medium">
                This
		design is simply stunning! From layout to color, it's a work of art!
               </span>
              </div>
              <div class="kt-input">
               <input placeholder="Reply" type="text" value=""/>
               <button class="kt-btn kt-btn-ghost kt-btn-icon size-6 -me-1.5">
                <i class="ki-filled ki-picture">
                </i>
               </button>
              </div>
             </div>
            </div>
           </div>
           <div class="border-b border-b-border">
           </div>
           <div class="flex grow gap-2.5 px-5" id="notification_request_3">
            <div class="kt-avatar size-8">
             <div class="kt-avatar-image">
              <img alt="avatar" src="{{ asset('metronic-tailwind-html-demos/dist/assets/media/avatars/300-13.png') }}">
              </img>
             </div>
             <div class="kt-avatar-indicator -end-2 -bottom-2">
              <div class="kt-avatar-status kt-avatar-status-online size-2.5">
              </div>
             </div>
            </div>
            <div class="flex flex-col gap-3.5">
             <div class="flex flex-col gap-1">
              <div class="text-sm font-medium mb-px">
               <a class="hover:text-primary text-mono font-semibold" href="#">
                Thalia Fox
               </a>
               <span class="text-secondary-foreground">
                has invited you
		to join
               </span>
               <a class="hover:text-primary text-primary" href="#">
                Design Research
               </a>
               <span class="text-secondary-foreground">
               </span>
              </div>
              <span class="flex items-center text-xs font-medium text-muted-foreground">
               4 days ago
               <span class="rounded-full size-1 bg-mono/30 mx-1.5">
               </span>
               Dev
		Team
              </span>
             </div>
             <div class="flex flex-wrap gap-2.5">
              <button class="kt-btn kt-btn-outline kt-btn-sm" data-kt-dismiss="#notification_request_3">
               Decline
              </button>
              <button class="kt-btn kt-btn-mono kt-btn-sm" data-kt-dismiss="#notification_request_3">
               Accept
              </button>
             </div>
            </div>
           </div>
          </div>
         </div>
         <div class="border-b border-b-border">
         </div>
         <div class="grid grid-cols-2 p-5 gap-2.5" id="notifications_team_footer">
          <button class="kt-btn kt-btn-outline justify-center">
           Archive all
          </button>
          <button class="kt-btn kt-btn-outline justify-center">
           Mark all as read
          </button>
         </div>
        </div>
        <div class="grow flex flex-col hidden" id="notifications_tab_following">
         <div class="grow kt-scrollable-y-auto" data-kt-scrollable="true" data-kt-scrollable-dependencies="#header" data-kt-scrollable-max-height="auto" data-kt-scrollable-offset="150px">
          <div class="flex flex-col gap-5 pt-3 pb-4">
           <div class="flex grow gap-2.5 px-5">
            <div class="kt-avatar size-8">
             <div class="kt-avatar-image">
              <img alt="avatar" src="{{ asset('metronic-tailwind-html-demos/dist/assets/media/avatars/300-1.png') }}">
              </img>
             </div>
             <div class="kt-avatar-indicator -end-2 -bottom-2">
              <div class="kt-avatar-status kt-avatar-status-online size-2.5">
              </div>
             </div>
            </div>
            <div class="flex flex-col gap-2.5 grow">
             <div class="flex flex-col gap-1 mb-1">
              <div class="text-sm font-medium mb-px">
               <a class="hover:text-primary text-mono font-semibold" href="#">
                Jane Perez
               </a>
               <span class="text-secondary-foreground">
                added 2 new works to
               </span>
               <a class="hover:text-primary text-primary font-semibold" href="#">
                Inspirations 2024
               </a>
              </div>
              <span class="flex items-center text-xs font-medium text-muted-foreground">
               23 hours ago
               <span class="rounded-full size-1 bg-mono/30 mx-1.5">
               </span>
               Craftwork Design
              </span>
             </div>
             <div class="flex items-center gap-2.5">
              <div class="kt-card shadow-none flex flex-col gap-3.5 bg-muted/70 w-40">
               <div class="bg-cover bg-no-repeat kt-card-rounded-t shrink-0 h-24" style="background-image: url('{{ asset('metronic-tailwind-html-demos/dist/assets/media/images/600x600/6.jpg') }}')">
               </div>
               <div class="px-2.5 pb-2">
                <a class="font-medium block text-secondary-foreground hover:text-primary text-xs leading-4 mb-0.5" href="#">
                 Geometric Patterns
                </a>
                <div class="text-xs font-medium text-muted-foreground">
                 Token ID:
                 <span class="text-xs font-medium text-secondary-foreground">
                  81023
                 </span>
                </div>
               </div>
              </div>
              <div class="kt-card shadow-none flex flex-col gap-3.5 bg-muted/70 w-40">
               <div class="bg-cover bg-no-repeat kt-card-rounded-t shrink-0 h-24" style="background-image: url('{{ asset('metronic-tailwind-html-demos/dist/assets/media/images/600x600/1.jpg') }}')">
               </div>
               <div class="px-2.5 pb-2">
                <a class="font-medium block text-secondary-foreground hover:text-primary text-xs leading-4 mb-0.5" href="#">
                 Artistic Expressions
                </a>
                <div class="text-xs font-medium text-muted-foreground">
                 Token ID:
                 <span class="text-xs font-medium text-secondary-foreground">
                  67890
                 </span>
                </div>
               </div>
              </div>
             </div>
            </div>
           </div>
           <div class="border-b border-b-border">
           </div>
           <div class="flex grow gap-2.5 px-5" id="notification_request_17">
            <div class="kt-avatar size-8">
             <div class="kt-avatar-image">
              <img alt="avatar" src="{{ asset('metronic-tailwind-html-demos/dist/assets/media/avatars/300-19.png') }}"/>
             </div>
             <div class="kt-avatar-indicator -end-2 -bottom-2">
              <div class="kt-avatar-status kt-avatar-status-online size-2.5">
              </div>
             </div>
            </div>
            <div class="flex flex-col gap-2.5 grow">
             <div class="flex flex-col gap-1 mb-1">
              <div class="text-sm font-medium mb-px">
               <a class="hover:text-primary text-mono font-semibold" href="#">
                Natalie Wood
               </a>
               <span class="text-secondary-foreground">
                wants to edit marketing project
               </span>
              </div>
              <span class="flex items-center text-xs font-medium text-muted-foreground">
               1 day ago
               <span class="rounded-full size-1 bg-mono/30 mx-1.5">
               </span>
               Designer
              </span>
             </div>
             <div class="kt-card shadow-none flex items-center flex-row gap-1.5 p-2.5 rounded-lg bg-muted/70">
              <div class="flex items-center justify-center w-[26px] h-[30px] shrink-0 bg-white rounded-sm border border-border">
               <img class="h-5" src="{{ asset('metronic-tailwind-html-demos/dist/assets/media/brand-logos/jira.svg') }}"/>
              </div>
              <a class="hover:text-primary font-medium text-secondary-foreground text-xs me-1" href="#">
               User-feedback.jira
              </a>
              <span class="font-medium text-muted-foreground text-xs">
               Edited 1 hour ago
              </span>
             </div>
             <div class="flex flex-wrap gap-2.5">
              <button class="kt-btn kt-btn-outline kt-btn-sm" data-kt-dismiss="#notification_request_17">
               Decline
              </button>
              <button class="kt-btn kt-btn-mono kt-btn-sm" data-kt-dismiss="#notification_request_17">
               Accept
              </button>
             </div>
            </div>
           </div>
           <div class="border-b border-b-border">
           </div>
           <div class="flex grow gap-2.5 px-5">
            <div class="kt-avatar size-8">
             <div class="kt-avatar-image">
              <img alt="avatar" src="{{ asset('metronic-tailwind-html-demos/dist/assets/media/avatars/300-17.png') }}"/>
             </div>
             <div class="kt-avatar-indicator -end-2 -bottom-2">
              <div class="kt-avatar-status kt-avatar-status-online size-2.5">
              </div>
             </div>
            </div>
            <div class="flex flex-col gap-2.5 grow">
             <div class="flex flex-col gap-1 mb-1">
              <div class="text-sm font-medium mb-px">
               <a class="hover:text-primary text-mono font-semibold" href="#">
                Aaron Foster
               </a>
               <span class="text-secondary-foreground">
                requested to view
               </span>
              </div>
              <span class="flex items-center text-xs font-medium text-muted-foreground">
               3 day ago
               <span class="rounded-full size-1 bg-mono/30 mx-1.5">
               </span>
               Larsen Ltd
              </span>
             </div>
             <div class="kt-card shadow-none flex items-center flex-row gap-1.5 px-2.5 py-1.5 rounded-lg bg-muted/70">
              <i class="ki-filled ki-user-tick text-green-500 text-base">
              </i>
              <span class="font-medium text-green-500 text-sm">
               You allowed Aaron to view
              </span>
             </div>
            </div>
           </div>
           <div class="border-b border-b-border">
           </div>
           <div class="flex grow gap-2.5 px-5">
            <div class="kt-avatar size-8">
             <div class="kt-avatar-image">
              <img alt="avatar" src="{{ asset('metronic-tailwind-html-demos/dist/assets/media/avatars/300-34.png') }}"/>
             </div>
             <div class="kt-avatar-indicator -end-2 -bottom-2">
              <div class="kt-avatar-status kt-avatar-status-online size-2.5">
              </div>
             </div>
            </div>
            <div class="flex flex-col gap-1">
             <div class="text-sm font-medium mb-px">
              <a class="hover:text-primary text-mono font-semibold" href="#">
               Chloe Morgan
              </a>
              <span class="text-secondary-foreground">
               posted a new
		article
              </span>
              <a class="hover:text-primary text-primary" href="#">
               User Experience
              </a>
             </div>
             <span class="flex items-center text-xs font-medium text-muted-foreground">
              1 day ago
              <span class="rounded-full size-1 bg-mono/30 mx-1.5">
              </span>
              Nexus
             </span>
            </div>
           </div>
           <div class="border-b border-b-border">
           </div>
           <div class="flex grow gap-2.5 px-5">
            <div class="kt-avatar size-8">
             <div class="kt-avatar-image">
              <img alt="avatar" src="{{ asset('metronic-tailwind-html-demos/dist/assets/media/avatars/300-9.png') }}"/>
             </div>
             <div class="kt-avatar-indicator -end-2 -bottom-2">
              <div class="kt-avatar-status kt-avatar-status-online size-2.5">
              </div>
             </div>
            </div>
            <div class="flex flex-col gap-2.5 grow">
             <div class="flex flex-col gap-1 mb-1">
              <div class="text-sm font-medium mb-px">
               <a class="hover:text-primary text-mono font-semibold" href="#">
                Gabriel Bennett
               </a>
               <span class="text-secondary-foreground">
                started connect you
               </span>
              </div>
              <span class="flex items-center text-xs font-medium text-muted-foreground">
               3 day ago
               <span class="rounded-full size-1 bg-mono/30 mx-1.5">
               </span>
               Development
              </span>
             </div>
             <div class="flex flex-wrap gap-2.5">
              <button class="kt-btn kt-btn-sm kt-btn-outline">
               <i class="ki-filled ki-check-circle">
               </i>
               Connected
              </button>
              <button class="kt-btn kt-btn-mono kt-btn-sm">
               Go to profile
              </button>
             </div>
            </div>
           </div>
           <div class="border-b border-b-border">
           </div>
           <div class="flex grow gap-2.5 px-5" id="notification_request_3">
            <div class="kt-avatar size-8">
             <div class="kt-avatar-image">
              <img alt="avatar" src="{{ asset('metronic-tailwind-html-demos/dist/assets/media/avatars/300-13.png') }}"/>
             </div>
             <div class="kt-avatar-indicator -end-2 -bottom-2">
              <div class="kt-avatar-status kt-avatar-status-online size-2.5">
              </div>
             </div>
            </div>
            <div class="flex flex-col gap-3.5">
             <div class="flex flex-col gap-1">
              <div class="text-sm font-medium mb-px">
               <a class="hover:text-primary text-mono font-semibold" href="#">
                Thalia Fox
               </a>
               <span class="text-secondary-foreground">
                has invited you
		to join
               </span>
               <a class="hover:text-primary text-primary" href="#">
                Design Research
               </a>
               <span class="text-secondary-foreground">
               </span>
              </div>
              <span class="flex items-center text-xs font-medium text-muted-foreground">
               4 days ago
               <span class="rounded-full size-1 bg-mono/30 mx-1.5">
               </span>
               Dev
		Team
              </span>
             </div>
             <div class="flex flex-wrap gap-2.5">
              <button class="kt-btn kt-btn-outline kt-btn-sm" data-kt-dismiss="#notification_request_3">
               Decline
              </button>
              <button class="kt-btn kt-btn-mono kt-btn-sm" data-kt-dismiss="#notification_request_3">
               Accept
              </button>
             </div>
            </div>
           </div>
          </div>
         </div>
         <div class="border-b border-b-border">
         </div>
         <div class="grid grid-cols-2 p-5 gap-2.5" id="notifications_following_footer">
          <button class="kt-btn kt-btn-outline justify-center">
           Archive all
          </button>
          <button class="kt-btn kt-btn-outline justify-center">
           Mark all as read
          </button>
         </div>
        </div>
       </div>
       <!--End of Notifications Drawer-->
       <!-- End of Notifications -->
       <!-- Chat -->
       <button class="relative kt-btn kt-btn-ghost kt-btn-icon size-9 rounded-full hover:bg-primary/10 hover:[&_i]:text-primary" data-kt-drawer-toggle="#chat_drawer" id="chat_bell_btn">
        <i class="ki-filled ki-messages text-lg">
        </i>
        @if ((int) ($messageUnreadCount ?? 0) > 0)
        <span class="absolute top-1 -end-1 flex items-center justify-center size-[18px] rounded-full bg-destructive text-white text-[10px] font-semibold leading-none" id="chat_bell_dot">{{ $messageUnreadCount > 9 ? '9+' : $messageUnreadCount }}</span>
        @endif
       </button>
       <!--Chat Drawer-->
       <div class="hidden kt-drawer kt-drawer-end card flex flex-col max-w-[90%] w-[450px] top-5 bottom-5 end-5 rounded-xl border border-border" data-kt-drawer="true" data-kt-drawer-container="body" id="chat_drawer">
        {{-- The static Metronic CSS bundle ships a pre-purged Tailwind build that OMITS the
             `min-h-0` and `overflow-y-auto` utilities, so the flex-column scroll must be defined
             here. `display:flex !important` also beats any inline display KTUI sets on open. This is
             what keeps the thread scrolling with the input bar docked, at any message count or zoom. --}}
        <style nonce="{{ $cspNonce ?? '' }}">
         #chat_drawer:not(.hidden){display:flex !important;}
         #chat_drawer_list_state,#chat_drawer_contacts_state,#chat_drawer_thread_state{min-height:0;}
         #chat_drawer .chat-scroll{min-height:0;overflow-y:auto;}
        </style>
        <div class="flex items-center justify-between gap-2.5 text-sm text-mono font-semibold px-5 py-3.5">
         Chat
         <button class="kt-btn kt-btn-sm kt-btn-icon kt-btn-dim shrink-0" data-kt-drawer-dismiss="true">
          <i class="ki-filled ki-cross">
          </i>
         </button>
        </div>
        <div class="border-b border-b-border">
        </div>
        <input type="hidden" id="chat_csrf_token" value="{{ csrf_token() }}">
        <!-- Conversation list state -->
        <div class="grow flex flex-col min-h-0" id="chat_drawer_list_state">
         <div class="flex items-center justify-between gap-2.5 px-5 py-2.5 border-b border-b-border">
          <span class="text-sm font-semibold text-mono">Messages</span>
          <button type="button" class="kt-btn kt-btn-sm kt-btn-outline" id="chat_drawer_compose">
           <i class="ki-filled ki-message-add"></i>
           New message
          </button>
         </div>
         <div class="grow chat-scroll">
          <div class="grow flex flex-col" id="chat_drawer_list">
           @include('layouts.partials._conversation_list_items', ['rows' => $conversations ?? []])
          </div>
         </div>
        </div>
        <!-- Compose / contacts state -->
        <div class="grow flex flex-col min-h-0 hidden" id="chat_drawer_contacts_state">
         <div class="flex items-center gap-2.5 px-5 py-3 border-b border-b-border">
          <button type="button" class="kt-btn kt-btn-sm kt-btn-icon kt-btn-ghost" id="chat_drawer_contacts_back">
           <i class="ki-filled ki-left text-lg"></i>
          </button>
          <span class="text-sm font-semibold text-mono">New message</span>
         </div>
         <div class="px-5 py-2.5 border-b border-b-border">
          <div class="kt-input">
           <i class="ki-filled ki-magnifier"></i>
           <input type="text" placeholder="Search people..." id="chat_drawer_contacts_search"/>
          </div>
         </div>
         <div class="grow chat-scroll">
          <div class="grow flex flex-col" id="chat_drawer_contacts"></div>
         </div>
        </div>
        <!-- Thread state -->
        <div class="grow flex flex-col min-h-0 hidden" id="chat_drawer_thread_state">
         <div class="flex items-center gap-2.5 px-5 py-3 border-b border-b-border">
          <button type="button" class="kt-btn kt-btn-sm kt-btn-icon kt-btn-ghost" id="chat_drawer_back">
           <i class="ki-filled ki-left text-lg">
           </i>
          </button>
          <span class="text-sm font-semibold text-mono" id="chat_drawer_thread_title">
          </span>
          <span class="flex flex-wrap gap-1" id="chat_drawer_thread_context"></span>
         </div>
         <div class="grow chat-scroll">
          <div id="chat_drawer_thread">
          </div>
         </div>
         <!--Chat Footer-->
         <div class="border-t border-t-border px-5 py-3">
          <div class="relative grow">
           <input class="kt-input h-auto py-4" placeholder="Write a message..." type="text" id="chat_drawer_input"/>
           <div class="flex items-center gap-2.5 absolute end-3 top-1/2 -translate-y-1/2">
            <button class="kt-btn kt-btn-primary kt-btn-sm" type="button" id="chat_drawer_send">
             Send
            </button>
           </div>
          </div>
         </div>
         <!--End of Chat Footer-->
        </div>
        <script nonce="{{ $cspNonce ?? '' }}">
         (function () {
          var baseUrl = '{{ url('/messaging/conversations') }}';
          var contactsUrl = '{{ route('messaging.contacts') }}';
          var storeUrl = '{{ route('messaging.conversations.store') }}';
          var token = document.getElementById('chat_csrf_token').value;
          var listState = document.getElementById('chat_drawer_list_state');
          var threadState = document.getElementById('chat_drawer_thread_state');
          var contactsState = document.getElementById('chat_drawer_contacts_state');
          var listEl = document.getElementById('chat_drawer_list');
          var threadEl = document.getElementById('chat_drawer_thread');
          var contactsEl = document.getElementById('chat_drawer_contacts');
          var contactsSearch = document.getElementById('chat_drawer_contacts_search');
          var threadTitle = document.getElementById('chat_drawer_thread_title');
          var threadContext = document.getElementById('chat_drawer_thread_context');
          var input = document.getElementById('chat_drawer_input');
          var currentId = null;

          // Optimized polling engine (replaces the task 33 Reverb subscription; BROADCAST_CONNECTION=log).
          // Two loops, both paused when the tab is hidden: a steady badge/list poll, and an adaptive
          // thread poll that runs fast (3s) right after activity and backs off to 10s when a thread is quiet.
          var chatDrawerEl = document.getElementById('chat_drawer');
          var BADGE_INTERVAL = 15000, THREAD_MIN = 3000, THREAD_MAX = 10000, THREAD_BACKOFF_AFTER = 3;
          var badgeTimer = null, threadTimer = null;
          var threadDelay = THREAD_MIN, threadIdleCycles = 0;
          var lastThreadSig = null;   // change token of the open thread, last rendered
          var lastListSig = null;     // change token of the badge/list poll, last rendered
          function pageVisible() { return document.visibilityState === 'visible'; }
          function threadActive() { return currentId && chatDrawerEl && !chatDrawerEl.classList.contains('hidden'); }

          function showOnly(target) {
           [listState, threadState, contactsState].forEach(function (s) { s.classList.add('hidden'); });
           target.classList.remove('hidden');
          }

          function headers(extra) {
           return Object.assign({ 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json', 'X-CSRF-TOKEN': token }, extra || {});
          }

          var inboxListEl = document.getElementById('conversations_list'); // Inbox tab under the bell
          var chatBadgeCls = 'absolute top-1 -end-1 flex items-center justify-center size-[18px] rounded-full bg-destructive text-white text-[10px] font-semibold leading-none';

          function setMsgBadge(count) {
           var btn = document.getElementById('chat_bell_btn');
           var dot = document.getElementById('chat_bell_dot');
           if (!count || count < 1) { if (dot) { dot.remove(); } return; }
           if (!dot) { dot = document.createElement('span'); dot.id = 'chat_bell_dot'; dot.className = chatBadgeCls; if (btn) { btn.appendChild(dot); } }
           dot.textContent = count > 9 ? '9+' : String(count);
          }

          // Keeps the message-icon badge, the bell total, the Inbox tab, and the chat drawer list in
          // sync. Always refreshes the numeric badge; only re-renders the list HTML when the server
          // signature moved (or force=true on explicit navigation) to avoid pointless reflow.
          function pollMessaging(force) {
           fetch(baseUrl, { headers: headers() })
            .then(function (r) { return r.json(); })
            .then(function (data) {
             if (window.qyzenUnread) { window.qyzenUnread.msg = data.unread_count; if (window.qyzenRenderBell) { window.qyzenRenderBell(); } }
             if (window.qyzenRenderTabDots) { window.qyzenRenderTabDots(); }
             setMsgBadge(data.unread_count);
             if (typeof data.html === 'string' && (force || data.signature !== lastListSig)) {
              lastListSig = data.signature;
              if (inboxListEl) { inboxListEl.innerHTML = data.html; }
              if (!listState.classList.contains('hidden')) { listEl.innerHTML = data.html; }
             }
            })
            .catch(function () {});
          }

          // Read-only thread poll (?peek=1 — no DB write) except when opts.markRead (open). Only swaps
          // the HTML when the signature changed; on change it snaps back to the fast interval and, if a
          // new message arrived while viewing, marks the thread read (one write, not per tick).
          function pollThread(opts) {
           if (!currentId) { return; }
           var markRead = opts && opts.markRead;
           fetch(baseUrl + '/' + currentId + (markRead ? '' : '?peek=1'), { headers: headers() })
            .then(function (r) { return r.json(); })
            .then(function (data) {
             var changed = data.signature !== lastThreadSig;
             if (typeof data.html === 'string' && (changed || (opts && opts.force))) { threadEl.innerHTML = data.html; if (threadContext && typeof data.context_html === 'string') { threadContext.innerHTML = data.context_html; } }
             if (changed) {
              if (lastThreadSig !== null && !markRead && threadActive() && pageVisible()) {
               fetch(baseUrl + '/' + currentId + '/read', { method: 'POST', headers: headers() })
                .then(function () { pollMessaging(); }).catch(function () {});
              }
              lastThreadSig = data.signature;
              threadDelay = THREAD_MIN; threadIdleCycles = 0;
             } else if (++threadIdleCycles >= THREAD_BACKOFF_AFTER) {
              threadDelay = THREAD_MAX;
             }
            })
            .catch(function () {});
          }

          // Self-scheduling adaptive loop: re-arms only while the thread is open and the tab is visible.
          function threadTick() {
           threadTimer = null;
           if (!threadActive() || !pageVisible()) { return; }
           pollThread();
           threadTimer = setTimeout(threadTick, threadDelay);
          }
          function scheduleThreadPoll() { clearTimeout(threadTimer); threadTimer = setTimeout(threadTick, threadDelay); }

          function openThread(id, name) {
           currentId = id;
           lastThreadSig = null; threadDelay = THREAD_MIN; threadIdleCycles = 0;
           threadTitle.textContent = name || '';
           showOnly(threadState);
           pollThread({ markRead: true, force: true });  // opening marks read server-side + renders
           setTimeout(pollMessaging, 1000);               // badges reflect the read once it lands
           scheduleThreadPoll();
          }

          function backToList() {
           currentId = null; lastThreadSig = null;
           clearTimeout(threadTimer); threadTimer = null;
           showOnly(listState);
           pollMessaging(true);
          }

          function openCompose() {
           showOnly(contactsState);
           if (contactsSearch) { contactsSearch.value = ''; }
           contactsEl.innerHTML = '<div class="text-sm text-muted-foreground px-5 py-4">Loading…</div>';
           fetch(contactsUrl, { headers: headers() })
            .then(function (r) { return r.json(); })
            .then(function (data) {
             if (typeof data.html === 'string') { contactsEl.innerHTML = data.html; }
             // The subject filter is a KTUI searchable select injected after load — init it manually.
             var sel = document.getElementById('chat_drawer_subject_filter');
             if (sel && window.KTSelect) { window.KTSelect.getOrCreateInstance(sel); }
            })
            .catch(function () { contactsEl.innerHTML = '<div class="text-sm text-muted-foreground px-5 py-4">Could not load contacts.</div>'; });
          }

          // Start (or reopen) a conversation with the picked contact, then jump into its thread.
          function startConversation(otherId, name) {
           fetch(storeUrl, {
            method: 'POST', headers: headers({ 'Content-Type': 'application/json' }), body: JSON.stringify({ other_user_id: otherId }),
           }).then(function (r) { return r.json(); })
            .then(function (data) { if (data && data.conversation_id) { openThread(data.conversation_id, name); } });
          }

          document.addEventListener('click', function (e) {
           var item = e.target.closest('[data-conversation-item]');
           if (item) {
            e.preventDefault();
            var name = item.querySelector('[data-conversation-name]');
            openThread(item.getAttribute('data-conversation-id'), name ? name.textContent.trim() : '');
            // If this item lives in the notifications drawer, close it so the chat drawer is visible.
            var notifDismiss = document.querySelector('#notifications_drawer [data-kt-drawer-dismiss]');
            var notifDrawer = document.getElementById('notifications_drawer');
            if (notifDismiss && notifDrawer && !notifDrawer.classList.contains('hidden')) { notifDismiss.click(); }
            // Only open the chat drawer if it's closed; if the item was clicked inside the already-open
            // drawer, toggling here would close it (task 32).
            var chatDrawer = document.getElementById('chat_drawer');
            if (chatDrawer && chatDrawer.classList.contains('hidden')) {
             var chatToggle = document.querySelector('[data-kt-drawer-toggle="#chat_drawer"]');
             if (chatToggle) { chatToggle.click(); }
            }
            return;
           }

           if (e.target.closest('#chat_drawer_back')) { backToList(); return; }
           if (e.target.closest('#chat_drawer_compose')) { openCompose(); return; }
           if (e.target.closest('#chat_drawer_contacts_back')) { showOnly(listState); return; }

           var contact = e.target.closest('[data-contact-item]');
           if (contact) {
            e.preventDefault();
            var cName = contact.querySelector('[data-contact-name]');
            startConversation(contact.getAttribute('data-contact-id'), cName ? cName.textContent.trim() : '');
            return;
           }

           var editBtn = e.target.closest('[data-message-edit]');
           if (editBtn && currentId) {
            var mid = editBtn.getAttribute('data-message-edit');
            var bubble = editBtn.closest('[data-message-id]');
            var current = bubble ? bubble.querySelector('p').textContent : '';
            var next = window.prompt('Edit message', current);
            if (next !== null && next.trim() !== '') {
             fetch('{{ url('/messaging/messages') }}/' + mid, {
              method: 'PUT', headers: headers({ 'Content-Type': 'application/json' }), body: JSON.stringify({ content: next }),
             }).then(function (r) { return r.json(); })
              .then(function (data) { if (typeof data.html === 'string') { threadEl.innerHTML = data.html; } if (data.signature) { lastThreadSig = data.signature; } });
            }
            return;
           }

           var delBtn = e.target.closest('[data-message-delete]');
           if (delBtn && currentId) {
            var did = delBtn.getAttribute('data-message-delete');
            if (window.confirm('Delete this message?')) {
             fetch('{{ url('/messaging/messages') }}/' + did, { method: 'DELETE', headers: headers() })
              .then(function (r) { return r.json(); })
              .then(function (data) { if (typeof data.html === 'string') { threadEl.innerHTML = data.html; } if (data.signature) { lastThreadSig = data.signature; } });
            }
            return;
           }
          });

          if (input) {
           input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); sendMessage(); } });
          }
          var sendBtn = document.getElementById('chat_drawer_send');
          if (sendBtn) { sendBtn.addEventListener('click', sendMessage); }

          function sendMessage() {
           var content = input.value.trim();
           if (!content || !currentId) { return; }
           fetch(baseUrl + '/' + currentId + '/messages', {
            method: 'POST', headers: headers({ 'Content-Type': 'application/json' }), body: JSON.stringify({ content: content }),
           }).then(function (r) { return r.json(); })
            .then(function (data) {
             if (typeof data.html === 'string') { threadEl.innerHTML = data.html; }
             if (data.signature) { lastThreadSig = data.signature; }
             input.value = '';
             threadDelay = THREAD_MIN; threadIdleCycles = 0;  // expect a quick reply — poll fast
            });
          }

          // Client-side filter of the contact picker: search text AND (educator) subject/section.
          function applyContactFilter() {
           var q = contactsSearch ? contactsSearch.value.trim().toLowerCase() : '';
           var sel = document.getElementById('chat_drawer_subject_filter');
           var subj = '';
           if (sel) {
            subj = sel.value || '';
            // KTUI keeps the native <select> synced, but prefer the instance value if exposed.
            if (window.KTSelect) {
             var inst = window.KTSelect.getInstance(sel);
             if (inst && typeof inst.getValue === 'function') {
              var v = inst.getValue();
              subj = Array.isArray(v) ? (v[0] || '') : (v == null ? '' : String(v));
             }
            }
           }
           contactsEl.querySelectorAll('[data-contact-item]').forEach(function (row) {
            var textMatch = row.getAttribute('data-contact-search').indexOf(q) !== -1;
            var subjMatch = !subj || (row.getAttribute('data-subject-ids') || '').split(',').indexOf(subj) !== -1;
            var show = textMatch && subjMatch;
            row.classList.toggle('hidden', !show);
            var divider = row.nextElementSibling;
            if (divider && divider.classList.contains('border-b')) { divider.classList.toggle('hidden', !show); }
           });
          }
          if (contactsSearch) { contactsSearch.addEventListener('input', applyContactFilter); }
          // The subject dropdown is (re)rendered inside the fetched fragment, so bind via delegation.
          // KTUI's searchable select emits 'ktselect.change'; a plain <select> emits native 'change'.
          document.addEventListener('ktselect.change', function (e) {
           if (e.target && e.target.id === 'chat_drawer_subject_filter') { applyContactFilter(); }
          });
          document.addEventListener('change', function (e) {
           if (e.target && e.target.id === 'chat_drawer_subject_filter') { applyContactFilter(); }
          });

          // Start/stop the two poll loops. Hidden tabs make zero requests; becoming visible catches up
          // immediately, then resumes. This is the whole "optimized polling" replacement for Reverb.
          function startPolling() {
           if (!badgeTimer) { badgeTimer = setInterval(function () { if (pageVisible()) { pollMessaging(); } }, BADGE_INTERVAL); }
           pollMessaging();
           if (threadActive()) { threadDelay = THREAD_MIN; threadIdleCycles = 0; scheduleThreadPoll(); }
          }
          function stopPolling() {
           clearInterval(badgeTimer); badgeTimer = null;
           clearTimeout(threadTimer); threadTimer = null;
          }
          document.addEventListener('visibilitychange', function () { if (pageVisible()) { startPolling(); } else { stopPolling(); } });
          if (pageVisible()) { startPolling(); }
         })();
        </script>
       </div>
       <!--End of Chat Drawer-->
       <!-- End of Chat -->
