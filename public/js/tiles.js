/*
 * Scoring a word tile by tile: every letter is a tile worth its usual points, it sits on a square that can double or triple
 * the letter or the whole word, it can be a blank (worth nothing) and it can be a tile that was already on the board (it
 * counts for what it is worth, whatever square it sits on, and does not count towards the seven tiles a turn plays).
 *
 *   letters = what each tile counts for, with its letter bonus
 *   main    = the letters added up and multiplied by every word square a new tile sits on
 *   score   = main and the points of any other words the tiles made, it is what is stored with the turn
 *   bingo   = seven new tiles, the 50 points are on top of the score, as they are for a turn that was typed in
 *
 * No page is needed to run it, tests/Unit/TileScoringTest.php runs it under Node. What a letter is worth (and the bingo)
 * come from the server, App\Support\ScoreRules, they are passed in. The tiles are stored as text, two characters for each
 * letter, App\Support\ScoreRules::TILES_PATTERN says what a valid one looks like and this file reads and writes the same.
 */
/* global module */
(function (root, factory) {
    'use strict';

    var api = factory();

    // A page gets window.Tiles, Node (tests/Unit/TileScoringTest.php) gets it from require
    if (typeof module === 'object' && module.exports) { module.exports = api; } else { root.Tiles = api; }
})(typeof window !== 'undefined' ? window : globalThis, function () {
    'use strict';

    var LONGEST = 15;
    var RACK = 7;

    // What is under a tile. The code is what is stored.
    var SQUARES = [
        {code: '-', short: '', name: 'Nothing', detail: 'Plain square', letter: 1, word: 1},
        {code: 'd', short: 'DL', name: 'Double letter', detail: 'Double letter', letter: 2, word: 1},
        {code: 't', short: 'TL', name: 'Triple letter', detail: 'Triple letter', letter: 3, word: 1},
        {code: 'D', short: 'DW', name: 'Double word', detail: 'Double word', letter: 1, word: 2},
        {code: 'T', short: 'TW', name: 'Triple word', detail: 'Triple word', letter: 1, word: 3}
    ];

    function square(code) {
        for (var i = 0; i < SQUARES.length; i++) {
            if (SQUARES[i].code === code) { return SQUARES[i]; }
        }

        return SQUARES[0];
    }

    // A tile nobody has said anything about: new, from the rack, on a plain square
    function plain() { return {square: '-', blank: false, board: false}; }

    // ---- Scoring ------------------------------------------------------------------------------------------------------

    function score(word, tiles, extra, values, bingo) {
        var counted = [];
        var sum = 0;
        var multiplier = 1;
        var placed = 0;

        for (var i = 0; i < word.length; i++) {
            var tile = tiles[i] || plain();
            var letter = word.charAt(i);
            var known = Object.prototype.hasOwnProperty.call(values, letter);
            var worth = tile.blank || !known ? 0 : values[letter];
            // A tile that was already there has no square that counts
            var under = tile.board ? SQUARES[0] : square(tile.square);
            var points = worth * under.letter;

            if (!tile.board) {
                placed++;
                multiplier *= under.word;
            }

            sum += points;
            counted.push({letter: letter, value: worth, points: points, square: under.code, blank: !!tile.blank, board: !!tile.board, known: known});
        }

        var other = Math.max(0, Math.floor(extra || 0));
        var main = sum * multiplier;
        var isBingo = placed === RACK;

        return {
            tiles: counted,
            sum: sum,
            multiplier: multiplier,
            main: main,
            extra: other,
            score: main + other,
            placed: placed,
            bingo: isBingo,
            bonus: isBingo ? bingo : 0,
            total: main + other + (isBingo ? bingo : 0)
        };
    }

    // Why this cannot be a turn, null when it can. The server's rules for tiles, so the button only says yes to a turn
    // that will be accepted. maxWord is the most a word can score.
    function problem(word, result, maxWord) {
        if (word.length === 0) { return 'Type the word to see its tiles'; }
        if (!/^[A-Z]+$/.test(word) || word.length > LONGEST) { return 'Tile by tile is for a word of the letters A to Z'; }
        if (result.placed === 0) { return 'At least one tile has to be new'; }
        if (result.placed > RACK) { return 'Seven new tiles at most, mark the others as on the board'; }
        if (result.score < 1) { return 'A word scores at least 1'; }
        if (result.score > maxWord) { return 'A word scores ' + maxWord + ' at most'; }

        return null;
    }

    // What the word multiplier is called
    function multiplierName(multiplier) {
        if (multiplier === 2) { return 'Double word'; }
        if (multiplier === 3) { return 'Triple word'; }

        return multiplier + ' times the word';
    }

    // ---- Storing ------------------------------------------------------------------------------------------------------

    // Two characters for each tile: what is under it (- d t D T) and what the tile is (n new, b new blank, o already on
    // the board, x a blank that was already on the board). A tile that was already there has nothing under it.
    function encode(tiles, length) {
        var text = '';

        for (var i = 0; i < length; i++) {
            var tile = tiles[i] || plain();

            text += tile.board ? '-' + (tile.blank ? 'x' : 'o') : square(tile.square).code + (tile.blank ? 'b' : 'n');
        }

        return text;
    }

    // The tiles of a word from the text stored with a turn, null when the text is not valid for a word of this length
    function decode(text, length) {
        if (typeof text !== 'string' || text.length !== length * 2 || !/^(?:[-dtDT][nb]|-[ox]){1,15}$/.test(text)) { return null; }

        var tiles = [];

        for (var i = 0; i < text.length; i += 2) {
            var state = text.charAt(i + 1);

            tiles.push({square: text.charAt(i), blank: state === 'b' || state === 'x', board: state === 'o' || state === 'x'});
        }

        return tiles;
    }

    return {
        SQUARES: SQUARES, LONGEST: LONGEST, RACK: RACK,
        square: square, plain: plain, score: score, problem: problem, multiplierName: multiplierName, encode: encode, decode: decode
    };
});
