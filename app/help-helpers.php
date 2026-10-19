<?php

declare(strict_types=1);

/**
 * Data-access helpers for the Help Centre module (`/help-centre.php`).
 *
 * These functions back the Help_Centre_Index (the topic-grouped listing of
 * published articles) and the Article_Viewer (a single published article by
 * slug). They read from the `help_articles` table created by migration
 * `database/migrations/005_help_articles.sql`.
 *
 * Every article's `body` column holds the Rich_Body as a JSON array of ordered
 * content blocks. These helpers decode that JSON into a PHP array so callers
 * (and the renderer added in a later task) can walk the blocks directly.
 *
 * Conventions mirror app/search-helpers.php:
 *   - the shared db() PDO helper from config.php,
 *   - prepared statements with PDO::FETCH_ASSOC,
 *   - try/catch (Throwable) returning an empty/null result so the module
 *     degrades gracefully before the migration has been applied.
 */

require_once __DIR__ . '/../config.php';

/**
 * Decode a stored Rich_Body JSON string into an ordered block array.
 *
 * A non-array result (null, scalar, corrupt or empty JSON) yields an empty
 * array so the article renders with no body rather than throwing.
 *
 * @return array<int, mixed>
 */
function help_decode_body(?string $json): array
{
    if ($json === null || $json === '') {
        return [];
    }

    $decoded = json_decode($json, true);

    return is_array($decoded) ? $decoded : [];
}

/**
 * All published articles, ordered for the topic-grouped index.
 *
 * Ordered by topic, then the per-topic sort_order, then title as a stable
 * tiebreaker. Each row's `body` is decoded from JSON into an array.
 *
 * Returns [] when the table is absent (before the migration is applied).
 *
 * @return array<int, array<string, mixed>>
 */
function help_published_articles(): array
{
    $sql = "
        SELECT id, slug, title, summary, keywords, body, topic, status, sort_order
        FROM help_articles
        WHERE status = 'published'
        ORDER BY topic ASC, sort_order ASC, title ASC";

    try {
        $stmt = db()->prepare($sql);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }

    $out = [];

    foreach ($rows as $row) {
        $row['body'] = help_decode_body(isset($row['body']) ? (string) $row['body'] : null);
        $out[] = $row;
    }

    return $out;
}

/**
 * Group article rows by their `topic`, preserving the incoming order.
 *
 * The caller passes rows already ordered by help_published_articles(), so the
 * order of topics (first appearance) and the order of articles within each
 * topic both follow the SQL ordering.
 *
 * @param array<int, array<string, mixed>> $rows
 * @return array<string, array<int, array<string, mixed>>>
 */
function help_group_by_topic(array $rows): array
{
    $grouped = [];

    foreach ($rows as $row) {
        $topic = (string) ($row['topic'] ?? '');
        $grouped[$topic][] = $row;
    }

    return $grouped;
}

/**
 * A single published article by slug, or null if missing or a draft.
 *
 * A draft or unknown slug returns null, which drives the viewer's not-found
 * state. The `body` is decoded from JSON into an array. Returns null when the
 * table is absent.
 *
 * @return array<string, mixed>|null
 */
function help_find_published_article(string $slug): ?array
{
    $sql = "
        SELECT id, slug, title, summary, keywords, body, topic, status, sort_order
        FROM help_articles
        WHERE slug = ? AND status = 'published'
        LIMIT 1";

    try {
        $stmt = db()->prepare($sql);
        $stmt->execute([$slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return null;
    }

    if (!is_array($row)) {
        return null;
    }

    $row['body'] = help_decode_body(isset($row['body']) ? (string) $row['body'] : null);

    return $row;
}

/* ===========================================================================
 * Admin data-access + authoring helpers
 *
 * These back the District-admin Help Centre editor (`/help-admin.php` and
 * `/help-admin-article.php`). Unlike the public read helpers above, they see
 * drafts as well as published articles, and they write to the store.
 *
 * Bodies are authored in a simple, forgiving plain-text format (see
 * help_parse_body_source()) rather than raw JSON, then stored as the same
 * Rich_Body JSON the renderer already consumes.
 * ===========================================================================
 */

/**
 * All articles (published AND draft) for the admin listing.
 *
 * Ordered by topic, per-topic sort_order, then title. Each row's `body` is
 * decoded. Returns [] when the table is absent.
 *
 * @return array<int, array<string, mixed>>
 */
function help_all_articles(): array
{
    $sql = "
        SELECT id, slug, title, summary, keywords, body, topic, status, sort_order, updated_at
        FROM help_articles
        ORDER BY topic ASC, sort_order ASC, title ASC";

    try {
        $stmt = db()->prepare($sql);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }

    $out = [];

    foreach ($rows as $row) {
        $row['body'] = help_decode_body(isset($row['body']) ? (string) $row['body'] : null);
        $out[] = $row;
    }

    return $out;
}

/**
 * A single article by id, regardless of status (for the editor). Null if
 * missing or the table is absent.
 *
 * @return array<string, mixed>|null
 */
function help_find_article_by_id(int $id): ?array
{
    $sql = "
        SELECT id, slug, title, summary, keywords, body, topic, status, sort_order
        FROM help_articles
        WHERE id = ?
        LIMIT 1";

    try {
        $stmt = db()->prepare($sql);
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return null;
    }

    if (!is_array($row)) {
        return null;
    }

    $row['body'] = help_decode_body(isset($row['body']) ? (string) $row['body'] : null);

    return $row;
}

/**
 * Turn arbitrary text into a URL-safe slug (lowercase, dash-separated).
 */
function help_slugify(string $value): string
{
    $value = trim($value);
    $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    $value = trim($value, '-');

    return $value !== '' ? $value : 'article';
}

/**
 * A slug that is unique within help_articles, ignoring the article being
 * edited ($ignoreId). Appends -2, -3, … on collision.
 */
function help_unique_slug(string $desired, int $ignoreId = 0): string
{
    $base = help_slugify($desired);
    $slug = $base;
    $i = 2;

    while (true) {
        try {
            $stmt = db()->prepare("
                SELECT id FROM help_articles WHERE slug = ? AND id <> ? LIMIT 1
            ");
            $stmt->execute([$slug, $ignoreId]);
        } catch (Throwable $e) {
            // If we cannot check, fall back to the base slug rather than loop.
            return $base;
        }

        if (!$stmt->fetchColumn()) {
            return $slug;
        }

        $slug = $base . '-' . $i;
        $i++;
    }
}

/**
 * Parse the admin body-authoring text into Rich_Body blocks.
 *
 * The format is line-oriented and deliberately simple so admins never touch
 * JSON. Blocks are separated by a blank line; the first line of a block may
 * carry a marker:
 *
 *   ## Heading text            -> heading (level 2; ### => 3, and so on)
 *   - step one                 -> steps block (every "- " line is one step)
 *   - step two
 *   [Button label](https://…)  -> action block (one action per "[…](…)" line;
 *   [Another](/internal.php)      an http(s):// URL is marked external)
 *   Plain text                 -> paragraph
 *
 * Consecutive "- " lines group into one ordered list; consecutive link lines
 * group into one action block. Anything else becomes a paragraph. Unknown or
 * empty input yields an empty block array.
 *
 * @return array<int, array<string, mixed>>
 */
function help_parse_body_source(string $source): array
{
    $source = str_replace(["\r\n", "\r"], "\n", $source);
    $chunks = preg_split('/\n[ \t]*\n/', trim($source)) ?: [];

    $blocks = [];

    foreach ($chunks as $chunk) {
        $chunk = trim($chunk);
        if ($chunk === '') {
            continue;
        }

        $lines = array_values(array_filter(
            array_map('trim', explode("\n", $chunk)),
            static fn(string $l): bool => $l !== ''
        ));

        if (!$lines) {
            continue;
        }

        // Heading: a block whose first line starts with one or more '#'.
        if (preg_match('/^(#{1,5})\s+(.*)$/', $lines[0], $m)) {
            $level = min(6, max(2, strlen($m[1]) + 1)); // '#' => h2, '##' => h3…
            $blocks[] = [
                'type' => 'heading',
                'level' => $level,
                'text' => $m[2],
            ];
            continue;
        }

        // Steps: every line is a "- " bullet.
        $allBullets = true;
        foreach ($lines as $l) {
            if (!preg_match('/^[-*]\s+/', $l)) {
                $allBullets = false;
                break;
            }
        }

        if ($allBullets) {
            $items = array_map(
                static fn(string $l): string => preg_replace('/^[-*]\s+/', '', $l) ?? $l,
                $lines
            );
            $blocks[] = ['type' => 'steps', 'items' => array_values($items)];
            continue;
        }

        // Actions: every line is a "[label](url)" link.
        $allLinks = true;
        $actions = [];
        foreach ($lines as $l) {
            if (preg_match('/^\[(.+?)\]\((\S+)\)$/', $l, $lm)) {
                $url = $lm[2];
                $actions[] = [
                    'label' => $lm[1],
                    'url' => $url,
                    'external' => (bool) preg_match('#^https?://#i', $url),
                    'style' => $actions === [] ? 'primary' : 'secondary',
                ];
            } else {
                $allLinks = false;
                break;
            }
        }

        if ($allLinks && $actions) {
            $blocks[] = ['type' => 'action', 'actions' => $actions];
            continue;
        }

        // Fallback: a paragraph (join wrapped lines with spaces).
        $blocks[] = ['type' => 'paragraph', 'text' => implode(' ', $lines)];
    }

    return $blocks;
}

/**
 * Turn stored Rich_Body blocks back into the plain-text authoring format, so
 * the editor can round-trip an existing article. Inverse of
 * help_parse_body_source() for the block types it produces.
 *
 * @param array<int, mixed> $blocks
 */
function help_body_to_source(array $blocks): string
{
    $parts = [];

    foreach ($blocks as $block) {
        if (!is_array($block) || !isset($block['type'])) {
            continue;
        }

        switch ((string) $block['type']) {
            case 'heading':
                $level = (int) ($block['level'] ?? 2);
                $hashes = str_repeat('#', max(1, min(5, $level - 1)));
                $parts[] = $hashes . ' ' . (string) ($block['text'] ?? '');
                break;

            case 'steps':
                $items = is_array($block['items'] ?? null) ? $block['items'] : [];
                $lines = array_map(static fn($i): string => '- ' . (string) $i, $items);
                $parts[] = implode("\n", $lines);
                break;

            case 'action':
                $actions = is_array($block['actions'] ?? null) ? $block['actions'] : [];
                $lines = [];
                foreach ($actions as $a) {
                    if (!is_array($a)) {
                        continue;
                    }
                    $lines[] = '[' . (string) ($a['label'] ?? '') . '](' . (string) ($a['url'] ?? '') . ')';
                }
                $parts[] = implode("\n", $lines);
                break;

            case 'paragraph':
            default:
                $parts[] = (string) ($block['text'] ?? '');
                break;
        }
    }

    return implode("\n\n", $parts);
}

/**
 * Create a new article from already-validated field values.
 *
 * Expects: slug (unique), title, summary, keywords, topic, status
 * ('draft'|'published'), sort_order (int) and body (Rich_Body array).
 * Returns the new article id.
 *
 * @param array<string, mixed> $fields
 */
function help_create_article(array $fields): int
{
    $stmt = db()->prepare("
        INSERT INTO help_articles
            (slug, title, summary, keywords, body, topic, status, sort_order)
        VALUES
            (:slug, :title, :summary, :keywords, :body, :topic, :status, :sort_order)
    ");

    $stmt->execute([
        'slug' => (string) $fields['slug'],
        'title' => (string) $fields['title'],
        'summary' => (string) $fields['summary'],
        'keywords' => (string) $fields['keywords'],
        'body' => json_encode($fields['body'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'topic' => (string) $fields['topic'],
        'status' => (string) $fields['status'],
        'sort_order' => (int) $fields['sort_order'],
    ]);

    return (int) db()->lastInsertId();
}

/**
 * Update an existing article by id from already-validated field values.
 * Same field set as help_create_article().
 *
 * @param array<string, mixed> $fields
 */
function help_update_article(int $id, array $fields): void
{
    $stmt = db()->prepare("
        UPDATE help_articles
        SET slug = :slug,
            title = :title,
            summary = :summary,
            keywords = :keywords,
            body = :body,
            topic = :topic,
            status = :status,
            sort_order = :sort_order
        WHERE id = :id
    ");

    $stmt->execute([
        'slug' => (string) $fields['slug'],
        'title' => (string) $fields['title'],
        'summary' => (string) $fields['summary'],
        'keywords' => (string) $fields['keywords'],
        'body' => json_encode($fields['body'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'topic' => (string) $fields['topic'],
        'status' => (string) $fields['status'],
        'sort_order' => (int) $fields['sort_order'],
        'id' => $id,
    ]);
}

/**
 * Delete an article by id.
 */
function help_delete_article(int $id): void
{
    $stmt = db()->prepare("DELETE FROM help_articles WHERE id = ?");
    $stmt->execute([$id]);
}

/**
 * Render a stored Rich_Body (an ordered array of content blocks) to HTML.
 *
 * Blocks are rendered in their stored order. Each block is dispatched on its
 * `type`; non-array blocks, blocks with no `type`, and unknown types are
 * skipped defensively so malformed stored content never breaks the page.
 *
 * Heading-level state is local to each call: a running "previous heading
 * level" is passed by reference into help_render_heading() so headings never
 * skip levels and always sit below the article's <h1> title. Because the state
 * is initialised here, every call to help_render_body() tracks its own levels
 * independently (the first heading starts at level 2).
 *
 * Every stored value rendered here is escaped by the per-block helpers with
 * htmlspecialchars(..., ENT_QUOTES, 'UTF-8').
 *
 * @param array<int, mixed> $blocks
 */
function help_render_body(array $blocks): string
{
    $html = '';
    $prevLevel = 1; // the <h1> title sits above the body; first heading -> 2

    foreach ($blocks as $block) {
        if (!is_array($block) || !isset($block['type'])) {
            continue; // defensive skip
        }

        $html .= match ((string) $block['type']) {
            'paragraph' => help_render_paragraph($block),
            'heading'   => help_render_heading($block, $prevLevel),
            'steps'     => help_render_steps($block),
            'action'    => help_render_actions($block),
            default     => '', // unknown type skipped
        };
    }

    return $html;
}

/**
 * Render a paragraph block as a single escaped <p>.
 *
 * @param array<string, mixed> $block
 */
function help_render_paragraph(array $block): string
{
    $text = htmlspecialchars((string) ($block['text'] ?? ''), ENT_QUOTES, 'UTF-8');

    return '<p>' . $text . '</p>';
}

/**
 * Render a heading block as an escaped <h2>..<h6>.
 *
 * The requested `level` is clamped so it is always at least 2 (headings sit
 * below the article's <h1> title) and at most one greater than the previously
 * rendered heading level, so levels never skip. The resolved level is written
 * back through $prevLevel so the next heading in the same body continues the
 * sequence.
 *
 * @param array<string, mixed> $block
 * @param int                  $prevLevel running previous heading level (by ref)
 */
function help_render_heading(array $block, int &$prevLevel): string
{
    $requested = (int) ($block['level'] ?? 2);

    // Clamp into the 2..6 band, then forbid skipping more than one level above
    // the previously rendered heading.
    $level = max(2, min(6, $requested));
    $level = min($level, $prevLevel + 1);
    $level = max(2, $level);

    $prevLevel = $level;

    $text = htmlspecialchars((string) ($block['text'] ?? ''), ENT_QUOTES, 'UTF-8');

    return '<h' . $level . '>' . $text . '</h' . $level . '>';
}

/**
 * Render a steps block as one ordered list with one escaped <li> per item.
 *
 * Items render in their stored order. A missing or non-array `items` value
 * yields an empty <ol>.
 *
 * @param array<string, mixed> $block
 */
function help_render_steps(array $block): string
{
    $items = $block['items'] ?? [];
    if (!is_array($items)) {
        $items = [];
    }

    $html = '<ol>';
    foreach ($items as $item) {
        $html .= '<li>' . htmlspecialchars((string) $item, ENT_QUOTES, 'UTF-8') . '</li>';
    }
    $html .= '</ol>';

    return $html;
}

/**
 * Render an action block as one or more labelled links.
 *
 * Each entry in the block's `actions` array is rendered via
 * help_render_action_link(), in stored order. A missing or non-array
 * `actions` value yields no output.
 *
 * @param array<string, mixed> $block
 */
function help_render_actions(array $block): string
{
    $actions = $block['actions'] ?? [];
    if (!is_array($actions)) {
        return '';
    }

    $html = '';
    foreach ($actions as $action) {
        if (is_array($action)) {
            $html .= help_render_action_link($action);
        }
    }

    return $html;
}

/**
 * Render a single action as a labelled <a>.
 *
 * The action's `label` is always used as the visible link text — never the
 * bare URL — so links read descriptively. Both `label` and `url` are escaped.
 * Actions missing either a `label` or a `url` are skipped (empty string).
 *
 * `style` defaults to `primary` and only `primary`|`secondary` are allowed,
 * mapping to the lt-help-action--primary / lt-help-action--secondary classes.
 *
 * When the action is marked external (`!empty($a['external'])`), the link opens
 * in a new browsing context with `target="_blank" rel="noopener noreferrer"`
 * and carries a new-tab affordance: a visible `aria-hidden` icon plus
 * visually-hidden "(opens in a new tab)" text so assistive technology conveys
 * the behaviour. The link stays a native, keyboard-reachable <a> element.
 *
 * @param array<string, mixed> $a
 */
function help_render_action_link(array $a): string
{
    $rawLabel = (string) ($a['label'] ?? '');
    $rawUrl   = (string) ($a['url'] ?? '');

    if ($rawLabel === '' || $rawUrl === '') {
        return '';
    }

    $label = htmlspecialchars($rawLabel, ENT_QUOTES, 'UTF-8');
    $url   = htmlspecialchars($rawUrl, ENT_QUOTES, 'UTF-8');

    $style = in_array($a['style'] ?? '', ['primary', 'secondary'], true)
        ? (string) $a['style']
        : 'primary';

    $external = !empty($a['external']);

    $rel  = $external ? ' target="_blank" rel="noopener noreferrer"' : '';
    $hint = $external
        ? ' <span class="lt-help-ext" aria-hidden="true">↗</span><span class="visually-hidden"> (opens in a new tab)</span>'
        : '';

    return '<a class="lt-help-action lt-help-action--' . $style . '" href="' . $url . '"' . $rel . '>'
         . $label . $hint . '</a>';
}
