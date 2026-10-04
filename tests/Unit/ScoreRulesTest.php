<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ScoreRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ScoreRulesTest extends TestCase
{
    /**
     * A stored turn, every key there
     */
    private function turn(string $id, string $kind = 'word', string $word = '', int $score = 0, bool $bingo = false, string $note = '', bool $removed = false, string $tiles = ''): array
    {
        return ['id' => $id, 'kind' => $kind, 'word' => $word, 'score' => $score, 'bingo' => $bingo, 'note' => $note, 'tiles' => $tiles, 'at' => '2026-10-04T19:30:00Z', 'removed' => $removed];
    }

    private function sheet(array ...$turns): array
    {
        return ['turns' => $turns, 'score' => []];
    }

    public function test_the_totals_of_an_empty_sheet_are_zero(): void
    {
        $zero = ['total' => 0, 'turns' => 0, 'words' => 0, 'bingos' => 0, 'best' => null, 'lowest' => null];

        self::assertSame($zero, ScoreRules::totals([]));
        self::assertSame($zero, ScoreRules::totals(ScoreRules::emptySheet()));
        self::assertSame(['turns' => [], 'score' => $zero], ScoreRules::emptySheet());
        self::assertSame(0, ScoreRules::turns([]));
    }

    public function test_a_bingo_is_fifty_on_top_of_the_word(): void
    {
        $sheet = $this->sheet(
            $this->turn('turn-0001', 'word', 'QUIZ', 52),
            $this->turn('turn-0002', 'word', 'RETAINS', 68, true)
        );

        self::assertSame(['total' => 170, 'turns' => 2, 'words' => 2, 'bingos' => 1, 'best' => 118, 'lowest' => 52], ScoreRules::totals($sheet));
        self::assertSame(52, ScoreRules::points($sheet['turns'][0]));
        self::assertSame(118, ScoreRules::points($sheet['turns'][1]));
    }

    public function test_a_pass_is_a_turn_that_scores_nothing_and_is_not_a_word(): void
    {
        $sheet = $this->sheet(
            $this->turn('turn-0001', 'word', 'FAX', 33),
            $this->turn('turn-0002', 'pass')
        );

        self::assertSame(['total' => 33, 'turns' => 2, 'words' => 1, 'bingos' => 0, 'best' => 33, 'lowest' => 33], ScoreRules::totals($sheet));
        self::assertSame(2, ScoreRules::turns($sheet));
    }

    public function test_an_adjustment_is_points_but_not_a_turn_or_a_word(): void
    {
        $sheet = $this->sheet(
            $this->turn('turn-0001', 'word', 'JAZZ', 44),
            $this->turn('turn-0002', 'adjust', '', -7, false, 'Tiles left'),
            $this->turn('turn-0003', 'adjust', '', 12, false, 'Went out')
        );

        self::assertSame(['total' => 49, 'turns' => 1, 'words' => 1, 'bingos' => 0, 'best' => 44, 'lowest' => 44], ScoreRules::totals($sheet));
        self::assertSame(-7, ScoreRules::points($sheet['turns'][1]));
    }

    public function test_the_total_can_end_up_below_zero(): void
    {
        $sheet = $this->sheet($this->turn('turn-0001', 'adjust', '', -9, false, 'Tiles left'));

        self::assertSame(-9, ScoreRules::totals($sheet)['total']);
    }

    public function test_a_removed_turn_counts_for_nothing(): void
    {
        $sheet = $this->sheet(
            $this->turn('turn-0001', 'word', 'QUIZ', 52),
            $this->turn('turn-0002', 'word', 'OXYGEN', 90, true, '', true)
        );

        self::assertSame(['total' => 52, 'turns' => 1, 'words' => 1, 'bingos' => 0, 'best' => 52, 'lowest' => 52], ScoreRules::totals($sheet));
        self::assertCount(2, ScoreRules::all($sheet));
        self::assertCount(1, ScoreRules::entries($sheet));
        self::assertSame('turn-0001', ScoreRules::last($sheet)['id']);
    }

    public function test_the_last_turn_is_the_last_one_that_counts(): void
    {
        self::assertNull(ScoreRules::last([]));
        self::assertNull(ScoreRules::last($this->sheet($this->turn('turn-0001', 'word', 'AX', 9, false, '', true))));
        self::assertSame('turn-0002', ScoreRules::last($this->sheet($this->turn('turn-0001', 'word', 'AX', 9), $this->turn('turn-0002', 'pass')))['id']);
    }

    public function test_a_stored_turn_is_read_with_every_key_whatever_the_api_gave_back(): void
    {
        // An API that merges what it is sent, or a sheet written by hand, can be missing keys or have the wrong types
        $sheet = ['turns' => [
            ['id' => 'turn-0001', 'score' => 12, 'word' => 'AX'],
            'not a turn',
            ['id' => 'turn-0002', 'kind' => 'nonsense', 'score' => '9', 'bingo' => 'yes', 'removed' => 1],
            ['id' => 5, 'kind' => 'pass', 'note' => ['x']],
        ]];

        $turns = ScoreRules::all($sheet);

        self::assertCount(3, $turns);
        self::assertSame(
            ['id' => 'turn-0001', 'kind' => 'word', 'word' => 'AX', 'score' => 12, 'bingo' => false, 'note' => '', 'tiles' => '', 'at' => '', 'removed' => false],
            $turns[0]
        );
        self::assertSame(
            ['id' => 'turn-0002', 'kind' => 'word', 'word' => '', 'score' => 0, 'bingo' => false, 'note' => '', 'tiles' => '', 'at' => '', 'removed' => false],
            $turns[1]
        );
        self::assertSame(['id' => '', 'kind' => 'pass', 'word' => '', 'score' => 0, 'bingo' => false, 'note' => '', 'tiles' => '', 'at' => '', 'removed' => false], $turns[2]);
        self::assertSame([], ScoreRules::all(['turns' => 'broken']));
        self::assertSame(12, ScoreRules::totals($sheet)['total']);
    }

    public function test_the_sheet_is_found_by_the_id_of_a_turn(): void
    {
        $sheet = $this->sheet($this->turn('turn-0001'), $this->turn('turn-0002'));

        self::assertSame(1, ScoreRules::indexOf($sheet, 'turn-0002'));
        self::assertNull(ScoreRules::indexOf($sheet, 'turn-0003'));
        self::assertNull(ScoreRules::indexOf([], 'turn-0001'));
    }

    // ---- What a turn can be ---------------------------------------------------------------------------------------

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function validTurns(): array
    {
        return [
            'a word' => [['id' => 'turn-0001', 'kind' => 'word', 'word' => 'QUIZ', 'score' => 52]],
            'a word in lower case' => [['id' => 'turn-0001', 'kind' => 'word', 'word' => 'quiz', 'score' => 52]],
            'a word with no word' => [['id' => 'turn-0001', 'kind' => 'word', 'score' => 52]],
            'a word with an empty word' => [['id' => 'turn-0001', 'kind' => 'word', 'word' => '', 'score' => 52]],
            'the score as a string' => [['id' => 'turn-0001', 'kind' => 'word', 'word' => 'QUIZ', 'score' => '52']],
            'the smallest word' => [['id' => 'turn-0001', 'kind' => 'word', 'word' => 'AA', 'score' => 1]],
            'the biggest word' => [['id' => 'turn-0001', 'kind' => 'word', 'word' => 'ABCDEFGHIJKLMNO', 'score' => 999]],
            'a bingo' => [['id' => 'turn-0001', 'kind' => 'word', 'word' => 'RETAINS', 'score' => 68, 'bingo' => true]],
            'a bingo from a form' => [['id' => 'turn-0001', 'kind' => 'word', 'word' => 'RETAINS', 'score' => 68, 'bingo' => '1']],
            'letters with accents' => [['id' => 'turn-0001', 'kind' => 'word', 'word' => 'Zoë', 'score' => 15]],
            'a uuid for an id' => [['id' => '3f0e3b2a-9b0c-4c63-8f30-6f5a7f4d1c21', 'kind' => 'word', 'word' => 'AX', 'score' => 9]],
            'a pass' => [['id' => 'turn-0001', 'kind' => 'pass']],
            'a pass that says it scored nothing' => [['id' => 'turn-0001', 'kind' => 'pass', 'score' => 0]],
            'a pass that says it scored nothing as a string' => [['id' => 'turn-0001', 'kind' => 'pass', 'score' => '0', 'bingo' => false]],
            'tiles left' => [['id' => 'turn-0001', 'kind' => 'adjust', 'score' => -7, 'note' => 'Tiles left']],
            'going out' => [['id' => 'turn-0001', 'kind' => 'adjust', 'score' => 14, 'note' => 'Went out']],
            'an adjustment as a string' => [['id' => 'turn-0001', 'kind' => 'adjust', 'score' => '-7']],
            'the biggest adjustment' => [['id' => 'turn-0001', 'kind' => 'adjust', 'score' => -999]],
        ];
    }

    #[DataProvider('validTurns')]
    public function test_a_valid_turn_has_no_problem(array $input): void
    {
        self::assertNull(ScoreRules::problem($input));
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidTurns(): array
    {
        return [
            'no id' => [['kind' => 'word', 'word' => 'QUIZ', 'score' => 52], 'The turn needs an id'],
            'an id that is too short' => [['id' => 'abc', 'kind' => 'word', 'word' => 'QUIZ', 'score' => 52], 'The turn needs an id'],
            'an id with odd characters' => [['id' => 'turn 0001!', 'kind' => 'word', 'word' => 'QUIZ', 'score' => 52], 'The turn needs an id'],
            'an id that is not a string' => [['id' => 12345678, 'kind' => 'word', 'word' => 'QUIZ', 'score' => 52], 'The turn needs an id'],
            'no kind' => [['id' => 'turn-0001', 'word' => 'QUIZ', 'score' => 52], 'That is not a kind of turn'],
            'a kind that does not exist' => [['id' => 'turn-0001', 'kind' => 'challenge', 'score' => 5], 'That is not a kind of turn'],
            'a word with no score' => [['id' => 'turn-0001', 'kind' => 'word', 'word' => 'QUIZ'], 'The score has to be a whole number'],
            'a score that is text' => [['id' => 'turn-0001', 'kind' => 'word', 'word' => 'QUIZ', 'score' => 'lots'], 'The score has to be a whole number'],
            'a score with a decimal' => [['id' => 'turn-0001', 'kind' => 'word', 'word' => 'QUIZ', 'score' => 5.5], 'The score has to be a whole number'],
            'a score that is an array' => [['id' => 'turn-0001', 'kind' => 'word', 'word' => 'QUIZ', 'score' => [52]], 'The score has to be a whole number'],
            'a word that scores nothing' => [['id' => 'turn-0001', 'kind' => 'word', 'word' => 'QUIZ', 'score' => 0], 'A word scores between 1 and 999'],
            'a word that scores less than nothing' => [['id' => 'turn-0001', 'kind' => 'word', 'word' => 'QUIZ', 'score' => -4], 'A word scores between 1 and 999'],
            'a word that scores too much' => [['id' => 'turn-0001', 'kind' => 'word', 'word' => 'QUIZ', 'score' => 1000], 'A word scores between 1 and 999'],
            'a word with digits' => [['id' => 'turn-0001', 'kind' => 'word', 'word' => 'QU1Z', 'score' => 52], 'A word is letters only, no more than 15 of them'],
            'a word with a space' => [['id' => 'turn-0001', 'kind' => 'word', 'word' => 'QUI Z', 'score' => 52], 'A word is letters only, no more than 15 of them'],
            'a word with markup' => [['id' => 'turn-0001', 'kind' => 'word', 'word' => '<b>', 'score' => 52], 'A word is letters only, no more than 15 of them'],
            'a word that does not fit on the board' => [['id' => 'turn-0001', 'kind' => 'word', 'word' => 'ABCDEFGHIJKLMNOP', 'score' => 52], 'A word is letters only, no more than 15 of them'],
            'a word that is not a string' => [['id' => 'turn-0001', 'kind' => 'word', 'word' => ['QUIZ'], 'score' => 52], 'A word is letters only, no more than 15 of them'],
            'a bingo that is not yes or no' => [['id' => 'turn-0001', 'kind' => 'word', 'word' => 'QUIZ', 'score' => 52, 'bingo' => 'maybe'], 'Bingo has to be yes or no'],
            'a pass that scored' => [['id' => 'turn-0001', 'kind' => 'pass', 'score' => 5], 'A pass scores nothing'],
            'a pass that is a bingo' => [['id' => 'turn-0001', 'kind' => 'pass', 'bingo' => true], 'A pass scores nothing'],
            'a pass with a silly score' => [['id' => 'turn-0001', 'kind' => 'pass', 'score' => 'lots'], 'A pass scores nothing'],
            'an adjustment of nothing' => [['id' => 'turn-0001', 'kind' => 'adjust', 'score' => 0], 'An adjustment is more than 0 and no more than 999, up or down'],
            'an adjustment that is too big' => [['id' => 'turn-0001', 'kind' => 'adjust', 'score' => 1000], 'An adjustment is more than 0 and no more than 999, up or down'],
            'an adjustment that is too small' => [['id' => 'turn-0001', 'kind' => 'adjust', 'score' => -1000], 'An adjustment is more than 0 and no more than 999, up or down'],
            'an adjustment with no score' => [['id' => 'turn-0001', 'kind' => 'adjust'], 'The score has to be a whole number'],
            'an adjustment that is a bingo' => [['id' => 'turn-0001', 'kind' => 'adjust', 'score' => 5, 'bingo' => true], 'An adjustment is not a bingo'],
            'an adjustment with a long note' => [['id' => 'turn-0001', 'kind' => 'adjust', 'score' => 5, 'note' => str_repeat('a', 31)], 'The note can be no more than 30 characters'],
            'an adjustment with a note that is not text' => [['id' => 'turn-0001', 'kind' => 'adjust', 'score' => 5, 'note' => ['x']], 'The note can be no more than 30 characters'],
        ];
    }

    #[DataProvider('invalidTurns')]
    public function test_an_invalid_turn_says_why(array $input, string $problem): void
    {
        self::assertSame($problem, ScoreRules::problem($input));
    }

    public function test_a_word_turn_is_stored_in_full_with_the_word_in_capitals(): void
    {
        $turn = ScoreRules::fromInput(
            ['id' => 'turn-0001', 'kind' => 'word', 'word' => ' quiz ', 'score' => '52', 'bingo' => 'true', 'note' => 'ignored'],
            '2026-10-04T19:30:00Z'
        );

        self::assertSame(
            ['id' => 'turn-0001', 'kind' => 'word', 'word' => 'QUIZ', 'score' => 52, 'bingo' => true, 'note' => '', 'tiles' => '', 'at' => '2026-10-04T19:30:00Z', 'removed' => false],
            $turn
        );
    }

    public function test_a_pass_is_stored_with_nothing_of_a_word(): void
    {
        $turn = ScoreRules::fromInput(['id' => 'turn-0001', 'kind' => 'pass', 'word' => 'QUIZ', 'score' => 0, 'note' => 'x'], '2026-10-04T19:30:00Z');

        self::assertSame(
            ['id' => 'turn-0001', 'kind' => 'pass', 'word' => '', 'score' => 0, 'bingo' => false, 'note' => '', 'tiles' => '', 'at' => '2026-10-04T19:30:00Z', 'removed' => false],
            $turn
        );
    }

    public function test_an_adjustment_is_stored_with_its_note_and_sign(): void
    {
        $turn = ScoreRules::fromInput(['id' => 'turn-0001', 'kind' => 'adjust', 'word' => 'QUIZ', 'score' => '-7', 'note' => '  Tiles   left '], '2026-10-04T19:30:00Z');

        self::assertSame(
            ['id' => 'turn-0001', 'kind' => 'adjust', 'word' => '', 'score' => -7, 'bingo' => false, 'note' => 'Tiles   left', 'tiles' => '', 'at' => '2026-10-04T19:30:00Z', 'removed' => false],
            $turn
        );
    }

    // ---- Changing a sheet ------------------------------------------------------------------------------------------

    public function test_adding_a_turn_works_out_the_totals_again_and_leaves_the_sheet_alone(): void
    {
        $sheet = ScoreRules::emptySheet();
        $turn = ScoreRules::fromInput(['id' => 'turn-0001', 'kind' => 'word', 'word' => 'QUIZ', 'score' => 52, 'bingo' => true], '2026-10-04T19:30:00Z');

        $updated = ScoreRules::with($sheet, $turn);

        self::assertSame([], $sheet['turns']);
        self::assertCount(1, $updated['turns']);
        self::assertSame(102, $updated['score']['total']);
        self::assertSame(1, $updated['score']['bingos']);
    }

    public function test_adding_a_turn_keeps_the_rest_of_the_sheet(): void
    {
        $sheet = ScoreRules::emptySheet() + ['something' => 'else'];

        $updated = ScoreRules::with($sheet, ScoreRules::fromInput(['id' => 'turn-0001', 'kind' => 'pass'], '2026-10-04T19:30:00Z'));

        self::assertSame('else', $updated['something']);
    }

    public function test_a_changed_turn_keeps_its_place_and_when_it_was_played(): void
    {
        $sheet = ScoreRules::with(ScoreRules::emptySheet(), ScoreRules::fromInput(['id' => 'turn-0001', 'kind' => 'word', 'word' => 'QUIZ', 'score' => 25], '2026-10-04T19:30:00Z'));
        $sheet = ScoreRules::with($sheet, ScoreRules::fromInput(['id' => 'turn-0002', 'kind' => 'pass'], '2026-10-04T19:35:00Z'));

        $updated = ScoreRules::changed($sheet, 0, ScoreRules::fromInput(['id' => 'turn-0001', 'kind' => 'word', 'word' => 'QUIZ', 'score' => 52], '2030-01-01T00:00:00Z'));

        self::assertSame(52, $updated['turns'][0]['score']);
        self::assertSame('2026-10-04T19:30:00Z', $updated['turns'][0]['at']);
        self::assertSame('turn-0002', $updated['turns'][1]['id']);
        self::assertSame(52, $updated['score']['total']);
        self::assertSame(25, $sheet['score']['total']);
    }

    public function test_changing_a_word_to_a_pass_leaves_nothing_of_the_word_behind(): void
    {
        $sheet = ScoreRules::with(ScoreRules::emptySheet(), ScoreRules::fromInput(['id' => 'turn-0001', 'kind' => 'word', 'word' => 'QUIZ', 'score' => 52, 'bingo' => true], '2026-10-04T19:30:00Z'));

        $updated = ScoreRules::changed($sheet, 0, ScoreRules::fromInput(['id' => 'turn-0001', 'kind' => 'pass'], '2026-10-04T19:40:00Z'));

        self::assertSame(
            ['id' => 'turn-0001', 'kind' => 'pass', 'word' => '', 'score' => 0, 'bingo' => false, 'note' => '', 'tiles' => '', 'at' => '2026-10-04T19:30:00Z', 'removed' => false],
            $updated['turns'][0]
        );
        self::assertSame(0, $updated['score']['total']);
    }

    public function test_a_turn_is_removed_by_marking_it_never_by_taking_it_out(): void
    {
        $sheet = ScoreRules::with(ScoreRules::emptySheet(), ScoreRules::fromInput(['id' => 'turn-0001', 'kind' => 'word', 'word' => 'QUIZ', 'score' => 52], '2026-10-04T19:30:00Z'));

        $removed = ScoreRules::removal($sheet, 0, true);

        self::assertCount(1, $removed['turns']);
        self::assertTrue($removed['turns'][0]['removed']);
        self::assertSame(0, $removed['score']['total']);
        self::assertSame(0, $removed['score']['turns']);

        $restored = ScoreRules::removal($removed, 0, false);

        self::assertFalse($restored['turns'][0]['removed']);
        self::assertSame(52, $restored['score']['total']);
    }

    public function test_every_turn_on_a_sheet_is_always_written_with_the_same_keys(): void
    {
        $sheet = ScoreRules::with(ScoreRules::emptySheet(), ['id' => 'turn-0001', 'kind' => 'pass']);
        $keys = ['id', 'kind', 'word', 'score', 'bingo', 'note', 'tiles', 'at', 'removed'];

        self::assertSame($keys, array_keys($sheet['turns'][0]));
        self::assertSame($keys, array_keys(ScoreRules::changed($sheet, 0, ['id' => 'turn-0001', 'kind' => 'word', 'word' => 'AX', 'score' => 9])['turns'][0]));
        self::assertSame($keys, array_keys(ScoreRules::removal($sheet, 0, true)['turns'][0]));
    }

    public function test_the_same_play_ignores_the_id_the_time_and_whether_it_was_removed(): void
    {
        $a = $this->turn('turn-0001', 'word', 'QUIZ', 52);
        $b = ['id' => 'turn-0002', 'at' => 'later', 'removed' => true] + $a;

        self::assertTrue(ScoreRules::same($a, $b));
        self::assertFalse(ScoreRules::same($a, ['score' => 53] + $a));
        self::assertFalse(ScoreRules::same($a, ['word' => 'QUIT'] + $a));
        self::assertFalse(ScoreRules::same($a, ['bingo' => true] + $a));
        self::assertFalse(ScoreRules::same($a, ['kind' => 'pass'] + $a));
        self::assertFalse(ScoreRules::same($a, ['note' => 'x'] + $a));
    }

    // ---- The little parts -------------------------------------------------------------------------------------------

    /**
     * @return array<string, array{0: mixed, 1: ?string}>
     */
    public static function words(): array
    {
        return [
            'capitals' => ['QUIZ', 'QUIZ'],
            'lower case' => ['quiz', 'QUIZ'],
            'mixed case, a blank written in lower case' => ['QuIZ', 'QUIZ'],
            'around spaces' => ['  quiz  ', 'QUIZ'],
            'nothing' => ['', ''],
            'only spaces' => ['   ', ''],
            'accents' => ['zoë', 'ZOË'],
            'exactly fifteen' => ['abcdefghijklmno', 'ABCDEFGHIJKLMNO'],
            'sixteen' => ['abcdefghijklmnop', null],
            'a hyphen' => ['well-known', null],
            'an apostrophe' => ["don't", null],
            'a number' => ['abc1', null],
            'not a string' => [123, null],
            'null' => [null, null],
        ];
    }

    #[DataProvider('words')]
    public function test_a_word_is_letters_in_capitals(mixed $word, ?string $expected): void
    {
        self::assertSame($expected, ScoreRules::word($word));
    }

    public function test_a_note_is_trimmed_and_limited(): void
    {
        self::assertSame('', ScoreRules::note(null));
        self::assertSame('', ScoreRules::note('   '));
        self::assertSame('Tiles left', ScoreRules::note('  Tiles left '));
        self::assertSame('a b', ScoreRules::note("a\n\tb"));
        self::assertSame(str_repeat('a', 30), ScoreRules::note(str_repeat('a', 30)));
        self::assertNull(ScoreRules::note(str_repeat('a', 31)));
        self::assertNull(ScoreRules::note(42));
    }

    /**
     * @return array<string, array{0: mixed, 1: ?int}>
     */
    public static function integers(): array
    {
        return [
            'an int' => [52, 52],
            'a negative int' => [-7, -7],
            'digits' => ['52', 52],
            'negative digits' => ['-7', -7],
            'four digits' => ['9999', 9999],
            'five digits' => ['12345', null],
            'a decimal' => ['5.5', null],
            'a float' => [5.0, null],
            'text' => ['abc', null],
            'a plus sign' => ['+5', null],
            'null' => [null, null],
            'an empty string' => ['', null],
        ];
    }

    #[DataProvider('integers')]
    public function test_a_score_is_a_whole_number(mixed $value, ?int $expected): void
    {
        self::assertSame($expected, ScoreRules::integer($value));
    }

    public function test_yes_and_no_come_from_json_or_a_form(): void
    {
        foreach ([true, 1, '1', 'true'] as $yes) {
            self::assertTrue(ScoreRules::boolean($yes), var_export($yes, true));
        }

        foreach ([false, 0, '0', 'false', '', null] as $no) {
            self::assertFalse(ScoreRules::boolean($no), var_export($no, true));
        }

        foreach (['yes', 2, 'maybe', [], 1.0] as $neither) {
            self::assertNull(ScoreRules::boolean($neither), var_export($neither, true));
        }
    }

    public function test_a_turn_is_described_for_the_game_log(): void
    {
        self::assertSame('Played QUIZ for 52', ScoreRules::describe($this->turn('t', 'word', 'QUIZ', 52)));
        self::assertSame('Played RETAINS for 118, including the 50 point bingo', ScoreRules::describe($this->turn('t', 'word', 'RETAINS', 68, true)));
        self::assertSame('Scored 33', ScoreRules::describe($this->turn('t', 'word', '', 33)));
        self::assertSame('Passed their turn', ScoreRules::describe($this->turn('t', 'pass')));
        self::assertSame('Adjusted their score by -7 (Tiles left)', ScoreRules::describe($this->turn('t', 'adjust', '', -7, false, 'Tiles left')));
        self::assertSame('Adjusted their score by +14', ScoreRules::describe($this->turn('t', 'adjust', '', 14)));
    }

    public function test_points_are_signed_for_the_screen_and_the_log(): void
    {
        self::assertSame('+52', ScoreRules::signed(52));
        self::assertSame('+0', ScoreRules::signed(0));
        self::assertSame('-7', ScoreRules::signed(-7));
        self::assertSame("\u{2212}7", ScoreRules::signed(-7, true));
    }

    // The tiles of a word that was scored tile by tile

    public function test_every_letter_has_the_value_of_its_english_tile(): void
    {
        self::assertSame(range('A', 'Z'), array_keys(ScoreRules::TILE_VALUES));

        // The hundred English tiles (the blanks are worth nothing) are worth 187 points between them
        $tiles = ['A' => 9, 'B' => 2, 'C' => 2, 'D' => 4, 'E' => 12, 'F' => 2, 'G' => 3, 'H' => 2, 'I' => 9, 'J' => 1, 'K' => 1, 'L' => 4, 'M' => 2,
            'N' => 6, 'O' => 8, 'P' => 2, 'Q' => 1, 'R' => 6, 'S' => 4, 'T' => 6, 'U' => 4, 'V' => 2, 'W' => 2, 'X' => 1, 'Y' => 2, 'Z' => 1];
        $total = 0;
        foreach ($tiles as $letter => $count) {
            $total += $count * ScoreRules::TILE_VALUES[$letter];
        }

        self::assertSame(98, array_sum($tiles));
        self::assertSame(187, $total);

        $worth = array_unique(array_values(ScoreRules::TILE_VALUES));
        sort($worth);
        self::assertSame([1, 2, 3, 4, 5, 8, 10], $worth);
    }

    /**
     * @return array<string, array{0: string, 1: string}> the word and its tiles
     */
    public static function validTiles(): array
    {
        return [
            'a double letter and a double word' => ['QUIZ', 'tn-n-nDn'],
            'all seven tiles for a bingo' => ['RETAINS', '-n-n-n-n-n-n-n'],
            'a blank' => ['QUIZ', '-b-n-n-n'],
            'two tiles that were already on the board' => ['QUIZ', '-n-o-oDn'],
            'a blank that was already on the board' => ['AX', '-x-n'],
            'one new tile and the rest on the board' => ['QUIZ', '-o-o-o-n'],
            'a double and a triple word' => ['ZA', 'Dn' . 'Tn'],
            'the longest word, seven tiles from the rack' => ['ABCDEFGHIJKLMNO', str_repeat('-n', 7) . str_repeat('-o', 8)],
        ];
    }

    #[DataProvider('validTiles')]
    public function test_the_tiles_of_the_word_are_accepted(string $word, string $tiles): void
    {
        self::assertNull(ScoreRules::tilesProblem($word, $tiles));
        self::assertNull(ScoreRules::problem(['id' => 'turn-0001', 'kind' => 'word', 'word' => $word, 'score' => 52, 'tiles' => $tiles]));
    }

    /**
     * @return array<string, array{0: string, 1: mixed, 2: string}> the word, its tiles and what is said
     */
    public static function invalidTiles(): array
    {
        $letters = 'Scoring tile by tile is for a word of the letters A to Z';
        $match = 'There has to be a tile for each letter of the word';

        return [
            'a word with accents' => ['ZOË', 'tn-n-n', $letters],
            'no word at all' => ['', 'tn', $letters],
            'a word that is too long' => ['ABCDEFGHIJKLMNOP', str_repeat('-n', 16), $letters],
            'a tile too few' => ['QUIZ', '-n-n-n', $match],
            'a tile too many' => ['QUIZ', '-n-n-n-n-n', $match],
            'an odd number of characters' => ['QUIZ', '-n-n-n-', $match],
            'something that is not a square' => ['QUIZ', 'xn-n-n-n', $match],
            'something that is not a tile' => ['QUIZ', '-q-n-n-n', $match],
            'a square under a tile that was already on the board' => ['QUIZ', 'to-n-n-n', $match],
            'tiles that are not text' => ['QUIZ', ['-n', '-n', '-n', '-n'], $match],
            'a number' => ['QUIZ', 12345678, $match],
            'tiles with spaces' => ['QUIZ', '-n -n -n -n', $match],
            'every tile already on the board' => ['QUIZ', '-o-o-o-x', 'At least one tile has to be new'],
            'more tiles than the rack holds' => ['ABCDEFGH', str_repeat('-n', 8), 'A turn plays 7 tiles at most'],
        ];
    }

    #[DataProvider('invalidTiles')]
    public function test_tiles_that_are_not_the_tiles_of_the_word_say_why(string $word, mixed $tiles, string $problem): void
    {
        self::assertSame($problem, ScoreRules::tilesProblem($word, $tiles));
    }

    public function test_a_turn_with_tiles_that_are_not_its_own_is_refused_with_the_reason(): void
    {
        self::assertSame(
            'There has to be a tile for each letter of the word',
            ScoreRules::problem(['id' => 'turn-0001', 'kind' => 'word', 'word' => 'QUIZ', 'score' => 52, 'tiles' => '-n-n-n'])
        );
        self::assertSame(
            'At least one tile has to be new',
            ScoreRules::problem(['id' => 'turn-0001', 'kind' => 'word', 'word' => 'QUIZ', 'score' => 52, 'tiles' => '-o-o-o-o'])
        );
    }

    public function test_no_tiles_is_a_turn_that_was_typed_in(): void
    {
        foreach ([null, ''] as $tiles) {
            self::assertNull(ScoreRules::tilesProblem('QUIZ', $tiles));
        }

        self::assertNull(ScoreRules::problem(['id' => 'turn-0001', 'kind' => 'word', 'word' => 'QUIZ', 'score' => 52]));
        self::assertNull(ScoreRules::problem(['id' => 'turn-0001', 'kind' => 'word', 'word' => 'QUIZ', 'score' => 52, 'tiles' => null]));
    }

    public function test_the_score_is_checked_before_the_tiles(): void
    {
        self::assertSame(
            'A word scores between 1 and 999',
            ScoreRules::problem(['id' => 'turn-0001', 'kind' => 'word', 'word' => 'QUIZ', 'score' => 0, 'tiles' => 'nonsense'])
        );
    }

    public function test_the_tiles_are_stored_with_a_word_and_with_nothing_else(): void
    {
        $word = ScoreRules::fromInput(['id' => 'turn-0001', 'kind' => 'word', 'word' => 'quiz', 'score' => 84, 'tiles' => 'tn-n-nDn'], '2026-10-04T19:30:00Z');
        self::assertSame($this->turn('turn-0001', 'word', 'QUIZ', 84, false, '', false, 'tn-n-nDn'), $word);

        $typed = ScoreRules::fromInput(['id' => 'turn-0001', 'kind' => 'word', 'word' => 'quiz', 'score' => 84], '2026-10-04T19:30:00Z');
        self::assertSame('', $typed['tiles']);

        // A pass or an adjustment has no tiles, whatever was sent with it
        $pass = ScoreRules::fromInput(['id' => 'turn-0001', 'kind' => 'pass', 'tiles' => 'tn-n-nDn'], '2026-10-04T19:30:00Z');
        $adjustment = ScoreRules::fromInput(['id' => 'turn-0001', 'kind' => 'adjust', 'score' => -7, 'tiles' => 'tn-n-nDn'], '2026-10-04T19:30:00Z');
        self::assertSame('', $pass['tiles']);
        self::assertSame('', $adjustment['tiles']);
    }

    public function test_a_turn_is_always_read_with_its_tiles_as_text(): void
    {
        $turns = ScoreRules::all(['turns' => [
            ['id' => 'turn-0001', 'kind' => 'word', 'word' => 'QUIZ', 'score' => 84, 'tiles' => 'tn-n-nDn'],
            ['id' => 'turn-0002', 'kind' => 'word', 'word' => 'AX', 'score' => 9],
            ['id' => 'turn-0003', 'kind' => 'word', 'word' => 'AX', 'score' => 9, 'tiles' => ['-n', '-n']],
        ]]);

        self::assertSame(['tn-n-nDn', '', ''], array_column($turns, 'tiles'));
    }

    public function test_the_same_words_with_different_tiles_are_not_the_same_play(): void
    {
        $a = $this->turn('turn-0001', 'word', 'QUIZ', 84, false, '', false, 'tn-n-nDn');

        self::assertTrue(ScoreRules::same($a, ['at' => 'later', 'removed' => true] + $a));
        self::assertFalse(ScoreRules::same($a, ['tiles' => 'dn-n-nDn'] + $a));
        self::assertFalse(ScoreRules::same($a, ['tiles' => ''] + $a));
    }

    public function test_changing_the_tiles_of_a_turn_keeps_its_place_and_when_it_was_played(): void
    {
        $sheet = $this->sheet($this->turn('turn-0001', 'word', 'QUIZ', 52, false, '', false, 'tn-n-nDn'), $this->turn('turn-0002', 'word', 'FAX', 33));

        $changed = ScoreRules::changed($sheet, 0, ScoreRules::fromInput(['id' => 'turn-0001', 'kind' => 'word', 'word' => 'QUIZ', 'score' => 52], '2030-01-01T00:00:00Z'));

        self::assertSame('', $changed['turns'][0]['tiles']);
        self::assertSame('2026-10-04T19:30:00Z', $changed['turns'][0]['at']);
        self::assertSame(['turn-0001', 'turn-0002'], array_column($changed['turns'], 'id'));
    }

    public function test_the_limits_for_the_browser_are_the_servers_own(): void
    {
        self::assertSame(
            ['bingo' => 50, 'rack' => 7, 'tileValues' => ScoreRules::TILE_VALUES, 'maxWord' => 999, 'maxAdjustment' => 999, 'maxLetters' => 15, 'maxNote' => 30],
            ScoreRules::limits()
        );
    }
}
