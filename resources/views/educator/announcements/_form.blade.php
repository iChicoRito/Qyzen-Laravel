<form method="POST" action="{{ $action }}" enctype="multipart/form-data" data-announcement-form>
    @csrf @if ($method !== 'POST') @method($method) @endif
    <div class="flex flex-col gap-5">
        <div class="flex flex-col gap-1.5"><label class="kt-form-label">Title</label><input class="kt-input" name="title" required maxlength="255" value="{{ old('title', $announcement?->title) }}">@error('title')<span class="text-xs text-destructive">{{ $message }}</span>@enderror</div>
        <div class="flex flex-col gap-1.5"><label class="kt-form-label">Description <span class="text-secondary-foreground">(Optional)</span></label><textarea class="kt-textarea" name="description" rows="2" maxlength="1000">{{ old('description', $announcement?->description) }}</textarea>@error('description')<span class="text-xs text-destructive">{{ $message }}</span>@enderror</div>
        <div class="flex flex-col gap-1.5">
            <label class="kt-form-label">Announcement Body</label>
            <div class="border border-border rounded-lg overflow-hidden">
                <div class="min-h-32" data-quill-editor>{!! \App\Support\AnnouncementHtml::sanitize(old('body', $announcement?->body)) !!}</div>
            </div>
            <input type="hidden" name="body" data-quill-value value="{{ \App\Support\AnnouncementHtml::sanitize(old('body', $announcement?->body)) }}">
            @error('body')<span class="text-xs text-destructive">{{ $message }}</span>@enderror
        </div>
        @php $selectedSubjectIds = array_map('strval', old('subject_ids', $announcement?->subjects->pluck('id')->all() ?? [])); @endphp
        <div class="flex flex-col gap-1.5">
            <label class="kt-form-label">Target <span class="text-secondary-foreground">(Select one or more)</span></label>
            {{-- KTSelect enhanced tags: each chosen subject becomes a removable tag. data-kt-select-*
                 attributes only; KTSelect.init() runs on DOM ready and again in the modal loader's
                 reinit(), since this fragment is injected after DOM ready. --}}
            <select class="kt-select" name="subject_ids[]" multiple data-subject-select
                    data-kt-select="true"
                    data-kt-select-multiple="true"
                    data-kt-select-tags="true"
                    data-kt-select-enable-search="true"
                    data-kt-select-placeholder="Select subjects…"
                    data-kt-select-search-placeholder="Search subjects…">
                @foreach ($subjects as $subject)
                    <option value="{{ $subject->id }}" @selected(in_array((string) $subject->id, $selectedSubjectIds, true))>{{ $subject->subject_code }} — {{ $subject->subject_name }} ({{ $subject->section?->section_name ?? '—' }})</option>
                @endforeach
            </select>
            @error('subject_ids')<span class="text-xs text-destructive">{{ $message }}</span>@enderror
            @error('subject_ids.*')<span class="text-xs text-destructive">{{ $message }}</span>@enderror
        </div>
        <input type="hidden" name="is_global" value="0"><x-checkbox-card variant="switch" name="is_global" value="1" title="Global announcement" desc="Show this announcement to every active student enrolled with you." :checked="(bool) old('is_global', $announcement?->is_global)" data-global-switch />
        <div class="flex flex-col gap-1.5"><label class="kt-form-label">Images <span class="text-secondary-foreground">(Optional, 10 MB maximum per file)</span></label><input class="kt-input" type="file" name="images[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple><span class="text-xs text-secondary-foreground">New images replace existing images when editing.</span>@error('images.*')<span class="text-xs text-destructive">{{ $message }}</span>@enderror</div>
        @if (($announcement?->images ?? []) !== [])<div class="flex flex-wrap gap-2">@foreach ($announcement->images as $image)<img class="size-20 object-cover rounded-lg" src="{{ route('student.announcements.image', [$announcement, $loop->index]) }}" alt="{{ $image['name'] ?? 'Announcement image' }}">@endforeach</div>@endif
    </div>
    <div class="flex gap-2 mt-5"><button class="kt-btn kt-btn-primary">{{ $method === 'POST' ? 'Create' : 'Save changes' }}</button><a href="{{ route('educator.announcements.index') }}" class="kt-btn kt-btn-outline" data-modal-cancel>Cancel</a></div>
</form>
