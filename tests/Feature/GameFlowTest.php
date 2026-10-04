<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ShareToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\FakesTheApi;
use Tests\TestCase;

class GameFlowTest extends TestCase
{
    use FakesTheApi;
    use RefreshDatabase;

    private const PLAYER_NAMES = ['p-1' => 'Ada', 'p-2' => 'Ben', 'p-3' => 'Cleo', 'p-4' => 'Dev', 'p-5' => 'Eve'];

    /**
     * Fakes for creating a game and adding players, a created game is g-new and a player added
     * to it is returned with a name derived from their id.
     */
    private function fakeCreatingGames(array $overrides = []): void
    {
        $this->fakeApi($overrides + [
            $this->items() => Http::response(['id' => 'g-new'], 201),
            $this->items('/g-new/categories') => fn (Request $request) => Http::response([
                'category' => ['id' => $request['category_id'], 'name' => self::PLAYER_NAMES[$request['category_id']] ?? 'Name of '.$request['category_id']],
            ], 201),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function tokenParameters(string $game_id, string $player_id): array
    {
        $token = ShareToken::query()->where('game_id', $game_id)->where('player_id', $player_id)->firstOrFail();

        return $token->parameters;
    }

    private function token(string $token, string $game_id, string $player_id): void
    {
        $share = new ShareToken();
        $share->token = $token;
        $share->game_id = $game_id;
        $share->player_id = $player_id;
        $share->parameters = '{}';
        $share->save();
    }

    /**
     * The players the API is asked to create, in order
     *
     * @return list<string>
     */
    private function createdPlayers(): array
    {
        return array_map(fn (Request $request) => $request['name'], $this->sent('POST', '/resource-types/rt-1/categories'));
    }

    // New game

    public function test_a_game_is_created_and_each_player_added_with_a_share_token(): void
    {
        $this->fakeCreatingGames();

        $this->signedIn()
            ->post('/new-game', [
                'name' => 'Scrabble game',
                'description' => 'Scrabble game created via the Scrabble app',
                'players' => ['p-1', 'p-2'],
            ])
            ->assertRedirect(route('game.show', ['game_id' => 'g-new']));

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === 'http://api.test/v3/resource-types/rt-1/resources/r-1/items'
            && $request->data() === ['name' => 'Scrabble game', 'description' => 'Scrabble game created via the Scrabble app']);
        self::assertCount(2, $this->sent('POST', '/items/g-new/categories'));

        $this->assertDatabaseCount('share_token', 2);
        self::assertSame(
            [
                'resource_type_id' => 'rt-1',
                'resource_id' => 'r-1',
                'game_id' => 'g-new',
                'player_id' => 'p-2',
                'player_name' => 'Ben',
                'owner_bearer' => 'test-bearer-token',
            ],
            $this->tokenParameters('g-new', 'p-2')
        );
        self::assertTrue(Str::isUuid(ShareToken::query()->where('player_id', 'p-1')->value('token')));
    }

    public function test_a_game_is_named_by_the_app_when_the_form_does_not_say(): void
    {
        $this->fakeCreatingGames();

        $this->signedIn()->post('/new-game', ['players' => ['p-1', 'p-2']])->assertRedirect(route('game.show', ['game_id' => 'g-new']));

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === 'http://api.test/v3/resource-types/rt-1/resources/r-1/items'
            && $request->data() === ['name' => 'Scrabble game', 'description' => 'Scrabble game created via the Scrabble app']);
    }

    public function test_a_game_needs_players(): void
    {
        $this->fakeCreatingGames();

        $this->signedIn()
            ->post('/new-game', ['name' => 'Scrabble game', 'description' => 'A game'])
            ->assertRedirect(route('game.create.view'))
            ->assertSessionHas('validation.errors', ['players' => ['errors' => ['Choose at least 2 players']]]);

        self::assertCount(0, $this->sent('POST', '/items'));
    }

    public function test_a_game_needs_at_least_two_players(): void
    {
        $this->fakeCreatingGames();

        $this->signedIn()
            ->post('/new-game', ['name' => 'Scrabble game', 'description' => 'A game', 'players' => ['p-1']])
            ->assertRedirect(route('game.create.view'))
            ->assertSessionHas('validation.errors', ['players' => ['errors' => ['Choose at least 2 players']]]);

        self::assertCount(0, $this->sent('POST', '/items'));
    }

    public function test_the_same_player_chosen_twice_is_one_player(): void
    {
        $this->fakeCreatingGames();

        $this->signedIn()
            ->post('/new-game', ['name' => 'Scrabble game', 'description' => 'A game', 'players' => ['p-1', 'p-1']])
            ->assertRedirect(route('game.create.view'))
            ->assertSessionHas('validation.errors', ['players' => ['errors' => ['Choose at least 2 players']]]);

        self::assertCount(0, $this->sent('POST', '/items'));
    }

    public function test_a_game_has_room_for_four_players(): void
    {
        $this->fakeCreatingGames();

        $this->signedIn()
            ->post('/new-game', ['name' => 'Scrabble game', 'description' => 'A game', 'players' => ['p-1', 'p-2', 'p-3', 'p-4', 'p-5']])
            ->assertRedirect(route('game.create.view'))
            ->assertSessionHas('validation.errors', ['players' => ['errors' => ['A game has room for 4 players at most, choose fewer']]]);

        self::assertCount(0, $this->sent('POST', '/items'));
    }

    public function test_four_players_is_a_full_table(): void
    {
        $this->fakeCreatingGames();

        $this->signedIn()
            ->post('/new-game', ['name' => 'Scrabble game', 'description' => 'A game', 'players' => ['p-1', 'p-2', 'p-3', 'p-4']])
            ->assertRedirect(route('game.show', ['game_id' => 'g-new']));

        self::assertCount(4, $this->sent('POST', '/items/g-new/categories'));
        $this->assertDatabaseCount('share_token', 4);
    }

    public function test_the_new_game_form_asks_for_the_players_to_include(): void
    {
        $this->signedIn()->get('/new-game')
            ->assertOk()
            ->assertSee('action="'.route('game.create.action').'"', false)
            ->assertSee('name="players[]"', false)
            ->assertSee('value="p-1"', false)
            ->assertSee('data-picker-min="2"', false)
            ->assertSee('data-picker-max="4"', false)
            ->assertSee('Ada')
            ->assertSee('Ben');
    }

    public function test_api_validation_errors_creating_a_game_return_to_the_form(): void
    {
        $this->fakeCreatingGames([$this->items() => Http::response([
            'message' => 'Validation error.',
            'fields' => ['name' => ['errors' => ['The name field is required.']]],
        ], 422)]);

        $this->signedIn()
            ->post('/new-game', ['name' => 'x', 'description' => 'A game', 'players' => ['p-1', 'p-2']])
            ->assertRedirect(route('game.create.view'))
            ->assertSessionHas('validation.errors', ['name' => ['errors' => ['The name field is required.']]]);
    }

    public function test_any_other_api_failure_creating_a_game_is_passed_on(): void
    {
        $this->fakeCreatingGames([$this->items() => Http::response(['message' => 'The API is down'], 503)]);

        $this->signedIn()
            ->post('/new-game', ['name' => 'Scrabble game', 'description' => 'A game', 'players' => ['p-1', 'p-2']])
            ->assertStatus(503);
    }

    // Start (first game, players typed in)

    public function test_starting_creates_each_player_then_the_game_and_its_share_tokens(): void
    {
        $this->fakeCreatingGames([
            'api.test/v3/resource-types/rt-1/categories' => fn (Request $request) => Http::response(['id' => 'new-'.strtolower($request['name'])], 201),
            $this->items('/g-new/categories') => fn (Request $request) => Http::response([
                'category' => ['id' => $request['category_id'], 'name' => ucfirst(substr($request['category_id'], 4))],
            ], 201),
        ]);

        $this->signedIn()
            ->post('/start', ['players' => "Cleo\nDev"])
            ->assertRedirect(route('game.show', ['game_id' => 'g-new']));

        self::assertSame(['Cleo', 'Dev'], $this->createdPlayers());

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === 'http://api.test/v3/resource-types/rt-1/resources/r-1/items'
            && $request['name'] === 'Scrabble game');
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/resource-types/rt-1/categories')
            && $request['description'] === 'New player - Added via the Scrabble App - Start');

        $this->assertDatabaseCount('share_token', 2);
        self::assertSame('Cleo', $this->tokenParameters('g-new', 'new-cleo')['player_name']);
        self::assertSame('Dev', $this->tokenParameters('g-new', 'new-dev')['player_name']);
    }

    public function test_starting_uses_the_players_that_already_exist_and_only_creates_the_new_ones(): void
    {
        $this->fakeCreatingGames([
            'api.test/v3/resource-types/rt-1/categories' => fn (Request $request) => Http::response(['id' => 'new-'.strtolower($request['name'])], 201),
        ]);

        // Ada is p-1 in the fake world, however it is typed
        $this->signedIn()
            ->post('/start', ['players' => "ada\nCleo"])
            ->assertRedirect(route('game.show', ['game_id' => 'g-new']));

        self::assertSame(['Cleo'], $this->createdPlayers());
        self::assertSame(['p-1', 'new-cleo'], array_map(fn (Request $request) => $request['category_id'], $this->sent('POST', '/items/g-new/categories')));
    }

    /**
     * Browsers submit the new lines of a textarea as CRLF, and people leave blank lines.
     */
    public function test_starting_copes_with_windows_line_endings_and_blank_lines(): void
    {
        $this->fakeCreatingGames([
            'api.test/v3/resource-types/rt-1/categories' => fn (Request $request) => Http::response(['id' => 'new-'.strtolower($request['name'])], 201),
        ]);

        $this->signedIn()
            ->post('/start', ['players' => "Cleo\r\n\r\n  Dev  \r\nEve"])
            ->assertRedirect(route('game.show', ['game_id' => 'g-new']));

        self::assertSame(['Cleo', 'Dev', 'Eve'], $this->createdPlayers());
    }

    public function test_starting_needs_the_names_of_the_players(): void
    {
        $this->fakeCreatingGames();

        $this->signedIn()
            ->post('/start', ['players' => ''])
            ->assertRedirect(route('home'))
            ->assertSessionHas('validation.errors', ['players' => ['errors' => ['Please enter the player names, one per line']]]);

        $this->signedIn()
            ->post('/start', ['players' => "\r\n  \r\n"])
            ->assertRedirect(route('home'))
            ->assertSessionHas('validation.errors', ['players' => ['errors' => ['Please enter the player names, one per line']]]);

        self::assertCount(0, $this->sent('POST', '/categories'));
    }

    public function test_starting_needs_two_names_and_not_more_than_four(): void
    {
        $this->fakeCreatingGames();

        $this->signedIn()
            ->post('/start', ['players' => 'Cleo'])
            ->assertRedirect(route('home'))
            ->assertSessionHas('validation.errors', ['players' => ['errors' => ['Enter at least 2 names, one per line']]]);

        $this->signedIn()
            ->post('/start', ['players' => "Ada\nBen\nCleo\nDev\nEve"])
            ->assertRedirect(route('home'))
            ->assertSessionHas('validation.errors', ['players' => ['errors' => ['A game has room for 4 players at most, enter fewer names']]]);

        // Nobody was created for a game that cannot be played
        self::assertCount(0, $this->sent('POST', '/categories'));
        self::assertCount(0, $this->sent('POST', '/items'));
    }

    public function test_the_same_name_typed_twice_is_one_player_whatever_the_case(): void
    {
        $this->fakeCreatingGames();

        $this->signedIn()
            ->post('/start', ['players' => "Cleo\ncleo\n CLEO "])
            ->assertRedirect(route('home'))
            ->assertSessionHas('validation.errors', ['players' => ['errors' => ['Enter at least 2 names, one per line']]]);

        self::assertCount(0, $this->sent('POST', '/categories'));
    }

    public function test_starting_reports_a_player_name_the_api_rejects(): void
    {
        $this->fakeCreatingGames([
            'api.test/v3/resource-types/rt-1/categories' => $this->byMethod([
                'GET' => Http::response($this->playerCollection([]), 200),
                'POST' => Http::response([
                    'message' => 'Validation error.',
                    'fields' => ['name' => ['errors' => ['The name has already been taken.']]],
                ], 422),
            ]),
        ]);

        $this->signedIn()
            ->post('/start', ['players' => "Cleo\nDev"])
            ->assertRedirect(route('home'))
            ->assertSessionHas('validation.errors', fn (array $errors) => str_contains($errors['players']['errors'][0], 'Failed to create the player named "Cleo"'));

        self::assertCount(0, $this->sent('POST', '/items'));
    }

    // Adding players to an open game

    public function test_the_add_players_page_offers_the_players_not_yet_in_the_game(): void
    {
        $this->fakeApi([
            $this->items('/g-1') => Http::response($this->game('g-1', ['p-1' => 'Ada']), 200),
            $this->items('/g-1/categories') => Http::response($this->assignedPlayers(['p-1' => 'Ada']), 200),
        ]);

        $this->signedIn()->get('/add-players-to-game/g-1')
            ->assertOk()
            ->assertSee('Playing now')
            ->assertSee('value="p-2"', false)
            ->assertDontSee('value="p-1"', false)
            // Three seats are left of the four
            ->assertSee('data-picker-max="3"', false);
    }

    public function test_players_are_added_to_an_open_game_with_share_tokens(): void
    {
        $this->fakeApi([
            $this->items('/g-1/categories') => $this->byMethod([
                'GET' => Http::response($this->assignedPlayers(['p-1' => 'Ada']), 200),
                'POST' => fn (Request $request) => Http::response([
                    'category' => ['id' => $request['category_id'], 'name' => self::PLAYER_NAMES[$request['category_id']]],
                ], 201),
            ]),
        ]);

        $this->signedIn()
            ->post('/add-players-to-game/g-1', ['players' => ['p-2', 'p-3']])
            ->assertRedirect(route('home'));

        self::assertSame(['p-2', 'p-3'], array_map(fn (Request $request) => $request['category_id'], $this->sent('POST', '/items/g-1/categories')));
        self::assertSame('Cleo', $this->tokenParameters('g-1', 'p-3')['player_name']);
        self::assertSame('test-bearer-token', $this->tokenParameters('g-1', 'p-3')['owner_bearer']);
    }

    public function test_adding_players_needs_a_selection(): void
    {
        $this->fakeApi();

        $this->signedIn()
            ->post('/add-players-to-game/g-1', [])
            ->assertRedirect(route('game.add-players.view', ['game_id' => 'g-1']))
            ->assertSessionHas('validation.errors', ['players' => ['errors' => ['Please select the additional players']]]);
    }

    public function test_a_full_game_cannot_have_more_players_added(): void
    {
        $this->fakeApi([
            $this->items('/g-1/categories') => Http::response($this->assignedPlayers(['p-1' => 'Ada', 'p-2' => 'Ben', 'p-3' => 'Cleo', 'p-4' => 'Dev']), 200),
        ]);

        $this->signedIn()
            ->post('/add-players-to-game/g-1', ['players' => ['p-5']])
            ->assertRedirect(route('game.add-players.view', ['game_id' => 'g-1']))
            ->assertSessionHas('validation.errors', ['players' => ['errors' => ['The game already has 4 players, which is as many as there is room for']]]);

        self::assertCount(0, $this->sent('POST', '/items/g-1/categories'));
    }

    public function test_only_as_many_players_as_there_are_seats_can_be_added(): void
    {
        $this->fakeApi([
            $this->items('/g-1/categories') => Http::response($this->assignedPlayers(['p-1' => 'Ada', 'p-2' => 'Ben', 'p-3' => 'Cleo']), 200),
        ]);

        $this->signedIn()
            ->post('/add-players-to-game/g-1', ['players' => ['p-4', 'p-5']])
            ->assertRedirect(route('game.add-players.view', ['game_id' => 'g-1']))
            ->assertSessionHas('validation.errors', ['players' => ['errors' => ['There is only room for 1 more player in the game']]]);

        self::assertCount(0, $this->sent('POST', '/items/g-1/categories'));
    }

    public function test_adding_a_player_the_api_rejects_returns_to_the_form_or_passes_on_the_failure(): void
    {
        $this->fakeApi([$this->items('/g-1/categories') => $this->byMethod([
            'GET' => Http::response($this->assignedPlayers(['p-2' => 'Ben']), 200),
            'POST' => Http::response([
                'message' => 'Validation error.',
                'fields' => ['category_id' => ['errors' => ['The player is already assigned.']]],
            ], 422),
        ])]);

        $this->signedIn()
            ->post('/add-players-to-game/g-1', ['players' => ['p-1']])
            ->assertRedirect(route('game.add-players.view', ['game_id' => 'g-1']))
            ->assertSessionHas('validation.errors', ['category_id' => ['errors' => ['The player is already assigned.']]]);

        $this->fakeApi([$this->items('/g-2/categories') => $this->byMethod([
            'GET' => Http::response($this->assignedPlayers(['p-2' => 'Ben']), 200),
            'POST' => Http::response(['message' => 'Not allowed'], 403),
        ])]);

        $this->signedIn()
            ->post('/add-players-to-game/g-2', ['players' => ['p-1']])
            ->assertForbidden();
    }

    // Removing a player

    public function test_a_player_is_removed_with_their_score_sheet_assignment_and_share_token(): void
    {
        $this->token('token-p-1', 'g-1', 'p-1');
        $this->token('token-p-2', 'g-1', 'p-2');

        $this->fakeApi([
            $this->items('/g-1/data/p-1') => $this->byMethod([
                'GET' => Http::response(['key' => 'p-1', 'value' => $this->scoreSheet()], 200),
                'DELETE' => Http::response(null, 204),
            ]),
            $this->items('/g-1/categories') => Http::response($this->assignedPlayers(['p-1' => 'Ada', 'p-2' => 'Ben']), 200),
            $this->items('/g-1/categories/ga-p-1') => Http::response(null, 204),
        ]);

        $this->signedIn()
            ->post('/game/g-1/player/p-1/delete')
            ->assertRedirect(route('home'));

        self::assertCount(1, $this->sent('DELETE', '/items/g-1/data/p-1'));
        self::assertCount(1, $this->sent('DELETE', '/items/g-1/categories/ga-p-1'));
        self::assertCount(0, $this->sent('DELETE', '/items/g-1/categories/ga-p-2'));

        $this->assertDatabaseMissing('share_token', ['token' => 'token-p-1']);
        $this->assertDatabaseHas('share_token', ['token' => 'token-p-2']);
    }

    public function test_a_player_without_a_score_sheet_is_still_removed(): void
    {
        $this->fakeApi([
            $this->items('/g-1/data/p-2') => Http::response(['message' => 'Not found'], 404),
            $this->items('/g-1/categories') => Http::response($this->assignedPlayers(['p-2' => 'Ben']), 200),
            $this->items('/g-1/categories/ga-p-2') => Http::response(null, 204),
        ]);

        $this->signedIn()->post('/game/g-1/player/p-2/delete')->assertRedirect(route('home'));

        self::assertCount(0, $this->sent('DELETE', '/data/'));
        self::assertCount(1, $this->sent('DELETE', '/items/g-1/categories/ga-p-2'));
    }

    public function test_a_failure_removing_a_player_is_a_server_error(): void
    {
        $this->fakeApi([
            $this->items('/g-1/data/p-1') => Http::response(['message' => 'Not found'], 404),
            $this->items('/g-1/categories') => Http::response($this->assignedPlayers(['p-1' => 'Ada']), 200),
            $this->items('/g-1/categories/ga-p-1') => Http::response(['message' => 'Locked'], 403),
        ]);

        $this->signedIn()->post('/game/g-1/player/p-1/delete')->assertStatus(500);
    }

    // Deleting a game

    public function test_a_game_is_deleted_with_its_score_sheets_players_and_share_tokens(): void
    {
        $this->token('token-1', 'g-1', 'p-1');
        $this->token('token-other', 'g-2', 'p-1');

        $this->fakeApi([
            $this->items('/g-1?include-players=1') => Http::response($this->game('g-1', ['p-1' => 'Ada', 'p-2' => 'Ben']), 200),
            $this->items('/g-1/data') => Http::response($this->scoreSheets(['p-1' => $this->scoreSheet(), 'p-2' => $this->scoreSheet()]), 200),
            $this->items('/g-1/data/p-1') => Http::response(null, 204),
            $this->items('/g-1/data/p-2') => Http::response(null, 204),
            $this->items('/g-1/categories') => Http::response($this->assignedPlayers(['p-1' => 'Ada', 'p-2' => 'Ben']), 200),
            $this->items('/g-1/categories/ga-p-1') => Http::response(null, 204),
            $this->items('/g-1/categories/ga-p-2') => Http::response(null, 204),
            $this->items('/g-1') => Http::response(null, 204),
        ]);

        $this->signedIn()
            ->post('/game/g-1/delete')
            ->assertRedirect(route('home'));

        foreach (['/data/p-1', '/data/p-2', '/categories/ga-p-1', '/categories/ga-p-2', ''] as $suffix) {
            self::assertCount(1, $this->sentTo('DELETE', $this->items('/g-1'.$suffix)), "DELETE /items/g-1{$suffix}");
        }

        $this->assertDatabaseMissing('share_token', ['game_id' => 'g-1']);
        $this->assertDatabaseHas('share_token', ['token' => 'token-other']);
    }

    public function test_deleting_a_game_that_cannot_be_found_is_a_404(): void
    {
        $this->fakeApi([$this->items('/g-1?include-players=1') => Http::response(['message' => 'Not found'], 404)]);

        $this->signedIn()->post('/game/g-1/delete')->assertNotFound();
    }

    public function test_a_failure_deleting_a_game_is_a_server_error_and_keeps_the_share_tokens(): void
    {
        $this->token('token-1', 'g-1', 'p-1');

        $this->fakeApi([
            $this->items('/g-1?include-players=1') => Http::response($this->game('g-1', ['p-1' => 'Ada']), 200),
            $this->items('/g-1/data') => Http::response([], 200),
            $this->items('/g-1/categories') => Http::response([], 200),
            $this->items('/g-1') => Http::response(['message' => 'Locked'], 403),
        ]);

        $this->signedIn()->post('/game/g-1/delete')->assertStatus(500);

        $this->assertDatabaseCount('share_token', 1);
    }

    // Finishing a game, by hand: Scrabble has no fixed number of turns

    /**
     * @param array<string, array{string, list<array>}> $players player id => [name, the turns on their sheet]
     */
    private function fakeFinishingGame(array $players, array $overrides = []): void
    {
        $names = [];
        $sheets = [];
        foreach ($players as $player_id => [$name, $turns]) {
            $names[$player_id] = $name;
            if ($turns !== null) {
                $sheets[$player_id] = $this->scoreSheet($turns);
            }
        }

        $this->fakeApi($overrides + [
            $this->items('/g-1?include-players=1') => Http::response($this->game('g-1', $names), 200),
            $this->items('/g-1/categories') => Http::response($this->assignedPlayers($names), 200),
            $this->items('/g-1/data') => Http::response($this->scoreSheets($sheets), 200),
            $this->items('/g-1') => Http::response(null, 204),
        ]);
    }

    /**
     * @return array<string, mixed> what the game was finished with
     */
    private function finishedWith(): array
    {
        $patches = $this->sent('PATCH', '/items/g-1');
        self::assertCount(1, $patches);

        $payload = $patches[0]->data();
        self::assertSame(1, $payload['complete']);

        return $payload;
    }

    public function test_finishing_a_game_stores_every_players_numbers_and_the_winner_and_removes_the_share_tokens(): void
    {
        $this->token('token-p-1', 'g-1', 'p-1');
        $this->token('token-p-2', 'g-1', 'p-2');
        $this->token('token-other-game', 'g-2', 'p-1');

        $this->fakeFinishingGame([
            'p-1' => ['Ada', [$this->word('QUIZ', 52), $this->word('FAX', 33), $this->pass(), $this->adjustment(-7, 'Tiles left')]],
            'p-2' => ['Ben', [$this->word('RETAINS', 68, true), $this->word('ZA', 11)]],
        ]);

        $this->signedIn()
            ->post('/game/g-1/complete')
            ->assertRedirect(route('game.show', ['game_id' => 'g-1']));

        $payload = $this->finishedWith();
        $stored = json_decode($payload['game'], true, 512, JSON_THROW_ON_ERROR);

        $ben = [
            'player_id' => 'p-2',
            'player_name' => 'Ben',
            'score' => 129,
            'turns' => 2,
            'words' => 2,
            'passes' => 0,
            'bingos' => 1,
            'word_points' => 129,
            'adjustment' => 0,
            'best' => ['word' => 'RETAINS', 'score' => 118, 'bingo' => true],
            'lowest' => ['word' => 'ZA', 'score' => 11, 'bingo' => false],
            'longest' => ['word' => 'RETAINS', 'score' => 118, 'bingo' => true],
        ];
        $ada = [
            'player_id' => 'p-1',
            'player_name' => 'Ada',
            'score' => 78,
            'turns' => 3,
            'words' => 2,
            'passes' => 1,
            'bingos' => 0,
            'word_points' => 85,
            'adjustment' => -7,
            'best' => ['word' => 'QUIZ', 'score' => 52, 'bingo' => false],
            'lowest' => ['word' => 'FAX', 'score' => 33, 'bingo' => false],
            'longest' => ['word' => 'QUIZ', 'score' => 52, 'bingo' => false],
        ];

        // Best score first, a winner, and what the API keeps beside the game
        self::assertSame([$ben, $ada], $stored['scores']);
        self::assertSame(['player_id' => 'p-2', 'player_name' => 'Ben', 'score' => 129], $stored['winner']);
        self::assertSame('p-2', $payload['winner_id']);
        self::assertSame(129, $payload['score']);

        $this->assertDatabaseMissing('share_token', ['game_id' => 'g-1']);
        $this->assertDatabaseHas('share_token', ['token' => 'token-other-game']);
    }

    /**
     * 197 beats 181 beats 92, a comparator that only ever answers "after" or "equal" ranks
     * 181 first for this order of scores.
     */
    public function test_the_winner_is_the_highest_score_whatever_order_the_players_are_in(): void
    {
        $this->fakeFinishingGame([
            'p-1' => ['Ada', [$this->word('ZA', 92)]],
            'p-2' => ['Ben', [$this->word('QI', 181)]],
            'p-3' => ['Cleo', [$this->word('XU', 197)]],
        ]);

        $this->signedIn()->post('/game/g-1/complete')->assertRedirect(route('game.show', ['game_id' => 'g-1']));

        $payload = $this->finishedWith();

        self::assertSame('p-3', $payload['winner_id']);
        self::assertSame(197, $payload['score']);
        self::assertSame(['p-3', 'p-2', 'p-1'], array_column(json_decode($payload['game'], true)['scores'], 'player_id'));
    }

    public function test_a_tie_keeps_the_order_of_play_and_the_first_player_is_the_stored_winner(): void
    {
        $this->fakeFinishingGame([
            'p-1' => ['Ada', [$this->word('QUIZ', 52)]],
            'p-2' => ['Ben', [$this->word('JAZZ', 52)]],
        ]);

        $this->signedIn()->post('/game/g-1/complete')->assertRedirect(route('game.show', ['game_id' => 'g-1']));

        $stored = json_decode($this->finishedWith()['game'], true);

        // Both are on 52, the stats and the pages treat the game as a win for both of them
        self::assertSame(['p-1', 'p-2'], array_column($stored['scores'], 'player_id'));
        self::assertSame('p-1', $stored['winner']['player_id']);
        self::assertSame([52, 52], array_column($stored['scores'], 'score'));
    }

    public function test_a_player_without_a_score_sheet_finishes_the_game_on_zero(): void
    {
        $this->fakeFinishingGame(['p-1' => ['Ada', [$this->word('QUIZ', 52)]]], [
            $this->items('/g-1/categories') => Http::response($this->assignedPlayers(['p-1' => 'Ada', 'p-2' => 'Ben']), 200),
        ]);

        $this->signedIn()->post('/game/g-1/complete')->assertRedirect(route('game.show', ['game_id' => 'g-1']));

        $scores = json_decode($this->finishedWith()['game'], true)['scores'];

        self::assertSame([['p-1', 52], ['p-2', 0]], array_map(fn (array $score) => [$score['player_id'], $score['score']], $scores));
        self::assertNull($scores[1]['best']);
        self::assertSame(0, $scores[1]['turns']);
    }

    public function test_a_game_nobody_scored_in_can_still_be_finished(): void
    {
        $this->fakeFinishingGame(['p-1' => ['Ada', null], 'p-2' => ['Ben', null]], [
            $this->items('/g-1/data') => Http::response(['message' => 'Not found'], 404),
        ]);

        $this->signedIn()->post('/game/g-1/complete')->assertRedirect(route('game.show', ['game_id' => 'g-1']));

        $stored = json_decode($this->finishedWith()['game'], true);

        self::assertSame([0, 0], array_column($stored['scores'], 'score'));
    }

    public function test_a_turn_that_was_taken_off_does_not_count_when_the_game_is_finished(): void
    {
        $this->fakeFinishingGame([
            'p-1' => ['Ada', [$this->word('QUIZ', 52), $this->word('OOPS', 99, false, null, true)]],
            'p-2' => ['Ben', [$this->word('JAZZ', 40)]],
        ]);

        $this->signedIn()->post('/game/g-1/complete')->assertRedirect(route('game.show', ['game_id' => 'g-1']));

        $scores = json_decode($this->finishedWith()['game'], true)['scores'];

        self::assertSame([['p-1', 52, 1], ['p-2', 40, 1]], array_map(fn (array $score) => [$score['player_id'], $score['score'], $score['words']], $scores));
    }

    public function test_a_game_without_players_cannot_be_finished(): void
    {
        $this->fakeFinishingGame([], [
            $this->items('/g-1/categories') => Http::response([], 200),
            $this->items('/g-1/data') => Http::response([], 200),
        ]);

        $this->signedIn()->post('/game/g-1/complete')->assertStatus(422);

        self::assertCount(0, $this->sent('PATCH', '/items/g-1'));
    }

    public function test_a_game_that_cannot_be_found_cannot_be_finished(): void
    {
        $this->fakeApi([$this->items('/g-1?include-players=1') => Http::response(['message' => 'Not found'], 404)]);

        $this->signedIn()->post('/game/g-1/complete')->assertNotFound();
    }

    public function test_a_failure_finishing_a_game_is_a_server_error_and_keeps_the_share_tokens(): void
    {
        $this->token('token-p-1', 'g-1', 'p-1');

        $this->fakeFinishingGame(['p-1' => ['Ada', [$this->word('QUIZ', 52)]]], [
            $this->items('/g-1') => Http::response(['message' => 'The API is down'], 503),
        ]);

        $this->signedIn()->post('/game/g-1/complete')->assertStatus(500);

        // The game is still going, the link still works
        $this->assertDatabaseHas('share_token', ['token' => 'token-p-1']);
    }

    public function test_finish_and_play_again_starts_a_new_game_with_the_same_players(): void
    {
        $this->token('token-p-1', 'g-1', 'p-1');

        $this->fakeFinishingGame(
            ['p-1' => ['Ada', [$this->word('QUIZ', 52)]], 'p-2' => ['Ben', [$this->word('JAZZ', 40)]]],
            [
                $this->items() => Http::response(['id' => 'g-new'], 201),
                $this->items('/g-new/categories') => fn (Request $request) => Http::response([
                    'category' => ['id' => $request['category_id'], 'name' => self::PLAYER_NAMES[$request['category_id']]],
                ], 201),
            ]
        );

        $this->signedIn()
            ->post('/game/g-1/complete-and-play-again')
            ->assertRedirect(route('game.show', ['game_id' => 'g-new']));

        self::assertCount(1, $this->sent('PATCH', '/items/g-1'));
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === 'http://api.test/v3/resource-types/rt-1/resources/r-1/items'
            && $request['name'] === 'Scrabble game');
        self::assertSame(['p-1', 'p-2'], array_map(fn (Request $request) => $request['category_id'], $this->sent('POST', '/items/g-new/categories')));
        $this->assertDatabaseHas('share_token', ['game_id' => 'g-new', 'player_id' => 'p-2']);
        $this->assertDatabaseMissing('share_token', ['game_id' => 'g-1']);
    }

    public function test_finish_and_play_again_does_not_start_a_game_when_the_old_one_could_not_be_finished(): void
    {
        $this->fakeFinishingGame(['p-1' => ['Ada', [$this->word('QUIZ', 52)]], 'p-2' => ['Ben', []]], [
            $this->items('/g-1') => Http::response(['message' => 'The API is down'], 503),
        ]);

        $this->signedIn()->post('/game/g-1/complete-and-play-again')->assertStatus(500);

        self::assertCount(0, $this->sent('POST', '/items'));
    }
}
