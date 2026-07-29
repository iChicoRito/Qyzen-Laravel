<?php

namespace App\Http\Requests;

use App\Services\SectionService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

// G2: create section. Name unique per term per educator (spans the section↔term M:N → service check).
class StoreSectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'section_name' => ['required', 'string', 'max:255'],
            'academic_term_ids' => ['required', 'array', 'min:1'],
            'academic_term_ids.*' => [Rule::exists('tbl_academic_term', 'id')->where('is_active', true)],
            'is_active' => ['required', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }
            $taken = app(SectionService::class)->nameTakenForTerms(
                Auth::id(),
                $this->input('section_name'),
                $this->input('academic_term_ids', []),
                $this->ignoreSectionId(),
            );
            if ($taken) {
                $v->errors()->add('section_name', 'You already have a section with this name in one of those terms.');
            }
        });
    }

    protected function ignoreSectionId(): ?int
    {
        return null;
    }
}
