<?php
/**
 * Group Manager service navigation bar.
 *
 * Include this partial on every group-manager page AFTER setting:
 *   - $selectedGroupId (int)
 *   - $manageableGroups (array)
 *   - $gmNavCurrent (string) — one of: 'people', 'add', 'inactive', 'access', 'details'
 *
 * This renders:
 *   1. A group selector dropdown (shown when user manages multiple groups)
 *   2. A persistent service navigation strip with links to each section
 */

$gmNavCurrent = $gmNavCurrent ?? 'people';

$gmNavItems = [
    ['key' => 'people',   'label' => 'People',           'href' => '/group-manager.php?group_id=' . (int) $selectedGroupId],
    ['key' => 'add',      'label' => 'Add person',       'href' => '/group-manager-add-person.php?group_id=' . (int) $selectedGroupId],
    ['key' => 'inactive', 'label' => 'Inactive',         'href' => '/group-manager-inactive.php?group_id=' . (int) $selectedGroupId],
    ['key' => 'access',   'label' => 'Calendar access',  'href' => '/group-manager-access.php?group_id=' . (int) $selectedGroupId],
    ['key' => 'details',  'label' => 'Group details',    'href' => '/group-manager-website.php?group_id=' . (int) $selectedGroupId],
];
?>

<style>
    .gm-service-bar {
        background: var(--iv-purple);
        border-bottom: 4px solid var(--iv-purple-dark);
    }

    .gm-service-bar-inner {
        max-width: 1180px;
        margin: 0 auto;
        padding: 0 1rem;
        display: flex;
        align-items: stretch;
        gap: 0;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }

    .gm-service-bar-inner::-webkit-scrollbar {
        height: 0;
    }

    .gm-nav-link {
        display: inline-flex;
        align-items: center;
        padding: .85rem 1.1rem;
        color: rgba(255, 255, 255, .88);
        font-weight: 900;
        font-size: .92rem;
        text-decoration: none;
        white-space: nowrap;
        border-bottom: 4px solid transparent;
        margin-bottom: -4px;
        transition: background .1s, border-color .1s;
    }

    .gm-nav-link:hover {
        color: #fff;
        background: rgba(255, 255, 255, .08);
        text-decoration: none;
    }

    .gm-nav-link:focus {
        outline: 3px solid var(--iv-yellow);
        outline-offset: -3px;
        color: #fff;
    }

    .gm-nav-link[aria-current="page"] {
        color: #fff;
        border-bottom-color: var(--iv-yellow);
        background: rgba(255, 255, 255, .06);
    }

    .gm-group-bar {
        background: var(--iv-grey-100);
        border-bottom: 1px solid var(--iv-grey-300);
    }

    .gm-group-bar-inner {
        max-width: 1180px;
        margin: 0 auto;
        padding: .6rem 1rem;
        display: flex;
        align-items: center;
        gap: .75rem;
        flex-wrap: wrap;
    }

    .gm-group-bar label {
        font-weight: 900;
        font-size: .9rem;
        color: var(--iv-grey-700);
        white-space: nowrap;
        margin: 0;
    }

    .gm-group-bar select {
        flex: 1;
        min-width: 180px;
        max-width: 420px;
        border: 2px solid var(--iv-grey-700);
        min-height: 38px;
        font-weight: 800;
        font-size: .9rem;
    }

    .gm-group-bar button {
        padding: .5rem 1rem;
        font-size: .88rem;
    }

    @media (max-width: 640px) {
        .gm-nav-link {
            padding: .75rem .85rem;
            font-size: .84rem;
        }
    }
</style>

<nav class="gm-service-bar" aria-label="Group Manager sections">
    <div class="gm-service-bar-inner">
        <?php foreach ($gmNavItems as $item): ?>
            <a
                class="gm-nav-link"
                href="<?= e($item['href']) ?>"
                <?= $gmNavCurrent === $item['key'] ? 'aria-current="page"' : '' ?>
            ><?= e($item['label']) ?></a>
        <?php endforeach; ?>
    </div>
</nav>

<?php if (count($manageableGroups) > 1): ?>
    <div class="gm-group-bar">
        <form class="gm-group-bar-inner" method="get" aria-label="Switch Group">
            <label for="gm-group-select">Managing:</label>
            <select class="form-control" id="gm-group-select" name="group_id">
                <?php foreach ($manageableGroups as $group): ?>
                    <option value="<?= (int) $group['id'] ?>" <?= (int) $group['id'] === $selectedGroupId ? 'selected' : '' ?>>
                        <?= e($group['group_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-primary lt-btn" type="submit">Switch</button>
        </form>
    </div>
<?php endif; ?>
