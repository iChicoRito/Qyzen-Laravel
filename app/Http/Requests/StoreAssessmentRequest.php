<?php

namespace App\Http\Requests;

use App\Models\Assessment;
use App\Models\Subject;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

// G5: create assessment. Any number of assessments allowed per subject/section/term — but the
// NAME must be unique within one, see after().
class StoreAssessmentRequest extends FormRequest
{
    public function prepareForValidation(): void
    {
        $publishMode = $this->input('publish_mode');

        if ($publishMode === null && $this->has('is_active')) {
            $publishMode = filter_var($this->input('is_active'), FILTER_VALIDATE_BOOLEAN)
                ? 'active_notify'
                : 'inactive';
        }

        if ($publishMode !== null) {
            $this->merge([
                'publish_mode' => $publishMode,
                'is_active' => $publishMode !== 'inactive',
            ]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'assessment_code' => ['required', 'string', 'max:255'],
            'subject_ids' => ['required', 'array', 'min:1'],
            'subject_ids.*' => [Rule::exists('tbl_subjects', 'id')->where('educator_id', Auth::id())],
            'term' => ['required', Rule::exists('tbl_academic_term', 'id')->where('is_active', true)],
            'time_limit' => ['required', 'string', 'max:255'],
            'cheating_attempts' => ['nullable', 'integer', 'min:0'],
            'is_shuffle' => ['required', 'boolean'],
            'allow_review' => ['required', 'boolean'],
            'allow_retake' => ['required', 'boolean'],
            'retake_count' => ['nullable', 'integer', 'min:0'],
            'allow_hint' => ['required', 'boolean'],
            'hint_count' => ['nullable', 'integer', 'min:0'],
            'publish_mode' => ['required', Rule::in(['inactive', 'active_notify', 'active_silent'])],
            'is_active' => ['required', 'boolean'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'start_time' => ['required'],
            'end_time' => ['required'],
        ];
    }

    /**
     * Task 32: tbl_assessments has unique(assessment_code, subject_id, section_id, term) and
     * nothing checked it, so reusing a name raised a duplicate-key QueryException — the 500 behind
     * the intermittent "The server returned an unexpected response". Checked here, per selected
     * subject, so the educator gets an inline field error naming the subject that clashes.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['assessment_code', 'subject_ids', 'term'])) {
                    return; // the tuple isn't fully known yet; don't pile on
                }

                $code = (string) $this->input('assessment_code');
                $term = $this->input('term');
                // Only the edit route updates a row in place; storeDuplicate() reuses this request
                // to CREATE, so it must still collide with its own source.
                $ignoreId = $this->routeIs('educator.assessments.update')
                    ? $this->route('assessment')?->getKey()
                    : null;

                $subjects = Subject::whereKey((array) $this->input('subject_ids'))
                    ->get(['id', 'sections_id', 'subject_code', 'subject_name']);

                foreach ($subjects as $subject) {
                    $taken = Assessment::where('assessment_code', $code)
                        ->where('subject_id', $subject->id)
                        ->where('section_id', $subject->sections_id)
                        ->where('term', $term)
                        ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
                        ->exists();

                    if ($taken) {
                        $validator->errors()->add(
                            'assessment_code',
                            "\"{$code}\" is already used by another assessment for {$subject->subject_code} in this section and term. Choose a different name."
                        );
                    }
                }
            },
        ];
    }

    public function shouldNotifyStudents(): bool
    {
        return $this->validated('publish_mode') !== 'active_silent';
    }
}
