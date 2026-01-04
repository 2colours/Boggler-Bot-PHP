<?php

namespace Bojler;

class ArchiveGameEntryData
{
    private function __construct(
        public private(set) array $letters_sorted,
        public private(set) array $found_words_sorted,
        public private(set) int $game_number,
        public private(set) string $current_lang
    ) {
    }

    public static function fromJsonFile(string $json_file, int $number): self
    {
        $entry = json_decode(file_get_contents($json_file), true)[$number - 1];
        return new self(...$entry);
    }

    public static function fromStatus(GameStatus $status): self
    {
        $ctor_dict = [];
        $letters_sorted = $status->lettersSorted();
        $ctor_dict['letters_sorted'] = $letters_sorted;
        $ctor_dict['found_words_sorted'] = $status->foundWordsSorted();
        $ctor_dict['game_number'] = $status->game_number;
        $ctor_dict['current_lang'] = $status->current_lang;
        return new self(...$ctor_dict);
    }
}
