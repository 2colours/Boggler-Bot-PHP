<?php

namespace Bojler;

readonly class CurrentGameData
{
    private function __construct(
        public array $letters,
        public array $found_words,
        public int $game_number,
        public string $current_lang,
        public string $base_lang,
        public string $planned_lang,
        public string $max_saved_game
    ) {
    }

    public static function fromJsonFile(string $json_file): self
    {
        $content = json_decode(file_get_contents($json_file), true);
        return new self(...$content);
    }

    public static function fromStatus(GameManager $status): self
    {
        $ctor_dict = [];
        $current_game = $status->current_game;
        $ctor_dict['letters'] = $current_game->letters->list;
        $ctor_dict['found_words'] = $current_game->found_words->toArray();
        $ctor_dict['game_number'] = $current_game->game_number;
        $ctor_dict['current_lang'] = $current_game->current_lang;
        $ctor_dict['base_lang'] = $status->base_lang;
        $ctor_dict['planned_lang'] = $status->planned_lang;
        $ctor_dict['max_saved_game'] = $status->max_saved_game;
        return new self(...$ctor_dict);
    }
}
