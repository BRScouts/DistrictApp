<?php

declare(strict_types=1);

namespace DistrictApp\Tests\Support;

/**
 * The expected seed content for migration 005 (help_articles).
 *
 * This mirrors the design's Seed Content Plan: each of the 14 articles with its
 * slug, grouping topic, and the real application action target(s) its Rich_Body
 * must link to. The example tests assert the migrated rows match this exactly.
 */
final class HelpSeedExpectations
{
    /**
     * slug => [topic, [action target URLs...]]
     *
     * @return array<string, array{topic: string, targets: list<string>}>
     */
    public static function articles(): array
    {
        return [
            'adding-new-members' => [
                'topic'   => 'People & Access',
                'targets' => ['/dc/add-user.php'],
            ],
            'adding-an-event' => [
                'topic'   => 'Events',
                'targets' => ['/dc/add-event.php'],
            ],
            'searching-risk-assessments' => [
                'topic'   => 'Risk Assessments',
                'targets' => ['/dc/risk-assessments.php', '/dc/download-risk-assessment.php'],
            ],
            'editing-events' => [
                'topic'   => 'Events',
                'targets' => ['/dc/manage-event.php', '/dc/event.php'],
            ],
            'approving-events' => [
                'topic'   => 'Events',
                'targets' => ['/dc/reviewer/'],
            ],
            'updating-your-profile' => [
                'topic'   => 'People & Access',
                'targets' => ['/profile.php'],
            ],
            'creating-a-district-email' => [
                'topic'   => 'Email & Microsoft 365',
                'targets' => ['/group-manager.php'],
            ],
            'using-a-shared-mailbox' => [
                'topic'   => 'Email & Microsoft 365',
                'targets' => ['https://outlook.cloud.microsoft/'],
            ],
            'why-a-shared-mailbox' => [
                'topic'   => 'Email & Microsoft 365',
                'targets' => ['https://outlook.cloud.microsoft/'],
            ],
            'navigating-the-calendar' => [
                'topic'   => 'Events',
                'targets' => ['/dc/event.php'],
            ],
            'finding-a-glv' => [
                'topic'   => 'People & Access',
                'targets' => ['/group-manager.php'],
            ],
            'requesting-support' => [
                'topic'   => 'Getting Help',
                'targets' => ['/technical-support.php'],
            ],
            'access-levels-and-roles' => [
                'topic'   => 'People & Access',
                'targets' => ['/group-manager.php'],
            ],
            'logging-in-with-microsoft' => [
                'topic'   => 'Email & Microsoft 365',
                'targets' => ['https://outlook.cloud.microsoft/'],
            ],
        ];
    }

    public const EXPECTED_COUNT = 14;

    /**
     * External action targets (must carry "external": true in the seed body).
     *
     * @return list<string>
     */
    public static function externalTargets(): array
    {
        return ['https://outlook.cloud.microsoft/'];
    }

    /**
     * Collect every action-block URL from a decoded Rich_Body, in order.
     *
     * @param array<int, mixed> $body
     * @return list<array{url: string, external: bool}>
     */
    public static function actionsFromBody(array $body): array
    {
        $actions = [];

        foreach ($body as $block) {
            if (!is_array($block) || ($block['type'] ?? null) !== 'action') {
                continue;
            }
            foreach (($block['actions'] ?? []) as $action) {
                if (!is_array($action) || !isset($action['url'])) {
                    continue;
                }
                $actions[] = [
                    'url'      => (string) $action['url'],
                    'external' => (bool) ($action['external'] ?? false),
                ];
            }
        }

        return $actions;
    }
}
