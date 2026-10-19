<?php

declare(strict_types=1);

namespace DistrictApp\Tests\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/app/help-helpers.php';

/**
 * Property test for descriptive action link text.
 *
 * Feature: help-centre, Property 8: Action links use descriptive text
 *
 * Property statement (design.md, Property 8): For any Action_Block action, the
 * rendered link's visible text SHALL be the action's `label` and SHALL NOT be
 * the bare target URL.
 *
 * Validates: Requirements 3.4, 6.3
 *
 * The functions under test are help_render_action_link() and
 * help_render_actions() in app/help-helpers.php. Both are pure functions over
 * a block/action array, so this test exercises them directly with no database.
 *
 * help_render_action_link() uses the action `label` as the visible link text
 * and puts the `url` only in the href. The external new-tab affordance adds an
 * aria-hidden icon span and a visually-hidden "(opens in a new tab)" span; the
 * test strips those hint spans before comparing the human-visible text so the
 * remaining text is exactly the (escaped) label.
 *
 * The test drives 150 randomized actions with descriptive labels that differ
 * from their urls (internal and external forms), asserting:
 *   - the <a> inner text (hint spans removed, tags stripped) equals the escaped
 *     label — the label is shown,
 *   - the url does NOT appear as the anchor's visible (tag-stripped) text, even
 *     though it legitimately appears inside href="...",
 *   - empty label OR empty url skips the action (renders ''),
 *   - a label identical to the url still carries the url in href while the
 *     visible text is the (escaped) label.
 */
#[Group('help-centre')]
#[Group('property')]
final class HelpRenderActionLabelPropertyTest extends TestCase
{
    /** Property tests run a minimum of 100 iterations; we use 150. */
    private const ITERATIONS = 150;

    #[Test]
    #[TestDox('Property 8: the rendered link shows the label, never the bare url, as visible text (100+ randomized actions)')]
    public function actionLinksUseDescriptiveText(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $action = $this->randomAction($i);

            $html = help_render_action_link($action);

            $label = (string) ($action['label'] ?? '');
            $url   = (string) ($action['url'] ?? '');

            // Edge: a missing/empty label or url skips the action entirely.
            if ($label === '' || $url === '') {
                self::assertSame(
                    '',
                    $html,
                    "Iteration {$i}: an action missing a label or url must render as empty"
                );
                continue;
            }

            // The url must appear inside the href attribute (escaped).
            $expectedHref = 'href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"';
            self::assertStringContainsString(
                $expectedHref,
                $html,
                "Iteration {$i}: the url must be carried in the href attribute"
            );

            // The human-visible text of the anchor is the label — never the url.
            $visible = $this->visibleText($html);

            self::assertSame(
                $this->normalise($label),
                $this->normalise($visible),
                "Iteration {$i}: the anchor's visible text must be the label"
            );

            // The url must not be the visible text. When label and url differ,
            // the bare url must not appear in the visible text at all.
            if ($this->normalise($label) !== $this->normalise($url)) {
                self::assertStringNotContainsString(
                    $this->normalise($url),
                    $this->normalise($visible),
                    "Iteration {$i}: the bare url must not appear as the anchor's visible text"
                );
            }
        }
    }

    #[Test]
    #[TestDox('Property 8: a label identical to the url still shows the escaped label as text and carries the url in href')]
    public function labelEqualToUrlStillShowsLabelAsText(): void
    {
        $shared = 'https://outlook.cloud.microsoft/path?a=1&b=2';

        $html = help_render_action_link([
            'label' => $shared,
            'url'   => $shared,
        ]);

        // The url is carried in href (escaped).
        self::assertStringContainsString(
            'href="' . htmlspecialchars($shared, ENT_QUOTES, 'UTF-8') . '"',
            $html,
            'the url must be carried in the href attribute'
        );

        // The visible text is the escaped label (which equals the shared value).
        $visible = $this->visibleText($html);
        self::assertSame(
            $this->normalise($shared),
            $this->normalise($visible),
            'the visible text must be the label even when it equals the url'
        );
    }

    #[Test]
    #[TestDox('Property 8: the label is shown (and the url never shown as text) across a mixed actions block')]
    public function descriptiveTextHoldsThroughActionsBlock(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $actions = [];
            $rendered = [];
            $count = random_int(1, 5);
            for ($n = 0; $n < $count; $n++) {
                $action = $this->randomAction($i * 10 + $n);
                $actions[] = $action;

                $label = (string) ($action['label'] ?? '');
                $url   = (string) ($action['url'] ?? '');
                if ($label !== '' && $url !== '') {
                    $rendered[] = ['label' => $label, 'url' => $url];
                }
            }

            $html = help_render_actions(['actions' => $actions]);

            // Every rendered action contributes its escaped label to the block's
            // visible text, and no url appears as visible text.
            $visible = $this->visibleText($html);
            $normVisible = $this->normalise($visible);

            foreach ($rendered as $r) {
                self::assertStringContainsString(
                    $this->normalise($r['label']),
                    $normVisible,
                    "Iteration {$i}: each action's label must appear in the block's visible text"
                );

                if ($this->normalise($r['label']) !== $this->normalise($r['url'])) {
                    self::assertStringNotContainsString(
                        $this->normalise($r['url']),
                        $normVisible,
                        "Iteration {$i}: no action's bare url may appear as visible text"
                    );
                }
            }
        }
    }

    /**
     * Extract the human-visible text of the rendered anchor(s): strip the
     * external new-tab hint spans (the aria-hidden icon and the visually-hidden
     * "(opens in a new tab)" text), then strip all remaining tags. The hint is
     * an affordance, not part of the descriptive label, so it is removed before
     * comparing the visible text against the label.
     */
    private function visibleText(string $html): string
    {
        // Remove the external-hint spans wholesale (icon + visually-hidden text).
        $withoutHint = preg_replace(
            '#<span class="lt-help-ext"[^>]*>.*?</span>\s*<span class="visually-hidden">.*?</span>#s',
            '',
            $html
        );

        // Strip any remaining tags to get the anchor's visible text content.
        return strip_tags($withoutHint ?? $html);
    }

    /**
     * Decode HTML entities and trim, so comparisons are against the human-read
     * text rather than its escaped representation. help_render_action_link()
     * escapes the label into the markup; decoding here lets us compare to the
     * raw label the author supplied.
     */
    private function normalise(string $text): string
    {
        return trim(html_entity_decode($text, ENT_QUOTES, 'UTF-8'));
    }

    /**
     * Build a randomized action with a descriptive label that is distinct from
     * its url. The first few iterations deterministically exercise the skipped
     * branches (empty label / empty url).
     *
     * @return array<string, mixed>
     */
    private function randomAction(int $seed): array
    {
        // Deterministic edge cases up front.
        if ($seed === 0) {
            return ['label' => '', 'url' => '/dc/add-user.php']; // empty label -> skipped
        }
        if ($seed === 1) {
            return ['label' => 'Add a member', 'url' => '']; // empty url -> skipped
        }

        $action = [
            'label'    => $this->randomLabel($seed),
            'url'      => $this->randomUrl(),
            'style'    => ['primary', 'secondary', 'weird', ''][array_rand(['primary', 'secondary', 'weird', ''])],
            'external' => random_int(0, 1) === 1,
        ];

        return $action;
    }

    /**
     * A descriptive, human-readable label — deliberately not a URL. Includes
     * HTML-significant characters to exercise escaping, and a random suffix so
     * labels vary across iterations.
     */
    private function randomLabel(int $seed): string
    {
        $words = [
            'Open the form',
            'Add a member',
            'View this event',
            'Go to Outlook',
            'Manage <b>event</b>',
            'Email & 365',
            'Approve "now"',
            'Download the risk assessment',
        ];

        return $words[$seed % count($words)] . ' ' . bin2hex(random_bytes(2));
    }

    /**
     * A url distinct from any label: internal app paths and external URLs.
     */
    private function randomUrl(): string
    {
        $internal = [
            '/dc/add-user.php',
            '/dc/add-event.php',
            '/profile.php',
            '/help-centre.php?slug=adding-an-event',
        ];
        $external = [
            'https://outlook.cloud.microsoft/',
            'https://example.com/path?q=1&x=2',
            'http://insecure.example.org/',
        ];

        return random_int(0, 1) === 1
            ? $internal[array_rand($internal)]
            : $external[array_rand($external)];
    }
}
