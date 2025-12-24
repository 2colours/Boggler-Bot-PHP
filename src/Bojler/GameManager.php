<?php

namespace Bojler;

use DI\FactoryInterface;
use Discord\Parts\Channel\Message;

use function React\Async\await;

class GameManager
{
    private readonly GameStatusFactory $factory;
    private readonly PlayerHandler $player_handler;
    private readonly ConfigHandler $config;

    private readonly array $community_wordlist_paths;

    private string $file;
    private string $archive_file;
    public private(set) GameStatus $current_game;
    public private(set) string $base_lang;
    public private(set) string $planned_lang;
    public private(set) int $max_saved_game;
    public private(set) bool $changes_to_save;
    public private(set) mixed $current_community_list; # TODO revise visibility and typing


    public function __construct(string $live_data_prefix, GameStatusFactory $factory, ConfigHandler $config, PlayerHandler $player_handler)
    {
        $this->factory = $factory;
        $this->player_handler = $player_handler;
        $this->config = $config;

        $this->file = $live_data_prefix . $config->getCurrentGameFileName();
        $this->archive_file = $live_data_prefix . $config->getSavesFileName();
        $this->community_wordlist_paths = array_map(fn($value) => $live_data_prefix . $value, $this->config->getCommunityWordlists());

        $default_translation = $config->getDefaultTranslation();
        $this->base_lang = $default_translation[1];
        $this->planned_lang = $default_translation[0];

        $this->loadGame(); # TODO https://github.com/2colours/Boggler-Bot-PHP/issues/40 et al.
    }

    public function currentEntryJson(): CurrentGameData # TODO the name will eventually lose the JSON
    {
        return CurrentGameData::fromStatus($this);
    }

    public function saveGame()
    {
        file_put_contents($this->file, json_encode($this->currentEntryJson(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    public function setLang(string $lang)
    {
        $this->planned_lang = $lang;
        $this->saveGame();
    }

    private function loadGame()
    {
        $parsed = CurrentGameData::fromJsonFile($this->file);
        $this->current_game = $this->factory->createInstanceFromCurrent($this, $parsed);

        # General Settings
        $this->base_lang = $parsed->base_lang;
        $this->planned_lang = $parsed->planned_lang;
        $this->max_saved_game = $parsed->max_saved_game;
        $this->changes_to_save = true;
    }

    # TODO refine visibility
    public function currentGameChanged(): void
    {
        $this->changes_to_save = true;
    }

    private function saveOld()
    {
        # unchanged loaded old games are not saved; if the game is not saved yet in saves.txt, it is appended (determined by game_number compared to max_saved_game and number of lines in saves.txt)
        if (!$this->changes_to_save) {
            return;
        }

        # determines if game is already saved in saves.txt
        if ($this->current_game->game_number <= $this->max_saved_game) {
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
        $content[$this->current_game->game_number - 1] = ArchiveGameEntryData::fromStatus($this->current_game);
        file_put_contents($this->archive_file, json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function appendArchiveJson(): void
    {
        $content = json_decode(file_get_contents($this->archive_file), true);
        $content[] = ArchiveGameEntryData::fromStatus($this->current_game);
        file_put_contents($this->archive_file, json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    public function newGame()
    {
        $this->saveOld();
        $this->player_handler->newGame();
        if ($this->checkNewestGame()) {
            $this->tryLoadOldGame($this->max_saved_game);
            return;
        }

        $this->current_game = $this->factory->createInstanceFromNew($this, new NewGamePayload($this->max_saved_game, $this->planned_lang));
        # we have a game (thrown_the_dice), this new game will be saved, even if empty and is not yet in saves.txt (changes_to_save)
        $this->changes_to_save = true;
    }

    public function tryLoadOldGame(int $number)
    {
        $this->saveOld();
        if ($number < 1 || $this->max_saved_game < $number) {
            return false;
        }
        $this->player_handler->newGame();

        $parsed = ArchiveGameEntryData::fromJsonFile($this->archive_file, $number);
        $this->current_game = $this->factory->createInstanceFromArchive($this, $parsed);
        # this game doesn't have to be saved again in saves.txt yet (changes_to_save), we have a loaded game (thrown_the_dice)
        $this->changes_to_save = false;
        $this->saveGame();
        return true;
    }

    public function checkNewestGame()
    {
        # answer to: should we load the newest game instead of creating a new one?
        if ($this->current_game->game_number === $this->max_saved_game) {
            return false;
        }

        $parsed = ArchiveGameEntryData::fromJsonFile($this->archive_file, $this->max_saved_game);
        if ($parsed->current_lang !== $this->current_game->current_lang) {
            return false;
        }
        if (count($parsed->found_words_sorted) <= 10) {
            return true;
        }
        return false;
    }

    # TODO review visibility and architecture overall
    public function loadCommunityList()
    {
        $this->current_community_list = file($this->community_wordlist_paths[$this->current_game->current_lang], FILE_IGNORE_NEW_LINES) ?: [];
    }

    public function tryAddCommunity(Message $ctx, string $word)
    {
        if (in_array($word, $this->current_community_list)) {
            await($ctx->channel->sendMessage('Word already in the community list.'));
            return false;
        }

        $current_game = $this->current_game;
        $approval_data = $this->current_game->approvalStatus($word);
        if ($approval_data->any) {
            await($ctx->channel->sendMessage('This word is already approved.'));
            return false;
        }

        file_put_contents($this->community_wordlist_paths[$current_game->current_lang], "$word\n", FILE_APPEND);
        array_push($this->current_community_list, $word);
        $current_game->acceptSolutionRetrospectively($ctx, $word);
        return true;
    }
}
