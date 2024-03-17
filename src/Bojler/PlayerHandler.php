<?php

namespace Bojler;

use Discord\Parts\{
    Channel\Message,
    User\Member,
};

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
    private readonly mixed $default_player;
    public $player_dict;

    private function __construct()
    {
        $this->default_player = ConfigHandler::getInstance()->get('default_player'); # TODO not sure if I like this "dependency injection"
        $read_content = file_get_contents(self::PLAYER_SAVES_PATH);
        if ($read_content === false) {
            $read_content = '{}';
            file_put_contents(self::PLAYER_SAVES_PATH, $read_content);
        }
        $read_dict = json_decode($read_content, true);
        $this->player_dict = array_map(
            fn ($player_data) => array_merge($this->default_player, $player_data),
            $read_dict
        );
        $this->saveFile();
    }

    public function getPlayerField(string|int $id, $field_name)
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
        file_put_contents(self::PLAYER_SAVES_PATH, json_encode((object)$this->player_dict, JSON_UNESCAPED_UNICODE));
    }

    public function newPlayer(Member $member)
    {
        $this->player_dict[$member->user->id] = array_merge(
            [
                'found_words' => [],
                'used_hints' => [],
                'all_time_found' => 0,
                'all_time_approved' => 0,
                'personal_emoji' => '👤'
            ],
            discord_specific_fields($member)
        );
    }

    public function newGame()
    {
        foreach ($this->player_dict as &$player_data) {
            $player_data['found_words'] = [];
            $player_data['used_hints'] = [];
        }
        $this->saveFile();
    }

    private function playerUpdate(Member $player)
    {
        if (!array_key_exists($player->user->id, $this->player_dict)) {
            $this->newPlayer($player);
            return;
        }

        $this->player_dict[$player->user->id] = array_merge(
            $this->default_player,
            $this->player_dict[$player->user->id],
            discord_specific_fields($player)
        );
    }

    public function playerAddWord(Message $ctx, $word_info)
    {
        $this->playerUpdate($ctx->member);
        $this->player_dict[$ctx->author->id]['found_words'][] = $word_info['word'];
        $this->player_dict[$ctx->author->id]['all_time_found']++;
        if ($word_info['any']) {
            $this->player_dict[$ctx->author->id]['all_time_approved']++;
        }
        $this->saveFile();
    }

    public function playerRemoveWord(Message $ctx, $word_info)
    {
        foreach ($this->player_dict as &$player_data) {
            $word_index = array_search($word_info['word'], $player_data['found_words']);
            if ($word_index !== false) {
                array_splice($player_data['found_words'], $word_index, 1);
                $player_data['all_time_found']--;
                if ($word_info['any']) {
                    $this->player_dict[$ctx->author->id]['all_time_approved']--;
                }
            }
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
