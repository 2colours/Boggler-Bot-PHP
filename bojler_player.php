<?php

use Discord\Parts\Channel\Message;
use Discord\Parts\User\Member;

function hungarian_role(Member $member)
{
    $hungarian_roles = ['Beginner', 'Native speaker', 'Intermediate', 'Fluent', 'Advanced', 'Distant native'];
    $roles = $member->roles;
    foreach ($roles as $item)
        foreach ($hungarian_roles as $hu_role)
            if ($item->name === $hu_role)
                return $hu_role;
    return '';
}

function name_shortened($name)
{
    if (grapheme_strlen($name) >= 15) {
        $name = grapheme_substr($name, 0, grapheme_strpos($name, '|'));
    }
    if (grapheme_strlen($name) >= 15) {
        $name = grapheme_substr($name, 0, grapheme_strpos($name, ' '));
    }
    if (grapheme_strlen($name) >= 15) {
        $name = grapheme_substr($name, 0, 15);
    }
    return $name;
}

class PlayerHandler
{
    private static $instance;

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }


    public const PLAYER_SAVES_PATH = 'live_data/player_saves.json';
    public $player_dict;

    private function __construct()
    {
        $read_content = file_get_contents(self::PLAYER_SAVES_PATH);
        if ($read_content === false) {
            $read_content = '{}';
            file_put_contents(self::PLAYER_SAVES_PATH, $read_content);
        }
        $this->player_dict = json_decode($read_content, true);
        foreach ($this->player_dict as &$player_data) {
            $player_data = array_merge($this->player_dict['default_user'], $player_data);
        }
        $this->saveFile();
    }

    public function getPlayerField(string $id, $field_name)
    {
        return $this->player_dict[$id][$field_name];
    }

    public function setEmoji(string $id, $emoji)
    {
        $this->player_dict[$id]['personal_emoji'] = $emoji;
        $this->saveFile();
    }

    public function saveFile()
    {
        file_put_contents(self::PLAYER_SAVES_PATH, json_encode($this->player_dict, JSON_FORCE_OBJECT));
    }

    public function newPlayer(Member $member)
    {
        $this->player_dict[$member->user->id] = [
            'name' => $member->username,
            'found_words' => [],
            'used_hints' => [],
            'all_time_found' => 0,
            'all_time_approved' => 0,
            'personal_emoji' => '👤',
            'role' => hungarian_role($member),
            'server_name' => name_shortened($member->displayname)
        ];
    }

    public function newGame()
    {
        foreach ($this->player_dict as $player_data) {
            $player_data['found_words'] = [];
            $player_data['used_hints'] = [];
        }
        $this->saveFile();
    }

    private function playerUpdate(Member $player)
    {
        if (!array_key_exists($player->user->id, $this->player_dict)) {
            $this->newPlayer($player);
            echo $this->player_dict[$player->user->id]; # TODO why?
        } else {
            echo $this->player_dict[$player->user->id]; # TODO why?
            $this->player_dict[$player->user->id] = array_merge(
                $this->player_dict['default_user'],
                $this->player_dict[$player->user->id],
                [
                    'role' => hungarian_role($player),
                    'name' => $player->username,
                    'server_name' => name_shortened($player->displayname),
                ]
            );
        }
    }

    public function playerAddWord(Message $ctx, $word_info)
    {
        $this->playerUpdate($ctx->member);
        $this->player_dict[$ctx->author->id]['found_words'][] = $word_info['word'];
        $this->player_dict[$ctx->author->id]['all_time_found']++;
        if (array_key_exists('any', $word_info))
            $this->player_dict[$ctx->author->id]['all_time_approved']++;
        $this->saveFile();
    }

    public function playerRemoveWord(Message $ctx, $word_info)
    {
        # TODO revise the necessity of this code and preferably delete it
        /*
        $this->playerUpdate($ctx->member);
        if (array_key_exists($word_info['word'], $this->player_dict[$ctx->author->id]['found_words'])) {
            $current_found_words = &$this->player_dict[$ctx->author->id]['found_words'];
            $current_found_words = array_diff($current_found_words, [$word_info["word"]]);
            $this->player_dict[$ctx->author->id]['all_time_found']--;
            if (array_key_exists('any', $word_info))
              $this->player_dict[$ctx->author->id]['all_time_approved']++;
        }*/
        foreach ($this->player_dict as $player_data)
            if (array_key_exists($word_info['word'], $player_data['found_words'])) {
                $player_data['found_words'] = array_diff($player_data['found_words'], [$word_info['word']]);
                $player_data['all_time_found']--;
                if (array_key_exists('any', $word_info))
                    $this->player_dict[$ctx->author->id]['all_time_approved']++;
            }
        # TODO only save when there is a change (Is that guaranteed at the call of this function?)
        $this->saveFile();
    }

    public function playerUsedHint(Message $ctx, $word)
    {
        $this->playerUpdate($ctx->member);
        $this->player_dict[$ctx->author->id]['used_hints'][] = $word;
        $this->saveFile();
    }
}
