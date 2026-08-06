<?php

namespace App\Http\Requests;

use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

// Task 51: edit one bank question.
class UpdateQuizRequest extends StoreQuizRequest
{
    public function rules(): array
    {
        return [
            'subject_ids' => ['required', 'array', 'min:1'],
            'subject_ids.*' => ['integer', Rule::exists('tbl_subjects', 'id')->where('educator_id', Auth::id())],
            'assessment_ids' => ['nullable', 'array'],
            'assessment_ids.*' => [Rule::exists('tbl_assessments', 'id')->where('educator_id', Auth::id())],
        ] + $this->questionRules();
    }
}
