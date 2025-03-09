<?php

namespace Bojler;

use Exception;

class CurrentGameData
{
    public private(set) array $letters;
    public private(set) array $found_words;
    public private(set) int $game_number;
    public private(set) string $current_lang;
    public private(set) string $base_lang;
    public private(set) string $planned_lang;
    public private(set) string $max_saved_game;

    public static function fromLegacyFile(string $legacy_file): CurrentGameData
    {
        $content = file($legacy_file, FILE_IGNORE_NEW_LINES);
        if ($content === false || count($content) < 10) {
            throw new Exception('Save file wrong.');
        }
        $instance = new self();
        # Current Game
        $instance->letters = explode(' ', $content[1]);
        $instance->found_words = $content[2] === '' ? [] : explode(' ', $content[2]);
        $instance->game_number = (int) explode("\t", $content[3])[1];
        $instance->current_lang = explode("\t", $content[4])[1];
        # General Settings
        $instance->base_lang = explode("\t", $content[7])[1];
        $instance->planned_lang = explode("\t", $content[8])[1];
        $instance->max_saved_game = (int) explode("\t", $content[9])[1];
        return $instance;
    }

    public static function fromJsonFile(string $json_file): CurrentGameData
    {
        $content = json_decode(file_get_contents($json_file), false);
        $instance = new self();
        foreach ($instance as $prop_name => &$value) {
            $value = $content->$prop_name;
        }
        return $instance;
    }

    public static function fromStatusObject(GameStatus $status): CurrentGameData
    {
        $instance = new self();
        $instance->letters = $status->letters->list;
        $instance->found_words = $status->found_words->toArray();
        $instance->game_number = $status->game_number;
        $instance->current_lang = $status->current_lang;
        $instance->base_lang = $status->base_lang;
        $instance->planned_lang = $status->planned_lang;
        $instance->max_saved_game = $status->max_saved_game;
        return $instance;
    }
}
