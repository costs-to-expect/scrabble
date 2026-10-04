<?php

use App\Http\Controllers\Action;
use App\Http\Controllers\View\Authentication;
use App\Http\Controllers\View\Game;
use App\Http\Controllers\View\Index;
use App\Http\Controllers\View\Player;
use App\Http\Controllers\View\Share;
use App\Http\Controllers\View\Stats;
use Illuminate\Support\Facades\Route;

Route::get(
    '/create-password',
    [Authentication::class, 'createPassword']
)->name('create-password.view');

Route::post(
    '/create-password',
    [Action\Authentication::class, 'createPassword']
)->name('create-password.process.action');

Route::get(
    '/',
    [Index::class, 'landing']
)->name('landing');

Route::get(
    '/sign-in',
    [Authentication::class, 'signIn']
)->name('sign-in.view');

Route::post(
    '/sign-in',
    [Action\Authentication::class, 'signIn']
)->name('sign-in.action');

Route::get(
    '/register',
    [Authentication::class, 'register']
)->name('register.view');

Route::post(
    '/register',
    [Action\Authentication::class, 'register']
)->name('register.action');

Route::get(
    '/forgot-password',
    [Authentication::class, 'forgotPassword']
)->name('forgot-password.view');

Route::post(
    '/forgot-password',
    [Action\Authentication::class, 'forgotPassword']
)->name('forgot-password.action');

Route::get(
    '/forgot-password-confirmation',
    [Authentication::class, 'forgotPasswordConfirmation']
)->name('forgot-password.confirmation');

Route::get(
    '/create-new-password',
    [Authentication::class, 'createNewPassword']
)->name('create-new-password.view');

Route::post(
    '/create-new-password',
    [Action\Authentication::class, 'createNewPassword']
)->name('create-new-password.action');

Route::get(
    '/create-new-password-confirmation',
    [Authentication::class, 'createNewPasswordConfirmation']
)->name('create-new-password.confirmation');

Route::get(
    '/registration-complete',
    [Authentication::class, 'registrationComplete']
)->name('registration-complete');

Route::get(
    '/sign-out',
    [Authentication::class, 'signOut']
)->name('sign-out');

// The public link of a player, it can only ever score for the player it was made for
Route::get(
    '/public/score-sheet/{token}',
    [Share::class, 'scoreSheet']
)->name('public.score-sheet');

Route::post(
    '/public/score-sheet/{token}/turn',
    [Action\Share::class, 'turn']
)->name('public.turn.action');

Route::post(
    '/public/score-sheet/{token}/turn/remove',
    [Action\Share::class, 'removeTurn']
)->name('public.turn.remove.action');

Route::post(
    '/public/score-sheet/{token}/turn/restore',
    [Action\Share::class, 'restoreTurn']
)->name('public.turn.restore.action');

Route::get(
    '/public/game/{token}/player-scores',
    [Share::class, 'playerScores']
)->name('public.player-scores');

Route::group(
    [
        'middleware' => [
            'auth'
        ]
    ],
    static function() {
        Route::get(
            '/home',
            [Index::class, 'home']
        )->name('home');

        Route::post(
            '/start',
            [Action\Game::class, 'start']
        )->name('start');

        Route::get(
            '/new-game',
            [Game::class, 'newGame']
        )->name('game.create.view');

        Route::post(
            '/new-game',
            [Action\Game::class, 'newGame']
        )->name('game.create.action');

        // The game screen: every player's turns and the score entry, the player in the address is the one it opens on
        Route::get(
            '/game/{game_id}/player/{player_id}/score-sheet',
            [Game::class, 'scoreSheet']
        )->name('game.score-sheet');

        Route::get(
            '/games',
            [Game::class, 'index']
        )->name('games');

        Route::get(
            '/games/{game_id}',
            [Game::class, 'show']
        )->name('game.show');

        Route::get(
            '/stats',
            [Stats::class, 'index']
        )->name('stats');

        Route::post(
            '/game/{game_id}/complete',
            [Action\Game::class, 'complete']
        )->name('game.complete.action');

        Route::post(
            '/game/{game_id}/complete-and-play-again',
            [Action\Game::class, 'completeAndPlayAgain']
        )->name('game.complete.play-again.action');

        Route::post(
            '/game/{game_id}/delete',
            [Action\Game::class, 'deleteGame']
        )->name('game.delete.action');

        // The turns of a player: add or change one, take one off the sheet and put one back
        Route::post(
            '/game/turn',
            [Action\Game::class, 'turn']
        )->name('game.turn.action');

        Route::post(
            '/game/turn/remove',
            [Action\Game::class, 'removeTurn']
        )->name('game.turn.remove.action');

        Route::post(
            '/game/turn/restore',
            [Action\Game::class, 'restoreTurn']
        )->name('game.turn.restore.action');

        // A POST, removing a player deletes their score sheet, a link or a prefetch must never do that
        Route::post(
            '/game/{game_id}/player/{player_id}/delete',
            [Game::class, 'deleteGamePlayer']
        )->name('game.player.delete');

        Route::get(
            '/game/{game_id}/player-scores',
            [Game::class, 'playerScores']
        )->name('game.player-scores');

        Route::get(
            '/add-players-to-game/{game_id}',
            [Game::class, 'addPlayersToGame']
        )->name('game.add-players.view');

        Route::post(
            '/add-players-to-game/{game_id}',
            [Action\Game::class, 'addPlayersToGame']
        )->name('game.add-players.action');

        Route::get(
            '/players',
            [Player::class, 'index']
        )->name('players');

        Route::get(
            '/new-player',
            [Player::class, 'newPlayer']
        )->name('player.create.view');

        Route::post(
            '/new-player',
            [Action\Player::class, 'newPlayer']
        )->name('player.create.action');

        Route::get(
            '/account',
            [Authentication::class, 'account']
        )->name('account');

        Route::get(
            '/account/confirm-delete-scrabble-account',
            [Authentication::class, 'confirmDeleteScrabbleAccount']
        )->name('account.confirm-delete-scrabble-account');

        Route::post(
            '/account/delete-scrabble-account',
            [Action\Authentication::class, 'deleteScrabbleAccount']
        )->name('account.delete-scrabble-account.action');

        Route::get(
            '/account/confirm-delete-account',
            [Authentication::class, 'confirmDeleteAccount']
        )->name('account.confirm-delete-account');

        Route::post(
            '/account/delete-account',
            [Action\Authentication::class, 'deleteAccount']
        )->name('account.delete-account.action');
    }
);
