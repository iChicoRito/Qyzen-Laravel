@extends('educator.layout')
@section('title', 'Announcements')
@section('heading', 'Announcements')
@section('toolbar')
    <button type="button" class="kt-btn kt-btn-sm kt-btn-primary"
            data-modal-url="{{ route('educator.announcements.create') }}" data-modal-target="#form_modal" data-modal-title="Create announcement">Create Announcement</button>
@endsection
@section('content')
    @include('admin._status')
    <x-data-table id="announcements_table" search-placeholder="Search announcements" :paginator="$announcements">
        <x-slot:head>
            <thead><tr>
                <th class="min-w-[240px]" data-sort="title"><span class="kt-table-col"><span class="kt-table-col-label">Title</span><span class="kt-table-col-sort"></span></span></th>
                <th class="min-w-[160px]" data-sort="subject"><span class="kt-table-col"><span class="kt-table-col-label">Target</span><span class="kt-table-col-sort"></span></span></th>
                <th class="min-w-[110px]" data-sort="status"><span class="kt-table-col"><span class="kt-table-col-label">Status</span><span class="kt-table-col-sort"></span></span></th>
                <th class="min-w-[140px]" data-sort="created"><span class="kt-table-col"><span class="kt-table-col-label">Created</span><span class="kt-table-col-sort"></span></span></th>
                <th class="w-[60px]"></th>
            </tr></thead>
        </x-slot:head>
        @forelse ($announcements as $announcement)
            <tr>
                <td><div class="flex flex-col gap-1"><span class="text-mono font-medium text-sm">{{ $announcement->title }}</span><span class="text-xs text-secondary-foreground">{{ Str::limit($announcement->description ?: strip_tags($announcement->body), 90) }}</span></div></td>
                <td>{{ $announcement->is_global ? 'All enrolled students' : ($announcement->subjects->pluck('subject_code')->join(', ') ?: '—') }}</td>
                <td><span class="kt-badge rounded-full kt-badge-outline kt-badge-{{ $announcement->is_active ? 'success' : 'destructive' }}">{{ $announcement->is_active ? 'Active' : 'Inactive' }}</span></td>
                <td class="text-secondary-foreground">{{ $announcement->created_at?->diffForHumans() }}</td>
                <td class="text-center"><x-table-actions :edit-modal="route('educator.announcements.edit', $announcement)" edit-modal-title="Edit announcement" :delete="route('educator.announcements.destroy', $announcement)" confirm="Delete this announcement and its images?" /></td>
            </tr>
        @empty
            <tr><td colspan="5" class="text-center text-secondary-foreground py-5">No announcements.</td></tr>
        @endforelse
    </x-data-table>
    <x-modal id="form_modal" width="760px" />
@endsection
