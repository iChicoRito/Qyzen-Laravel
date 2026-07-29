<?php

namespace App\Http\Requests;

use App\Support\AnnouncementHtml;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class StoreAnnouncementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['body' => AnnouncementHtml::sanitize($this->input('body'))]);
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'body' => ['required', 'string', 'max:100000'],
            // Task 29: many targets. Each id is re-checked against the educator's own subjects,
            // so a hand-crafted array can't announce into someone else's class.
            'subject_ids' => ['nullable', 'array'],
            'subject_ids.*' => [Rule::exists('tbl_subjects', 'id')->where('educator_id', Auth::id())],
            'is_global' => ['required', 'boolean'],
            'images' => ['nullable', 'array'],
            'images.*' => ['image', 'mimes:jpg,jpeg,png,webp,gif', 'max:10240'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if (! $this->boolean('is_global') && $this->input('subject_ids', []) === []) {
                $validator->errors()->add('subject_ids', 'Select at least one subject or enable the global announcement switch.');
            }
        });
    }
}
