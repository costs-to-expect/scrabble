<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\ScoreRules;
use App\Support\Stats;

/**
 * Builders for the data shapes the Costs to Expect API returns for Scrabble, shared by the tests that fake the API.
 * A score sheet is built with the same rules as the app: a bingo is fifty on top of the word, the total is everything
 * that counts.
 */
trait BuildsApiFixtures
{
    private int $turnCount = 0;

    protected function nextTurnId(): string
    {
        return sprintf('turn-%04d', ++$this->turnCount);
    }

    /**
     * A word, as it is stored on a score sheet, with its tiles when it was scored tile by tile
     */
    protected function word(string $word, int $score, bool $bingo = false, ?string $id = null, bool $removed = false, string $tiles = ''): array
    {
        return [
            'id' => $id ?? $this->nextTurnId(),
            'kind' => 'word',
            'word' => $word,
            'score' => $score,
            'bingo' => $bingo,
            'note' => '',
            'tiles' => $tiles,
            'at' => '2026-10-04T19:30:00Z',
            'removed' => $removed,
        ];
    }

    protected function pass(?string $id = null): array
    {
        return ['id' => $id ?? $this->nextTurnId(), 'kind' => 'pass', 'word' => '', 'score' => 0, 'bingo' => false, 'note' => '', 'tiles' => '', 'at' => '2026-10-04T19:30:00Z', 'removed' => false];
    }

    protected function adjustment(int $score, string $note = '', ?string $id = null): array
    {
        return ['id' => $id ?? $this->nextTurnId(), 'kind' => 'adjust', 'word' => '', 'score' => $score, 'bingo' => false, 'note' => $note, 'tiles' => '', 'at' => '2026-10-04T19:30:00Z', 'removed' => false];
    }

    /**
     * A score sheet with these turns, the totals worked out as the app works them out
     *
     * @param list<array<string, mixed>> $turns
     */
    protected function scoreSheet(array $turns = []): array
    {
        $sheet = ['turns' => $turns];
        $sheet['score'] = ScoreRules::totals($sheet);

        return $sheet;
    }

    /**
     * A game as returned when requesting items with include-players.
     *
     * @param array<string, string> $players player id => name
     * @param list<array<string, mixed>> $scores only for a complete game, what Complete stores for each player
     */
    protected function game(string $id, array $players, bool $complete = false, array $scores = []): array
    {
        $collection = [];
        foreach ($players as $player_id => $name) {
            $collection[] = ['id' => $player_id, 'name' => $name];
        }

        $game = [
            'id' => $id,
            'name' => 'Scrabble game',
            'description' => 'Scrabble game created via the Scrabble app',
            'complete' => $complete ? 1 : 0,
            'players' => ['collection' => $collection],
        ];

        if ($complete) {
            $game['game'] = ['scores' => $scores, 'winner' => $scores[0] ?? null];
        }

        return $game;
    }

    /**
     * What Complete stores for a player of a finished game: who they are and the numbers from their sheet
     *
     * @param list<array<string, mixed>> $turns
     */
    protected function finishedScore(string $player_id, string $player_name, array $turns): array
    {
        return Stats::entry($player_id, $player_name, $this->scoreSheet($turns));
    }

    /**
     * The players assigned to a game, as returned by the game's categories collection. Each
     * assignment has its own id (ga-p-1 for player p-1), it is the assignment that is deleted
     * to remove a player from a game.
     *
     * @param array<string, string> $players player id => name
     */
    protected function assignedPlayers(array $players): array
    {
        $assigned = [];
        foreach ($players as $player_id => $name) {
            $assigned[] = ['id' => 'ga-'.$player_id, 'category' => ['id' => $player_id, 'name' => $name]];
        }

        return $assigned;
    }

    /**
     * The players collection, as returned by the resource type's categories.
     *
     * @param array<string, string> $players player id => name
     */
    protected function playerCollection(array $players): array
    {
        $collection = [];
        foreach ($players as $player_id => $name) {
            $collection[] = ['id' => $player_id, 'name' => $name];
        }

        return $collection;
    }

    /**
     * A game's score sheets, one per player.
     *
     * @param array<string, array> $sheets player id => score sheet
     */
    protected function scoreSheets(array $sheets): array
    {
        $collection = [];
        foreach ($sheets as $player_id => $sheet) {
            $collection[] = ['key' => $player_id, 'value' => $sheet];
        }

        return $collection;
    }
}
