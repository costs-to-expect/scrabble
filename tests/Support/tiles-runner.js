// Runs public/js/tiles.js for tests/Unit/TileScoringTest.php, the scoring lives in the browser script and PHPUnit cannot
// call it. Reads {values, bingo, maxWord, vectors, strings} as JSON on stdin and writes what the script made of them.
'use strict';

const Tiles = require('../../public/js/tiles.js');

let input = '';

process.stdin.setEncoding('utf8');
process.stdin.on('data', (chunk) => { input += chunk; });
process.stdin.on('end', () => {
    const { values, bingo, maxWord, vectors, strings } = JSON.parse(input);

    const scored = vectors.map((vector) => {
        const tiles = vector.tiles !== undefined ? Tiles.decode(vector.tiles, vector.word.length) : vector.settings;
        const result = Tiles.score(vector.word, tiles, vector.extra, values, bingo);

        return {
            points: result.tiles.map((tile) => tile.points),
            sum: result.sum,
            multiplier: result.multiplier,
            main: result.main,
            extra: result.extra,
            score: result.score,
            placed: result.placed,
            bingo: result.bingo,
            total: result.total,
            problem: Tiles.problem(vector.word, result, maxWord),
            encoded: vector.tiles !== undefined ? Tiles.encode(tiles, vector.word.length) : null
        };
    });

    const decoded = strings.map((entry) => Tiles.decode(entry.text, entry.length) !== null);

    process.stdout.write(JSON.stringify({ scored, decoded }));
});
