<?php

namespace App\Http\Requests;

// G5: edit assessment. Updates the current row only; subject selection is single-choice.
class UpdateAssessmentRequest extends StoreAssessmentRequest
{
    public function rules(): array
    {
        return array_replace(parent::rules(), [
            'subject_ids' => ['required', 'array', 'size:1'],
        ]);
    }
}
