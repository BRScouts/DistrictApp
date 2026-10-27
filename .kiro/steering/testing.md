---
inclusion: fileMatch
fileMatchPattern: 'tests/**'
---

# Testing

How tests are set up and run in this project.

## Framework & layout

- Tests use PHPUnit (`phpunit/phpunit ^11.5`, dev dependency).
- Test classes live under `tests/` in the `DistrictApp\Tests\` PSR-4 namespace (see `composer.json` `autoload-dev`).
- Name test files `*Test.php` and test classes `SomethingTest` extending `PHPUnit\Framework\TestCase`.

## Running

- Run the suite with:
  ```
  composer test
  ```
  (equivalent to `phpunit --colors=always`). PHPUnit caches results in `.phpunit.cache/`.
- For a single file or filter, run `vendor/bin/phpunit tests/SomeTest.php` or `vendor/bin/phpunit --filter methodName`. Avoid watch mode.

## What to test

- Only add tests when asked, or when fixing a bug / adding a feature where a regression test adds clear value — don't bolt tests onto unrelated changes.
- Favour testing pure logic: validation, parsing/formatting helpers (`dce_parse_datetime`, URL validation, audit detail formatting), and permission decisions.
- Keep DB-dependent code testable by isolating the pure logic from the PDO call where reasonable, rather than standing up a live database in unit tests.
- When fixing a bug, add a test that fails before the fix and passes after.

## Verifying changes

- After changing PHP, run `composer test` before reporting the work as done, and report the result.
- There's no compile step; for syntax safety you can run `php -l path/to/file.php` on files you edited.
