# Requirements Document

## Introduction

The Help Centre is a top-level module in the District App that gives leaders and volunteers self-service guidance on common tasks. It stores help content in a new database table (`help_articles`), presents an index page that groups articles by topic, and renders individual articles through an article viewer that supports rich content including inline action buttons and links to real pages in the app. The Help Centre is seeded with authored, step-by-step guidance for a defined set of articles plus a set of useful additional articles. Help content is also searchable through the existing global search engine so that help articles surface alongside modules, calendar events, risk assessments and people, with the same permission discipline already applied to those categories.

This document defines the requirements for: the article data model and migration, the Help Centre index page, the article viewer with rich button/link blocks, integration of help content into global search with permission parity, the seed content to be authored, and the accessibility of the rendered rich content.

## Glossary

- **Help_Centre**: The top-level module, served from `/help-centre.php` at the application root, that lists and displays help articles.
- **Help_Centre_Index**: The view rendered by `/help-centre.php` when no article is requested, listing published articles grouped by topic.
- **Article_Viewer**: The view rendered by `/help-centre.php?slug={slug}` that displays a single help article.
- **Help_Article**: A single row in the `help_articles` table representing one piece of help content.
- **Help_Article_Store**: The `help_articles` database table and its migration (`database/migrations/005_help_articles.sql`).
- **Rich_Body**: The structured content of a help article, composed of ordered content blocks that include paragraphs, step lists, and action blocks (buttons/links).
- **Action_Block**: A content block within a Rich_Body that renders one or more labelled links or buttons pointing to a target page (internal or external).
- **Topic**: The category used to group help articles on the Help_Centre_Index (for example "Events", "Email & Microsoft 365", "People & Access").
- **Status**: The publication state of a Help_Article, one of `published` or `draft`, that controls whether the article is visible to users.
- **Slug**: The unique, URL-safe identifier for a Help_Article used in the Article_Viewer URL.
- **Search_Engine**: The global search engine in `app/search-helpers.php` shared by `/search.php` and `/search-results.php`.
- **Search_Result_Row**: The normalised result shape used by the Search_Engine, with keys `category`, `badge`, `title`, `subtitle`, `url` and `external`.
- **Help_Search_Category**: The new `help` search category added to the Search_Engine.
- **Signed_In_User**: A user who has an authenticated session established via `require_login()`.
- **App_Root_Layout**: The application-root page chrome provided by `header.php` and `footer.php` (the same layout used by other root modules such as `/technical-support.php`), as distinct from `dc/layout.php`.

## Requirements

### Requirement 1 — Help Article data model and migration

**User Story:** As a system administrator, I want help content stored in a dedicated database table, so that help articles can be authored, grouped, searched and displayed consistently.

#### Acceptance Criteria

1. THE Help_Article_Store SHALL be created by a new migration file at `database/migrations/005_help_articles.sql`.
2. THE Help_Article_Store SHALL define a numeric primary key column `id` that auto-increments.
3. THE Help_Article_Store SHALL define a `slug` column that is URL-safe and constrained to be unique across all rows.
4. THE Help_Article_Store SHALL define a `title` column that holds the article's display title.
5. THE Help_Article_Store SHALL define a `summary` column that holds a short plain-text description used in listings and search subtitles.
6. THE Help_Article_Store SHALL define a `keywords` column that holds search terms used to match the article in the Search_Engine.
7. THE Help_Article_Store SHALL define a `body` column that stores the Rich_Body as a JSON array of ordered content blocks.
8. THE Help_Article_Store SHALL define a `topic` column that holds the Topic used to group the article on the Help_Centre_Index.
9. THE Help_Article_Store SHALL define a `status` column that holds one of the values `published` or `draft` and defaults to `draft`.
10. THE Help_Article_Store SHALL define a `sort_order` column that holds an integer used to order articles within a Topic.
11. THE Help_Article_Store SHALL define a `created_at` column and an `updated_at` column that record the creation and last-modification timestamps.
12. THE Help_Article_Store SHALL define an index on the `status` and `topic` columns to support grouped listing queries.
13. WHEN the migration runs more than once, THE Help_Article_Store SHALL remain unchanged after the first successful run rather than producing duplicate rows or failing on existing objects.

### Requirement 2 — Help Centre index page

**User Story:** As a signed-in leader, I want a Help Centre landing page that groups articles by topic, so that I can quickly find guidance for the task I am doing.

#### Acceptance Criteria

1. THE Help_Centre SHALL be served from `/help-centre.php` at the application root.
2. THE Help_Centre SHALL render using the App_Root_Layout and SHALL NOT use `dc/layout.php`.
3. IF a request for the Help_Centre is made without an authenticated session, THEN THE Help_Centre SHALL redirect the request to the sign-in flow before rendering article content.
4. WHEN a Signed_In_User opens `/help-centre.php` without a `slug` parameter, THE Help_Centre_Index SHALL list every Help_Article whose `status` is `published`.
5. WHEN the Help_Centre_Index lists published articles, THE Help_Centre_Index SHALL group the articles under their Topic headings.
6. WHEN the Help_Centre_Index lists articles within a Topic, THE Help_Centre_Index SHALL order the articles by their `sort_order` value ascending and then by `title` ascending.
7. WHEN the Help_Centre_Index renders a Help_Article entry, THE Help_Centre_Index SHALL display the article's `title`, its `summary`, and a link to the Article_Viewer URL for that article's `slug`.
8. THE Help_Centre SHALL exclude every Help_Article whose `status` is `draft` from the Help_Centre_Index.
9. IF no Help_Article with `status` equal to `published` exists, THEN THE Help_Centre_Index SHALL display a message stating that no help articles are available.

### Requirement 3 — Article viewer with rich button/link blocks

**User Story:** As a signed-in leader, I want to read a full help article with step-by-step instructions and buttons that take me to the right page, so that I can complete a task without hunting for where to go.

#### Acceptance Criteria

1. WHEN a Signed_In_User opens `/help-centre.php?slug={slug}` and a published Help_Article with a matching `slug` exists, THE Article_Viewer SHALL render that article's `title` and Rich_Body.
2. WHEN the Article_Viewer renders a Rich_Body, THE Article_Viewer SHALL render each content block in the stored order.
3. WHEN the Article_Viewer renders a step-list block, THE Article_Viewer SHALL render the block's steps as an ordered sequence.
4. WHEN the Article_Viewer renders an Action_Block, THE Article_Viewer SHALL render each action as a labelled link pointing to the action's target URL.
5. WHERE an Action_Block action is marked external, THE Article_Viewer SHALL render the link so that it opens in a new browsing context with `rel="noopener noreferrer"`.
6. WHEN the Article_Viewer renders text content that originates from stored article data, THE Article_Viewer SHALL escape the content so that stored values are rendered as text rather than executable markup.
7. IF a request to `/help-centre.php?slug={slug}` references a `slug` that matches no Help_Article, THEN THE Article_Viewer SHALL respond with a not-found state and a link back to the Help_Centre_Index.
8. IF a request to `/help-centre.php?slug={slug}` references a Help_Article whose `status` is `draft`, THEN THE Article_Viewer SHALL respond with a not-found state rather than displaying the article.
9. WHEN the Article_Viewer renders an article, THE Article_Viewer SHALL display a link back to the Help_Centre_Index.

### Requirement 4 — Global search integration with permission parity

**User Story:** As a signed-in leader, I want help articles to appear in the global search, so that I can find guidance from the same search box I use for everything else.

#### Acceptance Criteria

1. THE Search_Engine SHALL include a `help` entry in `search_categories()` mapped to the display badge label `Help`.
2. THE Search_Engine SHALL provide a function `search_help_articles($query, $limit)` that returns Help article matches.
3. WHEN `search_help_articles($query, $limit)` is called with a non-empty query, THE Search_Engine SHALL match the query against each Help_Article's `title`, `summary` and `keywords`.
4. WHEN `search_help_articles($query, $limit)` returns a match, THE Search_Engine SHALL return a Search_Result_Row with `category` equal to `help`, `badge` equal to `Help`, `title` equal to the article `title`, `subtitle` equal to the article `summary`, `url` equal to `/help-centre.php?slug={slug}`, and `external` equal to `false`.
5. WHEN `search_run()` processes the `help` category, THE Search_Engine SHALL dispatch to `search_help_articles($query, $perCategory)` within its `match()` expression.
6. THE Search_Engine SHALL return at most `$limit` Help article matches from `search_help_articles($query, $limit)`.
7. WHEN `search_help_articles($query, $limit)` is called with an empty query, THE Search_Engine SHALL return no Help article matches.
8. THE Search_Engine SHALL restrict Help article matches to articles whose `status` is `published`, mirroring the visibility applied to the Article_Viewer.
9. IF the current request has no authenticated session, THEN THE Search_Engine SHALL return no Help article matches, mirroring the gating applied to the other search categories.
10. IF the Help_Article_Store does not exist at query time, THEN THE Search_Engine SHALL return no Help article matches rather than raising an error.

### Requirement 5 — Seed content authoring

**User Story:** As a leader using the District App for the first time, I want the Help Centre to already contain written guides for common tasks, so that help is available immediately without an administrator authoring content first.

#### Acceptance Criteria

1. THE Help_Article_Store SHALL be seeded with a Help_Article for each of the following explicit topics: adding new members to the District App; adding an event to the calendar; searching risk assessments; editing events; approving events; updating your profile; creating a District email for someone; using a shared mailbox; and why the District uses a shared mailbox.
2. THE Help_Article_Store SHALL additionally be seeded with Help_Article entries covering: viewing and navigating the District Calendar; finding and contacting a Group Lead Volunteer; requesting technical support; understanding access levels and roles; and logging in via Microsoft.
3. WHEN a seeded Help_Article describes a task that is performed on a page in the application, THE Rich_Body of that article SHALL include step-by-step instructions for completing the task.
4. WHEN a seeded Help_Article describes a task performed on a specific page, THE Rich_Body SHALL include an Action_Block linking to that page using one of the real application targets: `/dc/add-user.php`, `/dc/add-event.php`, `/dc/event.php`, `/dc/manage-event.php`, `/dc/download-risk-assessment.php`, `/dc/risk-assessments.php`, `/dc/reviewer/`, `/profile.php`, `/group-manager.php`, or the external address `https://outlook.cloud.microsoft/`.
5. WHERE a seeded Action_Block links to `https://outlook.cloud.microsoft/`, THE Action_Block action SHALL be marked external.
6. THE seeded Help_Article entries SHALL each be assigned a Topic so that the Help_Centre_Index groups them under meaningful headings.
7. THE seeded Help_Article entries SHALL each have `status` set to `published` so that the seeded content is visible on the Help_Centre_Index.
8. THE seeded Help_Article entries SHALL each have `keywords` populated with terms relevant to the article so that the Search_Engine can match the article.
9. THE seed content SHALL be delivered as part of the migration at `database/migrations/005_help_articles.sql`.

### Requirement 6 — Accessibility of rich content

**User Story:** As a leader using assistive technology or a keyboard, I want help articles and their action buttons to be navigable and understandable, so that I can use the guidance regardless of how I access the app.

#### Acceptance Criteria

1. WHEN the Article_Viewer renders an article, THE Article_Viewer SHALL render the article `title` as the page's top-level heading.
2. WHEN the Article_Viewer renders content block headings within a Rich_Body, THE Article_Viewer SHALL render those headings at a heading level below the article title heading and without skipping heading levels.
3. WHEN the Article_Viewer renders an Action_Block action, THE Article_Viewer SHALL render it with a descriptive, human-readable link text rather than a bare URL.
4. WHEN the Article_Viewer renders a step-list block, THE Article_Viewer SHALL render it using an ordered-list element so that assistive technology conveys the sequence and position of each step.
5. WHEN the Article_Viewer renders an interactive link or button, THE Article_Viewer SHALL ensure the element is reachable and operable by keyboard.
6. WHERE an Action_Block action opens in a new browsing context, THE Article_Viewer SHALL convey to assistive technology that the link opens in a new context.
