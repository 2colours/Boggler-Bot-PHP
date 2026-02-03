<?php

namespace Bojler;

use Collator;
use DI\FactoryInterface;
use Discord\Parts\Channel\Message;
use Ds\Set;

use function React\Async\await;

class GameStatus #not final because of mocking
{
    # primary data
    public private(set) LetterList $letters;
    public private(set) Set $found_words;
    public private(set) int $game_number;
    public protected(set) string $current_lang; #not private because of mocking

    public private(set) bool $thrown_the_dice; # TODO currently pretty useless, always set to true during setup - decide on the fate of this
    # dependent data, should be set during the setup process
    public protected(set) int $end_amount; #not private because of mocking
    private bool $game_over_acknowledged;
    private array $custom_emoji_solution;
    public private(set) Set $solutions;
    private Set $wordlist_solutions;
    public private(set) array $available_hints;
    private Set $approved_words;
    # set from constructor injection
    private readonly PlayerHandler $player_handler;
    private readonly ConfigHandler $config;
    private readonly FactoryInterface $factory;
    private readonly DatabaseHandler $db;
    # injected explicitly by the GameManager that creates the instance
    private readonly GameManager $manager;
    # injection-dependent
    private readonly int $default_end_amount;
    private readonly array $available_languages;
    private readonly array $custom_emojis;

    public function __construct(GameManager $manager, DatabaseHandler $db, PlayerHandler $player_handler, ConfigHandler $config, FactoryInterface $factory, CurrentGameData|ArchiveGameEntryData|NewGamePayload $payload)
    {
        # injected
        $this->db = $db;
        $this->player_handler = $player_handler;
        $this->config = $config;
        $this->factory = $factory;
        $this->manager = $manager;
        # injection-dependent
        $this->default_end_amount = $this->config->getDefaultEndAmount();
        $this->available_languages = $this->config->getAvailableLanguages();
        $this->custom_emojis = $this->config->getCustomEmojis();

        $this->thrown_the_dice = false;

        match (get_class($payload)) {
            CurrentGameData::class => $this->initializeFromCurrent($payload),
            ArchiveGameEntryData::class => $this->initializeFromArchive($payload),
            NewGamePayload::class => $this->initializeNew($payload)
        };

        $this->gameSetup();
    }

    private function initializeFromCurrent(CurrentGameData $parsed): void
    {
        $this->letters = $this->factory->make(LetterList::class, ['data' => $parsed->letters]);
        $this->found_words = new Set($parsed->found_words);
        $this->game_number = $parsed->game_number;
        $this->current_lang = $parsed->current_lang;
    }

    private function initializeFromArchive(ArchiveGameEntryData $parsed): void
    {
        $this->letters = $this->factory->make(LetterList::class, ['data' => $parsed->letters_sorted, 'preshuffle' => true]);
        if ($this->letters->isAbnormal()) {
            echo 'Game might be damaged.';
        }
        $this->found_words = new Set($parsed->found_words_sorted);
        $this->game_number = $parsed->game_number;
        $this->current_lang = $parsed->current_lang;
    }

    private function initializeNew(NewGamePayload $new_data): void
    {
        $this->found_words = new Set();
        $this->game_number = $new_data->games_so_far + 1;
        $this->current_lang = $new_data->planned_language;
        $this->throwDice();
    }

    private function throwDice(): void
    {
        $used_permutation = range(0, 15);
        shuffle($used_permutation);
        $current_dice = $this->config->getDice()[$this->current_lang];
        $dice_permutated = array_map(fn(int $dice_index) => $current_dice[$dice_index], $used_permutation);
        $this->letters = $this->factory->make(LetterList::class, ['data' => array_map(fn(array $current_die) => $current_die[array_rand($current_die)], $dice_permutated), 'just_regenerate' => true]);
        $this->manager->saveGame(); # TODO revise
    }

    private function gameSetup(): void
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

    private function findSolutions(): void
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
        $this->manager->loadCommunityList($this->current_lang);
        $this->solutions->add(...array_filter($this->manager->current_community_list, fn(string $word) => $this->wordValidFast($word, $refdict)));
        # custom emojis
        $this->findCustomEmojis($refdict);
        $this->solutions->add(...$this->custom_emoji_solution);
    }

    private function findHints(): void
    {
        $refdict = $this->letters->lower_cntdict;
        $this->available_hints = array_map(fn() => [], array_flip($this->available_languages));
        foreach ($this->availableDictionariesFrom($this->current_lang) as $language) {
            $this->available_hints[$language] = array_values(array_filter($this->db->getWords($this->factory->make(DictionaryType::class, ['src_lang' => $this->current_lang, 'target_lang' => $language])), fn(string $item) => $this->wordValidFast($item, $refdict)));
        }
    }

    # for a given language gives back which languages you can translate it to
    private function availableDictionariesFrom(?string $origin = null): array
    {
        $origin ??= $this->current_lang;
        return array_filter($this->available_languages, fn(string $item) => array_key_exists($this->factory->make(DictionaryType::class, ['src_lang' => $origin, 'target_lang' => $item])->asDictstring(), $this->config->getDictionaries()));
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

    public function relevantHint(string $hint): bool
    {
        return !$this->found_words->contains($hint);
    }

    public function preprocessWord(string $word): array
    {
        # Pre-processing word for validity check
        $word = mb_ereg_replace('[.\'-]', '', $word);
        if ($this->current_lang === 'German') {
            $word = german_letters($word);
        }
        $word = mb_strtolower($word);

        return grapheme_str_split($word);
    }

    private function setEndAmount(): void
    {
        $expected_solution_count = $this->wordlist_solutions->count();
        $this->end_amount = $this->solutions->isEmpty() ? 100 : min($this->default_end_amount, intdiv($expected_solution_count, 2));
    }

    private function setApprovedWords(): void
    {
        $this->approved_words = $this->solutions->intersect($this->found_words);
    }

    private function collator(): Collator
    {
        return new Collator($this->config->getLocale($this->current_lang));
    }

    public function foundWordsSorted(): array
    {
        $result = $this->found_words->toArray();
        $this->collator()->sort($result);
        return $result;
    }

    public function lettersSorted(): array
    {
        $result = $this->letters->list;
        $this->collator()->sort($result);
        return $result;
    }

    private function findWordlistSolutions(array $refdict): void
    {
        $wordlist_paths = array_map(fn(string $relative_path) => "param/$relative_path", $this->config->getWordlists());
        $content = file($wordlist_paths[$this->current_lang], FILE_IGNORE_NEW_LINES);
        $this->wordlist_solutions = new Set(array_filter($content, fn(string $line) => $this->wordValidFast($line, $refdict)));
    }

    public function approvalStatus(string $word): ApprovalData
    {
        $approval_data = new ApprovalData();
        $approval_data->word = $word;
        $approval_data->validity_info = $this->wordValid($word, $this->letters->lower_cntdict);
        $approval_data->wordlist = $this->wordlist_solutions->contains($word);
        $approval_data->community = in_array($word, $this->manager->current_community_list);
        $approval_data->custom_reactions = array_key_exists($word, $this->custom_emojis[$this->current_lang]);
        $approval_data->any = false;
        $approval_data->dictionary = false;
        foreach (['wordlist', 'community', 'custom_reactions'] as $key) {
            $approval_data->any = $approval_data->any || $approval_data->{$key};
        }
        $approval_data->translations = array_map(fn() => false, array_flip($this->available_languages));
        foreach ($this->availableDictionariesFrom($this->current_lang) as $language) {
            if (in_array($word, $this->available_hints[$language])) {
                $approval_data->translations[$language] = get_translation($word, $this->factory->make(DictionaryType::class, ['src_lang' => $this->current_lang, 'target_lang' => $language]), $this->db);
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

    public function isFoundApproved(string $word): bool
    {
        return $this->approved_words->contains($word);
    }

    private function findCustomEmojis(array $refdict): void
    {
        $this->custom_emoji_solution = array_filter(array_keys($this->custom_emojis[$this->current_lang]), fn(string $word) => $this->wordValidFast($word, $refdict));
    }

    public function shuffleLetters(): void
    {
        $this->letters->shuffle();
        $this->manager->saveGame();
    }

    private function ensureGameOver(Message $ctx): void
    {
        if ($this->game_over_acknowledged) {
            return;
        }
        $this->game_over_acknowledged = true;
        await($ctx->channel->sendMessage('**Congratulations! You won this game! You found ' . $this->end_amount . ' words!**'));
        await($ctx->channel->sendMessage(game_highscore($this, $this->player_handler)));
    }

    public function tryAddWord(Message $ctx, string $word): bool
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
        $this->manager->currentGameChanged();
        if ($this->solutions->contains($word)) {
            $this->addApprovedWord($ctx, $word);
        }
        $this->manager->saveGame();
        $this->player_handler->playerAddWord($ctx, $word_info);
        return true;
    }

    private function addApprovedWord(Message $ctx, string $word): void
    {
        $this->approved_words->add($word);
        if ($this->getApprovedAmount() === $this->end_amount) {
            $this->ensureGameOver($ctx);
        }
    }

    private function removeApprovedWord(string $word): void
    {
        $this->approved_words->remove($word);
    }

    public function removeWord(string $word): void
    {
        $this->found_words->remove($word);
        # removed words have always to be saved (changes_to_save)
        $this->manager->currentGameChanged();
        if ($this->solutions->contains($word)) {
            $this->removeApprovedWord($word);
        }
        $this->manager->saveGame(); # TODO revise
    }

    public function enoughWordsFound(): bool
    {
        return $this->found_words->count() >= $this->end_amount;
    }

    public function gameAwards(): array
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
            $awards['Best Beginner'] = array_filter($players, fn(string|int $player_id) => $this->player_handler->getPlayerField($player_id, 'role') === 'Beginner');
        }
        $relevant_players = array_merge(...$highscore);
        $is_newcomer = fn(array $player_data) => count($player_data['found_words']) === $player_data['all_time_found'];
        $solved_hints = fn(array $player_data) => count(array_intersect($player_data['found_words'], $player_data['used_hints']));
        $awards['Newcomer'] = array_filter($relevant_players, fn(string|int $player_id) => $is_newcomer($this->player_handler->player_dict[$player_id]));
        $most_solved_hints_amount = count($relevant_players) === 0 ? 0 : max(array_map(fn(string|int $player_id) => $solved_hints($this->player_handler->player_dict[$player_id]), $relevant_players));
        if ($most_solved_hints_amount > 0) {
            $awards['Most solved hints'] = array_filter($relevant_players, fn(string|int $player_id) => $solved_hints($this->player_handler->player_dict[$player_id]) === $most_solved_hints_amount);
        }
        return $awards;
    }

    public function isLongestSolution(string $word): bool
    {
        return $this->solutions->contains($word) && textual_length($word) >= $this->getLongestWordLength();
    }
    # TODO revise visibility
    public function acceptSolutionRetrospectively(Message $ctx, string $word): void
    {
        if ($this->wordValidFast($word, $this->letters->lower_cntdict)) {
            $this->solutions->add($word);
            if ($this->found_words->contains($word)) {
                $this->addApprovedWord($ctx, $word);
            }
            $this->player_handler->approveWord($word);
        }
    }
}
