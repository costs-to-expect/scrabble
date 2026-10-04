<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ScoreRules;
use PHPUnit\Framework\TestCase;

/**
 * A word can be scored tile by tile, the tiles are added up in the browser (public/js/tiles.js) and the turn is stored with
 * the score and the tiles. PHPUnit cannot call the script, so this runs it under Node, which is on a GitHub Actions
 * runner and in most development setups, and skips when it is not there.
 *
 * The expected scores are worked out by hand from the rules of the game: a tile is worth its letter, a double or triple
 * letter square multiplies that tile, a double or triple word square multiplies the whole word (two of them multiply each
 * other), a blank is worth nothing, a tile that was already on the board counts for what it is worth and whatever square
 * it sits on does not, and seven new tiles are a bingo.
 */
class TileScoringTest extends TestCase
{
    private string $node = '';

    protected function setUp(): void
    {
        $this->node = trim((string) @shell_exec('command -v node 2>/dev/null'));

        if ($this->node === '') {
            self::markTestSkipped('Node is not installed, public/js/tiles.js is not run');
        }
    }

    /**
     * @param list<array<string, mixed>> $vectors
     * @param list<array{text: string, length: int}> $strings
     * @return array{scored: list<array<string, mixed>>, decoded: list<bool>}
     */
    private function runScript(array $vectors, array $strings = []): array
    {
        $process = proc_open(
            [$this->node, dirname(__DIR__) . '/Support/tiles-runner.js'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);

        fwrite($pipes[0], json_encode([
            'values' => ScoreRules::TILE_VALUES,
            'bingo' => ScoreRules::BINGO_BONUS,
            'maxWord' => ScoreRules::MAX_WORD_SCORE,
            'vectors' => $vectors,
            'strings' => $strings,
        ], JSON_THROW_ON_ERROR));
        fclose($pipes[0]);

        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        self::assertSame(0, $status, 'The script failed: ' . $errors);

        return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, array<string, mixed>> what to score and what it comes to
     */
    private function vectors(): array
    {
        $none = ['problem' => null];

        return [
            'a triple letter and a double word' => [
                'word' => 'QUIZ', 'tiles' => 'tn-n-nDn', 'extra' => 0,
                'expect' => ['points' => [30, 1, 1, 10], 'sum' => 42, 'multiplier' => 2, 'main' => 84, 'extra' => 0, 'score' => 84, 'placed' => 4, 'bingo' => false, 'total' => 84] + $none,
            ],
            'seven new tiles are a bingo, on a double word' => [
                'word' => 'RETAINS', 'tiles' => '-n-n-n-n-n-nDn', 'extra' => 0,
                'expect' => ['points' => [1, 1, 1, 1, 1, 1, 1], 'sum' => 7, 'multiplier' => 2, 'main' => 14, 'extra' => 0, 'score' => 14, 'placed' => 7, 'bingo' => true, 'total' => 64] + $none,
            ],
            'a bingo and the points of other words' => [
                'word' => 'RETAINS', 'tiles' => '-n-n-n-n-n-n-n', 'extra' => 6,
                'expect' => ['points' => [1, 1, 1, 1, 1, 1, 1], 'sum' => 7, 'multiplier' => 1, 'main' => 7, 'extra' => 6, 'score' => 13, 'placed' => 7, 'bingo' => true, 'total' => 63] + $none,
            ],
            'six new tiles are not a bingo' => [
                'word' => 'RETAINS', 'tiles' => '-n-n-n-n-n-n-o', 'extra' => 0,
                'expect' => ['points' => [1, 1, 1, 1, 1, 1, 1], 'sum' => 7, 'multiplier' => 1, 'main' => 7, 'extra' => 0, 'score' => 7, 'placed' => 6, 'bingo' => false, 'total' => 7] + $none,
            ],
            'a blank is worth nothing' => [
                'word' => 'QUIZ', 'tiles' => '-b-n-n-n', 'extra' => 0,
                'expect' => ['points' => [0, 1, 1, 10], 'sum' => 12, 'multiplier' => 1, 'main' => 12, 'extra' => 0, 'score' => 12, 'placed' => 4, 'bingo' => false, 'total' => 12] + $none,
            ],
            'a blank on a triple letter is still nothing' => [
                'word' => 'QUIZ', 'tiles' => 'tb-n-n-n', 'extra' => 0,
                'expect' => ['points' => [0, 1, 1, 10], 'sum' => 12, 'multiplier' => 1, 'main' => 12, 'extra' => 0, 'score' => 12, 'placed' => 4, 'bingo' => false, 'total' => 12] + $none,
            ],
            'a blank on a triple word still triples the word' => [
                'word' => 'QUIZ', 'tiles' => 'Tb-n-n-n', 'extra' => 0,
                'expect' => ['points' => [0, 1, 1, 10], 'sum' => 12, 'multiplier' => 3, 'main' => 36, 'extra' => 0, 'score' => 36, 'placed' => 4, 'bingo' => false, 'total' => 36] + $none,
            ],
            'tiles that were already on the board count for what they are worth' => [
                'word' => 'QUIZ', 'tiles' => '-n-o-oDn', 'extra' => 0,
                'expect' => ['points' => [10, 1, 1, 10], 'sum' => 22, 'multiplier' => 2, 'main' => 44, 'extra' => 0, 'score' => 44, 'placed' => 2, 'bingo' => false, 'total' => 44] + $none,
            ],
            'a blank that was already on the board' => [
                'word' => 'AX', 'tiles' => '-x-n', 'extra' => 0,
                'expect' => ['points' => [0, 8], 'sum' => 8, 'multiplier' => 1, 'main' => 8, 'extra' => 0, 'score' => 8, 'placed' => 1, 'bingo' => false, 'total' => 8] + $none,
            ],
            'a double and a triple word multiply each other' => [
                'word' => 'ZA', 'tiles' => 'DnTn', 'extra' => 0,
                'expect' => ['points' => [10, 1], 'sum' => 11, 'multiplier' => 6, 'main' => 66, 'extra' => 0, 'score' => 66, 'placed' => 2, 'bingo' => false, 'total' => 66] + $none,
            ],
            'two triple words' => [
                'word' => 'AB', 'tiles' => 'TnTn', 'extra' => 0,
                'expect' => ['points' => [1, 3], 'sum' => 4, 'multiplier' => 9, 'main' => 36, 'extra' => 0, 'score' => 36, 'placed' => 2, 'bingo' => false, 'total' => 36] + $none,
            ],
            'the points of other words are not multiplied' => [
                'word' => 'QUIZ', 'tiles' => 'Dn-n-n-n', 'extra' => 6,
                'expect' => ['points' => [10, 1, 1, 10], 'sum' => 22, 'multiplier' => 2, 'main' => 44, 'extra' => 6, 'score' => 50, 'placed' => 4, 'bingo' => false, 'total' => 50] + $none,
            ],
            'the square under a tile that was already on the board does not count' => [
                'word' => 'QUIZ', 'settings' => [
                    ['square' => 't', 'blank' => false, 'board' => true],
                    ['square' => 'D', 'blank' => false, 'board' => false],
                    ['square' => '-', 'blank' => false, 'board' => false],
                    ['square' => '-', 'blank' => false, 'board' => false],
                ], 'extra' => 0,
                'expect' => ['points' => [10, 1, 1, 10], 'sum' => 22, 'multiplier' => 2, 'main' => 44, 'extra' => 0, 'score' => 44, 'placed' => 3, 'bingo' => false, 'total' => 44] + $none,
            ],
            'a tile nobody said anything about is a new tile on a plain square' => [
                'word' => 'QUIZ', 'settings' => [], 'extra' => 0,
                'expect' => ['points' => [10, 1, 1, 10], 'sum' => 22, 'multiplier' => 1, 'main' => 22, 'extra' => 0, 'score' => 22, 'placed' => 4, 'bingo' => false, 'total' => 22] + $none,
            ],
            'the longest word with seven new tiles' => [
                'word' => 'OXYPHENBUTAZONE', 'tiles' => str_repeat('-n', 7) . str_repeat('-o', 8), 'extra' => 0,
                'expect' => ['points' => [1, 8, 4, 3, 4, 1, 1, 3, 1, 1, 1, 10, 1, 1, 1], 'sum' => 41, 'multiplier' => 1, 'main' => 41, 'extra' => 0, 'score' => 41, 'placed' => 7, 'bingo' => true, 'total' => 91] + $none,
            ],
            'no new tile' => [
                'word' => 'QUIZ', 'tiles' => '-o-o-o-x', 'extra' => 0,
                'expect' => ['points' => [10, 1, 1, 0], 'sum' => 12, 'multiplier' => 1, 'main' => 12, 'extra' => 0, 'score' => 12, 'placed' => 0, 'bingo' => false, 'total' => 12, 'problem' => 'At least one tile has to be new'],
            ],
            'more new tiles than the rack holds' => [
                'word' => 'ABCDEFGH', 'tiles' => str_repeat('-n', 8), 'extra' => 0,
                'expect' => ['points' => [1, 3, 3, 2, 1, 4, 2, 4], 'sum' => 20, 'multiplier' => 1, 'main' => 20, 'extra' => 0, 'score' => 20, 'placed' => 8, 'bingo' => false, 'total' => 20, 'problem' => 'Seven new tiles at most, mark the others as on the board'],
            ],
            'a word of blanks scores nothing' => [
                'word' => 'AB', 'tiles' => '-b-b', 'extra' => 0,
                'expect' => ['points' => [0, 0], 'sum' => 0, 'multiplier' => 1, 'main' => 0, 'extra' => 0, 'score' => 0, 'placed' => 2, 'bingo' => false, 'total' => 0, 'problem' => 'A word scores at least 1'],
            ],
            'a score the server would refuse as too high' => [
                'word' => 'QUIZ', 'tiles' => '-n-n-n-n', 'extra' => 990,
                'expect' => ['points' => [10, 1, 1, 10], 'sum' => 22, 'multiplier' => 1, 'main' => 22, 'extra' => 990, 'score' => 1012, 'placed' => 4, 'bingo' => false, 'total' => 1012, 'problem' => 'A word scores 999 at most'],
            ],
            'no word yet' => [
                'word' => '', 'settings' => [], 'extra' => 0,
                'expect' => ['points' => [], 'sum' => 0, 'multiplier' => 1, 'main' => 0, 'extra' => 0, 'score' => 0, 'placed' => 0, 'bingo' => false, 'total' => 0, 'problem' => 'Type the word to see its tiles'],
            ],
            'a letter that is not in the English set is worth nothing and the word is not allowed' => [
                'word' => 'ZOË', 'settings' => [], 'extra' => 0,
                'expect' => ['points' => [10, 1, 0], 'sum' => 11, 'multiplier' => 1, 'main' => 11, 'extra' => 0, 'score' => 11, 'placed' => 3, 'bingo' => false, 'total' => 11, 'problem' => 'Tile by tile is for a word of the letters A to Z'],
            ],
        ];
    }

    public function test_the_tiles_are_added_up_as_the_rules_of_the_game_say(): void
    {
        $vectors = $this->vectors();
        $names = array_keys($vectors);

        $results = $this->runScript(array_map(
            static fn (array $vector): array => array_diff_key($vector, ['expect' => true]),
            array_values($vectors)
        ))['scored'];

        foreach ($results as $position => $result) {
            $expected = $vectors[$names[$position]]['expect'];

            self::assertSame($expected['points'], $result['points'], $names[$position] . ': what each tile counts for');

            foreach (['sum', 'multiplier', 'main', 'extra', 'score', 'placed', 'bingo', 'total', 'problem'] as $key) {
                self::assertSame($expected[$key], $result[$key], $names[$position] . ': ' . $key);
            }
        }
    }

    public function test_what_is_stored_for_the_tiles_is_what_was_read(): void
    {
        $vectors = array_filter($this->vectors(), static fn (array $vector): bool => isset($vector['tiles']));
        $results = $this->runScript(array_values(array_map(static fn (array $vector): array => array_diff_key($vector, ['expect' => true]), $vectors)))['scored'];

        foreach (array_values($vectors) as $position => $vector) {
            self::assertSame($vector['tiles'], $results[$position]['encoded']);
        }
    }

    public function test_the_script_and_the_server_agree_on_what_the_stored_tiles_look_like(): void
    {
        $strings = [];

        // What the server accepts and refuses in its own tests
        foreach (['tn-n-nDn', '-n-n-n-n-n-n-n', '-b-n-n-n', '-n-o-oDn', '-x-n', 'DnTn', '-o-o-o-x', 'xn-n-n-n', '-q-n-n-n', 'to-n-n-n', '-n -n -n -n', '-n-n-n-', '', 'tn'] as $text) {
            $strings[] = ['text' => $text, 'length' => intdiv(strlen($text), 2)];
            $strings[] = ['text' => $text, 'length' => intdiv(strlen($text), 2) + 1];
        }

        // And a lot of text that is nearly right, the same every time
        mt_srand(20261004);
        $alphabet = str_split('-dtDTnbox q');
        for ($i = 0; $i < 600; $i++) {
            $length = mt_rand(0, 34);
            $text = '';
            for ($j = 0; $j < $length; $j++) {
                $text .= $alphabet[mt_rand(0, count($alphabet) - 1)];
            }
            $strings[] = ['text' => $text, 'length' => intdiv($length, 2)];
        }

        $decoded = $this->runScript([], $strings)['decoded'];

        foreach ($strings as $position => $entry) {
            $server = preg_match(ScoreRules::TILES_PATTERN, $entry['text']) === 1 && strlen($entry['text']) === 2 * $entry['length'];

            self::assertSame($server, $decoded[$position], 'The script and the server disagree about ' . json_encode($entry));
        }
    }
}
