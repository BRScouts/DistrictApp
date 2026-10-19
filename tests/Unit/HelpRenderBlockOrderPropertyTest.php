<?php

declare(strict_types=1);

namespace DistrictApp\Tests\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/app/help-helpers.php';

/**
 * Property test for Rich_Body block render order.
 *
 * Feature: help-centre, Property 4: Blocks render in stored order
 *
 * Property statement (design.md, Property 4): For any Rich_Body block array,
 * the Article_Viewer SHALL render the blocks in the same order they appear in
 * storage.
 *
 * Validates: Requirements 3.2
 *
 * The function under test is the pure renderer help_render_body(array $blocks):
 * string in app/help-helpers.php. It iterates the block array in order,
 * dispatches each block on its `type` (paragraph/heading/steps/action) and
 * defensively skips non-array/typeless blocks and unknown types.
 *
 * Strategy: generate 100+ randomized block arrays mixing the four valid block
 * types plus occasional unknown/malformed blocks (which the renderer must
 * skip). Each valid block is stamped with a globally-unique, escape-safe marker
 * embedded in its rendered output (unique paragraph/heading text, step text, or
 * action label). After rendering, we locate each valid block's marker with
 * strpos and assert the byte offsets are strictly increasing in exactly the
 * input order — i.e. blocks render in stored order — and that interleaved
 * skipped blocks never perturb that ordering.
 */
#[Group('help-centre')]
#[Group('property')]
final class HelpRenderBlockOrderPropertyTest extends TestCase
{
    /** Property tests run a minimum of 100 iterations; we use 150 randomized arrays. */
    private const ITERATIONS = 150;

    #[Test]
    #[TestDox('Property 4: blocks render in their stored order; skipped blocks do not disturb it (100+ randomized arrays)')]
    public function blocksRenderInStoredOrder(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            // Build a randomized block array. Each valid block carries a unique
            // marker; we record the markers of valid blocks in input order.
            [$blocks, $expectedMarkers] = $this->randomBlocks($i);

            $html = help_render_body($blocks);

            // Every valid block's marker must appear exactly once in the output.
            foreach ($expectedMarkers as $marker) {
                self::assertSame(
                    1,
                    substr_count($html, $marker),
                    "Iteration {$i}: marker '{$marker}' must appear exactly once in the rendered output"
                );
            }

            // The byte offsets of the markers must be strictly increasing in the
            // same sequence the valid blocks appear in the input array.
            $prevOffset = -1;
            $prevMarker = null;
            foreach ($expectedMarkers as $marker) {
                $offset = strpos($html, $marker);

                self::assertNotFalse(
                    $offset,
                    "Iteration {$i}: marker '{$marker}' for a stored block is missing from the output"
                );

                self::assertGreaterThan(
                    $prevOffset,
                    $offset,
                    "Iteration {$i}: block '{$marker}' rendered before '{$prevMarker}' but appears later in storage; "
                    . 'blocks must render in stored order'
                );

                $prevOffset = $offset;
                $prevMarker = $marker;
            }
        }
    }

    #[Test]
    #[TestDox('Example: a fixed mixed array (with interleaved malformed blocks) renders valid blocks in stored order')]
    public function fixedMixedArrayRendersInOrder(): void
    {
        $blocks = [
            ['type' => 'paragraph', 'text' => 'MARKER_A_intro'],
            ['not-an-array'],                               // skipped: typeless/non-dispatch
            ['type' => 'heading', 'level' => 2, 'text' => 'MARKER_B_heading'],
            ['type' => 'mystery', 'text' => 'ignored'],     // skipped: unknown type
            ['type' => 'steps', 'items' => ['MARKER_C_step1', 'MARKER_D_step2']],
            'a-bare-string',                                // skipped: non-array block
            ['type' => 'action', 'actions' => [
                ['label' => 'MARKER_E_action', 'url' => 'https://example.test/'],
            ]],
            ['type' => 'paragraph', 'text' => 'MARKER_F_outro'],
        ];

        $html = help_render_body($blocks);

        $order = [
            'MARKER_A_intro',
            'MARKER_B_heading',
            'MARKER_C_step1',
            'MARKER_D_step2',
            'MARKER_E_action',
            'MARKER_F_outro',
        ];

        $prev = -1;
        foreach ($order as $marker) {
            $offset = strpos($html, $marker);
            self::assertNotFalse($offset, "Marker '{$marker}' must be present");
            self::assertGreaterThan($prev, $offset, "Marker '{$marker}' is out of stored order");
            $prev = $offset;
        }
    }

    /**
     * Generate a randomized block array for one iteration.
     *
     * Produces a mix of the four valid block types, with occasional
     * unknown/malformed blocks interleaved that the renderer must skip. Each
     * valid block is stamped with a unique, HTML-safe marker so its fragment is
     * individually locatable in the output; the markers are returned in the
     * same order the valid blocks appear in the input array.
     *
     * Boundary iterations are forced: a single-block array and a skip-heavy
     * array (every other block malformed) are both covered within the run.
     *
     * @return array{0: list<mixed>, 1: list<string>} the block array and the
     *               ordered list of expected markers for its valid blocks
     */
    private function randomBlocks(int $iteration): array
    {
        $blocks = [];
        $markers = [];
        $seq = 0;

        $count = match ($iteration) {
            0 => 1,                 // single valid block
            1 => random_int(6, 10), // skip-heavy (forced below)
            default => random_int(1, 12),
        };

        for ($n = 0; $n < $count; $n++) {
            // On the skip-heavy iteration, drop a malformed block before every
            // other position so skips are densely interleaved.
            $forceSkipBefore = ($iteration === 1 && $n % 2 === 1);
            if ($forceSkipBefore) {
                $blocks[] = $this->malformedBlock($iteration, $seq++);
            } elseif ($iteration > 1 && random_int(0, 2) === 0) {
                // Otherwise, randomly interleave a skipped block (~1/3 chance).
                $blocks[] = $this->malformedBlock($iteration, $seq++);
            }

            // A unique, alphanumeric-only marker is unaffected by HTML escaping,
            // so it survives intact into the rendered fragment.
            $marker = sprintf('MK%03d%03d%s', $iteration, $n, bin2hex(random_bytes(3)));

            $blocks[] = $this->validBlock($marker, $seq++);
            $markers[] = $marker;
        }

        return [$blocks, $markers];
    }

    /**
     * Build one valid block whose rendered output embeds the given unique
     * marker. Rotates across the four valid block types.
     *
     * @return array<string, mixed>
     */
    private function validBlock(string $marker, int $seq): array
    {
        return match ($seq % 4) {
            0 => [
                'type' => 'paragraph',
                'text' => 'Para ' . $marker,
            ],
            1 => [
                'type'  => 'heading',
                'level' => random_int(2, 6),
                'text'  => 'Heading ' . $marker,
            ],
            2 => [
                'type'  => 'steps',
                // Marker lives in a step item so it appears inside the <ol>.
                'items' => ['First', $marker, 'Last'],
            ],
            default => [
                'type'    => 'action',
                'actions' => [
                    // Marker is the action's visible label.
                    ['label' => $marker, 'url' => 'https://example.test/' . $seq],
                ],
            ],
        };
    }

    /**
     * Build a block the renderer must skip: a non-array block, a typeless
     * array, or an array with an unknown type. None of these must emit output
     * that could carry a marker or disturb ordering.
     */
    private function malformedBlock(int $iteration, int $seq): mixed
    {
        return match ($seq % 3) {
            0 => 'bare-string-block-' . $iteration . '-' . $seq, // non-array
            1 => ['no' => 'type', 'text' => 'typeless-' . $seq],  // array without `type`
            default => ['type' => 'unknown-' . $seq, 'text' => 'ignored-' . $seq], // unknown type
        };
    }
}
