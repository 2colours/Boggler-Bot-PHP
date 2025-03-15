<?php

namespace Bojler;

#A dictionary entry that represents one record - needs to be changed when applying a migration
class DictionaryEntry
{
    public const TABLE_TYPES = ['TEXT', 'TEXT', 'INTEGER'];
    public const TABLE_COLUMNS = ['word', 'description', 'dictionarycode'];
    public const INDEXES = ['dictindex' => ['word', 'dictionarycode']];

    public readonly string $word;
    public readonly string $description;
    public readonly string $langcode;

    public function __construct(string $line)
    {
        $line_pieces = explode("\t", $line);
        /*debug purposes
        if (count($line_pieces) !== 3) {
            var_dump($line_pieces);
        }
        */
        list($this->word, $this->description, $this->langcode) = $line_pieces;
    }

    public function __toString()
    {
        return "{$this->word}\t{$this->description}\t{$this->langcode}";
    }

    # Has to be in accordance with TABLE_TYPES and TABLE_COLUMNS!
    public function asRow()
    {
        return [$this->word, $this->description, $this->langcode];
    }
}
