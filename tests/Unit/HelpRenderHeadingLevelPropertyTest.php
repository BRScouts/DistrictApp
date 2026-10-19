<?php

declare(strict_types=1);

namespace DistrictApp\Tests\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/app/help-helpers.php';

/**
 * Property test for the Rich_Body renderer's heading-level discipline.
 *
 * Feature: help-centre, Property 6: Heading levels never skip and stay below the title
 *
 * Property statement (design.md, Property 6): For any Rich_Body containing
 * heading blocks, every rendered heading SHALL be at a level greater than the
 * article title's level (the title is the only <h1>) and SHALL NOT skip a
 * heading level relative to the previously rendered heading.
 *
 * Validates: Requirements 6.1, 6.2
 *
 * The functions under test are help_render_body() and help_render_heading() in
 * app/help-helpers.php. Both are pure functions over the stored block array, so
 * this test exercises them directly with no database involved: it generates
 * randomized Rich_Body arrays whose heading blocks request arbitrary (and
 * frequently out-of-range or missing) levels, interleaved with other block
 * types, renders them, and asserts the SEQUENCE of rendered heading levels
 * satisfies the property.
 */
#[Group('help-centre')]
#[Group('property')]
final class HelpRenderHeadingLevelPropertyTest extends TestCase
{
    /** Property tests run a minimum of 100 iterations; we use 200 randomized bodies. */
    private const ITERATIONS = 200;

    #[Test]
    #[TestDox('Property 6: rendered heading levels stay in 2..6, start at 2, and never skip upward by more than one (100+ randomized bodies)')]
    public function headingLevelsNeverSkipAndStayBelowTheTitle(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $blocks = $this->randomRichBody($i);

            $html = help_render_body($blocks);

            // There is never an <h1> in the rendered body: the article title is
            // the only <h1>, so every body heading must sit strictly below it.
            self::assertDoesNotMatchRegularExpression(
                '/<h1[\s>]/i',
                $html,
                "Iteration {$i}: the rendered body must never contain an <h1> (reserved for the article title)"
            );
            self::assertStringNotContainsString(
                '<h1>',
                $html,
                "Iteration {$i}: the rendered body must never contain an <h1>"
            );

            // Parse rendered heading levels in output order.
            preg_match_all('/<h([2-6])>/', $html, $matches);
            $levels = array_map('intval', $matches[1]);

            // Count how many heading blocks we actually fed in, so we can be sure
            // every requested heading produced exactly one rendered heading (the
            // clamp must never drop or duplicate a heading).
            $headingBlockCount = 0;
            foreach ($blocks as $block) {
                if (is_array($block) && ($block['type'] ?? null) === 'heading') {
                    $headingBlockCount++;
                }
            }
            self::assertCount(
                $headingBlockCount,
                $levels,
                "Iteration {$i}: every heading block must render exactly one <h2>..<h6>"
            );

            if ($levels === []) {
                // Bodies with no heading blocks trivially satisfy the property.
                continue;
            }

            // The first rendered heading always starts at level 2 (one below the
            // <h1> title), regardless of the requested level.
            self::assertSame(
                2,
                $levels[0],
                "Iteration {$i}: the first rendered heading must be level 2"
            );

            $prev = $levels[0];
            foreach ($levels as $index => $level) {
                // Every rendered heading is in the 2..6 band: never an <h1>, and
                // never beyond <h6>.
                self::assertGreaterThanOrEqual(
                    2,
                    $level,
                    "Iteration {$i}, heading #{$index}: level must be >= 2 (below the title)"
                );
                self::assertLessThanOrEqual(
                    6,
                    $level,
                    "Iteration {$i}, heading #{$index}: level must be <= 6"
                );

                if ($index > 0) {
                    // Never skip upward by more than one relative to the previous
                    // rendered heading. Decreases are allowed freely.
                    self::assertLessThanOrEqual(
                        $prev + 1,
                        $level,
                        "Iteration {$i}, heading #{$index}: level {$level} skips upward from previous {$prev}"
                    );
                }

                $prev = $level;
            }
        }
    }

    /**
     * Build a randomized Rich_Body for one iteration.
     *
     * Heading blocks request arbitrary levels drawn from a pool that deliberately
     * includes out-of-range values (0, 1, 7, large, negative) and, sometimes, a
     * missing `level` key entirely. Headings are interleaved with other block
     * types (paragraph, steps, action, plus junk/unknown blocks) so the renderer
     * must track heading state across non-heading blocks.
     *
     * The first couple of iterations are seeded with boundary shapes: a body with
     * a single heading, and a heading-free body.
     *
     * @return array<int, mixed>
     */
    private function randomRichBody(int $iteration): array
    {
        if ($iteration === 0) {
            // A single heading requesting an out-of-range high level.
            return [['type' => 'heading', 'level' => 7, 'text' => 'Only heading']];
        }
        if ($iteration === 1) {
            // No heading blocks at all.
            return [
                ['type' => 'paragraph', 'text' => 'No headings here'],
                ['type' => 'steps', 'items' => ['one', 'two']],
            ];
        }

        $blockCount = random_int(0, 12);
        $blocks = [];

        for ($n = 0; $n < $blockCount; $n++) {
            $blocks[] = $this->randomBlock();
        }

        return $blocks;
    }

    /**
     * Produce one random block, weighted towards headings so most bodies carry a
     * meaningful heading sequence.
     *
     * @return mixed
     */
    private function randomBlock()
    {
        $roll = random_int(1, 10);

        // ~50% headings, the rest a mix of other / malformed blocks.
        if ($roll <= 5) {
            return $this->randomHeadingBlock();
        }

        return match ($roll) {
            6       => ['type' => 'paragraph', 'text' => 'Para ' . bin2hex(random_bytes(2))],
            7       => ['type' => 'steps', 'items' => ['step a', 'step b']],
            8       => ['type' => 'action', 'actions' => [['label' => 'Go', 'url' => '/x']]],
            9       => ['type' => 'unknown-' . bin2hex(random_bytes(1))], // skipped by renderer
            default => 'not-an-array-block', // defensively skipped by renderer
        };
    }

    /**
     * A heading block whose requested level spans in-range and out-of-range
     * values, sometimes omitting `level` entirely (the renderer defaults it).
     *
     * @return array<string, mixed>
     */
    private function randomHeadingBlock(): array
    {
        // Pool of requested levels: valid band plus deliberately invalid values.
        $pool = [0, 1, 2, 3, 4, 5, 6, 7, 9, 42, -3, -1];
        $requested = $pool[array_rand($pool)];

        $block = ['type' => 'heading', 'text' => 'H ' . bin2hex(random_bytes(2))];

        // ~1 in 5 headings omit the level key entirely.
        if (random_int(1, 5) !== 1) {
            $block['level'] = $requested;
        }

        return $block;
    }
}
