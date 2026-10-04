<?php
declare(strict_types=1);

namespace App\Http\Controllers\Action;

use App\Actions\Game\ChangeTurn;
use App\Api\Service;
use App\Http\Controllers\Controller;
use App\Models\ShareToken;
use Illuminate\Http\Request;

/**
 * Scoring through the public link of a player, the link is a token the app maps back to the game, the player and the
 * owner's bearer token. It can only ever score for the player it was made for.
 *
 * @author Dean Blackborough <dean@g3d-development.com>
 * @copyright Dean Blackborough (Costs to Expect) 2026
 * https://github.com/costs-to-expect/scrabble/blob/main/LICENSE
 */
class Share extends Controller
{
    public function turn(Request $request, string $token)
    {
        return $this->turnFor($token, $request->boolean('replace') ? ChangeTurn::CHANGE : ChangeTurn::ADD, $request);
    }

    public function removeTurn(Request $request, string $token)
    {
        return $this->turnFor($token, ChangeTurn::REMOVE, $request);
    }

    public function restoreTurn(Request $request, string $token)
    {
        return $this->turnFor($token, ChangeTurn::RESTORE, $request);
    }

    private function turnFor(string $token, string $operation, Request $request)
    {
        $parameters = ShareToken::parametersFor($token);

        // The game and the player are the link's, never whatever the browser sent
        return $this->changeTurn(
            new Service($parameters['owner_bearer']),
            $parameters['resource_type_id'],
            $parameters['resource_id'],
            $parameters['game_id'],
            $parameters['player_id'],
            $operation,
            $request->only(self::TURN_FIELDS)
        );
    }
}
