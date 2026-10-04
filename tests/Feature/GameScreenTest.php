<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ShareToken;
use App\Support\ScoreRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\FakesTheApi;
use Tests\TestCase;

/**
 * The game screen: every player's turns and the entry for a new one on a single page, so the person keeping the score
 * never has to leave it. The player in the address is the one it opens on.
 */
class GameScreenTest extends TestCase
{
    use FakesTheApi;
    use RefreshDatabase;

    /**
     * @param array<string, array> $sheets player id => score sheet, a player without one has not scored yet
     * @param array<string, string> $players
     */
    private function fakeScreen(array $sheets = [], bool $complete = false, array $overrides = [], array $players = ['p-1' => 'Ada', 'p-2' => 'Ben']): void
    {
        $this->fakeApi($overrides + [
            $this->items('/g-1?include-players=1') => Http::response($this->game('g-1', $players, $complete), 200),
            $this->items('/g-1/data') => $this->byMethod([
                'GET' => Http::response($this->scoreSheets($sheets), 200),
                'POST' => Http::response(['id' => 'ds-1'], 201),
            ]),
        ]);
    }

    /**
     * The settings the game screen's script reads, written into the page as JSON
     *
     * @return array<string, mixed>
     */
    private function sheetConfig(string $html): array
    {
        self::assertSame(1, preg_match('#<script type="application/json" id="sheet-config">(.*?)</script>#s', $html, $matches), 'the page carries its settings');

        return json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
    }

    private function screen(string $player = 'p-1'): \Illuminate\Testing\TestResponse
    {
        return $this->signedIn()->get("/game/g-1/player/{$player}/score-sheet");
    }

    private function twoSheets(): array
    {
        return [
            'p-1' => $this->scoreSheet([$this->word('QUIZ', 52, false, 'turn-0001'), $this->word('FAX', 33, false, 'turn-0002')]),
            'p-2' => $this->scoreSheet([$this->word('RETAINS', 68, true, 'turn-0003')]),
        ];
    }

    public function test_the_screen_opens_on_the_player_in_the_address(): void
    {
        $this->fakeScreen($this->twoSheets());

        $response = $this->screen('p-1')
            ->assertOk()
            ->assertSee('Player: Ada')
            ->assertSee('<title>Scrabble Game Scorer: Score sheet</title>', false);

        $config = $this->sheetConfig($response->getContent());

        self::assertSame('p-1', $config['focus']);
        self::assertTrue($config['owner']);
        self::assertSame([['id' => 'p-1', 'name' => 'Ada'], ['id' => 'p-2', 'name' => 'Ben']], $config['players']);
        // Sent with every turn, the server uses these, never the share link's
        self::assertSame(['game_id' => 'g-1'], $config['ids']);
        self::assertSame(
            [
                'turn' => route('game.turn.action'),
                'remove' => route('game.turn.remove.action'),
                'restore' => route('game.turn.restore.action'),
                'players' => route('game.player-scores', ['game_id' => 'g-1']),
                'back' => route('home'),
                'screen' => route('game.score-sheet', ['game_id' => 'g-1', 'player_id' => '__player__']),
            ],
            $config['urls']
        );
        self::assertSame(ScoreRules::limits(), $config['limits']);
        self::assertFalse($config['complete']);
    }

    public function test_it_opens_on_another_player_when_the_address_says_so(): void
    {
        $this->fakeScreen($this->twoSheets());

        $response = $this->screen('p-2')->assertOk()->assertSee('Player: Ben');

        self::assertSame('p-2', $this->sheetConfig($response->getContent())['focus']);
    }

    public function test_a_player_who_is_not_in_the_game_is_a_404(): void
    {
        $this->fakeScreen($this->twoSheets());

        $this->screen('p-9')->assertNotFound();

        // Nothing was made for a player the game does not have
        self::assertCount(0, $this->sent('POST', '/items/g-1/data'));
    }

    public function test_the_page_carries_every_players_stored_turns_for_the_script_to_draw(): void
    {
        $sheets = $this->twoSheets();
        $this->fakeScreen($sheets);

        $config = $this->sheetConfig($this->screen()->assertOk()->getContent());

        self::assertSame(['p-1', 'p-2'], array_keys($config['sheets']));
        self::assertSame(['turns' => $sheets['p-1']['turns'], 'score' => $sheets['p-1']['score']], $config['sheets']['p-1']);
        self::assertSame(['turns' => $sheets['p-2']['turns'], 'score' => $sheets['p-2']['score']], $config['sheets']['p-2']);
    }

    public function test_a_turn_that_was_taken_off_is_carried_so_it_can_be_put_back_and_does_not_count(): void
    {
        $this->fakeScreen([
            'p-1' => $this->scoreSheet([$this->word('QUIZ', 52, false, 'turn-0001'), $this->word('OOPS', 99, false, 'turn-0002', true)]),
            'p-2' => $this->scoreSheet(),
        ]);

        $html = $this->screen()->assertOk()->getContent();
        $config = $this->sheetConfig($html);

        self::assertSame([false, true], array_column($config['sheets']['p-1']['turns'], 'removed'));
        self::assertSame(52, $config['sheets']['p-1']['score']['total']);
        self::assertMatchesRegularExpression('/id="total"[^>]*>52</', $html);
    }

    public function test_every_turn_is_carried_with_every_key_whatever_the_api_stored(): void
    {
        $this->fakeScreen(['p-1' => ['turns' => [['id' => 'turn-0001', 'word' => 'QUIZ', 'score' => 52]]], 'p-2' => $this->scoreSheet()]);

        $config = $this->sheetConfig($this->screen()->assertOk()->getContent());

        self::assertSame(
            ['id' => 'turn-0001', 'kind' => 'word', 'word' => 'QUIZ', 'score' => 52, 'bingo' => false, 'note' => '', 'at' => '', 'removed' => false],
            $config['sheets']['p-1']['turns'][0]
        );
    }

    public function test_every_player_has_the_colour_of_their_place_in_the_players_list(): void
    {
        $this->fakeScreen($this->twoSheets());

        $config = $this->sheetConfig($this->screen()->assertOk()->getContent());

        self::assertSame(['p-1' => 0, 'p-2' => 1], $config['tones']);
    }

    public function test_the_first_paint_has_the_totals_of_the_player_in_the_address(): void
    {
        $this->fakeScreen([
            'p-1' => $this->scoreSheet([$this->word('QUIZ', 52), $this->word('RETAINS', 68, true), $this->pass(), $this->adjustment(-7, 'Tiles left')]),
            'p-2' => $this->scoreSheet(),
        ]);

        $html = $this->screen()->assertOk()->getContent();

        // 52 + 118 + 0 - 7, three turns (the adjustment is not one), the best word is the bingo
        self::assertMatchesRegularExpression('/id="total"[^>]*>163</', $html);
        self::assertStringContainsString('&middot; 3 turns', $html);
        self::assertMatchesRegularExpression('/id="head-best"[^>]*>118</', $html);
        self::assertMatchesRegularExpression('/id="head-bingos"[^>]*>1</', $html);
    }

    public function test_a_player_who_has_not_played_starts_on_nothing(): void
    {
        $this->fakeScreen(['p-1' => $this->scoreSheet(), 'p-2' => $this->scoreSheet()]);

        $html = $this->screen()->assertOk()->getContent();

        self::assertMatchesRegularExpression('/id="total"[^>]*>0</', $html);
        self::assertStringContainsString('&middot; 0 turns', $html);
        self::assertMatchesRegularExpression('/id="head-best"[^>]*>–</', $html);
    }

    public function test_corrections_are_only_offered_when_they_are_switched_on(): void
    {
        $this->fakeScreen($this->twoSheets());

        config(['app.config.score_corrections' => false]);
        $off = $this->screen()->assertOk();
        self::assertFalse($this->sheetConfig($off->getContent())['corrections']);
        $off->assertSee('can&rsquo;t be changed', false)->assertDontSee('Tap <strong>Undo</strong>', false);

        config(['app.config.score_corrections' => true]);
        $on = $this->screen()->assertOk();
        self::assertTrue($this->sheetConfig($on->getContent())['corrections']);
        $on->assertSee('Tap <strong>Undo</strong>', false)->assertDontSee('can&rsquo;t be changed', false);
    }

    public function test_the_scripts_and_the_ways_to_manage_the_game_are_on_the_page_of_an_open_game(): void
    {
        $this->fakeScreen($this->twoSheets());

        $this->screen()
            ->assertOk()
            ->assertSee('js/ui.js', false)
            ->assertSee('js/turn-entry.js', false)
            ->assertSee('js/score-sheet.js', false)
            ->assertSee('id="add-turn"', false)
            ->assertSee('Add a turn')
            ->assertSee('Finished playing?')
            ->assertSee('data-dialog-open="finish-dialog"', false)
            ->assertSee('data-dialog-open="share-dialog"', false)
            ->assertSee('data-dialog-open="remove-dialog"', false)
            ->assertSee('data-dialog-open="delete-dialog"', false)
            ->assertSee('id="finish-unsaved"', false)
            ->assertSee(route('game.complete.action', ['game_id' => 'g-1']), false)
            ->assertSee(route('game.add-players.view', ['game_id' => 'g-1']), false)
            ->assertSee(route('home'), false)
            ->assertDontSee('This game is finished');
    }

    public function test_everyone_in_the_game_has_a_share_link_in_the_dialog(): void
    {
        ShareToken::issue('rt-1', 'r-1', 'g-1', 'p-1', 'Ada', 'owner-bearer');
        $token = (string) ShareToken::query()->value('token');
        $this->fakeScreen($this->twoSheets());

        $this->screen()
            ->assertOk()
            ->assertSee('data-copy="'.route('public.score-sheet', ['token' => $token]).'"', false)
            // Ben has no link
            ->assertSee('No link')
            ->assertDontSee('owner-bearer');
    }

    public function test_a_game_with_every_seat_taken_has_no_add_player_link(): void
    {
        $players = ['p-1' => 'Ada', 'p-2' => 'Ben', 'p-3' => 'Cleo', 'p-4' => 'Dev'];
        $this->fakeScreen([], false, [], $players);

        $this->screen()
            ->assertOk()
            ->assertDontSee(route('game.add-players.view', ['game_id' => 'g-1']), false);
    }

    public function test_a_finished_game_is_a_read_only_screen(): void
    {
        $this->fakeScreen($this->twoSheets(), complete: true);

        $response = $this->screen()
            ->assertOk()
            ->assertSee('This game is finished')
            ->assertSee('The scores as they were when the game was finished.')
            ->assertDontSee('id="add-turn"', false)
            ->assertDontSee('Finished playing?')
            ->assertDontSee('data-dialog-open="finish-dialog"', false)
            ->assertDontSee('data-dialog-open="delete-dialog"', false);

        // The script still draws the turns, it does not let anyone add one, and back goes to the finished game
        $config = $this->sheetConfig($response->getContent());
        self::assertTrue($config['complete']);
        self::assertSame(route('game.show', ['game_id' => 'g-1']), $config['urls']['back']);
        $response->assertSee('js/score-sheet.js', false);
    }

    public function test_a_finished_game_never_makes_score_sheets(): void
    {
        $this->fakeScreen(['p-1' => $this->scoreSheet([$this->word('QUIZ', 52)])], complete: true);

        $config = $this->sheetConfig($this->screen()->assertOk()->getContent());

        self::assertCount(0, $this->sent('POST', '/items/g-1/data'));
        // A player who never scored is on nothing
        self::assertSame(0, $config['sheets']['p-2']['score']['total']);
        self::assertSame([], $config['sheets']['p-2']['turns']);
    }

    public function test_a_player_without_a_score_sheet_is_given_an_empty_one(): void
    {
        $this->fakeScreen(['p-1' => $this->scoreSheet([$this->word('QUIZ', 52)])]);

        $config = $this->sheetConfig($this->screen('p-2')->assertOk()->getContent());

        $created = $this->sent('POST', '/items/g-1/data');
        self::assertCount(1, $created);
        self::assertSame('p-2', $created[0]['key']);
        self::assertSame(ScoreRules::emptySheet(), json_decode($created[0]['value'], true, 512, JSON_THROW_ON_ERROR));

        self::assertSame(['turns' => [], 'score' => ScoreRules::emptySheet()['score']], $config['sheets']['p-2']);
    }

    public function test_every_player_without_a_score_sheet_is_given_one_not_only_the_one_in_the_address(): void
    {
        $this->fakeScreen();

        $this->screen('p-1')->assertOk();

        self::assertSame(['p-1', 'p-2'], array_map(fn (Request $request) => $request['key'], $this->sent('POST', '/items/g-1/data')));
    }

    public function test_a_game_nobody_has_scored_in_has_no_sheets_to_read(): void
    {
        $this->fakeScreen([], overrides: [
            $this->items('/g-1/data') => $this->byMethod([
                'GET' => Http::response(['message' => 'Not found'], 404),
                'POST' => Http::response(['id' => 'ds-1'], 201),
            ]),
        ]);

        $this->screen()->assertOk()->assertSee('Player: Ada');

        self::assertCount(2, $this->sent('POST', '/items/g-1/data'));
    }

    public function test_when_two_screens_make_the_same_sheet_at_once_the_one_that_got_there_first_is_used(): void
    {
        $this->fakeScreen([], overrides: [
            $this->items('/g-1/data') => $this->byMethod([
                'GET' => Http::response($this->scoreSheets([]), 200),
                'POST' => Http::response(['message' => 'The key already exists'], 422),
            ]),
            $this->items('/g-1/data/p-1') => Http::response(['key' => 'p-1', 'value' => $this->scoreSheet([$this->word('QUIZ', 52, false, 'turn-0001')])], 200),
            $this->items('/g-1/data/p-2') => Http::response(['key' => 'p-2', 'value' => $this->scoreSheet()], 200),
        ]);

        $config = $this->sheetConfig($this->screen()->assertOk()->getContent());

        self::assertSame(52, $config['sheets']['p-1']['score']['total']);
    }

    public function test_a_failure_creating_a_score_sheet_is_passed_on(): void
    {
        $this->fakeScreen([], overrides: [
            $this->items('/g-1/data') => $this->byMethod([
                'GET' => Http::response($this->scoreSheets([]), 200),
                'POST' => Http::response(['message' => 'The API is down'], 503),
            ]),
            $this->items('/g-1/data/p-1') => Http::response(['message' => 'Not found'], 404),
        ]);

        $this->screen()->assertStatus(503);
    }

    public function test_a_game_that_cannot_be_found_is_passed_on(): void
    {
        $this->fakeApi([$this->items('/g-404?include-players=1') => Http::response(['message' => 'Not found'], 404)]);

        $this->signedIn()->get('/game/g-404/player/p-1/score-sheet')->assertNotFound();
    }

    public function test_a_failure_reading_the_score_sheets_is_passed_on(): void
    {
        $this->fakeScreen([], overrides: [
            $this->items('/g-1/data') => Http::response(['message' => 'The API is down'], 503),
        ]);

        $this->screen()->assertStatus(503);
    }

    public function test_the_screen_is_read_live_and_as_the_signed_in_player(): void
    {
        $this->fakeScreen($this->twoSheets());

        $this->screen()->assertOk();

        Http::assertNotSent(fn (Request $request) => $request->method() === 'GET' && $request->header('X-Skip-Cache') !== ['true']);
        Http::assertNotSent(fn (Request $request) => $request->header('Authorization') !== ['Bearer '.self::BEARER]);
    }

    public function test_the_page_does_not_let_the_word_in_a_turn_break_out_of_the_settings(): void
    {
        $this->fakeScreen([
            'p-1' => $this->scoreSheet([$this->adjustment(-7, '</script><script>alert(1)</script>')]),
            'p-2' => $this->scoreSheet(),
        ]);

        $html = $this->screen()->assertOk()->getContent();

        self::assertStringNotContainsString('</script><script>alert(1)', $html);
        self::assertSame('</script><script>alert(1)</script>', $this->sheetConfig($html)['sheets']['p-1']['turns'][0]['note']);
    }

    public function test_a_player_name_cannot_break_the_page(): void
    {
        $this->fakeScreen([], players: ['p-1' => '<b>Ada</b>', 'p-2' => 'Ben']);

        $html = $this->screen()->assertOk()->getContent();

        self::assertStringNotContainsString('<b>Ada</b>', $html);
        self::assertStringContainsString('Player: &lt;b&gt;Ada&lt;/b&gt;', $html);
    }

    public function test_the_add_turn_address_opens_the_entry_sheet_on_the_screen_that_asked(): void
    {
        $this->fakeScreen($this->twoSheets());

        // The home page links to ?add=1, the script opens the entry sheet for it, the server only has to serve the screen
        $this->signedIn()->get('/game/g-1/player/p-2/score-sheet?add=1')
            ->assertOk()
            ->assertSee('Player: Ben')
            ->assertSee('id="add-turn"', false);
    }
}
