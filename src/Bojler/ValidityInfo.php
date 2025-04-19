<?php

namespace Bojler;

use Iterator;

class ValidityInfo
{
    private array $problematic_letters = []; # letter => [needed_count, available_count]

    public static function listProblems(array $input_dictionary, array $reference_dictionary): Iterator
    {
        foreach (array_keys($input_dictionary) as $letter) {
            if ($input_dictionary[$letter] > ($reference_dictionary[$letter] ?? 0)) {
                yield $letter;
            }
        }
    }

    public function __construct(private string $given_word, array $input_dictionary, array $reference_dictionary)
    {
        foreach (self::listProblems($input_dictionary, $reference_dictionary) as $letter) {
            $this->problematic_letters[$letter] = [$input_dictionary[$letter], $reference_dictionary[$letter] ?? 0];
        }
    }

    public function messageWhenValid(): ?string
    {
        if (count($this->problematic_letters) === 0) {
            return null;
        }

        $result = "**{$this->given_word}** doesn't fit the given letters:";
        foreach ($this->problematic_letters as $letter => [$needed_count, $available_count]) {
            $italic_letter = italic($letter);
            $result .= "\n$italic_letter: **$needed_count** in the word, **$available_count** available";
        }
        return $result;
    }
}
