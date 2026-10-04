<?php
declare(strict_types=1);

namespace App\Http\Controllers\View;

use App\Api\Service;
use App\Http\Controllers\Controller;
use App\Models\ShareToken;
use Illuminate\Http\Request;

/**
 * @author Dean Blackborough <dean@g3d-development.com>
 * @copyright Dean Blackborough (Costs to Expect) 2026
 * https://github.com/costs-to-expect/scrabble/blob/main/LICENSE
 */
class Share extends Controller
{
    /**
     * Everyone's scores for the "Everyone" panel of a public score sheet: the scores and the last play, nobody's
     * whole list of words
     */
    public function playerScores(Request $request, string $token)
    {
        $parameters = ShareToken::parametersFor($token);

        $api = new Service($parameters['owner_bearer']);

        $players_response = $api->getAssignedGamePlayers(
            $parameters['resource_type_id'],
            $parameters['resource_id'],
            $parameters['game_id']
        );

        if ($players_response['status'] !== 200) {
            abort(404, 'Unable to find the game players');
        }

        $game_score_sheets_response = $api->getGameScoreSheets(
            $parameters['resource_type_id'],
            $parameters['resource_id'],
            $parameters['game_id'],
        );

        // Nobody has scored yet when the API has no sheets to give
        if ($game_score_sheets_response['status'] !== 200 && $game_score_sheets_response['status'] !== 404) {
            abort(404, 'Unable to fetch the game scores');
        }

        return response()->json([
            'players' => $this->fetchPlayerScores(
                $game_score_sheets_response['status'] === 200 ? $game_score_sheets_response['content'] : [],
                $players_response['content']
            ),
        ]);
    }

    public function scoreSheet(Request $request, string $token)
    {
        $parameters = ShareToken::parametersFor($token);

        $api = new Service($parameters['owner_bearer']);

        $game = $api->getGame(
            $parameters['resource_type_id'],
            $parameters['resource_id'],
            $parameters['game_id']
        );

        if ($game['status'] !== 200) {
            abort(404, 'Game not found');
        }

        $game = $game['content'];

        $player_score_sheet = $api->getPlayerScoreSheet(
            $parameters['resource_type_id'],
            $parameters['resource_id'],
            $parameters['game_id'],
            $parameters['player_id']
        );

        if ($player_score_sheet['status'] === 404) {
            $add_score_sheet_response = $api->addScoreSheetForPlayer(
                $parameters['resource_type_id'],
                $parameters['resource_id'],
                $parameters['game_id'],
                $parameters['player_id']
            );

            if ($add_score_sheet_response['status'] === 201) {
                return redirect()->route('public.score-sheet', ['token' => $token]);
            }

            abort($add_score_sheet_response['status'], $add_score_sheet_response['content']);
        }

        if ($player_score_sheet['status'] !== 200) {
            abort($player_score_sheet['status'], $player_score_sheet['content']);
        }

        $score_sheet = $player_score_sheet['content']['value'];

        return view(
            'public-score-sheet',
            [
                'token' => $token,

                'player_name' => $parameters['player_name'],

                'config' => $this->sheetConfig(
                    $api,
                    $parameters['resource_type_id'],
                    [['id' => $parameters['player_id'], 'name' => $parameters['player_name']]],
                    [$parameters['player_id'] => $score_sheet],
                    $parameters['player_id'],
                    $game['complete'] === 1,
                    [
                        'turn' => route('public.turn.action', ['token' => $token]),
                        'remove' => route('public.turn.remove.action', ['token' => $token]),
                        'restore' => route('public.turn.restore.action', ['token' => $token]),
                        'players' => route('public.player-scores', ['token' => $token]),
                    ]
                ),
            ]
        );
    }
}
