<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Cache;

/**
 * Task 28: the published limits for the free Groq tier, enforced before we call out.
 *
 * These are APPLICATION-WIDE, not per-educator — there is one API key, so one educator hammering
 * the assistant would otherwise spend everyone's daily allowance. Per-educator abuse control is a
 * separate concern and lives in the `throttle:assistant` limiter on the route.
 *
 * The per-minute windows are ROLLING, matching how Groq accounts for them. An earlier fixed-bucket
 * version let a burst straddling a minute boundary through — our counter reset to zero while
 * Groq's window still held ~7k tokens, and the provider returned the 429 we were supposed to
 * prevent. The daily windows stay fixed-bucket, because a calendar-day reset is genuinely correct.
 */
class GroqBudget
{
    // Free-tier defaults for openai/gpt-oss-120b. The live values come from
    // config('services.groq.limits') so a tier upgrade is an .env change, not a code change.
    public const REQUESTS_PER_MINUTE = 30;

    public const REQUESTS_PER_DAY = 1000;

    public const TOKENS_PER_MINUTE = 8000;

    public const TOKENS_PER_DAY = 200000;

    private function limit(string $name, int $default): int
    {
        $value = (int) config("services.groq.limits.{$name}", $default);

        return $value > 0 ? $value : $default;
    }

    private function requestsPerMinute(): int
    {
        return $this->limit('requests_per_minute', self::REQUESTS_PER_MINUTE);
    }

    private function requestsPerDay(): int
    {
        return $this->limit('requests_per_day', self::REQUESTS_PER_DAY);
    }

    private function tokensPerMinute(): int
    {
        return $this->limit('tokens_per_minute', self::TOKENS_PER_MINUTE);
    }

    private function tokensPerDay(): int
    {
        return $this->limit('tokens_per_day', self::TOKENS_PER_DAY);
    }

    /**
     * Only spend this fraction of the per-minute allowance. Our accounting and Groq's cannot be
     * exactly in step — their window advances continuously and their tokenizer is not ours — so the
     * remainder is deliberate headroom that turns a provider 429 into our own clean fallback.
     */
    private const SAFETY_FACTOR = 0.95;

    /**
     * Tokens reserved for the completion when sizing a request, deliberately lower than
     * GroqClient::MAX_TOKENS. Observed completions run 58–180 tokens; reserving the full 500 cap
     * on every round trip was rejecting requests that would have fit. record() reconciles against
     * the real figure immediately after the call, so a low reservation costs accuracy for one call,
     * never for the window.
     */
    public const COMPLETION_RESERVE = 400;

    /** Fallback when the caller cannot estimate; real calls pass a payload-derived figure. */
    public const ESTIMATED_TOKENS_PER_CALL = 2000;

    private const MINUTE_KEY = 'groq:window:minute';

    /**
     * Rough token count for a chunk of request payload. Four characters per token is the usual
     * English approximation and errs slightly high on JSON, which is the safe direction here.
     */
    public static function estimateTokens(string $payload, int $maxCompletionTokens): int
    {
        return (int) ceil(mb_strlen($payload) / 4) + $maxCompletionTokens;
    }

    /**
     * Reserve one request plus an estimated token spend. False means "do not call the provider" —
     * the caller must return the budget fallback rather than queueing or retrying.
     */
    public function attempt(int $estimatedTokens = self::ESTIMATED_TOKENS_PER_CALL): bool
    {
        $events = $this->recentEvents();

        $requestsThisMinute = count($events);
        $tokensThisMinute = array_sum(array_column($events, 'tokens'));

        if ($requestsThisMinute + 1 > (int) ($this->requestsPerMinute() * self::SAFETY_FACTOR)) {
            return false;
        }

        if ($tokensThisMinute + $estimatedTokens > (int) ($this->tokensPerMinute() * self::SAFETY_FACTOR)) {
            return false;
        }

        if ($this->daily('req') + 1 > $this->requestsPerDay()) {
            return false;
        }

        if ($this->daily('tok') + $estimatedTokens > $this->tokensPerDay()) {
            return false;
        }

        $events[] = ['at' => now()->getTimestamp(), 'tokens' => $estimatedTokens];
        $this->putEvents($events);

        $this->addDaily('req', 1);
        $this->addDaily('tok', $estimatedTokens);

        return true;
    }

    /**
     * Reconcile the reservation against what the provider actually charged, by correcting the
     * most recent event in place.
     *
     * ponytail: read-modify-write with no lock. Two concurrent requests can lose one reconciliation,
     *   which costs a little accuracy inside the safety margin. Add a cache lock only if this
     *   ever runs behind more than one PHP worker under real concurrency.
     */
    public function record(int $estimatedTokens, int $actualTokens): void
    {
        $delta = $actualTokens - $estimatedTokens;

        if ($delta === 0) {
            return;
        }

        $events = $this->recentEvents();

        if ($events !== []) {
            $last = array_key_last($events);
            $events[$last]['tokens'] = max(0, $events[$last]['tokens'] + $delta);
            $this->putEvents($events);
        }

        $this->addDaily('tok', $delta);
    }

    /**
     * How many seconds until `$needed` tokens would fit in the per-minute window, assuming no
     * further spend. Drives the "try again in N seconds" copy — a rolling window makes this exact,
     * so the educator gets a countdown instead of a vague "shortly".
     *
     * Returns 0 when it would fit right now, and 60 when a single request is larger than the whole
     * window will ever hold.
     */
    public function secondsUntilAvailable(int $needed): int
    {
        $ceiling = (int) ($this->tokensPerMinute() * self::SAFETY_FACTOR);
        $events = $this->recentEvents();
        $tokens = (int) array_sum(array_column($events, 'tokens'));

        if ($tokens + $needed <= $ceiling) {
            return 0;
        }

        $now = now()->getTimestamp();

        // Events are appended in time order, so expiring them oldest-first walks the window down.
        foreach ($events as $event) {
            $tokens -= $event['tokens'];

            if ($tokens + $needed <= $ceiling) {
                return max(1, ($event['at'] + 60) - $now);
            }
        }

        return 60;
    }

    /** @return array<string, int> current usage, for the audit line */
    public function snapshot(): array
    {
        $events = $this->recentEvents();

        return [
            'requests_this_minute' => count($events),
            'requests_today' => $this->daily('req'),
            'tokens_this_minute' => (int) array_sum(array_column($events, 'tokens')),
            'tokens_today' => $this->daily('tok'),
        ];
    }

    // ---------------------------------------------------------------- windows

    /**
     * The last 60 seconds of calls, pruned on read. Clocked via now() rather than time() so the
     * window is testable with time travel.
     *
     * @return array<int, array{at: int, tokens: int}>
     */
    private function recentEvents(): array
    {
        $cutoff = now()->getTimestamp() - 60;
        $events = Cache::get(self::MINUTE_KEY, []);

        if (! is_array($events)) {
            return [];
        }

        return array_values(array_filter($events, fn ($e) => is_array($e) && ($e['at'] ?? 0) > $cutoff));
    }

    /** @param array<int, array{at: int, tokens: int}> $events */
    private function putEvents(array $events): void
    {
        Cache::put(self::MINUTE_KEY, array_values($events), 120);
    }

    private function dailyKey(string $metric): string
    {
        return "groq:{$metric}:day:".now()->toDateString();
    }

    private function daily(string $metric): int
    {
        return (int) Cache::get($this->dailyKey($metric), 0);
    }

    private function addDaily(string $metric, int $amount): void
    {
        $key = $this->dailyKey($metric);

        Cache::put($key, max(0, $this->daily($metric) + $amount), 90000);
    }
}
