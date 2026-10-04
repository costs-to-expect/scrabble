<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ShareToken;
use App\Support\ScoreRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\FakesTheApi;
use Tests\TestCase;

/**
 * Only one player in a game needs an account. The owner shares a public link for every other
 * player, the link is a token the app maps back to the game, the player and the owner's bearer
 * token, so anyone with the link can score for that player and nobody else.
 */
class PublicShareTest extends TestCase
{
    use FakesTheApi;
    use RefreshDatabase;

    private function shareToken(array $parameters = [], string $token = 'public-token'): void
    {
        $share = new ShareToken();
        $share->token = $token;
        $share->game_id = 'g-1';
        $share->player_id = 'p-1';
        $share->parameters = $parameters + [
            'resource_type_id' => 'rt-1',
            'resource_id' => 'r-1',
            'game_id' => 'g-1',
            'player_id' => 'p-1',
            'player_name' => 'Ada',
            'owner_bearer' => 'owner-bearer',
        ];
        $share->save();
    }

    private function fakeSharedGame(array $sheet, bool $complete = false, array $overrides = []): void
    {
        $this->fakeApi($overrides + [
            $this->items('/g-1') => Http::response($this->game('g-1', ['p-1' => 'Ada', 'p-2' => 'Ben'], $complete), 200),
            $this->items('/g-1/data/p-1') => Http::response(['key' => 'p-1', 'value' => $sheet], 200),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function sheetConfig(string $html): array
    {
        self::assertSame(1, preg_match('#<script type="application/json" id="sheet-config">(.*?)</script>#s', $html, $matches), 'the page carries its settings');

        return json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_the_public_score_sheet_is_the_score_sheet_of_the_player_the_link_was_made_for(): void
    {
        $this->shareToken();
        $sheet = $this->scoreSheet([$this->word('QUIZ', 52), $this->word('RETAINS', 68, true)]);
        $this->fakeSharedGame($sheet);

        $response = $this->get('/public/score-sheet/public-token')
            ->assertOk()
            ->assertSee('Hey Ada, play Scrabble with us!')
            ->assertSee('Player: Ada');

        self::assertMatchesRegularExpression('/id="total"[^>]*>170</', $response->getContent());

        // The player is never told who the owner is: the page holds the link's addresses, not the owner's ids or bearer
        $config = $this->sheetConfig($response->getContent());

        self::assertFalse($config['owner']);
        self::assertSame('p-1', $config['focus']);
        self::assertSame([['id' => 'p-1', 'name' => 'Ada']], $config['players']);
        self::assertSame([], $config['ids']);
        self::assertSame(
            [
                'turn' => route('public.turn.action', ['token' => 'public-token']),
                'remove' => route('public.turn.remove.action', ['token' => 'public-token']),
                'restore' => route('public.turn.restore.action', ['token' => 'public-token']),
                'players' => route('public.player-scores', ['token' => 'public-token']),
            ],
            $config['urls']
        );
        self::assertSame(['p-1'], array_keys($config['sheets']));
        self::assertSame(['turns' => $sheet['turns'], 'score' => $sheet['score']], $config['sheets']['p-1']);
        self::assertSame(ScoreRules::limits(), $config['limits']);
        // Nothing that says which game it is, or whose it is: no ids, no bearer, no address of the game
        foreach (['owner-bearer', 'rt-1', '"g-1"', '/g-1', 'game_id', 'resource'] as $secret) {
            self::assertStringNotContainsString($secret, $response->getContent());
        }
    }

    public function test_the_public_score_sheet_is_kept_out_of_search_engines_and_has_no_account_navigation_or_game_controls(): void
    {
        $this->shareToken();
        $this->fakeSharedGame($this->scoreSheet());

        $this->get('/public/score-sheet/public-token')
            ->assertOk()
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false)
            ->assertDontSee(route('home'), false)
            ->assertDontSee(route('sign-out'), false)
            ->assertDontSee(route('account'), false)
            ->assertDontSee('data-tab-bar', false)
            // The player can't finish, share or delete the game, that is for the owner
            ->assertDontSee('data-dialog-open', false)
            ->assertDontSee('Finished playing?')
            ->assertDontSee(route('game.complete.action', ['game_id' => 'g-1']), false)
            ->assertDontSee(route('game.add-players.view', ['game_id' => 'g-1']), false)
            // But they can add a turn
            ->assertSee('id="add-turn"', false)
            ->assertSee('js/turn-entry.js', false)
            ->assertSee('js/score-sheet.js', false);
    }

    public function test_the_public_score_sheet_reads_the_game_as_its_owner(): void
    {
        $this->shareToken();
        $this->fakeSharedGame($this->scoreSheet());

        $this->get('/public/score-sheet/public-token')->assertOk();

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/items/g-1/data/p-1')
            && $request->header('Authorization') === ['Bearer owner-bearer']);
        Http::assertNotSent(fn (Request $request) => $request->header('Authorization') !== ['Bearer owner-bearer']);
        // Live, like every other read
        Http::assertNotSent(fn (Request $request) => $request->method() === 'GET' && $request->header('X-Skip-Cache') !== ['true']);
    }

    public function test_a_link_without_a_player_name_calls_the_player_a_scrabble_player(): void
    {
        $share = new ShareToken();
        $share->token = 'public-token';
        $share->game_id = 'g-1';
        $share->player_id = 'p-1';
        $share->parameters = [
            'resource_type_id' => 'rt-1',
            'resource_id' => 'r-1',
            'game_id' => 'g-1',
            'player_id' => 'p-1',
            'owner_bearer' => 'owner-bearer',
        ];
        $share->save();
        $this->fakeSharedGame($this->scoreSheet());

        $this->get('/public/score-sheet/public-token')
            ->assertOk()
            ->assertSee('Hey Scrabble Player, play Scrabble with us!');
    }

    public function test_the_players_name_cannot_break_the_page(): void
    {
        $this->shareToken(['player_name' => '<script>alert(1)</script>']);
        $this->fakeSharedGame($this->scoreSheet());

        $html = $this->get('/public/score-sheet/public-token')->assertOk()->getContent();

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    public function test_a_player_without_a_score_sheet_is_given_an_empty_one(): void
    {
        $this->shareToken();
        $this->fakeApi([
            $this->items('/g-1') => Http::response($this->game('g-1', ['p-1' => 'Ada']), 200),
            $this->items('/g-1/data/p-1') => Http::response(['message' => 'Not found'], 404),
            $this->items('/g-1/data') => Http::response(['id' => 'ds-1'], 201),
        ]);

        $this->get('/public/score-sheet/public-token')
            ->assertRedirect(route('public.score-sheet', ['token' => 'public-token']));

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/items/g-1/data')
            && $request['key'] === 'p-1'
            && json_decode($request['value'], true) === ScoreRules::emptySheet()
            && $request->header('Authorization') === ['Bearer owner-bearer']);
    }

    public function test_a_failure_making_the_score_sheet_is_passed_on(): void
    {
        $this->shareToken();
        $this->fakeApi([
            $this->items('/g-1') => Http::response($this->game('g-1', ['p-1' => 'Ada']), 200),
            $this->items('/g-1/data/p-1') => Http::response(['message' => 'Not found'], 404),
            $this->items('/g-1/data') => Http::response(['message' => 'The API is down'], 503),
        ]);

        $this->get('/public/score-sheet/public-token')->assertStatus(503);
    }

    public function test_a_failure_reading_the_score_sheet_is_passed_on(): void
    {
        $this->shareToken();
        $this->fakeApi([
            $this->items('/g-1') => Http::response($this->game('g-1', ['p-1' => 'Ada']), 200),
            $this->items('/g-1/data/p-1') => Http::response(['message' => 'The API is down'], 503),
        ]);

        $this->get('/public/score-sheet/public-token')->assertStatus(503);
    }

    public function test_a_game_that_no_longer_exists_is_a_404(): void
    {
        $this->shareToken();
        $this->fakeApi([$this->items('/g-1') => Http::response(['message' => 'Not found'], 404)]);

        $this->get('/public/score-sheet/public-token')->assertNotFound();
    }

    public function test_an_unknown_link_is_a_404_and_makes_no_api_requests(): void
    {
        $this->fakeApi();

        $this->get('/public/score-sheet/not-a-token')->assertNotFound();
        $this->get('/public/game/not-a-token/player-scores')->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_a_link_with_corrupt_parameters_is_a_server_error(): void
    {
        DB::table('share_token')->insert([
            'token' => 'public-token',
            'game_id' => 'g-1',
            'player_id' => 'p-1',
            'parameters' => '{not json',
        ]);

        $this->get('/public/score-sheet/public-token')->assertStatus(500);
        $this->get('/public/game/public-token/player-scores')->assertStatus(500);
    }

    public function test_the_public_player_scores_are_everyones_scores_and_last_plays_as_the_owner_reads_them(): void
    {
        $this->shareToken();
        $this->fakeApi([
            $this->items('/g-1/categories') => Http::response($this->assignedPlayers(['p-1' => 'Ada', 'p-2' => 'Ben', 'p-3' => 'Cleo']), 200),
            $this->items('/g-1/data') => Http::response($this->scoreSheets([
                'p-1' => $this->scoreSheet([$this->word('QUIZ', 52), $this->word('FAX', 33)]),
                'p-2' => $this->scoreSheet([$this->word('RETAINS', 68, true), $this->pass(), $this->adjustment(-7, 'Tiles left')]),
                // Removed from the game since, no longer in the list
                'p-9' => $this->scoreSheet([$this->word('GONE', 99)]),
            ]), 200),
        ]);

        $this->get('/public/game/public-token/player-scores')
            ->assertOk()
            // The scores and the last play, nobody else's whole list of words
            ->assertExactJson(['players' => [
                ['id' => 'p-1', 'name' => 'Ada', 'total' => 85, 'turns' => 2, 'last' => 'FAX +33'],
                ['id' => 'p-2', 'name' => 'Ben', 'total' => 111, 'turns' => 2, 'last' => "Tiles left \u{2212}7"],
                ['id' => 'p-3', 'name' => 'Cleo', 'total' => 0, 'turns' => 0, 'last' => null],
            ]]);

        Http::assertSent(fn (Request $request) => $request->header('Authorization') === ['Bearer owner-bearer']);
        Http::assertNotSent(fn (Request $request) => $request->header('Authorization') !== ['Bearer owner-bearer']);
    }

    public function test_a_game_nobody_has_scored_in_has_everyone_on_nothing(): void
    {
        $this->shareToken();
        $this->fakeApi([
            $this->items('/g-1/categories') => Http::response($this->assignedPlayers(['p-1' => 'Ada', 'p-2' => 'Ben']), 200),
            $this->items('/g-1/data') => Http::response(['message' => 'Not found'], 404),
        ]);

        $this->get('/public/game/public-token/player-scores')
            ->assertOk()
            ->assertJsonPath('players.0.total', 0)
            ->assertJsonPath('players.1.total', 0);
    }

    public function test_a_failure_reading_the_public_player_scores_is_a_404(): void
    {
        $this->shareToken();
        $this->fakeApi([$this->items('/g-1/categories') => Http::response(['message' => 'Not found'], 404)]);

        $this->get('/public/game/public-token/player-scores')->assertNotFound();

        $this->fakeApi([
            $this->items('/g-1/categories') => Http::response($this->assignedPlayers(['p-1' => 'Ada']), 200),
            $this->items('/g-1/data') => Http::response(['message' => 'The API is down'], 503),
        ]);

        $this->get('/public/game/public-token/player-scores')->assertNotFound();
    }

    /**
     * The links work for the life of the game, finishing the game removes them for good.
     */
    public function test_finishing_the_game_removes_the_public_links(): void
    {
        $this->shareToken();
        $this->fakeApi([
            $this->items('/g-1?include-players=1') => Http::response($this->game('g-1', ['p-1' => 'Ada']), 200),
            $this->items('/g-1/categories') => Http::response($this->assignedPlayers(['p-1' => 'Ada']), 200),
            $this->items('/g-1/data') => Http::response($this->scoreSheets(['p-1' => $this->scoreSheet([$this->word('QUIZ', 52)])]), 200),
            $this->items('/g-1') => $this->byMethod([
                'GET' => Http::response($this->game('g-1', ['p-1' => 'Ada']), 200),
                'PATCH' => Http::response(null, 204),
            ]),
            $this->items('/g-1/data/p-1') => Http::response(['key' => 'p-1', 'value' => $this->scoreSheet([$this->word('QUIZ', 52)])], 200),
        ]);

        $this->get('/public/score-sheet/public-token')->assertOk();

        $this->signedIn()->post('/game/g-1/complete')->assertRedirect(route('game.show', ['game_id' => 'g-1']));

        $this->get('/public/score-sheet/public-token')->assertNotFound();
        $this->get('/public/game/public-token/player-scores')->assertNotFound();
    }

    public function test_deleting_the_game_removes_the_public_links(): void
    {
        $this->shareToken();
        $this->fakeApi([
            $this->items('/g-1?include-players=1') => Http::response($this->game('g-1', ['p-1' => 'Ada']), 200),
            $this->items('/g-1/data') => Http::response($this->scoreSheets([]), 200),
            $this->items('/g-1/categories') => Http::response([], 200),
            $this->items('/g-1') => Http::response(null, 204),
        ]);

        $this->signedIn()->post('/game/g-1/delete')->assertRedirect(route('home'));

        $this->get('/public/score-sheet/public-token')->assertNotFound();
    }
}
