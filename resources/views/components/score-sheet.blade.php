@props(['config', 'public' => false, 'gameId' => null, 'standings' => [], 'shareTokens' => []])
@php
    // The first paint, the script draws the rest from the same data and keeps it up to date
    $owner = $config['owner'];
    $complete = $config['complete'];
    $focus = collect($config['players'])->firstWhere('id', $config['focus']);
    $name = $focus['name'];
    $tone = $config['tones']->{$focus['id']} ?? 0;
    $first = \App\Support\ScoreRules::totals($config['sheets']->{$focus['id']});
    $board = ['id' => $gameId];
@endphp

{{-- The score sheet has its own bar, the totals have to stay in view while scoring --}}
<nav class="border-b border-stone-200 bg-white" aria-label="Score sheet">
    <div class="mx-auto flex h-14 max-w-5xl items-center justify-between gap-2 px-2 sm:px-4">
        @if ($owner)
            <a href="{{ $config['urls']['back'] }}" class="-ml-1 inline-flex min-h-11 items-center gap-1 rounded-xl pl-1 pr-3 text-sm font-bold text-stone-700 hover:bg-stone-100 focus-visible:outline-2 focus-visible:outline-brand-600"><x-icon name="chevron-left" class="h-5 w-5" />{{ $complete ? 'Game' : 'Home' }}</a>
        @else
            <x-brand :href="route('landing')" class="-ml-1 pl-1" />
        @endif
        <div class="flex min-w-0 items-center gap-2.5">
            <span id="nav-avatar"><x-avatar :name="$name" :index="$tone" class="h-8 w-8 text-sm" /></span>
            <h1 id="nav-name" class="truncate text-base font-extrabold tracking-tight">Player: {{ $name }}</h1>
        </div>
        <div class="flex items-center">
            @if ($owner && ! $complete)
                <details data-menu class="relative">
                    <summary class="flex h-11 w-11 cursor-pointer list-none items-center justify-center rounded-full text-stone-600 hover:bg-stone-100 hover:text-stone-900 focus-visible:outline-2 focus-visible:outline-brand-600 [&::-webkit-details-marker]:hidden" aria-label="More game options"><x-icon name="more" class="h-6 w-6" /></summary>
                    <div class="absolute right-0 top-full z-30 mt-1 w-56 rounded-2xl bg-white p-1.5 shadow-lift ring-1 ring-stone-200">
                        <button type="button" data-dialog-open="share-dialog" class="flex min-h-11 w-full items-center gap-2.5 rounded-xl px-3 text-left font-semibold text-stone-800 hover:bg-stone-100"><x-icon name="share" class="h-5 w-5" />Share links</button>
                        @if (count($standings) < config('app.game.max_players'))
                            <a href="{{ route('game.add-players.view', ['game_id' => $gameId]) }}" class="flex min-h-11 w-full items-center gap-2.5 rounded-xl px-3 text-left font-semibold text-stone-800 hover:bg-stone-100"><x-icon name="plus" class="h-5 w-5" />Add player</a>
                        @endif
                        <button type="button" data-dialog-open="remove-dialog" class="flex min-h-11 w-full items-center gap-2.5 rounded-xl px-3 text-left font-semibold text-stone-800 hover:bg-stone-100"><x-icon name="user-minus" class="h-5 w-5" />Remove a player&hellip;</button>
                        <button type="button" data-dialog-open="delete-dialog" class="flex min-h-11 w-full items-center gap-2.5 rounded-xl px-3 text-left font-semibold text-red-700 hover:bg-red-50"><x-icon name="trash" class="h-5 w-5" />Delete game&hellip;</button>
                    </div>
                </details>
            @endif
            <button type="button" id="help-toggle" aria-expanded="false" aria-controls="help" class="rounded-full p-2.5 text-stone-600 hover:bg-stone-100 hover:text-stone-900 focus-visible:outline-2 focus-visible:outline-brand-600" aria-label="How to score"><x-icon name="help" class="h-6 w-6" /></button>
        </div>
    </div>
</nav>

<div id="help" hidden class="border-b border-brand-100 bg-brand-50">
    <div class="mx-auto max-w-5xl px-4 py-3.5 text-sm text-stone-800">
        <p class="font-extrabold text-brand-900">How to score</p>
        <p class="mt-1 max-w-2xl">Tap <strong>Add a turn</strong>, type the word if you like, enter what it scored and tap add. Played all seven tiles? Tap <strong>Bingo</strong> for the 50 point bonus. No word this turn, or swapped some tiles? Choose <strong>Pass</strong>. At the end of the game use <strong>Adjust</strong> for the tiles left on each rack.</p>
        @if ($config['corrections'])
            <p class="mt-1.5 max-w-2xl">Made a mistake? Tap <strong>Undo</strong> straight after, or tap the turn later to change it or take it off.@if ($owner) Everyone is on this screen, tap a player to see their turns.@endif</p>
        @else
            <p class="mt-1.5 max-w-2xl">A turn is saved as soon as you add it and can&rsquo;t be changed, so check it before you do.</p>
        @endif
    </div>
</div>

{{-- Totals stay in view while you score, so you always see what a turn did --}}
<header class="sticky top-0 z-20 border-b border-stone-200 bg-white/95 backdrop-blur">
    <div class="mx-auto flex max-w-5xl items-end gap-3 px-4 pb-2.5 pt-3 sm:gap-6">
        <div class="min-w-0">
            <p class="text-[11px] font-bold uppercase tracking-wider text-stone-600">Total <span id="turn-label" class="font-semibold normal-case tracking-normal">&middot; {{ $first['turns'] }} {{ $first['turns'] === 1 ? 'turn' : 'turns' }}</span></p>
            <p id="total" class="mt-0.5 inline-block origin-left text-4xl font-extrabold leading-none tabular-nums" aria-live="polite">{{ $first['total'] }}</p>
        </div>
        <dl class="ml-auto flex items-end gap-3.5 text-right sm:gap-6">
            <div><dt class="text-[10px] font-bold uppercase tracking-wider text-stone-600">Best</dt><dd id="head-best" class="text-base font-bold tabular-nums">{{ $first['best'] ?? '–' }}</dd></div>
            <div><dt class="text-[10px] font-bold uppercase tracking-wider text-stone-600">Average</dt><dd id="head-average" class="text-base font-bold tabular-nums">–</dd></div>
            <div><dt class="text-[10px] font-bold uppercase tracking-wider text-stone-600">Bingos</dt><dd id="head-bingos" class="text-base font-bold tabular-nums">{{ $first['bingos'] }}</dd></div>
        </dl>
        <button type="button" id="status" class="-mr-1 inline-flex h-11 min-w-11 shrink-0 items-center justify-center gap-1.5 rounded-full px-2.5 text-xs font-bold text-emerald-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600" aria-live="polite">
            <x-icon name="check-circle" class="h-5 w-5" /><span class="sr-only sm:not-sr-only">Saved</span>
        </button>
    </div>
    @if ($owner)
        {{-- Everyone, in the order they play in: tap a player to see their turns. From lg up the Everyone panel does this. --}}
        <div id="strip" class="mx-auto max-w-5xl px-3 pb-2.5 lg:hidden" role="group" aria-label="Players"></div>
    @endif
    <div id="banner" hidden class="border-t border-amber-200 bg-amber-50">
        <div class="mx-auto flex max-w-5xl items-center gap-3 px-4 py-2 text-sm text-amber-900">
            <x-icon name="alert" class="h-5 w-5 shrink-0" />
            <p id="banner-text" class="flex-1 font-semibold"></p>
            <button type="button" id="banner-retry" class="min-h-10 rounded-lg bg-amber-100 px-4 font-bold hover:bg-amber-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-700">Retry</button>
        </div>
    </div>
</header>

<div class="mx-auto max-w-5xl px-4 pb-20 pt-5 lg:grid lg:grid-cols-[minmax(0,1fr)_20rem] lg:items-start lg:gap-8">

    <main class="space-y-6">
        <noscript>
            <x-alert type="warning" title="JavaScript is needed to score">The score sheet draws itself and saves each turn as you add it, switch JavaScript on and reload.</x-alert>
        </noscript>

        @if ($complete)
            <x-alert type="info" title="This game is finished">The scores are locked. Open the game to see how everyone did.</x-alert>
        @else
            <div>
                <button type="button" id="add-turn" class="btn btn-primary btn-block h-16 text-base font-extrabold"><x-icon name="plus" class="h-6 w-6" /><span id="add-label">Add a turn</span></button>
                <p id="add-hint" class="mt-2 text-center text-sm text-stone-600"></p>
            </div>
        @endif

        <section aria-labelledby="stats-heading">
            <h2 id="stats-heading" class="sr-only">Words so far</h2>
            <dl id="player-stats" class="grid grid-cols-4 gap-2 sm:gap-3"></dl>
        </section>

        <section aria-labelledby="turns-heading">
            <div class="flex items-baseline justify-between gap-3">
                <h2 id="turns-heading" class="text-lg font-extrabold tracking-tight">Turns</h2>
                <p id="turns-count" class="text-xs text-stone-600"></p>
            </div>
            <ul id="turn-list" class="card-list mt-3"></ul>
            <div id="turn-empty" hidden class="mt-3 rounded-2xl bg-white px-4 py-6 text-center text-sm text-stone-600 shadow-card ring-1 ring-stone-200/70">
                <p class="font-bold text-stone-800">No turns yet.</p>
                <p id="turn-empty-text" class="mt-1"></p>
            </div>
        </section>

        @if ($owner && ! $complete)
            <section class="card p-5 sm:p-5" aria-labelledby="finish-heading">
                <h2 id="finish-heading" class="text-lg font-extrabold tracking-tight">Finished playing?</h2>
                <p class="mt-0.5 text-sm text-stone-600">A game of {{ config('app.game.name') }} is over when the bag is empty and someone has played out. Add the tiles left as an adjustment for each player, then finish the game.</p>
                <button type="button" data-dialog-open="finish-dialog" class="btn btn-secondary mt-4 w-full sm:w-auto">Finish game&hellip;</button>
            </section>
        @endif
    </main>

    <aside @class(['mt-8 lg:sticky lg:top-36 lg:mt-0', 'hidden lg:block' => $owner]) aria-labelledby="everyone-heading">
        <div class="flex items-baseline justify-between">
            <h2 id="everyone-heading" class="text-lg font-extrabold tracking-tight">Everyone</h2>
            @unless ($complete)
                <p class="inline-flex items-center gap-1.5 text-xs font-semibold text-stone-600"><span class="relative flex h-2 w-2" aria-hidden="true"><span class="absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75 motion-safe:animate-ping"></span><span class="relative inline-flex h-2 w-2 rounded-full bg-emerald-500"></span></span>Live</p>
            @endunless
        </div>
        <ul id="everyone" class="card-list mt-3" aria-live="off"></ul>
        <p class="mt-2.5 px-1 text-xs text-stone-600">{{ $complete ? 'The scores as they were when the game was finished.' : 'Scores update by themselves, you never need to refresh.' }}</p>
    </aside>
</div>

<x-footer />

@if ($owner && ! $complete)
    <x-game-dialogs :board="$board" :standings="$standings" :share-tokens="$shareTokens" :live="true" />
@endif

<script type="application/json" id="sheet-config">@json($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)</script>
@push('scripts')
    <script src="{{ asset('js/tiles.js') }}?v={{ config('app.version.js') }}" defer></script>
    <script src="{{ asset('js/turn-entry.js') }}?v={{ config('app.version.js') }}" defer></script>
    <script src="{{ asset('js/score-sheet.js') }}?v={{ config('app.version.js') }}" defer></script>
@endpush
