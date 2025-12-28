<?php

use function Bojler\{
    acknowledgement_reaction,
    progress_bar
};
use Bojler\{
    ConfigHandler,
    GameStatus
};

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
        $property->setValue($game_status_mock, $end_amount);
    }

    $emoji_scales = ['🤾‍♀️🥇', '🥚🐣🐥', '📖🇩🇪', '🥖🇫🇷', '🎨🏞️', '🎲🔠', '☁️🌥️⛅🌤️☀️'];
    $first_symbols = ['🤾‍♀️', '🥚', '📖', '🥖', '🎨', '🎲', '☁️'];
    $last_symbols = ['🥇', '🐥', '🇩🇪', '🇫🇷', '🏞️', '🔠', '☀️'];

    $config_handler = new ConfigHandler();

    test('100 = word limit < found approved words', function () use ($config_handler, $emoji_scales, $last_symbols) {
        $expected_emoji_count = 10;

        $mocked_status = Mockery::mock(GameStatus::class);
        hack_end_amount($mocked_status, 100);
        $mocked_status->shouldReceive('getApprovedAmount')->andReturn(252);

        foreach (array_map(null, $emoji_scales, $last_symbols) as [$current_scale, $current_symbol]) {
            $result = progress_bar($config_handler, $mocked_status, $current_scale);
            expect($result)->toBe(str_repeat($current_symbol, $expected_emoji_count));
        }
    });

    test('0 = found approved words < word limit = 123', function () use ($config_handler, $emoji_scales, $first_symbols) {
        $expected_emoji_count = 13;

        $mocked_status = Mockery::mock(GameStatus::class);
        hack_end_amount($mocked_status, 123);
        $mocked_status->shouldReceive('getApprovedAmount')->andReturn(0);

        foreach (array_map(null, $emoji_scales, $first_symbols) as [$current_scale, $current_symbol]) {
            $result = progress_bar($config_handler, $mocked_status, $current_scale);
            expect($result)->toBe(str_repeat($current_symbol, $expected_emoji_count));
        }
    });

    test('42 = found approved words < word limit = 53', function () use ($config_handler, $emoji_scales, $first_symbols, $last_symbols) {
        $expected_full_emoji_count = 4;
        $expected_intermediate_emojis = ['🤾‍♀️', '🥚', '📖', '🥖', '🎨', '🎲', '☁️'];
        $expected_empty_emoji_count = 1;

        $mocked_status = Mockery::mock(GameStatus::class);
        hack_end_amount($mocked_status, 53);
        $mocked_status->shouldReceive('getApprovedAmount')->andReturn(42);

        foreach ($emoji_scales as $current_index => $current_scale) {
            $result = progress_bar($config_handler, $mocked_status, $current_scale);
            $expected = str_repeat($last_symbols[$current_index], $expected_full_emoji_count)
                . $expected_intermediate_emojis[$current_index]
                . str_repeat($first_symbols[$current_index], $expected_empty_emoji_count);
            expect($result)->toBe($expected);
        }
    });

    test('117 = found approved words < word limit = 118', function () use ($config_handler, $emoji_scales, $last_symbols) {
        $expected_full_emoji_count = 11;
        $expected_intermediate_emojis = ['🤾‍♀️', '🐣', '📖', '🥖', '🎨', '🎲', '🌤️'];

        $mocked_status = Mockery::mock(GameStatus::class);
        hack_end_amount($mocked_status, 118);
        $mocked_status->shouldReceive('getApprovedAmount')->andReturn(117);

        foreach ($emoji_scales as $current_index => $current_scale) {
            $result = progress_bar($config_handler, $mocked_status, $current_scale);
            $expected = str_repeat($last_symbols[$current_index], $expected_full_emoji_count)
                . $expected_intermediate_emojis[$current_index];
            expect($result)->toBe($expected);
        }
    });

    test('104 = found approved words < word limit = 148', function () use ($config_handler, $emoji_scales, $first_symbols, $last_symbols) {
        $expected_full_emoji_count = 10;
        $expected_intermediate_emojis = ['🤾‍♀️', '🥚', '📖', '🥖', '🎨', '🎲', '🌥️'];
        $expected_empty_emoji_count = 4;

        $mocked_status = Mockery::mock(GameStatus::class);
        hack_end_amount($mocked_status, 148);
        $mocked_status->shouldReceive('getApprovedAmount')->andReturn(104);

        foreach ($emoji_scales as $current_index => $current_scale) {
            $result = progress_bar($config_handler, $mocked_status, $current_scale);
            $expected = str_repeat($last_symbols[$current_index], $expected_full_emoji_count)
                . $expected_intermediate_emojis[$current_index]
                . str_repeat($first_symbols[$current_index], $expected_empty_emoji_count);
            expect($result)->toBe($expected);
        }
    });

    test('6 = found approved words < word limit = 47', function () use ($config_handler, $emoji_scales, $first_symbols) {
        $expected_intermediate_emojis = ['🤾‍♀️', '🐣', '📖', '🥖', '🎨', '🎲', '⛅'];
        $expected_empty_emoji_count = 4;

        $mocked_status = Mockery::mock(GameStatus::class);
        hack_end_amount($mocked_status, 47);
        $mocked_status->shouldReceive('getApprovedAmount')->andReturn(6);

        foreach ($emoji_scales as $current_index => $current_scale) {
            $result = progress_bar($config_handler, $mocked_status, $current_scale);
            $expected = $expected_intermediate_emojis[$current_index]
                . str_repeat($first_symbols[$current_index], $expected_empty_emoji_count);
            expect($result)->toBe($expected);
        }
    });
});
