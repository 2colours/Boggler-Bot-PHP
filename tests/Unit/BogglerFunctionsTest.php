<?php

use function Bojler\{
    acknowledgement_reaction,
    progress_bar
};
use Bojler\{
    GameStatus,
    ConfigHandler
};
use Mockery;

describe('acknowledgement_reaction', function () {
    it('detects short words correctly', function () {
        $short_words = ['alma', 'béka', 'e-mail', 'Fuß', 'naïve'];
        foreach ($short_words as $current_word) {
            $result = acknowledgement_reaction($current_word);
            expect($result)->toBe('👍');
        }
    });

    it('detects intermediate (6, 7, 8) length words correctly', function () {
        $intermediate_words = ['szavak', 'lebzsel', 'doesn\'t', 'túlélőit']; # NOTE: the apostrophe is not stripped
        foreach ($intermediate_words as $current_word) {
            $result = acknowledgement_reaction($current_word);
            expect($result)->toBe('🎉');
        }
    });

    it('detects advanced (9) length words correctly', function () {
        $advanced_words = ['kilencven', 'lassított', 'lassít-ott', 'Aalfänger', 'Zytologie'];
        foreach ($advanced_words as $current_word) {
            $result = acknowledgement_reaction($current_word);
            expect($result)->toBe('🤯');
        }
    });

    it('detects long words correctly', function () {
        $long_words = ['érelmeszesedés', 'árvíztűrő tükörfúrógép'];
        foreach ($long_words as $current_word) {
            $result = acknowledgement_reaction($current_word);
            expect($result)->toBe('💯');
        }
    });
});


describe('progress_bar', function () {
    function hack_end_amount(mixed $game_status_mock, int $end_amount)
    {
        $reflection = new ReflectionClass($game_status_mock);
        $property = $reflection->getProperty('end_amount');
        $property->setAccessible(true);
        $property->setValue($game_status_mock, $end_amount);
    }

    $mocked_status = Mockery::mock(GameStatus::class);
    $emoji_scales = ['🤾‍♀️🥇', '🥚🐣🐥', '📖🇩🇪', '🥖🇫🇷', '🎨🏞️', '🎲🔠', '☁️🌥️⛅🌤️☀️'];

    test('150 = possible words < found approved words', function () use ($mocked_status, $emoji_scales) {
        $last_symbols = ['🥇', '🐥', '🇩🇪', '🇫🇷', '🏞️', '🔠', '☀️'];
        hack_end_amount($mocked_status, 150);
        $mocked_status->shouldReceive('getApprovedAmount')->andReturn(250);

        foreach (array_map(null, $emoji_scales, $last_symbols) as [$current_scale, $current_symbol]) {
            $result = progress_bar($mocked_status, $current_scale);
            expect($result)->toBe(str_repeat($current_symbol, 15));
        }
    });
});
