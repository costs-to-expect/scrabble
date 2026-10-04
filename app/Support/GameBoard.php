<?php

declare(strict_types=1);

namespace App\Support;

use App\View\Components\Avatar;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * What the home page, the games and the score sheet show about the players of a game: who is ahead, who is next,
 * the colour each of them keeps, how a finished game ended and the words for when a game was played.
 */
final class GameBoard
{
    /**
     * The colour of each player, from their place in the players list so they keep it in every game and nothing is stored
     *
     * @param list<array{id: string, name: string}> $players
     * @return array<string, int> player id => index into Avatar::TONES
     */
    public static function tones(array $players): array
    {
        $tones = [];

        foreach (array_values($players) as $position => $player) {
            $tones[$player['id']] = $position % count(Avatar::TONES);
        }

        return $tones;
    }

    /**
     * Who is playing, in the order they were added to the game, which is the order they take their turns in. A tile that
     * moves every time someone scores is a tile that gets tapped by mistake, so nothing here is sorted by score, see
     * ranked() for that.
     *
     * Whoever has played the fewest turns is next, the first of them when several have, so a scorer who enters the turns
     * in any order is still told who is due.
     *
     * @param list<array{id: string, name: string}> $players the players of the game
     * @param array<string, array> $sheets player id => score sheet
     * @param array<string, int> $tones
     * @return list<array{id: string, name: string, tone: int, score: int, turns: int, last: ?array, leader: bool, next: bool}>
     */
    public static function standings(array $players, array $sheets, array $tones): array
    {
        $standings = [];

        foreach ($players as $player) {
            $sheet = $sheets[$player['id']] ?? [];
            $totals = ScoreRules::totals($sheet);

            $standings[] = [
                'id' => $player['id'],
                'name' => $player['name'],
                'tone' => $tones[$player['id']] ?? 0,
                'score' => $totals['total'],
                'turns' => $totals['turns'],
                'last' => ScoreRules::last($sheet),
                'leader' => false,
                'next' => false,
            ];
        }

        if ($standings === []) {
            return [];
        }

        // A crown is for being ahead: someone has scored and someone else has scored less
        if (count($standings) > 1) {
            $scores = array_column($standings, 'score');
            $best = max($scores);

            if ($best > 0 && $best > min($scores)) {
                foreach ($standings as $position => $standing) {
                    $standings[$position]['leader'] = $standing['score'] === $best;
                }
            }
        }

        $fewest = min(array_column($standings, 'turns'));
        foreach ($standings as $position => $standing) {
            if ($standing['turns'] === $fewest) {
                $standings[$position]['next'] = true;
                break;
            }
        }

        return $standings;
    }

    /**
     * The same players, best score first. Players with the same score keep the order they play in.
     *
     * @template T of array{score: int}
     * @param list<T> $standings
     * @return list<T>
     */
    public static function ranked(array $standings): array
    {
        usort($standings, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return $standings;
    }

    /**
     * Why this many players cannot play a game, null when they can. A game needs a few players and the board only has
     * room for so many racks.
     *
     * @param bool $typed the players were typed in a box (names) rather than chosen from the list
     */
    public static function playersProblem(int $count, int $min, int $max, bool $typed = false): ?string
    {
        if ($count < $min) {
            return $typed
                ? 'Enter at least ' . $min . ' names, one per line'
                : 'Choose at least ' . $min . ' players';
        }

        if ($count > $max) {
            return 'A game has room for ' . $max . ' players at most' . ($typed ? ', enter fewer names' : ', choose fewer');
        }

        return null;
    }

    /**
     * The player whose turn it is, null when there are no players
     *
     * @param list<array{next: bool}> $standings
     * @return array<string, mixed>|null
     */
    public static function next(array $standings): ?array
    {
        foreach ($standings as $standing) {
            if ($standing['next']) {
                return $standing;
            }
        }

        return null;
    }

    /**
     * What a turn was, for a line under a name: QUIZ +52, Passed, Tiles left −7
     *
     * @param array{kind: string, word: string, score: int, bingo: bool, note: string}|null $turn
     */
    public static function play(?array $turn): ?string
    {
        if ($turn === null) {
            return null;
        }

        $points = ScoreRules::points($turn);

        return match ($turn['kind']) {
            ScoreRules::PASS => 'Passed',
            ScoreRules::ADJUST => ($turn['note'] !== '' ? $turn['note'] : 'Adjustment') . ' ' . ScoreRules::signed($points, true),
            default => trim($turn['word'] . ' ' . ScoreRules::signed($points, true)),
        };
    }

    /**
     * How a finished game ended, from what was stored when it was finished: who won (more than one when they tied),
     * with what, and what everyone else scored. Null when the game has no scores.
     *
     * @param array<string, mixed> $game a finished game as the API returns it
     * @return array{winner: string, tied: bool, score: int, others: string}|null
     */
    public static function result(array $game): ?array
    {
        $scores = $game['game']['scores'] ?? [];
        if (is_array($scores) === false || $scores === []) {
            return null;
        }

        $best = max(array_column($scores, 'score'));
        $winners = [];
        $others = [];

        foreach ($scores as $score) {
            if ($score['score'] === $best) {
                $winners[] = $score['player_name'];
            } else {
                $others[] = $score['player_name'] . ' ' . $score['score'];
            }
        }

        return [
            'winner' => self::names($winners),
            'tied' => count($winners) > 1,
            'score' => $best,
            'others' => implode(' · ', $others),
        ];
    }

    /**
     * Ada, Ben and Cleo
     *
     * @param list<string> $names
     */
    public static function names(array $names): string
    {
        if (count($names) <= 1) {
            return $names[0] ?? '';
        }

        return implode(', ', array_slice($names, 0, -1)) . ' and ' . $names[count($names) - 1];
    }

    /**
     * Ada, Ben & Cleo, short enough for a button
     *
     * @param list<string> $names
     */
    public static function ampersands(array $names): string
    {
        if (count($names) <= 1) {
            return $names[0] ?? '';
        }

        return implode(', ', array_slice($names, 0, -1)) . ' & ' . $names[count($names) - 1];
    }

    /**
     * When the API says a game was created, null when it does not say
     *
     * @param array<string, mixed> $game
     */
    public static function startedAt(array $game): ?CarbonImmutable
    {
        foreach (['created_at', 'created'] as $key) {
            if (isset($game[$key]) && is_string($game[$key]) && $game[$key] !== '') {
                try {
                    return CarbonImmutable::parse($game[$key]);
                } catch (\Throwable) {
                    return null;
                }
            }
        }

        return null;
    }

    /**
     * Today, Yesterday, Saturday or 12 Oct, null when there is no date to talk about
     */
    public static function when(?CarbonInterface $at, ?CarbonInterface $now = null): ?string
    {
        if ($at === null) {
            return null;
        }

        $now ??= CarbonImmutable::now();
        $days = (int) $at->startOfDay()->diffInDays($now->startOfDay(), true);

        return match (true) {
            $days === 0 => 'Today',
            $days === 1 => 'Yesterday',
            $days < 7 => $at->format('l'),
            $at->year === $now->year => $at->format('j M'),
            default => $at->format('j M Y'),
        };
    }

    /**
     * 40 min, how long ago a game started, null when there is no start to talk about
     */
    public static function since(?CarbonInterface $at, ?CarbonInterface $now = null): ?string
    {
        if ($at === null) {
            return null;
        }

        $now ??= CarbonImmutable::now();

        if ($at->greaterThan($now)) {
            return null;
        }

        return $at->diffForHumans($now, ['syntax' => CarbonInterface::DIFF_ABSOLUTE, 'parts' => 1, 'short' => true]);
    }

    /**
     * "today", "yesterday" or "on Saturday", for a sentence such as "Played on Saturday". Null when there is no date.
     */
    public static function playedOn(?string $when): ?string
    {
        if ($when === null) {
            return null;
        }

        return in_array($when, ['Today', 'Yesterday'], true) ? strtolower($when) : 'on ' . $when;
    }
}
