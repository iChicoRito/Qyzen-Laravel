<aside class="kt-card order-1 lg:order-2 lg:sticky lg:top-24 lg:max-h-[calc(100vh-7rem)] overflow-hidden" id="announcement_timeline">
    <div class="kt-card-header">
        <h3 class="kt-card-title">Announcements timeline</h3>
    </div>
    <div class="kt-card-content p-3 overflow-y-auto">
        <div class="flex flex-col gap-5">
            @forelse ($announcements as $announcement)
                @php($isNew = in_array($announcement->id, $newAnnouncementIds, true))
                <div class="border-s-2 {{ $isNew ? 'border-primary' : 'border-input' }} ps-3"
                    data-announcement-timeline-item="{{ $announcement->id }}" data-announcement-new="{{ $isNew ? 'true' : 'false' }}">
                    <div class="flex flex-wrap gap-2 items-center mb-0.5">
                        <time class="text-xs text-secondary-foreground">{{ $announcement->created_at?->format('M j, Y') }}</time>
                        <span class="rounded-full size-1.5 bg-input"></span>
                        <span class="text-xs text-secondary-foreground">{{ $announcement->is_global ? 'All students' : ($announcement->subjects->pluck('subject_code')->join(', ') ?: 'Subject') }}</span>
                        @if ($isNew)<span class="kt-badge kt-badge-sm kt-badge-primary">New</span>@endif
                    </div>
                    <h4 class="text-sm font-semibold text-mono leading-5.5">{{ $announcement->title }}</h4>
                    <p class="text-sm text-foreground leading-5.5 mt-1">{{ Str::limit(strip_tags($announcement->description ?: $announcement->body), 110) }}</p>
                </div>
            @empty
                <p class="text-sm text-secondary-foreground">No announcement activity yet.</p>
            @endforelse
        </div>
    </div>
</aside>
