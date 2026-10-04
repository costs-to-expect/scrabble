@php
    use App\Support\GameBoard;

    $config = config('app.game');
    $complete = $game['complete'] === 1;
    $board = ['id' => $game['id']];
    $scores = GameBoard::ranked($game['game']['scores'] ?? []);
    $result = GameBoard::result($game);
    $ranked = GameBoard::ranked($standings);
    $leader = $ranked[0] ?? null;
    $played = GameBoard::playedOn(GameBoard::when($started));

    // The numbers kept with a finished game, a game finished before they were kept has none of them
    $has_stats = $complete && ($stats['words'] ?? 0) > 0;
    $word = fn (?array $play): string => $play === null ? '' : ($play['word'] !== '' ? $play['word'] : 'Word not entered');
@endphp
<x-layouts.app :title="$config['name'].' Game Scorer: Game'" :active="$complete ? 'games' : 'home'">
    <div class="mx-auto max-w-3xl">
        <a href="{{ $complete ? route('games') : route('home') }}" class="-ml-3 inline-flex min-h-11 items-center gap-1 rounded-xl pl-2 pr-3 text-sm font-bold text-stone-700 hover:bg-stone-100 focus-visible:outline-2 focus-visible:outline-brand-600"><x-icon name="chevron-left" class="h-5 w-5" />{{ $complete ? 'All games' : 'Home' }}</a>

        <h1 class="mt-2 text-3xl font-extrabold tracking-tight sm:text-4xl">Game overview</h1>
        <p class="mt-1 text-stone-600">
            @if ($complete)
                @if ($result)<strong class="text-stone-900">{{ $result['winner'] }}</strong> {{ $result['tied'] ? 'tied on' : 'won with' }} {{ $result['score'] }}.@endif
                @if ($played) Played {{ $played }}.@endif
            @else
                Tap a player to add their turn. Each player has a link to share, they can add their own words on their own phone.
            @endif
        </p>

        @if (! $complete)
            <div class="card mt-6">
                <x-player-tiles :standings="$standings" :game-id="$game['id']" />
                <x-game-actions :game-id="$game['id']" :full="count($standings) >= $config['max_players']" />
            </div>
            <x-game-dialogs :board="$board" :standings="$standings" :share-tokens="$share_tokens" />
        @else
            <ul class="card-list mt-6">
                @foreach ($scores as $__score)
                    <li class="flex items-center gap-3 px-4 py-3">
                        <span class="w-5 text-center text-sm font-bold text-stone-500">{{ $loop->iteration }}</span>
                        <x-avatar :name="$__score['player_name']" :index="$tones[$__score['player_id']] ?? 0" class="h-10 w-10 text-base" />
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-bold">{{ $__score['player_name'] }}</span>
                            @if ($result && $__score['score'] === $result['score'])
                                <span class="mt-0.5 inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-bold text-amber-800"><x-icon name="trophy" class="h-3.5 w-3.5" />{{ $result['tied'] ? 'Joint winner' : 'Winner' }}</span>
                            @endif
                        </span>
                        <span class="text-2xl font-extrabold tabular-nums">{{ $__score['score'] }}<span class="sr-only"> points</span></span>
                        <a href="{{ route('game.score-sheet', ['game_id' => $game['id'], 'player_id' => $__score['player_id']]) }}" class="inline-flex min-h-11 items-center rounded-xl px-3 text-sm font-bold text-brand-700 hover:bg-brand-50 focus-visible:outline-2 focus-visible:outline-brand-600">Turns<span class="sr-only"> for {{ $__score['player_name'] }}</span></a>
                    </li>
                @endforeach
            </ul>

            @if ($has_stats)
                {{-- ============ The game in numbers ============ --}}
                <section class="mt-10" aria-labelledby="highlights-heading">
                    <h2 id="highlights-heading" class="text-lg font-extrabold tracking-tight">Highlights</h2>

                    <dl class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3">
                        @if ($stats['best_word'])
                            <div class="rounded-2xl bg-white p-4 shadow-card ring-1 ring-stone-200/70">
                                <dt class="text-[11px] font-bold uppercase tracking-wider text-stone-600">Highest word</dt>
                                <dd class="mt-1 text-3xl font-extrabold leading-none tabular-nums">{{ $stats['best_word']['score'] }}</dd>
                                <dd class="mt-1.5 flex items-center gap-1 text-xs text-stone-600">@if ($stats['best_word']['bingo'])<x-icon name="star" class="h-3 w-3 shrink-0 text-amber-500" /><span class="sr-only">Bingo </span>@endif<span class="truncate"><strong class="font-bold uppercase tracking-wide text-stone-800">{{ $word($stats['best_word']) }}</strong> &middot; {{ $stats['best_word']['player'] }}</span></dd>
                            </div>
                        @endif
                        @if ($stats['lowest_word'])
                            <div class="rounded-2xl bg-white p-4 shadow-card ring-1 ring-stone-200/70">
                                <dt class="text-[11px] font-bold uppercase tracking-wider text-stone-600">Lowest word</dt>
                                <dd class="mt-1 text-3xl font-extrabold leading-none tabular-nums">{{ $stats['lowest_word']['score'] }}</dd>
                                <dd class="mt-1.5 truncate text-xs text-stone-600"><strong class="font-bold uppercase tracking-wide text-stone-800">{{ $word($stats['lowest_word']) }}</strong> &middot; {{ $stats['lowest_word']['player'] }}</dd>
                            </div>
                        @endif
                        @if ($stats['longest_word'])
                            <div class="rounded-2xl bg-white p-4 shadow-card ring-1 ring-stone-200/70">
                                <dt class="text-[11px] font-bold uppercase tracking-wider text-stone-600">Longest word</dt>
                                <dd class="mt-1 text-3xl font-extrabold leading-none tabular-nums">{{ mb_strlen($stats['longest_word']['word']) }}<span class="ml-1 text-base font-bold text-stone-600">letters</span></dd>
                                <dd class="mt-1.5 truncate text-xs text-stone-600"><strong class="font-bold uppercase tracking-wide text-stone-800">{{ $stats['longest_word']['word'] }}</strong> &middot; {{ $stats['longest_word']['player'] }}</dd>
                            </div>
                        @endif
                        <div class="rounded-2xl bg-white p-4 shadow-card ring-1 ring-stone-200/70">
                            <dt class="text-[11px] font-bold uppercase tracking-wider text-stone-600">Average word</dt>
                            <dd class="mt-1 text-3xl font-extrabold leading-none tabular-nums">{{ number_format($stats['average_word'], 1) }}</dd>
                            <dd class="mt-1.5 truncate text-xs text-stone-600">{{ $stats['words'] }} {{ $stats['words'] === 1 ? 'word' : 'words' }} in {{ $stats['turns'] }} {{ $stats['turns'] === 1 ? 'turn' : 'turns' }}</dd>
                        </div>
                        <div class="rounded-2xl bg-white p-4 shadow-card ring-1 ring-stone-200/70">
                            <dt class="text-[11px] font-bold uppercase tracking-wider text-stone-600">Bingos</dt>
                            <dd class="mt-1 text-3xl font-extrabold leading-none tabular-nums">{{ $stats['bingos'] }}</dd>
                            <dd class="mt-1.5 truncate text-xs text-stone-600">@if ($stats['most_bingos']){{ $stats['most_bingos']['player'] }} played {{ $stats['most_bingos']['count'] }}@else All seven tiles, not this time @endif</dd>
                        </div>
                        @if ($stats['biggest_win'])
                            <div class="rounded-2xl bg-white p-4 shadow-card ring-1 ring-stone-200/70">
                                <dt class="text-[11px] font-bold uppercase tracking-wider text-stone-600">{{ $stats['biggest_win']['margin'] === 0 ? 'Result' : 'Winning margin' }}</dt>
                                <dd class="mt-1 text-3xl font-extrabold leading-none tabular-nums">{{ $stats['biggest_win']['margin'] === 0 ? 'Tied' : $stats['biggest_win']['margin'] }}</dd>
                                <dd class="mt-1.5 truncate text-xs text-stone-600">{{ $stats['biggest_win']['margin'] === 0 ? $stats['biggest_win']['winner'] : 'ahead of '.$stats['biggest_win']['runner_up'] }}</dd>
                            </div>
                        @endif
                    </dl>
                </section>

                <section class="mt-10" aria-labelledby="players-heading">
                    <h2 id="players-heading" class="text-lg font-extrabold tracking-tight">Player by player</h2>

                    <ul class="mt-3 grid gap-3 sm:grid-cols-2">
                        @foreach ($scores as $__score)
                            @php($mine_words = $__score['words'] ?? 0)
                            <li class="card p-4 sm:p-5">
                                <div class="flex items-center gap-3">
                                    <x-avatar :name="$__score['player_name']" :index="$tones[$__score['player_id']] ?? 0" class="h-10 w-10 text-base" />
                                    <span class="min-w-0 flex-1 truncate font-extrabold">{{ $__score['player_name'] }}</span>
                                    <span class="text-2xl font-extrabold tabular-nums">{{ $__score['score'] }}</span>
                                </div>
                                <dl class="mt-4 grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                                    <div><dt class="text-[11px] font-bold uppercase tracking-wider text-stone-600">Best word</dt><dd class="mt-0.5 font-bold">@if ($__score['best'] ?? null){{ $__score['best']['score'] }} <span class="font-semibold uppercase tracking-wide text-stone-600">{{ $word($__score['best']) }}</span>@if ($__score['best']['bingo']) <x-icon name="star" class="inline h-3 w-3 text-amber-500" /><span class="sr-only">Bingo</span>@endif @else &ndash; @endif</dd></div>
                                    <div><dt class="text-[11px] font-bold uppercase tracking-wider text-stone-600">Lowest word</dt><dd class="mt-0.5 font-bold">@if ($__score['lowest'] ?? null){{ $__score['lowest']['score'] }} <span class="font-semibold uppercase tracking-wide text-stone-600">{{ $word($__score['lowest']) }}</span>@else &ndash; @endif</dd></div>
                                    <div><dt class="text-[11px] font-bold uppercase tracking-wider text-stone-600">Average word</dt><dd class="mt-0.5 font-bold tabular-nums">{{ $mine_words > 0 ? number_format($__score['word_points'] / $mine_words, 1) : '–' }}</dd></div>
                                    <div><dt class="text-[11px] font-bold uppercase tracking-wider text-stone-600">Longest word</dt><dd class="mt-0.5 font-bold">@if (($__score['longest'] ?? null) && $__score['longest']['word'] !== ''){{ mb_strlen($__score['longest']['word']) }} <span class="font-semibold uppercase tracking-wide text-stone-600">{{ $__score['longest']['word'] }}</span>@else &ndash; @endif</dd></div>
                                    <div><dt class="text-[11px] font-bold uppercase tracking-wider text-stone-600">Turns</dt><dd class="mt-0.5 font-bold tabular-nums">{{ $__score['turns'] ?? 0 }}<span class="font-semibold text-stone-600"> &middot; {{ $mine_words }} {{ $mine_words === 1 ? 'word' : 'words' }}@if (($__score['passes'] ?? 0) > 0), {{ $__score['passes'] }} {{ $__score['passes'] === 1 ? 'pass' : 'passes' }}@endif</span></dd></div>
                                    <div><dt class="text-[11px] font-bold uppercase tracking-wider text-stone-600">Bingos</dt><dd class="mt-0.5 font-bold tabular-nums">{{ $__score['bingos'] ?? 0 }}</dd></div>
                                </dl>
                                @if (($__score['adjustment'] ?? 0) !== 0)
                                    <p class="mt-3 border-t border-stone-100 pt-3 text-sm text-stone-600">Adjusted at the end of the game <strong class="font-bold tabular-nums text-stone-900">{{ \App\Support\ScoreRules::signed($__score['adjustment'], true) }}</strong></p>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @else
                <p class="mt-4 text-sm text-stone-600">This game was finished before the numbers were kept, so there are no word stats for it.</p>
            @endif
        @endif
    </div>
</x-layouts.app>
