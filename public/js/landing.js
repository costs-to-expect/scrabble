/*
 * The landing page: a score sheet to try (nothing is sent anywhere, it only lives on this page) and the walkthrough that
 * swaps the phone as the steps scroll past. The entry sheet is the real one (turn-entry.js), the limits are the server's.
 */
(function () {
    'use strict';

    var UI = window.UI;

    // ---- Try the score sheet -----------------------------------------------------------------------------------------

    function demo(root) {
        var limits = JSON.parse(root.dataset.limits);
        var sample = [
            {kind: 'word', word: 'QUIZ', score: 52, bingo: false, note: ''},
            {kind: 'word', word: 'FAX', score: 33, bingo: false, note: ''}
        ];

        var turns = sample.map(copy);
        var previous = null;
        var SHOWN = 5;

        function copy(turn) { return Object.assign({}, turn); }
        function part(name) { return root.querySelector('[data-demo="' + name + '"]'); }

        function points(turn) {
            if (turn.kind === 'pass') { return 0; }
            return turn.kind === 'adjust' ? turn.score : turn.score + (turn.bingo ? limits.bingo : 0);
        }

        function words() { return turns.filter(function (turn) { return turn.kind === 'word'; }); }
        function total() { return turns.reduce(function (sum, turn) { return sum + points(turn); }, 0); }
        function played() { return turns.filter(function (turn) { return turn.kind !== 'adjust'; }).length; }

        function rowHtml(turn, number) {
            var title, hint;

            if (turn.kind === 'pass') {
                title = '<span class="block font-bold text-stone-700">Passed</span>';
                hint = 'Or swapped tiles';
            } else if (turn.kind === 'adjust') {
                title = '<span class="block font-bold">' + UI.escape(turn.note !== '' ? turn.note : 'Adjustment') + '</span>';
                hint = turn.score < 0 ? 'Taken off the score' : 'Added to the score';
            } else {
                title = '<span class="block font-bold ' + (turn.word !== '' ? 'uppercase tracking-wide' : 'text-stone-700') + '">' + UI.escape(turn.word !== '' ? turn.word : 'A word') + '</span>';
                hint = turn.bingo ? turn.score + ' + ' + limits.bingo : turn.score + (turn.score === 1 ? ' point' : ' points');
            }

            return '<li><div class="flex min-h-16 w-full items-center gap-3.5 px-4 py-2.5">' +
                '<span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-xs font-extrabold text-brand-800 ring-1 ring-brand-100" aria-hidden="true">' + (turn.kind === 'adjust' ? '&plusmn;' : number) + '</span>' +
                '<span class="min-w-0 flex-1">' + title + '<span class="block truncate text-xs text-stone-600">' + hint + '</span></span>' +
                '<span class="flex shrink-0 items-center gap-2">' +
                (turn.bingo ? '<span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-bold text-amber-900">' + UI.icon('star', 'h-3 w-3') + 'Bingo</span>' : '') +
                '<span class="w-14 text-right text-xl font-extrabold tabular-nums ' + (turn.kind === 'pass' ? 'text-stone-400' : '') + '">' + (turn.kind === 'pass' ? '0' : UI.signed(points(turn))) + '</span></span></div></li>';
        }

        function tip() {
            var latest = turns[turns.length - 1];

            if (turns.length > sample.length && latest.bingo) { return 'Bingo! That is 50 extra points. In a real game every word, every bingo and every player is kept.'; }
            if (turns.length >= sample.length + 4) { return 'That is the idea. A real game has your players, a screen each if they want one, and your stats.'; }

            var best = words().reduce(function (best, turn) { return best === null || points(turn) > points(best) ? turn : best; }, null);

            return turns.length === sample.length
                ? 'Tap Add a turn, type a word and what it scored. Played all seven tiles? Try the bingo. Or switch on Tile by tile and let it do the adding.'
                : 'Highest word so far: ' + (best.word !== '' ? best.word + ', ' : '') + points(best) + ' points. Can you beat it?';
        }

        function render() {
            var number = 0;
            var rows = turns.map(function (turn) {
                if (turn.kind !== 'adjust') { number++; }
                return rowHtml(turn, number);
            });
            var scored = words();
            var sum = total();
            var worth = scored.map(points);

            part('list').innerHTML = rows.slice(-SHOWN).reverse().join('');

            part('total').textContent = sum;
            if (previous !== null && previous !== sum && !UI.reducedMotion() && part('total').animate) {
                part('total').animate([{transform: 'scale(1.16)'}, {transform: 'scale(1)'}], {duration: 260, easing: 'ease-out'});
            }
            previous = sum;

            part('turns').textContent = '· ' + played() + (played() === 1 ? ' turn' : ' turns');
            part('best').textContent = worth.length ? Math.max.apply(null, worth) : '–';
            part('average').textContent = worth.length ? String(Math.round(worth.reduce(function (a, b) { return a + b; }, 0) / worth.length * 10) / 10) : '–';
            part('bingos').textContent = scored.filter(function (turn) { return turn.bingo; }).length;
            part('tip-text').textContent = tip();
        }

        function add() {
            TurnEntry.open({
                title: 'Add a turn',
                subtitle: 'Turn ' + (played() + 1),
                limits: limits,
                onSubmit: function (turn) {
                    turns.push(turn);
                    render();
                    if (turn.bingo) { UI.confetti(); }
                    part('add').focus({preventScroll: true});
                }
            });
        }

        root.addEventListener('click', function (event) {
            if (event.target.closest('[data-demo="add"]')) { add(); return; }

            if (event.target.closest('[data-demo="reset"]')) {
                turns = sample.map(copy);
                previous = null;
                render();
                part('add').focus({preventScroll: true});
            }
        });

        render();
    }

    // ---- The walkthrough: the phone shows the step that is in the middle of the screen --------------------------------

    function walkthrough(root) {
        var steps = Array.prototype.slice.call(root.querySelectorAll('[data-step]'));
        var shots = Array.prototype.slice.call(root.querySelectorAll('[data-shot]'));
        var current = -1;

        function activate(index) {
            if (index === current) { return; }
            current = index;

            steps.forEach(function (step, position) {
                if (position === index) { step.setAttribute('data-active', ''); } else { step.removeAttribute('data-active'); }
            });
            shots.forEach(function (shot, position) {
                if (position === index) { shot.setAttribute('data-active', ''); shot.removeAttribute('aria-hidden'); } else { shot.removeAttribute('data-active'); shot.setAttribute('aria-hidden', 'true'); }
            });
        }

        // From here the stylesheet swaps the pictures under each step for the one phone beside them
        root.setAttribute('data-js', '');
        activate(0);

        if (!('IntersectionObserver' in window)) { return; }

        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) { activate(steps.indexOf(entry.target)); }
            });
        }, {rootMargin: '-45% 0px -45% 0px'});

        steps.forEach(function (step) { observer.observe(step); });
    }

    var tryIt = document.getElementById('demo');
    if (tryIt) { demo(tryIt); }

    var walk = document.getElementById('walk');
    if (walk) { walkthrough(walk); }
})();
