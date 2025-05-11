<?php

namespace Bojler;

use Collator;
use Discord\Parts\Channel\Message;
use Ds\Set;
use Exception;

use function React\Async\await;

# https://github.com/2colours/Boggler-Bot-PHP/issues/26
define('CONFIG', ConfigHandler::getInstance());
define('DEFAULT_TRANSLATION', CONFIG->getDefaultTranslation());
define('DICE_DICT', CONFIG->getDice());
define('AVAILABLE_LANGUAGES', CONFIG->getAvailableLanguages());
define('DEFAULT_END_AMOUNT', CONFIG->getDefaultEndAmount());
define('WORDLIST_PATHS', array_map(fn($value) => "param/$value", CONFIG->getWordlists()));
define('DICTIONARIES', CONFIG->getDictionaries());
define('COMMUNITY_WORDLIST_PATHS', array_map(fn($value) => "live_data/$value", CONFIG->getCommunityWordlists()));
define('CUSTOM_EMOJIS', CONFIG->getCustomEmojis());

class GameStatus #not final because of mocking
{
    private readonly PlayerHandler $player_handler;
    private string $file;
    private string $archive_file;
    private $game_over_acknowledged;
    public private(set) LetterList $letters;
    public private(set) Set $found_words;
    public private(set) int $game_number;
    public protected(set) string $current_lang; #not private because of mocking
    public private(set) string $base_lang;
    public private(set) string $planned_lang;
    public private(set) int $max_saved_game;
    public private(set) bool $changes_to_save;
    public private(set) bool $thrown_the_dice;
    public protected(set) int $end_amount; #not private because of mocking
    private $custom_emoji_solution;
    public private(set) Set $solutions;
    private Set $wordlist_solutions;
    private $community_list;
    private $communitylist_solutions;
    public private(set) array $available_hints;
    private Set $approved_words;

    public function __construct(string $current_file, string $archive_file)
    {
        $this->player_handler = PlayerHandler::getInstance(); # https://github.com/2colours/Boggler-Bot-PHP/issues/26
        $this->archive_file = $archive_file;
        $this->file = $current_file;
        $this->letters = new LetterList(array_fill(0, 16, null));
        $this->found_words = new Set();
        # complete lists
        $this->community_list = [];
        # solution lists
        $this->wordlist_solutions = new Set();
        #dependent data
        $this->communitylist_solutions = [];
        $this->available_hints = array_map(fn() => [], array_flip(AVAILABLE_LANGUAGES));
        #dependent data
        $this->solutions = new Set();
        $this->end_amount = DEFAULT_END_AMOUNT;
        #dependent data
        $this->approved_words = new Set();
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

        $this->loadGame();
    }

    public function loadGame()
    {
        $parsed = CurrentGameData::fromJsonFile($this->file);
        # Current Game
        $this->letters = new LetterList($parsed->letters);
        $this->found_words = new Set($parsed->found_words);
        $this->game_number = $parsed->game_number;
        $this->current_lang = $parsed->current_lang;
        # General Settings
        $this->base_lang = $parsed->base_lang;
        $this->planned_lang = $parsed->planned_lang;
        $this->max_saved_game = $parsed->max_saved_game;
        $this->gameSetup();
        $this->changes_to_save = true;
    }

    private function gameSetup()
    {
        $this->findSolutions();
        $this->setEndAmount();
        $this->setApprovedWords();
        $this->game_over_acknowledged = $this->getApprovedAmount() >= $this->end_amount;
        $this->thrown_the_dice = true;
        # easter egg stuff
        #$this->_easter_egg_conditions();
    }

    public function getLongestWordLength(): int
    {
        return max(array_map(textual_length(...), $this->solutions->toArray()));
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
        $this->solutions->add(...array_filter($this->community_list, fn($word) => $this->wordValidFast($word, $refdict)));
        # custom emojis
        $this->findCustomEmojis($refdict);
        $this->solutions->add(...$this->custom_emoji_solution);
    }

    private function findHints()
    {
        $db = DatabaseHandler::getInstance();
        $refdict = $this->letters->lower_cntdict;
        foreach ($this->availableDictionariesFrom($this->current_lang) as $language) {
            $this->available_hints[$language] = array_values(array_filter($db->getWords(new DictionaryType($this->current_lang, $language)), fn($item) => $this->wordValidFast($item, $refdict)));
        }
    }

    # for a given language gives back which languages you can translate it to
    public function availableDictionariesFrom(?string $origin = null)
    {
        $origin ??= $this->current_lang;
        return array_filter(AVAILABLE_LANGUAGES, fn($item) => array_key_exists((new DictionaryType($origin, $item))->asDictstring(), DICTIONARIES));
    }

    # gets the refdict instead of creating it every time
    # TODO isolate this from the class altogether (only $current_lang is a real dependency)
    public function wordValidFast(string $word, array $refdict): bool
    {
        return !ValidityInfo::listProblems(array_count_values($this->preprocessWord($word)), $refdict)->valid();
    }

    public function wordValid(string $word, array $refdict): ValidityInfo
    {

        return new ValidityInfo($word, array_count_values($this->preprocessWord($word)), $refdict);
    }

    public function preprocessWord(string $word): array
    {
        # Pre-processing word for validity check
        $word = mb_ereg_replace('[.\'-]', '', $word);
        if ($this->current_lang === 'German') {
            $word = $this->germanLetters($word);
        }
        $word = mb_strtolower($word);

        return grapheme_str_split($word);
    }

    # TODO isolate this from the class altogether
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

    private function setApprovedWords()
    {
        $this->approved_words = $this->solutions->intersect($this->found_words);
    }

    public function collator()
    {
        return new Collator(CONFIG->getLocale($this->current_lang));
    }

    public function currentEntryJson(): CurrentGameData # TODO the name will eventually lose the JSON
    {
        return CurrentGameData::fromStatusObject($this);
    }

    public function foundWordsSorted()
    {
        $result = $this->found_words->toArray();
        $this->collator()->sort($result);
        return $result;
    }

    public function saveGame()
    {
        file_put_contents($this->file, json_encode($this->currentEntryJson(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function findWordlistSolutions(array $refdict)
    {
        $content = file(WORDLIST_PATHS[$this->current_lang], FILE_IGNORE_NEW_LINES);
        $this->wordlist_solutions = new Set(array_filter($content, fn($line) => $this->wordValidFast($line, $refdict)));
    }

    public function tryLoadOldGame(int $number)
    {
        $this->saveOld();
        if ($number < 1 || $this->max_saved_game < $number) {
            return false;
        }
        $this->player_handler->newGame();

        $parsed = ArchiveGameEntryData::fromJsonFile($this->archive_file, $number);
        $this->letters = new LetterList($parsed->letters_sorted, true);
        if ($this->letters->isAbnormal()) {
            echo 'Game might be damaged.';
        }
        $this->found_words = new Set($parsed->found_words_sorted);
        $this->game_number = $parsed->game_number;
        $this->current_lang = $parsed->current_lang;
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

        $parsed = ArchiveGameEntryData::fromJsonFile($this->archive_file, $this->max_saved_game);
        echo $parsed->current_lang;
        if ($parsed->current_lang !== $this->current_lang) {
            return false;
        }
        if (count($parsed->found_words_sorted) <= 10) {
            return true;
        }
        return false;
    }

    public function approvalStatus(string $word): ApprovalData
    {
        $approval_data = new ApprovalData();
        $approval_data->word = $word;
        $approval_data->validity_info = $this->wordValid($word, $this->letters->lower_cntdict);
        $approval_data->wordlist = $this->wordlist_solutions->contains($word);
        $approval_data->community = in_array($word, $this->community_list);
        $approval_data->custom_reactions = array_key_exists($word, CUSTOM_EMOJIS[$this->current_lang]);
        $approval_data->any = false;
        $approval_data->dictionary = false;
        foreach (['wordlist', 'community', 'custom_reactions'] as $key) {
            $approval_data->any = $approval_data->any || $approval_data->{$key};
        }
        $approval_data->translations = array_map(fn() => false, array_flip(AVAILABLE_LANGUAGES));
        foreach ($this->availableDictionariesFrom($this->current_lang) as $language) {
            if (in_array($word, $this->available_hints[$language])) {
                $approval_data->translations[$language] = get_translation($word, new DictionaryType($this->current_lang, $language), DatabaseHandler::getInstance()); # TODO https://github.com/2colours/Boggler-Bot-PHP/issues/26
                $approval_data->dictionary = true;
                $approval_data->any = true;
            }
        }
        return $approval_data;
    }

    public function getApprovedAmount(): int
    {
        return $this->approved_words->count();
    }

    #TODO style guide about indicating return type?
    public function isFoundApproved(string $word): bool
    {
        return $this->approved_words->contains($word);
    }

    private function loadCommunityList()
    {
        $this->community_list = file(COMMUNITY_WORDLIST_PATHS[$this->current_lang], FILE_IGNORE_NEW_LINES) ?: [];
    }

    private function findCustomEmojis(array $refdict)
    {
        $this->custom_emoji_solution = array_filter(array_keys(CUSTOM_EMOJIS[$this->current_lang]), fn($word) => $this->wordValidFast($word, $refdict));
    }

    private function saveOld()
    {
        # unchanged loaded old games are not saved; if the game is not saved yet in saves.txt, it is appended (determined by game_number compared to max_saved_game and number of lines in saves.txt)
        if (!$this->changes_to_save) {
            return;
        }

        # determines if game is already saved in saves.txt
        if ($this->game_number <= $this->max_saved_game) {
            $this->overwriteArchiveJson();
            return;
        }

        # New game is appended (if game_number > max_saved_game)
        $this->appendArchiveJson();
        $this->max_saved_game++;
        $this->saveGame();
    }

    private function overwriteArchiveJson(): void
    {
        $content = json_decode(file_get_contents($this->archive_file), true);
        $content[$this->game_number - 1] = ArchiveGameEntryData::fromStatusObject($this);
        file_put_contents($this->archive_file, json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function appendArchiveJson(): void
    {
        $content = json_decode(file_get_contents($this->archive_file), true);
        $content[] = ArchiveGameEntryData::fromStatusObject($this);
        file_put_contents($this->archive_file, json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
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

    public function tryAddWord(Message $ctx, string $word)
    {
        $word_info = $this->approvalStatus($word);
        #await(easter_egg_trigger($ctx, $word, '_Rev'));
        $problem_message = $word_info->validity_info->messageWhenValid();
        if (isset($problem_message)) {
            await($ctx->channel->sendMessage($problem_message));
            return false;
        }

        if ($this->found_words->contains($word)) {
            await($ctx->channel->sendMessage("$word was already found."));
            return false;
        }

        #await(easter_egg_trigger($ctx, $word));
        $this->found_words->add($word);
        $this->changes_to_save = true;
        if ($this->solutions->contains($word)) {
            $this->addApprovedWord($ctx, $word);
        }
        $this->saveGame();
        $this->player_handler->playerAddWord($ctx, $word_info);
        return true;
    }

    private function addApprovedWord(Message $ctx, string $word)
    {
        $this->approved_words->add($word);
        if ($this->getApprovedAmount() === $this->end_amount) {
            $this->ensureGameOver($ctx);
        }
    }

    private function removeApprovedWord(string $word)
    {
        $this->approved_words->remove($word);
    }

    public function tryAddCommunity(Message $ctx, string $word)
    {
        if (in_array($word, $this->community_list)) {
            await($ctx->channel->sendMessage('Word already in the community list.'));
            return false;
        }

        $approval_data = $this->approvalStatus($word);
        if ($approval_data->any) {
            await($ctx->channel->sendMessage('This word is already approved.'));
            return false;
        }

        file_put_contents(COMMUNITY_WORDLIST_PATHS[$this->current_lang], "$word\n", FILE_APPEND);
        array_push($this->community_list, $word);
        if ($this->wordValidFast($word, $this->letters->lower_cntdict)) {
            array_push($this->communitylist_solutions, $word);
            $this->solutions->add($word);
            if ($this->found_words->contains($word)) {
                $this->addApprovedWord($ctx, $word);
            }
            $this->player_handler->approveWord($word);
        }
        return true;
    }

    public function removeWord(string $word)
    {
        $this->found_words->remove($word);
        # removed words have always to be saved (changes_to_save)
        $this->changes_to_save = true;
        if ($this->solutions->contains($word)) {
            $this->removeApprovedWord($word);
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

    public function enoughWordsFound()
    {
        return $this->found_words->count() >= $this->end_amount;
    }

    public function throwDice()
    {
        $used_permutation = range(0, 15);
        shuffle($used_permutation);
        $current_dice = DICE_DICT[$this->current_lang];
        $dice_permutated = array_map(fn($dice_index) => $current_dice[$dice_index], $used_permutation);
        $this->letters = new LetterList(array_map(fn($current_die) => $current_die[array_rand($current_die)], $dice_permutated), just_regenerate: true);
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
            $awards['Best Beginner'] = array_filter($players, fn($player) => $this->player_handler->getPlayerField($player, 'role') === 'Beginner');
        }
        $relevant_players = array_merge(...$highscore);
        $is_newcomer = fn($player_data) => count($player_data['found_words']) === $player_data['all_time_found'];
        $solved_hints = fn($player_data) => count(array_intersect($player_data['found_words'], $player_data['used_hints']));
        $awards['Newcomer'] = array_filter($relevant_players, fn($player) => $is_newcomer($this->player_handler->player_dict[$player]));
        $most_solved_hints_amount = count($relevant_players) === 0 ? 0 : max(array_map(fn($player) => $solved_hints($this->player_handler->player_dict[$player]), $relevant_players));
        if ($most_solved_hints_amount > 0) {
            $awards['Most solved hints'] = array_filter($relevant_players, fn($player) => $solved_hints($this->player_handler->player_dict[$player]) === $most_solved_hints_amount);
        }
        return $awards;
    }

    public function isLongestSolution(string $word): bool
    {
        return $this->solutions->contains($word) && textual_length($word) >= $this->getLongestWordLength();
    }
}
