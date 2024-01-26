<?php

include __DIR__.'/vendor/autoload.php';

use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Typography\Font;
use Ds\Set;

require __DIR__ .'/bojler_config.php'; # TODO ConfigHandler with PSR-4 autoloader
require __DIR__ .'/bojler_db.php'; # TODO DatabaseHandler, DictionaryType with PSR-4 autoloader
require __DIR__ .'/bojler_util.php'; # TODO remove_special_char with PSR-4 autoloader
require __DIR__ .'/bojler_player.php'; # TODO PlayerHandler with PSR-4 autoloader

# TODO better dependency injection surely...
define('CONFIG', ConfigHandler::getInstance());
define('DISPLAY', CONFIG->get('display'));
define('DISPLAY_NORMAL', DISPLAY['normal']);
define('DISPLAY_SMALL', DISPLAY['small']);
define('DEFAULT_TRANSLATION', CONFIG->get('default_translation'));
define('DICE_DICT', CONFIG->get('dice'));
define('AVAILABLE_LANGUAGES', array_keys(DICE_DICT));
define('DEFAULT_END_AMOUNT', CONFIG->get('default_end_amount'));
define('WORDLIST_PATHS', array_map(fn($value) => "param/$value", CONFIG->get('wordlists')));
define('DICTIONARIES', CONFIG->get('dictionaries'));
define('AVAILABLE_DICTIONARIES', array_keys(DICTIONARIES)); # TODO is this still needed?
define('COMMUNITY_WORDLIST_PATHS', array_map(fn($value) => "live_data/$value", CONFIG->get('community_wordlists')));
define('CUSTOM_EMOJIS', CONFIG->get('custom_emojis'));
define('EASTER_EGGS', CONFIG->get('easter_eggs'));


class LetterList
{
    public const SIZE = 16;

    public readonly mixed $list;
    public readonly array $lower_cntdict;

    public function __construct($data, $preshuffle=false, $just_regenerate=false)
    {
        $this->list = $data;
        $this->lower_cntdict = array_count_values(array_map(mb_strtolower(...), $data)); # TODO there was some None filtering - watch out with the input
        if ($preshuffle)
            $this->shuffle();
        elseif ($just_regenerate) {
            $this->drawImageMatrix(...DISPLAY_NORMAL);
            $this->drawImageMatrix(...DISPLAY_SMALL);
        }
    }

    public function shuffle()
    {
        shuffle($this->list);
        $this->drawImageMatrix(...DISPLAY_NORMAL);
        $this->drawImageMatrix(...DISPLAY_SMALL);
    }

    private function drawImageMatrix($space_top, $space_left, $distance_vertical, $distance_horizontal, $font_size, $image_filename, $img_h, $img_w)
    {
        $manager = new ImageManager(Driver::class);;
        $image = $manager->create($img_w, $img_h);
        $font = new Font('param/arial.ttf');
        $font->setSize($font_size);
        $font->setColor('rgb(0, 178, 238)');

        foreach ($this->list as $i => $item)
            $image->text(
                $item,
                $space_left + $distance_horizontal * ($i % 4),
                $space_top + $distance_vertical * intdiv($i, 4),
                $font);

        $image->save("live_data/$image_filename");
    }

    public function isAbnormal()
    {
        return count($this->list) != self::SIZE;
    }
}

class GameStatus
{
    # TODO refined permissions (including readonly)
    private $file;
    public LetterList $letters;
    public Set $found_words;
    public $game_number;
    public $current_lang;
    public $base_lang;
    public $planned_lang;
    public $max_saved_game;
    private $changes_to_save;
    public Set $longest_solutions;
    public Set $solutions;
    public Set $wordlist_solutions;
    public $available_hints;
	public $amount_approved_words;

    public function fileValid()
    {
        #return true;
        $result = false;
        # Does the minimum check if the current_game file is right: should be 3 lines and the first 32 characters
        $f = fopen($this->file, 'r'); # TODO cleaner resource management (try-finally at the very least instead of goto)
        $first_line = fgets($f);
        # Current check: 16 letter + 15 spaces + 1 line break = 32
        if (mb_strlen($first_line) != 32) {
            echo 'Wrong amount of letters saved in current_game.';
            goto cleanup;
        }
        # Checks if the right amount of letters is saved
        if (count(explode(' ', $first_line)) != 16)
            echo 'File might be damaged.';
        for ($i = 0; fgets($f) !== false; $i++); #echo "Current counted line: $i"
        # file has 3 lines
        if ($i != 2)
            goto cleanup;
        $result = true;
        cleanup:
        fclose($f);
        return $result;
    }

    public function loadGame()
    {
        $content = file($this->file, FILE_IGNORE_NEW_LINES);
        if (count($content) < 9) {
            echo 'Save file wrong.';
            return;
        }
        # Current Game
        $this->letters = new LetterList(explode(' ', $content[1]));
        $this->found_words = new Set(explode(' ', $content[2]));
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
        $this->thrown_the_dice = True;
        # easter egg stuff
        #$this->_easter_egg_conditions();
        # achievement stuff
        $this->getLongestWords();
    }

    private function getLongestWords()
    {
        $length = max(array_map(fn($item) => mb_strlen(remove_special_char($item)), $this->solutions->toArray()));
        $this->longest_solutions = $this->solutions->filter(fn($item) => mb_strlen(remove_special_char($item)) == $length);
        echo 'Longest solution: ' . $length . ' letters';
        #var_dump($this->longest_solutions);
    }

    private function findSolutions()
    {
        $refdict = $this->letters->lower_cntdict;
        # wordlist
        $this->findWordlistSolutions($refdict);
        $this->solutions = $this->wordlist_solutions;
        # dictionaries (hints)
        $this->findHints();
        $this->solutions->add($this->available_hints);
        /*
        # communitylist
        self._load_communitylist()
        self.solutions.update(item for item in self.communitylist if self._word_valid_fast(item, refdict))
        # custom emojis
        self._find_custom_emojis(refdict)
        self.solutions.update(self.custom_emoji_solution)
        print("Custom reactions: " + str(len(self.custom_emoji_solution)))   */
    }

    private function findWordlistSolutions($refdict)
    {
        $content = file(WORDLIST_PATHS[$this->current_lang], FILE_IGNORE_NEW_LINES);
        $this->wordlist_solutions = new Set(array_filter($content, fn($item) => $this->wordValidFast($item, $refdict)));
    }

    private function findHints()
    {
        $db = DatabaseHandler::getInstance();
        $refdict = $this->letters->lower_cntdict;
        foreach ($this->availableDictionariesFrom($this->current_lang) as $language) {
            $this->available_hints[$language] = array_filter($db->getWords(new DictionaryType($this->current_lang, $language)), fn($item) => $this->wordValidFast($item, $refdict));
        }
    }

    # for a given language gives back which languages you can translate it to
    public function availableDictionariesFrom($origin = null)
    {
        $origin ??= $this->current_lang;
        return array_filter(AVAILABLE_LANGUAGES, fn($item) => array_key_exists((new DictionaryType($origin, $item))->asDictstring(), DICTIONARIES));
    }

    # gets the refdict instead of creating it every time
    public function wordValidFast($word, $refdict) # TODO why is there a $refdict passed and $this->letters->lower_cntdict also used??
    {
		# Pre-processing word for validity check
		$word = mb_ereg_replace("/[.'-]/", '', $word);
		if ($this->current_lang == "German")
			$word = $this->germanLetters($word);
		$word = mb_strtolower($word);

		$word_letters = mb_str_split($word);
		foreach ($word_letters as $letter)
			if (!array_key_exists($letter, $this->letters->lower_cntdict))
				return false;
		$wdict = array_count_values($word_letters);

		foreach ($word_letters as $letter)
			if ($wdict[$letter] > $refdict[$letter])
				return false;
		return true;
    }

	public function germanLetters($word)
	{
		$german_letters = [
			"ä" => "ae",
			"ö" => "oe",
			"ü" => "ue",
			"Ä" => "AE",
			"Ö" => "OE",
			"Ü" => "UE",
			"ß" => "ss"
		];
		return str_replace(array_keys($german_letters), array_values($german_letters), $word);
	}

	private function setEndAmount()
	{
		if (!empty($this->solutions))
			$this->end_amount = 100;
		else
			$this->end_amount = min(DEFAULT_END_AMOUNT, intdiv($this->solutions->count() * 2, 3));
	}

	private function countApprovedWords()
	{
		$amount = $this->solutions->diff($this->found_words)->count();
		$this->amount_approved_words = $this->solutions->count() - $amount;
	}

    private function collator()
    {
        return new Collator(CONFIG->get('locale')[$this->current_lang]);
    }
}

/*
from numpy import random as rnd
import icu

  def try_load_oldgame(self, number):
    self._save_old()
    if number not in range(1,self.max_saved_game + 1):
      return False
    PlayerHandler.new_game()
    with open(self.archive_file, 'r') as f:
      content = f.readlines()
    linenumber = 3*(number-1)
    languages = content[linenumber].strip()
    letters = content[linenumber+1].strip()
    words = content[linenumber+2].strip()
    self.letters = LetterList(letters.split(' '), True)
    if self.letters.is_abnormal():
      print("Game might be damaged.")
    self.found_words_set.clear()
    # safe already found words
    if words:
      wordlist = words.split(' ')
      for item in wordlist:
        self.found_words_set.add(item)
    # set language and number
    if languages:
      languages_list = languages.split(' ')
      read_number = languages_list[0]
      read_number = read_number[:-1]
      read_lang = languages_list[1]
      read_lang = read_lang[1:-1]
      self.current_lang = read_lang
      self.game_number = int(read_number)
      # this game doesn't have to be saved again in saves.txt yet (changes_to_save), we have a loaded game (thrown_the_dice)
    self._game_setup()
    self.changes_to_save = False
    self.save_game()
    return True

  def check_newest_game(self):
    # answer to: should we load the newest game instead of creating a new one?
    if self.game_number == self.max_saved_game:
      return False
    with open(self.archive_file, 'r') as f:
      content = f.readlines()
    last_found_words = content[len(content)-1].split(' ')
    language = content[len(content)-3].split(' ')[1][1:-2]
    print(language)
    if language != self.current_lang:
      return False
    if len(last_found_words) <= 10:
      return True
    return False

  def __init__(self, file, archive_file):
    self.archive_file = archive_file
    self.file = file
    self.letters = LetterList([None]*16)
    self.found_words_set = set()
    # complete lists
    self.communitylist = []
    # solution lists
    self.wordlist_solutions = []
    #dependent data
    self.communitylist_solutions = []
    self.available_hints = {lang:[] for lang in available_languages}
    self.custom_emoji_solutions = []
    #dependent data
    self.solutions = set()
    self.end_amount = default_end_amount
    # dependent data
    self.amount_approved_words = 0
    # changes_to_save determines if we have to save sth in saves.txt. Always true for a loaded current_game or new games (might not be saved yet). False when loading old games. Becomes true with every adding or removing word.
    self.changes_to_save = False
    # just false when no game is loaded or created yet
    self.thrown_the_dice = False
    self.planned_lang = default_translation[0]
    self.current_lang = default_translation[0]
    self.base_lang = default_translation[1]
    self.game_number = 0
    #dependent data
    self.max_saved_game = 0

    # achievement stuff
    self.longest_solutions = []
    self.rev_counter = 0
    
    self.load_game()

  def get_translation(self, arg, dt = DictionaryType(*default_translation)):
    entry = DatabaseHandler.translate(arg, dt)
    for word, item in entry:
      if item:
        return item
    return ''

  def approval_status(self, word):
    approval_dict = dict()
    approval_dict["word"] = word
    approval_dict["valid"] = self.word_valid(word)
    approval_dict["any"] = False
    approval_dict["wordlist"] = word in self.wordlist_solutions
    approval_dict["community"] = word in self.communitylist
    approval_dict["custom_reactions"] = word in custom_emojis[self.current_lang]
    for language in available_languages:
      approval_dict[language] = False
    for language in self.available_dictionaries_from(self.current_lang):
      approval_dict[language] = (word in self.available_hints[language]) and self.get_translation(word, DictionaryType(self.current_lang, language))
    # set "any"
    for x in ["wordlist", "community", "custom_reactions"] + available_languages:
      approval_dict["any"] = bool(approval_dict[x]) or approval_dict["any"]
    return approval_dict

  def found_words_sorted(self):
    return sorted(self.found_words_set, key=self._collator().getSortKey)
  
  def word_valid(self, word):
    if self.current_lang == "German":
      word = self.german_letters(word)
    word = word.lower()
    refdict = self.letters.lower_cntdict
    #print(refdict)
    wdict = CntDict(word)
    # Hope this works - ignoring "-"s
    del wdict['-']
    del wdict['.']
    del wdict["'"]
    if any(wdict - refdict):
      return False
    return True

  def _load_communitylist(self):
    with open(community_wordlist_paths[self.current_lang], 'r') as f:
      self.communitylist = f.read().splitlines()

  def _find_custom_emojis(self, refdict):
    self.custom_emoji_solution = [item for item in custom_emojis[self.current_lang].keys() if self._word_valid_fast(item, refdict)]


  def save_game(self):
    with open(self.file, 'w') as f:
      f.write(self.current_entry())

  #should be called when the current game is overwritten (by a load or starting a new game etc.)
  def _save_old(self):
    # unchanged loaded old games are not saved; if the game is not saved yet in saves.txt, it is appended (determined by game_number compared to max_saved_game and number of lines in saves.txt)
    if self.changes_to_save:
      # determines if game is already saved in saves.txt
      if self.game_number <= self.max_saved_game:
        archive_temp = []
        linenumber = 3*(int(self.game_number)-1)
        with open(self.archive_file, 'r') as f:
          archive_temp = f.readlines()
        with open(self.archive_file, 'w') as f:
          # older games
          for i in range(0,linenumber):
            f.write(archive_temp[i])
          # loaded game
          print(self.archive_entry(), file=f)
          # newer games
          for i in range(linenumber + 3, len(archive_temp)):
            f.write(archive_temp[i])
      # New game is appended (if game_number > max_saved_game)
      else:
        with open(self.archive_file, 'a') as f:
          print(self.archive_entry(), file=f)
        # one more saved game
        self.max_saved_game += 1
        self.save_game()

  def archive_entry(self):
    return '{}. ({})\n{}\n{}'.format(self.game_number, self.current_lang, ' '.join(sorted(self.letters.list, key=self._collator().getSortKey)), ' '.join(self.found_words_sorted()))

  def current_entry(self):
    return (
    f"#Current Game\n"
    f"{' '.join(self.letters.list)}\n"
    f"{' '.join(self.found_words_set)}\n"
    f"Game Number\t{self.game_number}\n"
    f"Game Language\t{self.current_lang}\n\n"
    f"# General Settings\n"
    f"Base Language\t{self.base_lang}\n"
    f"Planned Language\t{self.planned_lang}\n"
    f"Saved Games\t{self.max_saved_game}")
  
  def new_game(self):
    self._save_old()
    PlayerHandler.new_game()
    self._update_current_lang()
    if self.check_newest_game():
      self.try_load_oldgame(self.max_saved_game)
      return
    self.found_words_set.clear()
    # before creating a new one, old ones are saved, so max_saved_game contains all games
    self.game_number = self.max_saved_game + 1
    self.throw_dice()
    self._game_setup()
    # we have a game (thrown_the_dice), this new game will be saved, even if empty and is not yet in saves.txt (changes_to_save)
    self.changes_to_save = True

  def throw_dice(self):
    used_permutation = rnd.permutation(16)
    current_dice = dice_dict[self.current_lang]
    self.letters = LetterList([current_dice[dice_index][rnd.randint(0,6)] for dice_index in used_permutation], just_regenerate=True)
    self.save_game()

  def shuffle_letters(self):
    self.letters.shuffle()
    self.save_game()

  def add_word(self, word):
    starter_amount = self.amount_approved_words
    self.found_words_set.add(word)
    # added word has always to be saved (changes_to_save)
    self.changes_to_save = True
    if word in self.solutions:
      self.amount_approved_words += 1
    self.save_game()
    return starter_amount < self.end_amount and self.amount_approved_words == self.end_amount
    
  def try_add_community(self, word):
    if word in self.communitylist:
      return False
    with open(community_wordlist_paths[self.current_lang], 'a') as f:
      f.write(word + "\n")
    self.communitylist.append(word)
    if self.word_valid(word):
      self.communitylist_solutions.append(word)
      self.solutions.add(word)
      if word in self.found_words_set:
        self.amount_approved_words += 1
    return True

  def remove_word(self, word):
    self.found_words_set.discard(word)
    # removed words have always to be saved (changes_to_save)
    self.changes_to_save = True
    if word in self.solutions:
      self.amount_approved_words -= 1
    self.save_game()

  def clear_words(self):
    self.found_words_set.clear()
    self.save_game()

  def set_lang(self, word):
    self.planned_lang = word
    self.save_game()

  def _update_current_lang(self):
    self.current_lang = self.planned_lang
    self.save_game()
    # Is just called in next, thus doesn't need an own save_game
  
  
  def game_awards(self):
    highscore = dict()
    awards = {
      "First place" : [],
      "Second place" : [],
      "Third place" : [],
      "Newcomer" : [],
      "Best Beginner" : [],
      "Most solved hints" : []
    }
    for p_key, p_value in PlayerHandler.player_dict.items():
      if len(p_value["found_words"]) in highscore:
        highscore[len(p_value["found_words"])].append(p_key)
      # don't save people who didn't participate
      elif len(p_value["found_words"]):
        highscore[len(p_value["found_words"])] = [p_key]
    highscore_list = sorted(highscore.keys(), reverse=True)
    # Places 1,2,3
    places = ["First place", "Second place", "Third place"]
    for i in range(min(len(highscore_list), 3)):
      awards[places[i]] = highscore[highscore_list[i]]
    # Best Beginner
    for i in range(len(highscore_list)):
      for player in highscore[highscore_list[i]]:
        if PlayerHandler.player_dict[player]["role"] == "Beginner":
          awards["Best Beginner"].append(player)
      if awards["Best Beginner"]:
        break
    # Newcomer
    for i in range(len(highscore_list)):
      for player in highscore[highscore_list[i]]:
        if len(PlayerHandler.player_dict[player]["found_words"]) == PlayerHandler.player_dict[player]["all_time_found"]:
          awards["Newcomer"].append(player)
    # Most solved hints
    amount = 1
    for i in range(len(highscore_list)):
      for player in highscore[highscore_list[i]]:
        amount = max(amount, len(set(PlayerHandler.player_dict[player]["found_words"]) & set(PlayerHandler.player_dict[player]["used_hints"])))
    for i in range(len(highscore_list)):
      for player in highscore[highscore_list[i]]:
        if len(set(PlayerHandler.player_dict[player]["found_words"]).intersection(set(PlayerHandler.player_dict[player]["used_hints"]))) == amount:
          awards["Most solved hints"].append(player)
    print(awards)
    return(awards)

class EasterEggHandler():
  
  def __init__(self, found_words_set):
    self.amount_ny = 0
    self.huszár_counter = 0
    self._count_ny(found_words_set)
    
  def _count_ny(self, found_words_set):
    amount = 0
    for item in found_words_set:
      if "ny" in item:
        amount += 1
    self.amount_ny = amount
  
  def handle_easter_eggs(self, arg='', add=''):
    if arg:
      if arg == "bojler":
        if add == "_Rev":
          return "tongue"
        return "bojler"
      if "ny" in arg:
        self.amount_ny +=1
        if self.amount_ny % 7 == 0:
          return "nyan"
      if arg == "gulyáságyú":
        self.huszár_counter += 1
        print(self.huszár_counter)
        if self.huszár_counter == 3:
          self.huszár_counter = 0
          return "huszár"
    return ''


*/