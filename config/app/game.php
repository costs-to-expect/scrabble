<?php
declare(strict_types=1);

/*
 * What the pages say about the game. Everything that is the game's, rather than the app's, is here so a sibling scorer
 * (Yahtzee, Carcassonne) changes this file, the mark in App\View\Icons and its own score rules, and the rest is shared.
 */
return [
    'key' => 'scrabble',
    'name' => 'Scrabble',
    'tagline' => 'Game Scorer',
    // The mark in the logo tile, a game is told apart by its mark and name, never its colour
    'mark' => 'scrabble',
    // What the tile of a player says, they add a turn
    'action' => 'Add a turn',
    // A game needs at least two players and the board only has room for four racks
    'min_players' => 2,
    'max_players' => 4,
    // Scrabble has no fixed number of turns, so there is no ring and no "8 of 13 turns", a tile shows the last play
    // and the owner finishes the game when the bag is empty and someone has played out
    'game_name' => 'Scrabble game',
    'game_description' => 'Scrabble game created via the Scrabble app',
];
