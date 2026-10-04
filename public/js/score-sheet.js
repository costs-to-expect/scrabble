/*
 * The score sheet. The page (resources/views/components/score-sheet.blade.php) holds the totals bar and the places to
 * draw into, the players, their turns and the settings come from the JSON in #sheet-config. This script draws every
 * list, takes the turns, saves them and keeps everyone's scores up to date.
 *
 * The signed-in page scores for every player in the game from this one screen, a public link scores for the one player
 * it was made for, the script is the same.
 *
 * Turns are saved one at a time, in the order they were made: the server reads the player's whole sheet, adds the turn
 * and writes it back, so two saves at once would lose one of them. The screen updates straight away and a pill says
 * Saving, Saved or Not saved. A save that fails keeps its turn on the screen, with a Retry, nothing is lost. A turn
 * has an id made up here, so a save that is sent again after its answer got lost never scores twice.
 */
(function () {
    'use strict';

    var UI = window.UI;
    var config = JSON.parse(document.getElementById('sheet-config').textContent);

    var LIMITS = config.limits;
    var owner = config.owner === true;
    var readOnly = config.complete === true;
    var corrections = config.corrections === true && !readOnly;

    // ---- State -------------------------------------------------------------------------------------------------------

    var players = config.players;       // who this screen scores for, in the order they play in
    var sheets = {};                    // player id -> every turn on their sheet, the removed ones too
    var selected = config.focus;        // whose turns are listed, and who a new turn is for
    var queue = [];                     // saves waiting to be sent, in the order they were made
    var unsaved = {};                   // key -> the save that could not be sent
    var inFlight = null;                // the save being sent now
    var last = null;                    // the last change, for Undo
    var returnFocus = null;             // the row to give focus back to after the lists are redrawn
    var previousTotal = null;
    var everyone = [];                  // what the server last said about everyone, a public page needs it for the others
    var sessionEnded = false;           // the signed-in player was signed out
    var gone = false;                   // the game was finished or deleted, a public link stops working

    players.forEach(function (player) {
        sheets[player.id] = (config.sheets[player.id] ? config.sheets[player.id].turns : []).map(copy);
    });

    function copy(turn) { return Object.assign({}, turn); }
    function $(id) { return document.getElementById(id); }
    function toneOf(id) { return config.tones && config.tones[id] !== undefined ? config.tones[id] : 0; }
    function playerOf(id) { return players.filter(function (player) { return player.id === id; })[0]; }
    function nameOf(id) { var player = playerOf(id); return player ? player.name : ''; }
    function unsavedCount() { return Object.keys(unsaved).length; }
    function unsavedFor(player) { return Object.keys(unsaved).filter(function (id) { return unsaved[id].player === player; }).length; }
    function key(player, id) { return player + ':' + id; }
    function opKey(operation) { return key(operation.player, operation.turn ? operation.turn.id : operation.id); }

    // ---- The rules, the server checks the same ones (App\Support\ScoreRules and App\Support\Stats) ---------------------

    function points(turn) {
        if (turn.kind === 'pass') { return 0; }
        return turn.kind === 'adjust' ? turn.score : turn.score + (turn.bingo ? LIMITS.bingo : 0);
    }

    function live(id) { return (sheets[id] || []).filter(function (turn) { return !turn.removed; }); }

    // What a player has done: the score, the turns played, and the best, lowest and longest word. The first of a tie wins.
    function numbers(id) {
        var result = {total: 0, turns: 0, words: 0, bingos: 0, wordPoints: 0, best: null, lowest: null, longest: null, last: null};

        live(id).forEach(function (turn) {
            var worth = points(turn);

            result.total += worth;
            result.last = turn;

            if (turn.kind !== 'adjust') { result.turns++; }
            if (turn.kind !== 'word') { return; }

            result.words++;
            result.wordPoints += worth;
            if (turn.bingo) { result.bingos++; }
            if (result.best === null || worth > points(result.best)) { result.best = turn; }
            if (result.lowest === null || worth < points(result.lowest)) { result.lowest = turn; }
            if (turn.word !== '' && (result.longest === null || turn.word.length > result.longest.word.length ||
                (turn.word.length === result.longest.word.length && worth > points(result.longest)))) { result.longest = turn; }
        });

        result.average = result.words > 0 ? result.wordPoints / result.words : null;

        return result;
    }

    // Whoever has played the fewest turns is next, the first of them when several have
    function nextUp() {
        var fewest = Infinity, who = null;

        players.forEach(function (player) {
            var turns = numbers(player.id).turns;
            if (turns < fewest) { fewest = turns; who = player.id; }
        });

        return who;
    }

    function playText(turn) {
        if (!turn) { return ''; }
        if (turn.kind === 'pass') { return 'Passed'; }
        if (turn.kind === 'adjust') { return (turn.note !== '' ? turn.note : 'Adjustment') + ' ' + UI.signed(turn.score); }

        return (turn.word + ' ' + UI.signed(points(turn))).trim();
    }

    function average(value) { return value === null ? '–' : (Math.round(value * 10) / 10).toFixed(1).replace(/\.0$/, ''); }

    // ---- Everyone: the same list for the strip, the panel and the finish dialog ----------------------------------------

    function people() {
        var due = nextUp();
        var list;

        if (owner) {
            list = players.map(function (player) {
                var mine = numbers(player.id);
                return {id: player.id, name: player.name, total: mine.total, turns: mine.turns, last: playText(mine.last), you: false, next: player.id === due, unsaved: unsavedFor(player.id)};
            });
        } else {
            // Until the first read from the server there is only the player whose sheet this is
            var me = numbers(players[0].id);
            list = everyone.filter(function (person) { return person.id !== players[0].id; }).map(function (person) {
                return {id: person.id, name: person.name, total: person.total, turns: person.turns, last: person.last || '', you: false, next: false};
            });
            list.push({id: players[0].id, name: players[0].name, total: me.total, turns: me.turns, last: playText(me.last), you: true, next: false, unsaved: unsavedFor(players[0].id)});

            // Whoever has played the fewest turns, among the people we know about
            var fewest = Math.min.apply(null, list.map(function (person) { return person.turns; }));
            if (everyone.length > 1) { list.some(function (person) { if (person.turns === fewest) { person.next = true; return true; } return false; }); }
        }

        // A crown is for being ahead: someone has scored and someone else has scored less
        var totals = list.map(function (person) { return person.total; });
        var best = Math.max.apply(null, totals), worst = Math.min.apply(null, totals);
        var crowned = list.length > 1 && best > 0 && best > worst;

        list.forEach(function (person) { person.leader = crowned && person.total === best; });

        return list;
    }

    function ranked(list) {
        // A sort that is stable: a tie keeps the order the players play in
        return list.map(function (person, position) { return {person: person, position: position}; })
            .sort(function (a, b) { return (b.person.total - a.person.total) || (a.position - b.position); })
            .map(function (entry) { return entry.person; });
    }

    // ---- Drawing -----------------------------------------------------------------------------------------------------

    function pop(element) {
        if (!element.animate || UI.reducedMotion()) { return; }
        element.animate([{transform: 'scale(1.16)'}, {transform: 'scale(1)'}], {duration: 260, easing: 'ease-out'});
    }

    function renderStatus() {
        var button = $('status');
        var failed = unsavedCount();
        var saving = inFlight !== null || queue.length > 0;
        var base = '-mr-1 inline-flex h-11 min-w-11 shrink-0 items-center justify-center gap-1.5 rounded-full px-2.5 text-xs font-bold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 ';

        if (failed > 0) {
            button.className = base + 'bg-amber-100 text-amber-900';
            button.innerHTML = UI.icon('alert', 'h-5 w-5') + '<span class="sr-only sm:not-sr-only">' + failed + ' not saved</span>';
        } else if (saving) {
            button.className = base + 'text-stone-600';
            button.innerHTML = UI.icon('refresh', 'h-5 w-5 motion-safe:animate-spin') + '<span class="sr-only sm:not-sr-only">Saving</span>';
        } else {
            button.className = base + 'text-emerald-700';
            button.innerHTML = UI.icon('check-circle', 'h-5 w-5') + '<span class="sr-only sm:not-sr-only">Saved</span>';
        }

        $('banner').hidden = failed === 0;

        if (gone) {
            $('banner-text').textContent = 'This game has been finished or deleted, so the turn could not be saved. Ask whoever is running the game.';
            $('banner-retry').hidden = true;
        } else if (sessionEnded) {
            $('banner-text').textContent = 'You have been signed out, reload the page to carry on. Your turns are safe on this screen until you do.';
            $('banner-retry').textContent = 'Reload';
            $('banner-retry').hidden = false;
        } else {
            $('banner-text').textContent = failed === 1 ? '1 turn isn’t saved yet, it’s safe on this screen.' : failed + ' turns aren’t saved yet, they’re safe on this screen.';
            $('banner-retry').textContent = 'Retry';
            $('banner-retry').hidden = false;
        }
    }

    function badge(text) {
        return '<span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-xs font-extrabold text-brand-800 ring-1 ring-brand-100" aria-hidden="true">' + text + '</span>';
    }

    function time(turn) {
        if (!turn.at) { return ''; }

        var date = new Date(turn.at);
        return isNaN(date.getTime()) ? '' : date.toLocaleTimeString([], {hour: 'numeric', minute: '2-digit'});
    }

    function rowHtml(turn, number) {
        var failed = unsaved[key(selected, turn.id)] !== undefined;
        var interactive = !readOnly && (corrections || failed);
        var worth = points(turn);
        var title, hint, right;

        if (turn.kind === 'pass') {
            title = '<span class="block font-bold text-stone-700">Passed</span>';
            hint = 'Or swapped tiles, it still counts as a turn';
            right = '<span class="w-14 text-right text-xl font-extrabold tabular-nums text-stone-400">0</span>';
        } else if (turn.kind === 'adjust') {
            title = '<span class="block font-bold">' + UI.escape(turn.note !== '' ? turn.note : 'Adjustment') + '</span>';
            hint = worth < 0 ? 'Taken off the score' : 'Added to the score';
            right = '<span class="w-14 text-right text-xl font-extrabold tabular-nums">' + UI.signed(worth) + '</span>';
        } else {
            title = '<span class="block font-bold ' + (turn.word !== '' ? 'uppercase tracking-wide' : 'text-stone-700') + '">' + UI.escape(turn.word !== '' ? turn.word : 'A word') + '</span>';
            hint = turn.bingo ? turn.score + ' + ' + LIMITS.bingo + ' bingo' : turn.score + (turn.score === 1 ? ' point' : ' points');
            right = (turn.bingo ? '<span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-bold text-amber-900">' + UI.icon('star', 'h-3 w-3') + 'Bingo</span>' : '') +
                '<span class="w-14 text-right text-xl font-extrabold tabular-nums">' + UI.signed(worth) + '</span>';
        }

        var when = time(turn);
        if (failed) { right = '<span class="inline-flex items-center gap-1.5 rounded-full bg-amber-100 px-3 py-1.5 text-xs font-bold text-amber-900">' + UI.icon('alert', 'h-4 w-4') + 'Not saved &middot; Retry</span>'; }

        var inner = badge(turn.kind === 'adjust' ? '&plusmn;' : number) +
            '<span class="min-w-0 flex-1">' + title + '<span class="block truncate text-xs text-stone-600">' + hint + (when ? ' &middot; ' + when : '') + '</span></span>' +
            '<span class="flex shrink-0 items-center gap-2">' + right + '</span>';

        if (!interactive) {
            return '<li><div class="flex min-h-16 w-full items-center gap-3.5 px-4 py-2.5">' + inner + '</div></li>';
        }

        return '<li><button type="button" data-turn="' + UI.escape(turn.id) + '" class="flex min-h-16 w-full items-center gap-3.5 px-4 py-2.5 text-left transition hover:bg-stone-50 active:bg-stone-100 focus-visible:bg-stone-50 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-brand-600 motion-reduce:transition-none">' +
            inner + '</button></li>';
    }

    // Four across, even on a phone, so the turns are not pushed off the screen by the numbers about them
    function statCard(label, value, detail, star) {
        return '<div class="min-w-0 rounded-2xl bg-white p-2.5 shadow-card ring-1 ring-stone-200/70 sm:p-3.5"><dt class="truncate text-[10px] font-bold uppercase tracking-wider text-stone-600 sm:text-[11px]">' + label + '</dt>' +
            '<dd class="mt-1 text-xl font-extrabold leading-none tabular-nums sm:text-2xl">' + value + '</dd>' +
            '<dd class="mt-1.5 flex min-h-4 items-center gap-1 text-[11px] text-stone-600 sm:text-xs">' + (star ? UI.icon('star', 'h-3 w-3 shrink-0 text-amber-500') : '') + '<span class="truncate">' + detail + '</span></dd></div>';
    }

    function renderStats(mine) {
        var word = function (turn) { return turn === null ? 'No words yet' : (turn.word !== '' ? UI.escape(turn.word) : 'Word not entered'); };

        $('player-stats').innerHTML =
            statCard('Best', mine.best === null ? '–' : points(mine.best), word(mine.best), mine.best !== null && mine.best.bingo) +
            statCard('Lowest', mine.lowest === null ? '–' : points(mine.lowest), word(mine.lowest), false) +
            statCard('Average', average(mine.average), mine.words === 0 ? 'No words yet' : 'a word', false) +
            statCard('Longest', mine.longest === null ? '–' : mine.longest.word.length, mine.longest === null ? 'No words yet' : UI.escape(mine.longest.word), false);
    }

    function renderTurns(mine) {
        var entries = live(selected);
        var number = 0;
        var rows = entries.map(function (turn) {
            if (turn.kind !== 'adjust') { number++; }
            return rowHtml(turn, number);
        });

        // The newest first, it is the one that is about to be corrected
        $('turn-list').innerHTML = rows.reverse().join('');
        $('turn-list').hidden = entries.length === 0;
        $('turn-empty').hidden = entries.length !== 0;
        $('turn-empty-text').textContent = readOnly ? nameOf(selected) + ' did not play a turn.' : 'Tap Add a turn when it is ' + (owner ? nameOf(selected) + '’s' : 'your') + ' go.';
        $('turns-count').textContent = mine.turns + (mine.turns === 1 ? ' turn' : ' turns') + (mine.words > 0 ? ' · ' + mine.words + (mine.words === 1 ? ' word' : ' words') : '');
        $('turns-heading').textContent = owner ? nameOf(selected) + '’s turns' : 'Your turns';
    }

    function personRow(person, clickable) {
        var content = '<span class="relative h-11 w-11 shrink-0">' + UI.avatar(person.name, toneOf(person.id), 'h-11 w-11 text-sm') +
            (person.leader ? '<span class="absolute -right-1 -top-1 flex h-4.5 w-4.5 items-center justify-center rounded-full bg-amber-400 text-amber-950 ring-2 ring-white">' + UI.icon('crown', 'h-2.5 w-2.5') + '<span class="sr-only">Leading</span></span>' : '') + '</span>' +
            '<span class="min-w-0 flex-1"><span class="flex items-center gap-2"><span class="truncate font-bold">' + UI.escape(person.name) + '</span>' +
            (person.you ? '<span class="text-xs font-semibold text-brand-800">you</span>' : '') +
            (person.next && !readOnly ? '<span class="inline-flex shrink-0 items-center rounded-full bg-brand-700 px-2 py-0.5 text-[11px] font-bold text-white">Next up</span>' : '') +
            (person.unsaved ? '<span class="inline-flex shrink-0 items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-bold text-amber-900">' + UI.icon('alert', 'h-3 w-3') + 'Not saved</span>' : '') + '</span>' +
            '<span class="block truncate text-xs text-stone-600">' + (person.last !== '' ? 'Last: ' + UI.escape(person.last) : 'No turns yet') + '</span></span>' +
            '<span class="text-2xl font-extrabold tabular-nums">' + person.total + '</span>';
        var highlight = person.you || (clickable && person.id === selected) ? 'bg-brand-50/70' : '';

        if (!clickable) { return '<li class="flex items-center gap-3 px-4 py-3 ' + highlight + '">' + content + '</li>'; }

        return '<li><button type="button" data-select="' + UI.escape(person.id) + '" aria-pressed="' + (person.id === selected) + '" class="flex w-full items-center gap-3 px-4 py-3 text-left transition hover:bg-stone-50 focus-visible:bg-stone-50 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-brand-600 motion-reduce:transition-none ' + highlight + '">' + content + '</button></li>';
    }

    function renderEveryone(list) {
        $('everyone').innerHTML = ranked(list).map(function (person) { return personRow(person, owner); }).join('');
    }

    // The players across the top of a phone, in the order they play in, the one whose turns are listed is filled in
    function renderStrip(list) {
        var strip = $('strip');
        if (!strip) { return; }

        strip.innerHTML = '<div class="grid gap-1.5" style="grid-template-columns: repeat(' + list.length + ', minmax(0, 1fr))">' + list.map(function (person) {
            var on = person.id === selected;

            return '<button type="button" data-select="' + UI.escape(person.id) + '" aria-pressed="' + on + '" class="min-w-0 rounded-xl px-2.5 py-1.5 text-left transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 motion-reduce:transition-none ' +
                (on ? 'bg-brand-700 text-white' : 'bg-stone-100 text-stone-800 hover:bg-stone-200') + '">' +
                '<span class="flex items-center gap-1.5"><span class="truncate text-xs font-bold">' + UI.escape(person.name) + '</span>' +
                (person.leader ? UI.icon('crown', 'h-3 w-3 shrink-0 ' + (on ? 'text-amber-300' : 'text-amber-500')) + '<span class="sr-only">Leading</span>' : '') +
                (person.unsaved ? UI.icon('alert', 'h-3.5 w-3.5 shrink-0 ' + (on ? 'text-amber-300' : 'text-amber-600')) + '<span class="sr-only">Has a turn that is not saved</span>' : '') + '</span>' +
                '<span class="flex items-center justify-between gap-1"><span class="text-lg font-extrabold leading-tight tabular-nums">' + person.total + '</span>' +
                (person.next && !readOnly ? '<span class="rounded-full px-1.5 text-[10px] font-bold ' + (on ? 'bg-white text-brand-800' : 'bg-brand-700 text-white') + '">Next</span>' : '') + '</span></button>';
        }).join('') + '</div>';
    }

    function renderFinish(list) {
        var finish = $('finish-list');
        if (!finish) { return; }

        finish.innerHTML = ranked(list).map(function (person, rank) {
            return '<li class="flex items-center gap-3 px-3 py-2.5"><span class="w-5 text-center text-sm font-bold text-stone-500">' + (rank + 1) + '</span>' +
                UI.avatar(person.name, toneOf(person.id), 'h-8 w-8 text-sm') +
                '<span class="flex-1 font-semibold">' + UI.escape(person.name) + (person.leader ? ' ' + UI.icon('crown', 'ml-1 inline h-4 w-4 text-amber-500') + '<span class="sr-only">Leading</span>' : '') + '</span>' +
                '<span class="text-lg font-extrabold tabular-nums">' + person.total + '</span></li>';
        }).join('');

        // A turn that is not saved yet would not count, finish once it has been
        var pending = unsavedCount() > 0 || queue.length > 0 || inFlight !== null;
        $('finish-unsaved').hidden = !pending;
        document.querySelectorAll('[data-finish]').forEach(function (button) { button.disabled = pending; });
    }

    // The lists are rebuilt on every change, so remember which control had focus and give it back afterwards
    function focusSelector(element) {
        if (!element || !element.dataset) { return null; }
        if (element.dataset.turn) { return '[data-turn="' + element.dataset.turn + '"]'; }
        if (element.dataset.select) { return '[data-select="' + element.dataset.select + '"]'; }

        return element.id === 'status' ? '#status' : null;
    }

    function render() {
        var keep = focusSelector(document.activeElement);
        var mine = numbers(selected);
        var list = people();
        var name = nameOf(selected);

        $('total').textContent = mine.total;
        if (previousTotal !== null && previousTotal !== mine.total) { pop($('total')); }
        previousTotal = mine.total;
        $('turn-label').textContent = '· ' + mine.turns + (mine.turns === 1 ? ' turn' : ' turns');
        $('head-best').textContent = mine.best === null ? '–' : points(mine.best);
        $('head-average').textContent = average(mine.average);
        $('head-bingos').textContent = mine.bingos;
        $('nav-name').textContent = 'Player: ' + name;
        $('nav-avatar').innerHTML = UI.avatar(name, toneOf(selected), 'h-8 w-8 text-sm');

        renderStats(mine);
        renderTurns(mine);
        renderEveryone(list);
        renderStrip(list);
        renderFinish(list);

        var add = $('add-turn');
        if (add) {
            var due = nextUp();

            $('add-label').textContent = owner ? 'Add a turn for ' + name : 'Add a turn';
            $('add-hint').textContent = owner && list.length > 1 ? nameOf(due) + ' is next up' : '';
        }

        renderStatus();

        var selector = returnFocus ? '[data-turn="' + returnFocus + '"]' : keep;
        if (selector && !document.querySelector('dialog[open]')) {
            var again = document.querySelector(selector);
            if (again) { again.focus({preventScroll: true}); }
            returnFocus = null;
        }
    }

    // ---- Saving ------------------------------------------------------------------------------------------------------

    function csrf() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.content : '';
    }

    function fields(turn) {
        return {id: turn.id, kind: turn.kind, word: turn.word, score: turn.score, bingo: turn.bingo, note: turn.note};
    }

    function requestFor(operation) {
        var payload = Object.assign({}, config.ids, {player_id: operation.player});

        switch (operation.type) {
            case 'add': return {url: config.urls.turn, payload: Object.assign(payload, fields(operation.turn))};
            case 'change': return {url: config.urls.turn, payload: Object.assign(payload, fields(operation.turn), {replace: true})};
            case 'remove': return {url: config.urls.remove, payload: Object.assign(payload, {id: operation.id})};
            default: return {url: config.urls.restore, payload: Object.assign(payload, {id: operation.id})};
        }
    }

    function enqueue(operation) {
        queue.push(operation);
        delete unsaved[opKey(operation)];
        pump();
    }

    function pump() {
        if (inFlight !== null) { return; }

        var operation = queue.shift();
        if (!operation) { renderStatus(); return; }

        inFlight = operation;
        renderStatus();

        var request = requestFor(operation);

        fetch(request.url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest'},
            body: JSON.stringify(request.payload)
        }).then(function (response) {
            return response.json().catch(function () { return {}; }).then(function (body) { return {status: response.status, body: body}; });
        }).catch(function () {
            return {status: 0, body: {}};
        }).then(function (result) {
            finished(operation, result);
        });
    }

    function finished(operation, result) {
        inFlight = null;
        var status = result.status, body = result.body;

        if (status === 200) {
            delete unsaved[opKey(operation)];
            if (body.sheet) { catchUp(operation.player, body.sheet); }
        } else if ((status === 409 || status === 422 || status === 403) && body.sheet) {
            // The server will not take it and says what the sheet really is, show that and say why
            delete unsaved[opKey(operation)];
            catchUp(operation.player, body.sheet);
            UI.Snack.show(body.message || 'That turn could not be saved', {duration: 8000});
        } else if (status === 404 && !owner) {
            // The link only lives until the game is finished or deleted
            gone = true;
            unsaved[opKey(operation)] = operation;
            UI.Snack.show('This game has finished, the turn could not be saved', {duration: 8000});
        } else {
            // No connection, the API is down or the session ended: keep the turn on the screen and offer a retry
            if (status === 419 || status === 401) { sessionEnded = true; }
            unsaved[opKey(operation)] = operation;
            UI.Snack.show(sessionEnded ? 'You have been signed out, the turn is safe on this screen' : 'Not saved, we’ll keep it here until it is', sessionEnded ? {duration: 8000} : {action: 'Retry', onAction: retryAll, duration: 8000});
        }

        render();
        pump();
    }

    // Lays one change on a list of turns
    function apply(turns, operation) {
        var id = operation.turn ? operation.turn.id : operation.id;
        var index = -1;

        turns.forEach(function (turn, position) { if (turn.id === id) { index = position; } });

        if (operation.type === 'add') {
            if (index === -1) { turns.push(copy(operation.turn)); }
        } else if (index !== -1) {
            if (operation.type === 'change') { Object.assign(turns[index], fields(operation.turn)); }
            if (operation.type === 'remove') { turns[index].removed = true; }
            if (operation.type === 'restore') { turns[index].removed = false; }
        }
    }

    // Take the server's sheet for a player, then lay what has not reached the server yet back on top of it
    function catchUp(player, sheet) {
        if (!sheets[player]) { return; }

        var turns = (sheet.turns || []).map(copy);
        var pending = queue.slice();

        if (inFlight) { pending.unshift(inFlight); }
        Object.keys(unsaved).forEach(function (id) { pending.push(unsaved[id]); });
        pending.forEach(function (operation) { if (operation.player === player) { apply(turns, operation); } });

        sheets[player] = turns;
    }

    function retryAll() {
        if (sessionEnded) { window.location.reload(); return; }

        var failed = Object.keys(unsaved).map(function (id) { return unsaved[id]; });
        unsaved = {};
        failed.forEach(function (operation) { queue.push(operation); });
        render();
        pump();
    }

    // ---- Changing the sheet ------------------------------------------------------------------------------------------

    function newId() {
        var bytes = new Uint8Array(9);

        if (window.crypto && window.crypto.getRandomValues) { window.crypto.getRandomValues(bytes); } else {
            for (var i = 0; i < bytes.length; i++) { bytes[i] = Math.floor(Math.random() * 256); }
        }

        return 't' + Array.prototype.map.call(bytes, function (byte) { return ('0' + byte.toString(16)).slice(-2); }).join('');
    }

    function announce(player, text) { return owner ? nameOf(player) + ' · ' + text : text; }
    function find(player, id) { return sheets[player].filter(function (turn) { return turn.id === id; })[0]; }

    function commitAdd(player, entered) {
        var turn = Object.assign({id: newId(), at: '', removed: false}, entered);

        sheets[player].push(turn);
        last = {type: 'add', player: player, id: turn.id};

        // The screen follows the game: the next turn is the next player's, so they are ready to be scored for
        if (owner) { selected = nextUp(); }

        enqueue({type: 'add', player: player, turn: copy(turn)});
        render();
        remember();

        if (turn.bingo) { UI.confetti(); }

        var text = turn.kind === 'word' && turn.bingo ? 'Bingo! ' + playText(turn) : (turn.kind === 'pass' ? 'Passed' : playText(turn));
        UI.Snack.show(announce(player, text), corrections ? {action: 'Undo', onAction: undo} : {});
    }

    function commitChange(player, id, entered) {
        var turn = find(player, id);
        var before = copy(turn);

        Object.assign(turn, entered);
        last = {type: 'change', player: player, id: id, before: before};
        returnFocus = id;

        enqueue({type: 'change', player: player, turn: copy(turn)});
        render();

        UI.Snack.show('Changed ' + (owner ? nameOf(player) + '’s' : 'your') + ' turn to ' + playText(turn), corrections ? {action: 'Undo', onAction: undo} : {});
    }

    function commitRemove(player, id) {
        var turn = find(player, id);

        turn.removed = true;
        last = {type: 'remove', player: player, id: id};

        enqueue({type: 'remove', player: player, id: id});
        render();

        UI.Snack.show('Removed ' + (owner ? nameOf(player) + '’s ' : 'your ') + playText(turn), {action: 'Undo', onAction: undo});
    }

    function undo() {
        if (!last) { return; }

        var undoing = last;
        var turn = find(undoing.player, undoing.id);
        last = null;

        if (!turn) { return; }

        if (undoing.type === 'add') {
            turn.removed = true;
            enqueue({type: 'remove', player: undoing.player, id: undoing.id});
        } else if (undoing.type === 'remove') {
            turn.removed = false;
            returnFocus = undoing.id;
            enqueue({type: 'restore', player: undoing.player, id: undoing.id});
        } else {
            Object.assign(turn, fields(undoing.before));
            returnFocus = undoing.id;
            enqueue({type: 'change', player: undoing.player, turn: copy(turn)});
        }

        selected = owner ? undoing.player : selected;
        render();
        remember();
    }

    // ---- The entry sheet ---------------------------------------------------------------------------------------------

    function withTones(list) {
        return list.map(function (player) { return {id: player.id, name: player.name, tone: toneOf(player.id)}; });
    }

    function describeAdd(player) {
        var turns = numbers(player).turns;

        return owner ? nameOf(player) + ' · turn ' + (turns + 1) : 'Turn ' + (turns + 1);
    }

    function openAdd(player) {
        TurnEntry.open({
            title: 'Add a turn',
            limits: LIMITS,
            players: owner ? withTones(players) : null,
            player: player,
            describe: describeAdd,
            subtitle: describeAdd(player),
            onSubmit: function (entered, who) { commitAdd(who, entered); }
        });
    }

    function openEdit(id) {
        var turn = find(selected, id);
        var player = selected;

        // Another screen may have taken it off the sheet since this one was drawn
        if (!turn) { return; }

        if (unsaved[key(player, id)] !== undefined) {
            // A turn that is on the screen but has not reached the server
            UI.Sheet.open({
                title: 'Not saved yet',
                subtitle: 'This turn is on your screen but we couldn’t save it.',
                body: '<p class="flex items-start gap-2 rounded-xl bg-amber-50 px-3.5 py-3 text-sm text-amber-900">' + UI.icon('alert', 'mt-0.5 h-4 w-4 shrink-0') +
                    (gone ? 'This game has been finished or deleted, ask whoever is running it.' : (sessionEnded ? 'You have been signed out. Reload the page and score it again.' : 'Check your connection. Nothing is lost, it stays here until it has been saved.')) + '</p>' +
                    (gone ? '' : '<div class="mt-4"><button type="button" data-retry data-autofocus class="btn btn-primary btn-block h-14 text-base font-extrabold">' + (sessionEnded ? 'Reload the page' : 'Try saving again') + '</button></div>')
            });
            return;
        }

        TurnEntry.open({
            title: 'Change turn',
            subtitle: (owner ? nameOf(player) + ' · ' : '') + playText(turn),
            limits: LIMITS,
            player: player,
            turn: copy(turn),
            onSubmit: function (entered) { commitChange(player, id, entered); },
            onRemove: corrections ? function () { commitRemove(player, id); } : null
        });
    }

    // ---- Events ------------------------------------------------------------------------------------------------------

    function select(id) {
        selected = id;
        render();
        remember();
    }

    // The address is the player whose turns are shown, so a reload comes back to the same place
    function remember() {
        if (!owner || !window.history.replaceState || !config.urls.screen) { return; }

        window.history.replaceState(null, '', config.urls.screen.replace('__player__', encodeURIComponent(selected)));
    }

    document.addEventListener('click', function (event) {
        var t = event.target, element;

        if (t.closest('#add-turn')) { openAdd(owner ? selected : players[0].id); return; }

        if ((element = t.closest('[data-turn]'))) { openEdit(element.dataset.turn); return; }

        if ((element = t.closest('[data-select]'))) { select(element.dataset.select); return; }

        if (t.closest('[data-retry]')) {
            UI.Sheet.close();
            if (sessionEnded) { window.location.reload(); } else { retryAll(); }
            return;
        }

        if (t.closest('#status, #banner-retry') && unsavedCount() > 0 && !gone) { retryAll(); return; }

        if ((element = t.closest('#help-toggle'))) {
            var help = $('help'), open = help.hidden;
            help.hidden = !open;
            element.setAttribute('aria-expanded', open);
        }
    });

    // Leaving with turns that are not saved asks first
    window.addEventListener('beforeunload', function (event) {
        if (unsavedCount() > 0 || queue.length > 0 || inFlight !== null) {
            event.preventDefault();
            event.returnValue = '';
        }
    });

    // ---- Everyone ----------------------------------------------------------------------------------------------------

    function readEveryone() {
        if (document.hidden || readOnly) { return; }

        fetch(config.urls.players, {credentials: 'same-origin', headers: {'Accept': 'application/json'}})
            .then(function (response) { return response.ok ? response.json() : null; })
            .then(function (data) {
                if (!data || !Array.isArray(data.players)) { return; }

                everyone = data.players;

                // The signed-in page has every player's turns, other people may be scoring on their own phones
                data.players.forEach(function (person) { if (person.sheet) { catchUp(person.id, person.sheet); } });
                render();
            })
            .catch(function () { /* the screen keeps what it has, the next read tries again */ });
    }

    render();

    // A tile on the home page opens the game ready for that player's turn
    if (!readOnly && /(^|[?&])add(=|&|$)/.test(window.location.search)) {
        openAdd(owner ? selected : players[0].id);
    }
    remember();

    readEveryone();
    window.setInterval(readEveryone, 10000);
    document.addEventListener('visibilitychange', readEveryone);
})();
