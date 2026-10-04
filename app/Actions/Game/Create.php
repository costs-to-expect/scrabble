<?php
declare(strict_types=1);

namespace App\Actions\Game;

use App\Actions\Action;
use App\Actions\Game\Concerns\AssignsPlayers;
use App\Api\Service;
use App\Support\GameBoard;
use Illuminate\Support\Facades\Config;

/**
 * @author Dean Blackborough <dean@g3d-development.com>
 * @copyright Dean Blackborough (Costs to Expect) 2026
 * https://github.com/costs-to-expect/scrabble/blob/main/LICENSE
 */
class Create extends Action
{
    use AssignsPlayers;

    public function __invoke(Service $api, string $resource_type_id, string $resource_id, array $input): int
    {
        $players = $input['players'] ?? null;
        $players = is_array($players) ? array_values(array_unique(array_filter($players, 'is_string'))) : [];

        $problem = GameBoard::playersProblem(count($players), (int) Config::get('app.game.min_players'), (int) Config::get('app.game.max_players'));
        if ($problem !== null) {
            $this->message = 'Missing players';
            $this->validation_errors['players'] = ['errors' => [$problem]];
            return 422;
        }

        $create_game_response = $api->createGame(
            $resource_type_id,
            $resource_id,
            $input['name'] ?? Config::get('app.game.game_name'),
            $input['description'] ?? Config::get('app.game.game_description')
        );

        if ($create_game_response['status'] === 201) {
            $this->game_id = $create_game_response['content']['id'];

            return $this->assignPlayers($api, $resource_type_id, $resource_id, $this->game_id, $players);
        }

        $this->message = $create_game_response['content'];

        if ($create_game_response['status'] === 422) {
            $this->validation_errors = $create_game_response['fields'];
        }

        return $create_game_response['status'];
    }
}
