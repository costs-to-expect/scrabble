<?php

namespace App\Http\Controllers;

use App\Actions\Game\ChangeTurn;
use App\Api\Service;
use App\Support\GameBoard;
use App\Support\ScoreRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Config;

/**
 * @author Dean Blackborough <dean@g3d-development.com>
 * @copyright Dean Blackborough (Costs to Expect) 2026
 * https://github.com/costs-to-expect/scrabble/blob/main/LICENSE
 */
class Controller extends BaseController
{
    use AuthorizesRequests;
    use DispatchesJobs;
    use ValidatesRequests;

    /** What the browser sends for a turn, everything else in the request is ignored */
    protected const TURN_FIELDS = ['id', 'kind', 'word', 'score', 'bingo', 'note'];

    protected array $config;

    protected string $item_type_id;
    protected string $item_subtype_id;

    protected ?string $resource_type_id = null;
    protected ?string $resource_id = null;

    protected Service $api;

    public function __construct()
    {
        $this->config = Config::get('app.config');
        $this->item_type_id = $this->config['item_type_id'];
        $this->item_subtype_id = $this->config['item_subtype_id'];
    }

    protected function bootstrap(Request $request)
    {
        $this->api = new Service($request->cookie($this->config['cookie_bearer']));

        $resource_types = $this->api->getResourceTypes(['item-type' => $this->item_type_id]);

        if ($resource_types['status'] === 200) {

            if (count($resource_types['content']) === 1) {

                $resource_type_id = $resource_types['content'][0]['id'];
                $resources = $this->api->getResources($resource_type_id, ['item-subtype' => $this->item_subtype_id]);

                if ($resources['status'] === 200) {

                    if (count($resources['content']) === 1) {
                        $this->resource_type_id = $resource_type_id;
                        $this->resource_id = $resources['content'][0]['id'];

                        return true;
                    }

                    $create_resource_response = $this->api->createResource($resource_type_id);
                    if ($create_resource_response['status'] === 201) {
                        $this->resource_type_id = $resource_type_id;
                        $this->resource_id = $create_resource_response['content']['id'];

                        return true;
                    }
                    abort($create_resource_response['status'], $create_resource_response['content']);
                } else {
                    abort($resources['status'], $resources['content']);
                }
            } else {
                $create_resource_type_response = $this->api->createResourceType();
                if ($create_resource_type_response['status'] === 201) {

                    $this->resource_type_id = $create_resource_type_response['content']['id'];

                    $create_resource_response = $this->api->createResource($this->resource_type_id);
                    if ($create_resource_response['status'] === 201) {
                        $this->resource_id = $create_resource_response['content']['id'];

                        return true;
                    }
                    abort($create_resource_response['status'], $create_resource_response['content']);
                }
                abort($create_resource_type_response['status'], $create_resource_type_response['content']);
            }
        } else {
            abort($resource_types['status'], $resource_types['content']);
        }
    }

    /**
     * Adds, changes, removes or puts back a turn on a player's score sheet and answers the browser with JSON. The
     * signed-in player and the public share link both use it so a turn is checked in one place (ChangeTurn).
     *
     * The answer carries the sheet as it is now, also when the turn is not accepted, so the browser can catch up.
     *
     * @param array<string, mixed> $input the turn as the browser sent it
     */
    protected function changeTurn(
        Service $api,
        mixed $resource_type_id,
        mixed $resource_id,
        mixed $game_id,
        mixed $player_id,
        string $operation,
        array $input
    ): JsonResponse
    {
        foreach ([$resource_type_id, $resource_id, $game_id, $player_id] as $id) {
            if (is_string($id) === false || $id === '') {
                return response()->json(['message' => 'The game and the player are needed to score'], 422);
            }
        }

        $action = new ChangeTurn();
        $status = $action($api, $resource_type_id, $resource_id, $game_id, $player_id, $operation, $input);

        if ($status === 200) {
            return response()->json([
                'message' => $action->getMessage(),
                'sheet' => $this->browserSheet($action->getSheet()),
            ]);
        }

        // A turn that is not allowed comes back with the sheet as it is, so the browser can catch up
        if ($action->failedToSave() === false && $action->getSheet() !== []) {
            return response()->json(['message' => $action->getMessage(), 'sheet' => $this->browserSheet($action->getSheet())], $status);
        }

        return response()->json(['message' => $action->getMessage()], $status);
    }

    /**
     * A score sheet as the browser has it: the turns with every key (removed ones included, they can be put back) and
     * the totals
     *
     * @return array{turns: list<array<string, mixed>>, score: array<string, mixed>}
     */
    protected function browserSheet(array $sheet): array
    {
        return ['turns' => ScoreRules::all($sheet), 'score' => ScoreRules::totals($sheet)];
    }

    /**
     * What the score sheet script needs to draw the screen and to save to it. It is written into the page as JSON, the
     * script keeps no other state. The signed-in page scores for every player in the game, a public page for the one
     * player the link was made for, they differ only in the players and the addresses.
     *
     * @param list<array{id: string, name: string}> $players the players this screen can score for
     * @param array<string, array> $sheets player id => score sheet
     * @param array<string, string> $urls where to save, where to read everyone's scores and where to go back to
     * @param array<string, string> $ids sent with every save by the signed-in page, the public link knows its own
     * @param array<string, int>|null $tones when the page has the colours already, otherwise they are read here
     */
    protected function sheetConfig(
        Service $api,
        string $resource_type_id,
        array $players,
        array $sheets,
        string $focus,
        bool $complete,
        array $urls,
        array $ids = [],
        bool $owner = false,
        ?array $tones = null
    ): array
    {
        if ($tones === null) {
            $everyone = [];
            $players_response = $api->getPlayers($resource_type_id, ['collection' => true]);

            if ($players_response['status'] === 200) {
                foreach ($players_response['content'] as $player) {
                    $everyone[] = ['id' => $player['id'], 'name' => $player['name']];
                }
            }

            $tones = GameBoard::tones($everyone);
        }

        $browser_sheets = [];
        foreach ($players as $player) {
            $browser_sheets[$player['id']] = $this->browserSheet($sheets[$player['id']] ?? []);
        }

        return [
            'owner' => $owner,
            'focus' => $focus,
            'players' => array_map(
                static fn (array $player): array => ['id' => $player['id'], 'name' => $player['name']],
                array_values($players)
            ),
            'sheets' => (object) $browser_sheets,
            'complete' => $complete,
            'corrections' => (bool) config('app.config.score_corrections'),
            'limits' => ScoreRules::limits(),
            'tones' => (object) $tones,
            'urls' => $urls,
            'ids' => (object) $ids,
        ];
    }

    /**
     * Everyone's scores for the "Everyone" panel, a player with no score sheet yet is on zero
     *
     * @param array $game_score_sheets the game's score sheets, keyed by player
     * @param array $players the players assigned to the game
     * @param bool $with_turns the signed-in page also draws everyone's turns, the public page only needs the scores
     * @return list<array<string, mixed>>
     */
    protected function fetchPlayerScores(
        array $game_score_sheets,
        array $players,
        bool $with_turns = false
    ): array
    {
        $scores = [];
        foreach ($players as $player) {
            $scores[$player['category']['id']] = [
                'id' => $player['category']['id'],
                'name' => $player['category']['name'],
                'total' => 0,
                'turns' => 0,
                'last' => null,
            ];

            if ($with_turns) {
                $scores[$player['category']['id']]['sheet'] = $this->browserSheet([]);
            }
        }

        foreach ($game_score_sheets as $score_sheet) {
            // A score sheet for a player who has since been removed from the game
            if (array_key_exists($score_sheet['key'], $scores) === false) {
                continue;
            }

            $totals = ScoreRules::totals($score_sheet['value']);
            $scores[$score_sheet['key']]['total'] = $totals['total'];
            $scores[$score_sheet['key']]['turns'] = $totals['turns'];
            $scores[$score_sheet['key']]['last'] = GameBoard::play(ScoreRules::last($score_sheet['value']));

            if ($with_turns) {
                $scores[$score_sheet['key']]['sheet'] = $this->browserSheet($score_sheet['value']);
            }
        }

        return array_values($scores);
    }
}
