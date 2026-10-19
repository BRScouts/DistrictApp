<?php

declare(strict_types=1);

namespace DistrictApp\Tests\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/app/help-helpers.php';

/**
 * Property test for the Article_Viewer steps-block renderer.
 *
 * Feature: help-centre, Property 5: Steps render as an ordered list
 *
 * Property statement (design.md, Property 5): For any steps block with N items,
 * the Article_Viewer SHALL render exactly one ordered-list element containing N
 * list items in item order.
 *
 * Validates: Requirements 3.3, 6.4
 *
 * The functions under test are the pure renderers in app/help-helpers.php:
 *
 *   - help_render_steps(array $block): string — emits one <ol> with one escaped
 *     <li> per item, in item order.
 *   - help_render_body(array $blocks): string — dispatches a `steps` block to
 *     help_render_steps(); exercised here so the property holds through the
 *     full render path as well as the block helper in isolation.
 *
 * Both are pure functions over block arrays (no database or I/O), so the
 * property is checked directly in-memory across 100+ randomized steps blocks
 * with N varying — including N = 0, N = 1 and large N.
 *
 * Strategy per iteration:
 *   - Generate N items, each tagged with a globally unique marker so its
 *     position in the output can be located unambiguously by substring offset.
 *   - Some items carry HTML metacharacters so escaping inside the <li> is also
 *     asserted (the ordered-list contract must not depend on raw markup).
 *   - Render via help_render_steps() and, independently, via help_render_body()
 *     wrapping the same block, and assert both:
 *       * exactly one <ol> and exactly one </ol>,
 *       * exactly N <li> and exactly N </li>,
 *       * each item's unique marker appears exactly once, in strictly
 *         increasing offset order (i.e. item order is preserved),
 *       * metacharacters are escaped (no raw "<script" / "<b>" leaks).
 */
#[Group('help-centre')]
#[Group('property')]
final class HelpRenderStepsPropertyTest extends TestCase
{
    /** Property tests run a minimum of 100 iterations; we use 150 randomized blocks. */
    private const ITERATIONS = 150;

    #[Test]
    #[TestDox('Property 5: a steps block of N items renders as exactly one ordered list of N items, in order (100+ randomized blocks)')]
    public function stepsRenderAsOneOrderedListInItemOrder(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $n = $this->itemCountForIteration($i);

            // Build N items, each prefixed with a unique marker so position is
            // unambiguous, and some seeded with HTML metacharacters.
            $items = [];
            $markers = [];
            for ($k = 0; $k < $n; $k++) {
                $marker = sprintf('MARK_%03d_%02d_%s', $i, $k, bin2hex(random_bytes(3)));
                $markers[] = $marker;
                $items[] = $marker . ' ' . $this->randomItemBody();
            }

            $block = ['type' => 'steps', 'items' => $items];

            // Render both directly and through the full body dispatcher.
            $direct = help_render_steps($block);
            $viaBody = help_render_body([$block]);

            foreach (['help_render_steps' => $direct, 'help_render_body' => $viaBody] as $via => $html) {
                $ctx = "Iteration {$i} (N={$n}) via {$via}";

                // Exactly one <ol>...</ol> element.
                self::assertSame(
                    1,
                    substr_count($html, '<ol>'),
                    "{$ctx}: output must contain exactly one <ol> opening tag"
                );
                self::assertSame(
                    1,
                    substr_count($html, '</ol>'),
                    "{$ctx}: output must contain exactly one </ol> closing tag"
                );

                // Exactly N <li> and N </li>.
                self::assertSame(
                    $n,
                    substr_count($html, '<li>'),
                    "{$ctx}: output must contain exactly {$n} <li> opening tags"
                );
                self::assertSame(
                    $n,
                    substr_count($html, '</li>'),
                    "{$ctx}: output must contain exactly {$n} </li> closing tags"
                );

                // Each marker appears exactly once, and markers appear in item
                // order (strictly increasing offsets).
                $lastOffset = -1;
                foreach ($markers as $index => $marker) {
                    self::assertSame(
                        1,
                        substr_count($html, $marker),
                        "{$ctx}: item marker '{$marker}' (position {$index}) must appear exactly once"
                    );

                    $offset = strpos($html, $marker);
                    self::assertNotFalse(
                        $offset,
                        "{$ctx}: item marker '{$marker}' (position {$index}) must be present"
                    );
                    self::assertGreaterThan(
                        $lastOffset,
                        $offset,
                        "{$ctx}: item '{$marker}' (position {$index}) must appear after the previous item (order preserved)"
                    );
                    $lastOffset = $offset;
                }

                // Metacharacters must be escaped inside the list: no raw markup
                // should survive from the stored item bodies.
                self::assertStringNotContainsString(
                    '<script',
                    $html,
                    "{$ctx}: stored item markup must be escaped (no raw <script>)"
                );
                self::assertStringNotContainsString(
                    '<b>',
                    $html,
                    "{$ctx}: stored item markup must be escaped (no raw <b>)"
                );
            }
        }
    }

    /**
     * The N (item count) for a given iteration. Boundary sizes are forced early
     * — N = 0 (empty), N = 1 (single), and a large N — then the remainder vary
     * randomly across a wide range.
     */
    private function itemCountForIteration(int $iteration): int
    {
        return match ($iteration) {
            0 => 0,              // empty steps block -> one <ol>, zero <li>
            1 => 1,              // single step
            2 => 50,             // large N
            3 => 100,            // larger N
            default => random_int(0, 30),
        };
    }

    /**
     * A randomized item body. Roughly half the time it embeds HTML
     * metacharacters so the escaping branch is exercised alongside ordering.
     */
    private function randomItemBody(): string
    {
        $plain = [
            'Open the form',
            'Enter the details',
            'Save your changes',
            'Review the summary',
            'Submit for approval',
            'Click the button labelled Continue',
        ];

        if (random_int(0, 1) === 1) {
            $danger = [
                'Use <script>alert(1)</script> here',
                'A <b>bold</b> claim & more',
                'Quote "this" and \'that\'',
                'Tag <li> and </ol> as literal text',
                'Ampersand & angle < > brackets',
            ];

            return $danger[array_rand($danger)];
        }

        return $plain[array_rand($plain)];
    }
}
