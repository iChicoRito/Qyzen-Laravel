<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

// G5: edit assessment. Updates the current row only; subject selection is single-choice.
class UpdateAssessmentRequest extends StoreAssessmentRequest
{
    public function rules(): array
    {
        return array_replace(parent::rules(), [
            'subject_ids' => ['required', 'array', 'size:1'],
        ]);
    }

    // Task 31: an active term OR the term this assessment already carries. Deactivating a term
    // must not make its existing assessments unsaveable, but picking a *different* inactive term
    // is still rejected. Also covers storeDuplicate, which binds the source assessment.
    protected function termRule(): array
    {
        $current = $this->route('assessment')?->term;

        return ['required', Rule::exists('tbl_academic_term', 'id')
            ->where(fn ($q) => $q->where('is_active', true)->orWhere('id', $current))];
    }
}
