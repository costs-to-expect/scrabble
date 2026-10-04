<?php
declare(strict_types=1);

namespace App\Http\Controllers\Action;

use App\Actions\Game\AddPlayers;
use App\Actions\Game\ChangeTurn;
use App\Actions\Game\Complete;
use App\Actions\Game\Create;
use App\Actions\Game\Delete;
use App\Actions\Game\Start;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * @author Dean Blackborough <dean@g3d-development.com>
 * @copyright Dean Blackborough (Costs to Expect) 2026
 * https://github.com/costs-to-expect/scrabble/blob/main/LICENSE
 */
class Game extends Controller
{
    public function newGame(Request $request)
    {
        $this->bootstrap($request);

        $action = new Create();
        $result = $action(
            $this->api,
            $this->resource_type_id,
            $this->resource_id,
            $request->only(['name', 'description', 'players'])
        );

        if ($result === 201) {
            return redirect()->route('game.show', ['game_id' => $action->getGameId()]);
        }

        if ($result === 422) {
            return redirect()->route('game.create.view')
                ->withInput()
                ->with('validation.errors',$action->getValidationErrors());
        }

        abort($result, $action->getMessage());
    }

    public function start(Request $request)
    {
        $this->bootstrap($request);

        $action = new Start();
        $result = $action(
            $this->api,
            $this->resource_type_id,
            $this->resource_id,
            $request->only(['players'])
        );

        if ($result === 201) {
            return redirect()->route('game.show', ['game_id' => $action->getGameId()]);
        }

        if ($result === 422) {
            return redirect()->route('home')
                ->withInput()
                ->with('validation.errors',$action->getValidationErrors());
        }

        abort($result, $action->getMessage());
    }

    public function addPlayersToGame(Request $request)
    {
        $this->bootstrap($request);

        $game_id = $request->route('game_id');

        $action = new AddPlayers();
        $result = $action(
            $this->api,
            $this->resource_type_id,
            $this->resource_id,
            $game_id,
            $request->only(['players'])
        );

        if ($result === 201) {
            return redirect()->route('home');
        }

        if ($result === 422) {
            return redirect()->route('game.add-players.view', ['game_id' => $game_id])
                ->withInput()
                ->with('validation.errors',$action->getValidationErrors());
        }

        abort($result, $action->getMessage());
    }

    public function complete(Request $request, string $game_id)
    {
        $this->bootstrap($request);

        $action = new Complete();
        try {
            $result = $action(
                $this->api,
                $this->resource_type_id,
                $this->resource_id,
                $game_id
            );

            if ($result === 204) {
                return redirect()->route('game.show', ['game_id' => $game_id]);
            }
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (\Exception $e) {
            abort(500, $e->getMessage());
        }

        abort(500, 'Unable to finish the game, returned status code: ' . $result);
    }

    public function completeAndPlayAgain(Request $request, string $game_id)
    {
        $this->bootstrap($request);

        $action = new Complete();
        try {
            $result = $action(
                $this->api,
                $this->resource_type_id,
                $this->resource_id,
                $game_id
            );

            if ($result === 204) {

                $game_response = $this->api->getGame(
                    $this->resource_type_id,
                    $this->resource_id,
                    $game_id,
                    ['include-players' => 1]
                );

                if ($game_response['status'] !== 200) {
                   abort(404, 'Unable to find the game');
                }

                $players = [];

                foreach($game_response['content']['players']['collection'] as $player) {
                    $players[] = $player['id'];
                }

                $create_action = new Create();
                $result = $create_action(
                    $this->api,
                    $this->resource_type_id,
                    $this->resource_id,
                    [
                        'name' => Config::get('app.game.game_name'),
                        'description' => Config::get('app.game.game_description'),
                        'players' => $players
                    ]
                );

                if ($result === 201) {
                    return redirect()->route('game.show', ['game_id' => $create_action->getGameId()]);
                }

                abort($result, $create_action->getMessage());

            }
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (\Exception $e) {
            abort(500, $e->getMessage());
        }

        abort(500, 'Unable to finish the game, returned status code: ' . $result);
    }

    public function deleteGame(Request $request, string $game_id)
    {
        $this->bootstrap($request);

        $action = new Delete();
        try {
            $result = $action(
                $this->api,
                $this->resource_type_id,
                $this->resource_id,
                $game_id
            );

            if ($result === 204) {
                return redirect()->route('home');
            }
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (\Exception $e) {
            abort(500, $e->getMessage());
        }

        abort(500, 'Unable to delete the game, unknown error');
    }

    /**
     * Adds a turn to a player's score sheet, or with replace set changes one that is already on it
     */
    public function turn(Request $request)
    {
        return $this->turnFor($request, $request->boolean('replace') ? ChangeTurn::CHANGE : ChangeTurn::ADD);
    }

    /**
     * Takes a turn off the score sheet, it stays on it marked removed
     */
    public function removeTurn(Request $request)
    {
        return $this->turnFor($request, ChangeTurn::REMOVE);
    }

    /**
     * Puts a removed turn back
     */
    public function restoreTurn(Request $request)
    {
        return $this->turnFor($request, ChangeTurn::RESTORE);
    }

    private function turnFor(Request $request, string $operation)
    {
        $this->bootstrap($request);

        return $this->changeTurn(
            $this->api,
            $this->resource_type_id,
            $this->resource_id,
            $request->input('game_id'),
            $request->input('player_id'),
            $operation,
            $request->only(self::TURN_FIELDS)
        );
    }
}
