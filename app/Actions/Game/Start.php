<?php
declare(strict_types=1);

namespace App\Actions\Game;

use App\Actions\Action;
use App\Actions\Game\Concerns\AssignsPlayers;
use App\Api\Service;
use App\Support\GameBoard;
use Illuminate\Support\Facades\Config;

/**
 * Starts the first game from the names typed into the box on the home page: creates the players, then the game.
 *
 * @author Dean Blackborough <dean@g3d-development.com>
 * @copyright Dean Blackborough (Costs to Expect) 2026
 * https://github.com/costs-to-expect/scrabble/blob/main/LICENSE
 */
class Start extends Action
{
    use AssignsPlayers;

    /** @var list<string> */
    private array $players = [];

    public function __invoke(Service $api, string $resource_type_id, string $resource_id, array $input): int
    {
        if (array_key_exists('players', $input) === false || $input['players'] === null || $input['players'] === '') {
            $this->message = 'Missing players';
            $this->validation_errors['players'] = ['errors' => ['Please enter the player names, one per line']];
            return 422;
        }

        // Browsers submit the new lines of a textarea as CRLF, and people leave blank lines
        $names = array_values(array_filter(
            array_map('trim', preg_split('/\R/', (string) $input['players'])),
            static fn (string $name): bool => $name !== ''
        ));

        if ($names === []) {
            $this->message = 'Missing players';
            $this->validation_errors['players'] = ['errors' => ['Please enter the player names, one per line']];
            return 422;
        }

        // The same name typed twice is one player, count them once or "Ada" and "ada" would pass for two
        $unique = [];
        foreach ($names as $name) {
            $unique[mb_strtolower($name)] ??= $name;
        }
        $names = array_values($unique);

        $problem = GameBoard::playersProblem(count($names), (int) Config::get('app.game.min_players'), (int) Config::get('app.game.max_players'), typed: true);
        if ($problem !== null) {
            $this->message = 'Wrong number of players';
            $this->validation_errors['players'] = ['errors' => [$problem]];
            return 422;
        }

        // A start that failed part of the way (one name was taken) has already made some of the players, use them
        // rather than failing again on the names that were made
        $existing = $this->existingPlayers($api, $resource_type_id);

        foreach ($names as $name) {
            if (array_key_exists(mb_strtolower($name), $existing)) {
                $this->addPlayer($existing[mb_strtolower($name)]);
                continue;
            }

            $result = $this->createPlayer($api, $resource_type_id, $name);
            if ($result === false) {
                $this->message = 'Failed to create player';
                $this->validation_errors['players'] = ['errors' => ['Failed to create the player named "' . $name . '", is the name taken by another player?']];
                return 422;
            }
        }

        $create_game_response = $api->createGame(
            $resource_type_id,
            $resource_id,
            Config::get('app.game.game_name'),
            Config::get('app.game.game_description')
        );

        if ($create_game_response['status'] === 201) {
            $this->game_id = $create_game_response['content']['id'];

            return $this->assignPlayers($api, $resource_type_id, $resource_id, $this->game_id, $this->players);
        }

        $this->message = $create_game_response['content'];

        if ($create_game_response['status'] === 422) {
            $this->validation_errors = $create_game_response['fields'];
        }

        return $create_game_response['status'];
    }

    /**
     * @return array<string, string> lower case name => player id
     */
    private function existingPlayers(Service $api, string $resource_type_id): array
    {
        $response = $api->getPlayers($resource_type_id, ['collection' => true]);
        if ($response['status'] !== 200) {
            return [];
        }

        $existing = [];
        foreach ($response['content'] as $player) {
            $existing[mb_strtolower((string) $player['name'])] = $player['id'];
        }

        return $existing;
    }

    private function addPlayer(string $player_id): void
    {
        if (in_array($player_id, $this->players, true) === false) {
            $this->players[] = $player_id;
        }
    }

    private function createPlayer(Service $api, string $resource_type_id, string $name): bool
    {
        $create_player_response = $api->createPlayer(
            $resource_type_id,
            $name,
            'New player - Added via the ' . Config::get('app.game.name') . ' App - Start'
        );

        if ($create_player_response['status'] === 201) {
            $this->addPlayer($create_player_response['content']['id']);

            return true;
        }

        return false;
    }
}
