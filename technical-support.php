<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

require_login();

if (user_needs_group_onboarding()) {
    redirect('/onboarding.php');
}

$pdo = db();
$user = current_user();
$appName = app_config('APP_NAME', 'Irwell Valley Leader Tool');

$pageTitle = 'Technical Support | ' . $appName;
$heroTitle = 'Technical Support';
$heroText = 'Raise a support request for the website, District App, email or OneDrive.';
$breadcrumb = '<a href="/index.php">Home</a> / Technical Support';

$supportEmail = 'support@irvalscouts.org.uk';

$error = null;
$success = null;
$raisedReference = null;

$personId = (int) ($user['id'] ?? 0);

$issueTypes = [
    'website' => 'Website',
    'district_app' => 'District App',
    'email' => 'Email',
    'onedrive' => 'OneDrive',
];

function tech_table_exists(string $table): bool
{
    static $cache = [];

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    try {
        $stmt = db()->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
        ");
        $stmt->execute(['table_name' => $table]);

        return $cache[$table] = ((int) $stmt->fetchColumn()) > 0;
    } catch (Throwable $e) {
        return $cache[$table] = false;
    }
}

function tech_column_exists(string $table, string $column): bool
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

function tech_quote_identifier(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function tech_email_queue_columns(): array
{
    static $columns = null;

    if ($columns !== null) {
        return $columns;
    }

    try {
        $stmt = db()->prepare("
            SELECT COLUMN_NAME
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'email_queue'
        ");
        $stmt->execute();

        return $columns = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
        return $columns = [];
    }
}

function tech_insert_email_queue(array $data): void
{
    $columns = tech_email_queue_columns();

    if (!$columns) {
        throw new RuntimeException('The email_queue table does not exist or has no recognised columns.');
    }

    $insert = [];

    foreach ($data as $column => $value) {
        if (in_array((string) $column, $columns, true)) {
            $insert[(string) $column] = $value;
        }
    }

    if (in_array('status', $columns, true) && !array_key_exists('status', $insert)) {
        $insert['status'] = 'pending';
    }

    if (in_array('created_at', $columns, true) && !array_key_exists('created_at', $insert)) {
        $insert['created_at'] = date('Y-m-d H:i:s');
    }

    if (!$insert) {
        throw new RuntimeException('No compatible email_queue columns were found.');
    }

    $quotedColumns = array_map('tech_quote_identifier', array_keys($insert));
    $placeholders = array_map(static fn(string $column): string => ':' . $column, array_keys($insert));

    $stmt = db()->prepare("
        INSERT INTO email_queue (" . implode(', ', $quotedColumns) . ")
        VALUES (" . implode(', ', $placeholders) . ")
    ");

    $stmt->execute($insert);
}

function tech_plain_from_html(string $html): string
{
    $html = preg_replace('/<\s*br\s*\/?>/i', "\n", $html) ?? $html;
    $html = preg_replace('/<\/p>/i', "\n\n", $html) ?? $html;
    $html = preg_replace('/<\/li>/i', "\n", $html) ?? $html;

    $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

    return preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
}

function tech_person_name(array $person): string
{
    $name = trim((string) ($person['full_name'] ?? ''));

    if ($name !== '') {
        return $name;
    }

    return trim((string) ($person['primary_email'] ?? 'Unknown person'));
}

function tech_current_user_name(array $user): string
{
    foreach (['full_name', 'preferred_name', 'email'] as $field) {
        $value = trim((string) ($user[$field] ?? ''));

        if ($value !== '') {
            return $value;
        }
    }

    return 'Unknown user';
}

function tech_current_user_email(array $user, array $profile = []): string
{
    foreach ([
        $user['email'] ?? '',
        $user['primary_email'] ?? '',
        $profile['primary_email'] ?? '',
    ] as $value) {
        $email = strtolower(trim((string) $value));

        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $email;
        }
    }

    return '';
}

function tech_fetch_current_person(int $personId): array
{
    if ($personId < 1) {
        return [];
    }

    try {
        $stmt = db()->prepare("
            SELECT
                p.id,
                p.full_name,
                p.primary_email,
                p.phone,
                p.status
            FROM people p
            WHERE p.id = :person_id
            LIMIT 1
        ");
        $stmt->execute(['person_id' => $personId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function tech_fetch_active_people(): array
{
    try {
        $stmt = db()->query("
            SELECT
                p.id,
                p.full_name,
                p.primary_email,
                GROUP_CONCAT(DISTINCT g.group_name ORDER BY g.group_name SEPARATOR ', ') AS group_names
            FROM people p
            LEFT JOIN group_memberships gm
              ON gm.person_id = p.id
             AND gm.status = 'active'
            LEFT JOIN groups g
              ON g.id = gm.group_id
             AND g.is_active = 1
            WHERE p.status = 'active'
            GROUP BY
                p.id,
                p.full_name,
                p.primary_email
            ORDER BY p.full_name ASC, p.primary_email ASC
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

function tech_fetch_active_person(int $selectedPersonId): ?array
{
    if ($selectedPersonId < 1) {
        return null;
    }

    try {
        $stmt = db()->prepare("
            SELECT
                p.id,
                p.full_name,
                p.primary_email,
                p.phone,
                p.status,
                GROUP_CONCAT(DISTINCT g.group_name ORDER BY g.group_name SEPARATOR ', ') AS group_names
            FROM people p
            LEFT JOIN group_memberships gm
              ON gm.person_id = p.id
             AND gm.status = 'active'
            LEFT JOIN groups g
              ON g.id = gm.group_id
             AND g.is_active = 1
            WHERE p.id = :person_id
              AND p.status = 'active'
            GROUP BY
                p.id,
                p.full_name,
                p.primary_email,
                p.phone,
                p.status
            LIMIT 1
        ");
        $stmt->execute(['person_id' => $selectedPersonId]);

        $person = $stmt->fetch(PDO::FETCH_ASSOC);

        return $person ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function tech_reference(): string
{
    return 'TS-' . date('Ymd-His') . '-' . random_int(1000, 9999);
}

function tech_email_template(string $title, string $bodyHtml): string
{
    return '
        <div style="font-family: Arial, sans-serif; line-height: 1.5; color: #1d1d1b;">
            <h2 style="color: #4d0b93;">' . e($title) . '</h2>
            ' . $bodyHtml . '
        </div>
    ';
}

function tech_queue_email(
    string $toEmail,
    ?string $toName,
    string $subject,
    string $bodyHtml,
    int $createdByPersonId,
    string $notificationType,
    array $extra = []
): void {
    $toEmail = strtolower(trim($toEmail));

    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('A valid email address could not be found for ' . ($toName ?: 'the recipient') . '.');
    }

    $plain = tech_plain_from_html($bodyHtml);

    $queueData = array_merge([
        'to_email' => $toEmail,
        'to_name' => $toName,
        'subject' => $subject,
        'body' => tech_column_exists('email_queue', 'body_html') ? $plain : $bodyHtml,
        'body_html' => $bodyHtml,
        'body_markdown' => null,
        'status' => 'pending',
        'created_by_person_id' => $createdByPersonId,
        'notification_type' => $notificationType,
        'related_entity_type' => 'technical_support',
        'related_entity_id' => null,
    ], $extra);

    tech_insert_email_queue($queueData);
}

function tech_audit(int $actorPersonId, string $action, array $details = []): void
{
    if (!tech_table_exists('audit_log')) {
        return;
    }

    try {
        $stmt = db()->prepare("
            INSERT INTO audit_log (
                actor_type,
                actor_person_id,
                action,
                entity_type,
                entity_id,
                details_json,
                created_at
            )
            VALUES (
                'person',
                :actor_person_id,
                :action,
                'technical_support',
                NULL,
                :details_json,
                NOW()
            )
        ");

        $stmt->execute([
            'actor_person_id' => $actorPersonId,
            'action' => $action,
            'details_json' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    } catch (Throwable $e) {
        // Do not fail support submission because audit logging failed.
    }
}

$currentPerson = tech_fetch_current_person($personId);
$currentUserName = tech_current_user_name($user);
$currentUserEmail = tech_current_user_email($user, $currentPerson);
$activePeople = tech_fetch_active_people();

$form = [
    'raise_for' => (string) ($_POST['raise_for'] ?? 'self'),
    'on_behalf_person_id' => (int) ($_POST['on_behalf_person_id'] ?? 0),
    'issue_type' => (string) ($_POST['issue_type'] ?? ''),
    'summary' => trim((string) ($_POST['summary'] ?? '')),
    'details' => trim((string) ($_POST['details'] ?? '')),
    'affected_url' => trim((string) ($_POST['affected_url'] ?? '')),
];

if (!in_array($form['raise_for'], ['self', 'other'], true)) {
    $form['raise_for'] = 'self';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selectedIssueType = $issueTypes[$form['issue_type']] ?? '';
    $affectedPerson = $currentPerson;
    $affectedPersonId = $personId;

    if ($form['raise_for'] === 'other') {
        $affectedPerson = tech_fetch_active_person($form['on_behalf_person_id']);
        $affectedPersonId = (int) ($affectedPerson['id'] ?? 0);
    }

    if ($form['raise_for'] === 'other' && !$affectedPerson) {
        $error = 'Choose the person you are raising this for.';
    } elseif ($selectedIssueType === '') {
        $error = 'Choose what the issue relates to.';
    } elseif ($form['summary'] === '') {
        $error = 'Enter a short summary.';
    } elseif ($form['details'] === '') {
        $error = 'Enter the details of the issue.';
    } elseif ($currentUserEmail === '') {
        $error = 'Your account does not have a valid email address, so a confirmation cannot be sent.';
    } else {
        $raisedReference = tech_reference();
        $affectedName = tech_person_name($affectedPerson ?: $currentPerson);
        $affectedEmail = strtolower(trim((string) ($affectedPerson['primary_email'] ?? '')));
        $affectedGroups = trim((string) ($affectedPerson['group_names'] ?? ''));

        $detailsHtml = nl2br(e($form['details']));
        $urlHtml = $form['affected_url'] !== ''
            ? '<p><strong>Link or page:</strong><br><a href="' . e($form['affected_url']) . '">' . e($form['affected_url']) . '</a></p>'
            : '';

        $confirmationBody = tech_email_template('Support request raised', '
            <p>Hello ' . e($currentUserName) . ',</p>

            <p>Your technical support request has been raised.</p>

            <p><strong>Reference:</strong> ' . e($raisedReference) . '</p>
            <p><strong>Type:</strong> ' . e($selectedIssueType) . '</p>
            <p><strong>Summary:</strong> ' . e($form['summary']) . '</p>

            ' . ($form['raise_for'] === 'other'
                ? '<p><strong>Raised for:</strong> ' . e($affectedName) . '</p>'
                : '') . '

            <p>The support team will review it and get back to you.</p>

            <hr>

            <p><strong>Details submitted:</strong></p>
            <p>' . $detailsHtml . '</p>
            ' . $urlHtml . '
        ');

        $supportBody = tech_email_template('New technical support request', '
            <p>A new technical support request has been raised from the District App.</p>

            <p><strong>Reference:</strong> ' . e($raisedReference) . '</p>
            <p><strong>Type:</strong> ' . e($selectedIssueType) . '</p>
            <p><strong>Summary:</strong> ' . e($form['summary']) . '</p>

            <h3>Raised by</h3>
            <p>
                <strong>Name:</strong> ' . e($currentUserName) . '<br>
                <strong>Email:</strong> <a href="mailto:' . e($currentUserEmail) . '">' . e($currentUserEmail) . '</a><br>
                <strong>Person ID:</strong> ' . (int) $personId . '
            </p>

            <h3>Affected person</h3>
            <p>
                <strong>Name:</strong> ' . e($affectedName) . '<br>
                <strong>Email:</strong> ' . ($affectedEmail !== '' ? '<a href="mailto:' . e($affectedEmail) . '">' . e($affectedEmail) . '</a>' : 'Not set') . '<br>
                <strong>Person ID:</strong> ' . (int) $affectedPersonId . '<br>
                <strong>Groups:</strong> ' . e($affectedGroups !== '' ? $affectedGroups : 'Not linked')
                . '
            </p>

            <h3>Issue details</h3>
            <p>' . $detailsHtml . '</p>
            ' . $urlHtml . '
        ');

        $pdo->beginTransaction();

        try {
            tech_queue_email(
                $currentUserEmail,
                $currentUserName,
                'Support request raised: ' . $form['summary'],
                $confirmationBody,
                $personId,
                'technical_support_confirmation',
                [
                    'reply_to_email' => $supportEmail,
                    'reply_to_name' => 'Irwell Valley Technical Support',
                ]
            );

            tech_queue_email(
                $supportEmail,
                'Irwell Valley Technical Support',
                '[' . $raisedReference . '] ' . $selectedIssueType . ': ' . $form['summary'],
                $supportBody,
                $personId,
                'technical_support_request',
                [
                    'reply_to_email' => $currentUserEmail,
                    'reply_to_name' => $currentUserName,
                ]
            );

            tech_audit($personId, 'technical_support_request_raised', [
                'reference' => $raisedReference,
                'issue_type' => $form['issue_type'],
                'issue_type_label' => $selectedIssueType,
                'summary' => $form['summary'],
                'raised_by_person_id' => $personId,
                'raised_by_email' => $currentUserEmail,
                'affected_person_id' => $affectedPersonId,
                'raised_for_other' => $form['raise_for'] === 'other',
            ]);

            $pdo->commit();

            $success = 'Support request raised. Reference: ' . $raisedReference;

            $form = [
                'raise_for' => 'self',
                'on_behalf_person_id' => 0,
                'issue_type' => '',
                'summary' => '',
                'details' => '',
                'affected_url' => '',
            ];
        } catch (Throwable $e) {
            $pdo->rollBack();
            $error = 'The support request could not be raised. ' . $e->getMessage();
        }
    }
}

?>
<?php include __DIR__ . '/header.php'; ?>

<style>
    .support-layout {
        display: grid;
        gap: 1rem;
    }

    @media (min-width: 1000px) {
        .support-layout {
            grid-template-columns: minmax(0, 1fr) 320px;
            align-items: start;
        }
    }

    .support-panel,
    .support-side-card {
        background: #ffffff;
        border: 2px solid #e6e6e6;
        border-radius: 0;
        padding: 1.25rem;
        box-shadow: none;
    }

    .support-panel h2,
    .support-side-card h2 {
        margin: 0 0 .85rem;
        color: #4d0b93;
        font-size: 1.35rem;
        font-weight: 900;
    }

    .support-radio-stack {
        display: grid;
        gap: .5rem;
    }

    .support-hidden {
        display: none;
    }

    .support-actions {
        display: flex;
        flex-wrap: wrap;
        gap: .75rem;
        align-items: center;
    }

    .support-side-card {
        border-left: 8px solid #7413dc;
    }

    @media (min-width: 1000px) {
        .support-side-card {
            position: sticky;
            top: 1rem;
        }
    }

    .support-summary-item {
        border-top: 2px solid #e6e6e6;
        padding-top: .75rem;
        margin-top: .75rem;
        font-weight: 800;
    }

    .support-summary-item strong {
        display: block;
        color: #4d0b93;
        font-weight: 900;
    }

    @media (max-width: 575.98px) {
        .support-actions .btn {
            width: 100%;
        }
    }
</style>

<main class="lt-main">
    <?php if ($error): ?>
        <div class="alert alert-danger">
            <strong>There is a problem:</strong> <?= e($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success">
            <?= e($success) ?>
        </div>
    <?php endif; ?>

    <div class="support-layout">
        <section class="support-panel">
            <h2>Raise a support request</h2>

            <form method="post" novalidate>
                <fieldset class="form-group">
                    <legend class="h5 font-weight-bold">Who is this for?</legend>

                    <div class="support-radio-stack">
                        <label class="lt-check">
                            <input
                                type="radio"
                                name="raise_for"
                                value="self"
                                <?= $form['raise_for'] === 'self' ? 'checked' : '' ?>
                                data-raise-for
                            >
                            <span>Me</span>
                        </label>

                        <label class="lt-check">
                            <input
                                type="radio"
                                name="raise_for"
                                value="other"
                                <?= $form['raise_for'] === 'other' ? 'checked' : '' ?>
                                data-raise-for
                            >
                            <span>Another user</span>
                        </label>
                    </div>
                </fieldset>

                <div class="form-group support-hidden" id="on-behalf-wrap">
                    <label for="on_behalf_person_id">User</label>
                    <select
                        id="on_behalf_person_id"
                        name="on_behalf_person_id"
                        class="form-control"
                    >
                        <option value="0">Choose a user</option>
                        <?php foreach ($activePeople as $person): ?>
                            <?php
                            $optionPersonId = (int) $person['id'];
                            $optionLabel = tech_person_name($person);
                            $optionEmail = trim((string) ($person['primary_email'] ?? ''));
                            $optionGroups = trim((string) ($person['group_names'] ?? ''));
                            ?>
                            <option value="<?= $optionPersonId ?>" <?= (int) $form['on_behalf_person_id'] === $optionPersonId ? 'selected' : '' ?>>
                                <?= e($optionLabel) ?>
                                <?= $optionEmail !== '' ? ' — ' . e($optionEmail) : '' ?>
                                <?= $optionGroups !== '' ? ' — ' . e($optionGroups) : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="issue_type">What does this relate to?</label>
                    <select id="issue_type" name="issue_type" class="form-control" required>
                        <option value="">Choose a type</option>
                        <?php foreach ($issueTypes as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= $form['issue_type'] === $value ? 'selected' : '' ?>>
                                <?= e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="summary">Short summary</label>
                    <input
                        type="text"
                        id="summary"
                        name="summary"
                        class="form-control"
                        value="<?= e($form['summary']) ?>"
                        maxlength="180"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="details">Details</label>
                    <textarea
                        id="details"
                        name="details"
                        class="form-control"
                        rows="7"
                        required
                    ><?= e($form['details']) ?></textarea>
                </div>

                <div class="form-group">
                    <label for="affected_url">Link or page, if relevant</label>
                    <input
                        type="text"
                        id="affected_url"
                        name="affected_url"
                        class="form-control"
                        value="<?= e($form['affected_url']) ?>"
                        placeholder="https://..."
                    >
                </div>

                <div class="support-actions">
                    <button type="submit" class="btn btn-primary btn-lg lt-btn">
                        Raise support request
                    </button>

                    <a href="/index.php" class="btn lt-btn lt-btn-secondary">
                        Back to dashboard
                    </a>
                </div>
            </form>
        </section>

        <aside class="support-side-card">
            <h2>Your details</h2>

            <div class="support-summary-item">
                <strong>Name</strong>
                <?= e($currentUserName) ?>
            </div>

            <div class="support-summary-item">
                <strong>Email</strong>
                <?= e($currentUserEmail !== '' ? $currentUserEmail : 'Not found') ?>
            </div>

            <div class="support-summary-item">
                <strong>Support mailbox</strong>
                <?= e($supportEmail) ?>
            </div>
        </aside>
    </div>
</main>

<script>
(function () {
    var radios = document.querySelectorAll('[data-raise-for]');
    var onBehalfWrap = document.getElementById('on-behalf-wrap');

    function updateOnBehalfVisibility() {
        var selected = document.querySelector('[data-raise-for]:checked');

        if (!selected || !onBehalfWrap) {
            return;
        }

        onBehalfWrap.classList.toggle('support-hidden', selected.value !== 'other');
    }

    radios.forEach(function (radio) {
        radio.addEventListener('change', updateOnBehalfVisibility);
    });

    updateOnBehalfVisibility();
}());
</script>

<?php include __DIR__ . '/footer.php'; ?>