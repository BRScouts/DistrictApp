<?php

declare(strict_types=1);

function portal_role_options(): array
{
    return [
        'Group Lead Volunteer',
        'Group Leadership Team Member',
        'Squirrel Section Team Leader',
        'Squirrel Section Team Member',
        'Beaver Section Team Leader',
        'Beaver Section Team Member',
        'Cub Section Team Leader',
        'Cub Section Team Member',
        'Scout Section Team Leader',
        'Scout Section Team Member',
        'Group Chair',
        'Group Treasurer',
        'Group Trustee',
    ];
}

function portal_membership_role_from_title(string $roleTitle): string
{
    $roleTitle = strtolower($roleTitle);

    if (str_contains($roleTitle, 'group lead volunteer')) {
        return 'group_lead_volunteer';
    }

    if (str_contains($roleTitle, 'group leadership')) {
        return 'group_leadership_team_member';
    }

    if (str_contains($roleTitle, 'squirrel') && str_contains($roleTitle, 'leader')) {
        return 'squirrel_section_team_leader';
    }

    if (str_contains($roleTitle, 'squirrel') && str_contains($roleTitle, 'member')) {
        return 'squirrel_section_team_member';
    }

    if (str_contains($roleTitle, 'beaver') && str_contains($roleTitle, 'leader')) {
        return 'beaver_section_team_leader';
    }

    if (str_contains($roleTitle, 'beaver') && str_contains($roleTitle, 'member')) {
        return 'beaver_section_team_member';
    }

    if (str_contains($roleTitle, 'cub') && str_contains($roleTitle, 'leader')) {
        return 'cub_section_team_leader';
    }

    if (str_contains($roleTitle, 'cub') && str_contains($roleTitle, 'member')) {
        return 'cub_section_team_member';
    }

    if (str_contains($roleTitle, 'scout section') && str_contains($roleTitle, 'leader')) {
        return 'scout_section_team_leader';
    }

    if (str_contains($roleTitle, 'scout section') && str_contains($roleTitle, 'member')) {
        return 'scout_section_team_member';
    }

    if (str_contains($roleTitle, 'group chair')) {
        return 'group_chair';
    }

    if (str_contains($roleTitle, 'group treasurer')) {
        return 'group_treasurer';
    }

    if (str_contains($roleTitle, 'group trustee') || str_contains($roleTitle, 'trustee')) {
        return 'group_trustee';
    }

    // Legacy fallbacks for existing data
    if (str_contains($roleTitle, 'assistant section leader')) {
        return 'group_leadership_team_member';
    }

    if (str_contains($roleTitle, 'section leader')) {
        return 'scout_section_team_leader';
    }

    if (str_contains($roleTitle, 'section assistant')) {
        return 'scout_section_team_member';
    }

    if (str_contains($roleTitle, 'district')) {
        return 'group_leadership_team_member';
    }

    return 'group_leadership_team_member';
}

function portal_access_level_from_membership_role(string $membershipRole): string
{
    return $membershipRole === 'group_lead_volunteer' ? 'group_admin' : 'member';
}

function portal_accreditation_options(): array
{
    return [
        'Nights Away' => [
            'Nights Away Permit Holder',
            'Nights Away Adviser',
            'Greenfield Nights Away',
            'Lightweight Expedition Nights Away',
            'Indoor Nights Away',
            'Campsite Nights Away',
        ],
        'Activity Permits' => [
            'Archery Permit',
            'Air Rifle Shooting Permit',
            'Tomahawk Throwing Permit',
            'Climbing Permit',
            'Abseiling Permit',
            'Bouldering Permit',
            'Caving Permit',
            'Hillwalking Permit',
            'Mountain Biking Permit',
            'Canoeing Permit',
            'Kayaking Permit',
            'Stand Up Paddleboarding Permit',
            'Rafting Permit',
            'Sailing Permit',
            'Windsurfing Permit',
            'Powerboating Permit',
            'Pulling / Rowing Permit',
            'Bell Boating Permit',
        ],
        'Training / Support' => [
            'First Response Trainer',
            'First Response Assessor',
            'Safeguarding Trainer',
            'Safety Trainer',
            'Learning Assessor',
            'Training Adviser',
            'Skills Instructor',
            'Activity Assessor',
            'Permit Assessor',
        ],
        'Other' => [
            'Minibus Driver',
            'D1 Driver',
            'Trailer Towing',
            'Food Hygiene',
            'Event First Aid',
            'Mental Health First Aid',
        ],
    ];
}

function portal_flatten_options(array $groupedOptions): array
{
    $flat = [];

    foreach ($groupedOptions as $items) {
        foreach ($items as $item) {
            $flat[] = $item;
        }
    }

    return $flat;
}

function portal_decode_json_list(?string $value): array
{
    $value = trim((string) $value);

    if ($value === '') {
        return [];
    }

    $decoded = json_decode($value, true);

    if (!is_array($decoded)) {
        return [];
    }

    return array_values(array_filter(array_map('strval', $decoded)));
}
