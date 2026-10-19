<?php

declare(strict_types=1);

namespace DistrictApp\Tests\Migration;

use DistrictApp\Tests\Support\HelpSeedExpectations;
use DistrictApp\Tests\Support\MigrationRunner;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Example / smoke tests for migration 005_help_articles.sql.
 *
 * These are not property tests. They verify two concrete behaviours of the
 * real migration file:
 *
 *   - Idempotence: applying the migration twice leaves the seed row count
 *     stable (no duplicates) and raises no error on already-present objects.
 *   - Seed content: all 14 expected articles exist, published, under their
 *     expected topics, linking to their expected action targets, and the
 *     slug UNIQUE constraint rejects a duplicate insert.
 *
 * Requirements: 1.13 (idempotent migration), 5.9 (seed content delivered by
 * the migration).
 *
 * By default these run against an isolated in-memory SQLite database so they
 * need no server. Set HELP_TEST_MYSQL_DSN to run the exact MySQL migration
 * against a real MySQL/MariaDB test database (see MigrationRunner).
 */
#[Group('migration')]
#[Group('help-centre')]
final class HelpArticlesMigrationTest extends TestCase
{
    private MigrationRunner $runner;
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->runner = MigrationRunner::connect();
        $this->pdo = $this->runner->pdo();
    }

    private function seedCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM help_articles')->fetchColumn();
    }

    #[Test]
    #[TestDox('Applying the migration twice keeps the seed count stable (idempotent, no duplicates, no error)')]
    public function migrationIsIdempotent(): void
    {
        // First run creates the table and seeds the 14 articles.
        $this->runner->apply();
        $afterFirst = $this->seedCount();

        self::assertSame(
            HelpSeedExpectations::EXPECTED_COUNT,
            $afterFirst,
            'First migration run should seed exactly the expected number of articles'
        );

        // Second run must not error on the existing table/rows and must not
        // duplicate any seeded article (slug-guarded NOT EXISTS inserts).
        $this->runner->apply();
        $afterSecond = $this->seedCount();

        self::assertSame(
            $afterFirst,
            $afterSecond,
            'Re-running the migration must not add duplicate rows'
        );
    }

    #[Test]
    #[TestDox('No slug is seeded more than once')]
    public function everySlugIsUnique(): void
    {
        $this->runner->apply();
        $this->runner->apply();

        $rows = $this->pdo
            ->query('SELECT slug, COUNT(*) AS n FROM help_articles GROUP BY slug HAVING n > 1')
            ->fetchAll();

        self::assertSame([], $rows, 'Expected no duplicated slugs after running the migration twice');
    }

    #[Test]
    #[TestDox('A duplicate-slug insert is rejected by the unique constraint')]
    public function duplicateSlugInsertIsRejected(): void
    {
        $this->runner->apply();

        $this->expectException(PDOException::class);

        // Attempt a raw insert reusing an already-seeded slug. The UNIQUE
        // constraint on slug must reject it.
        $stmt = $this->pdo->prepare(
            "INSERT INTO help_articles (slug, title, body, topic, status, sort_order)
             VALUES (:slug, :title, :body, :topic, 'published', 99)"
        );
        $stmt->execute([
            'slug'  => 'adding-new-members',
            'title' => 'Duplicate slug attempt',
            'body'  => '[]',
            'topic' => 'People & Access',
        ]);
    }

    #[Test]
    #[TestDox('All 14 expected articles exist, published, with their expected topics')]
    public function allExpectedArticlesArePublishedUnderExpectedTopics(): void
    {
        $this->runner->apply();

        $expected = HelpSeedExpectations::articles();

        self::assertSame(
            HelpSeedExpectations::EXPECTED_COUNT,
            count($expected),
            'Expectations fixture should describe exactly 14 articles'
        );

        $stmt = $this->pdo->query('SELECT slug, topic, status FROM help_articles');
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[$row['slug']] = $row;
        }

        self::assertCount(
            HelpSeedExpectations::EXPECTED_COUNT,
            $rows,
            'Seeded article count should match the expected set'
        );

        foreach ($expected as $slug => $info) {
            self::assertArrayHasKey($slug, $rows, "Expected article '{$slug}' to be seeded");
            self::assertSame(
                'published',
                $rows[$slug]['status'],
                "Article '{$slug}' should be published"
            );
            self::assertSame(
                $info['topic'],
                $rows[$slug]['topic'],
                "Article '{$slug}' should be grouped under its expected topic"
            );
        }
    }

    #[Test]
    #[TestDox('Each article Rich_Body links to its expected action target(s)')]
    public function eachArticleLinksToExpectedActionTargets(): void
    {
        $this->runner->apply();

        $expected = HelpSeedExpectations::articles();
        $external = HelpSeedExpectations::externalTargets();

        $stmt = $this->pdo->query('SELECT slug, body FROM help_articles');
        $bodies = [];
        foreach ($stmt->fetchAll() as $row) {
            $bodies[$row['slug']] = $row['body'];
        }

        foreach ($expected as $slug => $info) {
            self::assertArrayHasKey($slug, $bodies, "Expected article '{$slug}' to be seeded");

            $decoded = json_decode((string) $bodies[$slug], true);
            self::assertIsArray($decoded, "Body of '{$slug}' should be a valid JSON array");

            $actions = HelpSeedExpectations::actionsFromBody($decoded);
            $urls = array_map(static fn (array $a): string => $a['url'], $actions);

            foreach ($info['targets'] as $target) {
                self::assertContains(
                    $target,
                    $urls,
                    "Article '{$slug}' should link to expected target '{$target}'"
                );

                // Microsoft 365 targets must be flagged external.
                if (in_array($target, $external, true)) {
                    foreach ($actions as $action) {
                        if ($action['url'] === $target) {
                            self::assertTrue(
                                $action['external'],
                                "External target '{$target}' in '{$slug}' should be marked external"
                            );
                        }
                    }
                }
            }
        }
    }
}
