<?php

declare(strict_types=1);

namespace DistrictApp\Tests\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/app/help-helpers.php';

/**
 * Property test for Rich_Body renderer output escaping.
 *
 * Feature: help-centre, Property 1: Stored content is always escaped
 *
 * Property statement (design.md, Property 1): For any Help_Article field value
 * or Rich_Body text/label/step containing HTML metacharacters, the rendered
 * output SHALL contain those characters in escaped form and SHALL NOT contain
 * the unescaped markup.
 *
 * Validates: Requirements 3.6
 *
 * The functions under test are the pure renderer helpers in
 * app/help-helpers.php — help_render_body() and the per-block helpers
 * help_render_paragraph(), help_render_heading(), help_render_steps(),
 * help_render_actions() and help_render_action_link(). They are pure functions
 * over block arrays (no database access), so they can be called directly after
 * require_once of the helpers file.
 *
 * Each iteration generates a randomized Rich_Body whose text/label/url/step
 * values embed HTML metacharacters (<, >, &, ", ') and well-known XSS payloads
 * (e.g. <script>alert(1)</script>, "><img src=x onerror=y>), renders it, and
 * asserts of the rendered output:
 *   - it does NOT contain the raw dangerous payload (no literal <script>, no
 *     unescaped onerror= attribute injection, no raw " breaking out of href),
 *   - it DOES contain the escaped form (&lt; &gt; &amp; &quot; &#039;) for the
 *     metacharacters that were present in each stored field.
 *
 * Every block type is covered: paragraph text, heading text, steps items,
 * action label and action url.
 */
#[Group('help-centre')]
#[Group('property')]
final class HelpRenderEscapingPropertyTest extends TestCase
{
    /** Property tests run a minimum of 100 iterations. */
    private const ITERATIONS = 150;

    /**
     * Dangerous raw payloads that embed HTML metacharacters. If any of these
     * appears verbatim in the rendered output, escaping has failed.
     *
     * @var list<string>
     */
    private const PAYLOADS = [
        '<script>alert(1)</script>',
        '"><img src=x onerror=alert(1)>',
        "'><svg onload=alert(2)>",
        '<b>bold</b> & <i>italic</i>',
        'a & b < c > d',
        'quote " and apostrophe \'',
        '</p><script>steal()</script>',
        '&amp; already & entity',
        'href="javascript:alert(1)"',
        '<iframe src=//evil></iframe>',
    ];

    #[Test]
    #[TestDox('Property 1: stored paragraph/heading/step/action values are always escaped in rendered output (100+ randomized bodies)')]
    public function storedContentIsAlwaysEscaped(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            // Collect, per generated block, the raw stored field values we
            // injected so we can assert each one's escaping independently.
            $storedValues = [];
            $blocks = $this->randomBody($i, $storedValues);

            $html = help_render_body($blocks);

            foreach ($storedValues as $field) {
                $raw = $field['raw'];

                // 1) The raw stored value must never appear verbatim when it
                //    carries HTML markup. (A value with no metacharacters may
                //    legitimately appear unchanged, so only assert absence when
                //    the raw value actually contains markup characters.)
                if ($this->containsMarkup($raw)) {
                    self::assertStringNotContainsString(
                        $raw,
                        $html,
                        sprintf(
                            "Iteration %d, %s: raw stored value %s must not appear unescaped in output:\n%s",
                            $i,
                            $field['where'],
                            var_export($raw, true),
                            $html
                        )
                    );
                }

                // 2) The correctly escaped form of the stored value must be
                //    present in the output.
                $escaped = htmlspecialchars($raw, ENT_QUOTES, 'UTF-8');
                self::assertStringContainsString(
                    $escaped,
                    $html,
                    sprintf(
                        "Iteration %d, %s: escaped stored value %s must appear in output:\n%s",
                        $i,
                        $field['where'],
                        var_export($escaped, true),
                        $html
                    )
                );

                // 3) Each metacharacter present in the raw value must appear in
                //    its escaped entity form somewhere in the output.
                foreach ($this->expectedEntities($raw) as $meta => $entity) {
                    self::assertStringContainsString(
                        $entity,
                        $html,
                        sprintf(
                            "Iteration %d, %s: metacharacter %s in %s must be escaped to %s:\n%s",
                            $i,
                            $field['where'],
                            var_export($meta, true),
                            var_export($raw, true),
                            $entity,
                            $html
                        )
                    );
                }
            }

            // Global guard: no classic injection signatures survive rendering.
            self::assertStringNotContainsString(
                '<script>',
                $html,
                "Iteration {$i}: an unescaped <script> tag leaked into the output:\n{$html}"
            );
            self::assertStringNotContainsString(
                '<img src=x onerror=',
                $html,
                "Iteration {$i}: an unescaped onerror attribute injection leaked into the output:\n{$html}"
            );
            self::assertStringNotContainsString(
                '<iframe',
                $html,
                "Iteration {$i}: an unescaped <iframe> tag leaked into the output:\n{$html}"
            );
        }
    }

    #[Test]
    #[TestDox('Example: an href-breakout payload in an action url is neutralised and cannot escape the attribute')]
    public function actionUrlBreakoutIsNeutralised(): void
    {
        $payload = '"><img src=x onerror=alert(1)>';
        $blocks = [
            [
                'type'    => 'action',
                'actions' => [
                    ['label' => 'Open the thing', 'url' => $payload, 'style' => 'primary'],
                ],
            ],
        ];

        $html = help_render_body($blocks);

        // The raw breakout payload must not appear, and the double quote that
        // would close the href must be escaped to &quot;.
        self::assertStringNotContainsString($payload, $html);
        self::assertStringNotContainsString('<img src=x onerror=', $html);
        self::assertStringContainsString('&quot;', $html);
        self::assertStringContainsString('&lt;img', $html);
    }

    /**
     * Build a randomized Rich_Body covering every block type, injecting a mix
     * of metacharacter payloads into the escapable stored fields. Each injected
     * raw value is recorded in $storedValues (by reference) with a human label.
     *
     * @param array<int, array{where: string, raw: string}> $storedValues
     * @return array<int, array<string, mixed>>
     */
    private function randomBody(int $iteration, array &$storedValues): array
    {
        $blocks = [];

        // Paragraph with a dangerous text value.
        $pText = $this->pick($iteration, 0);
        $blocks[] = ['type' => 'paragraph', 'text' => $pText];
        $storedValues[] = ['where' => 'paragraph.text', 'raw' => $pText];

        // Heading with a dangerous text value (level randomized but clamped by
        // the renderer; escaping is what we assert here).
        $hText = $this->pick($iteration, 1);
        $blocks[] = ['type' => 'heading', 'level' => random_int(1, 7), 'text' => $hText];
        $storedValues[] = ['where' => 'heading.text', 'raw' => $hText];

        // Steps with 1..4 dangerous items.
        $itemCount = random_int(1, 4);
        $items = [];
        for ($n = 0; $n < $itemCount; $n++) {
            $item = $this->pick($iteration, 2 + $n);
            $items[] = $item;
            $storedValues[] = ['where' => "steps.items[$n]", 'raw' => $item];
        }
        $blocks[] = ['type' => 'steps', 'items' => $items];

        // Action(s) with dangerous label and url. Label and url are both
        // non-empty so the action is rendered (empty label/url is skipped by
        // the renderer and is covered by other tasks).
        $actionCount = random_int(1, 3);
        $actions = [];
        for ($n = 0; $n < $actionCount; $n++) {
            $label = 'Label ' . $this->pick($iteration, 10 + $n * 2);
            $url   = 'https://example.test/' . $this->pick($iteration, 11 + $n * 2);
            $actions[] = [
                'label'    => $label,
                'url'      => $url,
                'style'    => random_int(0, 1) === 1 ? 'primary' : 'secondary',
                'external' => random_int(0, 1) === 1,
            ];
            $storedValues[] = ['where' => "action[$n].label", 'raw' => $label];
            $storedValues[] = ['where' => "action[$n].url", 'raw' => $url];
        }
        $blocks[] = ['type' => 'action', 'actions' => $actions];

        // Occasionally shuffle block order to vary the input space; escaping
        // must hold regardless of position.
        if ($iteration % 3 === 0) {
            shuffle($blocks);
        }

        return $blocks;
    }

    /**
     * Pick a payload deterministically-ish per (iteration, slot), mixing the
     * fixed dangerous payloads with randomized metacharacter noise so the input
     * space is broad but every slot carries markup.
     */
    private function pick(int $iteration, int $slot): string
    {
        $base = self::PAYLOADS[($iteration + $slot) % count(self::PAYLOADS)];

        // Append a little randomized metacharacter noise to widen coverage.
        $noiseChars = ['<', '>', '&', '"', "'", 'x', 'Z', '9', ' '];
        $noise = '';
        $len = random_int(0, 6);
        for ($k = 0; $k < $len; $k++) {
            $noise .= $noiseChars[array_rand($noiseChars)];
        }

        return $base . $noise;
    }

    /** Does the value contain any HTML metacharacter that htmlspecialchars would change? */
    private function containsMarkup(string $value): bool
    {
        return strpbrk($value, '<>&"\'') !== false;
    }

    /**
     * Map each HTML metacharacter present in $value to its expected ENT_QUOTES
     * entity, so we can assert each appears in the output.
     *
     * @return array<string, string>
     */
    private function expectedEntities(string $value): array
    {
        $map = [
            '<'  => '&lt;',
            '>'  => '&gt;',
            '&'  => '&amp;',
            '"'  => '&quot;',
            "'"  => '&#039;',
        ];

        $present = [];
        foreach ($map as $meta => $entity) {
            if (str_contains($value, $meta)) {
                $present[$meta] = $entity;
            }
        }

        return $present;
    }
}
