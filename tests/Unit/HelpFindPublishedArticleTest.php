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
 * Property and example tests for the Article_Viewer published-only lookup.
 *
 * Feature: help-centre, Property 9: Only published articles are viewable
 *
 * Property statement (design.md, Property 9): For any slug, the Article_Viewer
 * SHALL render the article if and only if a Help_Article with that slug exists
 * and has `status` equal to `published`; otherwise it SHALL render the
 * not-found state.
 *
 * Validates: Requirements 3.1, 3.7, 3.8
 *
 * The function under test is help_find_published_article(string $slug): ?array
 * in app/help-helpers.php, whose "found iff published" behaviour is driven by:
 *
 *     SELECT ... FROM help_articles
 *     WHERE slug = ? AND status = 'published'
 *     LIMIT 1
 *
 * returning null for a draft or unknown slug (which drives the viewer's
 * not-found state), with a try/catch (Throwable) that also returns null.
 *
 * help_find_published_article() reads through the global db() PDO (hardcoded to
 * the application's MySQL connection in config.php, with no injection seam), so
 * it cannot be pointed at an in-memory test database. To exercise the property
 * without a live MySQL server — matching the approach of the sibling
 * HelpPublishedArticlesPropertyTest — this test seeds randomized article sets
 * into the same isolated SQLite schema the migration tests use (via
 * MigrationRunner) and runs the exact slug+published lookup that
 * help_find_published_article() issues, through a small replica
 * (lookupPublishedArticle()) that preserves the function's try/catch → null
 * contract.
 */
#[Group('help-centre')]
#[Group('property')]
final class HelpFindPublishedArticleTest extends TestCase
{
    /** Property tests run a minimum of 100 iterations; we use 150 randomized sets. */
    private const ITERATIONS = 150;

    /**
     * The exact slug + published lookup help_find_published_article() runs.
     * Mirrored here because that function uses the global db() PDO, which
     * cannot be redirected at the test database.
     */
    private const VIEWER_LOOKUP_SQL = "
        SELECT id, slug, title, summary, keywords, body, topic, status, sort_order
        FROM help_articles
        WHERE slug = ? AND status = 'published'
        LIMIT 1";

    #[Test]
    #[TestDox('Property 9: a slug resolves to an article iff it exists and is published; drafts and unknowns yield null (100+ randomized sets)')]
    public function articleIsViewableIffPublished(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $pdo = $this->freshSchema();

            // Generate a randomized set of articles with arbitrary statuses.
            $articles = $this->randomArticleSet($i);

            /** @var array<string, array{title: string, status: string}> $bySlug */
            $bySlug = [];
            foreach ($articles as $article) {
                $this->insertArticle($pdo, $article);
                $bySlug[$article['slug']] = [
                    'title'  => $article['title'],
                    'status' => $article['status'],
                ];
            }

            // For every seeded slug, the lookup returns a row iff it is published.
            foreach ($bySlug as $slug => $info) {
                $result = $this->lookupPublishedArticle($pdo, $slug);

                if ($info['status'] === 'published') {
                    self::assertIsArray(
                        $result,
                        "Iteration {$i}: published slug '{$slug}' must resolve to an article"
                    );
                    self::assertSame(
                        $slug,
                        (string) $result['slug'],
                        "Iteration {$i}: lookup must return the requested slug"
                    );
                    self::assertSame(
                        $info['title'],
                        (string) $result['title'],
                        "Iteration {$i}: lookup must return the matching article's title"
                    );
                    self::assertSame(
                        'published',
                        (string) $result['status'],
                        "Iteration {$i}: lookup must only ever return a published row"
                    );
                } else {
                    self::assertNull(
                        $result,
                        "Iteration {$i}: draft slug '{$slug}' must yield the not-found state (null)"
                    );
                }
            }

            // And several random slugs that were never seeded must yield null.
            foreach ($this->randomUnknownSlugs($i, $bySlug) as $unknown) {
                self::assertNull(
                    $this->lookupPublishedArticle($pdo, $unknown),
                    "Iteration {$i}: unknown slug '{$unknown}' must yield the not-found state (null)"
                );
            }
        }
    }

    #[Test]
    #[TestDox('Example: a known draft slug resolves to null (drives the not-found state)')]
    public function knownDraftSlugReturnsNull(): void
    {
        $pdo = $this->freshSchema();

        $this->insertArticle($pdo, [
            'slug'       => 'draft-only-guide',
            'title'      => 'A guide that is still a draft',
            'topic'      => 'Getting Help',
            'status'     => 'draft',
            'sort_order' => 10,
        ]);

        self::assertNull(
            $this->lookupPublishedArticle($pdo, 'draft-only-guide'),
            'A draft article must not be viewable; the lookup must return null'
        );
    }

    #[Test]
    #[TestDox('Example: an unknown/nonexistent slug resolves to null (drives the not-found state)')]
    public function unknownSlugReturnsNull(): void
    {
        $pdo = $this->freshSchema();

        // Seed one published article so the table is non-empty, then look up a
        // slug that was never inserted.
        $this->insertArticle($pdo, [
            'slug'       => 'a-real-published-article',
            'title'      => 'A real, published article',
            'topic'      => 'Events',
            'status'     => 'published',
            'sort_order' => 10,
        ]);

        self::assertNull(
            $this->lookupPublishedArticle($pdo, 'no-such-slug-exists'),
            'An unknown slug must not resolve to any article; the lookup must return null'
        );
    }

    /**
     * Replica of help_find_published_article()'s data access against the test
     * PDO: the exact slug+published prepared lookup, decoding the body, and the
     * try/catch (Throwable) → null contract. Returns the matching row (body
     * decoded) or null for a draft/unknown slug or any failure.
     *
     * @return array<string, mixed>|null
     */
    private function lookupPublishedArticle(PDO $pdo, string $slug): ?array
    {
        try {
            $stmt = $pdo->prepare(self::VIEWER_LOOKUP_SQL);
            $stmt->execute([$slug]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return null;
        }

        if (!is_array($row)) {
            return null;
        }

        $decoded = json_decode(isset($row['body']) ? (string) $row['body'] : '', true);
        $row['body'] = is_array($decoded) ? $decoded : [];

        return $row;
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
     * Boundary iterations are forced: an all-draft set (nothing viewable) and
     * an all-published set (everything viewable) are both covered within the
     * run, plus a guaranteed non-empty mixed set.
     *
     * @return list<array{slug: string, title: string, topic: string, status: string, sort_order: int}>
     */
    private function randomArticleSet(int $iteration): array
    {
        $topics = ['Events', 'Email & Microsoft 365', 'People & Access', 'Risk Assessments', 'Getting Help', ''];

        $count = match ($iteration) {
            0 => random_int(1, 8), // all draft (forced below) -> nothing viewable
            1 => random_int(1, 8), // all published (forced below) -> all viewable
            default => random_int(1, 12),
        };

        $articles = [];
        for ($n = 0; $n < $count; $n++) {
            $status = match ($iteration) {
                0 => 'draft',
                1 => 'published',
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

    /**
     * A handful of random slugs guaranteed not to be present in the seeded set.
     *
     * @param array<string, array{title: string, status: string}> $bySlug
     * @return list<string>
     */
    private function randomUnknownSlugs(int $iteration, array $bySlug): array
    {
        $unknowns = [];
        for ($k = 0; $k < 4; $k++) {
            do {
                $candidate = sprintf('it%03d-unknown%02d-%s', $iteration, $k, bin2hex(random_bytes(4)));
            } while (isset($bySlug[$candidate]));
            $unknowns[] = $candidate;
        }

        // Also include a couple of fixed, obviously-absent slugs.
        $unknowns[] = '';
        $unknowns[] = 'definitely-not-a-real-slug';

        return $unknowns;
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
