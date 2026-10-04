<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ShareToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\FakesTheApi;
use Tests\TestCase;

class HomeTest extends TestCase
{
    use FakesTheApi;
    use RefreshDatabase;

    /**
     * @param list<array> $open
     * @param list<array> $closed
     * @param array<string, array> $sheets game id => score sheets
     */
    private function fakeHome(array $open = [], array $closed = [], array $sheets = [], ?array $players = null, array $overrides = []): void
    {
        $fakes = [
            $this->items('?complete=0&include-players=1') => Http::response($open, 200),
            $this->items('?complete=1&limit=5&include-players=1') => Http::response($closed, 200),
        ];

        foreach ($sheets as $game_id => $game_sheets) {
            $fakes[$this->items("/{$game_id}/data")] = Http::response($game_sheets, 200);
        }

        if ($players !== null) {
            $fakes['api.test/v3/resource-types/rt-1/categories?collection=1'] = Http::response($players, 200);
        }

        $this->fakeApi($overrides + $fakes);
    }

    private function shareToken(string $token, string $game_id, string $player_id): void
    {
        $share = new ShareToken();
        $share->token = $token;
        $share->game_id = $game_id;
        $share->player_id = $player_id;
        $share->parameters = ['game_id' => $game_id, 'player_id' => $player_id];
        $share->save();
    }

    private function finishedGame(string $id, string $first, int $first_score, string $second, int $second_score): array
    {
        return $this->game($id, ['p-1' => 'Ada', 'p-2' => 'Ben'], true, [
            $this->finishedScore('p-'.($first === 'Ada' ? 1 : 2), $first, [$this->word('QUIZ', $first_score)]),
            $this->finishedScore('p-'.($second === 'Ada' ? 1 : 2), $second, [$this->word('JAZZ', $second_score)]),
        ]);
    }

    public function test_a_new_player_is_invited_to_enter_their_players_and_start_a_game(): void
    {
        $this->fakeHome(players: []);

        $this->signedIn()->get('/home')
            ->assertOk()
            ->assertSee('Let&rsquo;s get started!', false)
            ->assertSee('2 to 4 of them')
            ->assertSee('action="'.route('start').'"', false)
            ->assertSee('name="players"', false)
            ->assertSee('Start the first game')
            // Nothing to pick or to look back on yet
            ->assertDontSee('Next game')
            ->assertDontSee('Last games')
            ->assertDontSee("Who&rsquo;s scoring?", false);
    }

    public function test_a_failed_start_shows_the_getting_started_form_again_with_what_was_typed_and_the_error(): void
    {
        $this->fakeHome();

        $this->signedIn()
            ->withSession(['validation.errors' => ['players' => ['errors' => ['Enter at least 2 names, one per line']]], '_old_input' => ['players' => "Ada\nBen"]])
            ->get('/home')
            ->assertOk()
            ->assertSee('Let&rsquo;s get started!', false)
            ->assertSee('Enter at least 2 names, one per line')
            ->assertSee('aria-invalid="true"', false);
    }

    public function test_a_player_with_players_and_no_game_is_offered_the_next_game_not_the_getting_started_form(): void
    {
        $this->fakeHome();

        $response = $this->signedIn()->get('/home')
            ->assertOk()
            ->assertSee('Ready for the first game?')
            ->assertSee('Next game')
            ->assertSee('action="'.route('game.create.action').'"', false)
            ->assertSee('name="name" value="Scrabble game"', false)
            ->assertSee('name="description" value="Scrabble game created via the Scrabble app"', false)
            ->assertSee('value="p-1"', false)
            ->assertSee('value="p-2"', false)
            ->assertSee('data-picker-min="2"', false)
            ->assertSee('data-picker-max="4"', false)
            ->assertSee(route('player.create.view'), false)
            ->assertDontSee('Let&rsquo;s get started!', false)
            ->assertDontSee('Play again with')
            ->assertSee('No finished games yet.')
            // Nobody has played yet, so nobody is chosen and the game cannot be started
            ->assertSee('Choose at least 2 players');

        self::assertDoesNotMatchRegularExpression('/<input type="checkbox"[^>]*\schecked/', $response->getContent());
    }

    public function test_the_players_of_an_open_game_keep_their_places_and_the_one_ahead_is_crowned(): void
    {
        $this->fakeHome(
            open: [$this->game('g-1', ['p-1' => 'Ada', 'p-2' => 'Ben'])],
            sheets: ['g-1' => $this->scoreSheets([
                'p-1' => $this->scoreSheet([$this->word('QUIZ', 52), $this->word('FAX', 33)]),
                'p-2' => $this->scoreSheet([$this->word('RETAINS', 68, true)]),
            ])]
        );

        $response = $this->signedIn()->get('/home')
            ->assertOk()
            ->assertSee('Game in progress')
            ->assertSee("Who&rsquo;s scoring?", false)
            // The tiles stay in the order they play in, Ben is ahead and does not jump to the front
            ->assertSeeInOrder(['Ada', '85', 'Ben', '118'])
            ->assertSee('Ben is ahead by 33.')
            ->assertSee('Last: FAX +33')
            ->assertSee('Last: RETAINS +118')
            ->assertSee(route('game.score-sheet', ['game_id' => 'g-1', 'player_id' => 'p-1', 'add' => 1]), false)
            ->assertSee(route('game.score-sheet', ['game_id' => 'g-1', 'player_id' => 'p-2', 'add' => 1]), false);

        // One crown on the tiles, the finish sheet marks whoever is ahead as well
        $tiles = substr($response->getContent(), 0, (int) strpos($response->getContent(), 'id="share-dialog"'));
        self::assertSame(1, substr_count($tiles, 'Leading</span>'));
        self::assertSame(1, substr_count(substr($response->getContent(), strlen($tiles)), 'Leading</span>'));
    }

    public function test_whoever_has_played_the_fewest_turns_is_next_up(): void
    {
        $this->fakeHome(
            open: [$this->game('g-1', ['p-1' => 'Ada', 'p-2' => 'Ben'])],
            sheets: ['g-1' => $this->scoreSheets([
                'p-1' => $this->scoreSheet([$this->word('QUIZ', 52), $this->word('FAX', 33)]),
                'p-2' => $this->scoreSheet([$this->word('RETAINS', 68, true)]),
            ])]
        );

        $response = $this->signedIn()->get('/home')->assertOk()->assertSee('Ben is next up.');

        self::assertSame(1, substr_count($response->getContent(), 'Next up</span>'));
        self::assertMatchesRegularExpression('/Ben<\/span>\s*<span[^>]*>Next up<\/span>/', $response->getContent());
    }

    public function test_a_pass_is_a_turn_and_an_adjustment_is_not(): void
    {
        $this->fakeHome(
            open: [$this->game('g-1', ['p-1' => 'Ada', 'p-2' => 'Ben'])],
            sheets: ['g-1' => $this->scoreSheets([
                'p-1' => $this->scoreSheet([$this->word('QUIZ', 52)]),
                'p-2' => $this->scoreSheet([$this->pass(), $this->adjustment(-7, 'Tiles left')]),
            ])]
        );

        // One turn each, so Ada, the first to play, is next up and Ben's last line is the adjustment
        $response = $this->signedIn()->get('/home')
            ->assertOk()
            ->assertSee('Ada is next up.')
            ->assertSee("Last: Tiles left \u{2212}7");

        self::assertMatchesRegularExpression('/Ada<\/span>\s*<span[^>]*>Next up<\/span>/', $response->getContent());
    }

    public function test_a_game_that_has_not_been_played_has_no_last_play_and_the_first_player_goes_first(): void
    {
        $this->fakeHome(open: [$this->game('g-1', ['p-1' => 'Ada', 'p-2' => 'Ben'])], sheets: ['g-1' => []]);

        $response = $this->signedIn()->get('/home')
            ->assertOk()
            ->assertSee('Ada is next up.')
            ->assertDontSee('Last:')
            ->assertDontSee('Leading')
            ->assertDontSee('is ahead by');

        self::assertSame(2, substr_count($response->getContent(), 'No turns yet'));
    }

    public function test_the_ways_to_manage_an_open_game_are_a_tap_away(): void
    {
        $this->fakeHome(
            open: [$this->game('g-1', ['p-1' => 'Ada', 'p-2' => 'Ben'])],
            sheets: ['g-1' => []]
        );

        $this->signedIn()->get('/home')
            ->assertOk()
            ->assertSee(route('game.add-players.view', ['game_id' => 'g-1']), false)
            ->assertSee(route('game.complete.action', ['game_id' => 'g-1']), false)
            ->assertSee(route('game.complete.play-again.action', ['game_id' => 'g-1']), false)
            ->assertSee(route('game.delete.action', ['game_id' => 'g-1']), false)
            ->assertSee(route('game.player.delete', ['game_id' => 'g-1', 'player_id' => 'p-1']), false)
            ->assertSee(route('game.player.delete', ['game_id' => 'g-1', 'player_id' => 'p-2']), false)
            ->assertSee('data-dialog-open="share-dialog"', false)
            ->assertSee('data-dialog-open="finish-dialog"', false)
            ->assertSee('data-dialog-open="remove-dialog"', false)
            ->assertSee('data-dialog-open="delete-dialog"', false);
    }

    public function test_a_game_is_finished_by_hand_and_the_finish_sheet_lists_where_everyone_is(): void
    {
        $this->fakeHome(
            open: [$this->game('g-1', ['p-1' => 'Ada', 'p-2' => 'Ben'])],
            sheets: ['g-1' => $this->scoreSheets([
                'p-1' => $this->scoreSheet([$this->word('QUIZ', 52)]),
                'p-2' => $this->scoreSheet([$this->word('RETAINS', 68, true)]),
            ])]
        );

        $html = $this->signedIn()->get('/home')
            ->assertOk()
            ->assertSee('Finish this game?')
            ->assertSee('Finish and play again')
            ->assertSee('Keep playing')
            ->assertSee('Played out?')
            ->getContent();

        // Best score first in the finish sheet, whatever the order of play
        $finish = substr($html, (int) strpos($html, 'id="finish-list"'));
        self::assertLessThan(strpos($finish, 'Ada'), strpos($finish, 'Ben'));
    }

    public function test_a_game_with_every_seat_taken_cannot_have_a_player_added(): void
    {
        $this->fakeHome(
            open: [$this->game('g-1', ['p-1' => 'Ada', 'p-2' => 'Ben', 'p-3' => 'Cleo', 'p-4' => 'Dev'])],
            sheets: ['g-1' => []],
            players: $this->playerCollection(['p-1' => 'Ada', 'p-2' => 'Ben', 'p-3' => 'Cleo', 'p-4' => 'Dev'])
        );

        $this->signedIn()->get('/home')
            ->assertOk()
            ->assertSee('Dev')
            ->assertDontSee(route('game.add-players.view', ['game_id' => 'g-1']), false);
    }

    public function test_nobody_is_crowned_until_someone_is_ahead(): void
    {
        $this->fakeHome(
            open: [$this->game('g-1', ['p-1' => 'Ada', 'p-2' => 'Ben'])],
            sheets: ['g-1' => $this->scoreSheets([
                'p-1' => $this->scoreSheet([$this->word('QUIZ', 52)]),
                'p-2' => $this->scoreSheet([$this->word('JAZZ', 52)]),
            ])]
        );

        $this->signedIn()->get('/home')->assertOk()->assertDontSee('Leading')->assertDontSee('is ahead by');

        $this->fakeHome(open: [$this->game('g-2', ['p-1' => 'Ada', 'p-2' => 'Ben'])], sheets: ['g-2' => []]);

        $this->signedIn()->get('/home')->assertOk()->assertDontSee('Leading');
    }

    public function test_several_open_games_are_switched_between_with_the_game_chosen_in_the_address(): void
    {
        $this->fakeHome(
            open: [
                $this->game('g-1', ['p-1' => 'Ada', 'p-2' => 'Ben']),
                $this->game('g-2', ['p-2' => 'Ben', 'p-3' => 'Cleo']),
            ],
            sheets: [
                'g-1' => $this->scoreSheets(['p-1' => $this->scoreSheet([$this->word('QUIZ', 52)])]),
                'g-2' => $this->scoreSheets(['p-3' => $this->scoreSheet([$this->word('JAZZ', 40)])]),
            ],
            players: $this->playerCollection(['p-1' => 'Ada', 'p-2' => 'Ben', 'p-3' => 'Cleo'])
        );

        // The first game, until another is chosen
        $this->signedIn()->get('/home')
            ->assertOk()
            ->assertSee('Open games')
            ->assertSee(route('home', ['game' => 'g-2']), false)
            ->assertSee(route('game.score-sheet', ['game_id' => 'g-1', 'player_id' => 'p-1', 'add' => 1]), false)
            ->assertDontSee(route('game.score-sheet', ['game_id' => 'g-2', 'player_id' => 'p-3', 'add' => 1]), false);

        $this->signedIn()->get('/home?game=g-2')
            ->assertOk()
            ->assertSee(route('game.score-sheet', ['game_id' => 'g-2', 'player_id' => 'p-3', 'add' => 1]), false)
            ->assertDontSee(route('game.score-sheet', ['game_id' => 'g-1', 'player_id' => 'p-1', 'add' => 1]), false);

        // A game that is not open falls back to the first
        $this->signedIn()->get('/home?game=nope')
            ->assertOk()
            ->assertSee(route('game.score-sheet', ['game_id' => 'g-1', 'player_id' => 'p-1', 'add' => 1]), false);
    }

    public function test_open_games_on_different_days_are_told_apart_by_the_day(): void
    {
        $earlier = $this->game('g-1', ['p-1' => 'Ada']);
        $earlier['created_at'] = now()->subDays(3)->setTime(19, 5)->toDateTimeString();
        $later = $this->game('g-2', ['p-2' => 'Ben']);
        $later['created_at'] = now()->toDateTimeString();
        $this->fakeHome(open: [$earlier, $later], sheets: ['g-1' => [], 'g-2' => []]);

        // Different days, so the day is enough
        $response = $this->signedIn()->get('/home')->assertOk();
        $response->assertSee(now()->subDays(3)->format('l'));
        $response->assertSee('Today');
    }

    public function test_open_games_are_numbered_when_the_api_does_not_say_when_they_started(): void
    {
        $this->fakeHome(open: [$this->game('g-1', ['p-1' => 'Ada']), $this->game('g-2', ['p-2' => 'Ben'])], sheets: ['g-1' => [], 'g-2' => []]);

        $this->signedIn()->get('/home')->assertOk()->assertSee('Game 1')->assertSee('Game 2');
    }

    public function test_two_games_that_started_on_the_same_day_are_told_apart_by_the_time(): void
    {
        $first = $this->game('g-1', ['p-1' => 'Ada']);
        $first['created_at'] = now()->startOfDay()->setTime(10, 15)->toDateTimeString();
        $second = $this->game('g-2', ['p-2' => 'Ben']);
        $second['created_at'] = now()->startOfDay()->setTime(10, 45)->toDateTimeString();
        $this->fakeHome(open: [$first, $second], sheets: ['g-1' => [], 'g-2' => []]);

        $this->signedIn()->get('/home')->assertOk()->assertSee('Today, 10:15')->assertSee('Today, 10:45');
    }

    public function test_a_single_open_game_has_nothing_to_switch_between(): void
    {
        $this->fakeHome(open: [$this->game('g-1', ['p-1' => 'Ada'])], sheets: ['g-1' => []]);

        $this->signedIn()->get('/home')->assertOk()->assertDontSee('Open games');
    }

    public function test_how_long_a_game_has_been_running_is_shown_when_the_api_says_when_it_started(): void
    {
        $started = $this->game('g-1', ['p-1' => 'Ada']);
        $started['created_at'] = now()->subMinutes(40)->toDateTimeString();
        $this->fakeHome(open: [$started], sheets: ['g-1' => []]);

        $this->signedIn()->get('/home')->assertOk()->assertSee('Game in progress')->assertSee('40m');
    }

    public function test_the_time_is_left_out_when_the_api_does_not_say_when_a_game_started(): void
    {
        $this->fakeHome(open: [$this->game('g-1', ['p-1' => 'Ada'])], sheets: ['g-1' => []]);

        $response = $this->signedIn()->get('/home')->assertOk()->assertSee('Game in progress');

        self::assertDoesNotMatchRegularExpression('/Game in progress\s*&middot;/', $response->getContent());
    }

    public function test_a_public_score_sheet_link_is_only_offered_to_players_with_a_share_token(): void
    {
        $this->shareToken('token-for-ada', 'g-1', 'p-1');
        $this->fakeHome(open: [$this->game('g-1', ['p-1' => 'Ada', 'p-2' => 'Ben'])], sheets: ['g-1' => []]);

        $response = $this->signedIn()->get('/home')
            ->assertOk()
            ->assertSee('data-copy="'.route('public.score-sheet', ['token' => 'token-for-ada']).'"', false)
            ->assertSee('No link');

        // Ben has no token, so there is a single public link on the page.
        self::assertSame(1, substr_count($response->getContent(), '/public/score-sheet/'));
    }

    public function test_only_the_share_links_of_the_open_games_are_read(): void
    {
        $this->shareToken('token-for-ada', 'g-1', 'p-1');
        $this->shareToken('token-of-another-game', 'g-9', 'p-1');
        $this->fakeHome(open: [$this->game('g-1', ['p-1' => 'Ada'])], sheets: ['g-1' => []]);

        $this->signedIn()->get('/home')->assertOk()->assertDontSee('token-of-another-game');
    }

    public function test_recent_games_show_who_won_and_what_everyone_else_scored(): void
    {
        $this->fakeHome(closed: [$this->finishedGame('g-9', 'Ada', 211, 'Ben', 187)]);

        $this->signedIn()->get('/home')
            ->assertOk()
            ->assertSee('Last games')
            ->assertSeeInOrder(['Ada', 'won with', '211'])
            ->assertSee('Ben 187')
            ->assertSee(route('game.show', ['game_id' => 'g-9']), false)
            ->assertSee(route('games'), false);
    }

    public function test_a_recent_game_that_ended_level_says_so(): void
    {
        $this->fakeHome(closed: [$this->finishedGame('g-9', 'Ada', 200, 'Ben', 200)]);

        $this->signedIn()->get('/home')
            ->assertOk()
            ->assertSeeInOrder(['Ada and Ben', 'tied on', '200']);
    }

    public function test_a_recent_game_with_no_scores_is_left_out(): void
    {
        $this->fakeHome(closed: [$this->game('g-9', ['p-1' => 'Ada'], true, [])]);

        $this->signedIn()->get('/home')->assertOk()->assertSee('No finished games yet.');
    }

    public function test_with_no_game_running_the_page_offers_to_play_again_with_the_players_of_the_last_game(): void
    {
        $this->fakeHome(closed: [$this->finishedGame('g-9', 'Ben', 211, 'Ada', 187)]);

        $response = $this->signedIn()->get('/home')
            ->assertOk()
            ->assertSee('Ready for another game?')
            ->assertSee('Last played with Ben and Ada.')
            ->assertSee('Play again with Ben &amp; Ada', false);

        // The same players, started in one tap, and chosen for the next game as well
        self::assertSame(2, substr_count($response->getContent(), '<input type="hidden" name="players[]" value="p-'));
        self::assertMatchesRegularExpression('/name="players\[\]" value="p-1"[^>]*checked/', $response->getContent());
        self::assertMatchesRegularExpression('/name="players\[\]" value="p-2"[^>]*checked/', $response->getContent());
        $response->assertSee('Start game with 2 players');
    }

    public function test_when_the_last_game_was_played_is_shown_when_the_api_says_when_it_started(): void
    {
        $game = $this->finishedGame('g-9', 'Ada', 100, 'Ben', 90);
        $game['created_at'] = now()->subDay()->toDateTimeString();
        $this->fakeHome(closed: [$game]);

        $this->signedIn()->get('/home')->assertOk()->assertSee('Last played yesterday with Ada and Ben.');
    }

    public function test_only_the_score_sheets_of_games_in_progress_are_read(): void
    {
        $this->fakeHome(
            open: [$this->game('g-1', ['p-1' => 'Ada'])],
            closed: [$this->finishedGame('g-9', 'Ada', 100, 'Ben', 90)],
            sheets: ['g-1' => []]
        );

        $this->signedIn()->get('/home')->assertOk();

        self::assertCount(1, $this->sentTo('GET', $this->items('/g-1/data')));
        self::assertCount(0, $this->sentTo('GET', $this->items('/g-9/data')));
    }

    public function test_every_api_request_is_made_with_the_players_bearer_token(): void
    {
        $this->fakeHome(open: [$this->game('g-1', ['p-1' => 'Ada'])], sheets: ['g-1' => []]);

        $this->signedIn()->get('/home')->assertOk();

        Http::assertSent(fn (Request $request) => $request->header('Authorization') === ['Bearer test-bearer-token']);
        Http::assertNotSent(fn (Request $request) => $request->header('Authorization') !== ['Bearer test-bearer-token']);
        Http::assertNotSent(fn (Request $request) => $request->hasHeader('X-Internal-Api-Key'));
    }

    public function test_everything_is_read_live_so_every_read_skips_the_api_cache_exactly_once(): void
    {
        $this->fakeHome(open: [$this->game('g-1', ['p-1' => 'Ada'])], sheets: ['g-1' => []]);

        $this->signedIn()->get('/home')->assertOk();

        $reads = array_filter(
            array_column(Http::recorded()->all(), 0),
            static fn (Request $request): bool => $request->method() === 'GET'
        );

        // The lookups, the players, the games and the score sheets: all of them, and the header is not repeated
        self::assertGreaterThanOrEqual(6, count($reads));
        foreach ($reads as $request) {
            self::assertSame(['true'], $request->header('X-Skip-Cache'), $request->url());
        }
    }

    public function test_the_home_page_is_a_404_when_the_account_cannot_be_fetched(): void
    {
        $this->fakeHome(overrides: ['api.test/v3/auth/user' => Http::response(['message' => 'Unauthenticated'], 401)]);

        $this->signedIn()->get('/home')->assertNotFound();
    }

    public function test_failed_game_lookups_show_as_empty_lists_rather_than_an_error(): void
    {
        $this->fakeApi([
            $this->items('?complete=0&include-players=1') => Http::response(['message' => 'Broken'], 500),
            $this->items('?complete=1&limit=5&include-players=1') => Http::response(['message' => 'Broken'], 500),
        ]);

        $this->signedIn()->get('/home')
            ->assertOk()
            ->assertDontSee('Game in progress')
            ->assertSee('Ready for the first game?')
            ->assertSee('No finished games yet.');
    }

    public function test_a_game_whose_score_sheets_cannot_be_read_still_shows_its_players_with_nothing_scored(): void
    {
        $this->fakeHome(
            open: [$this->game('g-1', ['p-1' => 'Ada', 'p-2' => 'Ben'])],
            overrides: [$this->items('/g-1/data') => Http::response(['message' => 'Broken'], 500)]
        );

        $this->signedIn()->get('/home')
            ->assertOk()
            ->assertSee('Game in progress')
            ->assertSee('Ada')
            ->assertSee('Ben')
            ->assertDontSee('Leading');
    }
}
