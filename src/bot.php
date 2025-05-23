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
    GameStatus,
    PlayerHandler,
};
use Discord\Builders\MessageBuilder;
use Symfony\Component\Dotenv\Dotenv;
use Discord\Parts\Channel\Message;
use Discord\WebSockets\Intents;
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
    try_send_msg,
    game_highscore,
    get_translation,
    hungarian_role,
    italic,
    strikethrough,
    progress_bar
};
use function React\Async\await;

$dotenv = new Dotenv();
$dotenv->load('./.env');

const CREATORS = ['297037173541175296', '217319536485990400'];
# TODO https://github.com/2colours/Boggler-Bot-PHP/issues/26
define('CONFIG', ConfigHandler::getInstance());
define('IMAGE_FILEPATH_NORMAL', 'live_data/' . CONFIG->getDisplayNormalFileName());
define('IMAGE_FILEPATH_SMALL', 'live_data/' . CONFIG->getDisplaySmallFileName());
define('SAVES_FILEPATH', 'live_data/' . CONFIG->getSavesFileName());
define('CURRENT_GAME', 'live_data/' . CONFIG->getCurrentGameFileName());
define('EXAMPLES', CONFIG->getExamples());
define('COMMUNITY_WORDLISTS', CONFIG->getCommunityWordlists());
define('DICTIONARIES', CONFIG->getDictionaries());
define('HOME_SERVER', $_ENV['HOME_SERVER']);
define('HOME_CHANNEL', $_ENV['HOME_CHANNEL']);
define('PROGRESS_BAR_VERSION', CONFIG->getProgressBarVersion());
define('CUSTOM_EMOJIS', CONFIG->getCustomEmojis());
define('AVAILABLE_LANGUAGES', CONFIG->getAvailableLanguages());

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
function instructions(string $lang)
{
    return str_replace(['{0}', '{1}'], [$lang, EXAMPLES[$lang]], INSTRUCTION_TEMPLATE);
}

function translator_command(?string $src_lang = null, ?string $target_lang = null)
{
    return function (Message $ctx, $args) use ($src_lang, $target_lang): void {
        $word = $args[0];
        $ctor_args = array_map(fn($first_choice, $default) => $first_choice ?? $default, [$src_lang, $target_lang], DEFAULT_TRANSLATION);
        $translation = get_translation($word, new DictionaryType(...$ctor_args), DatabaseHandler::getInstance()); # TODO https://github.com/2colours/Boggler-Bot-PHP/issues/26
        if (isset($translation)) {
            await($ctx->channel->sendMessage("$word: ||$translation||"));
        } else {
            await($ctx->react('😶'));
        }
    };
}

# internal context-dependent functions that aren't really related to command handling

#Determines which emoji reaction a certain word deserves - it doesn't remove special characters
function achievements(Message $ctx, string $word, string $command_type)
{
    $reactions = [];
    if ($command_type === 's' && (GAME_STATUS->isLongestSolution($word))) {
        $reactions = ['🇳', '🇮', '🇨', '🇪'];
    }
    return $reactions;
}

function s_reactions(Message $ctx, string $word)
{
    $reaction_list = [acknowledgement_reaction($word), approval_reaction($word)];
    return array_merge($reaction_list, achievements($ctx, $word, 's'));
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
function channel_valid(Message $ctx)
{
    return $ctx->guild?->id === HOME_SERVER && $ctx->channel?->id === HOME_CHANNEL;
}

#Checks if dice are thrown, thrown_the_dice exists just for this
function needs_thrown_dice()
{
    return GAME_STATUS->thrown_the_dice; # TODO really not nice dependency, especially if we want to move the function
}

function emoji_awarded(Message $ctx)
{
    return PlayerHandler::getInstance()->getPlayerField($ctx->author->id, 'all_time_found') >= CONFIG->getWordCountForEmoji();
}

# "handler-ish" functions (not higher order, takes context, DC side effects)

function send_instructions(Message $ctx): void
{
    await($ctx->channel->sendMessage(instructions(GAME_STATUS->current_lang)));
}

# sends the small game board with the found words if they fit into one message
function simple_board(Message $ctx): void
{
    $found_words_display = found_words_output();
    $message = "**Already found words:** $found_words_display";
    if (!(try_send_msg($ctx, $message))) {
        await($ctx->channel->sendMessage('_Too many found words. Please use b!see._'));
    }
    await($ctx->channel->sendMessage(MessageBuilder::new()->addFile(IMAGE_FILEPATH_SMALL)));
}

# "decorator-ish" stuff (produces something "handler-ish" or something "decorator-ish")
function needs_counting(callable $handler)
{
    return function ($ctx, ...$args) use ($handler) {
        $handler($ctx, ...$args);
        if (COUNTER->trigger()) {
            simple_board($ctx);
        }
    };
}

# $refusalMessageProducer is a function that can take $ctx
function ensure_predicate(callable $predicate, ?callable $refusalMessageProducer = null)
{
    return fn($handler) => function (Message $ctx, ...$args) use ($handler, $predicate, $refusalMessageProducer) {
        if ($predicate($ctx)) {
            $handler($ctx, ...$args);
        } elseif (isset($refusalMessageProducer)) {
            await($ctx->reply($refusalMessageProducer($ctx)));
        }
    };
}

# TODO it's dubious whether these are actually constants; gotta think about it
define('GAME_STATUS', new GameStatus(CURRENT_GAME, SAVES_FILEPATH));
# define('easter_egg_handler', new EasterEggHandler(GAME_STATUS->found_words_set));
const COUNTER = new Counter(10);
const RNG = new Randomizer();
const BOT_LOGGER = new Logger('bojlerLogger');
BOT_LOGGER->pushHandler(new StreamHandler('php://stdout', Level::Debug));

$bot = new CustomCommandClient([
    'prefix' => 'b!',
    'token' => $_ENV['DC_TOKEN'],
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
]);

$bot->registerCommand('info', send_instructions(...), ['description' => 'show instructions']);
$bot->registerCommand('teh', translator_command('English', 'Hungarian'), ['description' => 'translate given word Eng-Hun']);
$bot->registerCommand('the', translator_command('Hungarian', 'English'), ['description' => 'translate given word Hun-Eng']);
$bot->registerCommand('thg', translator_command('Hungarian', 'German'), ['description' => 'translate given word Hun-Ger']);
$bot->registerCommand('tgh', translator_command('German', 'Hungarian'), ['description' => 'translate given word Ger-Hun']);
$bot->registerCommand('thh', translator_command('Hungarian', 'Hungarian'), ['description' => 'translate given word Hun-Hun']);
$bot->registerCommand('t', function (Message $ctx, $args): void {
    $translator_args = channel_valid($ctx) ? [GAME_STATUS->current_lang, GAME_STATUS->base_lang] : [];
    translator_command(...$translator_args)($ctx, $args);
}, ['description' => 'translate given word']);
$bot->registerCommand('stats', function (Message $ctx) {
    $infos = PlayerHandler::getInstance()->player_dict[$ctx->author->id]; # TODO https://github.com/2colours/Boggler-Bot-PHP/issues/26
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
function next_language(Message $ctx, $args): void
{
    $lang = $args[0];
    if (is_null($lang) || !in_array($lang, AVAILABLE_LANGUAGES)) {
        $languages = implode(', ', AVAILABLE_LANGUAGES);
        await($ctx->reply(<<<END
            Please provide an argument <language>.
            <language> should be one of the values [$languages].
            END));
        return;
    }
    GAME_STATUS->setLang($lang);
    await($ctx->channel->sendMessage("Changed language to $lang for the next games."));
}

$bot->registerCommand(
    'new',
    decorate_handler([ensure_predicate(channel_valid(...))], new_game(...)),
    ['description' => 'start new game']
);
function new_game(Message $ctx): void
{
    # this isn't perfect, but at least it won't display this "found words" always when just having looked at an old game for a second. That would be annoying.
    if (GAME_STATUS->changes_to_save) {
        await($ctx->channel->sendMessage(game_highscore(GAME_STATUS, PlayerHandler::getInstance())));
        $message = 'All words found in the last game: ' . found_words_output() . "\n\n" . instructions(GAME_STATUS->planned_lang) . "\n\n";
        foreach (output_split_cursive($message) as $item) {
            await($ctx->channel->sendMessage($item));
        }
    } else {
        await($ctx->channel->sendMessage(instructions(GAME_STATUS->planned_lang) . "\n\n"));
    }

    $ctx->channel->broadcastTyping(); # TODO better synchronization maybe?
    GAME_STATUS->newGame();
    COUNTER->reset();

    $game_number = GAME_STATUS->game_number;
    $solutions_count = GAME_STATUS->solutions->count();
    $emoji_version_description = current_emoji_version()[0];
    await($ctx->channel->sendMessage(<<<END
        Game #**$game_number** _($emoji_version_description)_:
        **($solutions_count)** possible approved words
        END));
    await($ctx->channel->sendMessage(MessageBuilder::new()->addFile(IMAGE_FILEPATH_NORMAL)));
}

$bot->registerCommand(
    'see',
    decorate_handler([ensure_predicate(channel_valid(...))], see(...)),
    ['description' => 'show current game']
);

function see(Message $ctx): void
{
    $message = '**Game #' . GAME_STATUS->game_number . ': Already found words:** ' . found_words_output();
    foreach (output_split_cursive($message) as $part) {
        await($ctx->channel->sendMessage($part));
    }
    await($ctx->channel->sendMessage(MessageBuilder::new()->addFile(IMAGE_FILEPATH_SMALL)));
    COUNTER->reset();
}

define('ENSURE_THROWN_DICE', ensure_predicate(needs_thrown_dice(...), fn() => '_Please load game using_ **b!loadgame** _or start a new game using_ **b!new**'));

$bot->registerCommand(
    'status',
    decorate_handler([ENSURE_THROWN_DICE], status(...)),
    ['description' => 'show current status of the game']
);

function status(Message $ctx): void
{
    $game_status = GAME_STATUS;
    $space_separated_letters = implode(' ', $game_status->letters->list);
    $found_words_output = found_words_output();
    $emoji_version_description = current_emoji_version()[0];
    $solutions_count = $game_status->solutions->count();
    $status_text = <<<END
        **Game #{$game_status->game_number}** (saved {$game_status->max_saved_game}) $space_separated_letters _($emoji_version_description)_
        $found_words_output
        **$solutions_count** approved words (end amount: **{$game_status->end_amount}**)
        Current language: {$game_status->current_lang}
        END;
    if ($game_status->current_lang !== $game_status->planned_lang) {
        $status_text .= ", next game: {$game_status->planned_lang}";
    }
    foreach (output_split_cursive($status_text) as $part) {
        await($ctx->channel->sendMessage($part));
    }
    await($ctx->channel->sendMessage(MessageBuilder::new()->addFile(IMAGE_FILEPATH_SMALL)));
    COUNTER->reset();
}

$bot->registerCommand(
    'unfound',
    decorate_handler(
        [ensure_predicate(GAME_STATUS->enoughWordsFound(...), fn() => 'You have to find ' . GAME_STATUS->end_amount . ' words first.'), needs_counting(...)],
        unfound(...)
    ),
    ['description' => 'send unfound Scrabble solutions']
);

function unfound(Message $ctx): void
{
    $unfound_file = 'live_data/unfound_solutions.txt';
    #$found_words_caps = array_map(mb_strtoupper(...), GAME_STATUS->found_words->toArray());
    file_put_contents($unfound_file, implode("\n", GAME_STATUS->solutions->diff(GAME_STATUS->found_words)->toArray()));
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

function left(Message $ctx): void
{
    $amount = GAME_STATUS->solutions->diff(GAME_STATUS->found_words)->count();
    #$hints_caps = array_map(mb_strtoupper(...), GAME_STATUS->available_hints);
    $unfound_hints_without_empty = array_filter(
        array_map(
            fn($hints_for_language) => array_filter($hints_for_language, fn($hint) => !GAME_STATUS->found_words->contains($hint)),
            GAME_STATUS->available_hints
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
    $solution_count = GAME_STATUS->solutions->count();
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

function shuffle2(Message $ctx): void
{
    GAME_STATUS->shuffleLetters();
    await($ctx->channel->sendMessage('**Letters shuffled.**'));
    await($ctx->channel->sendMessage(MessageBuilder::new()->addFile(IMAGE_FILEPATH_NORMAL)));
}


$bot->registerCommand(
    's',
    decorate_handler(
        [ensure_predicate(channel_valid(...)), ENSURE_THROWN_DICE, needs_counting(...)],
        'add_solution'
    ),
    ['description' => 'add solution'] # TODO alias to empty string?
);

function add_solution(Message $ctx, $args): void
{
    $word = $args[0];
    $success = GAME_STATUS->tryAddWord($ctx, $word);
    if ($success) {
        foreach (s_reactions($ctx, $word) as $reaction) {
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

function remove(Message $ctx, $args): void
{
    $word = $args[0];
    if (GAME_STATUS->found_words->contains($word)) {
        GAME_STATUS->removeWord($word);
        PlayerHandler::getInstance()->playerRemoveWord($ctx, GAME_STATUS->approvalStatus($word));
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

function highscore(Message $ctx): void
{
    await($ctx->channel->sendMessage(game_highscore(GAME_STATUS, PlayerHandler::getInstance())));
}

$bot->registerCommand(
    'add',
    decorate_handler(
        [ensure_predicate(from_native_speaker(...), fn() => 'Only native speakers can use this command.'), needs_counting(...)],
        'add'
    ),
    ['description' => 'add to community wordlist']
);

function add(Message $ctx, $args): void
{
    $word = $args[0];
    if (GAME_STATUS->tryAddCommunity($ctx, $word)) {
        await($ctx->react('📝'));
    }
}

$bot->registerCommand(
    'communitylist',
    community_list(...),
    ['description' => 'send current community list']
);

function community_list(Message $ctx): void
{
    $file_to_send = 'live_data/' . COMMUNITY_WORDLISTS[GAME_STATUS->current_lang];
    if (!is_file($file_to_send)) {
        touch($file_to_send);
    }
    await($ctx->channel->sendMessage(MessageBuilder::new()->addFile($file_to_send)));
}

$bot->registerCommand(
    'emoji',
    decorate_handler(
        [ensure_predicate(emoji_awarded(...), fn(Message $ctx) => 'You have to find ' . CONFIG->getWordCountForEmoji() . ' words first! (currently ' . PlayerHandler::getInstance()->getPlayerField($ctx->author->id, 'all_time_found') . ')')],
        'emoji'
    ),
    ['description' => 'change your personal emoji']
);

function emoji(Message $ctx, $args): void
{
    $emoji_str = $args[0];
    PlayerHandler::getInstance()->setEmoji($ctx->author->id, $emoji_str);
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
    return function (Message $ctx) use ($from_language): void {
        $unfound_hint_list = array_values(array_filter(GAME_STATUS->available_hints[$from_language], fn($hint) => !GAME_STATUS->found_words->contains($hint)));
        if (count($unfound_hint_list) === 0) {
            await($ctx->channel->sendMessage('No hints left.'));
            return;
        }
        $chosen_hint = $unfound_hint_list[array_rand($unfound_hint_list)];
        $formatted_hint_content = italic(get_translation($chosen_hint, new DictionaryType(GAME_STATUS->current_lang, $from_language), DatabaseHandler::getInstance())); # TODO https://github.com/2colours/Boggler-Bot-PHP/issues/26
        await($ctx->channel->sendMessage("hint: $formatted_hint_content"));
        PlayerHandler::getInstance()->playerUsedHint($ctx, $chosen_hint);
    };
}

$bot->registerCommand(
    'oldgames',
    old_games(...),
    ['description' => 'send saved games']
);

function old_games(Message $ctx): void
{
    await($ctx->channel->sendMessage(MessageBuilder::new()->addFile(SAVES_FILEPATH)));
}

$bot->registerCommand(
    'loadgame',
    decorate_handler(
        [ensure_predicate(channel_valid(...))],
        'load_game'
    ),
    ['description' => 'load older games (see: oldgames), example: b!loadgame 5']
);

function load_game(Message $ctx, $args): void
{
    $game_number = (int) $args[0];
    # Checks for changes before showing "found words" every time when just skipping through old games. That would be annoying.
    if (GAME_STATUS->changes_to_save) {
        $message = 'All words found in the last game: ' . found_words_output();
        if (!try_send_msg($ctx, $message)) {
            $found_words_count = GAME_STATUS->found_words->count();
            await($ctx->channel->sendMessage("**$found_words_count** words found in this game. **"));
        }
    }
    $ctx->channel->broadcastTyping(); # TODO better synchronization maybe?
    if (!GAME_STATUS->tryLoadOldGame($game_number)) {
        await($ctx->channel->sendMessage('The requested game doesn\'t exist.'));
        return;
    }
    # here current_lang, because this is loaded from saves.txt
    $game_number = GAME_STATUS->game_number;
    $current_lang = GAME_STATUS->current_lang;
    $found_words_output = found_words_output();
    $message = <<<END


    **Game #$game_number** ($current_lang)
    **Already found words:** $found_words_output
    END;
    foreach (output_split_cursive($message) as $part) {
        await($ctx->channel->sendMessage($part));
    }
    await($ctx->channel->sendMessage(MessageBuilder::new()->addFile(IMAGE_FILEPATH_NORMAL)));
    COUNTER->reset();
}

$bot->registerCommand(
    'random',
    decorate_handler(
        [ensure_predicate(channel_valid(...))],
        random(...)
    ),
    ['description' => 'load random old game']
);

function random(Message $ctx): void
{
    # random game between 1 and max_saved_game - after one calling, newest game is saved, too. Newest game can appear as second random call
    $number = random_int(1, GAME_STATUS->max_saved_game + 1);
    if (GAME_STATUS->thrown_the_dice) {
        await($ctx->channel->sendMessage('All words found in the last game: ' . found_words_output()));
    }
    GAME_STATUS->tryLoadOldGame($number);
    # here current_lang, because this is loaded from saves.txt
    $game_number = GAME_STATUS->game_number;
    $current_lang = GAME_STATUS->current_lang;
    $found_words_output = found_words_output();
    $message = <<<END


    **Game #$game_number** ($current_lang)
    **Already found words:** $found_words_output
    END;
    await($ctx->channel->sendMessage(MessageBuilder::new()->addFile(IMAGE_FILEPATH_NORMAL)));
    COUNTER->reset();
}

$bot->registerCommand(
    'reveal',
    decorate_handler(
        [ensure_predicate(channel_valid(...)), needs_counting(...)],
        reveal(...)
    ),
    ['description' => 'reveal letters of a previously requested hint']
);

function reveal(Message $ctx): void
{
    $player_hints = PlayerHandler::getInstance()->getPlayerField($ctx->author->id, 'used_hints');
    $left_hints = array_diff($player_hints, GAME_STATUS->found_words->toArray());
    if (count($left_hints) === 0) {
        await($ctx->channel->sendMessage('You have no unsolved hints left.'));
        return;
    }
    $chosen_word = $left_hints[array_rand($left_hints)];
    $word_length = grapheme_strlen($chosen_word);
    $revealed_indices = RNG->pickArrayKeys(range(0, $word_length - 1), intdiv($word_length, 3));
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

function longest(Message $ctx): void
{
    $formatted_longest_word_length = italic((string) GAME_STATUS->getLongestWordLength());
    await($ctx->channel->sendMessage("The largest possible word length is: $formatted_longest_word_length."));
}

# Blocks the code - has to be at the bottom
$bot->run();

function approval_reaction(string $word): string
{
    if (array_key_exists($word, CUSTOM_EMOJIS[GAME_STATUS->current_lang])) {
        $custom_reaction_list = CUSTOM_EMOJIS[GAME_STATUS->current_lang][$word];
        return $custom_reaction_list[array_rand($custom_reaction_list)];
    }
    $approval_status = GAME_STATUS->approvalStatus($word);
    return match (true) {
        (bool) @$approval_status->translations[GAME_STATUS->base_lang] => '☑️',
        $approval_status->wordlist => '✅',
        array_any(AVAILABLE_LANGUAGES, fn($lang) => @$approval_status->translations[$lang]) => '✅',
        $approval_status->community => '✔',
        default => '❔'
    };
}

# emojis are retrieved in a deterministic way: (current date, sorted letters, emoji list) determine the value
# special dates have a unique emoji list to be used
# in general, the letters are hashed modulo the length of the emoji list, to obtain the index in the emoji list
function current_emoji_version(): array
{
    $letter_list = GAME_STATUS->letters->list;
    GAME_STATUS->collator()->sort($letter_list);
    $hash = md5(implode(' ', $letter_list));
    $date = date('md');
    if (array_key_exists($date, PROGRESS_BAR_VERSION)) {
        $current_list = PROGRESS_BAR_VERSION[$date];
    } else {
        $current_list = PROGRESS_BAR_VERSION['default'];
    }
    return $current_list[gmp_intval(gmp_mod(gmp_init($hash, 16), count($current_list)))];
}

function found_words_output()
{
    $found_word_list = GAME_STATUS->foundWordsSorted();
    if (count($found_word_list) === 0) {
        return 'No words found yet 😭';
    }
    [$found_word_list_formatted, $found_word_list_length] = [format_found_words($found_word_list), count($found_word_list)];
    $progress_bar = progress_bar(GAME_STATUS);
    [$amount_approved_words, $end_amount] = [GAME_STATUS->getApprovedAmount(), GAME_STATUS->end_amount];
    return <<<END
        _$found_word_list_formatted ($found_word_list_length)_
        $progress_bar ($amount_approved_words/$end_amount)
        END;
}

function format_found_words($words): string
{
    return implode(
        ', ',
        array_map(fn($word) => GAME_STATUS->isFoundApproved($word) ? $word : strikethrough($word), $words)
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
