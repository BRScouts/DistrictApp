<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_auth();

$pdo = db();

$isAdminOrReviewer = is_reviewer_or_admin();
$currentGroup = auth_group();
$currentGroupId = $currentGroup['group_id'] ?? null;

if (!$isAdminOrReviewer && !$currentGroupId) {
    redirect(ROUTE_403);
}

$flash = '';
$error = '';

$search = trim((string)($_GET['q'] ?? ''));
$scope = trim((string)($_GET['scope'] ?? ''));
$status = trim((string)($_GET['status'] ?? 'active'));
$sort = trim((string)($_GET['sort'] ?? 'updated_desc'));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = (int)($_GET['per_page'] ?? 24);

if (!in_array($perPage, [12, 24, 48, 96], true)) {
    $perPage = 24;
}

if (!in_array($status, ['active', 'archived', 'all'], true)) {
    $status = 'active';
}

$sortMap = [
    'updated_desc' => 'ra.updated_at DESC, display_name ASC',
    'uploaded_desc' => 'ra.uploaded_at DESC, display_name ASC',
    'name_asc' => 'display_name ASC',
    'group_asc' => 'g.group_name ASC, display_name ASC',
];

if (!isset($sortMap[$sort])) {
    $sort = 'updated_desc';
}

function ra_recent_enough(array $ra): bool
{
    $cutoff = strtotime('-90 days');
    return strtotime((string)$ra['uploaded_at']) >= $cutoff
        || strtotime((string)$ra['updated_at']) >= $cutoff;
}

function ra_download_url(int $id): string
{
    return BASE_URL . '/download-risk-assessment.php?id=' . $id;
}

function ra_preview_url(int $id): string
{
    return BASE_URL . '/preview-risk-assessment.php?id=' . $id;
}

function format_file_size(int $bytes): string
{
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1) . ' MB';
    }

    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 1) . ' KB';
    }

    return $bytes . ' bytes';
}

function ra_can_inline_preview(array $ra): bool
{
    return strtolower((string)$ra['file_extension']) === 'pdf';
}

function ra_file_badge(array $ra): string
{
    return strtoupper((string)$ra['file_extension']);
}

function ra_display_name(array $ra): string
{
    $filename = trim((string)($ra['original_filename'] ?? ''));

    if ($filename !== '') {
        return $filename;
    }

    $title = trim((string)($ra['title'] ?? ''));
    return $title !== '' ? $title : 'Risk assessment';
}

function can_manage_ra(array $ra, bool $isAdminOrReviewer, ?int $currentGroupId): bool
{
    if ($isAdminOrReviewer) {
        return true;
    }

    return $currentGroupId !== null && (int)$ra['group_id'] === $currentGroupId;
}

function risk_assessment_redirect(): void
{
    $params = $_GET;
    $params['saved'] = '1';

    redirect(ROUTE_RISK_ASSESSMENTS . '?' . http_build_query($params));
}

function query_url(array $overrides = []): string
{
    $params = array_merge($_GET, $overrides);

    foreach ($params as $key => $value) {
        if ($value === '' || $value === null || $value === 0 || $value === '0') {
            unset($params[$key]);
        }
    }

    return ROUTE_RISK_ASSESSMENTS . ($params ? '?' . http_build_query($params) : '');
}

/*
|--------------------------------------------------------------------------
| Handle edit / archive / restore / delete
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formType = trim((string)($_POST['form_type'] ?? ''));
    $raId = (int)($_POST['ra_id'] ?? 0);

    if ($raId <= 0) {
        $error = 'Invalid risk assessment selected.';
    } else {
        $stmt = $pdo->prepare("
            SELECT
                ra.*,
                (
                    SELECT COUNT(*)
                    FROM event_risk_assessments era
                    WHERE era.risk_assessment_id = ra.id
                ) AS linked_count
            FROM risk_assessments ra
            WHERE ra.id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => $raId]);
        $raForAction = $stmt->fetch();

        if (!$raForAction) {
            $error = 'Risk assessment not found.';
        } elseif (!can_manage_ra($raForAction, $isAdminOrReviewer, $currentGroupId ? (int)$currentGroupId : null)) {
            $error = 'You do not have permission to manage this risk assessment.';
        } elseif ($formType === 'update_ra') {
            $visibility = trim((string)($_POST['visibility'] ?? 'group'));
            $description = trim((string)($_POST['description'] ?? ''));

            if (!in_array($visibility, ['group', 'district'], true)) {
                $error = 'Invalid sharing option selected.';
            } else {
                $stmt = $pdo->prepare("
                    UPDATE risk_assessments
                    SET description = :description,
                        visibility = :visibility,
                        updated_at = NOW()
                    WHERE id = :id
                ");
                $stmt->execute([
                    'description' => $description !== '' ? $description : null,
                    'visibility' => $visibility,
                    'id' => $raId,
                ]);

                risk_assessment_redirect();
            }
        } elseif ($formType === 'archive_ra') {
            $stmt = $pdo->prepare("
                UPDATE risk_assessments
                SET is_active = 0,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute(['id' => $raId]);

            risk_assessment_redirect();
        } elseif ($formType === 'restore_ra') {
            $stmt = $pdo->prepare("
                UPDATE risk_assessments
                SET is_active = 1,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute(['id' => $raId]);

            risk_assessment_redirect();
        } elseif ($formType === 'delete_ra') {
            $linkedCount = (int)$raForAction['linked_count'];

            if ($linkedCount > 0) {
                $error = 'This risk assessment is linked to an event, so it cannot be deleted. Archive it instead.';
            } else {
                $filePath = (string)($raForAction['file_path'] ?? '');

                $stmt = $pdo->prepare("DELETE FROM risk_assessments WHERE id = :id");
                $stmt->execute(['id' => $raId]);

                if ($filePath !== '' && is_file($filePath)) {
                    @unlink($filePath);
                }

                risk_assessment_redirect();
            }
        }
    }
}

if (isset($_GET['saved']) && $_GET['saved'] === '1') {
    $flash = 'Risk assessment updated successfully.';
}

/*
|--------------------------------------------------------------------------
| Build query
|--------------------------------------------------------------------------
*/
$fromSql = "
    FROM risk_assessments ra
    INNER JOIN groups g ON g.id = ra.group_id
    WHERE ra.admin_review_status = 'available'
";

$params = [];

if ($status === 'active') {
    $fromSql .= " AND ra.is_active = 1 ";
} elseif ($status === 'archived') {
    $fromSql .= " AND ra.is_active = 0 ";
}

if (!$isAdminOrReviewer) {
    $fromSql .= "
      AND (
            ra.group_id = :current_group_id
            OR ra.visibility = 'district'
            OR (ra.is_active = 0 AND ra.group_id = :current_group_id_archived)
          )
    ";
    $params['current_group_id'] = (int)$currentGroupId;
    $params['current_group_id_archived'] = (int)$currentGroupId;
}

if ($search !== '') {
    $fromSql .= "
      AND (
            ra.title LIKE :search
            OR ra.description LIKE :search
            OR ra.activity_type LIKE :search
            OR ra.location_summary LIKE :search
            OR g.group_name LIKE :search
            OR ra.original_filename LIKE :search
          )
    ";
    $params['search'] = '%' . $search . '%';
}

if ($scope === 'my_group') {
    if (!$isAdminOrReviewer) {
        $fromSql .= " AND ra.group_id = :current_group_only ";
        $params['current_group_only'] = (int)$currentGroupId;
    }
} elseif ($scope === 'district') {
    $fromSql .= " AND ra.visibility = 'district' ";
}

$countStmt = $pdo->prepare("SELECT COUNT(*) " . $fromSql);
$countStmt->execute($params);
$totalItems = (int)$countStmt->fetchColumn();

$totalPages = max(1, (int)ceil($totalItems / $perPage));

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $perPage;

$sql = "
    SELECT
        ra.id,
        ra.group_id,
        ra.title,
        COALESCE(NULLIF(ra.original_filename, ''), NULLIF(ra.title, ''), 'Risk assessment') AS display_name,
        ra.description,
        ra.activity_type,
        ra.location_summary,
        ra.visibility,
        ra.original_filename,
        ra.file_extension,
        ra.mime_type,
        ra.file_size_bytes,
        ra.file_path,
        ra.uploaded_by_name,
        ra.uploaded_by_email,
        ra.uploaded_at,
        ra.updated_at,
        ra.is_active,
        g.group_name,
        (
            SELECT COUNT(*)
            FROM event_risk_assessments era
            WHERE era.risk_assessment_id = ra.id
        ) AS linked_count
    " . $fromSql . "
    ORDER BY {$sortMap[$sort]}
    LIMIT :limit OFFSET :offset
";

$stmt = $pdo->prepare($sql);

foreach ($params as $key => $value) {
    $stmt->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}

$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();

$riskAssessments = $stmt->fetchAll();

$startItem = $totalItems === 0 ? 0 : $offset + 1;
$endItem = min($offset + $perPage, $totalItems);

render_page_start('Risk Assessments');
render_header('risk-assessments');
?>

<style>
.ra-compact-toolbar {
    position: sticky;
    top: 0;
    z-index: 5;
    background: #fff;
    border: 1px solid #e9ecef;
    border-radius: .75rem;
    box-shadow: 0 .25rem .75rem rgba(0,0,0,.04);
}

.ra-search {
    min-width: 260px;
}

.ra-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(275px, 1fr));
    gap: 1rem;
}

.ra-card {
    border: 1px solid #e9ecef;
    border-radius: 1rem;
    background: #fff;
    overflow: hidden;
    box-shadow: 0 .2rem .7rem rgba(0,0,0,.04);
    transition: transform .15s ease, box-shadow .15s ease;
}

.ra-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 .5rem 1rem rgba(0,0,0,.08);
}

.ra-card.archived {
    opacity: .72;
}

.ra-preview {
    height: 160px;
    background: linear-gradient(135deg, #f8f9fa 0%, #eef2f6 100%);
    border-bottom: 1px solid #e9ecef;
    display: flex;
    align-items: center;
    justify-content: center;
}

.ra-preview-inner {
    width: 76%;
    height: 76%;
    background: #fff;
    border: 1px solid #dfe3e8;
    border-radius: .75rem;
    box-shadow: 0 .25rem .75rem rgba(0,0,0,.06);
    padding: .9rem;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
}

.ra-preview-lines span {
    display: block;
    height: 7px;
    border-radius: 999px;
    background: #e9ecef;
    margin-bottom: .45rem;
}

.ra-card-body {
    padding: 1rem;
}

.ra-card-title {
    font-size: 1rem;
    font-weight: 800;
    line-height: 1.3;
    min-height: 2.6rem;
}

.ra-tags {
    display: flex;
    flex-wrap: wrap;
    gap: .35rem;
    margin-top: .75rem;
}

.ra-actions {
    display: flex;
    flex-wrap: wrap;
    gap: .4rem;
    margin-top: 1rem;
}

.ra-hidden {
    display: none !important;
}

.ra-empty {
    border: 1px dashed #ced4da;
    border-radius: 1rem;
    background: #fff;
    padding: 2rem;
    text-align: center;
}

@media (max-width: 767.98px) {
    .ra-compact-toolbar {
        position: static;
    }

    .ra-toolbar-row {
        display: block !important;
    }

    .ra-search {
        min-width: 100%;
        margin-bottom: .5rem;
    }

    .ra-toolbar-row .form-control,
    .ra-toolbar-row .btn {
        width: 100%;
        margin-bottom: .5rem;
    }
}
</style>

<div class="container-fluid">
    <div class="d-md-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="mb-1">Risk Assessments</h1>
            <p class="text-muted mb-0">
                Browse, preview, manage sharing and archive older documents.
            </p>
        </div>
    </div>

    <?php if ($flash !== ''): ?>
        <div class="alert alert-success"><?= e($flash) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger"><?= e($error) ?></div>
    <?php endif; ?>

    <div class="alert alert-info">
        Shared risk assessments are reference material only. Leaders must review and adapt them to their own event, venue, participants, and current conditions.
    </div>

    <?php if (!$isAdminOrReviewer && $currentGroup): ?>
        <div class="alert alert-light border">
            You are viewing the library for <strong><?= e($currentGroup['group_name']) ?></strong>:
            your own group¡¯s assessments plus district-shared documents.
        </div>
    <?php endif; ?>

    <div class="ra-compact-toolbar p-2 mb-4">
        <form method="get" action="<?= e(ROUTE_RISK_ASSESSMENTS) ?>" id="serverFilterForm">
            <div class="d-flex align-items-center ra-toolbar-row">
                <input
                    type="search"
                    class="form-control form-control-sm ra-search mr-2"
                    id="liveSearch"
                    name="q"
                    value="<?= e($search) ?>"
                    placeholder="Search documents, activities, locations, groups..."
                    autocomplete="off"
                >

                <select class="form-control form-control-sm mr-2" name="scope" id="scopeFilter">
                    <option value="">All scopes</option>
                    <option value="my_group" <?= $scope === 'my_group' ? 'selected' : '' ?>>My group</option>
                    <option value="district" <?= $scope === 'district' ? 'selected' : '' ?>>District shared</option>
                </select>

                <select class="form-control form-control-sm mr-2" name="status" id="statusFilter">
                    <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="archived" <?= $status === 'archived' ? 'selected' : '' ?>>Archived</option>
                    <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>Active + archived</option>
                </select>

                <select class="form-control form-control-sm mr-2" name="sort" id="sortFilter">
                    <option value="updated_desc" <?= $sort === 'updated_desc' ? 'selected' : '' ?>>Recently updated</option>
                    <option value="uploaded_desc" <?= $sort === 'uploaded_desc' ? 'selected' : '' ?>>Recently uploaded</option>
                    <option value="name_asc" <?= $sort === 'name_asc' ? 'selected' : '' ?>>Name A-Z</option>
                    <option value="group_asc" <?= $sort === 'group_asc' ? 'selected' : '' ?>>Group A-Z</option>
                </select>

                <select class="form-control form-control-sm mr-2" name="per_page">
                    <option value="12" <?= $perPage === 12 ? 'selected' : '' ?>>12</option>
                    <option value="24" <?= $perPage === 24 ? 'selected' : '' ?>>24</option>
                    <option value="48" <?= $perPage === 48 ? 'selected' : '' ?>>48</option>
                    <option value="96" <?= $perPage === 96 ? 'selected' : '' ?>>96</option>
                </select>

                <button type="submit" class="btn btn-primary btn-sm mr-2">Apply</button>
                <a href="<?= e(ROUTE_RISK_ASSESSMENTS) ?>" class="btn btn-outline-secondary btn-sm">Reset</a>
                <a href="<?= e(BASE_URL . '/map.php') ?>" class="btn btn-outline-primary">
    Activity map
</a>
            </div>
        </form>
    </div>

    <div class="d-md-flex justify-content-between align-items-center mb-3">
        <h2 class="h4 mb-2 mb-md-0">Library</h2>
        <span class="text-muted">
            <span id="visibleCount"><?= e((string)count($riskAssessments)) ?></span>
            visible on this page ¡¤
            showing <?= e((string)$startItem) ?>-<?= e((string)$endItem) ?> of <?= e((string)$totalItems) ?>
        </span>
    </div>

    <?php if (empty($riskAssessments)): ?>
        <div class="ra-empty">
            <h3 class="h5 mb-2">No risk assessments found</h3>
            <p class="text-muted mb-0">Try clearing filters or searching archived documents.</p>
        </div>
    <?php else: ?>
        <div id="raGrid" class="ra-grid">
            <?php foreach ($riskAssessments as $ra): ?>
                <?php
                $recent = ra_recent_enough($ra);
                $previewable = ra_can_inline_preview($ra);
                $displayName = ra_display_name($ra);
                $modalId = 'raModal' . (int)$ra['id'];
                $canManage = can_manage_ra($ra, $isAdminOrReviewer, $currentGroupId ? (int)$currentGroupId : null);
                $linkedCount = (int)$ra['linked_count'];
                $isArchived = (int)$ra['is_active'] !== 1;
                $searchText = strtolower(
                    $displayName . ' ' .
                    (string)$ra['title'] . ' ' .
                    (string)$ra['description'] . ' ' .
                    (string)$ra['activity_type'] . ' ' .
                    (string)$ra['location_summary'] . ' ' .
                    (string)$ra['group_name']
                );
                ?>
                <div class="ra-card <?= $isArchived ? 'archived' : '' ?>"
                     data-search="<?= e($searchText) ?>"
                     data-scope="<?= e((string)$ra['visibility']) ?>"
                     data-status="<?= $isArchived ? 'archived' : 'active' ?>">
                    <div class="ra-preview">
                        <div class="ra-preview-inner">
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="badge badge-light"><?= e(ra_file_badge($ra)) ?></span>

                                <?php if ($isArchived): ?>
                                    <span class="badge badge-secondary">Archived</span>
                                <?php elseif ($ra['visibility'] === 'district'): ?>
                                    <span class="badge badge-primary">District</span>
                                <?php else: ?>
                                    <span class="badge badge-secondary">Group</span>
                                <?php endif; ?>
                            </div>

                            <div class="ra-preview-lines">
                                <span style="width: 82%;"></span>
                                <span style="width: 95%;"></span>
                                <span style="width: 68%;"></span>
                                <span style="width: 88%;"></span>
                            </div>

                            <div class="small text-muted">
                                <?= e($ra['group_name']) ?>
                            </div>
                        </div>
                    </div>

                    <div class="ra-card-body">
                        <div class="ra-card-title"><?= e($displayName) ?></div>

                        <div class="small text-muted">
                            <div><strong>Group:</strong> <?= e($ra['group_name']) ?></div>
                            <div><strong>Updated:</strong> <?= e(date('d M Y', strtotime((string)$ra['updated_at']))) ?></div>
                            <div><strong>File:</strong> <?= e(format_file_size((int)$ra['file_size_bytes'])) ?></div>
                        </div>

                        <div class="ra-tags">
                            <?php if ($recent): ?>
                                <span class="badge badge-success">Recent</span>
                            <?php else: ?>
                                <span class="badge badge-warning">Review before reuse</span>
                            <?php endif; ?>

                            <?php if ($linkedCount > 0): ?>
                                <span class="badge badge-info"><?= e((string)$linkedCount) ?> linked event<?= $linkedCount === 1 ? '' : 's' ?></span>
                            <?php endif; ?>

                            <?php if (!empty($ra['location_summary'])): ?>
                                <span class="badge badge-light"><?= e($ra['location_summary']) ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="ra-actions">
                            <button type="button"
                                    class="btn btn-outline-primary btn-sm"
                                    data-toggle="modal"
                                    data-target="#<?= e($modalId) ?>">
                                Open
                            </button>

                            <?php if ($previewable): ?>
                                <a href="<?= e(ra_preview_url((int)$ra['id'])) ?>" target="_blank" class="btn btn-outline-secondary btn-sm">
                                    View
                                </a>
                            <?php endif; ?>

                            <a href="<?= e(ra_download_url((int)$ra['id'])) ?>" class="btn btn-primary btn-sm">
                                Download
                            </a>
                        </div>
                    </div>
                </div>

                <div class="modal fade" id="<?= e($modalId) ?>" tabindex="-1" role="dialog" aria-hidden="true">
                    <div class="modal-dialog modal-xl modal-dialog-centered" role="document">
                        <div class="modal-content">
                            <div class="modal-header">
                                <div>
                                    <h5 class="modal-title mb-1"><?= e($displayName) ?></h5>
                                    <div class="small text-muted">
                                        <?= e($ra['group_name']) ?> ¡¤ updated <?= e(date('d M Y', strtotime((string)$ra['updated_at']))) ?>
                                    </div>
                                </div>
                                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                    <span aria-hidden="true">&times;</span>
                                </button>
                            </div>

                            <div class="modal-body">
                                <div class="row">
                                    <div class="col-lg-4 mb-4">
                                        <dl class="small">
                                            <dt>Document</dt>
                                            <dd><?= e($displayName) ?></dd>

                                            <dt>Group</dt>
                                            <dd><?= e($ra['group_name']) ?></dd>

                                            <dt>Sharing</dt>
                                            <dd><?= e($ra['visibility'] === 'district' ? 'Shared with district' : 'Only this group') ?></dd>

                                            <dt>Status</dt>
                                            <dd><?= $isArchived ? 'Archived' : 'Active' ?></dd>

                                            <dt>Linked events</dt>
                                            <dd><?= e((string)$linkedCount) ?></dd>

                                            <dt>Uploaded</dt>
                                            <dd><?= e(date('d M Y H:i', strtotime((string)$ra['uploaded_at']))) ?></dd>

                                            <dt>Updated</dt>
                                            <dd><?= e(date('d M Y H:i', strtotime((string)$ra['updated_at']))) ?></dd>

                                            <?php if (!empty($ra['description'])): ?>
                                                <dt>Description</dt>
                                                <dd><?= nl2br(e((string)$ra['description'])) ?></dd>
                                            <?php endif; ?>
                                        </dl>

                                        <?php if ($canManage): ?>
                                            <hr>

                                            <h6>Edit details / sharing</h6>
                                            <form method="post" class="mb-3">
                                                <input type="hidden" name="form_type" value="update_ra">
                                                <input type="hidden" name="ra_id" value="<?= (int)$ra['id'] ?>">

                                                <div class="form-group">
                                                    <label class="small">Document title</label>
                                                    <input type="text"
                                                           class="form-control form-control-sm"
                                                           value="<?= e($displayName) ?>"
                                                           readonly>
                                                    <small class="text-muted">
                                                        The document title is taken from the uploaded file and cannot be edited here.
                                                    </small>
                                                </div>

                                                <div class="form-group">
                                                    <label class="small" for="description_<?= (int)$ra['id'] ?>">Description</label>
                                                    <textarea class="form-control form-control-sm"
                                                              id="description_<?= (int)$ra['id'] ?>"
                                                              name="description"
                                                              rows="3"><?= e((string)$ra['description']) ?></textarea>
                                                </div>

                                                <div class="form-group">
                                                    <label class="small" for="visibility_<?= (int)$ra['id'] ?>">Sharing</label>
                                                    <select class="form-control form-control-sm"
                                                            id="visibility_<?= (int)$ra['id'] ?>"
                                                            name="visibility">
                                                        <option value="group" <?= $ra['visibility'] === 'group' ? 'selected' : '' ?>>Only this group</option>
                                                        <option value="district" <?= $ra['visibility'] === 'district' ? 'selected' : '' ?>>Share with district</option>
                                                    </select>
                                                </div>

                                                <button type="submit" class="btn btn-primary btn-sm btn-block">
                                                    Save changes
                                                </button>
                                            </form>

                                            <?php if ($isArchived): ?>
                                                <form method="post">
                                                    <input type="hidden" name="form_type" value="restore_ra">
                                                    <input type="hidden" name="ra_id" value="<?= (int)$ra['id'] ?>">
                                                    <button type="submit" class="btn btn-outline-success btn-sm btn-block">
                                                        Restore
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <form method="post" class="mb-2">
                                                    <input type="hidden" name="form_type" value="archive_ra">
                                                    <input type="hidden" name="ra_id" value="<?= (int)$ra['id'] ?>">
                                                    <button type="submit"
                                                            class="btn btn-outline-warning btn-sm btn-block"
                                                            onclick="return confirm('Archive this risk assessment? It can still be found using the Archived filter.');">
                                                        Archive
                                                    </button>
                                                </form>

                                                <?php if ($linkedCount === 0): ?>
                                                    <form method="post">
                                                        <input type="hidden" name="form_type" value="delete_ra">
                                                        <input type="hidden" name="ra_id" value="<?= (int)$ra['id'] ?>">
                                                        <button type="submit"
                                                                class="btn btn-outline-danger btn-sm btn-block"
                                                                onclick="return confirm('Delete this risk assessment permanently?');">
                                                            Delete
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <small class="text-muted d-block">
                                                        This document is linked to <?= e((string)$linkedCount) ?>
                                                        event<?= $linkedCount === 1 ? '' : 's' ?>, so it cannot be deleted.
                                                    </small>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>

                                    <div class="col-lg-8">
                                        <?php if ($previewable): ?>
                                            <div class="border rounded overflow-hidden" style="height: 72vh; background: #f8f9fa;">
                                                <iframe
                                                    src="<?= e(ra_preview_url((int)$ra['id'])) ?>"
                                                    title="<?= e($displayName) ?>"
                                                    style="width:100%; height:100%; border:0;"
                                                ></iframe>
                                            </div>
                                        <?php else: ?>
                                            <div class="border rounded p-5 text-center bg-light">
                                                <h6 class="mb-2">Preview not available in-browser</h6>
                                                <p class="text-muted mb-3">
                                                    Word documents usually need to be downloaded to review properly.
                                                </p>
                                                <a href="<?= e(ra_download_url((int)$ra['id'])) ?>" class="btn btn-primary">
                                                    Download document
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="modal-footer">
                                <a href="<?= e(ra_download_url((int)$ra['id'])) ?>" class="btn btn-primary">
                                    Download
                                </a>
                                <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">
                                    Close
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div id="noLiveResults" class="ra-empty mt-3 d-none">
            <h3 class="h5 mb-2">No matching documents on this page</h3>
            <p class="text-muted mb-0">Try a different term or use Apply to search across all pages.</p>
        </div>

        <?php if ($totalPages > 1): ?>
            <nav class="mt-4" aria-label="Risk assessment pages">
                <ul class="pagination justify-content-center flex-wrap">
                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= e(query_url(['page' => max(1, $page - 1)])) ?>">Previous</a>
                    </li>

                    <?php
                    $window = 2;
                    $start = max(1, $page - $window);
                    $end = min($totalPages, $page + $window);
                    ?>

                    <?php for ($i = $start; $i <= $end; $i++): ?>
                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                            <a class="page-link" href="<?= e(query_url(['page' => $i])) ?>"><?= e((string)$i) ?></a>
                        </li>
                    <?php endfor; ?>

                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= e(query_url(['page' => min($totalPages, $page + 1)])) ?>">Next</a>
                    </li>
                </ul>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const liveSearch = document.getElementById('liveSearch');
    const scopeFilter = document.getElementById('scopeFilter');
    const statusFilter = document.getElementById('statusFilter');
    const sortFilter = document.getElementById('sortFilter');
    const visibleCount = document.getElementById('visibleCount');
    const noLiveResults = document.getElementById('noLiveResults');
    const cards = Array.from(document.querySelectorAll('.ra-card'));

    function normalise(value) {
        return String(value || '').toLowerCase().trim();
    }

    function applyLiveFilter() {
        const q = normalise(liveSearch ? liveSearch.value : '');
        const scope = scopeFilter ? scopeFilter.value : '';
        const status = statusFilter ? statusFilter.value : 'active';

        let shown = 0;

        cards.forEach(card => {
            const haystack = card.dataset.search || '';
            const cardScope = card.dataset.scope || '';
            const cardStatus = card.dataset.status || 'active';

            const matchesText = q === '' || haystack.includes(q);
            const matchesScope = scope === '' ||
                (scope === 'district' && cardScope === 'district') ||
                (scope === 'my_group' && cardScope === 'group');

            const matchesStatus = status === 'all' || cardStatus === status;

            const show = matchesText && matchesScope && matchesStatus;
            card.classList.toggle('ra-hidden', !show);

            if (show) {
                shown++;
            }
        });

        if (visibleCount) {
            visibleCount.textContent = shown;
        }

        if (noLiveResults) {
            noLiveResults.classList.toggle('d-none', shown !== 0);
        }
    }

    [liveSearch, scopeFilter, statusFilter].forEach(el => {
        if (el) {
            el.addEventListener('input', applyLiveFilter);
            el.addEventListener('change', applyLiveFilter);
        }
    });

    if (sortFilter) {
        sortFilter.addEventListener('change', function () {
            document.getElementById('serverFilterForm').submit();
        });
    }

    applyLiveFilter();
});
</script>

<?php render_page_end(); ?>