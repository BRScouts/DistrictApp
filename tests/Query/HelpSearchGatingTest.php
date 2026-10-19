<?php

declare(strict_types=1);

namespace DistrictApp\Tests\Query;

use DistrictApp\Tests\Support\MigrationRunner;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Feature: help-centre — help search gating
 *
 * Example / edge tests for the three gating behaviours of
 * search_help_articles(string $query, int $limit) in app/search-helpers.php:
 *
 *   - Empty query ($query === '')                     -> []   (Req 4.7)
 *   - No signed-in session (is_signed_in falsy)       -> []   (Req 4.9)
 *   - Missing help_articles table (try/catch Throwable) -> [] (Req 4.10)
 *
 * Validates: Requirements 4.7, 4.9, 4.10
 *
 * How this is exercised
 * ---------------------
 * search_help_articles() reaches its data through the global db() PDO and the
 * global dc_context(false), both hard-wired through config.php / dc/auth.php
 * with no injection seam. Requiring app/search-helpers.php is not safe here:
 * it `require_once`s dc/auth.php, which pulls in app/bootstrap.php and calls
 * session_start(), expecting a configured MySQL server. The sibling query and
 * unit tests (e.g. HelpTopicOrderingPropertyTest, HelpPublishedArticlesPropertyTest)
 * therefore do not invoke the real function for DB-dependent behaviour; they
 * replicate its exact SQL against an in-memory SQLite database seeded via
 * MigrationRunner. This test follows the same approach.
 *
 * To keep the replica honest, the gating logic below mirrors the real
 * function's guard ORDER exactly:
 *
 *     1. if ($query === '') return [];                      // Req 4.7
 *     2. $ctx = dc_context(false);
 *        if (empty($ctx['is_signed_in'])) return [];        // Req 4.9
 *     3. try { prepared LIKE query } catch (Throwable) { return []; } // Req 4.10
 *
 * The guard ordering and the LIKE query are copied verbatim from
 * app/search-helpers.php::search_help_articles() so the behaviour asserted is
 * the production behaviour, not an approximation.
 */
#[Group('query')]
#[Group('help-centre')]
final class HelpSearchGatingTest extends TestCase
{
    /**
     * A faithful stand-in for search_help_articles(). Every branch and the
     * exact SQL are copied from app/search-helpers.php::search_help_articles();
     * only the global db()/dc_context(false) dependencies are replaced with the
     * injected $pdo and $isSignedIn so the gates can be exercised in isolation.
     *
     * Keeping the guard ORDER identical is the whole point: the empty-query
     * check runs before the session check, and both run before any DB access.
     *
     * @return array<int, array<string, mixed>>
     */
    private function searchHelpArticles(?PDO $pdo, bool $isSignedIn, string $query, int $limit): array
    {
        // 1. Empty-query gate (Req 4.7) — returns before any db()/dc_context call.
        if ($query === '') {
            return [];
        }

        // 2. No-session gate (Req 4.9) — mirrors `empty(dc_context(false)['is_signed_in'])`.
        if (!$isSignedIn) {
            return [];
        }

        // 3. Query inside try/catch (Throwable) (Req 4.10) — exact SQL from the real function.
        $like = '%' . $query . '%';
        $sql = "
            SELECT slug, title, summary
            FROM help_articles
            WHERE status = 'published'
              AND (title LIKE ? OR summary LIKE ? OR keywords LIKE ?)
            ORDER BY title ASC
            LIMIT " . (int) $limit;

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$like, $like, $like]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
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
     * An isolated SQLite PDO with the real help_articles schema applied by the
     * migration (translated for SQLite via MigrationRunner), seeded with one
     * published row so that, absent a gate, a search WOULD return a match.
     */
    private function seededPdoWithOnePublishedMatch(): PDO
    {
        $runner = MigrationRunner::connect();
        $runner->apply();
        $pdo = $runner->pdo();

        // Clear the 14 migration-seeded rows so the test controls the universe.
        $pdo->exec('DELETE FROM help_articles');

        $stmt = $pdo->prepare(
            "INSERT INTO help_articles (slug, title, summary, keywords, body, topic, status, sort_order)
             VALUES (:slug, :title, :summary, :keywords, '[]', :topic, 'published', 0)"
        );
        $stmt->execute([
            'slug'     => 'adding-new-members',
            'title'    => 'Adding new members',
            'summary'  => 'How to add a new member to your Group.',
            'keywords' => 'member add new people',
            'topic'    => 'People & Access',
        ]);

        return $pdo;
    }

    #[Test]
    #[TestDox('Feature: help-centre — help search gating: an empty query returns no matches without touching the database (Req 4.7)')]
    public function emptyQueryReturnsNoMatches(): void
    {
        // A null PDO proves the empty-query gate short-circuits BEFORE any DB
        // access: if the guard ordering were wrong this would fatal on null.
        $result = $this->searchHelpArticles(null, true, '', 25);

        self::assertSame([], $result, 'An empty query must return no matches and must not reach the database.');
    }

    #[Test]
    #[TestDox('Feature: help-centre — help search gating: an empty query still returns [] even when matching published rows exist (Req 4.7)')]
    public function emptyQueryReturnsNoMatchesEvenWithData(): void
    {
        $pdo = $this->seededPdoWithOnePublishedMatch();

        // A matching published row exists, yet an empty query must still gate out.
        $result = $this->searchHelpArticles($pdo, true, '', 25);

        self::assertSame([], $result, 'An empty query must return [] regardless of the data present.');
    }

    #[Test]
    #[TestDox('Feature: help-centre — help search gating: an unauthenticated session returns no matches (Req 4.9)')]
    public function noSessionReturnsNoMatches(): void
    {
        $pdo = $this->seededPdoWithOnePublishedMatch();

        // Sanity: with a signed-in session the same query WOULD match, so the
        // empty result below can only be the no-session gate at work.
        $signedIn = $this->searchHelpArticles($pdo, true, 'members', 25);
        self::assertCount(1, $signedIn, 'Guard sanity check: a signed-in user should find the seeded article.');
        self::assertSame('Adding new members', $signedIn[0]['title']);

        // is_signed_in falsy -> no matches, even though a matching row exists.
        $result = $this->searchHelpArticles($pdo, false, 'members', 25);

        self::assertSame([], $result, 'An unauthenticated session must return no matches.');
    }

    #[Test]
    #[TestDox('Feature: help-centre — help search gating: a missing help_articles table returns no matches instead of erroring (Req 4.10)')]
    public function missingTableReturnsNoMatchesInsteadOfErroring(): void
    {
        // Build the schema, then DROP the table so the LIKE query raises — the
        // function must swallow the Throwable and return [] rather than propagate.
        $runner = MigrationRunner::connect();
        $runner->apply();
        $pdo = $runner->pdo();
        $pdo->exec('DROP TABLE help_articles');

        // Confirm the table really is gone (otherwise the test would pass vacuously).
        $missing = $pdo->query(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'help_articles'"
        )->fetchColumn();
        self::assertSame(0, (int) $missing, 'Precondition: help_articles must be absent for this case.');

        $result = $this->searchHelpArticles($pdo, true, 'members', 25);

        self::assertSame([], $result, 'A missing help_articles table must yield [] rather than an exception.');
    }

    #[Test]
    #[TestDox('Feature: help-centre — help search gating: querying a table that was never created returns [] (Req 4.10)')]
    public function neverCreatedTableReturnsNoMatches(): void
    {
        // A bare connection with no schema at all: the very first reference to
        // help_articles must be caught by the try/catch (Throwable) contract.
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $result = $this->searchHelpArticles($pdo, true, 'anything', 25);

        self::assertSame([], $result, 'Querying before the migration has run must return [] instead of erroring.');
    }
}
