<?php

namespace App\Http\Requests;

use App\Services\Ai\AssistantGuard;
use Illuminate\Foundation\Http\FormRequest;

// Task 28: one educator question. The role check here is redundant with role:educator on the
// route — kept because this is the last gate before a message reaches a third-party API, and a
// route group is easy to move.
class AssistantMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasRole('educator');
    }

    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'min:2', 'max:'.AssistantGuard::MAX_INPUT_LENGTH],
        ];
    }
}
