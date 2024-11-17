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

function highscore_names(array $ids, PlayerHandler $player_handler)
{
    if (count($ids) === 0) {
        return ' - ';
    }
    $names = [];
    foreach ($ids as $id) {
        array_push($names, $player_handler->player_dict[$id]['server_name'] ?? $player_handler->player_dict[$id]['name']);
    }
    return implode(', ', $names);
}


function on_podium(array $people, PlayerHandler $player_handler)
{
    switch (count($people)) {
        case 0:
            return '⬛⬛⬛';
        case 1:
            $personal_emoji = $player_handler->player_dict[$people[0]]['personal_emoji'];
            return "⬛{$personal_emoji}⬛";
        case 2:
            [$personal_emoji_first, $personal_emoji_second] = [
                $player_handler->player_dict[$people[0]]['personal_emoji'],
                $player_handler->player_dict[$people[1]]['personal_emoji'],
            ];
            return "{$personal_emoji_first}⬛{$personal_emoji_second}";
        case 3:
            [$personal_emoji_first, $personal_emoji_second, $personal_emoji_third] = [
                $player_handler->player_dict[$people[0]]['personal_emoji'],
                $player_handler->player_dict[$people[1]]['personal_emoji'],
                $player_handler->player_dict[$people[2]]['personal_emoji'],
            ];
            return "$personal_emoji_first$personal_emoji_second$personal_emoji_third";
        case 4:
            return '🧍🧑‍🤝‍🧑🧍';
        case 5:
            return '🧑‍🤝‍🧑🧍🧑‍🤝‍🧑';
        default:
            return '🧑‍🤝‍🧑🧑‍🤝‍🧑🧑‍🤝‍🧑';
    }
}


function game_highscore(GameStatus $status, PlayerHandler $player_handler)
{
    $awards = $status->gameAwards();
    [$on_podium_first, $on_podium_second, $on_podium_third] = [
        on_podium($awards['First place'], $player_handler),
        on_podium($awards['Second place'], $player_handler),
        on_podium($awards['Third place'], $player_handler),
    ];
    [$highscore_names_first, $highscore_names_second, $highscore_names_third] = [
        highscore_names($awards['First place'], $player_handler),
        highscore_names($awards['Second place'], $player_handler),
        highscore_names($awards['Third place'], $player_handler),
    ];
    $most_solved_hints = highscore_names($awards['Most solved hints'], $player_handler);
    $best_beginner = highscore_names($awards['Best Beginner'], $player_handler);
    $message = <<<END
        ⬛⬛⬛{$on_podium_first}⬛⬛⬛⬛***HIGHSCORE***
        {$on_podium_second}🟨🟨🟨⬛⬛⬛⬛**1.** $highscore_names_first
        🟨🟨🟨🟨🟨🟨{$on_podium_third}⬛**2.** $highscore_names_second
        🟨🟨🟨🟨🟨🟨🟨🟨🟨⬛**3.** $highscore_names_third

        *Most Solved Hints:* \t$most_solved_hints
        *Hard-Working Beginner:* \t$best_beginner
        END;
    if (count($awards['Newcomer']) !== 0) {
        $newcomer_highscore_names = highscore_names($awards['Newcomer'], $player_handler);
        $message .= "\n*Newcomer of the day:* $newcomer_highscore_names";
    }
    return $message;
}

function strikethrough(string $text): string
{
    return $text === '' ? '' : "~~$text~~";
}


function italic(string $text): string
{
    return $text === '' ? '' : "_{$text}_";
}