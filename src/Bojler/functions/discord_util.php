<?php

namespace Bojler;

use Discord\Parts\Channel\Message;
use Discord\Parts\User\Member;

use function React\Async\await;

function try_send_msg(Message $ctx, string $content)
{
    $can_be_sent = grapheme_strlen($content) <= 2000; # TODO this magic constant should be moved from here and other places as well
    if ($can_be_sent) {
        await($ctx->channel->sendMessage($content));
    }
    return $can_be_sent;
}

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
