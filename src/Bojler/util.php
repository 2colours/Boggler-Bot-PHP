<?php

declare(strict_types=1);

mb_internal_encoding('UTF-8');
mb_regex_encoding('UTF-8');

function remove_special_char(string $word)
{
    return str_replace(['.', '-'], '', $word);
}

# Might be a way of splitting a too long output string
function output_split(string $arg)
{
    if (grapheme_strlen($arg) <= 2000) {
        return [$arg];
    }
    # 1998, because we should be able to add _ before and after
    $index = grapheme_strrpos(grapheme_substr($arg, 0, 1998), ' ');
    return [grapheme_substr($arg, 0, $index), ...output_split(grapheme_substr($arg, $index + 1))];
}

function output_split_cursive(string $arg)
{
    $open_cursive = false;
    $output_array = output_split($arg);
    if (count($output_array) === 1) {
        return $output_array;
    }
    if (mb_substr_count($output_array[0], '_') % 2 === 1) {
        $output_array[0] = $output_array[0] . '_';
        $open_cursive = true;
    }
    foreach (array_slice($output_array, 1, count($output_array) - 2) as &$current_part) {
        if ($open_cursive) {
            $current_part = '_' . $current_part;
        }
        $open_cursive ^= mb_substr_count($current_part, '_') % 2 === 1;
        if ($open_cursive) {
            $current_part = $current_part . '_';
        }
    }
    if ($open_cursive) {
        $last_index = array_key_last($output_array);
        $output_array[$last_index] = '_' . $output_array[$last_index];
    }
    return $output_array;
}

function fetch_all(SQLite3Result $db_result)
{
    while ($current_entry = $db_result->fetchArray()) {
        yield $current_entry;
    }
}


function masked_word(string $original_word, array $transparent_positions)
{
    var_dump($transparent_positions);
    $result = '';
    $bytes_count = strlen($original_word);
    $next = 0;
    $current_position = 0;
    while (($current_grapheme = grapheme_extract($original_word, 1, GRAPHEME_EXTR_COUNT, $start = $next, $next)) !== false && $start < $bytes_count) {
        $result .= in_array($current_position, $transparent_positions) ? $current_grapheme : '◍';
        $current_position++;
    }
    return $result;
}
