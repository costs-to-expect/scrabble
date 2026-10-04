/*
 * The turn entry: the sheet the score sheet and the landing page open to add or change a turn. A word and what it scored
 * (typed on a number pad, the 50 point bingo bonus a tap away), a pass (which is also swapping tiles) or an adjustment
 * for the tiles left at the end of the game. It only gathers the turn, the page decides what to do with it.
 *
 *   TurnEntry.open({
 *       title, subtitle,
 *       limits,                      // the server's own limits, written into the page
 *       players, player,             // who it could be for (chips), and who it is for, left out when it is for one player
 *       describe(playerId),          // the subtitle for a player, when the player can be chosen
 *       turn,                        // the turn being changed, left out for a new one
 *       onSubmit(turn, playerId),    // turn is {kind, word, score, bingo, note}, score is signed for an adjustment
 *       onRemove()                   // given when the turn can be taken off the sheet
 *   });
 */
(function () {
    'use strict';

    var UI = window.UI;
    var session = null;

    // ---- What has been entered --------------------------------------------------------------------------------------

    function limits() { return session.options.limits; }
    function maximum() { return session.kind === 'adjust' ? limits().maxAdjustment : limits().maxWord; }
    function typed() { return session.digits === '' ? null : Number(session.digits); }

    function valid() {
        if (session.kind === 'pass') { return true; }

        var value = typed();
        return value !== null && value >= 1 && value <= maximum();
    }

    // What the turn is worth, the bingo is on top of the word and an adjustment can take points off
    function worth() {
        var value = typed() || 0;

        if (session.kind === 'adjust') { return session.negative ? -value : value; }
        return value + (session.kind === 'word' && session.bingo ? limits().bingo : 0);
    }

    function build() {
        if (session.kind === 'pass') { return {kind: 'pass', word: '', score: 0, bingo: false, note: ''}; }
        if (session.kind === 'adjust') { return {kind: 'adjust', word: '', score: session.negative ? -typed() : typed(), bingo: false, note: session.note.trim()}; }

        return {kind: 'word', word: session.word.trim().toUpperCase(), score: typed(), bingo: session.bingo, note: ''};
    }

    // ---- Drawing ------------------------------------------------------------------------------------------------------

    function body() { return UI.Sheet.body(); }
    function find(selector) { return body().querySelector(selector); }

    function padKey(content, attributes) {
        return '<button type="button" ' + attributes + ' class="h-14 rounded-2xl bg-stone-100 text-xl font-bold transition hover:bg-stone-200 active:bg-stone-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 motion-reduce:transition-none">' + content + '</button>';
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

        return '<fieldset class="mb-4"><legend class="sr-only">Who played</legend><div class="flex flex-wrap gap-2">' + options.players.map(function (player) {
            return '<label class="chip has-checked:bg-brand-700 has-checked:text-white has-checked:ring-brand-700 has-checked:hover:bg-brand-800 has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-brand-600">' +
                '<input type="radio" name="entry-player" value="' + UI.escape(player.id) + '" class="peer sr-only"' + (player.id === session.player ? ' checked' : '') + '>' +
                '<span class="hidden h-7 w-7 shrink-0 items-center justify-center rounded-full bg-white text-brand-700 peer-checked:inline-flex">' + UI.icon('check', 'h-4 w-4') + '</span>' +
                UI.avatar(player.name, player.tone, 'h-7 w-7 text-xs peer-checked:hidden') + UI.escape(player.name) + '</label>';
        }).join('') + '</div></fieldset>';
    }

    function kinds() {
        var labels = [['word', 'Word'], ['pass', 'Pass'], ['adjust', 'Adjust']];

        return '<div class="mb-4 flex gap-1 rounded-full bg-stone-100 p-1 text-sm font-bold" role="radiogroup" aria-label="What happened">' + labels.map(function (kind) {
            var on = session.kind === kind[0];

            return '<button type="button" role="radio" aria-checked="' + on + '" data-kind="' + kind[0] + '" class="min-h-10 flex-1 rounded-full px-3 transition focus-visible:outline-2 focus-visible:outline-brand-600 motion-reduce:transition-none ' +
                (on ? 'bg-white text-stone-900 shadow-sm' : 'text-stone-600 hover:text-stone-900') + '">' + kind[1] + '</button>';
        }).join('') + '</div>';
    }

    function display(label) {
        return '<div id="entry-display" data-autofocus tabindex="-1" class="min-w-0 flex-1 rounded-2xl bg-stone-50 px-4 py-3 text-center ring-1 ring-stone-200 outline-none">' +
            '<p class="text-xs font-bold uppercase tracking-wider text-stone-600">' + label + '</p>' +
            '<p id="entry-value" class="mt-1 text-5xl font-extrabold leading-none tabular-nums text-stone-300" aria-live="polite">&ndash;</p>' +
            '<p id="entry-note" class="mt-1.5 h-5 text-sm text-stone-600"></p></div>';
    }

    function bingo() {
        return '<button type="button" id="entry-bingo" data-bingo aria-pressed="false" class="flex w-24 shrink-0 flex-col items-center justify-center gap-0.5 rounded-2xl text-sm font-extrabold ring-1 ring-inset transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 motion-reduce:transition-none">' +
            UI.icon('star', 'h-5 w-5') + '<span>Bingo</span><span class="text-xs font-bold">+' + limits().bingo + '</span></button>';
    }

    function submitButton() {
        return '<button type="button" id="entry-submit" data-submit class="btn btn-primary btn-block h-14 text-base font-extrabold" disabled></button>';
    }

    function wordPanel() {
        return '<div><label for="entry-word" class="form-label">Word <span class="font-semibold text-stone-600">(optional)</span></label>' +
            '<input id="entry-word" type="text" inputmode="text" enterkeyhint="next" autocomplete="off" autocapitalize="characters" spellcheck="false" maxlength="' + limits().maxLetters + '" value="' + UI.escape(session.word) + '" ' +
            'class="form-control text-xl font-extrabold uppercase tracking-widest placeholder:text-base placeholder:font-semibold placeholder:normal-case placeholder:tracking-normal" placeholder="The main word you played"></div>' +
            '<div class="mt-3 flex items-stretch gap-2.5">' + display('Score for the word') + bingo() + '</div>' + pad() +
            '<div class="mt-3">' + submitButton() + '</div>';
    }

    function passPanel() {
        return '<p class="rounded-2xl bg-stone-50 px-4 py-3.5 text-stone-700 ring-1 ring-stone-200">Passing, or swapping tiles, scores nothing but it is still a turn, so it is the next player&rsquo;s go.</p>' +
            '<div class="mt-3">' + submitButton() + '</div>';
    }

    function adjustPanel() {
        var presets = [['Tiles left', true], ['Went out', false], ['Penalty', true]];

        return '<p class="text-sm text-stone-600">For the end of the game: take off the tiles left on a rack, add them for whoever went out, or take off a penalty.</p>' +
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
            pad() + '<div class="mt-3">' + submitButton() + '</div>';
    }

    function panel() {
        return session.kind === 'pass' ? passPanel() : (session.kind === 'adjust' ? adjustPanel() : wordPanel());
    }

    function frame() {
        var options = session.options;

        return '<div data-turn-entry>' + players() + '<div id="entry-kinds">' + kinds() + '</div><div id="entry-panel">' + panel() + '</div>' +
            (options.onRemove ? '<button type="button" data-remove class="btn btn-danger-soft btn-block mt-3">' + UI.icon('trash', 'h-5 w-5') + 'Remove this turn</button>' : '') + '</div>';
    }

    // The score, the bingo and the button say what will be saved. Nothing is redrawn, the word keeps its focus.
    function update() {
        var value = typed();
        var shown = find('#entry-value');
        var note = find('#entry-note');
        var button = find('#entry-submit');
        var adding = !session.options.turn;
        var ok = valid();

        if (shown) {
            shown.textContent = value === null ? '–' : (session.kind === 'adjust' ? UI.signed(session.negative ? -value : value) : String(value));
            shown.className = 'mt-1 text-5xl font-extrabold leading-none tabular-nums ' + (value === null ? 'text-stone-300' : 'text-stone-900');
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
        update();
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

    // ---- Events: one set of listeners for every sheet that is ever opened ---------------------------------------------

    document.addEventListener('click', function (event) {
        if (!session || !event.target.closest('[data-turn-entry]')) { return; }

        var target = event.target, element;

        if ((element = target.closest('[data-kind]'))) {
            session.kind = element.dataset.kind;
            render();

            var next = find('#entry-word') && !window.matchMedia('(pointer: coarse)').matches ? find('#entry-word') : (find('#entry-display') || find('#entry-submit'));
            if (next) { next.focus(); }
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
            var clean = lettersOnly(event.target.value);
            if (clean !== event.target.value) { event.target.value = clean; }
            session.word = clean;
        } else if (event.target.id === 'entry-adjust-note') {
            session.note = event.target.value;
        }
    });

    // Type the turn on a laptop: the word, Enter, the score, Enter. The number pad is for fingers.
    document.addEventListener('keydown', function (event) {
        if (!session || event.ctrlKey || event.metaKey || event.altKey || !event.target.closest('[data-turn-entry]')) { return; }

        var field = event.target.tagName === 'INPUT' && event.target.type === 'text';

        if (event.target.id === 'entry-word' && event.key === 'Enter') {
            event.preventDefault();
            find('#entry-display').focus();
            return;
        }

        if (field && event.key === 'Enter') {
            event.preventDefault();
            submit();
            return;
        }

        if (field || session.kind === 'pass') { return; }

        if (/^\d$/.test(event.key)) { press(event.key); event.preventDefault(); }
        else if (event.key === 'Backspace') { press('back'); event.preventDefault(); }
        else if (event.key === 'Enter' && event.target.tagName !== 'BUTTON') { event.preventDefault(); submit(); }
    });

    // ---- Opening ------------------------------------------------------------------------------------------------------

    function open(options) {
        var turn = options.turn || null;
        var mine = {
            options: options,
            kind: turn ? turn.kind : 'word',
            word: turn ? turn.word : '',
            digits: turn && turn.kind !== 'pass' ? String(Math.abs(turn.score)) : '',
            bingo: turn ? turn.bingo : false,
            negative: turn && turn.kind === 'adjust' ? turn.score < 0 : true,
            note: turn ? turn.note : '',
            player: options.player
        };

        session = mine;

        var subtitle = options.players && options.players.length > 1 && options.describe && !turn ? options.describe(mine.player) : options.subtitle;
        var fine = window.matchMedia('(pointer: fine)').matches && mine.kind === 'word';

        UI.Sheet.open({
            title: options.title,
            subtitle: subtitle,
            body: frame(),
            focus: fine ? '#entry-word' : (mine.kind === 'pass' ? '#entry-submit' : '#entry-display'),
            onClose: function () { if (session === mine) { session = null; } }
        });

        update();
    }

    window.TurnEntry = {open: open};
})();
