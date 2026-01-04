<?php

namespace Bojler;

use Psr\Container\ContainerInterface;
use SQLite3;

class DatabaseHandler
{
    public const TABLES = ['dictionary' => DictionaryEntry::class];
    private const DB_PATH = 'param/dictionary.db';

    public readonly SQLite3 $db;
    public readonly array $dictionaries;

    private function tableSetup(): void
    {
        foreach (self::TABLES as $name => $entry_class) {
            $typed_columns = [];
            for ($i = 0; $i < min(count($entry_class::TABLE_TYPES), count($entry_class::TABLE_COLUMNS)); $i++) {
                $typed_columns[] = "{$entry_class::TABLE_COLUMNS[$i]} {$entry_class::TABLE_TYPES[$i]}";
            }
            $column_string = implode(', ', $typed_columns);
            $query = "CREATE TABLE IF NOT EXISTS $name ($column_string)";
            $this->db->exec($query);
            foreach ($entry_class::INDEXES as $index_name => $index_columns) {
                $fields = implode(', ', $index_columns);
                $query = "CREATE INDEX IF NOT EXISTS $index_name ON $name ($fields)";
                $this->db->exec($query);
            }
        }
    }

    private function importData(string $table_name, string $table_file): void
    {
        $current_table_class = self::TABLES[$table_name];
        foreach (file($table_file, FILE_IGNORE_NEW_LINES) as $line) {
            $line_object = new $current_table_class($line);
            $current_row = $line_object->asRow();
            $column_names = implode(', ', $current_table_class::TABLE_COLUMNS);
            $column_placeholders = implode(', ', array_map(fn () => '?', $current_row));
            $statement = $this->db->prepare("INSERT INTO $table_name ($column_names) VALUES ($column_placeholders)");
            $column_types = $current_table_class::TABLE_TYPES;
            foreach ($current_row as $i => $value) {
                $statement->bindValue($i + 1, $value, constant("SQLITE3_{$column_types[$i]}"));
            }
            $statement->execute();
        }
    }

    public function importSucceeded(DictionaryType $dtype): bool
    {
        $in_language = $dtype->src_lang;
        $test_words = ['Hungarian' => 'bársonyos', 'German' => 'Ente', 'English' => 'admirer'];
        $dummy_request = [...$this->translate($test_words[$in_language], $dtype)];
        return isset(array_values($dummy_request)[0]);
    }

    public function translate(string $word, DictionaryType $dtype): array
    {
        $dictcode = $dtype->asDictcode();
        $operators = ['=', 'LIKE'];
        $result = [];
        foreach ($operators as $operator) {
            $query = "SELECT word, description FROM dictionary WHERE word $operator :word AND dictionarycode = :dictcode";
            $statement = $this->db->prepare($query);
            $statement->bindValue(':word', $word);
            $statement->bindValue(':dictcode', $dictcode, SQLITE3_INTEGER);
            $db_results = [...fetch_all($statement->execute())];
            $result[$operator] = $db_results ? implode(', ', array_column($db_results, 1)) : null;
        }
        return $result;
    }

    public function getWords(DictionaryType $dtype): array
    {
        $statement = $this->db->prepare('SELECT DISTINCT word FROM dictionary WHERE dictionarycode = :dictcode');
        $statement->bindValue(':dictcode', $dtype->asDictcode(), SQLITE3_INTEGER);
        $db_results = [...fetch_all($statement->execute())];
        return array_column($db_results, 0);
    }

    public function __construct(ConfigHandler $config)
    {
        $this->db = new SQLite3(self::DB_PATH);
        $this->dictionaries = $config->getDictionaries();
        $this->tableSetup();
        foreach ($this->dictionaries as $dictstring => $dictcode) {
            $dtype = DictionaryType::fromDictstring($config, $dictstring);
            if (!$this->importSucceeded($dtype)) {
                $this->importData('dictionary', "param/dict_import{$dictcode}.txt");
            }
        }
    }
}
