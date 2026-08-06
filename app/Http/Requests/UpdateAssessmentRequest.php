<?php

namespace App\Http\Requests;

// G5: edit assessment. Updates the current row only; subject selection is single-choice.
// The assessment_code uniqueness check is inherited from StoreAssessmentRequest::after(), which
// ignores the row being edited only on the update route (storeDuplicate() creates, so it must not).
class UpdateAssessmentRequest extends StoreAssessmentRequest
{
    public function rules(): array
    {
        return array_replace(parent::rules(), [
            'subject_ids' => ['required', 'array', 'size:1'],
        ]);
    }
}
