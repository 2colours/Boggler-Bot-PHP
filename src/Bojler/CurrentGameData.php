<?php

namespace Bojler;

use Exception;

class CurrentGameData
{
    private function __construct(
        public private(set) array $letters,
        public private(set) array $found_words,
        public private(set) int $game_number,
        public private(set) string $current_lang,
        public private(set) string $base_lang,
        public private(set) string $planned_lang,
        public private(set) string $max_saved_game
    ) {
    }

    # TODO remove this
    public static function fromLegacyFile(string $legacy_file): self
    {
        $content = file($legacy_file, FILE_IGNORE_NEW_LINES);
        if ($content === false || count($content) < 10) {
            throw new Exception('Save file wrong.');
        }
        $ctor_dict = [];
        # Current Game
        $ctor_dict['letters'] = explode(' ', $content[1]);
        $ctor_dict['found_words'] = $content[2] === '' ? [] : explode(' ', $content[2]);
        $ctor_dict['game_number'] = (int) explode("\t", $content[3])[1];
        $ctor_dict['current_lang'] = explode("\t", $content[4])[1];
        # General Settings
        $ctor_dict['base_lang'] = explode("\t", $content[7])[1];
        $ctor_dict['planned_lang'] = explode("\t", $content[8])[1];
        $ctor_dict['max_saved_game'] = (int) explode("\t", $content[9])[1];
        return new self(...$ctor_dict);
    }

    public static function fromJsonFile(string $json_file): self
    {
        $content = json_decode(file_get_contents($json_file), true);
        return new self(...$content);
    }

    public static function fromStatusObject(GameStatus $status): self
    {
        $ctor_dict = [];
        $ctor_dict['letters'] = $status->letters->list;
        $ctor_dict['found_words'] = $status->found_words->toArray();
        $ctor_dict['game_number'] = $status->game_number;
        $ctor_dict['current_lang'] = $status->current_lang;
        $ctor_dict['base_lang'] = $status->base_lang;
        $ctor_dict['planned_lang'] = $status->planned_lang;
        $ctor_dict['max_saved_game'] = $status->max_saved_game;
        return new self(...$ctor_dict);
    }

    #TODO remove this
    public function toLegacyEntry(): string
    {
        $space_separated_letters = implode(' ', $this->letters);
        $space_separated_found_words = implode(' ', $this->found_words);
        return <<<END
            #Current Game
            $space_separated_letters
            $space_separated_found_words
            Game Number\t{$this->game_number}
            Game Language\t{$this->current_lang}

            # General Settings
            Base Language\t{$this->base_lang}
            Planned Language\t{$this->planned_lang}
            Saved Games\t{$this->max_saved_game}
            END;
    }
}
