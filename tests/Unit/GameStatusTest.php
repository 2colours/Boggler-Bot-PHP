<?php

use Bojler\{
    GameStatus
};

function get_wordlist_file($language) {
    $name_suffixes = [
        'English' => 'eng',
        'German' => 'ger',
        'Hungarian' => 'hun'
    ];

    return __DIR__ . "/../input_data/wordlist_$name_suffixes[$language].txt";
}

describe('wordValidFast', function () {
    function hack_current_language(mixed $game_status_mock, string $current_lang)
    {
        $reflection = new ReflectionClass($game_status_mock);
        $property = $reflection->getProperty('current_lang');
        $property->setValue($game_status_mock, $current_lang);
    }

    function get_test_data_file($language, $index)
    {
        return __DIR__ . "/../input_data/basic_validity/$language$index.json";
    }

    function refdict_to_ordered_letters($refdict): array
    {
        $result = [];

        ksort($refdict);
        foreach ($refdict as $letter => $count)
        {
            array_push($result, ...array_fill(0, $count, $letter));
        }

        return $result;
    }

    $stable_tests = ['English' => 1, 'Hungarian' => 3, 'German' => 2];

    foreach ($stable_tests as $language => $amount) {
        for ($game_index = 1; $game_index <= $amount; $game_index++) {
            $json_file = get_test_data_file($language, $game_index);
            $test_data = json_decode(file_get_contents($json_file), true);
            $string_representation = implode(' ', refdict_to_ordered_letters($test_data['refdict']));
            

            test("$language game #$game_index: $string_representation", function () use ($language, $test_data) {
                $refdict = $test_data['refdict'];
                $expected_words = $test_data['expected_words'];
        
                $mocked_status = Mockery::mock(GameStatus::class)->makePartial();
                hack_current_language($mocked_status, $language);
        
                foreach (file(get_wordlist_file($language), FILE_IGNORE_NEW_LINES) as $word) {
                    /** @disregard type hint on mocked object */
                    if ($mocked_status->wordValidFast($word, $refdict)) {
                        $good_words[] = $word;
                    }
                }
        
                expect($good_words)->toBe($expected_words);
            });
        }
    }

});