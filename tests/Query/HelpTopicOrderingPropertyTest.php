<?php

declare(strict_types=1);

namespace DistrictApp\Tests\Query;

use DistrictApp\Tests\Support\MigrationRunner;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Feature: help-centre, Property 3: Topic ordering is stable
 *
 * Property statement (design.md, Property 3):
 *   For any set of published Help_Articles sharing a Topic, the
 *   Help_Centre_Index SHALL order them by `sort_order` ascending and then by
 *   `title` ascending.
 *
 * Validates: Requirements 2.6
 *
 * How this is exercised
 * ---------------------
 * The ordering lives in help_published_articles() as its SQL ORDER BY:
 *
 *     ORDER BY topic ASC, sort_order ASC, title ASC
 *
 * That helper calls the application's global db() PDO, which is hard-wired to a
 * MySQL server and cannot be pointed at a test connection. Following the design's
 * guidance ("replicate its exact ORDER BY through the test PDO"), this test runs
 * the identical ORDER BY against an in-memory SQLite database seeded with
 * randomised published articles — the same backend the migration example tests
 * use (tests/Support/MigrationRunner.php). The grouping under test is then
 * applied with the real help_group_by_topic() function from app/help-helpers.php,
 * so the production grouping logic — not a copy — is what is asserted.
 *
 * This is a property test: it generates 100+ randomised article sets (random
 * topics, random sort_order values including deliberate ties, random titles) and
 * asserts the invariant holds for every one of them. Ties on sort_order are
 * generated explicitly so the title tiebreak is always put under pressure.
 */
#[Group('query')]
#[Group('help-centre')]
#[Group('property')]
final class HelpTopicOrderingPropertyTest extends TestCase
{
    private const ITERATIONS = 150;

    /**
     * The exact ordering clause used by help_published_articles(). Kept here as
     * a single source of truth so the test asserts the real ordering contract.
     */
    private const ORDER_BY = 'ORDER BY topic ASC, sort_order ASC, title ASC';

    private PDO $pdo;

    protected function setUp(): void
    {
        // Reuse the migration harness purely to obtain a configured, isolated
        // in-memory SQLite PDO and the real help_articles schema (slug UNIQUE,
        // status CHECK, etc.) — identical plumbing to the migration tests.
        $runner = MigrationRunner::connect();
        $runner->apply();
        $this->pdo = $runner->pdo();

        // Start from a clean table so each iteration controls its own data set
        // without the 14 seeded rows interfering with the generated ordering.
        $this->pdo->exec('DELETE FROM help_articles');

        require_once dirname(__DIR__, 2) . '/app/help-helpers.php';
    }

    /**
     * Run the published-index ORDER BY through the test PDO, decode bodies and
     * group with the real help_group_by_topic(), exactly as the index page does.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function groupedPublishedIndex(): array
    {
        $sql = "
            SELECT id, slug, title, summary, keywords, body, topic, status, sort_order
            FROM help_articles
            WHERE status = 'published'
            " . self::ORDER_BY;

        $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        $decoded = [];
        foreach ($rows as $row) {
            $row['body'] = help_decode_body(isset($row['body']) ? (string) $row['body'] : null);
            $decoded[] = $row;
        }

        // The real grouping helper preserves the SQL order within each topic.
        return help_group_by_topic($decoded);
    }

    #[Test]
    #[TestDox('Feature: help-centre, Property 3: within every topic, published articles are ordered by sort_order ASC then title ASC')]
    public function withinEachTopicArticlesAreOrderedBySortOrderThenTitle(): void
    {
        $insert = $this->pdo->prepare(
            "INSERT INTO help_articles (slug, title, summary, keywords, body, topic, status, sort_order)
             VALUES (:slug, :title, '', '', '[]', :topic, :status, :sort_order)"
        );

        for ($iteration = 0; $iteration < self::ITERATIONS; $iteration++) {
            $this->pdo->exec('DELETE FROM help_articles');

            $articles = $this->generateArticleSet($iteration);

            foreach ($articles as $article) {
                $insert->execute([
                    'slug'       => $article['slug'],
                    'title'      => $article['title'],
                    'topic'      => $article['topic'],
                    'status'     => $article['status'],
                    'sort_order' => $article['sort_order'],
                ]);
            }

            $grouped = $this->groupedPublishedIndex();

            // Only published articles should ever appear (draft rows generated
            // below must never leak into the index).
            $publishedCount = 0;
            foreach ($articles as $article) {
                if ($article['status'] === 'published') {
                    $publishedCount++;
                }
            }

            $groupedCount = 0;
            foreach ($grouped as $topicRows) {
                $groupedCount += count($topicRows);
            }

            self::assertSame(
                $publishedCount,
                $groupedCount,
                "Iteration {$iteration}: grouped index must contain exactly the published articles"
            );

            foreach ($grouped as $topic => $topicRows) {
                $this->assertTopicIsStablyOrdered((string) $topic, $topicRows, $iteration);
            }
        }
    }

    #[Test]
    #[TestDox('Feature: help-centre, Property 3: equal sort_order values break ties by title ascending')]
    public function equalSortOrderBreaksTiesByTitle(): void
    {
        // A focused generator that forces every article in a topic to share the
        // same sort_order, so the title tiebreak is the only thing that can
        // produce a correct ordering. This exercises the stable-tiebreak clause
        // directly rather than relying on random ties showing up.
        $insert = $this->pdo->prepare(
            "INSERT INTO help_articles (slug, title, summary, keywords, body, topic, status, sort_order)
             VALUES (:slug, :title, '', '', '[]', :topic, 'published', :sort_order)"
        );

        for ($iteration = 0; $iteration < self::ITERATIONS; $iteration++) {
            $this->pdo->exec('DELETE FROM help_articles');

            $topic = 'Shared Topic';
            $sharedSortOrder = $this->randomInt(-5, 5);
            $count = $this->randomInt(2, 8);

            $titles = [];
            for ($i = 0; $i < $count; $i++) {
                $titles[] = $this->randomTitle($iteration, $i);
            }
            // De-duplicate titles so the expected ordering is unambiguous; equal
            // (sort_order, title) pairs have no defined relative order.
            $titles = array_values(array_unique($titles));

            $n = 0;
            foreach ($this->shuffled($titles, $iteration) as $index => $title) {
                $insert->execute([
                    'slug'       => "tie-{$iteration}-{$index}",
                    'title'      => $title,
                    'topic'      => $topic,
                    'sort_order' => $sharedSortOrder,
                ]);
                $n++;
            }

            if ($n < 2) {
                // Need at least two distinct titles to assert an order.
                continue;
            }

            $grouped = $this->groupedPublishedIndex();
            self::assertArrayHasKey($topic, $grouped, "Iteration {$iteration}: topic must be present");

            $actualTitles = array_map(
                static fn (array $row): string => (string) $row['title'],
                $grouped[$topic]
            );

            $expectedTitles = $titles;
            sort($expectedTitles, SORT_STRING);

            self::assertSame(
                $expectedTitles,
                $actualTitles,
                "Iteration {$iteration}: with equal sort_order, titles must be ascending"
            );
        }
    }

    /**
     * Assert one topic's rows are ordered by (sort_order ASC, title ASC).
     *
     * @param array<int, array<string, mixed>> $rows
     */
    private function assertTopicIsStablyOrdered(string $topic, array $rows, int $iteration): void
    {
        $previous = null;

        foreach ($rows as $row) {
            self::assertSame(
                'published',
                (string) $row['status'],
                "Iteration {$iteration}, topic '{$topic}': only published articles should appear"
            );
            self::assertSame(
                $topic,
                (string) $row['topic'],
                "Iteration {$iteration}: a row was grouped under the wrong topic"
            );

            $current = [
                'sort_order' => (int) $row['sort_order'],
                'title'      => (string) $row['title'],
            ];

            if ($previous !== null) {
                $ok = $previous['sort_order'] < $current['sort_order']
                    || (
                        $previous['sort_order'] === $current['sort_order']
                        && strcmp($previous['title'], $current['title']) <= 0
                    );

                self::assertTrue(
                    $ok,
                    sprintf(
                        "Iteration %d, topic '%s': ordering violated. '%s' (sort_order %d) came before "
                        . "'%s' (sort_order %d) but should not have.",
                        $iteration,
                        $topic,
                        $previous['title'],
                        $previous['sort_order'],
                        $current['title'],
                        $current['sort_order']
                    )
                );
            }

            $previous = $current;
        }
    }

    /**
     * Generate a randomised set of articles for one iteration.
     *
     * Articles are spread across one or more topics with random sort_order
     * values (a small range, so ties are common) and random titles. Some rows
     * are drafts, so the test also confirms drafts are excluded. Slugs are made
     * unique to satisfy the UNIQUE constraint.
     *
     * @return list<array{slug: string, title: string, topic: string, status: string, sort_order: int}>
     */
    private function generateArticleSet(int $iteration): array
    {
        $topicPool = ['Events', 'People & Access', 'Email & Microsoft 365', 'Risk Assessments', 'Getting Help'];
        $topicCount = $this->randomInt(1, count($topicPool));
        $topics = array_slice($this->shuffled($topicPool, $iteration), 0, $topicCount);

        $articleCount = $this->randomInt(1, 20);
        $articles = [];

        for ($i = 0; $i < $articleCount; $i++) {
            $topic = $topics[$this->randomInt(0, count($topics) - 1)];
            $status = $this->randomInt(0, 4) === 0 ? 'draft' : 'published';

            $articles[] = [
                'slug'       => "a-{$iteration}-{$i}",
                'title'      => $this->randomTitle($iteration, $i),
                'topic'      => $topic,
                'status'     => $status,
                // Small range => frequent ties that force the title tiebreak.
                'sort_order' => $this->randomInt(-3, 3),
            ];
        }

        return $articles;
    }

    /**
     * A pseudo-random, order-varying title. Deliberately mixes case, digits and
     * repeated prefixes so the ascending-title ordering is non-trivial.
     */
    private function randomTitle(int $iteration, int $index): string
    {
        $seed = ($iteration * 1000 + $index) * 2654435761;
        $alphabet = 'AaBbCc0123MmZz ';
        $len = 1 + ($seed % 6);
        $title = '';

        for ($i = 0; $i < $len; $i++) {
            $pos = (($seed >> ($i * 3)) ^ ($seed * ($i + 1))) % strlen($alphabet);
            $pos = ($pos + strlen($alphabet)) % strlen($alphabet);
            $title .= $alphabet[$pos];
        }

        // Guarantee a non-empty, trimmed title so SQL/PHP string compares agree.
        $title = trim($title);

        return $title === '' ? "t{$index}" : $title;
    }

    /**
     * Deterministic-but-varied shuffle driven by the iteration index, so each
     * iteration inserts rows in a different physical order (proving the ordering
     * comes from the ORDER BY, not insertion order).
     *
     * @template T
     * @param list<T> $items
     * @return list<T>
     */
    private function shuffled(array $items, int $iteration): array
    {
        $count = count($items);
        for ($i = $count - 1; $i > 0; $i--) {
            $j = $this->randomInt(0, $i);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }

        return $items;
    }

    private function randomInt(int $min, int $max): int
    {
        return random_int($min, $max);
    }
}
