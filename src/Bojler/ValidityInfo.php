<?php

namespace Bojler;

class ValidityInfo
{
    private array $problematic_letters = []; # letter => [needed_count, available_count]

    public function __construct(private string $given_word, $processed_letters, $reference_dictionary)
    {
        $input_dictionary = array_count_values($processed_letters);

        foreach (array_keys($input_dictionary) as $letter) {
            if ($input_dictionary[$letter] > $reference_dictionary[$letter]) {
                $this->problematic_letters[$letter] = [$input_dictionary[$letter], $reference_dictionary[$letter]];
            }
        }
    }

    public function message_when_invalid(): ?string
    {
        if (count($this->problematic_letters) === 0) {
            return null;
        }

        $result = "{$this->given_word} doesn't fit the given letters:";
        foreach ($this->problematic_letters as $letter => [$needed_count, $available_count]) {
            $italic_letter = italic($letter);
            $result .= "\n$italic_letter: **$needed_count** in the word, **$available_count** available";
        }
        return $result;
    }
}