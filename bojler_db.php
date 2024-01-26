<?php
require __DIR__ .'/bojler_config.php'; # TODO ConfigHandler with PSR-4 autoloader


class DictionaryType
{
    public static function fromDictstring($dictstring)
    {
        return new self(...explode('-', $dictstring));
    }

    public readonly mixed $src_lang;
    public readonly mixed $target_lang;
    public function __construct($src_lang, $target_lang)
    {
        $this->src_lang = $src_lang;
        $this->target_lang = $target_lang;
    }
  
    public function asDictstring()
    {
        return "{$this->src_lang}-{$this->target_lang}";
    }
  
    public function asDictcode()
    {
        return ConfigHandler::getInstance()->get('dictionaries')[$this->asDictstring()]; # TODO better injection of singleton
    }
}


#A dictionary entry that represents one record - needs to be changed when applying a migration
class DictionaryEntry
{
    public const TABLE_TYPES = ['TEXT', 'TEXT', 'INTEGER'];
    public const TABLE_COLUMNS = ['word', 'description', 'dictionarycode'];
    public const INDEXES = [['dictindex', ['word', 'dictionarycode']]];

    public readonly mixed $word;
    public readonly mixed $description;
    public readonly mixed $langcode;

    public function __construct($line)
    {
        $line_pieces = explode("\t", $line);
        /*debug purposes
        if (count($line_pieces) != 3)
            var_dump($line_pieces);
        */
        list($this->word, $this->description, $this->langcode) = $line_pieces;
    }

    public function __toString()
    {
        return "{$this->word}\t{$this->description}\t{$this->langcode}";
    }

    public function asRow()
    {
        return [$this->word, $this->description, $this->langcode];
    }
}
        

class DatabaseHandler
{
    private static $instance;

    public static function getInstance() : self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        
        return self::$instance;
    }

    public const TABLES = ['dictionary' => DictionaryEntry::class];

    public readonly mixed $db = new SQLite3('param/dictionary.db');
    public readonly mixed $dictionaries = ConfigHandler::getInstance()->get('dictionaries'); # TODO better injection

    private function tableSetup()
    {
        foreach (self::TABLES as $name => $entry_class) {
            $typed_columns = []; # TODO is this required?
            for ($i = 0; $i < min(count($entry_class::TABLE_TYPES), count($entry_class::TABLE_COLUMNS)); $i++) {
                $typed_columns[] = $entry_class::TABLE_COLUMNS[$i] . ' ' . $entry_class::TABLE_TYPES[$i];
            }
            $column_string = implode(', ', $typed_columns);
            $this->db->exec("CREATE TABLE IF NOT EXISTS $name ($column_string)");
            foreach ($entry_class::indexes as $index_name => $index_columns) {
                $fields = implode(', ', $index_columns);
                $this->db->exec("CREATE INDEX IF NOT EXISTS $index_name ON $name ($fields)");
            }
        }
    }

    private function importData($table_name, string $table_file)
    {
        $current_table_class = self::TABLES[$table_name];
        foreach (file($table_file, FILE_IGNORE_NEW_LINES) as $line) {
            $line_object = new $current_table_class($line);
            $current_row = $line_object->asRow();
            $column_names = implode(', ', $current_table_class::TABLE_COLUMNS);
            $column_placeholders = implode(', ', array_map(SQLite3::escapeString(...), $current_row));
            $this->db->exec("INSERT INTO $table_name ($column_names) VALUES ($column_placeholders)");
        }
    }

    public function importSucceeded(DictionaryType $dtype)
    {
        $in_language = $dtype->src_lang;
        $test_words = ['Hungarian' => 'bársonyos', 'German' => 'Ente', 'English' => 'admirer'];
        $dummy_request = [...$this->translate($test_words[$in_language], $dtype)];
        return !empty($dummy_request);
    }

    public function translate($word, DictionaryType $dtype)
    {
        $dictcode = $dtype->asDictcode();
        $operators = ['=', 'LIKE'];
        foreach ($operators as $operator) {
            $safe_word = SQLite3::escapeString($word);
            $results = array_column(
                $this->db
                    ->query("SELECT word, description FROM dictionary WHERE word $operator $safe_word AND dictionarycode = $dictcode")
                    ->fetchArray(SQLITE3_NUM),
                1);
            yield [$word, ', '.join($results)];
        }
    }

    public function getWords(DictionaryType $dtype)
    {
        $safe_dictcode = SQLite3::escapeString($dtype->asDictcode());
        return array_column(
            $this->db
                ->query("SELECT DISTINCT word FROM dictionary WHERE dictionarycode = $safe_dictcode")
                ->fetchArray(SQLITE3_NUM),
            0);
    }

    private function __construct()
    {
        $this->tableSetup();
        foreach ($this->dictionaries as $dictstring => $dictcode) {
            $dtype = DictionaryType::fromDictstring($dictstring);
            if (!$this->importSucceeded($dtype))
                $this->importData('dictionary', "param/dict_import{$dictcode}.txt");
        }
    }
}