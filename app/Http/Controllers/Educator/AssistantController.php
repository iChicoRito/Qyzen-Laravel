<?php

namespace App\Http\Controllers\Educator;

use App\Http\Controllers\Controller;
use App\Http\Requests\AssistantMessageRequest;
use App\Services\Ai\AssistantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

// Task 28: educator AI assistant. Note the name — Educator\ChatController is the G10 group-chat
// feature and is unrelated. Both methods return JsonResponse, which AjaxFormResponse passes
// through untouched (it only rewrites redirects).
class AssistantController extends Controller
{
    public function __construct(private AssistantService $assistant) {}

    public function message(AssistantMessageRequest $request): JsonResponse
    {
        // The educator is taken from the session here and threaded all the way down to the
        // retrieval scopes. No id ever comes off the request body.
        $result = $this->assistant->ask(Auth::user(), (string) $request->validated('message'));

        return response()->json([
            'status' => $result['status'],
            // Plain text, kept so any non-HTML consumer still works.
            'reply' => $result['reply'],
            // Sanitized markdown for the drawer. Tag-allowlisted and attribute-stripped by
            // AssistantGuard::renderHtml — this is the only model-derived HTML in the app.
            'html' => $result['html'],
        ]);
    }

    public function reset(): JsonResponse
    {
        $this->assistant->resetHistory();

        return response()->json(['status' => 'success']);
    }
}
