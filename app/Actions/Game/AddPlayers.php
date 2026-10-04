<?php
declare(strict_types=1);

namespace App\Actions\Game;

use App\Actions\Action;
use App\Actions\Game\Concerns\AssignsPlayers;
use App\Api\Service;
use Illuminate\Support\Facades\Config;

/**
 * @author Dean Blackborough <dean@g3d-development.com>
 * @copyright Dean Blackborough (Costs to Expect) 2026
 * https://github.com/costs-to-expect/scrabble/blob/main/LICENSE
 */
class AddPlayers extends Action
{
    use AssignsPlayers;

    public function __invoke(
        Service $api,
        string $resource_type_id,
        string $resource_id,
        string $game_id,
        array $input
    ): int
    {
        $players = $input['players'] ?? null;
        $players = is_array($players) ? array_values(array_unique(array_filter($players, 'is_string'))) : [];

        if ($players === []) {
            $this->message = 'Missing players';
            $this->validation_errors['players'] = ['errors' => ['Please select the additional players']];
            return 422;
        }

        // The board only has room for so many racks
        $playing_response = $api->getAssignedGamePlayers($resource_type_id, $resource_id, $game_id);
        if ($playing_response['status'] !== 200) {
            $this->message = 'Unable to find the game players';
            return $playing_response['status'];
        }

        $max = (int) Config::get('app.game.max_players');
        $room = $max - count($playing_response['content']);

        if (count($players) > $room) {
            $this->message = 'No room for the players';
            $this->validation_errors['players'] = ['errors' => [
                $room <= 0
                    ? 'The game already has ' . $max . ' players, which is as many as there is room for'
                    : 'There is only room for ' . $room . ' more ' . ($room === 1 ? 'player' : 'players') . ' in the game',
            ]];
            return 422;
        }

        return $this->assignPlayers($api, $resource_type_id, $resource_id, $game_id, $players);
    }
}
