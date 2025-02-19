<?php

namespace Bojler;

use Discord\Parts\Channel\Message;
use Discord\Parts\User\Member;

use function React\Async\await;

const MAX_GRAPHEME_NUMBER = 2000;

function try_send_msg(Message $ctx, string $content)
{
    $can_be_sent = grapheme_strlen($content) <= MAX_GRAPHEME_NUMBER;
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

function progress_bar(GameStatus $game_status, string|null $emoji_scale_str = null): string
{
    $emoji_scale_str ??= current_emoji_version()[1];
    $emoji_scale = grapheme_str_split($emoji_scale_str);
    if (count($emoji_scale) < 2) {
        echo 'Error in config. Not enough symbols for progress bar.';
        return '';
    }
    $progress_bar_length = (int) ceil($game_status->end_amount / 10);
    if ($game_status->getApprovedAmount() >= $game_status->end_amount) {
        return str_repeat($emoji_scale[array_key_last($emoji_scale)], $progress_bar_length);
    }
    $full_emoji_number = intdiv($game_status->getApprovedAmount(), 10);
    $progress_bar = str_repeat($emoji_scale[array_key_last($emoji_scale)], $full_emoji_number);
    $rest = $game_status->end_amount - $full_emoji_number * 10;
    $current_step_size = min($rest, 10);
    $progress_in_current_step = intdiv(($game_status->getApprovedAmount() % 10), $current_step_size);
    $empty_emoji_number = $progress_bar_length - $full_emoji_number - 1;
    $progress_bar .= $emoji_scale[$progress_in_current_step * (count($emoji_scale) - 1)];
    $progress_bar .= str_repeat($emoji_scale[0], $empty_emoji_number);
    return $progress_bar;
}

function acknowledgement_reaction(string $word): string
{
    $word = remove_special_char($word);
    $word_length = grapheme_strlen($word);
    return match (true) {
        $word_length >= 10 => '💯',
        $word_length === 9 => '🤯',
        $word_length > 5 => '🎉',
        default => '👍'
    };
}

function strikethrough(string $text): string
{
    return $text === '' ? '' : "~~$text~~";
}


function italic(string $text): string
{
    return $text === '' ? '' : "_{$text}_";
}