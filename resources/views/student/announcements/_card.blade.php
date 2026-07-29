@php
    $avatar = $announcement->educator?->profile_picture ? \Illuminate\Support\Facades\Storage::disk('profile_media')->url($announcement->educator->profile_picture) : null;
    $images = array_values($announcement->images ?? []);
@endphp
<article class="kt-card">
    <div class="flex items-center gap-3 mb-5 p-7.5 pb-0">
        @if ($avatar)<img class="rounded-full size-[50px]" src="{{ $avatar }}" alt="">@else<span class="flex items-center justify-center rounded-full size-[50px] bg-primary/10 text-primary font-semibold">{{ strtoupper(substr($announcement->educator?->given_name ?? 'E', 0, 1)) }}</span>@endif
        <div class="flex items-start justify-between gap-3 grow" data-announcement-author-row>
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-base font-medium text-mono">{{ $announcement->educator?->name ?? 'Educator' }}</span>
                <span class="kt-badge kt-badge-sm kt-badge-primary kt-badge-outline">Educator</span>
            </div>
            <time class="text-sm text-secondary-foreground shrink-0" data-announcement-timestamp>{{ $announcement->created_at?->diffForHumans() }}</time>
        </div>
    </div>
    <div class="grid gap-3.5 mb-5 px-7.5">
        <h2 class="text-lg font-semibold text-mono">{{ $announcement->title }}</h2>
        @if ($announcement->description)<p class="text-sm text-secondary-foreground">{{ $announcement->description }}</p>@endif
        <div class="text-sm text-foreground leading-5.5 announcement-body ql-editor">{!! $announcement->body !!}</div>
        @if ($images)
            <div class="grid gap-2.5 {{ count($images) === 1 ? 'grid-cols-1' : 'grid-cols-2' }}">
                @foreach ($images as $image)<img class="bg-cover bg-center rounded-xl w-full min-h-48 object-cover" src="{{ route('student.announcements.image', [$announcement, $loop->index]) }}" alt="{{ $image['name'] ?? 'Announcement image' }}">@endforeach
            </div>
        @endif
        @forelse ($announcement->subjects as $subject)<span class="kt-badge kt-badge-outline w-fit">{{ $subject->subject_code }} — {{ $subject->subject_name }}</span>@empty<span class="kt-badge kt-badge-outline w-fit">All enrolled students</span>@endforelse
    </div>
</article>
