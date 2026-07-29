<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

// G2: edit section. Same uniqueness, excluding self.
class UpdateSectionRequest extends StoreSectionRequest
{
    protected function ignoreSectionId(): ?int
    {
        return $this->route('section')->id;
    }

    // Task 31: an active term OR one this section is already filed under. Deactivating a term must
    // not make its existing sections unsaveable; picking a *different* inactive term still fails.
    protected function termRule(): array
    {
        $current = $this->route('section')->terms->pluck('id')->all();

        return [Rule::exists('tbl_academic_term', 'id')
            ->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $current ?: [0]))];
    }
}
