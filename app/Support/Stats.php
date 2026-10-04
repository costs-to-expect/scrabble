<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * The statistics: one player's numbers from their score sheet, and the numbers across finished games.
 *
 * When a game is finished the app stores each player's numbers with the game (see App\Actions\Game\Complete), so the
 * stats of any number of finished games come from the list of games alone, no score sheet has to be read again.
 */
final class Stats
{
    /**
     * One player's numbers from their sheet. A word's score is what the turn was worth, the bingo bonus included.
     *
     * - score: the total, adjustments included
     * - turns: words and passes, adjustments are not turns
     * - word_points: what the words were worth, bingos included, adjustments are not
     * - adjustment: what the adjustments came to (the tiles left, going out, a penalty)
     * - best, lowest, longest: a play, null when there was no word
     *
     * @return array{score: int, turns: int, words: int, passes: int, bingos: int, word_points: int, adjustment: int, best: ?array{word: string, score: int, bingo: bool}, lowest: ?array{word: string, score: int, bingo: bool}, longest: ?array{word: string, score: int, bingo: bool}}
     */
    public static function player(array $sheet): array
    {
        $words = 0;
        $passes = 0;
        $bingos = 0;
        $word_points = 0;
        $adjustment = 0;
        $best = null;
        $lowest = null;
        $longest = null;

        foreach (ScoreRules::entries($sheet) as $turn) {
            $points = ScoreRules::points($turn);

            if ($turn['kind'] === ScoreRules::PASS) {
                $passes++;
                continue;
            }

            if ($turn['kind'] === ScoreRules::ADJUST) {
                $adjustment += $points;
                continue;
            }

            $words++;
            $bingos += $turn['bingo'] ? 1 : 0;
            $word_points += $points;

            $play = ['word' => $turn['word'], 'score' => $points, 'bingo' => $turn['bingo']];

            // The first play wins a tie, the one that was played earlier
            if ($best === null || $points > $best['score']) {
                $best = $play;
            }

            if ($lowest === null || $points < $lowest['score']) {
                $lowest = $play;
            }

            if ($turn['word'] !== '') {
                $length = mb_strlen($turn['word']);

                if ($longest === null || $length > mb_strlen($longest['word']) || ($length === mb_strlen($longest['word']) && $points > $longest['score'])) {
                    $longest = $play;
                }
            }
        }

        return [
            'score' => ScoreRules::totals($sheet)['total'],
            'turns' => $words + $passes,
            'words' => $words,
            'passes' => $passes,
            'bingos' => $bingos,
            'word_points' => $word_points,
            'adjustment' => $adjustment,
            'best' => $best,
            'lowest' => $lowest,
            'longest' => $longest,
        ];
    }

    /**
     * What is stored with a finished game for one player: who they are and their numbers
     *
     * @return array<string, mixed>
     */
    public static function entry(string $player_id, string $player_name, array $sheet): array
    {
        return ['player_id' => $player_id, 'player_name' => $player_name] + self::player($sheet);
    }

    /**
     * The numbers across finished games: the records (the best word, the closest game...) and a line for each player.
     * Also the highlights of a single game, pass it as the only game.
     *
     * A game that ended in a tie is a win for everyone on the top score. A game stored without a player's numbers (it
     * was finished before they were kept) counts for its scores and its winner, and for nothing else.
     *
     * @param list<array<string, mixed>> $games finished games as the API returns them, newest first
     * @return array{
     *     games: int, turns: int, words: int, bingos: int, word_points: int, average_word: ?float,
     *     best_word: ?array, lowest_word: ?array, longest_word: ?array,
     *     best_game: ?array, lowest_win: ?array, biggest_win: ?array, closest_game: ?array, most_bingos: ?array,
     *     players: list<array<string, mixed>>
     * }
     */
    public static function overall(array $games): array
    {
        $counted = 0;
        $turns = 0;
        $words = 0;
        $bingos = 0;
        $word_points = 0;
        $best_word = null;
        $lowest_word = null;
        $longest_word = null;
        $best_game = null;
        $lowest_win = null;
        $biggest_win = null;
        $closest_game = null;
        $most_bingos = null;
        $players = [];

        foreach ($games as $game) {
            $stored = $game['game']['scores'] ?? [];

            $entries = [];
            foreach (is_array($stored) ? $stored : [] as $score) {
                if (is_array($score)) {
                    $score['score'] = (int) ($score['score'] ?? 0);
                    $entries[] = $score;
                }
            }

            // A game that was finished without any scores has nothing to count
            if ($entries === []) {
                continue;
            }

            $counted++;
            $where = ['game' => (string) ($game['id'] ?? ''), 'started' => GameBoard::startedAt($game)];

            $ranked = GameBoard::ranked($entries);
            $top = $ranked[0]['score'];

            foreach ($ranked as $entry) {
                $id = (string) ($entry['player_id'] ?? '');
                $name = (string) ($entry['player_name'] ?? '');
                $score = $entry['score'];

                $players[$id] ??= [
                    'id' => $id, 'name' => $name, 'games' => 0, 'wins' => 0, 'total' => 0, 'best_game' => null,
                    'turns' => 0, 'words' => 0, 'word_points' => 0, 'bingos' => 0, 'best_word' => null,
                ];
                $players[$id]['games']++;
                $players[$id]['wins'] += $score === $top ? 1 : 0;
                $players[$id]['total'] += $score;
                $players[$id]['best_game'] = max($players[$id]['best_game'] ?? $score, $score);

                $played = (int) ($entry['words'] ?? 0);
                $turns += (int) ($entry['turns'] ?? 0);
                $words += $played;
                $bingos += (int) ($entry['bingos'] ?? 0);
                $word_points += (int) ($entry['word_points'] ?? 0);

                $players[$id]['turns'] += (int) ($entry['turns'] ?? 0);
                $players[$id]['words'] += $played;
                $players[$id]['word_points'] += (int) ($entry['word_points'] ?? 0);
                $players[$id]['bingos'] += (int) ($entry['bingos'] ?? 0);

                $who = ['player' => $name] + $where;

                if ($best_game === null || $score > $best_game['score']) {
                    $best_game = ['score' => $score] + $who;
                }

                if (is_array($entry['best'] ?? null)) {
                    $play = $entry['best'] + ['word' => '', 'score' => 0, 'bingo' => false];

                    if ($best_word === null || $play['score'] > $best_word['score']) {
                        $best_word = $play + $who;
                    }

                    if ($players[$id]['best_word'] === null || $play['score'] > $players[$id]['best_word']['score']) {
                        $players[$id]['best_word'] = $play;
                    }
                }

                if (is_array($entry['lowest'] ?? null)) {
                    $play = $entry['lowest'] + ['word' => '', 'score' => 0, 'bingo' => false];

                    if ($lowest_word === null || $play['score'] < $lowest_word['score']) {
                        $lowest_word = $play + $who;
                    }
                }

                if (is_array($entry['longest'] ?? null)) {
                    $play = $entry['longest'] + ['word' => '', 'score' => 0, 'bingo' => false];

                    if ($longest_word === null
                        || mb_strlen($play['word']) > mb_strlen($longest_word['word'])
                        || (mb_strlen($play['word']) === mb_strlen($longest_word['word']) && $play['score'] > $longest_word['score'])) {
                        $longest_word = $play + $who;
                    }
                }

                $bingos_in_game = (int) ($entry['bingos'] ?? 0);
                if ($bingos_in_game > 0 && ($most_bingos === null || $bingos_in_game > $most_bingos['count'])) {
                    $most_bingos = ['count' => $bingos_in_game] + $who;
                }
            }

            $winners = array_values(array_filter($ranked, static fn (array $entry): bool => $entry['score'] === $top));
            $winner = GameBoard::names(array_map(static fn (array $entry): string => (string) ($entry['player_name'] ?? ''), $winners));

            if ($lowest_win === null || $top < $lowest_win['score']) {
                $lowest_win = ['score' => $top, 'player' => $winner] + $where;
            }

            // How far ahead first place finished, nothing when they tied
            if (count($ranked) > 1) {
                $runner_up = $ranked[1];
                $margin = $top - $runner_up['score'];
                $line = [
                    'margin' => $margin,
                    'winner' => $winner,
                    'score' => $top,
                    'runner_up' => (string) ($runner_up['player_name'] ?? ''),
                    'runner_up_score' => $runner_up['score'],
                ] + $where;

                if ($biggest_win === null || $margin > $biggest_win['margin']) {
                    $biggest_win = $line;
                }

                if ($closest_game === null || $margin < $closest_game['margin']) {
                    $closest_game = $line;
                }
            }
        }

        $lines = [];
        foreach ($players as $player) {
            $lines[] = $player + [
                'win_rate' => $player['games'] > 0 ? $player['wins'] / $player['games'] : 0.0,
                'average' => $player['games'] > 0 ? $player['total'] / $player['games'] : 0.0,
                'average_word' => $player['words'] > 0 ? $player['word_points'] / $player['words'] : null,
            ];
        }

        usort($lines, static fn (array $a, array $b): int => [$b['wins'], $b['average'], $a['name']] <=> [$a['wins'], $a['average'], $b['name']]);

        return [
            'games' => $counted,
            'turns' => $turns,
            'words' => $words,
            'bingos' => $bingos,
            'word_points' => $word_points,
            'average_word' => $words > 0 ? $word_points / $words : null,
            'best_word' => $best_word,
            'lowest_word' => $lowest_word,
            'longest_word' => $longest_word,
            'best_game' => $best_game,
            'lowest_win' => $lowest_win,
            'biggest_win' => $biggest_win,
            'closest_game' => $closest_game,
            'most_bingos' => $most_bingos,
            'players' => $lines,
        ];
    }
}
