<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Log;

/**
 * Task 28: the assistant's audit trail, on its own `ai` log channel.
 *
 * What goes in: who asked, when, which retrieval tools ran, how many rows came back, whether
 * authorization succeeded, token usage, and the final outcome.
 *
 * What never goes in: the educator's raw message, the model's reply, student names or numbers,
 * scores, and the API key. That is enough to investigate "why did it answer that?" and to spot
 * bulk-extraction or repeated-refusal patterns, without turning the log into a second copy of the
 * student records.
 */
class AiAudit
{
    /** @param array<string, mixed> $context */
    public function request(int $educatorId, string $outcome, array $context = []): void
    {
        Log::channel('ai')->info('assistant.request', [
            'educator_id' => $educatorId,
            'outcome' => $outcome,
        ] + $context);
    }

    /** Guardrail trips and authorization failures — the lines worth alerting on. */
    public function security(int $educatorId, string $event, array $context = []): void
    {
        Log::channel('ai')->warning('assistant.security', [
            'educator_id' => $educatorId,
            'event' => $event,
        ] + $context);
    }
}
