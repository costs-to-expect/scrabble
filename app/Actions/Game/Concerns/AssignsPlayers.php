<?php
declare(strict_types=1);

namespace App\Actions\Game\Concerns;

use App\Api\Service;
use App\Models\ShareToken;
use Illuminate\Support\Facades\Config;

/**
 * Putting players in a game: each one is assigned to the game in the API and gets their share link.
 *
 * @property string $message
 * @property array $validation_errors
 */
trait AssignsPlayers
{
    /**
     * Assigns the players to the game and makes the share link of each one. Stops at the first player the API
     * refuses, and keeps the API's reason for the controller.
     *
     * @param list<string> $player_ids
     * @return int 201 when every player is in the game, otherwise the status the API answered with
     */
    private function assignPlayers(
        Service $api,
        string $resource_type_id,
        string $resource_id,
        string $game_id,
        array $player_ids
    ): int
    {
        $bearer = request()->cookie(Config::get('app.config.cookie_bearer'));

        foreach ($player_ids as $player_id) {
            $response = $api->addPlayerToGame($resource_type_id, $resource_id, $game_id, $player_id);

            if ($response['status'] !== 201) {
                $this->message = (string) $response['content'];

                if ($response['status'] === 422) {
                    $this->validation_errors = $response['fields'] ?? [];
                }

                return $response['status'];
            }

            try {
                ShareToken::issue(
                    $resource_type_id,
                    $resource_id,
                    $game_id,
                    $player_id,
                    $response['content']['category']['name'],
                    $bearer
                );
            } catch (\Exception) {
                abort(500, 'Failed to create share token for player, create token manually');
            }
        }

        return 201;
    }
}
