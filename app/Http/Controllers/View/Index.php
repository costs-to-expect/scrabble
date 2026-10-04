<?php
declare(strict_types=1);

namespace App\Http\Controllers\View;

use App\Http\Controllers\Controller;
use App\Models\ShareToken;
use App\Support\GameBoard;
use Illuminate\Http\Request;

/**
 * @author Dean Blackborough <dean@g3d-development.com>
 * @copyright Dean Blackborough (Costs to Expect) 2026
 * https://github.com/costs-to-expect/scrabble/blob/main/LICENSE
 */
class Index extends Controller
{
    public function home(Request $request)
    {
        $this->bootstrap($request);

        $user = $this->api->getAuthUser();

        if ($user['status'] !== 200) {
            abort(404, 'Unable to fetch your account information from the Costs to Expect API');
        }

        $open_games_response = $this->api->getGames(
            $this->resource_type_id,
            $this->resource_id,
            ['complete' => 0, 'include-players' => 1]
        );

        $open_games = [];
        if ($open_games_response['status'] === 200 && count($open_games_response['content']) > 0) {
            $open_games = $open_games_response['content'];
        }

        $closed_games_response = $this->api->getGames(
            $this->resource_type_id,
            $this->resource_id,
            ['complete' => 1, 'limit' => 5, 'include-players' => 1]
        );

        $closed_games = [];
        if ($closed_games_response['status'] === 200 && count($closed_games_response['content']) > 0) {
            $closed_games = $closed_games_response['content'];
        }

        $players_response = $this->api->getPlayers($this->resource_type_id, ['collection'=> true]);

        $players = [];
        if ($players_response['status'] === 200 && count($players_response['content']) > 0) {
            foreach ($players_response['content'] as $player) {
                $players[] = [
                    'id' => $player['id'],
                    'name' => $player['name']
                ];
            }
        }

        $tones = GameBoard::tones($players);

        // Every open game with its players in the order they play in. The score sheets are read once for the totals,
        // the home page needs no more than that to show who is ahead, who is next and what each of them played last.
        $boards = [];
        foreach ($open_games as $game) {
            $sheets = [];

            $score_sheets_response = $this->api->getGameScoreSheets(
                $this->resource_type_id,
                $this->resource_id,
                $game['id']
            );

            if ($score_sheets_response['status'] === 200) {
                foreach ($score_sheets_response['content'] as $score_sheet) {
                    $sheets[$score_sheet['key']] = $score_sheet['value'];
                }
            }

            $started = GameBoard::startedAt($game);

            $boards[] = [
                'id' => $game['id'],
                'started' => $started,
                'when' => GameBoard::when($started),
                'since' => GameBoard::since($started),
                'players' => GameBoard::standings($game['players']['collection'] ?? [], $sheets, $tones),
            ];
        }

        // What each open game is called when there is more than one: when it started, and the time of day when two
        // started on the same day, so they can be told apart. Without a start time they are numbered.
        $labels = array_count_values(array_map(static fn (array $board): string => (string) $board['when'], $boards));
        foreach ($boards as $position => $board) {
            $boards[$position]['label'] = match (true) {
                $board['when'] === null => 'Game ' . ($position + 1),
                $labels[$board['when']] > 1 => $board['when'] . ', ' . $board['started']->format('H:i'),
                default => $board['when'],
            };
        }

        $selected = 0;
        $requested = $request->query('game');
        foreach ($boards as $position => $board) {
            if ($board['id'] === $requested) {
                $selected = $position;
            }
        }

        $share_tokens = (new ShareToken())->getShareTokens(array_column($boards, 'id'));

        // The last game that was played, its players are the ones "Play again" and the next game start with
        $last_game = null;
        if (count($closed_games) > 0) {
            $scores = $closed_games[0]['game']['scores'] ?? [];
            if (count($scores) > 0) {
                $last_game = [
                    'id' => $closed_games[0]['id'],
                    'when' => GameBoard::when(GameBoard::startedAt($closed_games[0])),
                    'players' => array_map(
                        static fn (array $score): array => ['id' => $score['player_id'], 'name' => $score['player_name']],
                        $scores
                    ),
                ];
            }
        }

        $history = [];
        foreach ($closed_games as $game) {
            $result = GameBoard::result($game);
            if ($result === null) {
                continue;
            }

            $history[] = $result + [
                'id' => $game['id'],
                'when' => GameBoard::when(GameBoard::startedAt($game)),
            ];
        }

        return view(
            'home',
            [
                'user_id' => $user['content']['id'],

                'resource_type_id' => $this->resource_type_id,
                'resource_id' => $this->resource_id,

                'open_games' => $open_games,
                'closed_games' => $closed_games,
                'players' => $players,
                'tones' => $tones,

                'boards' => $boards,
                'selected' => $selected,
                'share_tokens' => $share_tokens,
                'last_game' => $last_game,
                'history' => $history,

                'errors' => session()->get('validation.errors')
            ]
        );
    }

    public function landing()
    {
        return view('landing');
    }
}
