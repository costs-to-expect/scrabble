<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\FakesTheApi;
use Tests\TestCase;

/**
 * The stats are worked out from the finished games alone: each one keeps every player's numbers (see Complete), so
 * the page reads the list of games, a hundred at a time, and never a score sheet.
 */
class StatsPageTest extends TestCase
{
    use FakesTheApi;
    use RefreshDatabase;

    private function finished(string $id, array $ada, array $ben): array
    {
        $scores = [$this->finishedScore('p-1', 'Ada', $ada), $this->finishedScore('p-2', 'Ben', $ben)];
        usort($scores, fn (array $a, array $b) => $b['score'] <=> $a['score']);

        return $this->game($id, ['p-1' => 'Ada', 'p-2' => 'Ben'], true, $scores) + ['created_at' => '2026-09-27 19:00:00'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function threeGames(): array
    {
        return [
            // Ben wins by 33 with the only bingo
            $this->finished('g-1', [$this->word('QUIZ', 52), $this->word('FAX', 33)], [$this->word('RETAINS', 68, true)]),
            // Ada wins by 121, with the longest word and the highest word, Ben's ZA is the lowest
            $this->finished('g-2', [$this->word('AX', 11), $this->word('ZYGOMORPHIC', 120)], [$this->word('ZA', 10)]),
            // Level on 60
            $this->finished('g-3', [$this->word('QUIZ', 60)], [$this->word('JAZZ', 60)]),
        ];
    }

    private function fakeStats(array $pages): void
    {
        $fakes = [];
        foreach ($pages as $offset => $games) {
            $fakes[$this->items("?complete=1&offset={$offset}&limit=100")] = Http::response($games, 200);
        }

        $this->fakeApi($fakes);
    }

    public function test_with_no_finished_games_there_is_nothing_to_count_yet(): void
    {
        $this->fakeStats([0 => []]);

        $this->signedIn()->get('/stats')
            ->assertOk()
            ->assertSee('No stats yet.')
            ->assertSee('Finish a game and your highest word, your lowest word, the longest word and every bingo are counted here.')
            ->assertSee(route('home'), false)
            ->assertDontSee('Records');
    }

    public function test_the_stats_count_every_finished_game(): void
    {
        $this->fakeStats([0 => $this->threeGames()]);

        $html = $this->signedIn()->get('/stats')
            ->assertOk()
            ->assertSee('Across 3 finished games')
            ->getContent();

        $text = $this->text($html);

        // Games, words (3 + 3 + 2), the average word (464 points in 8 words) and the bingos
        self::assertStringContainsString('Games 3 Words 8 Average word 58.0 Bingos 1', $text);
    }

    public function test_the_records_say_which_word_which_game_and_who(): void
    {
        $this->fakeStats([0 => $this->threeGames()]);

        $text = $this->text($this->signedIn()->get('/stats')->assertOk()->getContent());

        self::assertStringContainsString('Highest scoring word 120 ZYGOMORPHIC · Ada', $text);
        self::assertStringContainsString('Lowest scoring word 10 ZA · Ben', $text);
        self::assertStringContainsString('Longest word 11 letters ZYGOMORPHIC · Ada', $text);
        self::assertStringContainsString('Highest game score 131 Ada', $text);
        self::assertStringContainsString('Closest game Tied Ada and Ben on 60', $text);
        self::assertStringContainsString('Biggest win 121 points Ada 131 to Ben 10', $text);
        self::assertStringContainsString('Lowest winning score 60 Ada and Ben', $text);
        self::assertStringContainsString('Most bingos in a game 1 Ben', $text);
    }

    public function test_every_record_links_to_the_game_it_was_set_in(): void
    {
        $this->fakeStats([0 => $this->threeGames()]);

        $this->signedIn()->get('/stats')
            ->assertOk()
            ->assertSee(route('game.show', ['game_id' => 'g-1']), false)
            ->assertSee(route('game.show', ['game_id' => 'g-2']), false)
            ->assertSee(route('game.show', ['game_id' => 'g-3']), false);
    }

    public function test_a_game_that_ends_level_is_a_win_for_everyone_on_the_top_score(): void
    {
        $this->fakeStats([0 => $this->threeGames()]);

        $text = $this->text($this->signedIn()->get('/stats')->assertOk()->getContent());

        // Ada won g-2 and drew g-3, Ben won g-1 and drew g-3: two wins of three games each
        self::assertMatchesRegularExpression('/Ada 3 2 67% /', $text);
        self::assertMatchesRegularExpression('/Ben 3 2 67% /', $text);
        self::assertStringContainsString('A game that ends level is a win for everyone on the top score.', $text);
    }

    public function test_each_player_has_a_line_of_their_own_numbers(): void
    {
        $this->fakeStats([0 => $this->threeGames()]);

        $text = $this->text($this->signedIn()->get('/stats')->assertOk()->getContent());

        // Games, wins, average game, best game, best word, average word, bingos
        self::assertStringContainsString('Ada 3 2 67% 92.0 131 120 ZYGOMORPHIC 55.2 0', $text);
        self::assertStringContainsString('Ben 3 2 67% 62.7 118 118 RETAINS 62.7 1', $text);
    }

    public function test_the_stats_are_read_from_the_list_of_games_alone_never_from_a_score_sheet(): void
    {
        $this->fakeStats([0 => $this->threeGames()]);

        $this->signedIn()->get('/stats')->assertOk();

        self::assertCount(0, $this->sent('GET', '/data'));
        self::assertCount(0, $this->sent('GET', '/categories/'));
        self::assertCount(1, $this->sentTo('GET', $this->items('?complete=1&offset=0&limit=100')));
        Http::assertNotSent(fn (Request $request) => $request->method() === 'GET' && $request->header('X-Skip-Cache') !== ['true']);
    }

    public function test_a_hundred_games_is_a_page_and_the_next_page_is_read_too(): void
    {
        $first_page = [];
        for ($i = 1; $i <= 100; $i++) {
            $first_page[] = $this->finished("g-{$i}", [$this->word('QUIZ', 52)], [$this->word('FAX', 33)]);
        }

        $this->fakeStats([0 => $first_page, 100 => [$this->finished('g-101', [$this->word('JAZZ', 40)], [$this->word('QI', 11)])]]);

        $this->signedIn()->get('/stats')
            ->assertOk()
            ->assertSee('Across 101 finished games')
            ->assertDontSee('the most recent ones');

        self::assertCount(1, $this->sentTo('GET', $this->items('?complete=1&offset=100&limit=100')));
        self::assertCount(0, $this->sentTo('GET', $this->items('?complete=1&offset=200&limit=100')));
    }

    public function test_a_thousand_games_is_as_many_as_are_read_and_the_page_says_so(): void
    {
        $pages = [];
        for ($page = 0; $page < 10; $page++) {
            $games = [];
            for ($i = 1; $i <= 100; $i++) {
                $games[] = $this->finished('g-'.($page * 100 + $i), [$this->word('QUIZ', 52)], [$this->word('FAX', 33)]);
            }
            $pages[$page * 100] = $games;
        }

        $this->fakeStats($pages);

        $this->signedIn()->get('/stats')
            ->assertOk()
            ->assertSee('Across 1000 finished games')
            ->assertSee('the most recent ones');

        self::assertCount(0, $this->sentTo('GET', $this->items('?complete=1&offset=1000&limit=100')));
    }

    public function test_a_failure_reading_the_games_is_passed_on(): void
    {
        $this->fakeApi([$this->items('?complete=1&offset=0&limit=100') => Http::response(['message' => 'The API is down'], 503)]);

        $this->signedIn()->get('/stats')->assertStatus(503);
    }

    public function test_the_stats_are_part_of_the_signed_in_navigation(): void
    {
        $this->fakeStats([0 => []]);

        $this->signedIn()->get('/stats')
            ->assertOk()
            ->assertSee('<title>Scrabble Game Scorer: Stats</title>', false)
            ->assertSee('aria-current="page"', false);
    }

    /**
     * The words on the page, one line, the markup gone
     */
    private function text(string $html): string
    {
        return html_entity_decode(trim((string) preg_replace('/\s+/', ' ', strip_tags(str_replace('<', ' <', $html)))));
    }
}
