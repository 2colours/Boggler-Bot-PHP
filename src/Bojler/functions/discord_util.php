<?php

namespace Bojler;

use Discord\Parts\User\Member;

function hungarian_role(Member $member)
{
    $hungarian_roles = ['Beginner', 'Native speaker', 'Intermediate', 'Fluent', 'Advanced', 'Distant native'];
    $roles = $member->roles;
    foreach ($roles as $item) {
        foreach ($hungarian_roles as $hu_role) {
            if ($item->name === $hu_role) {
                return $hu_role;
            }
        }
    }
    return '';
}

function discord_specific_fields(Member $member)
{
    return [
        'name' => $member->username,
        'role' => hungarian_role($member),
        'server_name' => name_shortened($member->nick ?? $member->username)
    ];
}
