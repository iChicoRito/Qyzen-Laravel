{{-- Standard 3-dots row action menu (from /admin/users). Props are URLs (any optional):
       view, edit   — link targets
       delete       — DELETE form action; confirmed via SweetAlert (data-confirm)
       confirm      — confirmation message text
     $slot holds optional extra <div class="kt-menu-item">…</div> entries (rendered before delete). --}}
@props([
    'view' => null,
    'viewModal' => null,        // URL of the detail fragment → opens in the shared modal (no redirect)
    'viewModalTitle' => 'Details',
    'edit' => null,
    'delete' => null,
    'confirm' => 'This action cannot be undone.',
    'editClass' => '',
    'editAttributes' => '',
    'editModal' => null,        // URL of the edit form fragment → opens in the shared modal
    'editModalTitle' => 'Edit',
    'modalTarget' => '#form_modal',
])
<div class="kt-menu flex-inline" data-kt-menu="true">
    <div class="kt-menu-item" data-kt-menu-item-offset="0, 10px" data-kt-menu-item-placement="bottom-end" data-kt-menu-item-placement-rtl="bottom-start" data-kt-menu-item-toggle="dropdown" data-kt-menu-item-trigger="click">
        {{-- type=button: a typeless button submits its form, and _modal-loader also pins the first
             typeless/submit button's row as the modal's sticky footer. --}}
        <button type="button" class="kt-menu-toggle kt-btn kt-btn-sm kt-btn-icon kt-btn-ghost">
            <i class="ki-filled ki-dots-vertical text-lg"></i>
        </button>
        <div class="kt-menu-dropdown kt-menu-default w-full max-w-[175px]" data-kt-menu-dismiss="true">
            @if ($viewModal)
                <div class="kt-menu-item">
                    <a class="kt-menu-link" href="#" data-modal-url="{{ $viewModal }}" data-modal-target="{{ $modalTarget }}" data-modal-title="{{ $viewModalTitle }}">
                        <span class="kt-menu-icon"><i class="ki-filled ki-search-list"></i></span>
                        <span class="kt-menu-title">View</span>
                    </a>
                </div>
            @elseif ($view)
                <div class="kt-menu-item">
                    <a class="kt-menu-link" href="{{ $view }}">
                        <span class="kt-menu-icon"><i class="ki-filled ki-search-list"></i></span>
                        <span class="kt-menu-title">View</span>
                    </a>
                </div>
            @endif
            @if ($editModal)
                <div class="kt-menu-item">
                    <a class="kt-menu-link" href="#" data-modal-url="{{ $editModal }}" data-modal-target="{{ $modalTarget }}" data-modal-title="{{ $editModalTitle }}">
                        <span class="kt-menu-icon"><i class="ki-filled ki-pencil"></i></span>
                        <span class="kt-menu-title">Edit</span>
                    </a>
                </div>
            @elseif ($edit)
                <div class="kt-menu-item">
                    <a class="kt-menu-link {{ $editClass }}" href="{{ $edit }}" {!! $editAttributes !!}>
                        <span class="kt-menu-icon"><i class="ki-filled ki-pencil"></i></span>
                        <span class="kt-menu-title">Edit</span>
                    </a>
                </div>
            @endif
            {{ $slot }}
            @if ($delete)
                <div class="kt-menu-separator"></div>
                <div class="kt-menu-item">
                    <a class="kt-menu-link" href="#" data-confirm="{{ $confirm }}" data-confirm-title="Delete?">
                        <span class="kt-menu-icon"><i class="ki-filled ki-trash"></i></span>
                        <span class="kt-menu-title">Remove</span>
                    </a>
                    <form method="POST" action="{{ $delete }}" class="hidden">@csrf @method('DELETE')</form>
                </div>
            @endif
        </div>
    </div>
</div>
