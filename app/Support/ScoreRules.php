<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The rules of a Scrabble score sheet, in one place: what a turn can be, what it can score, the bingo bonus and how
 * the totals are worked out.
 *
 * A score sheet is the array the API stores for a player. `turns` is everything that happened to their score, in the
 * order it happened, and `score` holds the totals. A turn is a word, a pass (or a tile exchange, it scores nothing
 * either way) or an adjustment (the tiles left at the end of the game, a penalty after a challenge).
 *
 * Nothing is ever taken out of a stored sheet. Removing a turn only marks it removed and every turn is always
 * written in full, so a correction works whether the API replaces the sheet it is sent or merges it into the one it
 * has stored. A turn is identified by an id the browser makes up, so a save that is sent twice (the answer got lost
 * and the browser tries again) never scores twice.
 */
final class ScoreRules
{
    public const WORD = 'word';

    public const PASS = 'pass';

    public const ADJUST = 'adjust';

    /** @var list<string> */
    public const KINDS = [self::WORD, self::PASS, self::ADJUST];

    /** Playing all seven tiles in one turn */
    public const BINGO_BONUS = 50;

    /** The highest play in a tournament game is 830, a word scores between 1 and this before the bingo bonus */
    public const MAX_WORD_SCORE = 999;

    public const MAX_ADJUSTMENT = 999;

    /** The board is 15 squares across */
    public const MAX_LETTERS = 15;

    public const MAX_NOTE = 30;

    /** Everything on one sheet, removed turns included, a game is about 20 turns each */
    public const MAX_TURNS = 150;

    public const ID_PATTERN = '/^[A-Za-z0-9_-]{8,40}$/';

    /**
     * What a player's sheet looks like before the first turn
     *
     * @return array{turns: list<array<string, mixed>>, score: array<string, mixed>}
     */
    public static function emptySheet(): array
    {
        return ['turns' => [], 'score' => self::totals([])];
    }

    /**
     * The numbers the home page and the API's own data need: the score, the turns played, how many words and bingos,
     * the best and lowest word. Adjustments are points but not turns.
     *
     * @return array{total: int, turns: int, words: int, bingos: int, best: ?int, lowest: ?int}
     */
    public static function totals(array $sheet): array
    {
        $total = 0;
        $turns = 0;
        $words = 0;
        $bingos = 0;
        $best = null;
        $lowest = null;

        foreach (self::entries($sheet) as $turn) {
            $points = self::points($turn);
            $total += $points;

            if ($turn['kind'] !== self::ADJUST) {
                $turns++;
            }

            if ($turn['kind'] === self::WORD) {
                $words++;
                $bingos += $turn['bingo'] ? 1 : 0;
                $best = $best === null ? $points : max($best, $points);
                $lowest = $lowest === null ? $points : min($lowest, $points);
            }
        }

        return ['total' => $total, 'turns' => $turns, 'words' => $words, 'bingos' => $bingos, 'best' => $best, 'lowest' => $lowest];
    }

    /**
     * The turns a player has played, adjustments are not turns
     */
    public static function turns(array $sheet): int
    {
        return self::totals($sheet)['turns'];
    }

    /**
     * What a turn is worth, the bingo bonus is on top of what the word scored
     */
    public static function points(array $turn): int
    {
        return match ($turn['kind'] ?? self::WORD) {
            self::PASS => 0,
            self::ADJUST => (int) ($turn['score'] ?? 0),
            default => (int) ($turn['score'] ?? 0) + (($turn['bingo'] ?? false) === true ? self::BINGO_BONUS : 0),
        };
    }

    /**
     * Every turn on the sheet, removed ones included, each with every key and the right types whatever the API handed
     * back (a key that went missing, a value that came back as the wrong type)
     *
     * @return list<array{id: string, kind: string, word: string, score: int, bingo: bool, note: string, at: string, removed: bool}>
     */
    public static function all(array $sheet): array
    {
        $stored = $sheet['turns'] ?? [];
        $turns = [];

        foreach (is_array($stored) ? $stored : [] as $turn) {
            if (is_array($turn)) {
                $turns[] = self::turn($turn);
            }
        }

        return $turns;
    }

    /**
     * The turns that count, in the order they were played
     *
     * @return list<array{id: string, kind: string, word: string, score: int, bingo: bool, note: string, at: string, removed: bool}>
     */
    public static function entries(array $sheet): array
    {
        return array_values(array_filter(
            self::all($sheet),
            static fn (array $turn): bool => $turn['removed'] === false
        ));
    }

    /**
     * The most recent turn that counts, null before the first
     *
     * @return array{id: string, kind: string, word: string, score: int, bingo: bool, note: string, at: string, removed: bool}|null
     */
    public static function last(array $sheet): ?array
    {
        $entries = self::entries($sheet);

        return $entries === [] ? null : $entries[array_key_last($entries)];
    }

    /**
     * One turn with every key and the right types
     *
     * @param array<string, mixed> $turn
     * @return array{id: string, kind: string, word: string, score: int, bingo: bool, note: string, at: string, removed: bool}
     */
    public static function turn(array $turn): array
    {
        $kind = $turn['kind'] ?? null;

        return [
            'id' => is_string($turn['id'] ?? null) ? $turn['id'] : '',
            'kind' => is_string($kind) && in_array($kind, self::KINDS, true) ? $kind : self::WORD,
            'word' => is_string($turn['word'] ?? null) ? $turn['word'] : '',
            'score' => is_int($turn['score'] ?? null) ? $turn['score'] : 0,
            'bingo' => ($turn['bingo'] ?? false) === true,
            'note' => is_string($turn['note'] ?? null) ? $turn['note'] : '',
            'at' => is_string($turn['at'] ?? null) ? $turn['at'] : '',
            'removed' => ($turn['removed'] ?? false) === true,
        ];
    }

    /**
     * Where a turn is on the sheet, null when it is not there
     */
    public static function indexOf(array $sheet, string $id): ?int
    {
        foreach (self::all($sheet) as $index => $turn) {
            if ($turn['id'] === $id) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Whether two turns are the same play, the id, when it was added and whether it was removed do not count
     */
    public static function same(array $a, array $b): bool
    {
        foreach (['kind', 'word', 'score', 'bingo', 'note'] as $key) {
            if (($a[$key] ?? null) !== ($b[$key] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Why a turn cannot go on the sheet, null when it can
     *
     * @param array<string, mixed> $input as sent by the browser: JSON numbers and booleans, or strings of them
     */
    public static function problem(array $input): ?string
    {
        $id = $input['id'] ?? null;
        if (is_string($id) === false || preg_match(self::ID_PATTERN, $id) !== 1) {
            return 'The turn needs an id';
        }

        $kind = $input['kind'] ?? null;
        if (is_string($kind) === false || in_array($kind, self::KINDS, true) === false) {
            return 'That is not a kind of turn';
        }

        $bingo = self::boolean($input['bingo'] ?? false);
        if ($bingo === null) {
            return 'Bingo has to be yes or no';
        }

        $score = $input['score'] ?? null;
        $points = self::integer($score);

        if ($kind === self::PASS) {
            if (($score !== null && $score !== '' && $points !== 0) || $bingo === true) {
                return 'A pass scores nothing';
            }

            return null;
        }

        if ($points === null) {
            return 'The score has to be a whole number';
        }

        if ($kind === self::ADJUST) {
            if ($points === 0 || abs($points) > self::MAX_ADJUSTMENT) {
                return 'An adjustment is more than 0 and no more than ' . self::MAX_ADJUSTMENT . ', up or down';
            }

            if ($bingo === true) {
                return 'An adjustment is not a bingo';
            }

            return self::note($input['note'] ?? '') === null
                ? 'The note can be no more than ' . self::MAX_NOTE . ' characters'
                : null;
        }

        if (self::word($input['word'] ?? '') === null) {
            return 'A word is letters only, no more than ' . self::MAX_LETTERS . ' of them';
        }

        if ($points < 1 || $points > self::MAX_WORD_SCORE) {
            return 'A word scores between 1 and ' . self::MAX_WORD_SCORE;
        }

        return null;
    }

    /**
     * The turn to store for an input that passed problem(), in full: a pass has no word, a word has no note and so on,
     * so changing a turn from one kind to another never leaves part of the old one behind
     *
     * @param array<string, mixed> $input
     * @return array{id: string, kind: string, word: string, score: int, bingo: bool, note: string, at: string, removed: bool}
     */
    public static function fromInput(array $input, string $at): array
    {
        $kind = (string) $input['kind'];

        return [
            'id' => (string) $input['id'],
            'kind' => $kind,
            'word' => $kind === self::WORD ? (string) self::word($input['word'] ?? '') : '',
            'score' => $kind === self::PASS ? 0 : (int) self::integer($input['score'] ?? null),
            'bingo' => $kind === self::WORD && self::boolean($input['bingo'] ?? false) === true,
            'note' => $kind === self::ADJUST ? (string) self::note($input['note'] ?? '') : '',
            'at' => $at,
            'removed' => false,
        ];
    }

    /**
     * The sheet with a turn added at the end and the totals worked out again
     *
     * @param array{id: string, kind: string, word: string, score: int, bingo: bool, note: string, at: string, removed: bool} $turn
     */
    public static function with(array $sheet, array $turn): array
    {
        $turns = self::all($sheet);
        $turns[] = self::turn($turn);

        return self::sheet($sheet, $turns);
    }

    /**
     * The sheet with a turn rewritten, it keeps its place, the time it was added and whether it is removed
     *
     * @param array{id: string, kind: string, word: string, score: int, bingo: bool, note: string, at: string, removed: bool} $turn
     */
    public static function changed(array $sheet, int $index, array $turn): array
    {
        $turns = self::all($sheet);
        $turns[$index] = self::turn(['at' => $turns[$index]['at'], 'removed' => $turns[$index]['removed']] + $turn);

        return self::sheet($sheet, $turns);
    }

    /**
     * The sheet with a turn marked removed, or put back
     */
    public static function removal(array $sheet, int $index, bool $removed): array
    {
        $turns = self::all($sheet);
        $turns[$index]['removed'] = $removed;

        return self::sheet($sheet, $turns);
    }

    /**
     * The word with its letters in capitals, null when it is not a word. Nothing at all is a fine word, the word is
     * optional.
     */
    public static function word(mixed $word): ?string
    {
        if (is_string($word) === false) {
            return null;
        }

        $word = trim($word);
        if ($word === '') {
            return '';
        }

        if (preg_match('/^\p{L}+$/u', $word) !== 1) {
            return null;
        }

        $word = mb_strtoupper($word);

        return mb_strlen($word) <= self::MAX_LETTERS ? $word : null;
    }

    /**
     * The note on an adjustment, trimmed, null when it is too long
     */
    public static function note(mixed $note): ?string
    {
        if ($note === null) {
            return '';
        }

        if (is_string($note) === false) {
            return null;
        }

        $note = trim((string) preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', $note));

        return mb_strlen($note) <= self::MAX_NOTE ? $note : null;
    }

    /**
     * A whole number, from a JSON number or from a string of digits (with a minus sign for an adjustment)
     */
    public static function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d{1,4}$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    /**
     * Yes or no, from a JSON boolean or from a form, null when it is neither
     */
    public static function boolean(mixed $value): ?bool
    {
        return match (true) {
            $value === null, $value === false, $value === 0, $value === '0', $value === 'false', $value === '' => false,
            $value === true, $value === 1, $value === '1', $value === 'true' => true,
            default => null,
        };
    }

    /**
     * What a turn is, in words, for the game log: Played QUIZ for 102, including the 50 point bingo
     */
    public static function describe(array $turn): string
    {
        $turn = self::turn($turn);

        if ($turn['kind'] === self::PASS) {
            return 'Passed their turn';
        }

        if ($turn['kind'] === self::ADJUST) {
            return 'Adjusted their score by ' . self::signed($turn['score']) . ($turn['note'] !== '' ? ' (' . $turn['note'] . ')' : '');
        }

        $points = self::points($turn);
        $bonus = $turn['bingo'] ? ', including the ' . self::BINGO_BONUS . ' point bingo' : '';

        return ($turn['word'] !== '' ? 'Played ' . $turn['word'] . ' for ' : 'Scored ') . $points . $bonus;
    }

    /**
     * +52 or -7, with a real minus sign for the screen
     */
    public static function signed(int $points, bool $typographic = false): string
    {
        if ($points < 0) {
            return ($typographic ? "\u{2212}" : '-') . abs($points);
        }

        return '+' . $points;
    }

    /**
     * The limits the browser applies before it asks, they are the server's own, written into the page
     *
     * @return array{bingo: int, maxWord: int, maxAdjustment: int, maxLetters: int, maxNote: int}
     */
    public static function limits(): array
    {
        return [
            'bingo' => self::BINGO_BONUS,
            'maxWord' => self::MAX_WORD_SCORE,
            'maxAdjustment' => self::MAX_ADJUSTMENT,
            'maxLetters' => self::MAX_LETTERS,
            'maxNote' => self::MAX_NOTE,
        ];
    }

    /**
     * The sheet with these turns and the totals worked out again, anything else in the sheet is kept
     *
     * @param list<array<string, mixed>> $turns
     */
    private static function sheet(array $sheet, array $turns): array
    {
        $sheet['turns'] = array_values($turns);
        $sheet['score'] = self::totals($sheet);

        return $sheet;
    }
}
