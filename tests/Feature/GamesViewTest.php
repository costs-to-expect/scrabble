<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ShareToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\FakesTheApi;
use Tests\TestCase;

class GamesViewTest extends TestCase
{
    use FakesTheApi;
    use RefreshDatabase;

    /**
     * A finished game as it is stored: Ada played QUIZ and FAX, passed and gave back 7 for the tiles she was left with
     * (78), Ben played RETAINS for a bingo and ZA (129)
     */
    private function finishedGame(string $id = 'g-9', ?array $ada = null, ?array $ben = null): array
    {
        $ada ??= [$this->word('QUIZ', 52), $this->word('FAX', 33), $this->pass(), $this->adjustment(-7, 'Tiles left')];
        $ben ??= [$this->word('RETAINS', 68, true), $this->word('ZA', 11)];

        $scores = [
            $this->finishedScore('p-1', 'Ada', $ada),
            $this->finishedScore('p-2', 'Ben', $ben),
        ];
        usort($scores, fn (array $a, array $b) => $b['score'] <=> $a['score']);

        return $this->game($id, ['p-1' => 'Ada', 'p-2' => 'Ben'], true, $scores);
    }

    private function paginationHeaders(string $previous, string $next, int $offset, int $limit, int $total): array
    {
        return [
            'X-Link-Previous' => $previous,
            'X-Link-Next' => $next,
            'X-Offset' => (string) $offset,
            'X-Limit' => (string) $limit,
            'X-Total-Count' => (string) $total,
        ];
    }

    // The list of finished games

    public function test_the_games_page_lists_finished_games_with_the_final_scores(): void
    {
        $this->fakeApi([
            $this->items('?complete=1&include-players=1&offset=0&limit=10') => Http::response(
                [$this->finishedGame('g-1'), $this->finishedGame('g-2', [$this->word('JAZZ', 40)], [$this->word('QI', 11)])],
                200,
                $this->paginationHeaders('', '', 0, 10, 2)
            ),
        ]);

        $this->signedIn()->get('/games')
            ->assertOk()
            ->assertSee('Every game you have finished')
            ->assertSeeInOrder(['Ben', 'won with', '129', 'Ben', '1', 'Ada', '2'])
            ->assertSeeInOrder(['Ada', 'won with', '40'])
            ->assertSee('Best word RETAINS 118')
            ->assertSee('Best word QUIZ 52')
            ->assertSee(route('game.show', ['game_id' => 'g-2']), false)
            ->assertSee(route('game.score-sheet', ['game_id' => 'g-1', 'player_id' => 'p-2']), false)
            ->assertSee('1 -')
            ->assertSee('of')
            // Nowhere to page to: both buttons are there, neither is a link
            ->assertDontSee(route('games', ['offset' => 10, 'limit' => 10]), false)
            ->assertDontSee(route('games', ['offset' => 0, 'limit' => 10]), false);
    }

    public function test_a_game_that_ended_level_says_so_and_ranks_the_players_in_the_order_they_played(): void
    {
        $this->fakeApi([
            $this->items('?complete=1&include-players=1&offset=0&limit=10') => Http::response(
                [$this->finishedGame('g-1', [$this->word('QUIZ', 52)], [$this->word('JAZZ', 52)])],
                200,
                $this->paginationHeaders('', '', 0, 10, 1)
            ),
        ]);

        $this->signedIn()->get('/games')
            ->assertOk()
            ->assertSeeInOrder(['Ada and Ben', 'tied on', '52']);
    }

    public function test_the_games_page_links_to_the_previous_and_next_pages_of_games(): void
    {
        $this->fakeApi([
            $this->items('?complete=1&include-players=1&offset=10&limit=5') => Http::response(
                [$this->finishedGame('g-6')],
                200,
                $this->paginationHeaders('/v3/previous', '/v3/next', 10, 5, 22)
            ),
        ]);

        $this->signedIn()->get('/games?offset=10&limit=5')
            ->assertOk()
            ->assertSee('href="'.e(route('games', ['offset' => 5, 'limit' => 5])).'"', false)
            ->assertSee('href="'.e(route('games', ['offset' => 15, 'limit' => 5])).'"', false)
            ->assertSee('11 -');
    }

    public function test_the_games_page_never_pages_back_before_the_first_game(): void
    {
        $this->fakeApi([
            $this->items('?complete=1&include-players=1&offset=2&limit=10') => Http::response(
                [$this->finishedGame('g-3')],
                200,
                $this->paginationHeaders('/v3/previous', '', 2, 10, 3)
            ),
        ]);

        $this->signedIn()->get('/games?offset=2')
            ->assertOk()
            ->assertSee('href="'.e(route('games', ['offset' => 0, 'limit' => 10])).'"', false);
    }

    public function test_a_nonsense_page_is_a_page_the_api_can_answer(): void
    {
        $this->fakeApi([
            $this->items('?complete=1&include-players=1&offset=0&limit=100') => Http::response([], 200, $this->paginationHeaders('', '', 0, 100, 0)),
        ]);

        $this->signedIn()->get('/games?offset=-50&limit=100000')->assertOk();

        $this->fakeApi([
            $this->items('?complete=1&include-players=1&offset=0&limit=1') => Http::response([], 200, $this->paginationHeaders('', '', 0, 1, 0)),
        ]);

        $this->signedIn()->get('/games?offset=abc&limit=0')->assertOk();
    }

    public function test_the_games_page_explains_when_no_games_have_been_played(): void
    {
        $this->fakeApi([
            $this->items('?complete=1&include-players=1&offset=0&limit=10') => Http::response(
                [],
                200,
                $this->paginationHeaders('', '', 0, 10, 0)
            ),
        ]);

        $this->signedIn()->get('/games')->assertOk()->assertSee('You haven&rsquo;t played any games.', false)->assertSee(route('game.create.view'), false);
    }

    public function test_an_api_failure_listing_games_is_passed_on(): void
    {
        $this->fakeApi([
            $this->items('?complete=1&include-players=1&offset=0&limit=10') => Http::response(['message' => 'The API is down'], 503),
        ]);

        $this->signedIn()->get('/games')->assertStatus(503);
    }

    // The overview of a game

    public function test_the_overview_of_an_open_game_has_the_players_in_play_order_links_and_the_ways_to_manage_it(): void
    {
        ShareToken::issue('rt-1', 'r-1', 'g-1', 'p-1', 'Ada', 'owner-bearer');
        $token = (string) ShareToken::query()->value('token');

        $this->fakeApi([
            $this->items('/g-1?include-players=1') => Http::response($this->game('g-1', ['p-1' => 'Ada', 'p-2' => 'Ben']), 200),
            $this->items('/g-1/data') => Http::response($this->scoreSheets(['p-1' => $this->scoreSheet([$this->word('QUIZ', 52)])]), 200),
        ]);

        $this->signedIn()->get('/games/g-1')
            ->assertOk()
            ->assertSee('Game overview')
            ->assertSeeInOrder(['Ada', '52', 'Ben', '0'])
            ->assertSee('Last: QUIZ +52')
            // Ada has played and Ben has not, so Ben is next up
            ->assertSeeInOrder(['Ben', 'Next up'])
            ->assertSee(route('game.score-sheet', ['game_id' => 'g-1', 'player_id' => 'p-2', 'add' => 1]), false)
            ->assertSee('data-copy="'.route('public.score-sheet', ['token' => $token]).'"', false)
            ->assertSee(route('game.player.delete', ['game_id' => 'g-1', 'player_id' => 'p-2']), false)
            ->assertSee(route('game.add-players.view', ['game_id' => 'g-1']), false)
            ->assertSee(route('game.complete.action', ['game_id' => 'g-1']), false)
            ->assertSee(route('game.complete.play-again.action', ['game_id' => 'g-1']), false)
            ->assertSee(route('game.delete.action', ['game_id' => 'g-1']), false);
    }

    public function test_the_overview_only_offers_the_share_links_of_its_own_game(): void
    {
        ShareToken::issue('rt-1', 'r-1', 'g-other', 'p-1', 'Ada', 'owner-bearer');
        $other = (string) ShareToken::query()->value('token');

        $this->fakeApi([
            $this->items('/g-1?include-players=1') => Http::response($this->game('g-1', ['p-1' => 'Ada']), 200),
            $this->items('/g-1/data') => Http::response($this->scoreSheets([]), 200),
        ]);

        $this->signedIn()->get('/games/g-1')->assertOk()->assertDontSee($other, false);
    }

    public function test_an_open_game_reads_the_players_live(): void
    {
        $this->fakeApi([
            $this->items('/g-1?include-players=1') => Http::response($this->game('g-1', ['p-1' => 'Ada']), 200),
            $this->items('/g-1/data') => Http::response($this->scoreSheets([]), 200),
        ]);

        $this->signedIn()->get('/games/g-1')->assertOk();

        Http::assertNotSent(fn (Request $request) => $request->method() === 'GET' && $request->header('X-Skip-Cache') !== ['true']);
    }

    public function test_the_overview_of_a_finished_game_is_the_result_the_highlights_and_each_player(): void
    {
        $this->fakeApi([$this->items('/g-9?include-players=1') => Http::response($this->finishedGame(), 200)]);

        $response = $this->signedIn()->get('/games/g-9')
            ->assertOk()
            ->assertSee('Game overview')
            ->assertSee('won with 129')
            ->assertSeeInOrder(['Ben', 'Winner', '129', 'Ada', '78'])
            // The turns of each player, for as long as the game is kept
            ->assertSee(route('game.score-sheet', ['game_id' => 'g-9', 'player_id' => 'p-1']), false)
            ->assertSee(route('game.score-sheet', ['game_id' => 'g-9', 'player_id' => 'p-2']), false)
            // Nothing to change about a finished game
            ->assertDontSee(route('game.player.delete', ['game_id' => 'g-9', 'player_id' => 'p-1']), false)
            ->assertDontSee(route('game.complete.action', ['game_id' => 'g-9']), false)
            ->assertDontSee(route('game.delete.action', ['game_id' => 'g-9']), false)
            ->assertDontSee('data-dialog-open', false);

        self::assertSame(1, preg_match_all('/>\s*Winner\s*</', $response->getContent()));
    }

    public function test_the_highlights_of_a_finished_game_are_worked_out_from_what_was_stored_with_it(): void
    {
        // Only the game is read: the stats need no score sheets
        $this->fakeApi([$this->items('/g-9?include-players=1') => Http::response($this->finishedGame(), 200)]);

        $html = $this->signedIn()->get('/games/g-9')->assertOk()->getContent();

        $this->assertSeeInHtml($html, ['Highlights', 'Highest word', '118', 'RETAINS', 'Ben']);
        $this->assertSeeInHtml($html, ['Lowest word', '11', 'ZA', 'Ben']);
        $this->assertSeeInHtml($html, ['Longest word', '7', 'letters', 'RETAINS', 'Ben']);
        // (52 + 33 + 118 + 11) / 4 words
        $this->assertSeeInHtml($html, ['Average word', '53.5', '4 words in 5 turns']);
        $this->assertSeeInHtml($html, ['Bingos', '1', 'Ben played 1']);
        $this->assertSeeInHtml($html, ['Winning margin', '51', 'ahead of Ada']);

        // Each player: the numbers, the pass and what the end of the game took off
        $this->assertSeeInHtml($html, ['Player by player', 'Ada', '78', 'Best word', '52', 'QUIZ', 'Lowest word', '33', 'FAX', 'Average word', '42.5', '3', '1 pass']);
        self::assertStringContainsString('Adjusted at the end of the game', $html);
        self::assertStringContainsString('−7', $html);

        self::assertCount(0, $this->sent('GET', '/data'));
    }

    public function test_a_finished_game_that_ended_level_has_joint_winners_and_no_margin(): void
    {
        $this->fakeApi([$this->items('/g-9?include-players=1') => Http::response($this->finishedGame('g-9', [$this->word('QUIZ', 52)], [$this->word('JAZZ', 52)]), 200)]);

        $response = $this->signedIn()->get('/games/g-9')
            ->assertOk()
            ->assertSee('Ada and Ben')
            ->assertSee('tied on 52')
            ->assertSee('Result')
            ->assertSee('Tied')
            ->assertDontSee('Winning margin');

        self::assertSame(2, preg_match_all('/>\s*Joint winner\s*</', $response->getContent()));
    }

    public function test_a_finished_game_without_a_word_has_no_highlights_and_says_why(): void
    {
        $this->fakeApi([$this->items('/g-9?include-players=1') => Http::response($this->finishedGame('g-9', [$this->pass()], [$this->pass()]), 200)]);

        $this->signedIn()->get('/games/g-9')
            ->assertOk()
            ->assertSee('No words were played in this game, so there are no word highlights for it.')
            ->assertDontSee('Highlights')
            ->assertDontSee('before the numbers were kept');
    }

    public function test_a_word_that_was_scored_without_the_word_is_not_called_nothing(): void
    {
        $this->fakeApi([$this->items('/g-9?include-players=1') => Http::response($this->finishedGame('g-9', [$this->word('', 40)], [$this->word('QI', 11)]), 200)]);

        $this->signedIn()->get('/games/g-9')
            ->assertOk()
            ->assertSee('Word not entered');
    }

    public function test_the_overview_of_a_game_that_does_not_exist_is_a_404(): void
    {
        $this->fakeApi([$this->items('/g-404?include-players=1') => Http::response(['message' => 'Not found'], 404)]);

        $this->signedIn()->get('/games/g-404')->assertNotFound();
    }

    public function test_a_failure_reading_the_score_sheets_of_an_open_game_is_passed_on(): void
    {
        $this->fakeApi([
            $this->items('/g-1?include-players=1') => Http::response($this->game('g-1', ['p-1' => 'Ada']), 200),
            $this->items('/g-1/data') => Http::response(['message' => 'The API is down'], 503),
        ]);

        $this->signedIn()->get('/games/g-1')->assertStatus(503);
    }

    // Everyone's scores, as the game screen keeps them up to date

    public function test_the_player_scores_are_read_as_json_with_the_totals_the_last_play_and_the_turns_of_every_player(): void
    {
        $ada = $this->scoreSheet([$this->word('QUIZ', 52, false, 'turn-0001'), $this->word('FAX', 33, false, 'turn-0002')]);
        $ben = $this->scoreSheet([$this->word('RETAINS', 68, true, 'turn-0003'), $this->pass('turn-0004'), $this->adjustment(-7, 'Tiles left', 'turn-0005'), $this->word('OOPS', 99, false, 'turn-0006', true)]);

        $this->fakeApi([
            $this->items('/g-1/categories') => Http::response($this->assignedPlayers(['p-1' => 'Ada', 'p-2' => 'Ben', 'p-3' => 'Cleo']), 200),
            $this->items('/g-1/data') => Http::response($this->scoreSheets([
                'p-1' => $ada,
                'p-2' => $ben,
                // Removed from the game since, no longer in the list
                'p-9' => $this->scoreSheet([$this->word('GONE', 99)]),
            ]), 200),
        ]);

        $response = $this->signedIn()->get('/game/g-1/player-scores')->assertOk();

        $response->assertJsonPath('players.0', ['id' => 'p-1', 'name' => 'Ada', 'total' => 85, 'turns' => 2, 'last' => 'FAX +33', 'sheet' => ['turns' => $ada['turns'], 'score' => $ada['score']]]);
        // The turn that was taken off is still on the sheet the screen draws, it does not count and is not the last play
        $response->assertJsonPath('players.1.total', 111)
            ->assertJsonPath('players.1.turns', 2)
            ->assertJsonPath('players.1.last', "Tiles left \u{2212}7")
            ->assertJsonCount(4, 'players.1.sheet.turns')
            ->assertJsonPath('players.1.sheet.turns.3.removed', true);
        // Nothing scored yet, no score sheet at all
        $response->assertJsonPath('players.2', ['id' => 'p-3', 'name' => 'Cleo', 'total' => 0, 'turns' => 0, 'last' => null, 'sheet' => ['turns' => [], 'score' => ['total' => 0, 'turns' => 0, 'words' => 0, 'bingos' => 0, 'best' => null, 'lowest' => null]]]);
        $response->assertJsonCount(3, 'players');
    }

    public function test_a_game_nobody_has_scored_in_has_everyone_on_nothing(): void
    {
        $this->fakeApi([
            $this->items('/g-1/categories') => Http::response($this->assignedPlayers(['p-1' => 'Ada', 'p-2' => 'Ben']), 200),
            $this->items('/g-1/data') => Http::response(['message' => 'Not found'], 404),
        ]);

        $this->signedIn()->get('/game/g-1/player-scores')
            ->assertOk()
            ->assertJsonPath('players.0.total', 0)
            ->assertJsonPath('players.1.total', 0);
    }

    public function test_the_player_scores_are_a_404_when_the_game_cannot_be_found(): void
    {
        $this->fakeApi([$this->items('/g-404/categories') => Http::response(['message' => 'Not found'], 404)]);

        $this->signedIn()->get('/game/g-404/player-scores')->assertNotFound();
    }

    public function test_the_player_scores_are_a_404_when_the_score_sheets_cannot_be_read(): void
    {
        $this->fakeApi([
            $this->items('/g-1/categories') => Http::response($this->assignedPlayers(['p-1' => 'Ada']), 200),
            $this->items('/g-1/data') => Http::response(['message' => 'The API is down'], 503),
        ]);

        $this->signedIn()->get('/game/g-1/player-scores')->assertNotFound();
    }

    /**
     * Text in the page in this order, ignoring the markup between it
     *
     * @param list<string> $needles
     */
    private function assertSeeInHtml(string $html, array $needles): void
    {
        $text = html_entity_decode(trim((string) preg_replace('/\s+/', ' ', strip_tags(str_replace('<', ' <', $html)))));
        $position = 0;

        foreach ($needles as $needle) {
            $found = strpos($text, $needle, $position);
            self::assertNotFalse($found, "Expected to see [{$needle}] after position {$position} in: ".substr($text, $position, 400));
            $position = $found + strlen($needle);
        }
    }
}
