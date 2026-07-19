<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$ctx = dc_require_access();
$errors = [];

function dc_ra_int_param(string $key, int $default, int $min, int $max): int
{
    $value = isset($_GET[$key]) ? (int) $_GET[$key] : $default;

    if ($value < $min) {
        return $min;
    }

    if ($value > $max) {
        return $max;
    }

    return $value;
}

function dc_ra_query_with(array $changes): string
{
    $query = $_GET;

    foreach ($changes as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
        } else {
            $query[$key] = $value;
        }
    }

    return '/dc/risk-assessments.php' . ($query ? '?' . http_build_query($query) : '');
}

function dc_ra_column_exists(string $table, string $column): bool
{
    static $cache = [];

    $key = $table . '.' . $column;

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        $stmt = db()->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
              AND COLUMN_NAME = :column_name
        ");
        $stmt->execute([
            'table_name' => $table,
            'column_name' => $column,
        ]);

        return $cache[$key] = ((int) $stmt->fetchColumn()) > 0;
    } catch (Throwable $e) {
        return $cache[$key] = false;
    }
}

function dc_ra_uploaded_date_expr(): string
{
    if (dc_ra_column_exists('risk_assessments', 'uploaded_at')) {
        return 'ra.uploaded_at';
    }

    if (dc_ra_column_exists('risk_assessments', 'created_at')) {
        return 'ra.created_at';
    }

    return 'ra.id';
}

function dc_ra_format_bytes(?int $bytes): string
{
    if (!$bytes || $bytes < 1) {
        return '';
    }

    if ($bytes >= 1024 * 1024) {
        return number_format($bytes / (1024 * 1024), 1) . ' MB';
    }

    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 0) . ' KB';
    }

    return $bytes . ' bytes';
}

function dc_ra_compact_text(?string $value, int $limit = 130): string
{
    $value = trim((string) $value);

    if ($value === '') {
        return '';
    }

    if (function_exists('mb_strlen') && mb_strlen($value) > $limit) {
        return mb_substr($value, 0, $limit - 1) . '…';
    }

    if (strlen($value) > $limit) {
        return substr($value, 0, $limit - 1) . '…';
    }

    return $value;
}

$requestedGroupId = isset($_GET['group_id']) ? (int) $_GET['group_id'] : null;
$selectedGroupId = dc_selected_group_id($requestedGroupId);
$accessibleGroups = dc_accessible_groups();
$showGroupPicker = count($accessibleGroups) > 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();
    $selectedGroupId = dc_selected_group_id((int) ($_POST['group_id'] ?? $selectedGroupId));
    $people = dc_fetch_group_people($selectedGroupId);
    $uploader = $people[0] ?? null;

    if (!$uploader && $ctx['person_id']) {
        $uploader = [
            'full_name' => $ctx['name'],
            'primary_email' => $ctx['email'],
        ];
    }

    try {
        dc_store_risk_assessment_upload(
            $_FILES['risk_file'],
            $selectedGroupId,
            trim((string) ($_POST['title'] ?? '')),
            trim((string) ($_POST['description'] ?? '')) ?: null,
            $uploader['full_name'] ?? 'Unknown leader',
            $uploader['primary_email'] ?? 'unknown@example.invalid',
            $ctx['person_id'],
            $ctx['actor_type'] === 'person' ? 'sso' : 'group_link',
            (string) ($_POST['visibility'] ?? 'district')
        );

        redirect('/dc/risk-assessments.php?uploaded=1&group_id=' . $selectedGroupId);
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

$search = trim((string) ($_GET['q'] ?? ''));
$visibility = (string) ($_GET['visibility'] ?? 'all');
$dateRange = (string) ($_GET['date_range'] ?? 'all');
$sort = (string) ($_GET['sort'] ?? 'newest');
$page = dc_ra_int_param('page', 1, 1, 100000);
$perPage = dc_ra_int_param('per_page', 25, 10, 100);
$offset = ($page - 1) * $perPage;

if (!in_array($visibility, ['all', 'district', 'group'], true)) {
    $visibility = 'all';
}

if (!in_array($dateRange, ['all', '30', '90', '365'], true)) {
    $dateRange = 'all';
}

if (!in_array($sort, ['newest', 'oldest', 'title', 'group'], true)) {
    $sort = 'newest';
}

$allowedGroups = array_values(array_unique(array_map('intval', (array) ($ctx['group_ids'] ?: [0]))));
if (!$allowedGroups) {
    $allowedGroups = [0];
}

$uploadedDateExpr = dc_ra_uploaded_date_expr();
$where = [];
$params = [];

$where[] = "ra.status = 'active'";
$where[] = "ra.admin_review_status = 'available'";
$where[] = "(ra.visibility = 'district' OR ra.group_id IN (" . implode(',', array_fill(0, count($allowedGroups), '?')) . "))";

foreach ($allowedGroups as $allowedGroupId) {
    $params[] = $allowedGroupId;
}

if ($showGroupPicker && $selectedGroupId) {
    $where[] = "(ra.group_id = ? OR ra.visibility = 'district')";
    $params[] = $selectedGroupId;
}

if ($visibility !== 'all') {
    $where[] = "ra.visibility = ?";
    $params[] = $visibility;
}

if ($dateRange !== 'all' && in_array($uploadedDateExpr, ['ra.uploaded_at', 'ra.created_at'], true)) {
    $where[] = "{$uploadedDateExpr} >= DATE_SUB(NOW(), INTERVAL ? DAY)";
    $params[] = (int) $dateRange;
}

if ($search !== '') {
    $where[] = "(
        ra.title LIKE ?
        OR ra.description LIKE ?
        OR ra.original_filename LIKE ?
        OR ra.uploaded_by_name LIKE ?
        OR ra.uploaded_by_email LIKE ?
        OR g.group_name LIKE ?
    )";

    $like = '%' . $search . '%';

    for ($i = 0; $i < 6; $i++) {
        $params[] = $like;
    }
}

$orderBy = match ($sort) {
    'oldest' => "{$uploadedDateExpr} ASC, ra.id ASC",
    'title' => "ra.title ASC, {$uploadedDateExpr} DESC",
    'group' => "g.group_name ASC, {$uploadedDateExpr} DESC",
    default => "{$uploadedDateExpr} DESC, ra.id DESC",
};

$whereSql = implode("\n      AND ", $where);

$countSql = "
    SELECT COUNT(*)
    FROM risk_assessments ra
    JOIN groups g ON g.id = ra.group_id
    WHERE {$whereSql}
";

$countStmt = db()->prepare($countSql);
$countStmt->execute($params);
$totalRisks = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRisks / $perPage));

if ($page > $totalPages) {
    redirect(dc_ra_query_with(['page' => $totalPages]));
}

$sql = "
    SELECT
        ra.*,
        g.group_name,
        {$uploadedDateExpr} AS uploaded_display_at
    FROM risk_assessments ra
    JOIN groups g ON g.id = ra.group_id
    WHERE {$whereSql}
    ORDER BY {$orderBy}
    LIMIT {$perPage}
    OFFSET {$offset}
";

$stmt = db()->prepare($sql);
$stmt->execute($params);
$risks = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Risk assessments';
$heroTitle = 'Risk assessments';
$heroText = 'Search, download and share Group and District risk assessments.';
$active = 'risk';

require __DIR__ . '/layout.php';
?>

<style>
    .dc-risk-layout {
        display: grid;
        gap: 1.5rem;
    }

    .dc-risk-main-panel {
        background: #ffffff;
        border: 1px solid #d8dde3;
        border-top: 8px solid #7413dc;
        box-shadow: 0 2px 0 rgba(16, 24, 32, 0.08);
    }

    .dc-risk-main-panel-inner {
        padding: clamp(1.25rem, 3vw, 2rem);
    }

    .dc-risk-results-head {
        display: grid;
        gap: 1rem;
        margin-bottom: 1.5rem;
    }

    @media (min-width: 760px) {
        .dc-risk-results-head {
            grid-template-columns: minmax(0, 1fr) auto;
            align-items: start;
        }
    }

    .dc-risk-results-head h2 {
        margin: 0;
        color: #101820;
        font-size: clamp(1.65rem, 3vw, 2.35rem);
        font-weight: 900;
        line-height: 1.05;
        letter-spacing: -0.035em;
    }

    .dc-risk-count {
        margin-top: 0.45rem;
        color: #4b5563;
        font-size: 1rem;
        font-weight: 800;
        line-height: 1.35;
    }

    .dc-risk-search {
        margin-bottom: 1.75rem;
        padding: 1.25rem;
        background: #f7f3fc;
        border: 1px solid #d8dde3;
        border-left: 8px solid #00a794;
    }

    .dc-risk-search-grid {
        display: grid;
        gap: 1rem;
    }

    @media (min-width: 768px) {
        .dc-risk-search-grid {
            grid-template-columns: minmax(260px, 2fr) minmax(150px, 1fr) minmax(150px, 1fr);
            align-items: end;
        }

        .dc-risk-search-grid.has-group {
            grid-template-columns:
                minmax(260px, 2fr)
                minmax(170px, 1fr)
                minmax(150px, 1fr)
                minmax(150px, 1fr);
        }

        .dc-risk-search-grid .dc-risk-sort-field {
            grid-column: span 1;
        }
    }

    @media (min-width: 1180px) {
        .dc-risk-search-grid {
            grid-template-columns: minmax(320px, 2.2fr) repeat(3, minmax(150px, 1fr));
        }

        .dc-risk-search-grid.has-group {
            grid-template-columns:
                minmax(320px, 2.2fr)
                minmax(180px, 1fr)
                minmax(150px, 1fr)
                minmax(150px, 1fr)
                minmax(150px, 1fr);
        }
    }

    .dc-risk-search label,
    .dc-risk-per-page label,
    .dc-risk-search-actions label {
        display: block;
        margin-bottom: 0.35rem;
        color: #101820;
        font-weight: 900;
        line-height: 1.2;
    }

    .dc-risk-search .form-control,
    .dc-risk-search-actions .form-control {
        min-height: 46px;
        border: 2px solid #101820;
        border-radius: 0;
        color: #101820;
        font-weight: 700;
    }

    .dc-risk-search .form-control:focus,
    .dc-risk-search-actions .form-control:focus {
        border-color: #101820;
        outline: 3px solid #ffdd00;
        outline-offset: 0;
        box-shadow: 0 0 0 5px #000000;
    }

    .dc-risk-search-actions {
        display: grid;
        gap: 0.75rem;
        margin-top: 1rem;
    }

    @media (min-width: 760px) {
        .dc-risk-search-actions {
            grid-template-columns: auto auto minmax(180px, 1fr);
            align-items: end;
        }
    }

    .dc-risk-button-row {
        display: flex;
        flex-wrap: wrap;
        gap: 0.75rem;
        align-items: center;
    }

    .dc-risk-search-actions .btn,
    .dc-risk-search-actions .lt-btn,
    .dc-risk-download,
    .dc-pagination a,
    .dc-pagination span {
        border-radius: 0;
        font-weight: 900;
    }

    .dc-risk-search-actions .btn,
    .dc-risk-search-actions .lt-btn {
        min-height: 46px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }

    .dc-risk-per-page {
        display: flex;
        justify-content: flex-start;
        align-items: end;
        gap: 0.65rem;
    }

    @media (min-width: 760px) {
        .dc-risk-per-page {
            justify-content: flex-end;
        }
    }

    .dc-risk-per-page label {
        margin-bottom: 0;
        white-space: nowrap;
    }

    .dc-risk-per-page select {
        width: auto;
        min-width: 96px;
    }

    .dc-risk-list {
        display: grid;
        gap: 0.9rem;
    }

    .dc-risk-row {
        display: grid;
        gap: 1rem;
        padding: 1.1rem;
        background: #ffffff;
        border: 1px solid #d8dde3;
        border-left: 8px solid #7413dc;
        box-shadow: 0 2px 0 rgba(16, 24, 32, 0.06);
    }

    @media (min-width: 760px) {
        .dc-risk-row {
            grid-template-columns: minmax(0, 1fr) auto;
            align-items: center;
            padding: 1.25rem;
        }
    }

    .dc-risk-title {
        margin: 0;
        color: #101820;
        font-size: clamp(1.15rem, 2vw, 1.35rem);
        font-weight: 900;
        line-height: 1.18;
        letter-spacing: -0.015em;
    }

    .dc-risk-title a {
        color: #4d0b93;
        text-decoration-thickness: 2px;
        text-underline-offset: 0.16em;
    }

    .dc-risk-title a:hover {
        color: #2f005c;
        text-decoration-thickness: 3px;
    }

    .dc-risk-meta {
        margin: 0.5rem 0 0;
        color: #4b5563;
        font-size: 0.95rem;
        font-weight: 800;
        line-height: 1.45;
    }

    .dc-risk-meta strong {
        color: #101820;
        font-weight: 900;
    }

    .dc-risk-desc {
        max-width: 760px;
        margin: 0.65rem 0 0;
        color: #101820;
        font-size: 0.98rem;
        font-weight: 700;
        line-height: 1.5;
    }

    .dc-risk-badges {
        display: flex;
        flex-wrap: wrap;
        gap: 0.45rem;
        margin-top: 0.8rem;
    }

    .dc-risk-badge {
        display: inline-flex;
        align-items: center;
        min-height: 30px;
        padding: 0.25rem 0.6rem;
        background: #f5f6f8;
        border: 1px solid #d8dde3;
        color: #101820;
        font-size: 0.78rem;
        font-weight: 900;
        line-height: 1.1;
    }

    .dc-risk-badge-district {
        background: #f7f3fc;
        border-color: #7413dc;
        color: #4d0b93;
    }

    .dc-risk-actions {
        display: flex;
        justify-content: flex-start;
    }

    @media (min-width: 760px) {
        .dc-risk-actions {
            justify-content: flex-end;
        }
    }

    .dc-risk-download {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 46px;
        padding: 0.65rem 0.95rem;
        background: #7413dc;
        border: 2px solid #7413dc;
        color: #ffffff;
        box-shadow: 0 4px 0 #000000;
        text-decoration: none;
        line-height: 1.15;
        white-space: nowrap;
    }

    .dc-risk-download:hover,
    .dc-risk-download:focus {
        background: #4d0b93;
        border-color: #4d0b93;
        color: #ffffff;
        text-decoration: none;
    }

    .dc-risk-empty {
        padding: 1.25rem;
        background: #f5f6f8;
        border: 2px dashed #6b7280;
    }

    .dc-risk-empty h3 {
        margin: 0;
        color: #101820;
        font-size: 1.25rem;
        font-weight: 900;
        line-height: 1.2;
    }

    .dc-risk-empty p {
        margin: 0.5rem 0 0;
        color: #101820;
        font-weight: 700;
        line-height: 1.5;
    }

    .dc-pagination {
        display: flex;
        flex-wrap: wrap;
        gap: 0.45rem;
        align-items: center;
        margin-top: 1.5rem;
        padding-top: 1.25rem;
        border-top: 1px solid #d8dde3;
    }

    .dc-pagination a,
    .dc-pagination span {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 42px;
        min-height: 42px;
        padding: 0.4rem 0.7rem;
        border: 2px solid #d8dde3;
        background: #ffffff;
        color: #4d0b93;
        text-decoration: none;
    }

    .dc-pagination a:hover {
        border-color: #7413dc;
        background: #f7f3fc;
        color: #2f005c;
    }

    .dc-pagination .current {
        background: #4d0b93;
        color: #ffffff;
        border-color: #4d0b93;
    }

    .dc-pagination .disabled {
        color: #6b7280;
        background: #f5f6f8;
    }

    .dc-success {
        margin-bottom: 1.25rem;
        padding: 1rem 1.25rem;
        background: #e9f8f4;
        border-left: 8px solid #00a794;
        color: #101820;
        font-weight: 900;
        line-height: 1.45;
    }

    .dc-error-summary {
        margin-bottom: 1.25rem;
        padding: 1rem 1.25rem;
        background: #fff1f0;
        border-left: 8px solid #d4351c;
        color: #101820;
    }

    .dc-error-summary h2 {
        margin: 0 0 0.5rem;
        color: #101820;
        font-size: 1.35rem;
        font-weight: 900;
    }

    .dc-error-summary ul {
        margin: 0;
        padding-left: 1.25rem;
        font-weight: 700;
    }

    @media (max-width: 767.98px) {
        .dc-risk-main-panel-inner {
            padding: 1rem;
        }

        .dc-risk-search {
            padding: 1rem;
        }

        .dc-risk-search-actions {
            gap: 1rem;
        }

        .dc-risk-button-row {
            display: grid;
            grid-template-columns: 1fr;
        }

        .dc-risk-button-row .btn,
        .dc-risk-button-row .lt-btn,
        .dc-risk-download {
            width: 100%;
        }

        .dc-risk-per-page {
            display: grid;
            grid-template-columns: 1fr;
            align-items: start;
        }

        .dc-risk-per-page select {
            width: 100%;
        }

        .dc-risk-row {
            padding: 1rem;
        }
    }

    @media (prefers-reduced-motion: reduce) {
        .dc-risk-layout *,
        .dc-risk-layout *::before,
        .dc-risk-layout *::after {
            scroll-behavior: auto !important;
            transition-duration: 0.001ms !important;
            animation-duration: 0.001ms !important;
            animation-iteration-count: 1 !important;
        }
    }
</style>

<?php if (isset($_GET['uploaded'])): ?>
    <div class="dc-success">Risk assessment uploaded.</div>
<?php endif; ?>

<?php if ($errors): ?>
    <div class="dc-error-summary">
        <h2>Upload failed</h2>
        <ul>
            <?php foreach ($errors as $error): ?>
                <li><?= e($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="dc-risk-layout">
    <section class="dc-risk-main-panel">
        <div class="dc-risk-main-panel-inner">
            <div class="dc-risk-results-head">
                <div>
                    <h2 class="lt-section-title mb-1">Available risk assessments</h2>
                    <div class="dc-risk-count">
                        <?= number_format($totalRisks) ?> result<?= $totalRisks === 1 ? '' : 's' ?>
                        <?php if ($totalRisks > 0): ?>
                            · page <?= number_format($page) ?> of <?= number_format($totalPages) ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <form method="get" class="dc-risk-search">
                <div class="dc-risk-search-grid <?= $showGroupPicker ? 'has-group' : '' ?>">
                    <div class="form-group mb-0">
                        <label for="q">Search</label>
                        <input
                            type="search"
                            id="q"
                            name="q"
                            class="form-control"
                            value="<?= e($search) ?>"
                            placeholder="Title, Group, uploader or filename"
                        >
                    </div>

                    <?php if ($showGroupPicker): ?>
                        <div class="form-group mb-0">
                            <label for="group_id">Group</label>
                            <select id="group_id" name="group_id" class="form-control">
                                <?= dc_group_options_html($selectedGroupId) ?>
                            </select>
                        </div>
                    <?php else: ?>
                        <input type="hidden" name="group_id" value="<?= (int) $selectedGroupId ?>">
                    <?php endif; ?>

                    <div class="form-group mb-0">
                        <label for="visibility">Sharing</label>
                        <select id="visibility" name="visibility" class="form-control">
                            <option value="all" <?= $visibility === 'all' ? 'selected' : '' ?>>All</option>
                            <option value="district" <?= $visibility === 'district' ? 'selected' : '' ?>>District</option>
                            <option value="group" <?= $visibility === 'group' ? 'selected' : '' ?>>Group only</option>
                        </select>
                    </div>

                    <div class="form-group mb-0">
                        <label for="date_range">Uploaded</label>
                        <select id="date_range" name="date_range" class="form-control">
                            <option value="all" <?= $dateRange === 'all' ? 'selected' : '' ?>>Any time</option>
                            <option value="30" <?= $dateRange === '30' ? 'selected' : '' ?>>Last 30 days</option>
                            <option value="90" <?= $dateRange === '90' ? 'selected' : '' ?>>Last 90 days</option>
                            <option value="365" <?= $dateRange === '365' ? 'selected' : '' ?>>Last year</option>
                        </select>
                    </div>

                    <div class="form-group mb-0 dc-risk-sort-field">
                        <label for="sort">Sort</label>
                        <select id="sort" name="sort" class="form-control">
                            <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest first</option>
                            <option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>Oldest first</option>
                            <option value="title" <?= $sort === 'title' ? 'selected' : '' ?>>Title</option>
                            <option value="group" <?= $sort === 'group' ? 'selected' : '' ?>>Group</option>
                        </select>
                    </div>
                </div>

                <div class="dc-risk-search-actions">
                    <div class="dc-risk-button-row">
                        <button class="btn btn-primary lt-btn" type="submit">Search</button>
                        <a class="btn btn-secondary" href="/dc/risk-assessments.php<?= $showGroupPicker ? '?group_id=' . (int) $selectedGroupId : '' ?>">Clear</a>
                    </div>

                    <div></div>

                    <div class="dc-risk-per-page">
                        <label for="per_page">Per page</label>
                        <select id="per_page" name="per_page" class="form-control" onchange="this.form.submit()">
                            <option value="10" <?= $perPage === 10 ? 'selected' : '' ?>>10</option>
                            <option value="25" <?= $perPage === 25 ? 'selected' : '' ?>>25</option>
                            <option value="50" <?= $perPage === 50 ? 'selected' : '' ?>>50</option>
                            <option value="100" <?= $perPage === 100 ? 'selected' : '' ?>>100</option>
                        </select>
                    </div>
                </div>
            </form>

            <?php if (!$risks): ?>
                <div class="dc-risk-empty">
                    <h3>No risk assessments found</h3>
                    <p>Try a broader search, remove filters, or upload a new risk assessment.</p>
                </div>
            <?php else: ?>
                <div class="dc-risk-list">
                    <?php foreach ($risks as $risk): ?>
                        <?php
                        $uploadedAt = !empty($risk['uploaded_display_at']) && strtotime((string) $risk['uploaded_display_at'])
                            ? date('j M Y', strtotime((string) $risk['uploaded_display_at']))
                            : 'Unknown date';

                        $fileMeta = [];
                        if (!empty($risk['file_extension'])) {
                            $fileMeta[] = strtoupper((string) $risk['file_extension']);
                        }
                        if (!empty($risk['file_size_bytes'])) {
                            $fileMeta[] = dc_ra_format_bytes((int) $risk['file_size_bytes']);
                        }
                        ?>
                        <article class="dc-risk-row">
                            <div>
                                <h3 class="dc-risk-title">
                                    <a href="/dc/download-risk-assessment.php?id=<?= (int) $risk['id'] ?>">
                                        <?= e($risk['title']) ?>
                                    </a>
                                </h3>

                                <p class="dc-risk-meta">
                                    <strong><?= e($risk['group_name']) ?></strong>
                                    · uploaded by <?= e($risk['uploaded_by_name'] ?: 'Unknown leader') ?>
                                    · <?= e($uploadedAt) ?>
                                </p>

                                <?php if (!empty($risk['description'])): ?>
                                    <p class="dc-risk-desc"><?= e(dc_ra_compact_text((string) $risk['description'], 180)) ?></p>
                                <?php endif; ?>

                                <div class="dc-risk-badges">
                                    <span class="dc-risk-badge <?= ($risk['visibility'] ?? '') === 'district' ? 'dc-risk-badge-district' : '' ?>">
                                        <?= ($risk['visibility'] ?? '') === 'district' ? 'District shared' : 'Group only' ?>
                                    </span>

                                    <?php if ($fileMeta): ?>
                                        <span class="dc-risk-badge"><?= e(implode(' · ', $fileMeta)) ?></span>
                                    <?php endif; ?>

                                    <?php if (!empty($risk['original_filename'])): ?>
                                        <span class="dc-risk-badge"><?= e(dc_ra_compact_text((string) $risk['original_filename'], 52)) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="dc-risk-actions">
                                <a class="dc-risk-download" href="/dc/download-risk-assessment.php?id=<?= (int) $risk['id'] ?>">
                                    Download
                                </a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>

                <?php if ($totalPages > 1): ?>
                    <nav class="dc-pagination" aria-label="Risk assessment pages">
                        <?php if ($page > 1): ?>
                            <a href="<?= e(dc_ra_query_with(['page' => $page - 1])) ?>">Previous</a>
                        <?php else: ?>
                            <span class="disabled">Previous</span>
                        <?php endif; ?>

                        <?php
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);

                        if ($startPage > 1): ?>
                            <a href="<?= e(dc_ra_query_with(['page' => 1])) ?>">1</a>
                            <?php if ($startPage > 2): ?><span class="disabled">…</span><?php endif; ?>
                        <?php endif; ?>

                        <?php for ($p = $startPage; $p <= $endPage; $p++): ?>
                            <?php if ($p === $page): ?>
                                <span class="current"><?= $p ?></span>
                            <?php else: ?>
                                <a href="<?= e(dc_ra_query_with(['page' => $p])) ?>"><?= $p ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>

                        <?php if ($endPage < $totalPages): ?>
                            <?php if ($endPage < $totalPages - 1): ?><span class="disabled">…</span><?php endif; ?>
                            <a href="<?= e(dc_ra_query_with(['page' => $totalPages])) ?>"><?= $totalPages ?></a>
                        <?php endif; ?>

                        <?php if ($page < $totalPages): ?>
                            <a href="<?= e(dc_ra_query_with(['page' => $page + 1])) ?>">Next</a>
                        <?php else: ?>
                            <span class="disabled">Next</span>
                        <?php endif; ?>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </section>
</div>

<?php require __DIR__ . '/layout-footer.php'; ?>