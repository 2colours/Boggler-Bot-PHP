<?php

function remove_special_char($word)
{
    return str_replace(['.', '-'], '', $word);
}

# Might be a way of splitting a too long output string
function output_split($arg)
{
    if (mb_strlen($arg) <= 2000)
        return [$arg];
    # 1998, because we should be able to add _ before and after
    $index = mb_strrpos(mb_substr($arg, 0, 1998), ' ');
    return [mb_substr($arg, 0, $index), ...output_split(mb_substr($arg, $index + 1))];
}

function output_split_cursive($arg)
{
    $open_cursive = false;
    $output_array = output_split($arg);
    if (count($output_array) == 1)
        return $output_array;
    if (mb_substr_count($output_array[0], '_') % 2 == 1) {
        $output_array[0] = $output_array[0] . '_';
        $open_cursive = true;
    }
    for ($i = 1; $i < count($output_array) - 1; $i++) { # TODO: try array_slice and referenced foreach here?
        if ($open_cursive)
            $output_array[$i] = '_' . $output_array[$i];
        $open_cursive ^= mb_substr_count($output_array[$i], '_') % 2 == 1;
        if ($open_cursive)
            $output_array[$i] .= '_';
    }
    if ($open_cursive) {
        $last_index = array_key_last($output_array);
        $output_array[$last_index] = '_' . $output_array[$last_index];
    }
    return $output_array;
}

function fetch_all(SQLite3Result $db_result)
{
    while ($current_entry = $db_result->fetchArray())
        yield $current_entry;
}
