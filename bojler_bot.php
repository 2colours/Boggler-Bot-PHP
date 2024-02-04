<?php

declare(strict_types=1);

mb_internal_encoding('UTF-8');
mb_regex_encoding('UTF-8');

require_once __DIR__ . '/vendor/autoload.php';

use Discord\Builders\MessageBuilder;
use Symfony\Component\Dotenv\Dotenv;
use Discord\DiscordCommandClient;
use Discord\Parts\Channel\Message;
use Discord\WebSockets\Intents;
use function React\Async\await;

require_once __DIR__ . '/bojler_game_status.php'; # TODO GameStatus, EasterEggHandler with PSR-4 autoloader
require_once __DIR__ . '/bojler_player.php'; # TODO PlayerHandler with PSR-4 autoloader

$dotenv = new Dotenv();
$dotenv->load('./.env');

const CREATORS = ['297037173541175296', '217319536485990400'];
# TODO better dependency injection surely...
define('CONFIG', ConfigHandler::getInstance());
define('DISPLAY', CONFIG->get('display'));
define('DISPLAY_NORMAL', DISPLAY['normal']);
define('DISPLAY_SMALL', DISPLAY['small']);
define('IMAGE_FILEPATH_NORMAL', 'live_data/' . DISPLAY_NORMAL['image_filename']);
define('IMAGE_FILEPATH_SMALL', 'live_data/' . DISPLAY_SMALL['image_filename']);
define('SAVES_FILEPATH', 'live_data/' . CONFIG->get('saves_filename'));
define('CURRENT_GAME', 'live_data/' . CONFIG->get('current_game'));
define('EXAMPLES', CONFIG->get('examples'));
define('DICE_DICT', CONFIG->get('dice'));
define('WORDLISTS', CONFIG->get('wordlists'));
define('COMMUNITY_WORDLISTS', CONFIG->get('community_wordlists'));
define('DICTIONARIES', CONFIG->get('dictionaries'));
define('HOME_SERVER', (string) CONFIG->get('home_server'));
define('HOME_CHANNEL', (string) CONFIG->get('home_channel'));
define('PROGRESS_BAR_VERSION_DICT', CONFIG->get('progress_bar_version_dict'));
define('CUSTOM_EMOJIS', CONFIG->get('custom_emojis'));
define('EASTER_EGGS', CONFIG->get('easter_eggs'));
#useful stuff
define('AVAILABLE_LANGUAGES', array_keys(DICE_DICT));
# next is not necessary, used for testing purposes still
define('PROGRESS_BAR_VERSION_LIST',  PROGRESS_BAR_VERSION_DICT["default"]);
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
function instructions($lang)
{
    return str_replace(["{0}", "{1}"], [$lang, EXAMPLES[$lang]], INSTRUCTION_TEMPLATE);
}

class Counter
{
    private $current_value;
    public readonly int $threshold;

    public function __construct($threshold)
    {
        $this->current_value = 0;
        $this->threshold = $threshold;
    }

    public function reset()
    {
        $this->current_value = 0;
    }

    public function trigger()
    {
        $this->current_value++;
        if ($this->current_value === $this->threshold) {
            $this->current_value = 0;
            return true;
        }
        return false;
    }
}

function get_translation($text, DictionaryType $dictionary)
{
    $db = DatabaseHandler::getInstance(); # TODO better injection?
    foreach ($db->translate($text, $dictionary) as $translation) {
        if (isset($translation)) {
            return $translation;
        }
    }
    return null;
}

function translator_command($src_lang = null, $target_lang = null)
{
    return function (Message $ctx, $args) use ($src_lang, $target_lang) {
        $word = $args[0];
        $ctor_args = isset($src_lang) && isset($target_lang) ? [$src_lang, $target_lang] : DEFAULT_TRANSLATION;
        $translation = get_translation($word, new DictionaryType(...$ctor_args));
        if (isset($translation)) {
            await($ctx->channel->sendMessage("$word: ||$translation||"));
        } else {
            await($ctx->react('😶'));
        }
    };
}

# internal context-dependent functions that aren't really related to command handling

#Determines which emoji reaction a certain word deserves - it doesn't remove special characters
function achievements(Message $ctx, $word, $type)
{
    $reactions = [];
    if ($type === 's' && (GAME_STATUS->longest_solutions->contains($word))) {
        $reactions = ["🇳", "🇮", "🇨", "🇪"];
    }
    return $reactions;
}

function s_reactions(Message $ctx, $word)
{
    $reaction_list = [acknowledgement_reaction($word), approval_reaction($word)];
    return array_merge($reaction_list, achievements($ctx, $word, "s"));
}

# "predicate-ish" functions (not higher order, takes context, performs a check)
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
    #or: '_Please load game using_ **b!load** _or start a new game using_ **b!new**'
}

#Checks if current_game savefile is correctly formatted
# TODO may be unneeded in the new system
function savefile_valid()
{
    return GAME_STATUS->fileValid();
}

# TODO this definitely should be a method provided by GameStatus
function enough_found()
{
    return GAME_STATUS->found_words->count() >= GAME_STATUS->end_amount;
    #or: 'You have to find ' . GAME_STATUS->end_amount . ' words first.')
}

# "handler-ish" functions (not higher order, takes context, DC side effects)

function try_send_msg(Message $ctx, $content)
{
    $can_be_sent = grapheme_strlen($content) <= 2000; # TODO this magic constant should be moved from here and other places as well
    if ($can_be_sent) {
        await($ctx->channel->sendMessage($content));
    }
    return $can_be_sent;
}

# sends the small game board with the found words if they fit into one message
function simple_board(Message $ctx)
{
    $found_words_display = found_words_output();
    $message = "**Already found words:** $found_words_display";
    if (!(try_send_msg($ctx, $message))) {
        $ctx->channel->sendMessage('_Too many found words. Please use b!see._');
    }
    $ctx->channel->sendMessage(MessageBuilder::new()->addFile(IMAGE_FILEPATH_SMALL)); # TODO is this really binary-safe?
}

# "decorator-ish" stuff (produces something "handler-ish" or something "decorator-ish")
# TODO does one have to manually lift await or is it auto-detected in called functions?
function needs_counting($handler)
{
    return function ($ctx, ...$args) use ($handler) {
        $handler($ctx, ...$args);
        if (COUNTER->trigger()) {
            simple_board($ctx);
        }
    };
}

# $refusalMessageProducer is a function that can take $ctx
function ensure_predicate($predicate, $refusalMessageProducer = null)
{
    return fn ($handler) => function (Message $ctx, ...$args) use ($handler, $predicate, $refusalMessageProducer) {
        if ($predicate($ctx)) {
            $handler($ctx, ...$args);
        } elseif (isset($refusalMessageProducer)) {
            $ctx->reply($refusalMessageProducer($ctx));
        }
    };
}

# [d1, d2, d3, ..., dn], h -> d1 ∘ d2 ∘ d3 ∘ ... ∘ dn ∘ h
function decorate_handler(array $decorators, $handler)
{
    return array_reduce(array_reverse($decorators), fn ($aggregate, $current) => $current($aggregate), $handler);
}

# TODO it's dubious whether these are actually constants; gotta think about it
define('GAME_STATUS', new GameStatus(CURRENT_GAME, SAVES_FILEPATH));
# define('easter_egg_handler', new EasterEggHandler(GAME_STATUS->found_words_set));
define('COUNTER', new Counter(10));

$bot = new DiscordCommandClient([
    'prefix' => 'b!',
    'token' => $_ENV['DC_TOKEN'],
    'description' => 'Szórakodtató bot',
    'discordOptions' => [
        'intents' => Intents::getDefaultIntents()
        //      | Intents::MESSAGE_CONTENT, // Note: MESSAGE_CONTENT is privileged, see https://dis.gd/mcfaq
    ]
]);

# TODO more consistency about how the functions send the message? (not super important if we move to slash commands)
$bot->registerCommand('info', fn () => instructions(GAME_STATUS->current_lang), ['description' => 'show instructions']);
$bot->registerCommand('teh', translator_command('English', 'Hungarian'), ['description' => 'translate given word Eng-Hun']);
$bot->registerCommand('the', translator_command('Hungarian', 'English'), ['description' => 'translate given word Hun-Eng']);
$bot->registerCommand('thg', translator_command('Hungarian', 'German'), ['description' => 'translate given word Hun-Ger']);
$bot->registerCommand('tgh', translator_command('German', 'Hungarian'), ['description' => 'translate given word Ger-Hun']);
$bot->registerCommand('thh', translator_command('Hungarian', 'Hungarian'), ['description' => 'translate given word Hun-Hun']);
$bot->registerCommand('t', function (Message $ctx, $args) {
    $translator_args = channel_valid($ctx) ? [GAME_STATUS->current_lang, GAME_STATUS->base_lang] : [];
    translator_command(...$translator_args)($ctx, $args);
}, ['description' => 'translate given word']);
$bot->registerCommand('stats', function (Message $ctx) {
    $infos = PlayerHandler::getInstance()->player_dict[$ctx->author->id]; # TODO better injection
    if (is_null($infos)) {
        $ctx->reply('You don\'t have any statistics registered.');
        return;
    }
    $found_words = implode(', ', $infos['found_words']);
    $ctx->channel->sendMessage(<<<END
    **Player stats for $infos[server_name]:**
    *Total found words:* $infos[all_time_found]
    *Approved words in previous games:* $infos[all_time_approved]
    *Personal emoji:* $infos[personal_emoji]
    *Words found in current game:* $found_words
    END);
}, ['description' => 'send user stats']);

$bot->registerCommand(
    'trigger',
    decorate_handler([ensure_predicate(from_creator(...), fn () => 'This would be very silly now, wouldn\'t it.')], 'trigger'),
    ['description' => 'testing purposes only']
);
function trigger(Message $ctx, $args)
{
    $ctx->reply('Congrats, Master.');
}

$bot->registerCommand(
    'nextlang',
    decorate_handler([ensure_predicate(channel_valid(...))], 'next_language'),
    ['description' => 'change language']
);
function next_language(Message $ctx, $args)
{
    $lang = $args[0];
    if (is_null($lang) || !in_array($lang, AVAILABLE_LANGUAGES)) {
        $languages = implode(', ', AVAILABLE_LANGUAGES);
        $ctx->reply(<<<END
        Please provide an argument <language>.
        <language> should be one of the values [$languages].
        END);
        return;
    }
    GAME_STATUS->setLang($lang);
    $ctx->channel->sendMessage("Changed language to $lang for the next games.");
}

# Blocks the code - has to be at the bottom
$bot->run();

function highscore_names($ids)
{
    if (count($ids) === 0) {
        return " - ";
    }
    $names = [];
    $handler = PlayerHandler::getInstance();
    foreach ($ids as $id) {
        if (array_key_exists("server_name", $handler->player_dict[$id])) {
            array_push($handler->player_dict[$id]["server_name"], $names);
        } else {
            array_push($handler->player_dict[$id]["name"], $names);
        }
    }
    return implode(", ", $names);
}

function game_highscore()
{
    $awards = GAME_STATUS->gameAwards();
    [$on_podium_first, $on_podium_second, $on_podium_third] = [
        on_podium($awards["First place"]),
        on_podium($awards["Second place"]),
        on_podium($awards["Third place"]),
    ];
    [$highscore_names_first, $highscore_names_second, $highscore_names_third] = [
        highscore_names($awards["First place"]),
        highscore_names($awards["Second place"]),
        highscore_names($awards["Third place"]),
    ];
    $most_solved_hints = highscore_names($awards["Most solved hints"]);
    $best_beginner = highscore_names($awards["Best Beginner"]);
    $message = '';
    $message .= "⬛⬛⬛{$on_podium_first}⬛⬛⬛⬛***HIGHSCORE***\n";
    $message .= "{$on_podium_second}🟨🟨🟨⬛⬛⬛⬛**1.** $highscore_names_first\n";
    $message .= "🟨🟨🟨🟨🟨🟨{$on_podium_third}⬛**2.** $highscore_names_second\n";
    $message .= "🟨🟨🟨🟨🟨🟨🟨🟨🟨⬛**3.** $highscore_names_third\n";
    $message .= "\n";
    $message .= "*Most Solved Hints:* \t$most_solved_hints\n";
    $message .= "*Hard-Working Beginner:* \t$best_beginner\n";
    if (array_key_exists("Newcomer", $awards) && count($awards["Newcomer"]) !== 0) {
        $newcomer_highscore_names = highscore_names($awards["Newcomer"]);
        $message .= "*Newcomer of the day:* $newcomer_highscore_names";
    }
    return $message;
}

function on_podium($people)
{
    switch (count($people)) {
        case 0:
            return "⬛⬛⬛";
        case 1:
            $handler = PlayerHandler::getInstance();
            $personal_emoji = $handler->player_dict[$people[0]]["personal_emoji"];
            return "⬛{$personal_emoji}⬛";
        case 2:
            $handler = PlayerHandler::getInstance();
            [$personal_emoji_first, $personal_emoji_second] = [
                $handler->player_dict[$people[0]]["personal_emoji"],
                $handler->player_dict[$people[1]]["personal_emoji"],
            ];
            return "{$personal_emoji_first}⬛{$personal_emoji_second}";
        case 3:
            $handler = PlayerHandler::getInstance();
            [$personal_emoji_first, $personal_emoji_second, $personal_emoji_third] = [
                $handler->player_dict[$people[0]]["personal_emoji"],
                $handler->player_dict[$people[1]]["personal_emoji"],
                $handler->player_dict[$people[2]]["personal_emoji"],
            ];
            return "$personal_emoji_first$personal_emoji_second$personal_emoji_third";
        case 4:
            return "🧍🧑‍🤝‍🧑🧍";
        case 5:
            return "🧑‍🤝‍🧑🧍🧑‍🤝‍🧑";
        default:
            return "🧑‍🤝‍🧑🧑‍🤝‍🧑🧑‍🤝‍🧑";
    }
}

function approval_reaction($word)
{
    if (array_key_exists($word, CUSTOM_EMOJIS[GAME_STATUS->current_lang])) {
        $custom_reaction_list = CUSTOM_EMOJIS[GAME_STATUS->current_lang][$word];
        return $custom_reaction_list[array_rand($custom_reaction_list)];
    }
    $approval_status = GAME_STATUS->approvalStatus($word);
    if (array_key_exists("any", $approval_status) && count($approval_status["any"]) !== 0) {
        if (array_key_exists(GAME_STATUS->base_lang, $approval_status) && $approval_status[GAME_STATUS->base_lang]) {
            return "☑️";
        }
        if (array_key_exists("word_list", $approval_status) && $approval_status["word_list"]) {
            return "✅";
        }
        foreach (AVAILABLE_LANGUAGES as $language) {
            if (array_key_exists($language, $approval_status) && $approval_status[$language]) {
                return "✅";
            }
        }
        if (array_key_exists("community", $approval_status) && $approval_status["community"]) {
            return "✔";
        }
    }

    return "❔";
}

# emojis are retrieved in a deterministic way: (current date, sorted letters, emoji list) determine the value
# special dates have a unique emoji list to be used
# in general, the letters are hashed modulo the length of the emoji list, to obtain the index in the emoji list
function current_emoji_version()
{
    $letter_list = GAME_STATUS->letters->list;
    GAME_STATUS->collator()->sort($letter_list);
    $hash = md5(implode(' ', $letter_list));
    $date = date("md");
    if (array_key_exists($date, PROGRESS_BAR_VERSION_DICT)) {
        $current_list = PROGRESS_BAR_VERSION_DICT[$date];
    } else {
        $current_list = PROGRESS_BAR_VERSION_DICT["default"];
    }
    return $current_list[gmp_intval(gmp_mod(gmp_init($hash, 16), count($current_list)))];
}

function progress_bar($emoji_scale = null)
{
    if (!isset($emoji_scale)) {
        $emoji_scale = current_emoji_version()[1];
    }
    if (count($emoji_scale) < 2) {
        echo "Error in config. Not enough symbols for progress bar.";
        return '';
    }
    $progress_bar_length = ceil(GAME_STATUS->end_amount / 10);
    if (GAME_STATUS->amount_approved_words >= GAME_STATUS->end_amount) {
        return $progress_bar_length * $emoji_scale[array_key_last($emoji_scale)];
    }
    $full_emoji_number = floor(GAME_STATUS->amount_approved_words / 10);
    $progress_bar = $full_emoji_number * $emoji_scale[array_key_last($emoji_scale)];
    $rest = GAME_STATUS->end_amount - $full_emoji_number * 10;
    $current_step_size = $rest < 10 ? $rest : 10;
    $progress_in_current_step = floor((GAME_STATUS->amount_approved_word % 10) / $current_step_size);
    $empty_emoji_number = $progress_bar_length - $full_emoji_number - 1;
    $progress_bar .= $emoji_scale[floor($progress_in_current_step * (count($emoji_scale) - 1))];
    $progress_bar .= $empty_emoji_number * $emoji_scale[0];
    return $progress_bar;
}

function found_words_output()
{
    $found_word_list = GAME_STATUS->foundWordsSorted();
    if (count($found_word_list) === 0) {
        return "No words found yet 😭";
    }
    [$found_word_list_formatted, $found_word_list_length] = [implode(", ", $found_word_list), count($found_word_list)];
    $progress_bar = progress_bar();
    [$amount_approved_words, $end_amount] = [GAME_STATUS->amount_approved_words, GAME_STATUS->end_amount];
    return "_$found_word_list_formatted ($found_word_list_length)_\n$progress_bar ($amount_approved_words/$end_amount)";
}

function acknowledgement_reaction($word)
{
    $word = remove_special_char($word);
    $word_length = grapheme_strlen($word);
    if ($word_length >= 10) {
        return "💯";
    } elseif ($word_length === 9) {
        return "🤯";
    } elseif ($word_length > 5) {
        return "🎉";
    } else {
        return "👍";
    }
}

/*import math
from numpy import random as rnd
import random as rand
from json import load, dump
import discord
from discord.ext import commands
import os
import sys
import traceback
from hashlib import md5
from datetime import date
from asyncio import sleep
import inspect
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
        @bot.command(brief='add solution', aliases=['', 'S'])
        @commands.check(channel_valid)
        @commands.check(thrown_dice)
        async def s(ctx, word):
        word_info = game_status.approval_status(word)
        await easter_egg_trigger(ctx, word, "_Rev")
        if word_info["valid"]:
        if word in game_status.found_words_set:
        await ctx.send(word + " was already found.")
        else:
        await easter_egg_trigger(ctx, word)
        end_signal = game_status.add_word(word)
        for reaction in await s_reactions(ctx, word):
        await ctx.message.add_reaction(reaction)
        PlayerHandler.player_add_word(ctx, word_info)
        if end_signal:
        await ctx.send("**Congratulations! You won this game! You found " + str(int(game_status.end_amount)) + " words!** \n")
        await ctx.send(game_highscore())
        return
        else:
        await ctx.send(word + " doesn't fit the given letters.")
        if counter.trigger():
        await simple_board(ctx)

        @bot.command(brief='add to community wordlist')
        @needs_counting
        async def add(ctx, word):
        await achievements(ctx, word, "add")
        if game_status.try_add_community(word):
        await ctx.message.add_reaction("📝")
        else:
        await ctx.send("Word already in the community list")

        @bot.command(brief='send current community list')
        async def communitylist(ctx):
        file_to_send = f'live_data/{community_wordlists[game_status.current_lang]}'
        if not os.path.isfile(file_to_send):
        with open(file_to_send, 'w'):
        pass
        with open(file_to_send, "r") as f:
        await ctx.send(file=discord.File(f))

        @bot.command(brief='show current status of the game')
        @commands.check(thrown_dice)
        async def status(ctx):
        status_text = "**Game #" + str(game_status.game_number) + '** (saved ' + str(game_status.max_saved_game) + ') ' + ' '.join(game_status.letters.list) + " _(" + current_emoji_version()[0] + ")_\n" + found_words_output() + "\n**" + str(len(game_status.solutions)) + "** words in the Scrabble dictionary (end amount: **" + str(int(game_status.end_amount)) + "**)" + "\nCurrent language: " + game_status.current_lang
        if game_status.current_lang != game_status.planned_lang:
        status_text = status_text + ", next game: " + game_status.planned_lang
        for item in output_split_cursive(status_text):
        await ctx.send(item)
        with open(image_filepath_small, "rb") as f:
        await ctx.send(file=discord.File(f))
        counter.reset()

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


        @bot.command(brief='remove solution', aliases=['r'])
        @commands.check(channel_valid)
        async def remove(ctx, arg):
        word_info = game_status.approval_status(arg)
        if arg in game_status.found_words_set:
        game_status.remove_word(arg)
        PlayerHandler.player_remove_word(ctx, word_info)
        if counter.trigger():
        await simple_board(ctx)
        await ctx.send("Removed _" + arg + "_.")

        @bot.command(brief='show current game')
        @commands.check(channel_valid)
        async def see(ctx):
        message = "**Game #" + str(game_status.game_number) + ": Already found words:** " + found_words_output()
        for item in output_split_cursive(message):
        await ctx.send(item)
        with open(image_filepath_small, 'rb') as f:
        await ctx.send(file=discord.File(f))
        counter.reset()

        @bot.command(brief='start new game')
        @commands.check(channel_valid)
        async def new(ctx):
        # this isn't perfect, but at least it won't display this "found words" always when just having looked at an old game for a second. That would be annoying.
        if game_status.changes_to_save:
        await ctx.send(game_highscore())
        #message = "All words found in the last game: " + found_words_output() + "\n\n" + instructions(game_status.planned_lang) + "\n\n"
        #for item in output_split_cursive(message):
        # await ctx.send(item)
        else:
        await ctx.send(instructions(game_status.planned_lang) + "\n\n")
        # important: first save, then new (obviously)
        async with ctx.channel.typing():
        game_status.new_game()
        counter.reset()

        await ctx.send("Game #**" + str(game_status.game_number) + "** _(" + current_emoji_version()[0] + ")_:" + "\n**(" + str(len(game_status.solutions)) + "** possible approved words)")

        #with open(saves_filepath, 'a') as f:
        # f.write(str(game_status.game_number) + '. ' + "(" + current_lang + ")\n" + ' '.join(game_status.letters))
        # f.write("\n")

        with open(image_filepath_normal, 'rb') as f:
        await ctx.send(file=discord.File(f))
        counter.reset()
        # print all saves, should be removed sometime
        #with open(saves_filepath, 'r') as file:
        # for line in file:
        # print(line)

        @bot.command(brief='send saved games')
        async def oldgames(ctx):
        with open(saves_filepath, 'r') as f:
        await ctx.send(file=discord.File(f))

        @bot.command(brief='send unfound Scrabble solutions')
        @commands.check(enough_found)
        @needs_counting
        async def unfound(ctx):
        unfound_file = 'live_data/unfound_solutions.txt'
        #found_words_caps = list(map(str.upper, game_status.found_words_set))
        with open(unfound_file, 'w') as f:
        for item in game_status.solutions:
        if not item in game_status.found_words_set:
        f.write(item + "\n")
        with open(unfound_file, "r") as f:
        await ctx.send(file=discord.File(f))

        @bot.command(brief='amount of unfound Scrabble solutions')
        @needs_counting
        async def left(ctx):
        amount = 0
        for item in game_status.solutions:
        if not item in game_status.found_words_set:
        amount += 1
        #hints_caps = list(map(str.upper, game_status.available_hints))
        unfound_hints = dict()
        for item in game_status.available_hints:
        unfound_hints[item] = list(filter(lambda x: x not in game_status.found_words_set, game_status.available_hints[item]))
        language_hints = ''
        for item in unfound_hints:
        if unfound_hints[item]:
        language_hints += (str(len(unfound_hints[item])) + " " + item + ", ")
        if not language_hints:
        language_hints = "0.."
        await ctx.send("**" + str(amount) + "** approved words left (of " + str(len(game_status.solutions)) + ") - " + language_hints[:-2] + " hints left.")


        @bot.command(brief = 'give a hint')
        @commands.check(channel_valid)
        @needs_counting
        async def hint(ctx):
        #found_words_caps = list(map(str.upper, game_status.found_words_set))
        #hints_caps = list(map(str.upper, game_status.available_hints))
        unfound_hint_list = list(filter(lambda x: x not in game_status.found_words_set, game_status.available_hints["English"]))
        if len(unfound_hint_list) == 0:
        await ctx.send("No hints left.")
        else:
        number = rnd.randint(0, len(unfound_hint_list))
        entry = game_status.get_translation(unfound_hint_list[number], DictionaryType(game_status.current_lang, "English"))
        await ctx.send('hint: _' + entry + '_')
        PlayerHandler.player_used_hint(ctx, unfound_hint_list[number])

        @bot.command(brief = 'give a hint in German', aliases=['Hinweis'])
        @commands.check(channel_valid)
        @needs_counting
        async def hinweis(ctx):
        #found_words_caps = list(map(str.upper, game_status.found_words_set))
        #hints_caps = list(map(str.upper, game_status.available_hints["German"]))
        unfound_hint_list = list(filter(lambda x: x not in game_status.found_words_set, game_status.available_hints["German"]))
        if len(unfound_hint_list) == 0:
        await ctx.send("No hints left.")
        else:
        number = rnd.randint(0, len(unfound_hint_list))
        entry = game_status.get_translation(unfound_hint_list[number], DictionaryType(game_status.current_lang, "German"))
        await ctx.send('Hinweis: _' + entry + '_')
        PlayerHandler.player_used_hint(ctx, unfound_hint_list[number])


        @bot.command(brief = 'give a hint in Hungarian')
        @commands.check(channel_valid)
        @needs_counting
        async def súgás(ctx):
        unfound_hint_list = list(filter(lambda x: x not in game_status.found_words_set, game_status.available_hints["Hungarian"]))
        if len(unfound_hint_list) == 0:
        await ctx.send("No hints left.")
        else:
        number = rnd.randint(0, len(unfound_hint_list))
        entry = game_status.get_translation(unfound_hint_list[number], DictionaryType(game_status.current_lang, "Hungarian"))
        await ctx.send('Súgás: _' + entry + '_')
        PlayerHandler.player_used_hint(ctx, unfound_hint_list[number])

        @bot.command(brief = 'reveal letters of a previously requested hint')
        @commands.check(channel_valid)
        @needs_counting
        async def reveal(ctx):
        players_hints = PlayerHandler.get_player_field(ctx.author.id, "used_hints")
        left_hints = []
        for word in players_hints:
        if not word in game_status.found_words_set:
        left_hints.append(word)
        if not left_hints:
        await ctx.send("You have no unsolved hints left.")
        else:
        word = left_hints[rnd.randint(0, len(left_hints))]
        poslist = rand.sample(range(len(word)), int(len(word)/3))
        message = ""
        for i in range(len(word)):
        if i in poslist:
        message += word[i]
        else:
        message += "◍"
        await ctx.send("Hint: _" + message + "_")

        @bot.command(brief='load older games (see: oldgames), example: b!loadgame 5')
        @commands.check(channel_valid)
        async def loadgame(ctx, arg):
        # Checks for changes before showing "found words" every time when just skipping through old games. That would be annoying.
        if game_status.changes_to_save:
        message = "All words found in the last game: " + found_words_output()
        if not (await ctx.try_send(message)):
        await ctx.send("Congratulations! You won this game! 🎉 You found **" + str(len(game_status.found_words_set)) + "** words.\n")
        async with ctx.channel.typing():
        if game_status.try_load_oldgame(int(arg)):
        # here current_lang, because this is loaded from saves.txt
        message = "\n\n**Game #" + str(game_status.game_number) + "** (" + game_status.current_lang + ")\n" + "**Already found words:** " + found_words_output()
        for item in output_split_cursive(message):
        await ctx.send(item)

        with open(image_filepath_normal, 'rb') as f:
        await ctx.send(file=discord.File(f))
        counter.reset()
        else:
        await ctx.send("The requested game doesn't exist.")

        @bot.command(brief='load random old game')
        @commands.check(channel_valid)
        async def random(ctx):
        # random game between 1 and max_saved_game - after one calling, newest game is saved, too. Newest game can appear as second random call
        number = rnd.randint(1, game_status.max_saved_game+1)

        if game_status.thrown_the_dice:
        await ctx.send("All words found in the last game: " + found_words_output())


        game_status.try_load_oldgame(number)

        # here current_lang, because this is loaded from saves.txt
        await ctx.send("\n\n**Game #" + str(game_status.game_number) + "** (" + game_status.current_lang + ")\n" + "**Already found words:** " + found_words_output())

        with open(image_filepath_normal, 'rb') as f:
        await ctx.send(file=discord.File(f))
        counter.reset()

        @bot.command(brief='shuffle the position of dice')
        @commands.check(channel_valid)
        async def shuffle(ctx):
        game_status.shuffle_letters()
        await ctx.send('**Letters shuffled.**')
        with open(image_filepath_normal, 'rb') as f:
        await ctx.send(file=discord.File(f))

        @bot.command(brief='change your personal emoji')
        async def emoji(ctx, arg):
        if PlayerHandler.get_player_field(ctx.author.id, "all_time_found")>= 100:
        PlayerHandler.set_emoji(ctx.author.id, arg)
        await ctx.send("Changed emoji to " + arg)
        else:
        await ctx.send("You have to find 100 words first! (currently " + str(PlayerHandler.get_player_field(ctx.author.id, "all_time_found")) + ")")

        @bot.command(brief = 'send highscore')
        async def highscore(ctx):
        await ctx.send(game_highscore())

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