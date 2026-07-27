<?php

namespace Tests\Feature\Educator;

use Tests\TestCase;

/**
 * Task 28: guards the two ways the assistant drawer can break silently in the browser.
 *
 *  1. The server gains a new `status` and the drawer has no alert styling for it.
 *  2. Someone uses a Tailwind/KTUI class that is not in the compiled bundle. The Metronic build
 *     is PRE-PURGED — it contains only what the demo used — so `pe-20` silently did nothing and
 *     long questions rendered underneath the send button. Nothing at runtime warns about this.
 */
class AssistantAlertCoverageTest extends TestCase
{
    private const DRAWER = 'resources/views/layouts/partials/_assistant_drawer.blade.php';

    private const BUNDLE = 'public/metronic-tailwind-html-demos/dist/assets/css/styles.css';

    private const ICONS = 'public/metronic-tailwind-html-demos/dist/assets/vendors/keenicons/styles.bundle.css';

    /** Every non-ok status App\Services\Ai\AssistantService can hand back to the browser. */
    private const SERVER_STATUSES = [
        'blocked', 'rate_limited', 'unavailable', 'tool_loop', 'tool_fan_out', 'exhausted_rounds',
    ];

    public function test_every_server_status_has_alert_styling_in_the_drawer(): void
    {
        $drawer = $this->read(self::DRAWER);
        $service = $this->read('app/Services/Ai/AssistantService.php');

        foreach (self::SERVER_STATUSES as $status) {
            $this->assertStringContainsString($status.':', $drawer,
                "status [{$status}] has no entry in the drawer's ALERTS map — it would fall back to a generic error");

            // Keeps this list honest in the other direction too: a status listed here that the
            // service no longer produces is dead weight worth noticing.
            $this->assertStringContainsString("'".$status."'", $service,
                "status [{$status}] is styled in the drawer but the service never returns it");
        }
    }

    public function test_the_drawer_only_uses_kt_classes_that_exist_in_the_compiled_bundle(): void
    {
        $drawer = $this->read(self::DRAWER);
        $bundle = $this->read(self::BUNDLE);

        // The lookbehind skips `data-kt-*`, which are KTUI's JavaScript hooks — they are attributes,
        // not classes, and are correctly absent from the stylesheet.
        preg_match_all('/(?<!data-)\bkt-[a-z0-9-]+/', $drawer, $matches);

        $missing = [];
        foreach (array_unique($matches[0]) as $class) {
            if (! str_contains($bundle, '.'.$class)) {
                $missing[] = $class;
            }
        }

        $this->assertSame([], $missing,
            'these kt-* classes are used by the drawer but absent from the purged Metronic bundle: '.implode(', ', $missing));
    }

    public function test_the_drawer_only_uses_icons_that_exist_in_the_icon_font(): void
    {
        $drawer = $this->read(self::DRAWER);
        $icons = $this->read(self::ICONS);

        preg_match_all('/\bki-[a-z0-9-]+/', $drawer, $matches);

        $missing = [];
        foreach (array_unique($matches[0]) as $icon) {
            if ($icon === 'ki-filled' || $icon === 'ki-outline') {
                continue; // style modifiers, not glyphs
            }

            if (! str_contains($icons, '.'.$icon)) {
                $missing[] = $icon;
            }
        }

        $this->assertSame([], $missing,
            'these ki-* icons are referenced but absent from the keenicons bundle: '.implode(', ', $missing));
    }

    public function test_the_alert_markup_matches_the_ktui_contract(): void
    {
        $drawer = $this->read(self::DRAWER);

        // The nesting KTUI's CSS expects. Getting this wrong renders an unstyled box, which no
        // other test would catch. The alerts are deliberately not dismissible, so there is no
        // kt-alert-toolbar / kt-alert-close / data-kt-dismiss here.
        foreach (['kt-alert-light', 'kt-alert-icon', 'kt-alert-title', 'kt-alert-description'] as $piece) {
            $this->assertStringContainsString($piece, $drawer, "alert markup is missing [{$piece}]");
        }

        $this->assertStringNotContainsString('kt-alert-close', $drawer,
            'alerts are inline conversation records and must not carry a dismiss button');
    }

    public function test_every_variant_colours_its_icon_and_title(): void
    {
        $drawer = $this->read(self::DRAWER);

        // KTUI tints the icon via `.kt-alert-icon > svg`, but the drawer renders a keenicons <i>
        // glyph — so without an explicit rule per variant every alert renders the same colour
        // while every class still "exists". That failure is invisible to the class check above.
        foreach (['primary', 'destructive', 'warning', 'info', 'success'] as $variant) {
            $this->assertMatchesRegularExpression(
                '/kt-alert-'.$variant.' \.kt-alert-icon > i,\s*[^\n]*\n?[^\n]*kt-alert-'.$variant.' \.kt-alert-title\{color:/',
                $drawer,
                "variant [{$variant}] does not colour its <i> icon and title"
            );
        }
    }

    private function read(string $relative): string
    {
        $path = base_path($relative);
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
