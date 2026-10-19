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
 * Feature: help-centre, Property 11: Help search respects the result limit
 *
 * Property statement (design.md, Property 11):
 *   For any query and limit N, search_help_articles($query, N) SHALL return at
 *   most N rows.
 *
 * Validates: Requirements 4.6
 *
 * How this is exercised
 * ---------------------
 * The result cap lives in search_help_articles() as the tail of its SQL:
 *
 *     SELECT slug, title, summary
 *     FROM help_articles
 *     WHERE status = 'published'
 *       AND (title LIKE ? OR summary LIKE ? OR keywords LIKE ?)
 *     ORDER BY title ASC
 *     LIMIT (int) $limit
 *
 * That helper calls the application's global db() PDO, which is hard-wired to a
 * MySQL server and gated behind dc_context(false) (sign-in check), so it cannot
 * be pointed at a test connection. Following the approach of the sibling query
 * tests (HelpTopicOrderingPropertyTest, HelpPublishedArticlesPropertyTest), this
 * test seeds randomised published articles into the same isolated in-memory
 * SQLite schema the migration tests use (via MigrationRunner) and replicates the
 * EXACT published-only LIKE query with `LIMIT (int) $limit` through the test PDO.
 *
 * This is a property test: it generates 100+ randomised scenarios, varying the
 * number of matching published articles (from fewer-than-limit to many more) and
 * the limit N (including the small boundary values 0, 1 and 3 as well as larger
 * values), and asserts for each one that:
 *
 *   - the query never returns more than N rows (the core cap invariant);
 *   - when more than N matching published articles exist, EXACTLY N come back
 *     (the cap is actually applied, not ignored); and
 *   - when fewer than N match, ALL matching articles come back (the cap never
 *     drops rows it should not).
 *
 * Only published articles that match the query term should be countable, so the
 * generator also seeds draft rows and non-matching rows to prove they neither
 * inflate nor are capped into the result.
 */
#[Group('query')]
#[Group('help-centre')]
#[Group('property')]
final class HelpSearchLimitPropertyTest extends TestCase
{
    private const ITERATIONS = 200;

    /**
     * The exact search query search_help_articles() runs, kept as a single
     * source of truth. The LIMIT is interpolated with an (int) cast exactly as
     * the production helper does.
     */
    private function searchSql(int $limit): string
    {
        return "
            SELECT slug, title, summary
            FROM help_articles
            WHERE status = 'published'
              AND (title LIKE ? OR summary LIKE ? OR keywords LIKE ?)
            ORDER BY title ASC
            LIMIT " . (int) $limit;
    }

    private PDO $pdo;

    protected function setUp(): void
    {
        // Reuse the migration harness purely to obtain a configured, isolated
        // in-memory SQLite PDO and the real help_articles schema (slug UNIQUE,
        // status CHECK, etc.) — identical plumbing to the sibling query tests.
        $runner = MigrationRunner::connect();
        $runner->apply();
        $this->pdo = $runner->pdo();

        // Start from a clean table so each iteration fully controls its data set
        // (the migration seeds 14 published rows we do not want interfering).
        $this->pdo->exec('DELETE FROM help_articles');
    }

    /**
     * Run the replicated search query through the test PDO.
     *
     * @return list<array<string, mixed>>
     */
    private function runSearch(string $query, int $limit): array
    {
        $like = '%' . $query . '%';
        $stmt = $this->pdo->prepare($this->searchSql($limit));
        $stmt->execute([$like, $like, $like]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    #[Test]
    #[TestDox('Feature: help-centre, Property 11: for any query and limit N, the search returns at most N rows (100+ randomised scenarios)')]
    public function searchNeverReturnsMoreThanTheLimit(): void
    {
        $insert = $this->pdo->prepare(
            "INSERT INTO help_articles (slug, title, summary, keywords, body, topic, status, sort_order)
             VALUES (:slug, :title, :summary, :keywords, '[]', '', :status, 0)"
        );

        for ($iteration = 0; $iteration < self::ITERATIONS; $iteration++) {
            $this->pdo->exec('DELETE FROM help_articles');

            // A query term guaranteed unique to this iteration so only the rows
            // we deliberately make "matching" can ever match it.
            $term = sprintf('zq%03d%s', $iteration, bin2hex(random_bytes(3)));

            // Pick a limit, deliberately covering the small boundaries plus a
            // spread of larger values.
            $limit = $this->chooseLimit($iteration);

            // Pick how many matching published articles exist, spanning
            // fewer-than, equal-to and many-more-than the limit.
            $matching = $this->chooseMatchingCount($iteration, $limit);

            // Seed the matching published articles. The term is placed in a
            // different searchable column each time so all three LIKE branches
            // (title / summary / keywords) are exercised across the run.
            for ($n = 0; $n < $matching; $n++) {
                $where = $n % 3;
                $insert->execute([
                    'slug'     => sprintf('it%03d-match%03d', $iteration, $n),
                    'title'    => $where === 0 ? "Guide {$term} {$n}" : sprintf('Title %03d', $n),
                    'summary'  => $where === 1 ? "About {$term}" : 'A summary',
                    'keywords' => $where === 2 ? "tag {$term}" : 'misc',
                    'status'   => 'published',
                ]);
            }

            // Seed decoys that must NOT be counted: published-but-non-matching
            // rows, and matching-but-draft rows. Neither should influence the
            // cap behaviour.
            $decoyPublishedNonMatching = random_int(0, 5);
            for ($d = 0; $d < $decoyPublishedNonMatching; $d++) {
                $insert->execute([
                    'slug'     => sprintf('it%03d-pubdecoy%03d', $iteration, $d),
                    'title'    => sprintf('Unrelated %03d', $d),
                    'summary'  => 'nothing to see',
                    'keywords' => 'other',
                    'status'   => 'published',
                ]);
            }

            $decoyMatchingDraft = random_int(0, 5);
            for ($d = 0; $d < $decoyMatchingDraft; $d++) {
                $insert->execute([
                    'slug'     => sprintf('it%03d-draftdecoy%03d', $iteration, $d),
                    'title'    => "Draft {$term} {$d}",
                    'summary'  => "draft {$term}",
                    'keywords' => $term,
                    'status'   => 'draft',
                ]);
            }

            $rows = $this->runSearch($term, $limit);
            $returned = count($rows);

            // Core invariant: never more than N rows, regardless of how many
            // matched.
            self::assertLessThanOrEqual(
                max(0, $limit),
                $returned,
                sprintf(
                    'Iteration %d: search returned %d rows for limit %d (%d matching published seeded)',
                    $iteration,
                    $returned,
                    $limit,
                    $matching
                )
            );

            // Every returned row must be one of the matching published articles
            // (decoys must never appear). The matching slug prefix proves both
            // that the row is a genuine match and that it is neither a
            // non-matching published decoy nor a matching draft decoy.
            foreach ($rows as $row) {
                self::assertStringStartsWith(
                    sprintf('it%03d-match', $iteration),
                    (string) $row['slug'],
                    sprintf('Iteration %d: a decoy (draft or non-matching) row leaked into the results', $iteration)
                );
            }

            $expected = $limit <= 0
                ? 0
                : min($matching, $limit);

            // The cap is actually applied when there are more matches than N,
            // and nothing is dropped when there are fewer.
            self::assertSame(
                $expected,
                $returned,
                sprintf(
                    'Iteration %d: expected min(matching=%d, limit=%d)=%d rows, got %d',
                    $iteration,
                    $matching,
                    $limit,
                    $expected,
                    $returned
                )
            );
        }
    }

    #[Test]
    #[TestDox('Feature: help-centre, Property 11: when more matching published articles exist than the limit, exactly the limit is returned')]
    public function capIsAppliedWhenMatchesExceedLimit(): void
    {
        $insert = $this->pdo->prepare(
            "INSERT INTO help_articles (slug, title, summary, keywords, body, topic, status, sort_order)
             VALUES (:slug, :title, '', '', '[]', '', 'published', 0)"
        );

        // Small, telling limits with a clear surplus of matches each time.
        foreach ([1, 1, 2, 3, 3, 5, 7, 10] as $i => $limit) {
            $this->pdo->exec('DELETE FROM help_articles');

            $term = sprintf('cap%02d', $i);
            $matching = $limit + random_int(1, 10);

            for ($n = 0; $n < $matching; $n++) {
                $insert->execute([
                    'slug'  => sprintf('cap%02d-%03d', $i, $n),
                    'title' => sprintf('%s item %03d', $term, $n),
                ]);
            }

            $rows = $this->runSearch($term, $limit);

            self::assertCount(
                $limit,
                $rows,
                sprintf('With %d matches and limit %d, exactly %d rows must be returned', $matching, $limit, $limit)
            );
        }
    }

    #[Test]
    #[TestDox('Feature: help-centre, Property 11: when fewer matching published articles exist than the limit, all matches are returned')]
    public function allMatchesReturnedWhenFewerThanLimit(): void
    {
        $insert = $this->pdo->prepare(
            "INSERT INTO help_articles (slug, title, summary, keywords, body, topic, status, sort_order)
             VALUES (:slug, :title, '', '', '[]', '', 'published', 0)"
        );

        foreach ([5, 10, 25, 100] as $i => $limit) {
            $this->pdo->exec('DELETE FROM help_articles');

            $term = sprintf('few%02d', $i);
            $matching = random_int(0, $limit - 1);

            for ($n = 0; $n < $matching; $n++) {
                $insert->execute([
                    'slug'  => sprintf('few%02d-%03d', $i, $n),
                    'title' => sprintf('%s item %03d', $term, $n),
                ]);
            }

            $rows = $this->runSearch($term, $limit);

            self::assertCount(
                $matching,
                $rows,
                sprintf('With %d matches and limit %d, all %d matches must be returned', $matching, $limit, $matching)
            );
        }
    }

    /**
     * Choose a limit for the iteration, guaranteeing the small boundary values
     * 0, 1 and 3 are covered as well as a spread of larger values.
     */
    private function chooseLimit(int $iteration): int
    {
        return match ($iteration) {
            0 => 0,
            1 => 1,
            2 => 3,
            3 => 1,
            4 => 0,
            default => [0, 1, 2, 3, 5, 10, 25][random_int(0, 6)],
        };
    }

    /**
     * Choose how many matching published articles to seed, spanning
     * fewer-than-limit, exactly-the-limit and many-more-than-limit.
     */
    private function chooseMatchingCount(int $iteration, int $limit): int
    {
        return match ($iteration % 4) {
            0 => max(0, $limit - random_int(1, 3)), // fewer than the limit
            1 => $limit,                             // exactly the limit
            2 => $limit + random_int(1, 12),         // many more than the limit
            default => random_int(0, 20),            // free-for-all
        };
    }
}
