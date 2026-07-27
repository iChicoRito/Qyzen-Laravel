<?php

namespace Tests\Unit\Services;

use App\Services\Ai\GroqBudget;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

// Task 28: the app-wide provider allowance. These limits are the published free-tier numbers, and
// a live run proved the accounting matters: an earlier fixed-window version let a burst through at
// a minute boundary and Groq returned the 429 this class exists to prevent.
class GroqBudgetTest extends TestCase
{
    private GroqBudget $budget;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush(); // phpunit.xml pins CACHE_STORE=array, but the store persists within a test.
        $this->budget = new GroqBudget;
    }

    public function test_the_request_per_minute_ceiling_is_enforced_with_headroom(): void
    {
        // 5% of the allowance is left unspent as headroom, so the ceiling is 28, not 30.
        for ($i = 0; $i < 28; $i++) {
            $this->assertTrue($this->budget->attempt(1), "request {$i} should be allowed");
        }

        $this->assertFalse($this->budget->attempt(1));
        $this->assertLessThan(GroqBudget::REQUESTS_PER_MINUTE, $this->budget->snapshot()['requests_this_minute']);
    }

    public function test_limits_are_configurable_so_a_tier_upgrade_needs_no_code_change(): void
    {
        config(['services.groq.limits.tokens_per_minute' => 80000]);

        // Ten times the free-tier allowance: spend that would be refused by default now fits.
        for ($i = 0; $i < 20; $i++) {
            $this->assertTrue($this->budget->attempt(2000), "call {$i} should fit the raised limit");
        }

        $this->assertSame(40000, $this->budget->snapshot()['tokens_this_minute']);
    }

    public function test_a_zero_or_negative_configured_limit_falls_back_to_the_free_tier_default(): void
    {
        config(['services.groq.limits.tokens_per_minute' => 0]);

        $this->assertTrue($this->budget->attempt(6000));
        $this->assertFalse($this->budget->attempt(6000), 'a bad config value must not disable the budget');
    }

    public function test_the_retry_countdown_reports_when_the_window_frees_up(): void
    {
        $this->assertSame(0, $this->budget->secondsUntilAvailable(1000), 'an empty window fits anything');

        $this->budget->attempt(7000);

        // 7,000 spent against a 7,600 ceiling: 1,000 more does not fit until that spend ages out.
        $wait = $this->budget->secondsUntilAvailable(1000);
        $this->assertGreaterThan(0, $wait);
        $this->assertLessThanOrEqual(60, $wait);

        $this->travel(59)->seconds();
        $this->assertGreaterThan(0, $this->budget->secondsUntilAvailable(1000));

        $this->travel(2)->seconds();
        $this->assertSame(0, $this->budget->secondsUntilAvailable(1000), 'the spend has aged out of the window');
    }

    public function test_the_token_per_minute_ceiling_is_enforced_independently_of_the_request_count(): void
    {
        // Three calls of 2,000 fit under the 7,200 effective ceiling; the fourth does not.
        for ($i = 0; $i < 3; $i++) {
            $this->assertTrue($this->budget->attempt(2000));
        }

        $this->assertFalse($this->budget->attempt(2000));
        $this->assertSame(6000, $this->budget->snapshot()['tokens_this_minute']);
    }

    public function test_actual_usage_below_the_reservation_is_refunded(): void
    {
        $this->assertTrue($this->budget->attempt(2000));
        $this->assertSame(2000, $this->budget->snapshot()['tokens_this_minute']);

        $this->budget->record(2000, 300);

        $this->assertSame(300, $this->budget->snapshot()['tokens_this_minute']);
    }

    public function test_actual_usage_above_the_reservation_is_charged(): void
    {
        $this->assertTrue($this->budget->attempt(1000));
        $this->budget->record(1000, 2500);

        $this->assertSame(2500, $this->budget->snapshot()['tokens_this_minute']);
    }

    /**
     * The regression that caused a real 429: with fixed 60s buckets, spend at 00:59 vanished at
     * 01:00 while Groq's rolling window still held it. The window must decay continuously.
     */
    public function test_the_minute_window_rolls_rather_than_resetting_on_a_boundary(): void
    {
        $this->budget->attempt(5000);
        $this->assertSame(5000, $this->budget->snapshot()['tokens_this_minute']);

        // 30 seconds on: still inside the window, so the spend must still count.
        $this->travel(30)->seconds();
        $this->assertSame(5000, $this->budget->snapshot()['tokens_this_minute']);
        $this->assertFalse($this->budget->attempt(4000), 'a burst straddling the boundary must not be admitted');

        // Past 60 seconds the original spend has aged out.
        $this->travel(31)->seconds();
        $this->assertSame(0, $this->budget->snapshot()['tokens_this_minute']);
        $this->assertTrue($this->budget->attempt(4000));
    }

    public function test_the_daily_counter_survives_the_minute_window_rolling_over(): void
    {
        $this->budget->attempt(5000);

        $this->travel(61)->seconds();

        $this->assertSame(0, $this->budget->snapshot()['tokens_this_minute']);
        $this->assertSame(5000, $this->budget->snapshot()['tokens_today']);
        $this->assertSame(1, $this->budget->snapshot()['requests_today']);
    }

    public function test_the_estimate_scales_with_payload_size(): void
    {
        $small = GroqBudget::estimateTokens(str_repeat('a', 400), 500);
        $large = GroqBudget::estimateTokens(str_repeat('a', 8000), 500);

        $this->assertSame(600, $small);   // 400/4 + 500
        $this->assertSame(2500, $large);  // 8000/4 + 500
        $this->assertGreaterThan($small, $large);
    }
}
