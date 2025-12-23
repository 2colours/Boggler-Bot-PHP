<?php

namespace Bojler;

use Discord\Parts\{
    Channel\Message,
    User\Member,
};

class PlayerHandler
{
    public const PLAYER_SAVES_PATH = 'live_data/player_saves.json';
    private readonly array $default_player; # injection-dependent
    public $player_dict;

    public function __construct(ConfigHandler $config)
    {
        $this->default_player = $config->getPlayerDefaults();
        $read_content = file_get_contents(self::PLAYER_SAVES_PATH);
        if ($read_content === false) {
            $read_content = '{}';
            file_put_contents(self::PLAYER_SAVES_PATH, $read_content);
        }
        $read_dict = json_decode($read_content, true);
        $this->player_dict = array_map(
            fn($player_data) => array_merge($this->default_player, $player_data),
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

    public function playerAddWord(Message $ctx, ApprovalData $word_info)
    {
        $this->playerUpdate($ctx->member);
        $this->player_dict[$ctx->author->id]['found_words'][] = $word_info->word;
        $this->player_dict[$ctx->author->id]['all_time_found']++;
        if ($word_info->any) {
            $this->player_dict[$ctx->author->id]['all_time_approved']++;
        }
        $this->saveFile();
    }

    public function approveWord(string $word)
    {
        $content_changed = false;
        foreach ($this->player_dict as &$player_entry) {
            if (in_array($word, $player_entry['found_words'])) {
                $content_changed = true;
                $player_entry['all_time_approved']++;
            }
        }

        if ($content_changed) {
            $this->saveFile();
        }
    }

    public function playerRemoveWord(Message $ctx, ApprovalData $word_info)
    {
        foreach ($this->player_dict as &$player_data) {
            $word_index = array_search($word_info->word, $player_data['found_words']);
            if ($word_index !== false) {
                array_splice($player_data['found_words'], $word_index, 1);
                $player_data['all_time_found']--;
                if ($word_info->any) {
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
