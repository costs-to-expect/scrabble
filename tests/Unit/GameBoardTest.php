<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\GameBoard;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class GameBoardTest extends TestCase
{
    private function players(string ...$names): array
    {
        $players = [];
        foreach ($names as $position => $name) {
            $players[] = ['id' => 'p-'.($position + 1), 'name' => $name];
        }

        return $players;
    }

    /**
     * A sheet of words, each word is [word, score] or [word, score, bingo]
     */
    private function sheet(array ...$words): array
    {
        $turns = [];
        foreach ($words as $position => $word) {
            $turns[] = [
                'id' => 'turn-'.str_pad((string) ($position + 1), 4, '0', STR_PAD_LEFT),
                'kind' => 'word', 'word' => $word[0], 'score' => $word[1], 'bingo' => $word[2] ?? false,
                'note' => '', 'at' => '2026-10-04T19:30:00Z', 'removed' => false,
            ];
        }

        return ['turns' => $turns];
    }

    private function pass(): array
    {
        return ['id' => 'turn-pass-1', 'kind' => 'pass', 'word' => '', 'score' => 0, 'bingo' => false, 'note' => '', 'at' => '2026-10-04T19:30:00Z', 'removed' => false];
    }

    public function test_a_player_keeps_the_colour_of_their_place_in_the_players_list_and_the_colours_start_again(): void
    {
        $tones = GameBoard::tones($this->players('A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'));

        self::assertSame(['p-1' => 0, 'p-2' => 1, 'p-3' => 2, 'p-4' => 3, 'p-5' => 4, 'p-6' => 5, 'p-7' => 0, 'p-8' => 1], $tones);
        self::assertSame([], GameBoard::tones([]));
    }

    public function test_the_standings_keep_the_order_the_players_take_their_turns_in(): void
    {
        $standings = GameBoard::standings(
            $this->players('Ada', 'Ben', 'Cleo'),
            ['p-1' => $this->sheet(['AX', 9]), 'p-2' => $this->sheet(['QUIZ', 52]), 'p-3' => $this->sheet(['FAX', 33])],
            ['p-1' => 0, 'p-2' => 1, 'p-3' => 2]
        );

        self::assertSame(['Ada', 'Ben', 'Cleo'], array_column($standings, 'name'));
        self::assertSame([9, 52, 33], array_column($standings, 'score'));
        self::assertSame([1, 1, 1], array_column($standings, 'turns'));
        self::assertSame([0, 1, 2], array_column($standings, 'tone'));
        self::assertSame('QUIZ', $standings[1]['last']['word']);
    }

    public function test_the_players_can_be_ranked_best_score_first_and_a_tie_keeps_the_order_they_play_in(): void
    {
        $standings = GameBoard::standings(
            $this->players('Ada', 'Ben', 'Cleo'),
            ['p-1' => $this->sheet(['AX', 50]), 'p-2' => $this->sheet(['QUIZ', 80]), 'p-3' => $this->sheet(['FAX', 50])],
            []
        );

        self::assertSame(['Ben', 'Ada', 'Cleo'], array_column(GameBoard::ranked($standings), 'name'));
        self::assertSame(['Ada', 'Ben', 'Cleo'], array_column($standings, 'name'));
    }

    public function test_a_player_with_no_score_sheet_yet_is_on_zero_with_no_turns(): void
    {
        $standings = GameBoard::standings($this->players('Ada', 'Ben'), ['p-1' => $this->sheet(['AX', 9])], []);

        self::assertSame(
            ['id' => 'p-2', 'name' => 'Ben', 'tone' => 0, 'score' => 0, 'turns' => 0, 'last' => null, 'leader' => false, 'next' => true],
            $standings[1]
        );
    }

    public function test_the_leader_is_crowned_only_when_someone_has_scored_and_someone_else_has_scored_less(): void
    {
        $leaders = fn (array $scores) => array_column(
            GameBoard::standings(
                $this->players('Ada', 'Ben', 'Cleo'),
                array_map(static fn (int $score): array => ['turns' => $score === 0 ? [] : [['id' => 'turn-0001', 'kind' => 'word', 'word' => '', 'score' => $score]]], $scores),
                []
            ),
            'leader'
        );

        self::assertSame([true, false, false], $leaders(['p-1' => 30, 'p-2' => 20, 'p-3' => 0]));
        // Nobody has scored yet
        self::assertSame([false, false, false], $leaders(['p-1' => 0, 'p-2' => 0, 'p-3' => 0]));
        // All level
        self::assertSame([false, false, false], $leaders(['p-1' => 20, 'p-2' => 20, 'p-3' => 20]));
        // Two share the lead, both are crowned
        self::assertSame([true, true, false], $leaders(['p-1' => 40, 'p-2' => 40, 'p-3' => 10]));
    }

    public function test_a_player_on_their_own_is_never_crowned(): void
    {
        $standings = GameBoard::standings($this->players('Ada'), ['p-1' => $this->sheet(['QUIZ', 100])], []);

        self::assertFalse($standings[0]['leader']);
        self::assertTrue($standings[0]['next']);
    }

    public function test_the_player_with_the_fewest_turns_is_next_and_the_first_of_them_when_several_have(): void
    {
        $next = fn (array $sheets) => array_column(
            array_filter(GameBoard::standings($this->players('Ada', 'Ben', 'Cleo'), $sheets, []), static fn (array $standing): bool => $standing['next']),
            'name'
        );

        // Nobody has played, the first player starts
        self::assertSame(['Ada'], array_values($next([])));
        // Ada has played, so Ben is next, then Cleo, then it is Ada again
        self::assertSame(['Ben'], array_values($next(['p-1' => $this->sheet(['AX', 9])])));
        self::assertSame(['Cleo'], array_values($next(['p-1' => $this->sheet(['AX', 9]), 'p-2' => $this->sheet(['QUIZ', 52])])));
        self::assertSame(['Ada'], array_values($next(['p-1' => $this->sheet(['AX', 9]), 'p-2' => $this->sheet(['QUIZ', 52]), 'p-3' => $this->sheet(['FAX', 33])])));
        // The scorer entered Cleo's turn first, Ada is still the first of the players who are due
        self::assertSame(['Ada'], array_values($next(['p-3' => $this->sheet(['FAX', 33])])));
    }

    public function test_a_pass_is_a_turn_so_it_moves_the_game_on_and_an_adjustment_is_not(): void
    {
        $standings = GameBoard::standings(
            $this->players('Ada', 'Ben'),
            ['p-1' => ['turns' => [$this->pass()]], 'p-2' => ['turns' => [['id' => 'turn-adj-01', 'kind' => 'adjust', 'word' => '', 'score' => -5, 'note' => 'Tiles left']]]],
            []
        );

        self::assertSame('Ben', GameBoard::next($standings)['name']);
        self::assertSame(-5, $standings[1]['score']);
        self::assertSame(0, $standings[1]['turns']);
    }

    public function test_nobody_is_next_when_there_are_no_players(): void
    {
        self::assertSame([], GameBoard::standings([], [], []));
        self::assertNull(GameBoard::next([]));
    }

    public function test_the_last_play_is_a_line_for_under_a_name(): void
    {
        $turn = static fn (array $overrides): array => $overrides + ['kind' => 'word', 'word' => '', 'score' => 0, 'bingo' => false, 'note' => ''];

        self::assertNull(GameBoard::play(null));
        self::assertSame('QUIZ +52', GameBoard::play($turn(['word' => 'QUIZ', 'score' => 52])));
        self::assertSame('RETAINS +118', GameBoard::play($turn(['word' => 'RETAINS', 'score' => 68, 'bingo' => true])));
        self::assertSame('+33', GameBoard::play($turn(['score' => 33])));
        self::assertSame('Passed', GameBoard::play($turn(['kind' => 'pass'])));
        self::assertSame("Tiles left \u{2212}7", GameBoard::play($turn(['kind' => 'adjust', 'score' => -7, 'note' => 'Tiles left'])));
        self::assertSame('Adjustment +12', GameBoard::play($turn(['kind' => 'adjust', 'score' => 12])));
    }

    public function test_how_a_game_ended_names_the_winner_and_what_everyone_else_scored(): void
    {
        $result = GameBoard::result(['game' => ['scores' => [
            ['player_id' => 'p-1', 'player_name' => 'Ada', 'score' => 345],
            ['player_id' => 'p-2', 'player_name' => 'Ben', 'score' => 310],
            ['player_id' => 'p-3', 'player_name' => 'Cleo', 'score' => 298],
        ]]]);

        self::assertSame(['winner' => 'Ada', 'tied' => false, 'score' => 345, 'others' => 'Ben 310 · Cleo 298'], $result);
    }

    public function test_a_game_that_ended_level_is_a_tie_between_everyone_on_the_top_score(): void
    {
        $result = GameBoard::result(['game' => ['scores' => [
            ['player_id' => 'p-1', 'player_name' => 'Ada', 'score' => 300],
            ['player_id' => 'p-2', 'player_name' => 'Ben', 'score' => 300],
            ['player_id' => 'p-3', 'player_name' => 'Cleo', 'score' => 120],
        ]]]);

        self::assertSame(['winner' => 'Ada and Ben', 'tied' => true, 'score' => 300, 'others' => 'Cleo 120'], $result);
    }

    public function test_a_game_with_no_scores_has_no_result(): void
    {
        self::assertNull(GameBoard::result([]));
        self::assertNull(GameBoard::result(['game' => null]));
        self::assertNull(GameBoard::result(['game' => ['scores' => []]]));
    }

    public function test_names_are_listed_in_words_and_with_ampersands(): void
    {
        self::assertSame('', GameBoard::names([]));
        self::assertSame('Ada', GameBoard::names(['Ada']));
        self::assertSame('Ada and Ben', GameBoard::names(['Ada', 'Ben']));
        self::assertSame('Ada, Ben and Cleo', GameBoard::names(['Ada', 'Ben', 'Cleo']));

        self::assertSame('', GameBoard::ampersands([]));
        self::assertSame('Ada', GameBoard::ampersands(['Ada']));
        self::assertSame('Ada & Ben', GameBoard::ampersands(['Ada', 'Ben']));
        self::assertSame('Ada, Ben & Cleo', GameBoard::ampersands(['Ada', 'Ben', 'Cleo']));
    }

    public function test_the_start_of_a_game_is_read_from_created_at_or_created_and_is_null_when_the_api_says_nothing(): void
    {
        self::assertSame('2026-10-02 18:30:00', GameBoard::startedAt(['created_at' => '2026-10-02 18:30:00'])?->format('Y-m-d H:i:s'));
        self::assertSame('2026-10-02 18:30:00', GameBoard::startedAt(['created' => '2026-10-02 18:30:00'])?->format('Y-m-d H:i:s'));
        self::assertNull(GameBoard::startedAt([]));
        self::assertNull(GameBoard::startedAt(['created_at' => '']));
        self::assertNull(GameBoard::startedAt(['created_at' => 12345]));
        self::assertNull(GameBoard::startedAt(['created_at' => 'not a date']));
    }

    public function test_when_a_game_was_played_is_in_words(): void
    {
        $now = CarbonImmutable::parse('2026-10-03 21:00:00');

        self::assertNull(GameBoard::when(null, $now));
        self::assertSame('Today', GameBoard::when(CarbonImmutable::parse('2026-10-03 00:05:00'), $now));
        self::assertSame('Today', GameBoard::when(CarbonImmutable::parse('2026-10-03 20:59:00'), $now));
        self::assertSame('Yesterday', GameBoard::when(CarbonImmutable::parse('2026-10-02 23:59:00'), $now));
        self::assertSame('Wednesday', GameBoard::when(CarbonImmutable::parse('2026-09-30 19:00:00'), $now));
        self::assertSame('Sunday', GameBoard::when(CarbonImmutable::parse('2026-09-27 19:00:00'), $now));
        self::assertSame('26 Sep', GameBoard::when(CarbonImmutable::parse('2026-09-26 19:00:00'), $now));
        self::assertSame('3 Oct 2025', GameBoard::when(CarbonImmutable::parse('2025-10-03 19:00:00'), $now));
    }

    public function test_when_a_game_was_played_reads_in_a_sentence(): void
    {
        self::assertNull(GameBoard::playedOn(null));
        self::assertSame('today', GameBoard::playedOn('Today'));
        self::assertSame('yesterday', GameBoard::playedOn('Yesterday'));
        self::assertSame('on Saturday', GameBoard::playedOn('Saturday'));
        self::assertSame('on 12 Oct', GameBoard::playedOn('12 Oct'));
    }

    public function test_how_long_a_game_has_been_running_is_short_and_never_negative(): void
    {
        $now = CarbonImmutable::parse('2026-10-03 21:00:00');

        self::assertNull(GameBoard::since(null, $now));
        self::assertSame('40m', GameBoard::since($now->subMinutes(40), $now));
        self::assertSame('1h', GameBoard::since($now->subMinutes(75), $now));
        self::assertSame('2d', GameBoard::since($now->subDays(2), $now));
        // A clock that is a little out must not say "-3m"
        self::assertNull(GameBoard::since($now->addMinutes(3), $now));
    }
}
