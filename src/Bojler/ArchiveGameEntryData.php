<?php

namespace Bojler;

use Exception;

class ArchiveGameEntryData
{
    private function __construct(
        public private(set) array $letters_sorted,
        public private(set) array $found_words_sorted,
        public private(set) int $game_number,
        public private(set) string $current_lang
    ) {}

    public static function fromLegacyFile(string $legacy_file, int $number): self
    {
        $lines = file($legacy_file, FILE_IGNORE_NEW_LINES);
        $offset = 3 * ($number - 1);
        [$languages_line, $letters_line, $words_line] = array_slice($lines, $offset, 3);
        $ctor_dict = [];
        $ctor_dict['letters_sorted'] = explode(' ', $letters_line);
        $ctor_dict['found_words_sorted'] = $words_line === '' ? [] : explode(' ', $words_line);
        # set language and game number
        [$read_number, $read_lang] = explode(' ', $languages_line);
        $read_number = grapheme_substr($read_number, 0, grapheme_strlen($read_number) - 1);
        $read_lang = grapheme_substr($read_lang, 1, grapheme_strlen($read_lang) - 2);
        $ctor_dict['game_number'] = intval($read_number);
        $ctor_dict['current_lang'] = $read_lang;
        return new self(...$ctor_dict);
    }

    public static function fromJsonFile(string $json_file, int $number): self
    {
        $entry = json_decode(file_get_contents($json_file), true)[$number - 1];
        return new self(...$entry);
    }

    public static function fromStatusObject(GameStatus $status): self
    {
        $ctor_dict = [];
        $letters_sorted = $status->letters->list;
        $status->collator()->sort($letters_sorted);
        $ctor_dict['letters_sorted'] = $letters_sorted;
        $ctor_dict['found_words'] = $status->foundWordsSorted();
        $ctor_dict['game_number'] = $status->game_number;
        $ctor_dict['current_lang'] = $status->current_lang;
        return new self(...$ctor_dict);
    }

    public function toLegacyEntry(): string
    {
        $space_separated_letters_alphabetic = implode(' ', $this->letters_sorted);
        $space_separated_found_words_alphabetic = implode(' ', $this->found_words_sorted);
        return <<<END
            {$this->game_number}. ({$this->current_lang})
            $space_separated_letters_alphabetic
            $space_separated_found_words_alphabetic

            END;
    }
}
