<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Stats;
use PHPUnit\Framework\TestCase;

class StatsTest extends TestCase
{
    private function word(int $number, string $word, int $score, bool $bingo = false, bool $removed = false): array
    {
        return ['id' => 'turn-'.str_pad((string) $number, 4, '0', STR_PAD_LEFT), 'kind' => 'word', 'word' => $word, 'score' => $score, 'bingo' => $bingo, 'note' => '', 'at' => '2026-10-04T19:30:00Z', 'removed' => $removed];
    }

    private function pass(int $number): array
    {
        return ['id' => 'turn-'.str_pad((string) $number, 4, '0', STR_PAD_LEFT), 'kind' => 'pass', 'word' => '', 'score' => 0, 'bingo' => false, 'note' => '', 'at' => '2026-10-04T19:30:00Z', 'removed' => false];
    }

    private function adjust(int $number, int $score, string $note = ''): array
    {
        return ['id' => 'turn-'.str_pad((string) $number, 4, '0', STR_PAD_LEFT), 'kind' => 'adjust', 'word' => '', 'score' => $score, 'bingo' => false, 'note' => $note, 'at' => '2026-10-04T19:30:00Z', 'removed' => false];
    }

    public function test_a_players_numbers_come_from_their_sheet(): void
    {
        $stats = Stats::player(['turns' => [
            $this->word(1, 'QUIZ', 52),
            $this->word(2, 'RETAINS', 68, true),
            $this->pass(3),
            $this->word(4, 'AX', 9),
            $this->word(5, 'OXYGEN', 40, false, true),
            $this->adjust(6, -7, 'Tiles left'),
            $this->word(7, 'QUARTZ', 61),
        ]]);

        self::assertSame([
            'score' => 52 + 118 + 9 + 61 - 7,
            'turns' => 5,
            'words' => 4,
            'passes' => 1,
            'bingos' => 1,
            'word_points' => 52 + 118 + 9 + 61,
            'adjustment' => -7,
            'best' => ['word' => 'RETAINS', 'score' => 118, 'bingo' => true],
            'lowest' => ['word' => 'AX', 'score' => 9, 'bingo' => false],
            'longest' => ['word' => 'RETAINS', 'score' => 118, 'bingo' => true],
        ], $stats);
    }

    public function test_a_player_with_no_words_has_no_best_lowest_or_longest(): void
    {
        $stats = Stats::player(['turns' => [$this->pass(1), $this->adjust(2, -4)]]);

        self::assertNull($stats['best']);
        self::assertNull($stats['lowest']);
        self::assertNull($stats['longest']);
        self::assertSame(-4, $stats['score']);
        self::assertSame(1, $stats['turns']);
        self::assertSame(0, $stats['words']);

        self::assertSame(0, Stats::player([])['score']);
    }

    public function test_the_earlier_play_wins_a_tie_for_the_best_and_the_lowest(): void
    {
        $stats = Stats::player(['turns' => [
            $this->word(1, 'FAX', 33),
            $this->word(2, 'JAZ', 33),
        ]]);

        self::assertSame('FAX', $stats['best']['word']);
        self::assertSame('FAX', $stats['lowest']['word']);
    }

    public function test_the_longest_word_is_the_one_with_the_most_letters_and_then_the_most_points(): void
    {
        $stats = Stats::player(['turns' => [
            $this->word(1, 'QUIZ', 50),
            $this->word(2, 'JAZZ', 60),
            $this->word(3, 'MAYBE', 20),
            $this->word(4, 'WORLD', 30),
            $this->word(5, '', 99),
        ]]);

        self::assertSame(['word' => 'WORLD', 'score' => 30, 'bingo' => false], $stats['longest']);
        // A play with no word is still the best play
        self::assertSame(['word' => '', 'score' => 99, 'bingo' => false], $stats['best']);
    }

    public function test_what_is_stored_with_a_finished_game_says_who_the_numbers_are_for(): void
    {
        $entry = Stats::entry('p-1', 'Ada', ['turns' => [$this->word(1, 'QUIZ', 52)]]);

        self::assertSame('p-1', $entry['player_id']);
        self::assertSame('Ada', $entry['player_name']);
        self::assertSame(52, $entry['score']);
        self::assertSame(['player_id', 'player_name', 'score', 'turns', 'words', 'passes', 'bingos', 'word_points', 'adjustment', 'best', 'lowest', 'longest'], array_keys($entry));
    }

    /**
     * Two finished games and one that was stored without the players' numbers
     *
     * @return list<array<string, mixed>>
     */
    private function games(): array
    {
        $ada = fn (int $score, array $extra = []) => $extra + [
            'player_id' => 'p-1', 'player_name' => 'Ada', 'score' => $score, 'turns' => 10, 'words' => 9, 'passes' => 1, 'bingos' => 1,
            'word_points' => 270, 'adjustment' => 0,
            'best' => ['word' => 'QUIZ', 'score' => 102, 'bingo' => true],
            'lowest' => ['word' => 'AX', 'score' => 9, 'bingo' => false],
            'longest' => ['word' => 'RETAINS', 'score' => 70, 'bingo' => true],
        ];
        $ben = fn (int $score, array $extra = []) => $extra + [
            'player_id' => 'p-2', 'player_name' => 'Ben', 'score' => $score, 'turns' => 10, 'words' => 10, 'passes' => 0, 'bingos' => 0,
            'word_points' => 250, 'adjustment' => 0,
            'best' => ['word' => 'JAZZ', 'score' => 44, 'bingo' => false],
            'lowest' => ['word' => 'ZA', 'score' => 11, 'bingo' => false],
            'longest' => ['word' => 'QUARTZ', 'score' => 61, 'bingo' => false],
        ];

        return [
            ['id' => 'g-3', 'created_at' => '2026-10-03 19:00:00', 'game' => ['scores' => [$ada(300), $ben(300, ['best' => ['word' => 'ZAX', 'score' => 40, 'bingo' => false]])]]],
            ['id' => 'g-2', 'created_at' => '2026-10-02 19:00:00', 'game' => ['scores' => [$ben(280), $ada(250, ['bingos' => 0, 'best' => ['word' => 'BOX', 'score' => 30, 'bingo' => false]])]]],
            ['id' => 'g-1', 'created_at' => '2026-10-01 19:00:00', 'game' => ['scores' => [
                ['player_id' => 'p-1', 'player_name' => 'Ada', 'score' => 150],
                ['player_id' => 'p-2', 'player_name' => 'Ben', 'score' => 90],
            ]]],
        ];
    }

    public function test_the_totals_across_games_only_use_the_games_that_kept_their_numbers(): void
    {
        $stats = Stats::overall($this->games());

        self::assertSame(3, $stats['games']);
        self::assertSame(40, $stats['turns']);
        self::assertSame(38, $stats['words']);
        self::assertSame(1, $stats['bingos']);
        self::assertSame(1040, $stats['word_points']);
        self::assertEqualsWithDelta(1040 / 38, $stats['average_word'], 0.0001);
    }

    public function test_the_records_name_who_set_them_and_in_which_game(): void
    {
        $stats = Stats::overall($this->games());

        self::assertSame(['word' => 'QUIZ', 'score' => 102, 'bingo' => true, 'player' => 'Ada', 'game' => 'g-3'], array_diff_key($stats['best_word'], ['started' => 1]));
        self::assertSame('2026-10-03', $stats['best_word']['started']->format('Y-m-d'));
        self::assertSame(['word' => 'AX', 'score' => 9, 'bingo' => false, 'player' => 'Ada', 'game' => 'g-3'], array_diff_key($stats['lowest_word'], ['started' => 1]));
        self::assertSame('RETAINS', $stats['longest_word']['word']);
        self::assertSame('Ada', $stats['longest_word']['player']);
        self::assertSame(['score' => 300, 'player' => 'Ada', 'game' => 'g-3'], array_diff_key($stats['best_game'], ['started' => 1]));
        self::assertSame(['count' => 1, 'player' => 'Ada', 'game' => 'g-3'], array_diff_key($stats['most_bingos'], ['started' => 1]));
    }

    public function test_the_earlier_record_in_the_list_keeps_a_tied_record(): void
    {
        // The list is newest first, so a tie keeps the newer game's record
        $stats = Stats::overall($this->games());

        self::assertSame('g-3', $stats['best_game']['game']);
        self::assertSame('g-3', $stats['lowest_word']['game']);
    }

    public function test_the_closest_and_biggest_wins_and_the_lowest_winning_score(): void
    {
        $stats = Stats::overall($this->games());

        // g-3 was a tie, nothing separated them
        self::assertSame(0, $stats['closest_game']['margin']);
        self::assertSame('Ada and Ben', $stats['closest_game']['winner']);
        self::assertSame('g-3', $stats['closest_game']['game']);

        self::assertSame(60, $stats['biggest_win']['margin']);
        self::assertSame('Ada', $stats['biggest_win']['winner']);
        self::assertSame('Ben', $stats['biggest_win']['runner_up']);
        self::assertSame(90, $stats['biggest_win']['runner_up_score']);
        self::assertSame('g-1', $stats['biggest_win']['game']);

        // The game Ada won with 150 had the lowest winning score
        self::assertSame(150, $stats['lowest_win']['score']);
        self::assertSame('Ada', $stats['lowest_win']['player']);
        self::assertSame('g-1', $stats['lowest_win']['game']);
    }

    public function test_the_players_are_ranked_by_wins_then_average_score_and_a_tie_is_a_win_for_both(): void
    {
        $players = Stats::overall($this->games())['players'];

        self::assertSame(['Ada', 'Ben'], array_column($players, 'name'));

        // Ada won g-3 (a tie) and g-1, Ben won g-3 (a tie) and g-2
        self::assertSame(2, $players[0]['wins']);
        self::assertSame(3, $players[0]['games']);
        self::assertEqualsWithDelta(2 / 3, $players[0]['win_rate'], 0.0001);
        self::assertEqualsWithDelta((300 + 250 + 150) / 3, $players[0]['average'], 0.0001);
        self::assertSame(300, $players[0]['best_game']);
        self::assertSame('QUIZ', $players[0]['best_word']['word']);
        self::assertSame(1, $players[0]['bingos']);
        self::assertSame(18, $players[0]['words']);
        self::assertEqualsWithDelta(540 / 18, $players[0]['average_word'], 0.0001);

        self::assertSame(2, $players[1]['wins']);
        self::assertSame(20, $players[1]['words']);
        self::assertSame(0, $players[1]['bingos']);
    }

    public function test_a_player_with_no_words_has_no_average_word(): void
    {
        $players = Stats::overall($this->games())['players'];
        $old = Stats::overall([$this->games()[2]])['players'];

        self::assertNotNull($players[0]['average_word']);
        self::assertNull($old[0]['average_word']);
        self::assertSame(0, $old[0]['words']);
    }

    public function test_one_game_is_its_own_highlights(): void
    {
        $stats = Stats::overall([$this->games()[1]]);

        self::assertSame(1, $stats['games']);
        self::assertSame('Ben', $stats['best_game']['player']);
        self::assertSame('JAZZ', $stats['best_word']['word']);
        self::assertSame('Ben', $stats['best_word']['player']);
        self::assertSame(30, $stats['biggest_win']['margin']);
        self::assertSame(30, $stats['closest_game']['margin']);
    }

    public function test_a_player_on_their_own_has_no_margin(): void
    {
        $stats = Stats::overall([['id' => 'g-1', 'game' => ['scores' => [['player_id' => 'p-1', 'player_name' => 'Ada', 'score' => 120]]]]]);

        self::assertSame(1, $stats['games']);
        self::assertNull($stats['biggest_win']);
        self::assertNull($stats['closest_game']);
        self::assertSame(120, $stats['lowest_win']['score']);
        self::assertSame(1, $stats['players'][0]['wins']);
    }

    public function test_no_games_means_no_stats(): void
    {
        foreach ([[], [['id' => 'g-1', 'game' => null]], [['id' => 'g-1', 'game' => ['scores' => []]]], [['id' => 'g-1']], [['id' => 'g-1', 'game' => ['scores' => ['nonsense']]]]] as $games) {
            $stats = Stats::overall($games);

            self::assertSame(0, $stats['games']);
            self::assertSame(0, $stats['words']);
            self::assertNull($stats['average_word']);
            self::assertNull($stats['best_word']);
            self::assertNull($stats['best_game']);
            self::assertNull($stats['lowest_win']);
            self::assertSame([], $stats['players']);
        }
    }
}
