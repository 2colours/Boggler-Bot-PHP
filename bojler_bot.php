<?php

require_once __DIR__ . '/vendor/autoload.php';

use Symfony\Component\Dotenv\Dotenv;
use Discord\DiscordCommandClient;
use Discord\Parts\Channel\Message;
use Discord\WebSockets\Intents;
use function React\Async\await;

require_once __DIR__ . '/bojler_game_status.php'; # TODO GameStatus, EasterEggHandler with PSR-4 autoloader
require_once __DIR__ . '/bojler_player.php'; # TODO PlayerHandler with PSR-4 autoloader

$dotenv = new Dotenv();
$dotenv->load('./.env');


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
define('HOME_SERVER', CONFIG->get('home_server'));
define('HOME_CHANNEL', CONFIG->get('home_channel'));
define('PROGRESS_BAR_VERSION_DICT', CONFIG->get('progress_bar_version_dict'));
define('CUSTOM_EMOJIS', CONFIG->get('custom_emojis'));
define('EASTER_EGGS', CONFIG->get('easter_eggs'));
#useful stuff
define('AVAILABLE_DICTIONARIES', array_keys(DICTIONARIES)); # TODO is this still needed?
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
        if ($this->current_value == $this->threshold) {
            $this->current_value = 0;
            return true;
        }
        return false;
    }
}

function get_translation($text, DictionaryType $dictionary)
{
    $db = DatabaseHandler::getInstance(); # TODO better injection?
    foreach ($db->translate($text, $dictionary) as $translation)
        if (isset($translation))
            return $translation;
    return null;
}

function translator_command($src_lang = null, $target_lang = null)
{
    return function (Message $ctx, $args) use ($src_lang, $target_lang) {
        $word = $args[0];
        $ctor_args = isset($src_lang) && isset($target_lang) ? [$src_lang, $target_lang] : DEFAULT_TRANSLATION;
        $translation = get_translation($word, new DictionaryType(...$ctor_args));
        if (isset($translation))
            await($ctx->channel->sendMessage("$word: ||$translation||"));
        else
            await($ctx->react('😶'));
    };
}

function try_send_msg(Message $ctx, $content)
{
    $can_be_sent = mb_strlen($content) <= 2000; # TODO this magic constant should be moved from here and other places as well
    if ($can_be_sent)
        await($ctx->channel->sendMessage($content));
    return $can_be_sent;
}

function channel_valid(Message $ctx)
{
    return $ctx->guild?->id == HOME_SERVER && $ctx->channel?->id == HOME_CHANNEL;
}

# TODO it's dubious whether these are actually constants; gotta think about it
# TODO constructor not ported yet
# define('GAME_STATUS', new GameStatus(CURRENT_GAME, SAVES_FILEPATH));
# define('easter_egg_handler', new EasterEggHandler(GAME_STATUS->found_words_set));
define('counter', new Counter(10));

# bot=commands.Bot(command_prefix=('b!','B!'), owner_ids=[745671966266228838, 297037173541175296], help_command=commands.DefaultHelpCommand(verify_checks=False), intents=discord.Intents.all())
$bot = new DiscordCommandClient([
    'prefix' => 'b!',
    'token' => $_ENV['DC_TOKEN'],
    'discordOptions' => [
        'intents' => Intents::getDefaultIntents()
        //      | Intents::MESSAGE_CONTENT, // Note: MESSAGE_CONTENT is privileged, see https://dis.gd/mcfaq
    ]
]);

# TODO instructions(GAME_STATUS->current_lang)
# TODO more consistency about how the functions send the message? (not super important if we move to slash commands)
$bot->registerCommand('info', fn () => instructions('Hungarian'), ['description' => 'show instructions']);
$bot->registerCommand('teh', translator_command('English', 'Hungarian'), ['description' => 'translate given word Eng-Hun']);
$bot->registerCommand('thg', translator_command('Hungarian', 'German'), ['description' => 'translate given word Hun-Ger']);
$bot->registerCommand('tgh', translator_command('German', 'Hungarian'), ['description' => 'translate given word Ger-Hun']);
$bot->registerCommand('thh', translator_command('Hungarian', 'Hungarian'), ['description' => 'translate given word Hun-Hun']);
$bot->registerCommand('t', function (Message $ctx, $args) {
    $translator_args = channel_valid($ctx) ? [/*GAME_STATUS->current_lang, GAME_STATUS->base_lang*/] : []; # TODO uncomment when GameStatus is ready to construct
    translator_command(...$translator_args)($ctx, $args);
}, ['description' => 'translate given word']);
$bot->registerCommand('stats', function (Message $ctx, $args) {
    $infos = PlayerHandler::getInstance()->player_dict[$ctx->author->id]; # TODO better injection
    $found_words = implode(', ', $infos['found_words']);
    return <<<END
    **Player stats for $infos[server_name]:**
    *Total found words:* $infos[all_time_found]
    *Approved words in previous games:* $infos[all_time_approved]
    *Personal emoji:* $infos[personal_emoji]
    *Words found in current game:* $found_words
    END;
}, ['description' => 'send user stats']);

# Blocks the code - has to be at the bottom
$bot->run();

# Discord agnostic bits
/*
def highscore_names(ids):
    if not ids:
        return " - "
    names = []
    for item in ids:
        if PlayerHandler.player_dict[item]["server_name"]:
            names.append(PlayerHandler.player_dict[item]["server_name"]) # + " (" + str(len(PlayerHandler.player_dict[item]["found_words"])) + ")")
        else:
            names.append(PlayerHandler.player_dict[item]["name"])
    return ", ".join(names)

def game_highscore():
    awards = game_status.game_awards()
    message = ''
    message += "⬛⬛⬛" + on_podium(awards["First place"]) + "⬛⬛⬛" + "⬛***HIGHSCORE***" + "\n"
    message += on_podium(awards["Second place"]) + "🟨🟨🟨" + "⬛⬛⬛" + "⬛**1.** " + highscore_names(awards["First place"]) + "\n"
    message += "🟨🟨🟨" + "🟨🟨🟨" + on_podium(awards["Third place"]) + "⬛**2.** " + highscore_names(awards["Second place"]) + "\n"
    message += "🟨🟨🟨" + "🟨🟨🟨" + "🟨🟨🟨" + "⬛**3.** " + highscore_names(awards["Third place"]) + "\n"
    message += "\n"
    message += "*Most Solved Hints:* " + "\t" + highscore_names(awards["Most solved hints"]) + "\n"
    message += "*Hard-Working Beginner:* " + "\t" + highscore_names(awards["Best Beginner"]) + "\n"
    if awards["Newcomer"]:
        message += "*Newcomer of the day:* " + highscore_names(awards["Newcomer"])
    return message

def on_podium(people):
    if len(people)==1:
        return "⬛" + PlayerHandler.player_dict[people[0]]["personal_emoji"] + "⬛"
    if len(people)==2:
        return PlayerHandler.player_dict[people[0]]["personal_emoji"] + "⬛" + PlayerHandler.player_dict[people[1]]["personal_emoji"]
    if len(people)==3:
        return PlayerHandler.player_dict[people[0]]["personal_emoji"] + PlayerHandler.player_dict[people[1]]["personal_emoji"] + PlayerHandler.player_dict[people[2]]["personal_emoji"]
    if len(people)==4:
        return "🧍" + "🧑‍🤝‍🧑" + "🧍"
    if len(people)==5:
        return "🧑‍🤝‍🧑🧍🧑‍🤝‍🧑"
    if len(people)>= 6:
        return "🧑‍🤝‍🧑🧑‍🤝‍🧑🧑‍🤝‍🧑"
    return "⬛⬛⬛"

def approval_reaction(word):
    if word in custom_emojis[game_status.current_lang]:
        custom_reaction_list = custom_emojis[game_status.current_lang][word]
        return custom_reaction_list[rnd.randint(0,len(custom_reaction_list))]
    approval_status = game_status.approval_status(word)
    if approval_status["any"]:
        if approval_status[game_status.base_lang]:
            return '☑️'
        if approval_status["wordlist"]:
            return '✅'
        for item in available_languages:
            if approval_status[item]:
                return '✅'
        if approval_status["community"]:
            return '✔'
    else:
        return '❔'

# emojis are retrieved in a deterministic way: (current date, sorted letters, emoji list) determine the value
# special dates have a unique emoji list to be used
# in general, the letters are hashed modulo the length of the emoji list, to obtain the index in the emoji list
def current_emoji_version():
    hash=int(md5(bytes(' '.join(sorted(game_status.letters.list, key=game_status._collator().getSortKey)), ' utf-8')).hexdigest(), base=16)
    if date.today().strftime("%m%d") in progress_bar_version_dict:
        current_list=progress_bar_version_dict[date.today().strftime("%m%d")]
    else:
        current_list=progress_bar_version_dict["default"]
    return current_list[hash % len(current_list)]

def progress_bar(emoji_scale = None):
    if not emoji_scale:
        emoji_scale = current_emoji_version()[1]
    if len(emoji_scale)<2:
        print("Error in config. Not enough symbols for progress bar.")
        return ''
    progress_bar_length = math.ceil(game_status.end_amount/10)
    if game_status.amount_approved_words >= game_status.end_amount:
        return progress_bar_length*emoji_scale[-1]
    full_emoji_number = game_status.amount_approved_words //10
    progressbar = full_emoji_number*emoji_scale[-1]
    rest = game_status.end_amount - full_emoji_number*10
    current_step_size = rest if rest < 10 else 10
    progress_in_current_step = (game_status.amount_approved_words % 10)/current_step_size
    progressbar += emoji_scale[math.floor(progress_in_current_step*(len(emoji_scale)-1))]
    empty_emoji_number = progress_bar_length - full_emoji_number - 1
    progressbar += empty_emoji_number*emoji_scale[0]
    return progressbar

def found_words_output():
    fw_list = game_status.found_words_sorted()
    if not fw_list:
        return "No words found yet 😭"
    return "_" + ', '.join(fw_list) +  " (" + str(len(fw_list)) + ")_\n" + progress_bar() + " (" + str(game_status.amount_approved_words) + "/" + str(int(game_status.end_amount)) + ")"

def acknowledgement_reaction(word):
    word=remove_special_char(word)
    return '💯' if len(word)> 9 else '🤯' if len(word) > 8 else '🎉' if len(word) > 5 else '👍'

async def savefile_valid(ctx): # TODO may be unneeded in the new system
    return game_status.file_valid()

*/


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



#for player in player_dict:
#player_dict[player]["all_time_found"] = len(player_dict[player]["found_words"])

#adventure=Adventure(game_status.letters.list, game_status.solutions)
adventure=Adventure(game_status.letters.list, set(custom_emojis[game_status.current_lang].keys()))
def needs_counting(func): async def action(ctx, *args, **kwargs): await func(ctx, *args, **kwargs) if counter.trigger(): await simple_board(ctx) action.__name__=func.__name__ sig=inspect.signature(func) action.__signature__=sig.replace(parameters=tuple(sig.parameters.values())) return action

    # sends the small game board with the found words if they fit into one message async def simple_board(ctx): message="**Already found words:** " + found_words_output() if not (await ctx.try_send(message)): await ctx.send("_Too many found words. Please use b!see._") with open(image_filepath_small, 'rb' ) as f: await ctx.send(file=discord.File(f))  #Checks if dice are thrown, thrown_the_dice exists just for this async def thrown_dice(ctx): if not game_status.thrown_the_dice: await ctx.send("_Please load game using_ **b!load** _or start a new game using_ **b!new**") return game_status.thrown_the_dice #Checks if current_game savefile is correctly formatted  #Checks if the current message is in the tracked channel async def bojler(ctx): messages=easter_eggs["bojler"] times=[0,1.5,1.5,1.5,1.5,0.3,0.3] message=await ctx.send(messages[0]) for i in range(1,len(messages)): await sleep(times[i]) await message.edit(content=messages[i]) await sleep(0.3) await message.delete() async def quick_walk(ctx, arg): messages=easter_eggs[arg] message=await ctx.send(messages[0]) for i in range(1,len(messages)): await sleep(0.3) await message.edit(content=messages[i]) await sleep(0.3) await message.delete() async def easter_egg_trigger(ctx, word, add='' ): # handle_easter_eggs decides which one to trigger, this here triggers it then type=easter_egg_handler.handle_easter_eggs(word, add) if not type: return print("Easter Egg") # to tell us when this might be responsible for anything if type=="nyan" : await quick_walk(ctx, "nyan" ) elif type=="bojler" : await bojler(ctx) elif type=="tongue" : message=await ctx.send("😝") await sleep(0.3) await message.delete() elif type=="varázsló" : await quick_walk(ctx, "varázsló" ) elif type=="huszár" : message=await ctx.send(easter_eggs["nagyhuszár"][0]) await sleep(2) await message.delete() #Determines which emoji reaction a certain word deserves - it doesn't remove special characters

      async def achievements(ctx, word, type):
      reactions = []
      if type == "s":
      if word in game_status.longest_solutions:
      reactions = ["🇳","🇮", "🇨","🇪"]
      elif type == "add" and ctx.author.id == 185430750667997184:
      game_status.rev_counter += 1
      if game_status.rev_counter % 20 == 0:
      await ctx.send("https://tenor.com/view/nick-wilde-zootopia-fox-disney-smug-gif-5225055")
      return reactions

      async def s_reactions(ctx, word):
        reaction_list = [acknowledgement_reaction(word), approval_reaction(word)]
        # zsemle special
        if (ctx.author.id == 400894576577085460) and (reaction_list[1]=='❔'):
            reaction_list.append("<:blobpeep:393319772076376066>")
        reaction_list += await achievements(ctx, word, "s")
        return reaction_list

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

        @bot.command(brief='change language')
        @commands.check(channel_valid)
        async def german(ctx):
        game_status.set_lang("German")
        await ctx.send("Changed language to German for the next games.")

        @bot.command(brief='change language')
        @commands.check(channel_valid)
        async def hungarian(ctx):
        game_status.set_lang("Hungarian")
        await ctx.send("Changed language to Hungarian for the next games.")

        @bot.command(brief='change language')
        @commands.check(channel_valid)
        async def english(ctx):
        game_status.set_lang("English")
        await ctx.send("Changed language to English for the next games.")

        @bot.command(brief='send saved games')
        async def oldgames(ctx):
        with open(saves_filepath, 'r') as f:
        await ctx.send(file=discord.File(f))

        async def enough_found(ctx):
        res = len(game_status.found_words_set) >= game_status.end_amount
        if not res:
        await ctx.send("You have to find " + str(int(game_status.end_amount)) + " words first.")
        return res

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

        @bot.command(hidden=True,brief='testing purposes only')
        @commands.is_owner()
        async def trigger(ctx, arg = ''):
        #custom_reaction_list = custom_emojis[game_status.current_lang][arg]
        #custom_reaction = custom_reaction_list[rnd.randint(0,len(custom_reaction_list))]
        #await ctx.message.add_reaction(custom_reaction)
        #await ctx.send(progress_bar_version_list[int(arg)][0] + ":\n" + progress_bar(progress_bar_version_list[int(arg)][1]))
        #game_status.load_game_new()
        #game_status.load_game()
        #game_status.save_game_test()
        #await quick_walk(ctx, "varázsló")
        #await ctx.send(game_highscore())
        import icu
        word = icu.UnicodeString(arg)
        bri = icu.BreakIterator.createCharacterInstance(icu.Locale('hu_HU'))
        bri.setText(word)
        points = [0, *bri]
        parts = [str(word[points[idx]:points[idx+1]]) for idx in range(len(points)-1)]
        await ctx.send(' | '.join(parts))
        # parts now contains the recognized characters that you would see at pasting
        pass


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