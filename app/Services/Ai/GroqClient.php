<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Task 28: the ONLY class in the app that reads config('services.groq.key').
 *
 * Everything else — the service, the tools, the controller, the view — works with plain arrays.
 * That single-reader rule is what makes "the key never reaches the browser, a log, or a prompt"
 * auditable rather than aspirational.
 */
class GroqClient
{
    // Deliberately short: an educator is staring at a spinner. A slow provider should fail into
    // the fallback copy, not hold a PHP-FPM worker for a minute.
    private const TIMEOUT = 20;

    private const CONNECT_TIMEOUT = 5;

    // Caps the completion, which caps the token budget burn per request (spec: 8k tokens/min).
    // 500 truncated a ranked markdown table mid-name, so a table has to fit: roughly 40 tokens a
    // row plus the header, and get_class_scores defaults to 5 rows. Every token here is one the
    // per-minute allowance cannot spend on the next question, so it stays as low as that allows.
    public const MAX_TOKENS = 800;

    public function configured(): bool
    {
        return filled(config('services.groq.key'));
    }

    /**
     * One chat-completions round trip.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, array<string, mixed>>  $tools  JSON-schema tool definitions; [] disables tool calling
     * @return array{content: string, tool_calls: array<int, array<string, mixed>>, usage: array{prompt_tokens: int, completion_tokens: int, total_tokens: int}}
     *
     * @throws GroqUnavailableException
     */
    public function chat(array $messages, array $tools = []): array
    {
        if (! $this->configured()) {
            throw new GroqUnavailableException('GROQ_API_KEY is not set.');
        }

        $payload = [
            'model' => (string) config('services.groq.model'),
            'messages' => $messages,
            // Low temperature: this assistant reports database rows, it does not brainstorm.
            'temperature' => 0.2,
            'max_tokens' => self::MAX_TOKENS,
        ];

        if ($tools !== []) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
        }

        try {
            $response = Http::withToken((string) config('services.groq.key'))
                ->timeout(self::TIMEOUT)
                ->connectTimeout(self::CONNECT_TIMEOUT)
                // Retry transport blips only. `when` explicitly excludes 429 and every other 4xx:
                // retrying a rate-limit reply is how you burn a 1,000-request daily allowance in
                // an afternoon (spec: "prevent automatic retry loops").
                ->retry(2, 400, function ($exception) {
                    return $exception instanceof ConnectionException;
                }, throw: false)
                ->post(rtrim((string) config('services.groq.base_url'), '/').'/chat/completions', $payload);
        } catch (ConnectionException $e) {
            throw new GroqUnavailableException('Groq connection failed: '.$e->getMessage());
        }

        return $this->parse($response);
    }

    /**
     * @return array{content: string, tool_calls: array<int, array<string, mixed>>, usage: array{prompt_tokens: int, completion_tokens: int, total_tokens: int}}
     */
    private function parse(Response $response): array
    {
        if ($response->failed()) {
            // The body can echo back request metadata, so it goes to the ai channel and stops
            // there. Truncated because a provider HTML error page is not worth a megabyte of log.
            Log::channel('ai')->warning('Groq request failed', [
                'status' => $response->status(),
                'body' => mb_substr(AssistantGuard::redact($response->body()), 0, 500),
            ]);

            throw new GroqUnavailableException('Groq returned HTTP '.$response->status());
        }

        $json = $response->json();
        $message = data_get($json, 'choices.0.message');

        if (! is_array($message)) {
            throw new GroqUnavailableException('Groq returned an unexpected payload shape.');
        }

        // NOTE: `reasoning` / `reasoning_content` on gpt-oss models is deliberately dropped here
        // and never propagated — chain-of-thought is protected content (spec line 172).
        return [
            'content' => (string) ($message['content'] ?? ''),
            'tool_calls' => is_array($message['tool_calls'] ?? null) ? $message['tool_calls'] : [],
            'usage' => [
                'prompt_tokens' => (int) data_get($json, 'usage.prompt_tokens', 0),
                'completion_tokens' => (int) data_get($json, 'usage.completion_tokens', 0),
                'total_tokens' => (int) data_get($json, 'usage.total_tokens', 0),
            ],
        ];
    }
}
