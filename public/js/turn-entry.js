/*
 * The turn entry: the sheet the score sheet and the landing page open to add or change a turn. A word and what it scored,
 * a pass (which is also swapping tiles) or an adjustment for the tiles left at the end of the game. It only gathers the
 * turn, the page decides what to do with it.
 *
 * A word is scored one of two ways, the switch beside the close button chooses (it is remembered on the device):
 *   Quick         type the score on a number pad, the 50 point bingo bonus is a tap away
 *   Tile by tile  type the word and each letter becomes a tile, say what each one sits on (a double or triple letter or
 *                 word), which are blanks and which were already on the board, and the score is added up, see tiles.js
 *
 *   TurnEntry.open({
 *       title, subtitle,
 *       limits,                      // the server's own limits, written into the page
 *       players, player,             // who it could be for (chips), and who it is for, left out when it is for one player
 *       describe(playerId),          // the subtitle for a player, when the player can be chosen
 *       turn,                        // the turn being changed, left out for a new one
 *       onSubmit(turn, playerId),    // turn is {kind, word, score, bingo, note, tiles}, score is signed for an adjustment
 *       onRemove()                   // given when the turn can be taken off the sheet
 *   });
 */
(function () {
    'use strict';

    var UI = window.UI;
    var Tiles = window.Tiles || null;
    var session = null;

    // How a word is scored is remembered on the device, it is a preference and nothing depends on it
    var PREFERENCE = 'scrabble.entry';

    function preferred() {
        try { return window.localStorage.getItem(PREFERENCE) === 'tiles' ? 'tiles' : 'quick'; } catch (error) { return 'quick'; }
    }

    function remember(mode) {
        try { window.localStorage.setItem(PREFERENCE, mode); } catch (error) { /* a private window keeps nothing, that is fine */ }
    }

    // ---- What has been entered --------------------------------------------------------------------------------------

    function limits() { return session.options.limits; }
    function maximum() { return session.kind === 'adjust' ? limits().maxAdjustment : limits().maxWord; }
    function typed() { return session.digits === '' ? null : Number(session.digits); }

    // A word that is being scored tile by tile
    function tiling() { return session.kind === 'word' && session.mode === 'tiles'; }

    function extraPoints() { return session.extra === '' ? 0 : Number(session.extra); }

    function tally() { return Tiles.score(session.word, session.tiles, extraPoints(), limits().tileValues, limits().bingo); }

    // Why the tiles cannot be a turn yet, null when they can
    function trouble() { return Tiles.problem(session.word, tally(), limits().maxWord); }

    function valid() {
        if (session.kind === 'pass') { return true; }
        if (tiling()) { return trouble() === null; }

        var value = typed();
        return value !== null && value >= 1 && value <= maximum();
    }

    // What the turn is worth, the bingo is on top of the word and an adjustment can take points off
    function worth() {
        if (tiling()) { return tally().total; }

        var value = typed() || 0;

        if (session.kind === 'adjust') { return session.negative ? -value : value; }
        return value + (session.kind === 'word' && session.bingo ? limits().bingo : 0);
    }

    function build() {
        if (session.kind === 'pass') { return {kind: 'pass', word: '', score: 0, bingo: false, note: '', tiles: ''}; }
        if (session.kind === 'adjust') { return {kind: 'adjust', word: '', score: session.negative ? -typed() : typed(), bingo: false, note: session.note.trim(), tiles: ''}; }

        if (tiling()) {
            // The score is what the tiles came to and the other words, the bingo is the seven new tiles, and the tiles go with it
            var done = tally();

            return {kind: 'word', word: session.word, score: done.score, bingo: done.bingo, note: '', tiles: Tiles.encode(session.tiles, session.word.length)};
        }

        return {kind: 'word', word: session.word.trim().toUpperCase(), score: typed(), bingo: session.bingo, note: '', tiles: ''};
    }

    // ---- Drawing ------------------------------------------------------------------------------------------------------

    function body() { return UI.Sheet.body(); }
    function find(selector) { return body().querySelector(selector); }

    function padKey(content, attributes) {
        return '<button type="button" ' + attributes + ' class="h-12 rounded-2xl bg-stone-100 text-xl font-bold transition hover:bg-stone-200 active:bg-stone-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 motion-reduce:transition-none">' + content + '</button>';
    }

    function pad() {
        return '<div class="mt-3 grid grid-cols-3 gap-2" role="group" aria-label="Number pad">' +
            [1, 2, 3, 4, 5, 6, 7, 8, 9].map(function (digit) { return padKey(String(digit), 'data-key="' + digit + '"'); }).join('') +
            '<span aria-hidden="true"></span>' + padKey('0', 'data-key="0"') +
            padKey(UI.icon('backspace', 'mx-auto h-6 w-6'), 'data-key="back" aria-label="Delete the last digit"') + '</div>';
    }

    function players() {
        var options = session.options;

        if (!options.players || options.players.length < 2 || options.turn) { return ''; }

        return '<fieldset class="mb-3"><legend class="sr-only">Who played</legend><div class="flex flex-wrap gap-2">' + options.players.map(function (player) {
            return '<label class="chip has-checked:bg-brand-700 has-checked:text-white has-checked:ring-brand-700 has-checked:hover:bg-brand-800 has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-brand-600">' +
                '<input type="radio" name="entry-player" value="' + UI.escape(player.id) + '" class="peer sr-only"' + (player.id === session.player ? ' checked' : '') + '>' +
                '<span class="hidden h-7 w-7 shrink-0 items-center justify-center rounded-full bg-white text-brand-700 peer-checked:inline-flex">' + UI.icon('check', 'h-4 w-4') + '</span>' +
                UI.avatar(player.name, player.tone, 'h-7 w-7 text-xs peer-checked:hidden') + UI.escape(player.name) + '</label>';
        }).join('') + '</div></fieldset>';
    }

    function kinds() {
        var labels = [['word', 'Word'], ['pass', 'Pass'], ['adjust', 'Adjust']];

        return '<div class="mb-3 flex gap-1 rounded-full bg-stone-100 p-1 text-sm font-bold" role="radiogroup" aria-label="What happened">' + labels.map(function (kind) {
            var on = session.kind === kind[0];

            return '<button type="button" role="radio" aria-checked="' + on + '" data-kind="' + kind[0] + '" class="min-h-10 flex-1 rounded-full px-3 transition focus-visible:outline-2 focus-visible:outline-brand-600 motion-reduce:transition-none ' +
                (on ? 'bg-white text-stone-900 shadow-sm' : 'text-stone-600 hover:text-stone-900') + '">' + kind[1] + '</button>';
        }).join('') + '</div>';
    }

    function display(label) {
        return '<div id="entry-display" data-autofocus tabindex="-1" class="min-w-0 flex-1 rounded-2xl bg-stone-50 px-4 py-2.5 text-center ring-1 ring-stone-200 outline-none">' +
            '<p class="text-xs font-bold uppercase tracking-wider text-stone-600">' + label + '</p>' +
            '<p id="entry-value" class="mt-1 text-4xl font-extrabold leading-none tabular-nums text-stone-300" aria-live="polite">&ndash;</p>' +
            '<p id="entry-note" class="mt-1 h-5 text-sm text-stone-600"></p></div>';
    }

    function bingo() {
        return '<button type="button" id="entry-bingo" data-bingo aria-pressed="false" class="flex w-24 shrink-0 flex-col items-center justify-center gap-0.5 rounded-2xl text-sm font-extrabold ring-1 ring-inset transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 motion-reduce:transition-none">' +
            UI.icon('star', 'h-5 w-5') + '<span>Bingo</span><span class="text-xs font-bold">+' + limits().bingo + '</span></button>';
    }

    // The button stays at the bottom of the sheet, however tall the sheet is, so it is always in reach
    function submitButton() {
        return '<div class="sticky bottom-0 -mx-5 mt-2 bg-white/95 px-5 pb-1 pt-2 backdrop-blur sm:-mx-6 sm:px-6">' +
            '<button type="button" id="entry-submit" data-submit class="btn btn-primary btn-block h-12 text-base font-extrabold" disabled></button></div>';
    }

    function quickPanel() {
        return '<div><label for="entry-word" class="form-label">Word <span class="font-semibold text-stone-600">(optional)</span></label>' +
            '<input id="entry-word" type="text" inputmode="text" enterkeyhint="next" autocomplete="off" autocapitalize="characters" spellcheck="false" maxlength="' + limits().maxLetters + '" value="' + UI.escape(session.word) + '" ' +
            'class="form-control text-xl font-extrabold uppercase tracking-widest placeholder:text-base placeholder:font-semibold placeholder:normal-case placeholder:tracking-normal" placeholder="The main word you played"></div>' +
            '<div class="mt-3 flex items-stretch gap-2.5">' + display('Score for the word') + bingo() + '</div>' + pad() + submitButton();
    }

    // ---- Tile by tile --------------------------------------------------------------------------------------------------

    // What is under a tile, in the colours of the board: the badge on the tile and the button that chooses it. Written out in
    // full so the stylesheet finds the classes. Never colour alone, the badge and the button say what it is in words.
    var SQUARE_STYLES = {
        '-': {off: 'bg-white text-stone-800 ring-stone-300 hover:bg-stone-50', on: 'bg-stone-800 text-white ring-stone-800', badge: ''},
        d: {off: 'bg-sky-50 text-sky-900 ring-sky-300 hover:bg-sky-100', on: 'bg-sky-700 text-white ring-sky-700', badge: 'bg-sky-700 text-white'},
        t: {off: 'bg-blue-50 text-blue-900 ring-blue-300 hover:bg-blue-100', on: 'bg-blue-800 text-white ring-blue-800', badge: 'bg-blue-800 text-white'},
        D: {off: 'bg-rose-50 text-rose-900 ring-rose-300 hover:bg-rose-100', on: 'bg-rose-700 text-white ring-rose-700', badge: 'bg-rose-700 text-white'},
        T: {off: 'bg-red-50 text-red-900 ring-red-300 hover:bg-red-100', on: 'bg-red-800 text-white ring-red-800', badge: 'bg-red-800 text-white'}
    };

    // The two lines on a button that chooses what is under a tile
    var SQUARE_WORDS = {'-': ['Plain', 'square'], d: ['Double', 'letter'], t: ['Triple', 'letter'], D: ['Double', 'word'], T: ['Triple', 'word']};

    function tileClass(info, chosen) {
        var look = info.board ? 'bg-stone-100 text-stone-500 ring-stone-200 border-2 border-dotted border-stone-300'
            : (info.blank ? 'bg-white text-stone-400 ring-stone-200 border-2 border-dashed border-stone-300' : 'bg-white text-stone-900 ring-stone-300');

        return 'relative flex h-14 w-full items-center justify-center rounded-xl text-2xl font-extrabold shadow-sm ring-1 transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 motion-reduce:transition-none ' +
            (chosen ? 'ring-2 ring-brand-600 shadow-md ' : 'hover:shadow-md ') + look;
    }

    // What a screen reader says for a tile, the colours and the small numbers are no use to it
    function tileLabel(info, number, count) {
        var parts = [info.letter + (info.blank ? ', a blank, worth nothing' : ', worth ' + info.value)];

        if (info.board) {
            parts.push('already on the board');
        } else if (info.square !== '-') {
            parts.push('on a ' + Tiles.square(info.square).name.toLowerCase());
        }

        parts.push('counts for ' + info.points);

        return 'Tile ' + number + ' of ' + count + ': ' + parts.join(', ');
    }

    function toggleButton(flag, on, title, detail) {
        return '<button type="button" data-flag="' + flag + '" aria-pressed="' + on + '" class="flex min-h-12 items-center gap-2 rounded-xl px-2.5 text-left ring-1 ring-inset transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 motion-reduce:transition-none ' +
            (on ? 'bg-brand-700 text-white ring-brand-700 hover:bg-brand-800' : 'bg-white text-stone-800 ring-stone-300 hover:bg-stone-50') + '">' +
            '<span class="flex h-4.5 w-4.5 shrink-0 items-center justify-center rounded-md ring-1 ring-inset ' + (on ? 'bg-white text-brand-700 ring-white' : 'bg-white ring-stone-300') + '">' + (on ? UI.icon('check', 'h-3 w-3') : '') + '</span>' +
            '<span class="min-w-0"><span class="block text-[13px] font-bold leading-4">' + title + '</span><span class="block text-[11px] leading-4 ' + (on ? 'text-white/90' : 'text-stone-600') + '">' + detail + '</span></span></button>';
    }

    // The tile that is open: what is under it, and whether it is a blank or was already on the board
    function options(done) {
        var info = done.tiles[session.tile];
        var setting = session.tiles[session.tile];
        var count = done.tiles.length;

        var squares = Tiles.SQUARES.map(function (square) {
            var on = !info.board && setting.square === square.code;
            var words = SQUARE_WORDS[square.code];

            return '<button type="button" role="radio" aria-checked="' + on + '" data-square="' + square.code + '"' + (info.board ? ' disabled' : '') + ' class="flex min-h-14 flex-col items-center justify-center rounded-xl px-0.5 py-1.5 text-center ring-1 ring-inset transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 disabled:opacity-40 motion-reduce:transition-none ' +
                SQUARE_STYLES[square.code][on ? 'on' : 'off'] + '"><span class="text-xs font-extrabold leading-4">' + words[0] + '</span><span class="text-[11px] font-bold leading-4">' + words[1] + '</span></button>';
        }).join('');

        return '<div class="mt-3 rounded-2xl bg-stone-50 p-3 ring-1 ring-stone-200" role="group" aria-label="Tile ' + (session.tile + 1) + ' of ' + count + ', ' + UI.escape(info.letter) + '">' +
            '<div class="flex items-center justify-between gap-2"><p id="entry-tile-title" class="min-w-0 truncate text-sm font-extrabold">What is under the <span class="inline-block min-w-6 rounded-md bg-white px-1 text-center tracking-wide ring-1 ring-stone-300">' + UI.escape(info.letter) + '</span>?</p>' +
            '<div class="flex shrink-0 gap-1">' +
            ['-1', '1'].map(function (step) {
                var disabled = (step === '-1' && session.tile === 0) || (step === '1' && session.tile === count - 1);

                return '<button type="button" data-tile-step="' + step + '"' + (disabled ? ' disabled' : '') + ' aria-label="' + (step === '-1' ? 'Previous tile' : 'Next tile') + '" class="flex h-9 w-9 items-center justify-center rounded-full bg-white text-stone-700 ring-1 ring-inset ring-stone-300 hover:bg-stone-100 focus-visible:outline-2 focus-visible:outline-brand-600 disabled:opacity-40">' + UI.icon(step === '-1' ? 'chevron-left' : 'chevron-right', 'h-4 w-4') + '</button>';
            }).join('') + '</div></div>' +
            '<div role="radiogroup" aria-label="What is under the tile" class="mt-2 grid grid-cols-5 gap-1.5">' + squares + '</div>' +
            (info.board ? '<p class="mt-1.5 text-xs text-stone-600">It was already there, so what is under it does not count.</p>' : '') +
            '<div class="mt-2.5 grid grid-cols-2 gap-1.5">' +
            toggleButton('blank', info.blank, 'Blank tile', 'Worth nothing') +
            toggleButton('board', info.board, 'On the board', 'Played earlier') + '</div></div>';
    }

    // The tiles of the word, what each counts for, and the tile that is open
    function tilesArea() {
        if (session.word === '') {
            return '<p class="rounded-2xl border border-dashed border-stone-300 px-4 py-5 text-center text-sm text-stone-600">Type your word and its tiles appear here. Tap a tile to say what it sits on.</p>';
        }

        var done = tally();

        // Seven to a row, like a rack, so a word that is a bingo is one row of tiles
        return '<div role="radiogroup" aria-label="The tiles of the word" class="mt-4 grid grid-cols-7 gap-x-1.5 gap-y-2">' + done.tiles.map(function (info, index) {
            var chosen = index === session.tile;
            var badge = !info.board && info.square !== '-' ? '<span class="absolute -left-1 -top-1.5 rounded px-1 text-[9px] font-extrabold leading-4 ' + SQUARE_STYLES[info.square].badge + '" aria-hidden="true">' + Tiles.square(info.square).short + '</span>' : '';

            return '<div class="flex min-w-0 flex-col items-center"><button type="button" role="radio" aria-checked="' + chosen + '" tabindex="' + (chosen ? 0 : -1) + '" data-tile="' + index + '" aria-label="' + UI.escape(tileLabel(info, index + 1, done.tiles.length)) + '" class="' + tileClass(info, chosen) + '">' +
                badge + '<span aria-hidden="true">' + UI.escape(info.letter) + '</span><span class="absolute bottom-0.5 right-1 text-[10px] font-bold text-stone-500" aria-hidden="true">' + info.value + '</span></button>' +
                '<span class="mt-1 text-xs font-extrabold tabular-nums ' + (info.points !== info.value ? 'text-brand-800' : 'text-stone-600') + '" aria-hidden="true">' + info.points + '</span></div>';
        }).join('') + '</div>' + totalCard() + options(done);
    }

    function extraRow() {
        return '<div id="entry-extra-row" class="mt-3 flex items-center justify-between gap-3"' + (session.word === '' ? ' hidden' : '') + '><label for="entry-extra" class="min-w-0 text-sm font-bold">Other words made' +
            '<span class="block text-xs font-normal text-stone-600">Optional, the points of words made across yours</span></label>' +
            '<input id="entry-extra" type="text" inputmode="numeric" pattern="[0-9]*" enterkeyhint="done" autocomplete="off" maxlength="3" value="' + UI.escape(session.extra) + '" placeholder="0" class="form-control w-20 shrink-0 text-center text-lg font-extrabold" aria-describedby="entry-lines"></div>';
    }

    function totalCard() {
        return '<div class="mt-3 flex items-center justify-between gap-3 rounded-2xl bg-stone-50 px-4 py-2.5 ring-1 ring-stone-200">' +
            '<div><p class="sr-only">Total for the turn</p>' +
            '<p id="entry-value" class="text-5xl font-extrabold leading-none tabular-nums text-stone-300" aria-live="polite">&ndash;</p></div>' +
            '<div id="entry-lines" class="min-w-0 flex-1 space-y-0.5 text-right text-sm text-stone-700"></div></div>';
    }

    function tilesPanel() {
        return '<div><label for="entry-word" class="form-label">Word <span class="font-semibold text-stone-600">(the letters A to Z)</span></label>' +
            '<input id="entry-word" type="text" inputmode="text" enterkeyhint="next" autocomplete="off" autocapitalize="characters" spellcheck="false" maxlength="' + limits().maxLetters + '" value="' + UI.escape(session.word) + '" ' +
            'class="form-control text-xl font-extrabold uppercase tracking-widest placeholder:text-base placeholder:font-semibold placeholder:normal-case placeholder:tracking-normal" placeholder="Type the word you played"></div>' +
            '<div id="entry-tiles">' + tilesArea() + '</div>' + extraRow() + submitButton();
    }

    function wordPanel() { return session.mode === 'tiles' ? tilesPanel() : quickPanel(); }

    // The switch beside the close button: score the word tile by tile. It is for words, so it waits while a pass or an
    // adjustment is chosen, in the same place so nothing on the sheet moves.
    function modeButton() {
        return '<button type="button" id="entry-mode" data-mode aria-pressed="false" class="inline-flex min-h-10 items-center gap-1.5 rounded-full px-3 text-sm font-bold ring-1 ring-inset transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 disabled:opacity-40 motion-reduce:transition-none">' +
            UI.icon('tile', 'h-4 w-4') + '<span class="hidden min-[360px]:inline">Tile by tile</span><span class="min-[360px]:hidden">Tiles</span></button>';
    }

    function syncMode() {
        var button = document.getElementById('entry-mode');
        if (!button) { return; }

        var on = session.mode === 'tiles';

        button.setAttribute('aria-pressed', on);
        button.disabled = session.kind !== 'word';
        button.className = 'inline-flex min-h-10 items-center gap-1.5 rounded-full px-3 text-sm font-bold ring-1 ring-inset transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 disabled:opacity-40 motion-reduce:transition-none ' +
            (on ? 'bg-brand-700 text-white ring-brand-700 hover:bg-brand-800' : 'bg-white text-stone-800 ring-stone-300 hover:bg-stone-50');
    }

    function passPanel() {
        return '<p class="rounded-2xl bg-stone-50 px-4 py-3.5 text-stone-700 ring-1 ring-stone-200">Passing, or swapping tiles, scores nothing but it is still a turn, so it is the next player&rsquo;s go.</p>' + submitButton();
    }

    function adjustPanel() {
        var presets = [['Tiles left', true], ['Went out', false], ['Penalty', true]];

        return '<p class="text-sm text-stone-600">For the end of the game: the tiles left on a rack, going out, or a penalty.</p>' +
            '<div class="mt-3 flex flex-wrap gap-2">' + presets.map(function (preset) {
                return '<button type="button" data-preset="' + preset[0] + '" data-negative="' + preset[1] + '" class="inline-flex min-h-10 items-center rounded-full bg-white px-4 text-sm font-bold text-stone-800 ring-1 ring-inset ring-stone-300 hover:bg-stone-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600">' + preset[0] + '</button>';
            }).join('') + '</div>' +
            '<div class="mt-3 flex items-stretch gap-2.5">' +
            '<div class="flex w-24 shrink-0 flex-col gap-1.5" role="radiogroup" aria-label="Take off or add">' +
            ['minus', 'plus'].map(function (sign) {
                var on = (sign === 'minus') === session.negative;

                return '<button type="button" role="radio" aria-checked="' + on + '" data-sign="' + sign + '" aria-label="' + (sign === 'minus' ? 'Take off' : 'Add') + '" class="flex flex-1 items-center justify-center rounded-2xl ring-1 ring-inset transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 motion-reduce:transition-none ' +
                    (on ? 'bg-brand-700 text-white ring-brand-700' : 'bg-white text-stone-800 ring-stone-300 hover:bg-stone-50') + '">' + UI.icon(sign, 'h-6 w-6') + '</button>';
            }).join('') + '</div>' + display('Points') + '</div>' +
            '<label for="entry-adjust-note" class="form-label mt-3">What for <span class="font-semibold text-stone-600">(optional)</span></label>' +
            '<input id="entry-adjust-note" type="text" enterkeyhint="done" autocomplete="off" maxlength="' + limits().maxNote + '" value="' + UI.escape(session.note) + '" class="form-control" placeholder="Tiles left">' +
            pad() + submitButton();
    }

    function panel() {
        return session.kind === 'pass' ? passPanel() : (session.kind === 'adjust' ? adjustPanel() : wordPanel());
    }

    function frame() {
        var options = session.options;

        return '<div data-turn-entry>' + players() + '<div id="entry-kinds">' + kinds() + '</div><div id="entry-panel">' + panel() + '</div>' +
            (options.onRemove ? '<button type="button" data-remove class="btn btn-danger-soft btn-block mt-3">' + UI.icon('trash', 'h-5 w-5') + 'Remove this turn</button>' : '') + '</div>';
    }

    // The total and what it is made of, for a word that is being scored tile by tile
    function updateTiles() {
        var done = tally();
        var problem = trouble();
        var shown = find('#entry-value');
        var lines = find('#entry-lines');
        var other = find('#entry-extra-row');

        if (other) { other.hidden = session.word === ''; }

        if (shown) {
            shown.textContent = problem === null ? String(done.total) : '–';
            shown.className = 'mt-1 text-5xl font-extrabold leading-none tabular-nums ' + (problem === null ? 'text-stone-900' : 'text-stone-300');
        }

        if (!lines) { return; }

        if (problem !== null) {
            lines.innerHTML = '<p class="' + (session.word === '' ? 'text-stone-600' : 'font-semibold text-amber-900') + '">' + problem + '</p>';
            return;
        }

        lines.innerHTML = '<p>Tiles <strong class="tabular-nums">' + done.sum + '</strong></p>' +
            (done.multiplier > 1 ? '<p>' + Tiles.multiplierName(done.multiplier) + ' &times;' + done.multiplier + ' <strong class="tabular-nums">= ' + done.main + '</strong></p>' : '') +
            (done.extra > 0 ? '<p>Other words <strong class="tabular-nums">+' + done.extra + '</strong></p>' : '') +
            (done.bingo ? '<p class="inline-flex items-center gap-1 font-bold text-amber-900">' + UI.icon('star', 'h-3.5 w-3.5') + 'Bingo, all seven tiles <strong class="tabular-nums">+' + limits().bingo + '</strong></p>' : '');
    }

    // The score, the bingo and the button say what will be saved. Nothing is redrawn, the word keeps its focus.
    function update() {
        var value = typed();
        var shown = find('#entry-value');
        var note = find('#entry-note');
        var button = find('#entry-submit');
        var adding = !session.options.turn;
        var ok = valid();

        if (tiling()) {
            updateTiles();
            shown = null;
        }

        if (shown) {
            shown.textContent = value === null ? '–' : (session.kind === 'adjust' ? UI.signed(session.negative ? -value : value) : String(value));
            shown.className = 'mt-1 text-4xl font-extrabold leading-none tabular-nums ' + (value === null ? 'text-stone-300' : 'text-stone-900');
        }

        if (note) {
            if (session.kind === 'word') {
                note.textContent = ok && session.bingo ? limits().bingo + ' for the bingo, ' + worth() + ' in all' : 'Between 1 and ' + limits().maxWord;
            } else {
                note.textContent = value === 0 ? 'More than nothing, please' : 'Up to ' + limits().maxAdjustment;
            }
        }

        var toggle = find('#entry-bingo');
        if (toggle) {
            toggle.setAttribute('aria-pressed', session.bingo);
            toggle.className = 'flex w-24 shrink-0 flex-col items-center justify-center gap-0.5 rounded-2xl text-sm font-extrabold ring-1 ring-inset transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 motion-reduce:transition-none ' +
                (session.bingo ? 'bg-amber-400 text-amber-950 ring-amber-400 hover:bg-amber-300' : 'bg-white text-stone-700 ring-stone-300 hover:bg-stone-50');
        }

        if (button) {
            button.disabled = !ok;

            if (session.kind === 'pass') {
                button.textContent = adding ? 'Pass this turn' : 'Save as a pass';
            } else if (!ok) {
                button.textContent = adding ? 'Add turn' : 'Save changes';
            } else {
                button.textContent = (adding ? 'Add ' : 'Save ') + (session.kind === 'adjust' ? UI.signed(worth()) : worth());
            }
        }
    }

    function render() {
        find('#entry-kinds').innerHTML = kinds();
        find('#entry-panel').innerHTML = panel();
        syncMode();
        update();
    }

    // The tiles and the tile that is open, drawn again. Whatever had the focus gets it back, the word keeps its own.
    function renderTiles() {
        var area = find('#entry-tiles');
        if (!area) { return; }

        var active = document.activeElement;
        var again = null;

        if (active && area.contains(active)) {
            ['tile', 'square', 'flag', 'tileStep'].forEach(function (name) {
                if (active.dataset[name] !== undefined) { again = '[data-' + name.replace('tileStep', 'tile-step') + '="' + active.dataset[name] + '"]'; }
            });
        }

        area.innerHTML = tilesArea();

        updateTiles();

        var back = again ? area.querySelector(again) : null;
        if (back && !back.disabled) { back.focus({preventScroll: true}); }
    }

    // There is a setting for every letter of the word, and the tile that is open is one of them
    function fit() {
        while (session.tiles.length < session.word.length) { session.tiles.push(Tiles.plain()); }

        session.tiles.length = session.word.length;
        session.tile = Math.max(0, Math.min(session.tile, session.word.length - 1));
    }

    function chooseTile(index, focus) {
        session.tile = index;
        renderTiles();

        var tile = find('[data-tile="' + index + '"]');
        if (focus && tile) { tile.focus({preventScroll: true}); }
    }

    function setMode(mode) {
        if (!session || mode === session.mode || (mode === 'tiles' && !session.canTiles)) { return; }

        if (mode === 'tiles') {
            // The English tiles only have the letters A to Z, the word comes with it
            session.word = session.word.replace(/[^A-Z]/g, '');
            fit();
        } else {
            // What the tiles came to goes on to the number pad, to be tweaked
            var done = tally();

            if (done.score >= 1 && done.score <= limits().maxWord) { session.digits = String(done.score); }
            session.bingo = done.bingo;
        }

        session.mode = mode;
        remember(mode);
        render();

        var next = mode === 'tiles' ? find('#entry-word') : (find('#entry-word') && !window.matchMedia('(pointer: coarse)').matches ? find('#entry-word') : find('#entry-display'));
        if (next) { next.focus(); }
    }

    // ---- Changing what has been entered -------------------------------------------------------------------------------

    function press(digit) {
        if (session.kind === 'pass') { return; }

        if (digit === 'back') {
            session.digits = session.digits.slice(0, -1);
        } else if (session.digits.length < 3) {
            session.digits = (session.digits + digit).replace(/^0+(?=\d)/, '');
        }

        update();
    }

    function submit() {
        if (!session || !valid()) { return; }

        var turn = build();
        var player = session.player;
        var options = session.options;

        UI.Sheet.close();
        options.onSubmit(turn, player);
    }

    // Letters, in capitals. Any letter, so a name with an accent is fine, a browser that is too old to say what a letter
    // is gets A to Z. The pattern is built here so an old browser can still read the rest of the file.
    var NOT_LETTERS;
    try { NOT_LETTERS = new RegExp('[^\\p{L}]', 'gu'); } catch (error) { NOT_LETTERS = /[^A-Za-z]/g; }

    function lettersOnly(value) {
        return value.replace(NOT_LETTERS, '').toUpperCase();
    }

    // The tiles are the English ones, which have the letters A to Z
    function tileLetters(value) {
        return value.replace(/[^A-Za-z]/g, '').toUpperCase();
    }

    // ---- Events: one set of listeners for every sheet that is ever opened ---------------------------------------------

    document.addEventListener('click', function (event) {
        if (!session) { return; }

        // The switch is beside the close button, outside the sheet's body
        var switcher = event.target.closest('[data-mode]');
        if (switcher && switcher.closest('#sheet-actions')) {
            setMode(session.mode === 'tiles' ? 'quick' : 'tiles');
            return;
        }

        if (!event.target.closest('[data-turn-entry]')) { return; }

        var target = event.target, element;

        if ((element = target.closest('[data-kind]'))) {
            session.kind = element.dataset.kind;
            render();

            var next = tiling() ? find('#entry-word')
                : (find('#entry-word') && !window.matchMedia('(pointer: coarse)').matches ? find('#entry-word') : (find('#entry-display') || find('#entry-submit')));
            if (next) { next.focus(); }
            return;
        }

        if ((element = target.closest('[data-tile]'))) { chooseTile(Number(element.dataset.tile), false); return; }

        if ((element = target.closest('[data-tile-step]'))) {
            chooseTile(Math.max(0, Math.min(session.word.length - 1, session.tile + Number(element.dataset.tileStep))), false);
            return;
        }

        if ((element = target.closest('[data-square]'))) {
            session.tiles[session.tile].square = element.dataset.square;
            renderTiles();
            update();
            return;
        }

        if ((element = target.closest('[data-flag]'))) {
            var setting = session.tiles[session.tile];

            if (element.dataset.flag === 'blank') {
                setting.blank = !setting.blank;
            } else {
                // A tile that was already there has no square that counts
                setting.board = !setting.board;
                if (setting.board) { setting.square = '-'; }
            }

            renderTiles();
            update();
            return;
        }

        if ((element = target.closest('[data-key]'))) { press(element.dataset.key); return; }

        if (target.closest('[data-bingo]')) { session.bingo = !session.bingo; update(); return; }

        if ((element = target.closest('[data-sign]'))) {
            session.negative = element.dataset.sign === 'minus';
            find('#entry-panel').querySelectorAll('[data-sign]').forEach(function (button) {
                var on = (button.dataset.sign === 'minus') === session.negative;
                button.setAttribute('aria-checked', on);
                button.className = 'flex flex-1 items-center justify-center rounded-2xl ring-1 ring-inset transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 motion-reduce:transition-none ' +
                    (on ? 'bg-brand-700 text-white ring-brand-700' : 'bg-white text-stone-800 ring-stone-300 hover:bg-stone-50');
            });
            update();
            return;
        }

        if ((element = target.closest('[data-preset]'))) {
            session.note = element.dataset.preset;
            session.negative = element.dataset.negative === 'true';
            render();
            find('#entry-display').focus();
            return;
        }

        if (target.closest('[data-submit]')) { submit(); return; }

        if (target.closest('[data-remove]')) {
            var remove = session.options.onRemove;
            UI.Sheet.close();
            remove();
        }
    });

    document.addEventListener('change', function (event) {
        if (session && event.target.name === 'entry-player') {
            session.player = event.target.value;

            if (session.options.describe) {
                var subtitle = document.getElementById('sheet-subtitle');
                if (subtitle) { subtitle.textContent = session.options.describe(session.player); }
            }
        }
    });

    document.addEventListener('input', function (event) {
        if (!session) { return; }

        if (event.target.id === 'entry-word') {
            var clean = tiling() ? tileLetters(event.target.value) : lettersOnly(event.target.value);
            if (clean !== event.target.value) { event.target.value = clean; }
            session.word = clean;

            if (tiling()) {
                fit();
                renderTiles();
                update();
            }
        } else if (event.target.id === 'entry-extra') {
            var digits = event.target.value.replace(/\D/g, '').slice(0, 3).replace(/^0+(?=\d)/, '');
            if (digits !== event.target.value) { event.target.value = digits; }
            session.extra = digits;
            update();
        } else if (event.target.id === 'entry-adjust-note') {
            session.note = event.target.value;
        }
    });

    // Type the turn on a laptop: the word, Enter, the score, Enter. The number pad is for fingers.
    document.addEventListener('keydown', function (event) {
        if (!session || event.ctrlKey || event.metaKey || event.altKey || !event.target.closest('[data-turn-entry]')) { return; }

        var field = event.target.tagName === 'INPUT' && event.target.type === 'text';

        // The tiles are one choice, the arrow keys move between them
        var tile = event.target.closest('[data-tile]');
        if (tile && ['ArrowLeft', 'ArrowUp', 'ArrowRight', 'ArrowDown', 'Home', 'End'].indexOf(event.key) !== -1) {
            var last = session.word.length - 1;
            var at = Number(tile.dataset.tile);
            var to = event.key === 'Home' ? 0 : (event.key === 'End' ? last : (event.key === 'ArrowLeft' || event.key === 'ArrowUp' ? Math.max(0, at - 1) : Math.min(last, at + 1)));

            event.preventDefault();
            chooseTile(to, true);
            return;
        }

        if (event.target.id === 'entry-word' && event.key === 'Enter') {
            event.preventDefault();
            var onwards = find('#entry-display') || find('[data-tile][aria-checked="true"]') || find('#entry-submit');
            if (onwards) { onwards.focus(); }
            return;
        }

        if (field && event.key === 'Enter') {
            event.preventDefault();
            submit();
            return;
        }

        // The number pad is for the quick way of scoring a word, and for an adjustment
        if (field || session.kind === 'pass' || tiling()) { return; }

        if (/^\d$/.test(event.key)) { press(event.key); event.preventDefault(); }
        else if (event.key === 'Backspace') { press('back'); event.preventDefault(); }
        else if (event.key === 'Enter' && event.target.tagName !== 'BUTTON') { event.preventDefault(); submit(); }
    });

    // ---- Opening ------------------------------------------------------------------------------------------------------

    // The tiles of a turn that is being changed, null when it was typed in or its tiles do not add up to its score
    function stored(turn, options) {
        if (turn.kind !== 'word' || !turn.tiles) { return null; }

        var tiles = Tiles.decode(turn.tiles, turn.word.length);
        if (tiles === null) { return null; }

        // What the score is beyond the tiles is the points of the other words
        var base = Tiles.score(turn.word, tiles, 0, options.limits.tileValues, options.limits.bingo).main;
        if (turn.score < base) { return null; }

        return {tiles: tiles, extra: turn.score > base ? String(turn.score - base) : ''};
    }

    function open(options) {
        var turn = options.turn || null;
        var canTiles = Tiles !== null && !!(options.limits && options.limits.tileValues);
        var earlier = canTiles && turn ? stored(turn, options) : null;
        var mine = {
            options: options,
            kind: turn ? turn.kind : 'word',
            // A new turn opens the way the device last scored one, a turn that is being changed the way it was entered
            mode: canTiles && (earlier !== null || (!turn && preferred() === 'tiles')) ? 'tiles' : 'quick',
            canTiles: canTiles,
            word: turn ? turn.word : '',
            digits: turn && turn.kind !== 'pass' ? String(Math.abs(turn.score)) : '',
            bingo: turn ? turn.bingo : false,
            negative: turn && turn.kind === 'adjust' ? turn.score < 0 : true,
            note: turn ? turn.note : '',
            tiles: earlier ? earlier.tiles : [],
            tile: 0,
            extra: earlier ? earlier.extra : '',
            player: options.player
        };

        session = mine;

        if (mine.mode === 'tiles') { fit(); }

        var subtitle = options.players && options.players.length > 1 && options.describe && !turn ? options.describe(mine.player) : options.subtitle;
        var fine = window.matchMedia('(pointer: fine)').matches && mine.kind === 'word';
        var focus = fine ? '#entry-word' : (mine.kind === 'pass' ? '#entry-submit' : '#entry-display');

        if (mine.kind === 'word' && mine.mode === 'tiles') { focus = turn ? '[data-tile="0"]' : '#entry-word'; }

        UI.Sheet.open({
            title: options.title,
            subtitle: subtitle,
            actions: canTiles ? modeButton() : '',
            body: frame(),
            focus: focus,
            onClose: function () { if (session === mine) { session = null; } }
        });

        syncMode();
        update();
    }

    window.TurnEntry = {open: open};
})();
