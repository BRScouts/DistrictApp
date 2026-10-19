# Implementation Plan: Help Centre

## Overview

Build the Help Centre as a top-level application-root module. Work proceeds bottom-up: the migration and seed data first, then the data-access helpers, then the pure Rich_Body renderer, then the `/help-centre.php` page that wires those together, then global-search integration, and finally the supporting CSS. Each step builds on the previous one and ends integrated into the running module — no orphaned code. Testing is attached as sub-tasks next to the code it validates: property tests for the renderer, search and query logic, and example tests for migration idempotence and the not-found path.

The design uses concrete PHP throughout, so no implementation-language choice is needed — all tasks target PHP + PDO + MySQL/MariaDB, matching the existing codebase conventions in `app/search-helpers.php`, `database/migrations/004_district_events.sql`, and `technical-support.php`.

## Tasks

- [x] 1. Create the Help_Article_Store migration with schema and seed content
  - [x] 1.1 Create the `help_articles` table schema in `database/migrations/005_help_articles.sql`
    - Create `database/migrations/005_help_articles.sql` mirroring the structure of `004_district_events.sql`
    - Use `CREATE TABLE IF NOT EXISTS help_articles` with columns: `id BIGINT UNSIGNED AUTO_INCREMENT PK`, `slug VARCHAR(160) NOT NULL`, `title VARCHAR(255) NOT NULL`, `summary VARCHAR(500) NOT NULL DEFAULT ''`, `keywords VARCHAR(500) NOT NULL DEFAULT ''`, `body JSON NOT NULL`, `topic VARCHAR(120) NOT NULL DEFAULT ''`, `status ENUM('draft','published') NOT NULL DEFAULT 'draft'`, `sort_order INT NOT NULL DEFAULT 0`, `created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP`, `updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`
    - Add `UNIQUE KEY uq_help_articles_slug (slug)` and `KEY idx_help_articles_status_topic (status, topic, sort_order)`
    - Use `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`
    - _Requirements: 1.1, 1.2, 1.3, 1.4, 1.5, 1.6, 1.7, 1.8, 1.9, 1.10, 1.11, 1.12_

  - [x] 1.2 Author and seed the 9 explicit-topic articles (idempotent inserts)
    - Append one `INSERT INTO help_articles (...) SELECT ... FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM help_articles WHERE slug = '...')` per article
    - Seed slugs 1–9 from the design's Seed Content Plan: `adding-new-members` → `/dc/add-user.php`; `adding-an-event` → `/dc/add-event.php`; `searching-risk-assessments` → `/dc/risk-assessments.php` + `/dc/download-risk-assessment.php`; `editing-events` → `/dc/manage-event.php` + `/dc/event.php`; `approving-events` → `/dc/reviewer/`; `updating-your-profile` → `/profile.php`; `creating-a-district-email` → `/group-manager.php`; `using-a-shared-mailbox` → `https://outlook.cloud.microsoft/` (external); `why-a-shared-mailbox` → `https://outlook.cloud.microsoft/` (external)
    - Each `body` is a JSON array of ordered blocks (`paragraph`, `heading`, `steps`, `action`) with full step-by-step instructions for page-based tasks and an `action` block to the real target
    - Mark every Microsoft 365 action `"external": true`; set `status='published'`, a meaningful `topic`, populated `keywords`, and a `sort_order`
    - _Requirements: 5.1, 5.3, 5.4, 5.5, 5.6, 5.7, 5.8, 5.9_

  - [x] 1.3 Author and seed the 5 additional articles (idempotent inserts)
    - Append slug-guarded `INSERT ... SELECT ... WHERE NOT EXISTS` statements for slugs 10–14: `navigating-the-calendar` → `/dc/event.php`; `finding-a-glv` → `/group-manager.php`; `requesting-support` → `/technical-support.php`; `access-levels-and-roles` → `/group-manager.php`; `logging-in-with-microsoft` → `https://outlook.cloud.microsoft/` (external)
    - Same rich-JSON body rules as 1.2: step blocks where page-based, action block to the real target, external flag on the Microsoft 365 link, published status, topic, keywords, sort_order
    - _Requirements: 5.2, 5.3, 5.4, 5.5, 5.6, 5.7, 5.8, 5.9_

  - [x] 1.4 Write example tests for migration idempotence and seed content
    - Apply the migration twice; assert the seed count is stable after the second run (no duplicates, no error on existing objects)
    - Assert a duplicate-slug insert is rejected by the unique constraint
    - Assert all 14 expected articles exist with `status='published'`, their expected topics, and their expected action targets
    - _Requirements: 1.13, 5.9_

- [x] 2. Implement data-access helpers in `app/help-helpers.php`
  - [x] 2.1 Implement `help_published_articles()`, `help_group_by_topic()`, and `help_find_published_article()`
    - Create `app/help-helpers.php`
    - `help_published_articles(): array` — prepared `SELECT ... WHERE status='published' ORDER BY topic ASC, sort_order ASC, title ASC`; `json_decode($row['body'], true)` each body (non-array result → empty array); wrap in `try/catch (Throwable)` returning `[]` when the table is absent
    - `help_group_by_topic(array $rows): array` — group rows keyed by `topic`, preserving the SQL ordering within each topic
    - `help_find_published_article(string $slug): ?array` — prepared `SELECT ... WHERE slug = ? AND status = 'published' LIMIT 1`; decode `body`; return `null` for a draft or unknown slug; `try/catch (Throwable)` → `null`
    - _Requirements: 2.4, 2.5, 2.6, 2.8, 3.1, 3.7, 3.8_

  - [x] 2.2 Write property test for published-only index listing
    - **Property 2: Index lists exactly the published articles**
    - **Validates: Requirements 2.4, 2.8**

  - [x] 2.3 Write property test for topic ordering stability
    - **Property 3: Topic ordering is stable**
    - **Validates: Requirements 2.6**

  - [x] 2.4 Write property and example tests for published-only viewer lookup
    - **Property 9: Only published articles are viewable**
    - Example test: a draft slug and an unknown slug both return `null` (drives the not-found state)
    - **Validates: Requirements 3.1, 3.7, 3.8**

- [ ] 3. Implement the Rich_Body renderer and per-block helpers
  - [x] 3.1 Implement `help_render_body()` and block helpers in `app/help-helpers.php`
    - `help_render_body(array $blocks): string` — iterate blocks, defensively skip non-array/typeless blocks, dispatch on `type` via `match`, skip unknown types; render blocks in stored order
    - `help_render_paragraph()` → `<p>` with `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`
    - `help_render_heading()` → clamp `level` to 2–6 and to at most one greater than the previously rendered heading level (no skips, always below the `<h1>` title); escape text
    - `help_render_steps()` → one `<ol>` with one `<li>` per item, in item order, escaped
    - `help_render_actions()` / `help_render_action_link()` → labelled `<a>` using the action `label` as visible text (never the bare URL); escape `label` and `url`; default `style` to `primary`; skip actions missing `label` or `url`
    - _Requirements: 3.2, 3.3, 3.4, 3.6, 6.1, 6.2, 6.3, 6.4_

  - [x] 3.2 Implement external-link handling in `help_render_action_link()`
    - When `external` is truthy: add `target="_blank" rel="noopener noreferrer"` and an assistive-technology-conveyed "(opens in a new tab)" affordance (visible icon `aria-hidden` plus visually-hidden text)
    - Keep links as native `<a>` elements so they stay keyboard-reachable and operable
    - _Requirements: 3.5, 6.5, 6.6_

  - [-] 3.3 Write property test for escaping of stored content
    - **Property 1: Stored content is always escaped**
    - **Validates: Requirements 3.6**

  - [-] 3.4 Write property test for block render order
    - **Property 4: Blocks render in stored order**
    - **Validates: Requirements 3.2**

  - [-] 3.5 Write property test for steps rendering as an ordered list
    - **Property 5: Steps render as an ordered list**
    - **Validates: Requirements 3.3, 6.4**

  - [-] 3.6 Write property test for heading-level discipline
    - **Property 6: Heading levels never skip and stay below the title**
    - **Validates: Requirements 6.1, 6.2**

  - [-] 3.7 Write property test for safe external-action rendering
    - **Property 7: External actions open safely in a new context**
    - **Validates: Requirements 3.5, 6.6**

  - [~] 3.8 Write property test for descriptive action link text
    - **Property 8: Action links use descriptive text**
    - **Validates: Requirements 3.4, 6.3**

- [~] 4. Checkpoint - Ensure all tests pass
  - Ensure all data-access and renderer tests pass, ask the user if questions arise.

- [ ] 5. Build the `/help-centre.php` page
  - [~] 5.1 Scaffold the page: bootstrap, auth, routing, and App_Root_Layout
    - Create `/help-centre.php` with `declare(strict_types=1)`, `require_once __DIR__ . '/app/bootstrap.php'`, then `require_login()` (redirects when no session)
    - `require_once` the `app/help-helpers.php` module from task 2/3
    - Read `$slug` from `$_GET['slug']`; set `$pageTitle`, `$heroTitle`, `$heroText`, `$breadcrumb`
    - Branch into index mode (no slug) vs viewer mode (slug present); render between `include __DIR__ . '/header.php'` and `include __DIR__ . '/footer.php'` — do NOT use `dc/layout.php`
    - _Requirements: 2.1, 2.2, 2.3_

  - [~] 5.2 Render index mode (grouped by topic)
    - Call `help_published_articles()` then `help_group_by_topic()`; render each Topic as a heading with its articles beneath
    - For each article render its `title`, `summary`, and a link to `/help-centre.php?slug={slug}` (escaped)
    - When no published article exists, render a "no help articles are available" message
    - _Requirements: 2.4, 2.5, 2.6, 2.7, 2.8, 2.9_

  - [~] 5.3 Render viewer mode and not-found state
    - Call `help_find_published_article($slug)`; when found, render the `title` as the page's top-level `<h1>` and the Rich_Body via `help_render_body()`, plus a link back to the Help_Centre_Index
    - When `null` (missing or draft), render the not-found state with explanatory text and a link back to the index, inside the normal layout
    - _Requirements: 3.1, 3.2, 3.7, 3.8, 3.9, 6.1_

- [ ] 6. Integrate Help articles into global search in `app/search-helpers.php`
  - [~] 6.1 Add the `help` category to `search_categories()`
    - Add `'help' => 'Help'` to the array returned by `search_categories()`
    - _Requirements: 4.1_

  - [~] 6.2 Implement `search_help_articles($query, $limit)`
    - Shape after `search_risk_assessments()`: return `[]` on empty query; return `[]` when `dc_context(false)['is_signed_in']` is falsy
    - Prepared LIKE query matching `title`, `summary`, `keywords`; `WHERE status='published'`; `ORDER BY title ASC`; `LIMIT (int) $limit`
    - `try/catch (Throwable)` returning `[]` when the table is missing
    - Map each row to a Search_Result_Row: `category='help'`, `badge='Help'`, `title`, `subtitle=summary`, `url='/help-centre.php?slug=' . rawurlencode($slug)`, `external=false`
    - _Requirements: 4.2, 4.3, 4.4, 4.6, 4.7, 4.8, 4.9, 4.10_

  - [~] 6.3 Wire the `help` category into `search_run()`
    - Add `'help' => search_help_articles($query, $perCategory)` to the `match()` expression in `search_run()`
    - _Requirements: 4.5_

  - [~] 6.4 Write property test for help search result shape and published-only filtering
    - **Property 10: Help search rows are well-formed and published-only**
    - **Validates: Requirements 4.3, 4.4, 4.8**

  - [~] 6.5 Write property test for the search result limit
    - **Property 11: Help search respects the result limit**
    - **Validates: Requirements 4.6**

  - [~] 6.6 Write example/edge tests for search gating
    - Empty query returns no matches; no authenticated session returns no matches; missing table returns no matches instead of erroring
    - **Validates: Requirements 4.7, 4.9, 4.10**

- [ ] 7. Add Help Centre styling to `assets/css/leader-tool.css`
  - [~] 7.1 Style the action buttons and help layout
    - Add styles for `.lt-help-action`, `.lt-help-action--primary`, `.lt-help-action--secondary`, the external `.lt-help-ext` icon, and a `.visually-hidden` utility if not already present
    - Add layout styles for the index topic groups and the article viewer content blocks (paragraphs, headings, ordered step lists)
    - Ensure focus-visible states keep links/buttons keyboard-operable and visible
    - _Requirements: 6.5_

- [~] 8. Final checkpoint - Ensure all tests pass
  - Ensure all tests pass and the Help Centre renders (index, viewer, not-found) and appears in search; ask the user if questions arise.

## Notes

- Tasks marked with `*` are optional (tests) and can be skipped for a faster MVP, but they validate the design's correctness properties and are recommended.
- Each task references specific requirements (granular sub-clauses) and, where applicable, the correctness property it exercises, for full traceability.
- Property tests run a minimum of 100 iterations and are tagged `Feature: help-centre, Property {n}: {text}`; migration/table checks are example/smoke tests, not property tests.
- The renderer is a pure function over block arrays, making it the highest-value and cheapest property-test target.
- Data-access helpers and search both degrade gracefully (`try/catch (Throwable)` → `[]`/`null`) so the module behaves sanely before the migration is applied.
- Checkpoints give natural review points after the pure logic (renderer + queries) and after full integration.

## Task Dependency Graph

```json
{
  "waves": [
    { "id": 0, "tasks": ["1.1"] },
    { "id": 1, "tasks": ["1.2", "1.3"] },
    { "id": 2, "tasks": ["1.4", "2.1"] },
    { "id": 3, "tasks": ["2.2", "2.3", "2.4", "3.1"] },
    { "id": 4, "tasks": ["3.2"] },
    { "id": 5, "tasks": ["3.3", "3.4", "3.5", "3.6", "3.7", "3.8", "5.1"] },
    { "id": 6, "tasks": ["5.2", "5.3"] },
    { "id": 7, "tasks": ["6.1"] },
    { "id": 8, "tasks": ["6.2", "6.3"] },
    { "id": 9, "tasks": ["6.4", "6.5", "6.6", "7.1"] }
  ]
}
```
