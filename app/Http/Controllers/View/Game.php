<?php
declare(strict_types=1);

namespace App\Http\Controllers\View;

use App\Actions\Game\DeletePlayer;
use App\Http\Controllers\Controller;
use App\Models\ShareToken;
use App\Support\GameBoard;
use App\Support\ScoreRules;
use App\Support\Stats;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;

/**
 * @author Dean Blackborough <dean@g3d-development.com>
 * @copyright Dean Blackborough (Costs to Expect) 2026
 * https://github.com/costs-to-expect/scrabble/blob/main/LICENSE
 */
class Game extends Controller
{
    public function index(Request $request)
    {
        $this->bootstrap($request);

        $offset = max(0, (int) $request->query('offset', 0));
        $limit = min(100, max(1, (int) $request->query('limit', 10)));

        $games_response = $this->api->getGames(
            $this->resource_type_id,
            $this->resource_id,
            [
                'complete' => 1,
                'include-players' => 1,
                'offset' => $offset,
                'limit' => $limit
            ]
        );

        if ($games_response['status'] !== 200) {
            abort($games_response['status'], $games_response['content']);
        }

        $headers = $games_response['headers'] ?? [];

        $pagination = [
            'previous' => (($headers['X-Link-Previous'][0] ?? '') !== ''),
            'next' => (($headers['X-Link-Next'][0] ?? '') !== ''),
            'offset' => (int) ($headers['X-Offset'][0] ?? $offset),
            'limit' => (int) ($headers['X-Limit'][0] ?? $limit),
            'total' => (int) ($headers['X-Total-Count'][0] ?? count($games_response['content'])),
        ];

        $games = [];
        if (count($games_response['content']) > 0) {
            $games = $games_response['content'];
        }

        return view(
            'games',
            [
                'pagination' => $pagination,
                'games' => $games,
            ]
        );
    }

    public function show(Request $request, $game_id)
    {
        $this->bootstrap($request);

        $game_response = $this->api->getGame(
            $this->resource_type_id,
            $this->resource_id,
            $game_id,
            ['include-players' => 1]
        );

        if ($game_response['status'] !== 200) {
            abort(404, 'Unable to find the game');
        }

        $game = $game_response['content'];
        $complete = $game['complete'] === 1;

        // The colour of each player comes from their place in the players list, as it does everywhere else
        $tones = GameBoard::tones($this->players());

        if ($complete) {
            // A finished game keeps every player's numbers with it, there is nothing else to read
            return view(
                'game',
                [
                    'game' => $game,
                    'tones' => $tones,
                    'standings' => [],
                    'share_tokens' => [],
                    'started' => GameBoard::startedAt($game),
                    'stats' => Stats::overall([$game]),
                ]
            );
        }

        $sheets = $this->sheets($game_id);

        return view(
            'game',
            [
                'game' => $game,
                'tones' => $tones,
                'standings' => GameBoard::standings($game['players']['collection'] ?? [], $sheets, $tones),
                'share_tokens' => (new ShareToken())->getShareTokens([$game['id']]),
                'started' => GameBoard::startedAt($game),
                'stats' => null,
            ]
        );
    }

    public function newGame(Request $request)
    {
        $this->bootstrap($request);

        $players = $this->players();

        return view(
            'new-game',
            [
                'resource_type_id' => $this->resource_type_id,
                'resource_id' => $this->resource_id,

                'players' => $players,
                'tones' => GameBoard::tones($players),

                'errors' => session()->get('validation.errors')
            ]
        );
    }

    public function addPlayersToGame(Request $request, string $game_id)
    {
        $this->bootstrap($request);

        $game_response = $this->api->getGame(
            $this->resource_type_id,
            $this->resource_id,
            $game_id
        );

        if ($game_response['status'] !== 200) {
            abort($game_response['status'], $game_response['content']);
        }

        $players = [];
        $game_players = [];

        $all_players = $this->players();

        $game_players_response = $this->api->getAssignedGamePlayers(
            $this->resource_type_id,
            $this->resource_id,
            $game_id
        );

        $assigned_game_players = [];
        if ($game_players_response['status'] === 200 && count($game_players_response['content']) > 0) {
            foreach ($game_players_response['content'] as $player) {
                $assigned_game_players[] = $player['category']['id'];
            }
        }

        foreach ($all_players as $player) {
            if (!in_array($player['id'], $assigned_game_players, true)) {
                $players[] = $player;
            } else {
                $game_players[] = $player['name'];
            }
        }

        return view(
            'add-players-to-game',
            [
                'resource_type_id' => $this->resource_type_id,
                'resource_id' => $this->resource_id,
                'game_id' => $game_id,

                'players' => $players,
                'game_players' => $game_players,
                'room' => max(0, (int) Config::get('app.game.max_players') - count($assigned_game_players)),
                'tones' => GameBoard::tones($all_players),

                'errors' => session()->get('validation.errors')
            ]
        );
    }

    public function deleteGamePlayer(Request $request, string $game_id, string $player_id)
    {
        $this->bootstrap($request);

        $action = new DeletePlayer();
        try {
            $result = $action(
                $this->api,
                $this->resource_type_id,
                $this->resource_id,
                $game_id,
                $player_id
            );

            if ($result === 204) {
                return redirect()->route('home');
            }
        } catch (\Exception $e) {
            abort(500, $e->getMessage());
        }

        abort(500, 'Unable to delete the player from the game, unknown error');
    }

    /**
     * Everyone's scores, and for the signed-in player everyone's turns too, the game screen draws them and keeps them up
     * to date while other players score on their own phones
     */
    public function playerScores(Request $request, string $game_id)
    {
        $this->bootstrap($request);

        $players_response = $this->api->getAssignedGamePlayers(
            $this->resource_type_id,
            $this->resource_id,
            $game_id
        );
        if ($players_response['status'] !== 200) {
            abort(404, 'Unable to find the game players');
        }

        $game_score_sheets_response = $this->api->getGameScoreSheets(
            $this->resource_type_id,
            $this->resource_id,
            $game_id
        );

        // Nobody has scored yet when the API has no sheets to give
        if ($game_score_sheets_response['status'] !== 200 && $game_score_sheets_response['status'] !== 404) {
            abort(404, 'Unable to fetch the game scores');
        }

        return response()->json([
            'players' => $this->fetchPlayerScores(
                $game_score_sheets_response['status'] === 200 ? $game_score_sheets_response['content'] : [],
                $players_response['content'],
                true
            ),
        ]);
    }

    /**
     * The game screen: every player's turns and the entry for a new one, all on one page so the person keeping the
     * score never has to leave it. The player in the address is the one it opens on.
     */
    public function scoreSheet(Request $request, string $game_id, string $player_id)
    {
        $this->bootstrap($request);

        $game_response = $this->api->getGame(
            $this->resource_type_id,
            $this->resource_id,
            $game_id,
            ['include-players' => 1]
        );

        if ($game_response['status'] !== 200) {
            abort($game_response['status'], $game_response['content']);
        }

        $game = $game_response['content'];
        $complete = $game['complete'] === 1;
        $players = [];
        foreach ($game['players']['collection'] ?? [] as $game_player) {
            $players[] = ['id' => $game_player['id'], 'name' => $game_player['name']];
        }

        if (in_array($player_id, array_column($players, 'id'), true) === false) {
            abort(404, 'That player is not in this game');
        }

        $sheets = $this->sheets($game_id);

        // A player who has not scored yet has no score sheet, make one for each of them, a game that is over only
        // reads what there is
        foreach ($players as $player) {
            if (array_key_exists($player['id'], $sheets)) {
                continue;
            }

            $sheets[$player['id']] = $complete ? ScoreRules::emptySheet() : $this->createSheet($game_id, $player['id']);
        }

        $tones = GameBoard::tones($this->players());

        return view(
            'score-sheet',
            [
                'game_id' => $game_id,
                'complete' => $complete,
                'started' => GameBoard::startedAt($game),
                'standings' => $complete ? [] : GameBoard::standings($players, $sheets, $tones),
                'share_tokens' => $complete ? [] : (new ShareToken())->getShareTokens([$game_id]),
                'config' => $this->sheetConfig(
                    $this->api,
                    $this->resource_type_id,
                    $players,
                    $sheets,
                    $player_id,
                    $complete,
                    [
                        'turn' => route('game.turn.action'),
                        'remove' => route('game.turn.remove.action'),
                        'restore' => route('game.turn.restore.action'),
                        'players' => route('game.player-scores', ['game_id' => $game_id]),
                        'back' => $complete ? route('game.show', ['game_id' => $game_id]) : route('home'),
                        'screen' => route('game.score-sheet', ['game_id' => $game_id, 'player_id' => '__player__']),
                    ],
                    ['game_id' => $game_id],
                    true,
                    $tones
                ),
            ]
        );
    }

    /**
     * Everyone who can play, in the order the API lists them
     *
     * @return list<array{id: string, name: string}>
     */
    private function players(): array
    {
        $players_response = $this->api->getPlayers($this->resource_type_id, ['collection' => true]);

        $players = [];
        if ($players_response['status'] === 200 && count($players_response['content']) > 0) {
            foreach ($players_response['content'] as $player) {
                $players[] = [
                    'id' => $player['id'],
                    'name' => $player['name']
                ];
            }
        }

        return $players;
    }

    /**
     * The score sheets of a game, player id => sheet. A game nobody has scored in may have none.
     *
     * @return array<string, array>
     */
    private function sheets(string $game_id): array
    {
        $response = $this->api->getGameScoreSheets($this->resource_type_id, $this->resource_id, $game_id);

        if ($response['status'] !== 200 && $response['status'] !== 404) {
            abort($response['status'], $response['content']);
        }

        $sheets = [];
        foreach ($response['status'] === 200 ? $response['content'] : [] as $score_sheet) {
            $sheets[$score_sheet['key']] = $score_sheet['value'];
        }

        return $sheets;
    }

    /**
     * Makes a player's score sheet and gives it back. If that fails because another screen made it first (a player
     * opened their own link at the same moment), theirs is the one to use.
     */
    private function createSheet(string $game_id, string $player_id): array
    {
        $created = $this->api->addScoreSheetForPlayer($this->resource_type_id, $this->resource_id, $game_id, $player_id);

        if ($created['status'] === 201) {
            return ScoreRules::emptySheet();
        }

        $existing = $this->api->getPlayerScoreSheet($this->resource_type_id, $this->resource_id, $game_id, $player_id);

        if ($existing['status'] !== 200) {
            abort($created['status'], $created['content']);
        }

        return is_array($existing['content']['value'] ?? null) ? $existing['content']['value'] : ScoreRules::emptySheet();
    }
}
