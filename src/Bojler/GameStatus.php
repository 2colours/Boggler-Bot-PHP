<?php

namespace Bojler;

use Exception;
use Collator;
use Discord\Parts\Channel\Message;
use Ds\Set;

use function React\Async\await;

# TODO better dependency injection surely...
define('CONFIG', ConfigHandler::getInstance());
define('DEFAULT_TRANSLATION', CONFIG->getDefaultTranslation());
define('DICE_DICT', CONFIG->getDice());
define('AVAILABLE_LANGUAGES', CONFIG->getAvailableLanguages());
define('DEFAULT_END_AMOUNT', CONFIG->getDefaultEndAmount());
define('WORDLIST_PATHS', array_map(fn ($value) => "param/$value", CONFIG->getWordlists()));
define('DICTIONARIES', CONFIG->getDictionaries());
define('COMMUNITY_WORDLIST_PATHS', array_map(fn ($value) => "live_data/$value", CONFIG->getCommunityWordlists()));
define('CUSTOM_EMOJIS', CONFIG->getCustomEmojis());

class GameStatus
{
    # TODO refined permissions (including readonly)
    private readonly PlayerHandler $player_handler;
    private $file;
    private $archive_file;
    private $game_over_acknowledged;
    public LetterList $letters;
    public Set $found_words;
    public $game_number;
    public $current_lang;
    public $base_lang;
    public $planned_lang;
    public $max_saved_game;
    public $changes_to_save;
    public $thrown_the_dice;
    public $end_amount;
    private $custom_emoji_solution;
    public Set $longest_solutions;
    public Set $solutions;
    public Set $wordlist_solutions;
    public $community_list;
    public $communitylist_solutions;
    public $custom_emoji_solutions;
    public $available_hints;
    public $amount_approved_words;

    public function __construct(string $file, string $archive_file)
    {
        $this->player_handler = PlayerHandler::getInstance(); # TODO better injection?
        $this->archive_file = $archive_file;
        $this->file = $file;
        $this->letters = new LetterList(array_fill(0, 16, null));
        $this->found_words = new Set();
        # complete lists
        $this->community_list = [];
        # solution lists
        $this->wordlist_solutions = new Set();
        #dependent data
        $this->communitylist_solutions = [];
        $this->available_hints = array_map(fn () => [], array_flip(AVAILABLE_LANGUAGES));
        $this->custom_emoji_solutions = [];
        #dependent data
        $this->solutions = new Set();
        $this->end_amount = DEFAULT_END_AMOUNT;
        #dependent data
        $this->amount_approved_words = 0;
        $this->game_over_acknowledged = false;
        # changes_to_save determines if we have to save sth in saves.txt. Always true for a loaded current_game or new games (might not be saved yet). False when loading old games. Becomes true with every adding or removing word.
        $this->changes_to_save = false;
        $this->thrown_the_dice = false;
        $this->planned_lang = DEFAULT_TRANSLATION[0];
        $this->current_lang = DEFAULT_TRANSLATION[0];
        $this->base_lang = DEFAULT_TRANSLATION[1];
        $this->game_number = 0;
        #dependent data
        $this->max_saved_game = 0;

        # achievement stuff
        $this->longest_solutions = new Set();

        $this->loadGame();
    }

    # Does the minimum check if the current_game file is right: should be 3 lines and the first 32 characters
    public function fileValid()
    {
        try {
            $file = fopen($this->file, 'r');
            $first_line = fgets($file);
            # Current check: 16 letter + 15 spaces + 1 line break = 32
            if (grapheme_strlen($first_line) !== 32) {
                throw new Exception('Wrong amount of letters saved in current_game.');
            }
            # Checks if the right amount of letters is saved
            if (count(explode(' ', $first_line)) != 16) {
                throw new Exception('File might be damaged.');
            }
            for ($i = 0; $i < 2; $i++) {
                if (fgets($file) === false) {
                    throw new Exception('File is too short.');
                }
            }
        } catch (Exception $exception) {
            echo $exception;
            return false;
        } finally {
            if (isset($file)) {
                fclose($file);
            }
        }

        return true;
    }

    public function loadGame()
    {
        $content = file($this->file, FILE_IGNORE_NEW_LINES);
        if ($content === false || count($content) < 9) {
            echo 'Save file wrong.';
            return;
        }
        # Current Game
        $this->letters = new LetterList(explode(' ', $content[1]));
        $this->found_words = new Set($content[2] === '' ? [] : explode(' ', $content[2]));
        $this->game_number = (int) explode("\t", $content[3])[1];
        $this->current_lang = explode("\t", $content[4])[1];
        # General Settings
        $this->base_lang = explode("\t", $content[7])[1];
        $this->planned_lang = explode("\t", $content[8])[1];
        $this->max_saved_game = (int) explode("\t", $content[9])[1];
        $this->gameSetup();
        $this->changes_to_save = true;
    }

    private function gameSetup()
    {
        $this->findSolutions();
        $this->setEndAmount();
        $this->countApprovedWords();
        $this->game_over_acknowledged = $this->amount_approved_words >= $this->end_amount;
        $this->thrown_the_dice = True;
        # easter egg stuff
        #$this->_easter_egg_conditions();
        # achievement stuff
        $this->getLongestWords();
    }

    private function getLongestWords()
    {
        $solutions = $this->solutions->toArray();
        $solutions_with_length = array_map(fn ($item) => [$item, grapheme_strlen(remove_special_char($item))], $solutions);
        $longest_solution_length = max(array_map(fn ($item) => $item[1], $solutions_with_length));
        $this->longest_solutions = new Set(array_map(fn ($item) => $item[0], array_filter($solutions_with_length, fn ($item) => $item[1] === $longest_solution_length)));
    }

    private function findSolutions()
    {
        $refdict = $this->letters->lower_cntdict;
        # wordlist
        $this->findWordlistSolutions($refdict);
        $this->solutions = clone $this->wordlist_solutions;
        # dictionaries (hints)
        $this->findHints();
        foreach ($this->available_hints as $hints_for_language) {
            $this->solutions->add(...$hints_for_language);
        }
        # communitylist
        $this->loadCommunityList();
        $this->solutions->add(...array_filter($this->community_list, fn ($word) => $this->wordValidFast($word, $refdict)));
        # custom emojis
        $this->findCustomEmojis($refdict);
        $this->solutions->add(...$this->custom_emoji_solution);
    }

    private function findHints()
    {
        $db = DatabaseHandler::getInstance();
        $refdict = $this->letters->lower_cntdict;
        foreach ($this->availableDictionariesFrom($this->current_lang) as $language) {
            $this->available_hints[$language] = array_values(array_filter($db->getWords(new DictionaryType($this->current_lang, $language)), fn ($item) => $this->wordValidFast($item, $refdict)));
        }
    }

    # for a given language gives back which languages you can translate it to
    public function availableDictionariesFrom(string $origin = null)
    {
        $origin ??= $this->current_lang;
        return array_filter(AVAILABLE_LANGUAGES, fn ($item) => array_key_exists((new DictionaryType($origin, $item))->asDictstring(), DICTIONARIES));
    }

    # gets the refdict instead of creating it every time
    public function wordValidFast(string $word, array $refdict) # TODO why is there a $refdict passed and $this->letters->lower_cntdict also used??
    {
        # Pre-processing word for validity check
        $word = mb_ereg_replace('[.\'-]', '', $word);
        if ($this->current_lang === 'German') {
            $word = $this->germanLetters($word);
        }
        $word = mb_strtolower($word);

        $word_letters = mb_str_split($word);
        foreach ($word_letters as $letter) {
            if (!array_key_exists($letter, $this->letters->lower_cntdict)) {
                return false;
            }
        }
        $wdict = array_count_values($word_letters);

        foreach ($word_letters as $letter) {
            if ($wdict[$letter] > $refdict[$letter]) {
                return false;
            }
        }
        return true;
    }

    public function germanLetters(string $word)
    {
        $german_letters = [
            'ä' => 'ae',
            'ö' => 'oe',
            'ü' => 'ue',
            'Ä' => 'AE',
            'Ö' => 'OE',
            'Ü' => 'UE',
            'ß' => 'ss'
        ];
        return str_replace(array_keys($german_letters), array_values($german_letters), $word);
    }

    private function setEndAmount()
    {
        $expected_solution_count = $this->wordlist_solutions->count();
        $this->end_amount = $this->solutions->isEmpty() ? 100 : min(DEFAULT_END_AMOUNT, intdiv($expected_solution_count, 2));
    }

    private function countApprovedWords()
    {
        $amount = $this->solutions->diff($this->found_words)->count();
        $this->amount_approved_words = $this->solutions->count() - $amount;
    }

    public function collator()
    {
        return new Collator(CONFIG->getLocale($this->current_lang));
    }

    public function currentEntry()
    {
        $space_separated_letters = implode(' ', $this->letters->list);
        $space_separated_found_words = implode(' ', $this->found_words->toArray());
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

    public function archiveEntry()
    {
        $letters_sorted = $this->letters->list;
        $this->collator()->sort($letters_sorted);
        $space_separated_letters_alphabetic = implode(' ', $letters_sorted);
        $space_separated_found_words_alphabetic = implode(' ', $this->foundWordsSorted());
        return <<<END
            {$this->game_number}. ({$this->current_lang})
            $space_separated_letters_alphabetic
            $space_separated_found_words_alphabetic
            END;
    }

    public function foundWordsSorted()
    {
        $result = $this->found_words->toArray();
        $this->collator()->sort($result);
        return $result;
    }

    public function saveGame()
    {
        file_put_contents($this->file, $this->currentEntry());
    }

    private function findWordlistSolutions(array $refdict)
    {
        $content = file(WORDLIST_PATHS[$this->current_lang], FILE_IGNORE_NEW_LINES);
        $this->wordlist_solutions = new Set(array_filter($content, fn ($line) => $this->wordValidFast($line, $refdict)));
    }

    public function tryLoadOldGame(int $number)
    {
        $this->saveOld();
        if (1 <= $number && $number <= $this->max_saved_game + 1) {
            return True;
        }
        $this->player_handler->newGame();
        $lines = file($this->archive_file, FILE_IGNORE_NEW_LINES);
        $offset = 3 * ($number - 1);
        list($languages, $letters, $words) = array_slice($lines, $offset, 3);
        $this->letters = new LetterList(explode(' ', $letters), true);
        if ($this->letters->isAbnormal()) {
            echo 'Game might be damaged.';
        }
        $this->found_words = new Set(count($words) !== 0 ? explode(' ', $words) : []);
        # set language and game number
        $language_list = explode(' ', $languages);
        $read_number = $language_list[0];
        $read_number = grapheme_substr($read_number, 0, grapheme_strlen($read_number) - 1);
        $read_lang = $language_list[1];
        $read_lang = grapheme_substr($read_lang, 1, grapheme_strlen($read_number) - 1);
        $this->game_number = intval($read_number);
        $this->current_lang = $read_lang;
        # this game doesn't have to be saved again in saves.txt yet (changes_to_save), we have a loaded game (thrown_the_dice)
        $this->gameSetup();
        $this->changes_to_save = false;
        $this->saveGame();
        return true;
    }

    public function checkNewestGame()
    {
        # answer to: should we load the newest game instead of creating a new one?
        if ($this->game_number === $this->max_saved_game) {
            return false;
        }
        $lines = file($this->archive_file, FILE_IGNORE_NEW_LINES);
        $last_found_words = explode(' ', $lines[array_key_last($lines)]);
        $language_in_parens = explode(' ', $lines[count($lines) - 3])[1];
        $language = grapheme_substr($language_in_parens, 1, grapheme_strlen($language_in_parens) - 1);
        echo $language;
        if ($language != $this->current_lang) {
            return false;
        }
        if (count($last_found_words) <= 10) {
            return true;
        }
        return false;
    }

    public function approvalStatus(string $word)
    {
        $approval_dict = [];
        $approval_dict['word'] = $word;
        $approval_dict['valid'] = $this->wordValidFast($word, $this->letters->lower_cntdict);
        $approval_dict['wordlist'] = $this->wordlist_solutions->contains($word);
        $approval_dict['community'] = in_array($word, $this->community_list);
        $approval_dict['custom_reactions'] = array_key_exists($word, CUSTOM_EMOJIS[$this->current_lang]);
        $approval_dict['any'] = false;
        foreach (['wordlist', 'community', 'custom_reactions'] as $key) {
            $approval_dict['any'] = $approval_dict['any'] || $approval_dict[$key];
        }
        $approval_dict += array_map(fn () => false, array_flip(AVAILABLE_LANGUAGES));
        foreach ($this->availableDictionariesFrom($this->current_lang) as $language) {
            if (in_array($word, $this->available_hints[$language])) {
                $approval_dict[$language] = $word;
                $approval_dict['any'] = true;
            }
        }
        return $approval_dict;
    }

    private function loadCommunityList()
    {
        $this->community_list = file(COMMUNITY_WORDLIST_PATHS[$this->current_lang], FILE_IGNORE_NEW_LINES) ?: [];
    }

    private function findCustomEmojis(array $refdict)
    {
        $this->custom_emoji_solution = array_filter(array_keys(CUSTOM_EMOJIS[$this->current_lang]), fn ($word) => $this->wordValidFast($word, $refdict));
    }

    private function saveOld()
    {
        # unchanged loaded old games are not saved; if the game is not saved yet in saves.txt, it is appended (determined by game_number compared to max_saved_game and number of lines in saves.txt)
        if (!$this->changes_to_save) {
            return;
        }

        # determines if game is already saved in saves.txt
        if ($this->game_number <= $this->max_saved_game) {
            $archive_temp = file($this->archive_file, FILE_IGNORE_NEW_LINES);
            $line_number = 3 * ($this->game_number - 1);
            $file = fopen($this->archive_file, 'w');
            # older games
            for ($i = 0; $i < $line_number; $i++) {
                fwrite($file, $archive_temp[$i]);
            }
            # loaded game
            fwrite($file, $this->archiveEntry());
            # newer games
            for ($i = $line_number + 3; $i < count($archive_temp); $i++) {
                fwrite($file, $archive_temp[$i]);
            }
            fclose($file);
            return;
        }

        # New game is appended (if game_number > max_saved_game)
        file_put_contents($this->archive_file, $this->archiveEntry() . "\n", FILE_APPEND);
        $this->max_saved_game++;
        $this->saveGame();
    }

    public function newGame()
    {
        $this->saveOld();
        $this->player_handler->newGame();
        $this->updateCurrentLang();
        if ($this->checkNewestGame()) {
            $this->tryLoadOldGame($this->max_saved_game);
            return;
        }
        $this->found_words->clear();
        # before creating a new one, old ones are saved, so max_saved_game contains all games
        $this->game_number = $this->max_saved_game + 1;
        $this->throwDice();
        $this->gameSetup();
        # we have a game (thrown_the_dice), this new game will be saved, even if empty and is not yet in saves.txt (changes_to_save)
        $this->changes_to_save = true;
    }

    public function shuffleLetters()
    {
        $this->letters->shuffle();
        $this->saveGame();
    }

    private function ensureGameOver(Message $ctx)
    {
        if ($this->game_over_acknowledged) {
            return;
        }
        $this->game_over_acknowledged = true;
        await($ctx->channel->sendMessage('**Congratulations! You won this game! You found ' . $this->end_amount . ' words!**'));
        await($ctx->channel->sendMessage(game_highscore($this, $this->player_handler)));
    }

    public function addWord(Message $ctx, string $word)
    {
        $this->found_words->add($word);
        $this->changes_to_save = true;
        if ($this->solutions->contains($word)) {
            $this->addApprovedWord($ctx);
        }
        $this->saveGame();
    }

    private function addApprovedWord(Message $ctx)
    {
        $this->amount_approved_words++;
        if ($this->amount_approved_words === $this->end_amount) {
            $this->ensureGameOver($ctx);
        }
    }

    public function tryAddCommunity(Message $ctx, string $word)
    {
        if (in_array($word, $this->community_list)) {
            return false;
        }
        file_put_contents(COMMUNITY_WORDLIST_PATHS[$this->current_lang], "$word\n", FILE_APPEND);
        array_push($this->community_list, $word);
        if ($this->wordValidFast($word, $this->letters->lower_cntdict)) {
            array_push($this->communitylist_solutions, $word);
            $this->solutions->add($word);
            if ($this->found_words->contains($word)) {
                $this->addApprovedWord($ctx);
            }
        }
        return true;
    }

    public function removeWord(string $word)
    {
        $this->found_words->remove($word);
        # removed words have always to be saved (changes_to_save)
        $this->changes_to_save = true;
        if ($this->solutions->contains($word)) {
            $this->amount_approved_words--;
        }
        $this->saveGame();
    }

    public function clearWords()
    {
        $this->found_words->clear();
        $this->saveGame();
    }

    public function setLang(string $lang)
    {
        $this->planned_lang = $lang;
        $this->saveGame();
    }

    private function updateCurrentLang()
    {
        $this->current_lang = $this->planned_lang;
        $this->saveGame();
    }

    public function throwDice()
    {
        $used_permutation = range(0, 15);
        shuffle($used_permutation);
        $current_dice = DICE_DICT[$this->current_lang];
        $dice_permutated = array_map(fn ($dice_index) => $current_dice[$dice_index], $used_permutation);
        $this->letters = new LetterList(array_map(fn ($current_die) => $current_die[array_rand($current_die)], $dice_permutated), just_regenerate: true);
        $this->saveGame();
    }

    public function gameAwards()
    {
        $highscore = [];
        $awards = [
            'First place' => [],
            'Second place' => [],
            'Third place' => [],
            'Newcomer' => [],
            'Best Beginner' => [],
            'Most solved hints' => []
        ];
        foreach ($this->player_handler->player_dict as $key => $value) {
            $found_words = count($value['found_words']);
            if (key_exists($found_words, $highscore)) {
                array_push($highscore[$found_words], $key);
            } elseif ($found_words > 0) { # don't save people who didn't participate
                $highscore[$found_words] = [$key];
            }
        }
        krsort($highscore);
        # Places 1,2,3
        $places = ['First place', 'Second place', 'Third place'];
        $awarded_places = min(count($places), count($highscore));
        $awards = array_merge($awards, array_combine(array_slice($places, 0, $awarded_places), array_slice($highscore, 0, $awarded_places)));
        foreach (array_values($highscore) as $players) {
            if (count($awards['Best Beginner']) > 0) {
                break;
            }
            $awards['Best Beginner'] = array_filter($players, fn ($player) => $this->player_handler->getPlayerField($player, 'role') === 'Beginner');
        }
        $relevant_players = array_merge(...$highscore);
        $is_newcomer = fn ($player_data) => count($player_data['found_words']) === $player_data['all_time_found'];
        $solved_hints = fn ($player_data) => count(array_intersect($player_data['found_words'], $player_data['used_hints']));
        $awards['Newcomer'] = array_filter($relevant_players, fn ($player) => $is_newcomer($this->player_handler->player_dict[$player]));
        $most_solved_hints_amount = count($relevant_players) === 0 ? 0 : max(array_map(fn ($player) => $solved_hints($this->player_handler->player_dict[$player]), $relevant_players));
        if ($most_solved_hints_amount > 0) {
            $awards['Most solved hints'] = array_filter($relevant_players, fn ($player) => $solved_hints($this->player_handler->player_dict[$player]) === $most_solved_hints_amount);
        }
        return $awards;
    }
}
