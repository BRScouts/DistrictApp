<?php

declare(strict_types=1);

namespace DistrictApp\Tests\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/app/help-helpers.php';

/**
 * Property test for safe external-action rendering.
 *
 * Feature: help-centre, Property 7: External actions open safely in a new context
 *
 * Property statement (design.md, Property 7): For any Action_Block action, the
 * rendered link SHALL include `target="_blank"`, `rel="noopener noreferrer"`,
 * and an assistive-technology-conveyed new-tab indication IF AND ONLY IF the
 * action is marked external.
 *
 * Validates: Requirements 3.5, 6.6
 *
 * The functions under test are help_render_action_link() and
 * help_render_actions() in app/help-helpers.php. Both are pure functions over
 * a block/action array, so this test exercises them directly with no database.
 *
 * When an action is external (truthy `external`), help_render_action_link()
 * emits:
 *   - target="_blank"
 *   - rel="noopener noreferrer"
 *   - a visible, aria-hidden icon (aria-hidden="true")
 *   - visually-hidden text " (opens in a new tab)"
 * A non-external (falsy / absent `external`) action omits all of the above.
 * In both cases the result is a native <a ... href=...> whose visible text is
 * the escaped label.
 *
 * The test drives the biconditional across 150 randomized actions, varying the
 * label, url (internal and external URL forms), style, and the `external` value
 * itself — including truthy non-bool values (1, "1"), falsy values (0, "", "0",
 * null, false) and a missing key.
 */
#[Group('help-centre')]
#[Group('property')]
final class HelpRenderExternalActionPropertyTest extends TestCase
{
    /** Property tests run a minimum of 100 iterations; we use 150. */
    private const ITERATIONS = 150;

    private const EXT_TARGET = 'target="_blank"';
    private const EXT_REL    = 'rel="noopener noreferrer"';
    private const EXT_ARIA   = 'aria-hidden="true"';
    private const EXT_TEXT   = '(opens in a new tab)';

    #[Test]
    #[TestDox('Property 7: external links (and only external links) open safely in a new tab (100+ randomized actions)')]
    public function externalActionsOpenSafelyInANewContext(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            [$action, $isExternal] = $this->randomAction($i);

            $html = help_render_action_link($action);

            // Skipped actions (missing label or url) render as '' and are not
            // part of the biconditional — assert they are empty and move on.
            $label = (string) ($action['label'] ?? '');
            $url   = (string) ($action['url'] ?? '');
            if ($label === '' || $url === '') {
                self::assertSame(
                    '',
                    $html,
                    "Iteration {$i}: an action missing a label or url must render as empty"
                );
                continue;
            }

            // In every rendered case the link is a native <a> with an href and
            // the escaped label as its visible text.
            $expectedHref = 'href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"';
            self::assertStringStartsWith('<a ', $html, "Iteration {$i}: link must be a native <a> element");
            self::assertStringContainsString($expectedHref, $html, "Iteration {$i}: link must carry the escaped href");
            self::assertStringContainsString(
                htmlspecialchars($label, ENT_QUOTES, 'UTF-8'),
                $html,
                "Iteration {$i}: link must show the escaped label as visible text"
            );

            if ($isExternal) {
                self::assertStringContainsString(self::EXT_TARGET, $html, "Iteration {$i}: external link must set target=_blank");
                self::assertStringContainsString(self::EXT_REL, $html, "Iteration {$i}: external link must set rel=noopener noreferrer");
                self::assertStringContainsString(self::EXT_ARIA, $html, "Iteration {$i}: external link must carry an aria-hidden icon");
                self::assertStringContainsString(self::EXT_TEXT, $html, "Iteration {$i}: external link must carry visually-hidden new-tab text");
            } else {
                self::assertStringNotContainsString(self::EXT_TARGET, $html, "Iteration {$i}: internal link must NOT set target=_blank");
                self::assertStringNotContainsString(self::EXT_REL, $html, "Iteration {$i}: internal link must NOT set rel=noopener noreferrer");
                self::assertStringNotContainsString(self::EXT_TEXT, $html, "Iteration {$i}: internal link must NOT carry new-tab text");
            }
        }
    }

    #[Test]
    #[TestDox('Property 7: the biconditional holds through help_render_actions() for mixed action blocks')]
    public function biconditionalHoldsThroughActionsBlock(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $actions = [];
            $meta = [];
            $count = random_int(1, 5);
            for ($n = 0; $n < $count; $n++) {
                [$action, $isExternal] = $this->randomAction($i * 10 + $n);
                $actions[] = $action;
                $label = (string) ($action['label'] ?? '');
                $url   = (string) ($action['url'] ?? '');
                $meta[] = [
                    'external' => $isExternal,
                    'skipped'  => ($label === '' || $url === ''),
                ];
            }

            $html = help_render_actions(['actions' => $actions]);

            $externalRendered = 0;
            foreach ($meta as $m) {
                if (!$m['skipped'] && $m['external']) {
                    $externalRendered++;
                }
            }

            // The count of new-tab affordances in the combined output must equal
            // exactly the number of rendered external actions — no more, no less.
            self::assertSame(
                $externalRendered,
                substr_count($html, self::EXT_TEXT),
                "Iteration {$i}: new-tab text count must equal the number of external actions"
            );
            self::assertSame(
                $externalRendered,
                substr_count($html, self::EXT_TARGET),
                "Iteration {$i}: target=_blank count must equal the number of external actions"
            );
            self::assertSame(
                $externalRendered,
                substr_count($html, self::EXT_REL),
                "Iteration {$i}: rel=noopener count must equal the number of external actions"
            );
        }
    }

    /**
     * Build a randomized action plus the ground-truth expectation of whether it
     * is external, i.e. PHP truthiness of the chosen `external` value under
     * help_render_action_link()'s `!empty($a['external'])` test.
     *
     * The first handful of iterations deterministically exercise the tricky
     * truthy/falsy variants and the missing-key case.
     *
     * @return array{0: array<string, mixed>, 1: bool}
     */
    private function randomAction(int $seed): array
    {
        // A menu of `external` values with their expected !empty() truthiness.
        // Using a sentinel to represent "key absent" distinctly from null.
        $missing = new \stdClass();
        $externalChoices = [
            [true,  true],
            [1,     true],
            ['1',   true],
            ['yes', true],   // non-empty string is truthy
            [false, false],
            [0,     false],
            ['0',   false],  // "0" is falsy in PHP
            ['',    false],
            [null,  false],
            [$missing, false], // key omitted entirely
        ];

        $choice = match ($seed) {
            0 => $externalChoices[0], // true
            1 => $externalChoices[1], // 1
            2 => $externalChoices[2], // "1"
            3 => $externalChoices[6], // "0" -> falsy
            4 => $externalChoices[7], // "" -> falsy
            5 => $externalChoices[8], // null -> falsy
            6 => $externalChoices[9], // missing key -> falsy
            default => $externalChoices[array_rand($externalChoices)],
        };

        [$externalValue, $isExternal] = $choice;

        $action = [
            'label' => $this->randomLabel($seed),
            'url'   => $this->randomUrl(),
            'style' => ['primary', 'secondary', 'weird', ''][array_rand(['primary', 'secondary', 'weird', ''])],
        ];

        // Occasionally omit label or url to exercise the skipped-action branch.
        if ($seed % 23 === 0 && $seed > 6) {
            unset($action['label']);
        }
        if ($seed % 29 === 0 && $seed > 6) {
            $action['url'] = '';
        }

        if (!($externalValue instanceof \stdClass)) {
            $action['external'] = $externalValue;
        }

        return [$action, $isExternal];
    }

    private function randomLabel(int $seed): string
    {
        $words = ['Open', 'Add a member', 'View event', 'Go to Outlook', 'Manage <b>event</b>', 'Email & 365', 'Approve "now"'];

        return $words[$seed % count($words)] . ' ' . bin2hex(random_bytes(2));
    }

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
