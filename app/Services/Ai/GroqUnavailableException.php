<?php

namespace App\Services\Ai;

use RuntimeException;

/**
 * Task 28: the provider could not be reached, refused the request, or answered with something
 * we could not parse. The message on this exception is developer-facing and goes to the `ai`
 * log channel only — the educator always sees AssistantGuard::UNAVAILABLE instead, so provider
 * error bodies (which can echo request headers) never reach a browser.
 */
class GroqUnavailableException extends RuntimeException {}
