{{-- Task 30: message bubbles for one conversation thread, adapted from the real (formerly
     static-demo) chat drawer markup — same kt-card/bg-primary/bg-accent classes, now data-driven.
     Rendered on load AND returned as an HTML fragment by MessagingController for the 5s thread
     poll and after send/edit/delete, so the drawer never reloads the page. --}}
@php $myId = \Illuminate\Support\Facades\Auth::id(); @endphp
<div class="flex flex-col gap-5 py-5" id="chat_drawer_messages" data-conversation-id="{{ $conversation->id }}">
 @forelse (($thread ?? []) as $row)
 @php
     $message = $row->message;
     $mine = $message->sender_user_id === $myId;
     $deleted = $message->isDeleted();
     $isAlert = $message->isAlert();
 @endphp
 @if ($isAlert)
 {{-- Task 29: system-generated messages (exemption / special access) render as a KTUI alert
      spanning the thread — they are announcements, not conversation, so no avatar, no bubble
      tail, no edit/delete. --}}
 @php [$variant, $icon, $heading, $accent] = $message->alertStyle(); @endphp
 <div class="px-5" data-message-id="{{ $message->id }}" data-message-alert="{{ $message->kind }}">
  <div class="kt-alert kt-alert-light kt-alert-{{ $variant }} kt-alert-outline items-start">
   {{-- text-lg: the kt-alert icon sizing rule targets svg, so an <i> keenicon needs its own size.
        The accent colours the heading and icon; the description stays on --foreground so the
        actual reading text keeps full contrast. --}}
   <div class="kt-alert-icon"><i class="ki-filled ki-{{ $icon }} text-lg {{ $accent }}"></i></div>
   <div class="kt-alert-content">
    <div class="kt-alert-title {{ $accent }}">{{ $heading }}</div>
    <div class="kt-alert-description">{{ $message->displayContent() }}</div>
   </div>
  </div>
  <div class="flex items-center gap-2 mt-1.5 {{ $mine ? 'justify-end' : '' }}">
   <span class="text-xs font-medium text-muted-foreground">{{ $message->created_at->format('H:i') }}</span>
   @if ($mine)
   <i class="ki-filled ki-double-check text-lg {{ $row->isRead ? 'text-green-500' : 'text-muted-foreground' }}"></i>
   @endif
  </div>
 </div>
 @else
 <div class="flex items-end {{ $mine ? 'justify-end' : '' }} gap-3.5 px-5" data-message-id="{{ $message->id }}">
  @unless ($mine)
  <div class="kt-avatar size-9">
   @if ($message->sender?->profile_picture)
   <div class="kt-avatar-image">
    <img alt="avatar" src="{{ \Illuminate\Support\Facades\Storage::disk('profile_media')->url($message->sender->profile_picture) }}"/>
   </div>
   @else
   <span class="inline-flex items-center justify-center size-9 rounded-full bg-primary/10 text-primary text-xs font-semibold">{{ strtoupper(substr($message->sender?->given_name ?? '?', 0, 1)) }}</span>
   @endif
  </div>
  @endunless
  <div class="flex flex-col gap-1.5 {{ $mine ? 'items-end' : '' }}">
   <div class="kt-card shadow-none flex flex-col gap-2.5 p-3 text-2sm {{ $mine ? 'bg-primary rounded-be-none' : 'bg-accent/60 rounded-bs-none' }}">
    <p class="{{ $mine ? 'text-primary-foreground' : '' }} {{ $deleted ? 'italic opacity-70' : '' }}">{{ $message->displayContent() }}</p>
   </div>
   <div class="flex items-center gap-2 relative {{ $mine ? 'justify-end' : '' }}">
    <span class="text-xs font-medium text-muted-foreground">{{ $message->created_at->format('H:i') }}</span>
    @if ($message->isEdited() && !$deleted)
    <span class="text-xs font-medium text-muted-foreground italic">(edited)</span>
    @endif
    @if ($mine)
    <i class="ki-filled ki-double-check text-lg {{ $row->isRead ? 'text-green-500' : 'text-muted-foreground' }}"></i>
    @endif
    @if ($mine && !$deleted)
    <button type="button" class="kt-btn kt-btn-icon kt-btn-sm kt-btn-ghost" data-message-edit="{{ $message->id }}" title="Edit">
     <i class="ki-filled ki-notepad-edit text-sm"></i>
    </button>
    <button type="button" class="kt-btn kt-btn-icon kt-btn-sm kt-btn-ghost" data-message-delete="{{ $message->id }}" title="Delete">
     <i class="ki-filled ki-trash text-sm"></i>
    </button>
    @endif
   </div>
  </div>
 </div>
 @endif
 @empty
 <div class="flex flex-col items-center justify-center text-center gap-2 py-10 px-5">
  <span class="text-sm font-medium text-secondary-foreground">No messages yet — say hello!</span>
 </div>
 @endforelse
</div>
