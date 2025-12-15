<?php

declare(strict_types=1);

mb_internal_encoding('UTF-8');
mb_regex_encoding('UTF-8');

require_once __DIR__ . '/../vendor/autoload.php';

use Bojler\{
    ConfigHandler,
    Counter,
    CustomCommandClient,
    DatabaseHandler,
    DictionaryType,
    EnvironmentHandler,
    GameStatus,
    PlayerHandler,
};
use DI\ContainerBuilder;
use DI\FactoryInterface;
use Discord\Builders\MessageBuilder;
use Symfony\Component\Dotenv\Dotenv;
use Discord\Parts\Channel\Message;
use Discord\WebSockets\Intents;
use Invoker\InvokerInterface;
use Random\Randomizer;
use Monolog\{
    Logger,
    Handler\StreamHandler,
    Level,
};

use function Bojler\{
    masked_word,
    decorate_handler,
    output_split_cursive,
    acknowledgement_reaction,
    current_emoji_version,
    try_send_msg,
    game_highscore,
    get_translation,
    hungarian_role,
    italic,
    strikethrough,
    progress_bar
};
use function DI\factory;
use function React\Async\await;

$dotenv = new Dotenv();
$dotenv->load('./.env');

const CREATORS = ['297037173541175296', '217319536485990400'];
define('CONFIG', ConfigHandler::getInstance());
define('LIVE_DATA_PREFIX', 'live_data' . DIRECTORY_SEPARATOR);

const INSTRUCTION_TEMPLATE = <<<END
    __***Szórakodtató bot***__
    ***Rules:***
    _ - Build {0} words with the letters displayed._
    _ - You can use the letters in any order, but just in the displayed amount._
    _**b!s {1}** to add a found word "{1}"._
    _**b!remove {1}** to remove a solution "{1}"._
    _**b!new** to start a new game._
    _**b!help** for further commands._
    ✅ _means the word is Scrabble dictionary approved_
    ☑️ _means there is a translation available_
    ✔ _means the word is community-approved_
    ❔ _means the word is not in the dictionary (might still be right)_
    END;

# TODO maybe this would deserve a proper util function at least
# lame emulation of Python str.format as PHP only has sprintf
function instructions(ConfigHandler $config, string $lang)
{
    return str_replace(['{0}', '{1}'], [$lang, $config->getExamples()[$lang]], INSTRUCTION_TEMPLATE);
}

function translator_command(ConfigHandler $config, ?string $src_lang = null, ?string $target_lang = null)
{
    $default_translation = $config->getDefaultTranslation();
    $src_lang ??= $default_translation[0];
    $target_lang ??= $default_translation[1];
    $ctor_args = compact('src_lang', 'target_lang');
    return function (FactoryInterface $factory, DatabaseHandler $db, Message $ctx, $args) use ($ctor_args): void {
        $word = $args[0];
        $translation = get_translation($word, $factory->make(DictionaryType::class, [...$ctor_args]), $db);
        if (isset($translation)) {
            await($ctx->channel->sendMessage("$word: ||$translation||"));
        } else {
            await($ctx->react('😶'));
        }
    };
}

# internal context-dependent functions that aren't really related to command handling

#Determines which emoji reaction a certain word deserves - it doesn't remove special characters
function achievements(GameStatus $game, string $word, string $command_type)
{
    $reactions = [];
    if ($command_type === 's' && ($game->isLongestSolution($word))) {
        $reactions = ['🇳', '🇮', '🇨', '🇪'];
    }
    return $reactions;
}

function s_reactions(ConfigHandler $config, GameStatus $game, string $word)
{
    $reaction_list = [acknowledgement_reaction($word), approval_reaction($config, $game, $word)];
    return array_merge($reaction_list, achievements($game, $word, 's'));
}

# "predicate-ish" functions (not higher order, takes context, performs a check)

#Checks whether the author of the message is a "native speaker"
function from_native_speaker(Message $ctx)
{
    return hungarian_role($ctx->member) === 'Native speaker'; # TODO preferably should be configurable, not hardcoded constant
}

function from_creator(Message $ctx)
{
    return in_array($ctx->author->id, CREATORS, true);
}

#Checks if the current message is in the tracked channel
function channel_valid(EnvironmentHandler $env, Message $ctx)
{
    return $ctx->guild?->id === $env->getHomeServerId() && $ctx->channel?->id === $env->getHomeChannelId();
}

#Checks if dice are thrown, thrown_the_dice exists just for this
function needs_thrown_dice(GameStatus $game)
{
    return $game->thrown_the_dice;
}

function emoji_awarded(PlayerHandler $player, ConfigHandler $config, Message $ctx)
{
    return $player->getPlayerField($ctx->author->id, 'all_time_found') >= $config->getWordCountForEmoji();
}

# "handler-ish" functions (not higher order, takes context, DC side effects)

function send_instructions(InvokerInterface $invoker, GameStatus $game, Message $ctx): void
{
    await($ctx->channel->sendMessage($invoker->call(instructions(...), ['lang' => $game->current_lang])));
}

# sends the small game board with the found words if they fit into one message
function simple_board(InvokerInterface $invoker, ConfigHandler $config, Message $ctx): void
{
    $found_words_display = $invoker->call(found_words_output(...));
    $message = "**Already found words:** $found_words_display";
    if (!(try_send_msg($ctx, $message))) {
        await($ctx->channel->sendMessage('_Too many found words. Please use b!see._'));
    }
    await($ctx->channel->sendMessage(MessageBuilder::new()->addFile($config->getDisplaySmallFilePath(LIVE_DATA_PREFIX))));
}

# "decorator-ish" stuff (produces something "handler-ish" or something "decorator-ish")
function needs_counting(callable $handler)
{
    return function (Counter $counter, InvokerInterface $invoker, $ctx, $args) use ($handler) {
        $invoker->call($handler, ['ctx' => $ctx, 'args' => $args]);
        if ($counter->trigger()) {
            $invoker->call(simple_board(...), ['ctx' => $ctx]);
        }
    };
}

# $refusalMessageProducer is a function that can take $ctx
function ensure_predicate(callable $predicate, ?callable $refusalMessageProducer = null)
{
    return fn($handler) => function (InvokerInterface $invoker, Message $ctx, $args) use ($handler, $predicate, $refusalMessageProducer) {
        if ($invoker->call($predicate, ['ctx' => $ctx])) {
            $invoker->call($handler, ['ctx' => $ctx, 'args' => $args]);
        } elseif (isset($refusalMessageProducer)) {
            await($ctx->reply($invoker->call($refusalMessageProducer, ['ctx' => $ctx])));
        }
    };
}

$builder = new ContainerBuilder();
if ((new EnvironmentHandler())->isRunningInProduction())
{
    $builder->enableCompilation('php_cache');
}
$builder->addDefinitions([
    ConfigHandler::class => factory(ConfigHandler::getInstance(...)),
    DatabaseHandler::class => factory(DatabaseHandler::getInstance(...)),
    PlayerHandler::class => factory(PlayerHandler::getInstance(...)),
    GameStatus::class => DI\autowire()->constructor(LIVE_DATA_PREFIX),
    Counter::class => new Counter(10),
    Randomizer::class => new Randomizer(),
    EnvironmentHandler::class => new EnvironmentHandler()
]);
$container = $builder->build();
# TODO it's dubious whether these are actually constants; gotta think about it
# define('easter_egg_handler', new EasterEggHandler($game->found_words_set));
const BOT_LOGGER = new Logger('bojlerLogger');
BOT_LOGGER->pushHandler(new StreamHandler('php://stdout', Level::Debug));

$bot = new CustomCommandClient($container, [
    'prefix' => 'b!',
    'token' => $container->get(EnvironmentHandler::class)->getDiscordToken(),
    'description' => 'Szórakodtató bot',
    'discordOptions' => [
        'logger' => BOT_LOGGER,
        'intents' => Intents::getDefaultIntents() | Intents::MESSAGE_CONTENT # Note: MESSAGE_CONTENT is privileged, see https://dis.gd/mcfaq
    ],
    'caseInsensitiveCommands' => true,
    'customOptions' => [
        'locale' => CONFIG->getLocale(CONFIG->getDefaultTranslation()[0]), # TODO allow configuration of locale during the usage of the bot
        'caseInsensitivePrefix' => true
    ]
]); # TODO https://github.com/2colours/Boggler-Bot-PHP/issues/49

$bot->registerCommand('info', send_instructions(...), ['description' => 'show instructions']);
$bot->registerCommand('teh', translator_command($container->get(ConfigHandler::class), 'English', 'Hungarian'), ['description' => 'translate given word Eng-Hun']);
$bot->registerCommand('the', translator_command($container->get(ConfigHandler::class), 'Hungarian', 'English'), ['description' => 'translate given word Hun-Eng']);
$bot->registerCommand('thg', translator_command($container->get(ConfigHandler::class), 'Hungarian', 'German'), ['description' => 'translate given word Hun-Ger']);
$bot->registerCommand('tgh', translator_command($container->get(ConfigHandler::class), 'German', 'Hungarian'), ['description' => 'translate given word Ger-Hun']);
$bot->registerCommand('thh', translator_command($container->get(ConfigHandler::class), 'Hungarian', 'Hungarian'), ['description' => 'translate given word Hun-Hun']);
$bot->registerCommand('t', function (InvokerInterface $invoker, GameStatus $game, Message $ctx, $args): void {
    $translator_args = $invoker->call(channel_valid(...), compact('ctx'))
        ? ['src_lang' => $game->current_lang, 'target_lang' => $game->base_lang]
        : [];
    translator_command(...)
        |> (fn($tbuilder) => $invoker->call($tbuilder, $translator_args))
        |> (fn($handler) => $invoker->call($handler, compact('ctx', 'args')));
}, ['description' => 'translate given word']);
$bot->registerCommand('stats', function (PlayerHandler $player, Message $ctx) {
    $infos = $player->player_dict[$ctx->author->id];
    if (is_null($infos)) {
        await($ctx->reply('You don\'t have any statistics registered.'));
        return;
    }
    $found_words = implode(', ', $infos['found_words']);
    await($ctx->channel->sendMessage(<<<END
        **Player stats for $infos[server_name]:**
        *Total found words:* $infos[all_time_found]
        *Approved words in all games:* $infos[all_time_approved]
        *Personal emoji:* $infos[personal_emoji]
        *Words found in current game:* $found_words
        END));
}, ['description' => 'send user stats']);

$bot->registerCommand(
    'trigger',
    decorate_handler([ensure_predicate(from_creator(...), fn() => 'This would be very silly now, wouldn\'t it.')], 'trigger'),
    ['description' => 'testing purposes only']
);
function trigger(Message $ctx, $args): void
{
    await($ctx->reply('Congrats, Master.'));
}

$bot->registerCommand(
    'nextlang',
    decorate_handler([ensure_predicate(channel_valid(...))], 'next_language'),
    ['description' => 'change language']
);
function next_language(ConfigHandler $config, GameStatus $game, Message $ctx, $args): void
{
    $available_languages = $config->getAvailableLanguages();
    $lang = $args[0];
    if (is_null($lang) || !in_array($lang, $available_languages)) {
        $languages = implode(', ', $available_languages);
        await($ctx->reply(<<<END
            Please provide an argument <language>.
            <language> should be one of the values [$languages].
            END));
        return;
    }
    $game->setLang($lang);
    await($ctx->channel->sendMessage("Changed language to $lang for the next games."));
}

$bot->registerCommand(
    'new',
    decorate_handler([ensure_predicate(channel_valid(...))], new_game(...)),
    ['description' => 'start new game']
);
function new_game(Counter $counter, ConfigHandler $config, GameStatus $game, PlayerHandler $player, Message $ctx): void
{
    # this isn't perfect, but at least it won't display this "found words" always when just having looked at an old game for a second. That would be annoying.
    if ($game->changes_to_save) {
        await($ctx->channel->sendMessage(game_highscore($game, $player)));
        $message = 'All words found in the last game: ' . found_words_output($config, $game) . "\n\n" . instructions($config, $game->planned_lang) . "\n\n";
        foreach (output_split_cursive($message) as $item) {
            await($ctx->channel->sendMessage($item));
        }
    } else {
        await($ctx->channel->sendMessage(instructions($config, $game->planned_lang) . "\n\n"));
    }

    $ctx->channel->broadcastTyping(); # TODO better synchronization maybe?
    $game->newGame();
    $counter->reset();

    $game_number = $game->game_number;
    $solutions_count = $game->solutions->count();
    $emoji_version_description = current_emoji_version($config, $game)[0];
    await($ctx->channel->sendMessage(<<<END
        Game #**$game_number** _($emoji_version_description)_:
        **($solutions_count)** possible approved words
        END));
    await($ctx->channel->sendMessage(MessageBuilder::new()->addFile($config->getDisplaySmallFilePath(LIVE_DATA_PREFIX))));
}

$bot->registerCommand(
    'see',
    decorate_handler([ensure_predicate(channel_valid(...))], see(...)),
    ['description' => 'show current game']
);

function see(Counter $counter, InvokerInterface $invoker, ConfigHandler $config, GameStatus $game, Message $ctx): void
{
    $message = '**Game #' . $game->game_number . ': Already found words:** ' . $invoker->call(found_words_output(...));
    foreach (output_split_cursive($message) as $part) {
        await($ctx->channel->sendMessage($part));
    }
    await($ctx->channel->sendMessage(MessageBuilder::new()->addFile($config->getDisplaySmallFilePath(LIVE_DATA_PREFIX))));
    $counter->reset();
}

define('ENSURE_THROWN_DICE', ensure_predicate(needs_thrown_dice(...), fn() => '_Please load game using_ **b!loadgame** _or start a new game using_ **b!new**'));

$bot->registerCommand(
    'status',
    decorate_handler([ENSURE_THROWN_DICE], status(...)),
    ['description' => 'show current status of the game']
);

function status(Counter $counter, InvokerInterface $invoker, ConfigHandler $config, GameStatus $game, Message $ctx): void
{
    $space_separated_letters = implode(' ', $game->letters->list);
    $found_words_output = $invoker->call(found_words_output(...));
    $emoji_version_description = $invoker->call(current_emoji_version(...))[0];
    $solutions_count = $game->solutions->count();
    $status_text = <<<END
        **Game #{$game->game_number}** (saved {$game->max_saved_game}) $space_separated_letters _($emoji_version_description)_
        $found_words_output
        **$solutions_count** approved words (end amount: **{$game->end_amount}**)
        Current language: {$game->current_lang}
        END;
    if ($game->current_lang !== $game->planned_lang) {
        $status_text .= ", next game: {$game->planned_lang}";
    }
    foreach (output_split_cursive($status_text) as $part) {
        await($ctx->channel->sendMessage($part));
    }
    await($ctx->channel->sendMessage(MessageBuilder::new()->addFile($config->getDisplaySmallFilePath(LIVE_DATA_PREFIX))));
    $counter->reset();
}

$bot->registerCommand(
    'unfound',
    decorate_handler(
        [ensure_predicate(fn(GameStatus $game) => $game->enoughWordsFound(), fn(GameStatus $game) => 'You have to find ' . $game->end_amount . ' words first.'), needs_counting(...)],
        unfound(...)
    ),
    ['description' => 'send unfound Scrabble solutions']
);

function unfound(GameStatus $game, Message $ctx): void
{
    $unfound_file = LIVE_DATA_PREFIX . 'unfound_solutions.txt';
    #$found_words_caps = array_map(mb_strtoupper(...), $game->found_words->toArray());
    file_put_contents($unfound_file, implode("\n", $game->solutions->diff($game->found_words)->toArray()));
    await($ctx->channel->sendMessage(MessageBuilder::new()->addFile($unfound_file)));
}

$bot->registerCommand(
    'left',
    decorate_handler(
        [needs_counting(...)],
        left(...)
    ),
    ['description' => 'amount of unfound Scrabble solutions']
);

function left(GameStatus $game, Message $ctx): void
{
    $amount = $game->solutions->diff($game->found_words)->count();
    #$hints_caps = array_map(mb_strtoupper(...), $game->available_hints);
    $unfound_hints_without_empty = array_filter(
        array_map(
            fn($hints_for_language) => array_filter($hints_for_language, fn($hint) => !$game->found_words->contains($hint)),
            $game->available_hints
        ),
        fn($hints_for_language) => count($hints_for_language) > 0
    );
    $hint_count_by_language = implode(
        ', ',
        array_map(
            fn($language, $hints_for_language) => count($hints_for_language) . " $language",
            array_keys($unfound_hints_without_empty),
            array_values($unfound_hints_without_empty)
        )
    ) ?: '0';
    $solution_count = $game->solutions->count();
    await($ctx->channel->sendMessage("**$amount** approved words left (of $solution_count) - $hint_count_by_language hints left."));
}

$bot->registerCommand(
    'shuffle',
    decorate_handler(
        [ensure_predicate(channel_valid(...))],
        shuffle2(...)
    ),
    ['description' => 'shuffle the position of dice']
);

function shuffle2(GameStatus $game, ConfigHandler $config, Message $ctx): void
{
    $game->shuffleLetters();
    await($ctx->channel->sendMessage('**Letters shuffled.**'));
    await($ctx->channel->sendMessage(MessageBuilder::new()->addFile($config->getDisplayNormalFilePath(LIVE_DATA_PREFIX))));
}


$bot->registerCommand(
    's',
    decorate_handler(
        [ensure_predicate(channel_valid(...)), ENSURE_THROWN_DICE, needs_counting(...)],
        'add_solution'
    ),
    ['description' => 'add solution'] # TODO alias to empty string?
);

function add_solution(ConfigHandler $config, GameStatus $game, Message $ctx, $args): void
{
    var_dump($args);
    $word = $args[0];
    $success = $game->tryAddWord($ctx, $word);
    if ($success) {
        foreach (s_reactions($config, $game, $word) as $reaction) {
            await($ctx->react($reaction));
        }
    }
}

$bot->registerCommand(
    'remove',
    decorate_handler(
        [ensure_predicate(channel_valid(...)), needs_counting(...)],
        'remove'
    ),
    ['description' => 'remove solution', 'aliases' => ['r']]
);

function remove(GameStatus $game, PlayerHandler $player, Message $ctx, $args): void
{
    $word = $args[0];
    if ($game->found_words->contains($word)) {
        $game->removeWord($word);
        $player->playerRemoveWord($ctx, $game->approvalStatus($word));
        $formatted_word = italic($word);
        await($ctx->channel->sendMessage("Removed $formatted_word."));
    } else {
        await($ctx->channel->sendMessage("$word doesn't appear among the found solutions."));
    }
}

$bot->registerCommand(
    'highscore',
    highscore(...),
    ['description' => 'send highscore']
);

function highscore(GameStatus $game, PlayerHandler $player, Message $ctx): void
{
    await($ctx->channel->sendMessage(game_highscore($game, $player)));
}

$bot->registerCommand(
    'add',
    decorate_handler(
        [ensure_predicate(from_native_speaker(...), fn() => 'Only native speakers can use this command.'), needs_counting(...)],
        'add'
    ),
    ['description' => 'add to community wordlist']
);

function add(GameStatus $game, Message $ctx, $args): void
{
    $word = $args[0];
    if ($game->tryAddCommunity($ctx, $word)) {
        await($ctx->react('📝'));
    }
}

$bot->registerCommand(
    'communitylist',
    community_list(...),
    ['description' => 'send current community list']
);

function community_list(ConfigHandler $config, GameStatus $game, Message $ctx): void
{
    $file_to_send = LIVE_DATA_PREFIX . $config->getCommunityWordlists()[$game->current_lang];
    if (!is_file($file_to_send)) {
        touch($file_to_send);
    }
    await($ctx->channel->sendMessage(MessageBuilder::new()->addFile($file_to_send)));
}

$bot->registerCommand(
    'emoji',
    decorate_handler(
        [ensure_predicate(emoji_awarded(...), fn(PlayerHandler $player, ConfigHandler $config, Message $ctx) => 'You have to find ' . $config->getWordCountForEmoji() . ' words first! (currently ' . $player->getPlayerField($ctx->author->id, 'all_time_found') . ')')],
        'emoji'
    ),
    ['description' => 'change your personal emoji']
);

function emoji(PlayerHandler $player, Message $ctx, $args): void
{
    $emoji_str = $args[0];
    $player->setEmoji($ctx->author->id, $emoji_str);
    await($ctx->channel->sendMessage("Changed emoji to $emoji_str."));
}

$bot->registerCommand(
    'hint',
    decorate_handler(
        [ensure_predicate(channel_valid(...)), needs_counting(...)],
        hint_command('English')
    ),
    ['description' => 'give a hint in English']
);

$bot->registerCommand(
    'hinweis',
    decorate_handler(
        [ensure_predicate(channel_valid(...)), needs_counting(...)],
        hint_command('German')
    ),
    ['description' => 'give a hint in German']
);

$bot->registerCommand(
    'súgás',
    decorate_handler(
        [ensure_predicate(channel_valid(...)), needs_counting(...)],
        hint_command('Hungarian')
    ),
    ['description' => 'give a hint in Hungarian']
);

function hint_command(string $from_language)
{
    return function (GameStatus $game, FactoryInterface $factory, PlayerHandler $player, DatabaseHandler $db, Message $ctx) use ($from_language): void {
        $unfound_hint_list = array_values(array_filter($game->available_hints[$from_language], fn($hint) => !$game->found_words->contains($hint)));
        if (count($unfound_hint_list) === 0) {
            await($ctx->channel->sendMessage('No hints left.'));
            return;
        }
        $chosen_hint = $unfound_hint_list[array_rand($unfound_hint_list)];
        $formatted_hint_content = italic(get_translation($chosen_hint, $factory->make(DictionaryType::class, ['src_lang' => $game->current_lang, 'target_lang' => $from_language]), $db));
        await($ctx->channel->sendMessage("hint: $formatted_hint_content"));
        $player->playerUsedHint($ctx, $chosen_hint);
    };
}

$bot->registerCommand(
    'oldgames',
    old_games(...),
    ['description' => 'send saved games']
);

function old_games(ConfigHandler $config, Message $ctx): void
{
    await($ctx->channel->sendMessage(MessageBuilder::new()->addFile(LIVE_DATA_PREFIX . $config->getSavesFileName())));
}

$bot->registerCommand(
    'loadgame',
    decorate_handler(
        [ensure_predicate(channel_valid(...))],
        'load_game'
    ),
    ['description' => 'load older games (see: oldgames), example: b!loadgame 5']
);

function load_game(Counter $counter, InvokerInterface $invoker, ConfigHandler $config, GameStatus $game, Message $ctx, $args): void
{
    $game_number = (int) $args[0];
    # Checks for changes before showing "found words" every time when just skipping through old games. That would be annoying.
    if ($game->changes_to_save) {
        $message = 'All words found in the last game: ' . $invoker->call(found_words_output(...));
        if (!try_send_msg($ctx, $message)) {
            $found_words_count = $game->found_words->count();
            await($ctx->channel->sendMessage("**$found_words_count** words found in this game. **"));
        }
    }
    $ctx->channel->broadcastTyping(); # TODO better synchronization maybe?
    if (!$game->tryLoadOldGame($game_number)) {
        await($ctx->channel->sendMessage('The requested game doesn\'t exist.'));
        return;
    }
    # here current_lang, because this is loaded from saves.txt
    $game_number = $game->game_number;
    $current_lang = $game->current_lang;
    $found_words_output = $invoker->call(found_words_output(...));
    $message = <<<END


    **Game #$game_number** ($current_lang)
    **Already found words:** $found_words_output
    END;
    foreach (output_split_cursive($message) as $part) {
        await($ctx->channel->sendMessage($part));
    }
    await($ctx->channel->sendMessage(MessageBuilder::new()->addFile($config->getDisplayNormalFilePath(LIVE_DATA_PREFIX))));
    $counter->reset();
}

$bot->registerCommand(
    'random',
    decorate_handler(
        [ensure_predicate(channel_valid(...))],
        random(...)
    ),
    ['description' => 'load random old game']
);

function random(Counter $counter, ConfigHandler $config, GameStatus $game, Message $ctx): void
{
    # random game between 1 and max_saved_game - after one calling, newest game is saved, too. Newest game can appear as second random call
    $number = random_int(1, $game->max_saved_game + 1);
    if ($game->thrown_the_dice) {
        await($ctx->channel->sendMessage('All words found in the last game: ' . found_words_output($config, $game)));
    }
    $game->tryLoadOldGame($number);
    # here current_lang, because this is loaded from saves.txt
    $game_number = $game->game_number;
    $current_lang = $game->current_lang;
    $found_words_output = found_words_output($config, $game);
    $message = <<<END


    **Game #$game_number** ($current_lang)
    **Already found words:** $found_words_output
    END;
    await($ctx->channel->sendMessage(MessageBuilder::new()->addFile($config->getDisplayNormalFilePath(LIVE_DATA_PREFIX))));
    $counter->reset();
}

$bot->registerCommand(
    'reveal',
    decorate_handler(
        [ensure_predicate(channel_valid(...)), needs_counting(...)],
        reveal(...)
    ),
    ['description' => 'reveal letters of a previously requested hint']
);

function reveal(Randomizer $randomizer, GameStatus $game, PlayerHandler $player, Message $ctx): void
{
    $player_hints = $player->getPlayerField($ctx->author->id, 'used_hints');
    $left_hints = array_diff($player_hints, $game->found_words->toArray());
    if (count($left_hints) === 0) {
        await($ctx->channel->sendMessage('You have no unsolved hints left.'));
        return;
    }
    $chosen_word = $left_hints[array_rand($left_hints)];
    $word_length = grapheme_strlen($chosen_word);
    $revealed_indices = $randomizer->pickArrayKeys(range(0, $word_length - 1), intdiv($word_length, 3));
    $formatted_masked_word = italic(masked_word($chosen_word, $revealed_indices));
    await($ctx->channel->sendMessage("Hint: $formatted_masked_word"));
}

$bot->registerCommand(
    'longest',
    decorate_handler(
        [ensure_predicate(channel_valid(...)), needs_counting(...)],
        longest(...)
    ),
    ['description' => 'tell the length of the longest word(s)']
);

function longest(GameStatus $game, Message $ctx): void
{
    $formatted_longest_word_length = italic((string) $game->getLongestWordLength());
    await($ctx->channel->sendMessage("The largest possible word length is: $formatted_longest_word_length."));
}

# Blocks the code - has to be at the bottom
$bot->run();

function approval_reaction(ConfigHandler $config, GameStatus $game, string $word): string
{
    $custom_emojis = $config->getCustomEmojis();
    if (array_key_exists($word, $custom_emojis[$game->current_lang])) {
        $custom_reaction_list = $custom_emojis[$game->current_lang][$word];
        return $custom_reaction_list[array_rand($custom_reaction_list)];
    }
    $approval_status = $game->approvalStatus($word);
    return match (true) {
        (bool) @$approval_status->translations[$game->base_lang] => '☑️',
        $approval_status->wordlist => '✅',
        array_any($config->getAvailableLanguages(), fn($lang) => @$approval_status->translations[$lang]) => '✅',
        $approval_status->community => '✔',
        default => '❔'
    };
}

function found_words_output(ConfigHandler $config, GameStatus $game)
{
    $found_word_list = $game->foundWordsSorted();
    if (count($found_word_list) === 0) {
        return 'No words found yet 😭';
    }
    [$found_word_list_formatted, $found_word_list_length] = [format_found_words($game, $found_word_list), count($found_word_list)];
    $progress_bar = progress_bar($config, $game);
    [$amount_approved_words, $end_amount] = [$game->getApprovedAmount(), $game->end_amount];
    return <<<END
        _$found_word_list_formatted ($found_word_list_length)_
        $progress_bar ($amount_approved_words/$end_amount)
        END;
}

function format_found_words(GameStatus $game, $words): string
{
    return implode(
        ', ',
        array_map(fn($word) => $game->isFoundApproved($word) ? $word : strikethrough($word), $words)
    );
}

/*import math
from json import load, dump
from asyncio import sleep
from bojler_db import DatabaseHandler, DictionaryType
from bojler_config import ConfigHandler
from bojler_game_status import GameStatus, EasterEggHandler
from bojler_util import remove_special_char, output_split_cursive
from EasterEgg_Adventure import Adventure



#adventure=Adventure(game_status.letters.list, game_status.solutions)
adventure=Adventure(game_status.letters.list, set(custom_emojis[game_status.current_lang].keys()))

    async def bojler(ctx): messages=easter_eggs["bojler"] times=[0,1.5,1.5,1.5,1.5,0.3,0.3] message=await ctx.send(messages[0]) for i in range(1,len(messages)): await sleep(times[i]) await message.edit(content=messages[i]) await sleep(0.3) await message.delete()
    async def quick_walk(ctx, arg): messages=easter_eggs[arg] message=await ctx.send(messages[0]) for i in range(1,len(messages)): await sleep(0.3) await message.edit(content=messages[i]) await sleep(0.3) await message.delete()
    async def easter_egg_trigger(ctx, word, add='' ): # handle_easter_eggs decides which one to trigger, this here triggers it then type=easter_egg_handler.handle_easter_eggs(word, add) if not type: return print("Easter Egg") # to tell us when this might be responsible for anything if type=="nyan" : await quick_walk(ctx, "nyan" ) elif type=="bojler" : await bojler(ctx) elif type=="tongue" : message=await ctx.send("😝") await sleep(0.3) await message.delete() elif type=="varázsló" : await quick_walk(ctx, "varázsló" ) elif type=="huszár" : message=await ctx.send(easter_eggs["nagyhuszár"][0]) await sleep(2) await message.delete()

        # async def test(ctx, *, arg): for more than one word (whole message)

        @bot.command(hidden=True,brief='load saved game')
        @commands.is_owner()
        @commands.check(savefile_valid)
        async def load(ctx):
        # For manually loading the game, for example for testing a certain custam save file or some unexpected error occures
        game_status.load_game()
        message = "**Game loaded: **" + ' '.join(game_status.letters.list) + "\n" + found_words_output()
        if not (await ctx.try_send(message)):
        await ctx.send("_Too many words found. Please use b!see._")
        with open(image_filepath_small, "rb") as f:
        await ctx.send(file=discord.File(f))

        #debug/testing stuff
        @bot.command(hidden=True,brief='delete long time saves (current game is deleted with \'new\')')
        @commands.is_owner()
        async def deletesavesyesireallywantthis(ctx):
        with open(saves_filepath, 'w') as f:
        pass

        @bot.command(hidden=True,brief='set home server & channel')
        @commands.is_owner()
        async def sethome(ctx, arg = ''):
        global home_server
        home_server = ctx.guild.id
        print(ctx.guild.id)
        global home_channel
        home_channel = ctx.channel.id
        print(ctx.channel.id)


        @bot.event
        async def on_command_error(ctx, error):
        if isinstance(error, commands.errors.CheckFailure):
        pass
        else:
        print(error)

        @bot.event
        async def on_ready():
        print('BOT STARTED.')

        ############## ADVENTURE STUFF ###############

        async def send_messages(ctx, message_dict):
        for item in message_dict:
        if item == "author":
        await ctx.author.send("\n".join(message_dict["author"]))
        else:
        user = await bot.fetch_user(int(item))
        await user.send("\n".join(message_dict[item]))


        @bot.command(hidden=True, brief = "enter game")
        async def hiddenadventure(ctx):
        await send_messages(ctx, adventure.enter(ctx.author))

        @bot.command(hidden=True, brief = "look around")
        async def look(ctx):
        await ctx.send(adventure.get_description_for(str(ctx.author.id)))

        @bot.command(hidden=True, brief = "go north")
        async def north(ctx):
        await send_messages(ctx, adventure.north(str(ctx.author.id)))


        @bot.command(hidden=True, brief = "go east")
        async def east(ctx):
        await send_messages(ctx, adventure.east(str(ctx.author.id)))

        @bot.command(hidden=True, brief = "go south")
        async def south(ctx):
        await send_messages(ctx, adventure.south(str(ctx.author.id)))

        @bot.command(hidden=True, brief = "go west")
        async def west(ctx):
        await send_messages(ctx, adventure.west(str(ctx.author.id)))

        @bot.command(hidden=True, brief = "exit game")
        async def exit(ctx):
        await send_messages(ctx, adventure.exit(ctx.author))

        @bot.command(hidden=True, brief = "take something")
        async def take(ctx, arg = ""):
        if arg:
        await send_messages(ctx, adventure.take(str(ctx.author.id), arg))
        else:
        await ctx.send("What do you want to take?")

        @bot.command(hidden=True, brief = "drop something")
        async def drop(ctx, arg = ""):
        if arg:
        await send_messages(ctx, adventure.drop(str(ctx.author.id), arg))
        else:
        await ctx.send("What do you want to drop?")

        @bot.command(hidden=True, brief = "rob someone")
        async def rob(ctx, person=""):
        if person:
        await send_messages(ctx, adventure.rob(str(ctx.author.id), person))
        else:
        await ctx.send("Whom do you want to rob?")

        @bot.command(hidden=True, brief = "inventory")
        async def inventory(ctx):
        await ctx.send(adventure.get_inventory(str(ctx.author.id)))

        @bot.command(hidden=True, brief = "set custom name")
        async def name(ctx, arg):
        adventure.set_custom_name(str(ctx.author.id), arg)
        adventure.player_update(ctx.author)


        */
