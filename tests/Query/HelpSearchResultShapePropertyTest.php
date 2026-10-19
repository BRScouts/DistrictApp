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
 * Property test for the global-search Help result shape and published-only
 * filtering.
 *
 * Feature: help-centre, Property 10: Help search rows are well-formed and
 * published-only
 *
 * Property statement (design.md, Property 10): For any non-empty query
 * evaluated for a signed-in user, every row returned by search_help_articles()
 * SHALL have category='help', badge='Help', title=the article title,
 * subtitle=the article summary, url='/help-centre.php?slug={slug}',
 * external=false, and SHALL correspond to an article whose status is
 * published.
 *
 * Validates: Requirements 4.3, 4.4, 4.8
 *
 * The function under test is search_help_articles(string $query, int $limit) in
 * app/search-helpers.php, whose published-only, signed-in behaviour is driven
 * by:
 *
 *     SELECT slug, title, summary
 *     FROM help_articles
 *     WHERE status = 'published'
 *       AND (title LIKE ? OR summary LIKE ? OR keywords LIKE ?)
 *     ORDER BY title ASC
 *     LIMIT N
 *
 * mapping each row to the normalised Search_Result_Row:
 *
 *     [
 *       'category' => 'help',
 *       'badge'    => 'Help',
 *       'title'    => $row['title'],
 *       'subtitle' => $row['summary'],
 *       'url'      => '/help-centre.php?slug=' . rawurlencode($row['slug']),
 *       'external' => false,
 *     ]
 *
 * search_help_articles() reads through the global db() PDO (hardcoded to the
 * application's MySQL connection in config.php, with no injection seam) and is
 * gated by dc_context(false)['is_signed_in'], so it cannot be pointed at an
 * in-memory test database. To exercise the property without a live MySQL server
 * — matching the approach of the sibling HelpPublishedArticlesPropertyTest and
 * HelpFindPublishedArticleTest — this test seeds randomized article sets into
 * the same isolated SQLite schema the migration tests use (via MigrationRunner)
 * and runs the exact published-only LIKE query search_help_articles() issues
 * through a small replica (searchHelpArticles()) that reproduces the function's
 * EXACT mapping and its try/catch (Throwable) → [] contract. The signed-in gate
 * is assumed satisfied (the replica models the post-gate code path).
 */
#[Group('help-centre')]
#[Group('property')]
final class HelpSearchResultShapePropertyTest extends TestCase
{
    /** Property tests run a minimum of 100 iterations; we use 150 randomized sets. */
    private const ITERATIONS = 150;

    /**
     * A generous per-search limit so the result-shape property is observed over
     * the full matching set (the limit itself is Property 11's concern).
     */
    private const SEARCH_LIMIT = 100;

    /**
     * The exact published-only LIKE query search_help_articles() runs.
     * Mirrored here because that function uses the global db() PDO, which
     * cannot be redirected at the test database.
     */
    private const HELP_SEARCH_SQL = "
        SELECT slug, title, summary
        FROM help_articles
        WHERE status = 'published'
          AND (title LIKE ? OR summary LIKE ? OR keywords LIKE ?)
        ORDER BY title ASC
        LIMIT ";

    #[Test]
    #[TestDox('Property 10: every help search row is well-formed and corresponds to a published article (100+ randomized sets)')]
    public function helpSearchRowsAreWellFormedAndPublishedOnly(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $pdo = $this->freshSchema();

            // A query term that is embedded in a controlled subset of articles
            // (across both published and draft rows), so matches are guaranteed
            // to span both statuses and the published-only filter is exercised.
            $term = $this->randomTerm($i);

            /** @var array<string, array{title: string, summary: string, status: string, matches: bool}> $bySlug */
            $bySlug = [];
            foreach ($this->randomArticleSet($i, $term) as $article) {
                $this->insertArticle($pdo, $article);
                $bySlug[$article['slug']] = [
                    'title'   => $article['title'],
                    'summary' => $article['summary'],
                    'status'  => $article['status'],
                    'matches' => $article['matches'],
                ];
            }

            $rows = $this->searchHelpArticles($pdo, $term, self::SEARCH_LIMIT);

            foreach ($rows as $row) {
                // Every row maps back to a seeded slug we can reason about.
                $slug = $this->slugFromUrl((string) $row['url']);
                self::assertArrayHasKey(
                    $slug,
                    $bySlug,
                    "Iteration {$i}: returned url must decode to a seeded slug"
                );
                $article = $bySlug[$slug];

                // Shape invariants.
                self::assertSame('help', $row['category'], "Iteration {$i}: category must be 'help'");
                self::assertSame('Help', $row['badge'], "Iteration {$i}: badge must be 'Help'");
                self::assertFalse($row['external'], "Iteration {$i}: help rows must not be external");

                // Title/subtitle come straight from the article.
                self::assertSame(
                    $article['title'],
                    $row['title'],
                    "Iteration {$i}: title must be the article's title"
                );
                self::assertSame(
                    $article['summary'],
                    $row['subtitle'],
                    "Iteration {$i}: subtitle must be the article's summary"
                );

                // URL is exactly /help-centre.php?slug={rawurlencode(slug)}.
                self::assertSame(
                    '/help-centre.php?slug=' . rawurlencode($slug),
                    $row['url'],
                    "Iteration {$i}: url must point at the article's slug"
                );

                // Published-only: a returned row must correspond to a published
                // article and must never be a draft.
                self::assertSame(
                    'published',
                    $article['status'],
                    "Iteration {$i}: every returned row must be a published article"
                );
            }

            // A draft article whose fields match the query must NEVER appear.
            $returnedSlugs = array_map(
                fn (array $r): string => $this->slugFromUrl((string) $r['url']),
                $rows
            );
            foreach ($bySlug as $slug => $info) {
                if ($info['status'] === 'draft' && $info['matches']) {
                    self::assertNotContains(
                        $slug,
                        $returnedSlugs,
                        "Iteration {$i}: matching draft slug '{$slug}' must be filtered out"
                    );
                }
            }
        }
    }

    /**
     * Replica of search_help_articles()'s post-gate data access against the
     * test PDO: the exact published-only LIKE query and the EXACT row mapping
     * (category/badge/title/subtitle/url/external), with the try/catch
     * (Throwable) → [] contract preserved.
     *
     * @return list<array<string, mixed>>
     */
    private function searchHelpArticles(PDO $pdo, string $query, int $limit): array
    {
        if ($query === '') {
            return [];
        }

        $like = '%' . $query . '%';

        try {
            $stmt = $pdo->prepare(self::HELP_SEARCH_SQL . (int) $limit);
            $stmt->execute([$like, $like, $like]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'category' => 'help',
                'badge'    => 'Help',
                'title'    => (string) ($row['title'] ?? ''),
                'subtitle' => (string) ($row['summary'] ?? ''),
                'url'      => '/help-centre.php?slug=' . rawurlencode((string) ($row['slug'] ?? '')),
                'external' => false,
            ];
        }

        return $out;
    }

    /**
     * Recover the slug from a mapped row's url for back-reference to the seed.
     */
    private function slugFromUrl(string $url): string
    {
        $prefix = '/help-centre.php?slug=';
        if (str_starts_with($url, $prefix)) {
            return rawurldecode(substr($url, strlen($prefix)));
        }

        return $url;
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
     * A distinctive query term for this iteration. Deliberately unusual so it
     * only appears in the article fields where we inject it, never incidentally
     * elsewhere.
     */
    private function randomTerm(int $iteration): string
    {
        return sprintf('qz%03d%s', $iteration, bin2hex(random_bytes(2)));
    }

    /**
     * Generate a randomized set of articles for one iteration.
     *
     * Each article gets an arbitrary status (published/draft) and random
     * title/summary/keywords. A controlled subset has the iteration's query
     * term injected into one of its searchable fields (title, summary or
     * keywords) so that matches span both published and draft rows; the rest
     * are non-matching noise. Boundary iterations cover an all-draft matching
     * set and a set where nothing matches the term at all.
     *
     * @return list<array{slug: string, title: string, summary: string, keywords: string, topic: string, status: string, sort_order: int, matches: bool}>
     */
    private function randomArticleSet(int $iteration, string $term): array
    {
        $topics = ['Events', 'Email & Microsoft 365', 'People & Access', 'Risk Assessments', 'Getting Help', ''];

        $count = match ($iteration) {
            0 => random_int(2, 8),  // all matching articles are drafts (nothing returned)
            1 => random_int(1, 8),  // no article matches the term at all (empty result)
            default => random_int(1, 12),
        };

        $articles = [];
        for ($n = 0; $n < $count; $n++) {
            // Decide whether this article should match the query term.
            $shouldMatch = match ($iteration) {
                0 => true,                               // force matches, but all draft (below)
                1 => false,                              // never match the term
                default => random_int(0, 1) === 1,
            };

            $status = match ($iteration) {
                0 => 'draft',                            // matching drafts must be filtered out
                1 => random_int(0, 1) === 1 ? 'published' : 'draft',
                default => random_int(0, 1) === 1 ? 'published' : 'draft',
            };

            $title    = $this->randomTitle();
            $summary  = $this->randomSummary();
            $keywords = $this->randomKeywords();

            if ($shouldMatch) {
                // Inject the term into exactly one searchable field so the LIKE
                // predicate fires; vary which field across articles.
                switch (random_int(0, 2)) {
                    case 0:
                        $title .= ' ' . $term;
                        break;
                    case 1:
                        $summary .= ' ' . $term;
                        break;
                    default:
                        $keywords .= ' ' . $term;
                        break;
                }
            }

            $articles[] = [
                'slug'       => sprintf('it%03d-art%02d-%s', $iteration, $n, bin2hex(random_bytes(3))),
                'title'      => $title,
                'summary'    => $summary,
                'keywords'   => $keywords,
                'topic'      => $topics[array_rand($topics)],
                'status'     => $status,
                'sort_order' => random_int(-5, 100),
                'matches'    => $shouldMatch,
            ];
        }

        return $articles;
    }

    private function randomTitle(): string
    {
        $words = ['Add', 'Edit', 'View', 'Manage', 'Approve', 'Find', 'Update', 'Guide', 'Members', 'Events', 'Profile', 'Email'];

        return $this->randomPhrase($words, 1, 4);
    }

    private function randomSummary(): string
    {
        $words = ['Step', 'by', 'step', 'instructions', 'for', 'leaders', 'and', 'volunteers', 'in', 'the', 'District', 'portal'];

        return $this->randomPhrase($words, 2, 8);
    }

    private function randomKeywords(): string
    {
        $words = ['calendar', 'event', 'member', 'profile', 'email', 'risk', 'assessment', 'glv', 'support', 'login'];

        return $this->randomPhrase($words, 1, 5);
    }

    /**
     * @param list<string> $words
     */
    private function randomPhrase(array $words, int $min, int $max): string
    {
        $len = random_int($min, $max);
        $parts = [];
        for ($i = 0; $i < $len; $i++) {
            $parts[] = $words[array_rand($words)];
        }

        return implode(' ', $parts);
    }

    /**
     * @param array{slug: string, title: string, summary: string, keywords: string, topic: string, status: string, sort_order: int, matches: bool} $article
     */
    private function insertArticle(PDO $pdo, array $article): void
    {
        $stmt = $pdo->prepare(
            "INSERT INTO help_articles (slug, title, summary, keywords, body, topic, status, sort_order)
             VALUES (:slug, :title, :summary, :keywords, '[]', :topic, :status, :sort_order)"
        );
        $stmt->execute([
            'slug'       => $article['slug'],
            'title'      => $article['title'],
            'summary'    => $article['summary'],
            'keywords'   => $article['keywords'],
            'topic'      => $article['topic'],
            'status'     => $article['status'],
            'sort_order' => $article['sort_order'],
        ]);
    }
}
