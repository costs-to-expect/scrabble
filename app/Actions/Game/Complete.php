<?php
declare(strict_types=1);

namespace App\Actions\Game;

use App\Actions\Action;
use App\Api\Service;
use App\Models\ShareToken;
use App\Support\GameBoard;
use App\Support\ScoreRules;
use App\Support\Stats;

/**
 * Finishes a game. Scrabble has no fixed number of turns, so the person running the game decides when it is over.
 *
 * The game is stored with every player's numbers (their score, the words they played, their best and lowest word...)
 * as they stood when it was finished, so the games list, the game page and the stats read them from the games alone
 * and never have to read a score sheet again.
 *
 * @author Dean Blackborough <dean@g3d-development.com>
 * @copyright Dean Blackborough (Costs to Expect) 2026
 * https://github.com/costs-to-expect/scrabble/blob/main/LICENSE
 */
class Complete extends Action
{
    public function __invoke(
        Service $api,
        string $resource_type_id,
        string $resource_id,
        string $game_id
    ): int
    {
        $game_response = $api->getGame(
            $resource_type_id,
            $resource_id,
            $game_id,
            ['include-players' => true]
        );
        if ($game_response['status'] !== 200) {
            abort(404, 'Unable to find the game');
        }

        $assigned_players_response = $api->getAssignedGamePlayers($resource_type_id, $resource_id, $game_id);
        if ($assigned_players_response['status'] !== 200) {
            abort(404, 'Unable to find the game players');
        }

        // A game nobody has scored in has no score sheets, and can still be finished
        $game_score_sheets_response = $api->getGameScoreSheets($resource_type_id, $resource_id, $game_id);
        if ($game_score_sheets_response['status'] !== 200 && $game_score_sheets_response['status'] !== 404) {
            abort(404, 'Unable to fetch the game scores');
        }

        $sheets = [];
        foreach ($game_score_sheets_response['status'] === 200 ? $game_score_sheets_response['content'] : [] as $score_sheet) {
            $sheets[$score_sheet['key']] = $score_sheet['value'];
        }

        // A player who never scored has no score sheet, they finish on zero
        $entries = [];
        foreach ($assigned_players_response['content'] as $player) {
            $entries[] = Stats::entry(
                $player['category']['id'],
                $player['category']['name'],
                $sheets[$player['category']['id']] ?? ScoreRules::emptySheet()
            );
        }

        if ($entries === []) {
            abort(422, 'There are no players in the game to finish it for');
        }

        $scores = GameBoard::ranked($entries);

        $winner = [
            'player_id' => $scores[0]['player_id'],
            'player_name' => $scores[0]['player_name'],
            'score' => $scores[0]['score'],
        ];

        $update_game_response = $api->updateGame(
            $resource_type_id,
            $resource_id,
            $game_id,
            [
                'game' => json_encode(['scores' => $scores, 'winner' => $winner], JSON_THROW_ON_ERROR),
                'winner_id' => $winner['player_id'],
                'score' => $winner['score'],
                'complete' => 1
            ]
        );

        if ($update_game_response['status'] === 204) {
            // The links only live until the game is finished. They go once the game has been saved as finished, a
            // game that could not be finished keeps its links.
            ShareToken::query()->where('game_id', $game_id)->delete();

            return 204;
        }

        $this->message = (string) $update_game_response['content'];

        return $update_game_response['status'];
    }
}
