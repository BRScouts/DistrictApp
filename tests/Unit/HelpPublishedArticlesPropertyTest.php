<?php

declare(strict_types=1);

namespace DistrictApp\Tests\Unit;

use DistrictApp\Tests\Support\MigrationRunner;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Property test for the Help_Centre_Index published-only listing.
 *
 * Feature: help-centre, Property 2: Index lists exactly the published articles
 *
 * Property statement (design.md, Property 2): For any set of Help_Articles with
 * arbitrary `status` values, the Help_Centre_Index result SHALL contain exactly
 * those articles whose `status` is `published` and no article whose `status` is
 * `draft`.
 *
 * Validates: Requirements 2.4, 2.8
 *
 * The function under test is help_published_articles() in app/help-helpers.php,
 * whose published-only listing is driven by:
 *
 *     SELECT ... FROM help_articles
 *     WHERE status = 'published'
 *     ORDER BY topic ASC, sort_order ASC, title ASC
 *
 * help_published_articles() reads through the global db() PDO (hardcoded to the
 * application's MySQL connection in config.php, with no injection seam), so it
 * cannot be pointed at an in-memory test database. To exercise the property
 * without a live MySQL server, this test seeds randomized article sets into the
 * same isolated SQLite schema the migration tests use (via MigrationRunner) and
 * runs the exact published-only SELECT that help_published_articles() issues,
 * asserting the returned slugs are EXACTLY the published set and that no draft
 * slug ever appears.
 */
#[Group('help-centre')]
#[Group('property')]
final class HelpPublishedArticlesPropertyTest extends TestCase
{
    /**
     * Property tests run a minimum of 100 iterations; we use 150 randomized sets.
     */
    private const ITERATIONS = 150;

    /**
     * The exact published-only listing query help_published_articles() runs.
     * Mirrored here because help_published_articles() uses the global db() PDO,
     * which cannot be redirected at the test database.
     */
    private const PUBLISHED_INDEX_SQL = "
        SELECT id, slug, title, summary, keywords, body, topic, status, sort_order
        FROM help_articles
        WHERE status = 'published'
        ORDER BY topic ASC, sort_order ASC, title ASC";

    #[Test]
    #[TestDox('Property 2: the index lists exactly the published articles and no draft (100+ randomized sets)')]
    public function indexListsExactlyThePublishedArticles(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $pdo = $this->freshSchema();

            // Generate a randomized set of articles with arbitrary statuses.
            $articles = $this->randomArticleSet($i);

            $expectedPublished = [];
            $draftSlugs = [];
            foreach ($articles as $article) {
                $this->insertArticle($pdo, $article);
                if ($article['status'] === 'published') {
                    $expectedPublished[$article['slug']] = true;
                } else {
                    $draftSlugs[$article['slug']] = true;
                }
            }

            // Run the exact published-only listing query.
            $stmt = $pdo->query(self::PUBLISHED_INDEX_SQL);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $returnedSlugs = array_map(static fn (array $r): string => (string) $r['slug'], $rows);

            // Every returned row must itself be published (defensive against a
            // leaked status), and the result must contain no draft slug.
            foreach ($rows as $row) {
                self::assertSame(
                    'published',
                    $row['status'],
                    "Iteration {$i}: the index must only ever return published rows"
                );
                self::assertArrayNotHasKey(
                    (string) $row['slug'],
                    $draftSlugs,
                    "Iteration {$i}: draft slug '{$row['slug']}' must never appear in the index"
                );
            }

            // The returned set must equal EXACTLY the published set (no missing,
            // no extra). Compare as sets because order is a separate property.
            sort($returnedSlugs);
            $expectedSorted = array_keys($expectedPublished);
            sort($expectedSorted);

            self::assertSame(
                $expectedSorted,
                $returnedSlugs,
                "Iteration {$i}: the index must contain exactly the published slugs"
            );

            // And explicitly: no draft slug is present in the returned set.
            foreach (array_keys($draftSlugs) as $draftSlug) {
                self::assertNotContains(
                    $draftSlug,
                    $returnedSlugs,
                    "Iteration {$i}: draft slug '{$draftSlug}' must be excluded from the index"
                );
            }
        }
    }

    /**
     * A fresh, isolated in-memory schema with the help_articles table applied
     * by the real migration translated for SQLite (via MigrationRunner).
     *
     * The migration also seeds the 14 published fixture articles; this test
     * removes them so each iteration asserts over a clean, fully randomized set.
     */
    private function freshSchema(): PDO
    {
        $runner = MigrationRunner::connect();
        $runner->apply();
        $pdo = $runner->pdo();

        // Start from an empty table so the generated set is the whole universe.
        $pdo->exec('DELETE FROM help_articles');

        return $pdo;
    }

    /**
     * Generate a randomized set of articles for one iteration.
     *
     * Varies the count, status (published/draft), topic, sort_order and title,
     * with globally unique slugs so the slug UNIQUE constraint never trips and
     * each article is individually addressable.
     *
     * The first couple of iterations exercise boundary cases: an all-draft set
     * and an all-published set, so "no published articles" and "no drafts" are
     * both covered within the run.
     *
     * @return list<array{slug: string, title: string, topic: string, status: string, sort_order: int}>
     */
    private function randomArticleSet(int $iteration): array
    {
        $topics = ['Events', 'Email & Microsoft 365', 'People & Access', 'Risk Assessments', 'Getting Help', ''];

        // 0..12 articles; include the empty-set and boundary cases deterministically.
        $count = match ($iteration) {
            0 => 0,              // empty universe -> empty index
            1 => random_int(1, 8), // all draft (forced below)
            2 => random_int(1, 8), // all published (forced below)
            default => random_int(0, 12),
        };

        $articles = [];
        for ($n = 0; $n < $count; $n++) {
            $status = match ($iteration) {
                1 => 'draft',
                2 => 'published',
                default => random_int(0, 1) === 1 ? 'published' : 'draft',
            };

            $articles[] = [
                'slug'       => sprintf('it%03d-art%02d-%s', $iteration, $n, bin2hex(random_bytes(3))),
                'title'      => $this->randomTitle(),
                'topic'      => $topics[array_rand($topics)],
                'status'     => $status,
                'sort_order' => random_int(-5, 100),
            ];
        }

        return $articles;
    }

    private function randomTitle(): string
    {
        $words = ['Add', 'Edit', 'View', 'Manage', 'Approve', 'Find', 'Update', 'Guide', 'Members', 'Events', 'Profile', 'Email'];
        $len = random_int(1, 4);
        $parts = [];
        for ($i = 0; $i < $len; $i++) {
            $parts[] = $words[array_rand($words)];
        }

        return implode(' ', $parts);
    }

    /**
     * @param array{slug: string, title: string, topic: string, status: string, sort_order: int} $article
     */
    private function insertArticle(PDO $pdo, array $article): void
    {
        $stmt = $pdo->prepare(
            "INSERT INTO help_articles (slug, title, summary, keywords, body, topic, status, sort_order)
             VALUES (:slug, :title, '', '', '[]', :topic, :status, :sort_order)"
        );
        $stmt->execute([
            'slug'       => $article['slug'],
            'title'      => $article['title'],
            'topic'      => $article['topic'],
            'status'     => $article['status'],
            'sort_order' => $article['sort_order'],
        ]);
    }
}
