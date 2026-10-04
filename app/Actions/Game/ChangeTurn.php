<?php
declare(strict_types=1);

namespace App\Actions\Game;

use App\Actions\Action;
use App\Api\Service;
use App\Notifications\ApiError;
use App\Support\ScoreRules;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Notification;

/**
 * Adds a turn to a player's score sheet, or changes, removes or puts back one that is there. The signed-in player and
 * the public share link both come through here, so a turn is checked in one place: it has to be a turn the rules
 * allow, and a turn is only ever added once, whatever the browser does about a save whose answer got lost.
 *
 * Nothing is taken out of the sheet. A removed turn stays on it, marked removed, which is why changing and removing
 * work whether the API replaces the sheet it is sent or merges it into the one it has stored.
 *
 * __invoke returns the HTTP status for the browser: 200 done, 403 turns cannot be changed, 409 the turn has already
 * been scored as something else, 422 the turn is not allowed, anything else is the API's status.
 *
 * @author Dean Blackborough <dean@g3d-development.com>
 * @copyright Dean Blackborough (Costs to Expect) 2026
 * https://github.com/costs-to-expect/scrabble/blob/main/LICENSE
 */
class ChangeTurn extends Action
{
    public const ADD = 'add';

    public const CHANGE = 'change';

    public const REMOVE = 'remove';

    public const RESTORE = 'restore';

    private array $sheet = [];

    private bool $failed_to_save = false;

    /**
     * @param array<string, mixed> $input what the browser sent: the turn's id, and for an add or a change the turn
     */
    public function __invoke(
        Service $api,
        string $resource_type_id,
        string $resource_id,
        string $game_id,
        string $player_id,
        string $operation,
        array $input
    ): int
    {
        if (in_array($operation, [self::ADD, self::CHANGE, self::REMOVE, self::RESTORE], true) === false) {
            throw new \InvalidArgumentException('Unknown operation ' . $operation);
        }

        $sheet_response = $api->getPlayerScoreSheet($resource_type_id, $resource_id, $game_id, $player_id);

        if ($sheet_response['status'] !== 200) {
            $this->message = 'Unable to fetch your score sheet';
            $this->failed_to_save = true;

            return $sheet_response['status'];
        }

        $stored = $sheet_response['content']['value'] ?? [];
        $this->sheet = is_array($stored) ? $stored : [];

        // The sheet is kept so the browser can catch up with it, even when the turn is not accepted
        if ($operation !== self::ADD && (bool) Config::get('app.config.score_corrections', true) === false) {
            $this->message = 'Turns cannot be changed';

            return 403;
        }

        $id = $input['id'] ?? null;
        $problem = in_array($operation, [self::REMOVE, self::RESTORE], true)
            ? (is_string($id) && preg_match(ScoreRules::ID_PATTERN, $id) === 1 ? null : 'The turn needs an id')
            : ScoreRules::problem($input);

        if ($problem !== null) {
            $this->message = $problem;

            return 422;
        }

        $id = (string) $id;
        $index = ScoreRules::indexOf($this->sheet, $id);
        $before = $index === null ? null : ScoreRules::all($this->sheet)[$index];
        $at = now()->utc()->format('Y-m-d\TH:i:s\Z');

        switch ($operation) {
            case self::ADD:
                $turn = ScoreRules::fromInput($input, $at);

                if ($before !== null) {
                    // The same turn again, a save that reached us but whose answer got lost
                    if (ScoreRules::same($before, $turn)) {
                        $this->message = 'Turn saved';

                        return 200;
                    }

                    $this->message = 'That turn has already been scored';

                    return 409;
                }

                if (count(ScoreRules::all($this->sheet)) >= ScoreRules::MAX_TURNS) {
                    $this->message = 'This score sheet is full';

                    return 422;
                }

                $updated = ScoreRules::with($this->sheet, $turn);
                $message = ScoreRules::describe($turn);
                $parameters = $this->parameters($player_id, self::ADD, $turn);
                break;

            case self::CHANGE:
                $turn = ScoreRules::fromInput($input, $at);

                if ($before === null || $before['removed'] === true) {
                    $this->message = $before === null ? 'That turn is not on the score sheet' : 'That turn has been removed';

                    return 422;
                }

                if (ScoreRules::same($before, $turn)) {
                    $this->message = 'Turn saved';

                    return 200;
                }

                $updated = ScoreRules::changed($this->sheet, $index, $turn);
                $message = 'Changed their turn from ' . ScoreRules::label($before) . ' to ' . ScoreRules::label($turn);
                $parameters = $this->parameters($player_id, self::CHANGE, $turn) + ['previous' => ScoreRules::points($before), 'previous_word' => $before['word']];
                break;

            case self::REMOVE:
            case self::RESTORE:
                $removing = $operation === self::REMOVE;

                if ($before === null) {
                    $this->message = 'That turn is not on the score sheet';

                    return 422;
                }

                // Taking off a turn that is already off, or putting back one that is there, is a retry of a save that
                // reached us
                if ($before['removed'] === $removing) {
                    $this->message = 'Turn saved';

                    return 200;
                }

                $updated = ScoreRules::removal($this->sheet, $index, $removing);
                $message = ($removing ? 'Removed' : 'Restored') . ' their turn, ' . ScoreRules::label($before);
                $parameters = $this->parameters($player_id, $removing ? self::REMOVE : self::RESTORE, $before);
                break;

            default:
                throw new \InvalidArgumentException('Unknown operation ' . $operation);
        }

        $result = (new Score())($api, $resource_type_id, $resource_id, $game_id, $player_id, $updated);

        if ($result !== 204) {
            $this->message = 'Failed to update your score sheet';
            $this->failed_to_save = true;

            return $result;
        }

        $this->sheet = $updated;
        $this->message = 'Turn saved';

        $log = new Log();
        if ($log($api, $resource_type_id, $resource_id, $game_id, $message, $parameters) !== 201) {
            Notification::route('mail', Config::get('app.config')['error_email'])
                ->notify(new ApiError(
                    'Unable to log the turn',
                    $log->getMessage()
                ));
        }

        return 200;
    }

    /**
     * The sheet as it is now, for a status the browser can use to catch up (a turn that was already there)
     */
    public function getSheet(): array
    {
        return $this->sheet;
    }

    /**
     * True when the failure was reading or saving the sheet, the browser is only told that, never the sheet
     */
    public function failedToSave(): bool
    {
        return $this->failed_to_save;
    }

    /**
     * What goes in the game log next to the message
     *
     * @param array{id: string, kind: string, word: string, score: int, bingo: bool, note: string} $turn
     * @return array<string, mixed>
     */
    private function parameters(string $player_id, string $action, array $turn): array
    {
        return [
            'player' => $player_id,
            'action' => $action,
            'turn' => $turn['id'],
            'kind' => $turn['kind'],
            'word' => $turn['word'],
            'score' => ScoreRules::points($turn),
            'bingo' => $turn['bingo'],
        ];
    }
}
