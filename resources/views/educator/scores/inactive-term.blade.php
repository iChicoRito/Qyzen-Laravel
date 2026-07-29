{{-- Task 31: shown in place of the class matrix when the requested class belongs to a term the
     admin has since deactivated. A modal fragment, so this must render standalone — the loader
     injects the response body straight into the modal with innerHTML. --}}
<div class="p-10 text-center">
    <i class="ki-filled ki-calendar-remove text-3xl text-secondary-foreground"></i>
    <p class="mt-3 text-sm font-medium text-mono">This class is in an inactive term</p>
    <p class="mt-1 text-xs text-secondary-foreground">
        Its records are hidden until an administrator makes the term active again.
    </p>
</div>
