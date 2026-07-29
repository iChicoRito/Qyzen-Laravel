<?php

namespace App\Http\Requests;

// G2: edit section. Same uniqueness, excluding self.
class UpdateSectionRequest extends StoreSectionRequest
{
    protected function ignoreSectionId(): ?int
    {
        return $this->route('section')->id;
    }
}
