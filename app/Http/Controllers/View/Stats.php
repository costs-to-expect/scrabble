<?php
declare(strict_types=1);

namespace App\Http\Controllers\View;

use App\Http\Controllers\Controller;
use App\Support\GameBoard;
use App\Support\Stats as GameStats;
use Illuminate\Http\Request;

/**
 * The statistics across every game that has been finished. Each finished game keeps its players' numbers, so this only
 * has to read the list of games, a page of them at a time.
 */
class Stats extends Controller
{
    /** How many games the API is asked for at a time, it is also the most it will give */
    private const PAGE = 100;

    /** The most pages read, a thousand games is a lot of game nights */
    private const PAGES = 10;

    public function index(Request $request)
    {
        $this->bootstrap($request);

        $games = [];
        $capped = false;

        for ($page = 0; $page < self::PAGES; $page++) {
            $response = $this->api->getGames(
                $this->resource_type_id,
                $this->resource_id,
                ['complete' => 1, 'offset' => $page * self::PAGE, 'limit' => self::PAGE]
            );

            if ($response['status'] !== 200) {
                abort($response['status'], $response['content']);
            }

            $games = array_merge($games, $response['content']);

            if (count($response['content']) < self::PAGE) {
                break;
            }

            $capped = $page === self::PAGES - 1;
        }

        $players_response = $this->api->getPlayers($this->resource_type_id, ['collection' => true]);
        $players = [];
        if ($players_response['status'] === 200) {
            foreach ($players_response['content'] as $player) {
                $players[] = ['id' => $player['id'], 'name' => $player['name']];
            }
        }

        return view(
            'stats',
            [
                'stats' => GameStats::overall($games),
                'tones' => GameBoard::tones($players),
                'capped' => $capped,
            ]
        );
    }
}
