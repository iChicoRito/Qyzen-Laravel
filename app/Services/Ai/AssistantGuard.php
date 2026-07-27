<?php

namespace App\Services\Ai;

use Illuminate\Support\Str;

/**
 * Task 28: deterministic filters either side of the model.
 *
 * IMPORTANT: this class is NOT the security boundary. Authorization lives in
 * EducatorDataTools, where every query runs through a visibleTo scope — a regex that can be
 * phrased around must never be the thing standing between two educators' data. This layer exists
 * to (a) refuse obvious probes without spending a request from the daily allowance, and
 * (b) catch secrets on the way out if the model is ever induced to emit one.
 */
class AssistantGuard
{
    public const REFUSAL = 'I can only help with your own students, classes, assessments, quizzes, scores, grades, and enrollments. I cannot discuss how this system is built or configured.';

    public const OFF_TOPIC = 'I am the academic assistant for your classes. Ask me about your students, subjects, sections, assessments, quizzes, scores, grades, or enrollments.';

    public const UNAVAILABLE = 'The assistant is unavailable right now. Please try again in a few minutes.';

    public const BUDGET_EXCEEDED = 'The assistant has reached its usage limit for now. Please try again shortly.';

    /**
     * The per-minute allowance is shared by the whole app and refills continuously, so an exact
     * countdown is both possible and far less frustrating than "shortly".
     */
    public static function budgetMessage(int $seconds): string
    {
        if ($seconds < 1) {
            return self::BUDGET_EXCEEDED;
        }

        return "The assistant has used its shared per-minute allowance. Try again in about {$seconds} second".($seconds === 1 ? '' : 's').'.';
    }

    public const NO_ANSWER = 'I could not find that in your records. Try naming the student, subject, or assessment exactly as it appears in your class list.';

    /** The model emits this verbatim when a question falls outside the academic scope. */
    public const OFF_TOPIC_MARKER = '[[OFF_TOPIC]]';

    public const MAX_INPUT_LENGTH = 1000;

    /**
     * Probes for protected system information and instruction-override attempts. Matched against
     * the normalized message, so spacing, casing, zero-width padding, and full-width homoglyph
     * variants all collapse onto the same pattern before this runs.
     */
    private const BLOCKED = [
        // Instruction override / role escape.
        '/ignore\s+(all\s+|any\s+)?(previous|prior|above|earlier)\s+(instruction|prompt|rule|message)/i',
        '/disregard\s+(your|all|the)\s+(instruction|rule|prompt|guideline)/i',
        '/(system|developer|initial|hidden|original)\s+(prompt|message|instruction)/i',
        '/(reveal|show|print|repeat|output|display|dump)\s+(me\s+)?(your|the)\s+(prompt|instruction|rule|context|configuration)/i',
        '/\b(jailbreak|dan\s+mode|developer\s+mode|god\s+mode|sudo\s+mode)\b/i',
        '/act\s+as\s+(an?\s+)?(admin|administrator|root|superuser|developer|system)/i',
        '/pretend\s+(you\s+are|to\s+be)\s+(an?\s+)?(admin|administrator|root|another\s+educator)/i',
        '/you\s+are\s+now\s+(an?\s+)?(admin|administrator|unrestricted|uncensored)/i',
        '/\bchain[\s-]?of[\s-]?thought\b|\bthink\s+step\s+by\s+step\s+and\s+(show|reveal)/i',
        // Credentials / environment / infrastructure.
        '/\b(api[\s_-]?key|secret[\s_-]?key|access[\s_-]?token|bearer\s+token|auth[\s_-]?token)\b/i',
        '/\b(groq|openai|anthropic)[\s_-]?(key|token|credential)\b/i',
        '/\b(env|dotenv)\b.*\b(file|var|variable|value)\b|\.env\b|\bgetenv\b|\bprocess\.env\b/i',
        '/\benvironment\s+variable/i',
        '/\b(database|db|mysql|admin)\s+(password|credential|user(name)?|connection\s+string)\b/i',
        '/\b(app_key|db_password|groq_api_key|aws_secret)\b/i',
        // Source code / schema / infrastructure enumeration.
        '/\b(source\s+code|your\s+code|codebase|repository|git\s+log)\b/i',
        '/\b(database|db)\s+(schema|structure|table|column)s?\b/i',
        '/\b(show|list|describe|dump|enumerate)\s+(me\s+)?(all\s+)?(the\s+)?(table|column|schema|migration|route|endpoint)s?\b/i',
        '/\binformation_schema\b|\bsqlite_master\b|\btbl_[a-z_]+\b/i',
        // Raw query / command execution.
        '/\b(select|insert|update|delete|drop|truncate|alter|union)\b[\s\S]{0,40}\b(from|into|table|set|where|select)\b/i',
        '/\b(exec|shell_exec|system|passthru|eval)\s*\(|\brm\s+-rf\b|\bcurl\s+http/i',
        // Cross-educator access.
        '/\b(other|another|different|all)\s+(educator|teacher|instructor)s?[\s\S]{0,25}\b(student|score|grade|class|record|data)/i',
        '/\b(every|all)\s+(student|score|grade|record)s?\s+in\s+the\s+(system|database|school)\b/i',
        '/\bbypass\b[\s\S]{0,25}\b(auth|permission|restriction|security|filter)/i',
    ];

    /** Secret-shaped strings scrubbed from anything leaving the server. */
    private const SECRET_PATTERNS = [
        '/\bgsk_[A-Za-z0-9]{10,}/',
        '/\bsk-[A-Za-z0-9_\-]{10,}/',
        '/\bbase64:[A-Za-z0-9+\/=]{20,}/',
        '/\b(mysql|postgres(ql)?|redis|mongodb):\/\/\S+/i',
        '/\b(Bearer|Authorization:)\s+[A-Za-z0-9._\-]{16,}/i',
        '/\b[A-Z][A-Z0-9_]{5,}\s*=\s*\S{8,}/',
    ];

    /**
     * Returns the fallback copy to send back, or null when the message may proceed to the model.
     */
    public function inspectInput(string $message): ?string
    {
        $normalized = self::normalize($message);

        if ($normalized === '' || mb_strlen($normalized) > self::MAX_INPUT_LENGTH) {
            return self::OFF_TOPIC;
        }

        foreach (self::BLOCKED as $pattern) {
            if (preg_match($pattern, $normalized)) {
                return self::REFUSAL;
            }
        }

        // A long unbroken base64/hex run is an encoded payload, not a question about a class.
        if (preg_match('/[A-Za-z0-9+\/=]{80,}/', $normalized)) {
            return self::REFUSAL;
        }

        return null;
    }

    /**
     * Final pass over model output: resolve the off-topic marker, scrub secrets, and strip markup
     * so nothing renderable survives even if the client is later changed to use innerHTML.
     */
    public function inspectOutput(string $output): string
    {
        $clean = trim($output);

        if ($clean === '') {
            return self::NO_ANSWER;
        }

        if (str_contains($clean, self::OFF_TOPIC_MARKER)) {
            return self::OFF_TOPIC;
        }

        $clean = self::redact($clean);
        // Any raw HTML the model emitted dies here. Markdown syntax survives, because the drawer
        // renders it via renderHtml() below.
        $clean = strip_tags($clean);

        // Belt-and-braces: if the model somehow emitted a system-prompt fragment or a raw query,
        // refuse wholesale rather than shipping a partially-redacted leak.
        if (preg_match('/\btbl_[a-z_]+\b/i', $clean)
            || preg_match('/\bSELECT\b[\s\S]{0,40}\bFROM\b/i', $clean)
            || preg_match('/\bSYSTEM_RULES\b|\byou are an academic assistant\b/i', $clean)) {
            return self::REFUSAL;
        }

        return trim($clean);
    }

    /**
     * The only tags the drawer will ever be handed. No <a>, no <img>, no <script>, no <iframe> —
     * a link's text still shows, it just stops being clickable.
     */
    private const ALLOWED_TAGS = '<p><br><strong><em><del><code><pre><ul><ol><li><blockquote><h3><h4><table><thead><tbody><tr><th><td>';

    /**
     * Render the assistant's markdown for display.
     *
     * This is the one place model output becomes HTML, so it is defence in depth, in this order:
     *   1. inspectOutput() has already redacted secrets and stripped any raw HTML from the text.
     *   2. html_input:strip — anything HTML-shaped that survived is removed, never re-emitted.
     *   3. allow_unsafe_links:false — javascript:/data: URLs are dropped by the parser.
     *   4. strip_tags to the allowlist above.
     *   5. every attribute is stripped from every surviving tag, so there is no href, src, style,
     *      or on* handler left to carry a payload.
     *
     * Steps 4 and 5 mean the output is safe even if a future CommonMark option is misconfigured.
     */
    public static function renderHtml(string $text): string
    {
        $html = Str::markdown($text, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 10,
        ]);

        $html = strip_tags($html, self::ALLOWED_TAGS);

        // Opening tags only — the pattern cannot match "</p>", so closing tags pass through intact.
        $html = (string) preg_replace('/<([a-z0-9]+)\b[^>]*>/i', '<$1>', $html);

        return trim($html);
    }

    /** Replace secret-shaped substrings. Static so GroqClient can scrub a provider error body. */
    public static function redact(string $text): string
    {
        return (string) preg_replace(self::SECRET_PATTERNS, '[redacted]', $text);
    }

    /**
     * Collapse the tricks that let an injection slip past a literal pattern: Unicode compatibility
     * forms (full-width letters), zero-width joiners/spaces, control characters, and runs of
     * whitespace or repeated punctuation.
     */
    public static function normalize(string $text): string
    {
        if (class_exists(\Normalizer::class)) {
            $text = \Normalizer::normalize($text, \Normalizer::FORM_KC) ?: $text;
        }

        // Zero-width space/non-joiner/joiner, BOM, soft hyphen, and the bidi overrides.
        $text = (string) preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}\x{FEFF}\x{00AD}]/u', '', $text);
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
        $text = (string) preg_replace('/[\s]+/u', ' ', $text);

        return trim($text);
    }
}
