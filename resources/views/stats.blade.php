@php
    use App\Support\GameBoard;

    $games = $stats['games'];
    $word = fn (?array $play): string => $play === null ? '' : ($play['word'] !== '' ? $play['word'] : 'Word not entered');
    $on = fn (array $record): string => GameBoard::when($record['started']) ?? 'A game';
@endphp
<x-layouts.app :title="config('app.game.name').' Game Scorer: Stats'" active="stats">
    <div class="mx-auto max-w-3xl">
        <h1 class="text-3xl font-extrabold tracking-tight sm:text-4xl">Stats</h1>
        <p class="mt-1 text-stone-600">
            @if ($games > 0)
                Across {{ $games }} finished {{ $games === 1 ? 'game' : 'games' }}@if ($capped), the most recent ones @endif.
            @else
                The words, the records and who is winning, once you have finished a game.
            @endif
        </p>

        @if ($games === 0)
            <div class="card mt-6 text-center">
                <div class="flex justify-center"><x-art /></div>
                <h2 class="mt-4 text-xl font-extrabold tracking-tight">No stats yet.</h2>
                <p class="mt-1 text-stone-600">Finish a game and your highest word, your lowest word, the longest word and every bingo are counted here.</p>
                <a href="{{ route('home') }}" class="btn btn-primary mt-5">Back to the game</a>
            </div>
        @else
            <dl class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div class="rounded-2xl bg-white p-4 shadow-card ring-1 ring-stone-200/70">
                    <dt class="text-[11px] font-bold uppercase tracking-wider text-stone-600">Games</dt>
                    <dd class="mt-1 text-3xl font-extrabold leading-none tabular-nums">{{ $games }}</dd>
                </div>
                <div class="rounded-2xl bg-white p-4 shadow-card ring-1 ring-stone-200/70">
                    <dt class="text-[11px] font-bold uppercase tracking-wider text-stone-600">Words</dt>
                    <dd class="mt-1 text-3xl font-extrabold leading-none tabular-nums">{{ number_format($stats['words']) }}</dd>
                </div>
                <div class="rounded-2xl bg-white p-4 shadow-card ring-1 ring-stone-200/70">
                    <dt class="text-[11px] font-bold uppercase tracking-wider text-stone-600">Average word</dt>
                    <dd class="mt-1 text-3xl font-extrabold leading-none tabular-nums">{{ $stats['average_word'] === null ? '–' : number_format($stats['average_word'], 1) }}</dd>
                </div>
                <div class="rounded-2xl bg-white p-4 shadow-card ring-1 ring-stone-200/70">
                    <dt class="text-[11px] font-bold uppercase tracking-wider text-stone-600">Bingos</dt>
                    <dd class="mt-1 text-3xl font-extrabold leading-none tabular-nums">{{ number_format($stats['bingos']) }}</dd>
                </div>
            </dl>

            <section class="mt-10" aria-labelledby="records-heading">
                <h2 id="records-heading" class="text-lg font-extrabold tracking-tight">Records</h2>

                <ul class="mt-3 grid gap-3 sm:grid-cols-2">
                    @if ($stats['best_word'])
                        @php($record = $stats['best_word'])
                        <li><a href="{{ route('game.show', ['game_id' => $record['game']]) }}" class="card block p-4 transition hover:shadow-lift focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 sm:p-5 motion-reduce:transition-none">
                            <p class="text-[11px] font-bold uppercase tracking-wider text-stone-600">Highest scoring word</p>
                            <p class="mt-1 flex items-baseline gap-2"><span class="text-4xl font-extrabold leading-none tabular-nums">{{ $record['score'] }}</span>@if ($record['bingo'])<span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-bold text-amber-900"><x-icon name="star" class="h-3 w-3" />Bingo</span>@endif</p>
                            <p class="mt-2 truncate text-sm text-stone-600"><strong class="font-bold uppercase tracking-wide text-stone-800">{{ $word($record) }}</strong> &middot; {{ $record['player'] }} &middot; {{ $on($record) }}</p>
                        </a></li>
                    @endif
                    @if ($stats['lowest_word'])
                        @php($record = $stats['lowest_word'])
                        <li><a href="{{ route('game.show', ['game_id' => $record['game']]) }}" class="card block p-4 transition hover:shadow-lift focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 sm:p-5 motion-reduce:transition-none">
                            <p class="text-[11px] font-bold uppercase tracking-wider text-stone-600">Lowest scoring word</p>
                            <p class="mt-1"><span class="text-4xl font-extrabold leading-none tabular-nums">{{ $record['score'] }}</span></p>
                            <p class="mt-2 truncate text-sm text-stone-600"><strong class="font-bold uppercase tracking-wide text-stone-800">{{ $word($record) }}</strong> &middot; {{ $record['player'] }} &middot; {{ $on($record) }}</p>
                        </a></li>
                    @endif
                    @if ($stats['longest_word'])
                        @php($record = $stats['longest_word'])
                        <li><a href="{{ route('game.show', ['game_id' => $record['game']]) }}" class="card block p-4 transition hover:shadow-lift focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 sm:p-5 motion-reduce:transition-none">
                            <p class="text-[11px] font-bold uppercase tracking-wider text-stone-600">Longest word</p>
                            <p class="mt-1"><span class="text-4xl font-extrabold leading-none tabular-nums">{{ mb_strlen($record['word']) }}</span><span class="ml-1.5 text-base font-bold text-stone-600">letters</span></p>
                            <p class="mt-2 truncate text-sm text-stone-600"><strong class="font-bold uppercase tracking-wide text-stone-800">{{ $record['word'] }}</strong> &middot; {{ $record['player'] }} &middot; {{ $on($record) }}</p>
                        </a></li>
                    @endif
                    @if ($stats['best_game'])
                        @php($record = $stats['best_game'])
                        <li><a href="{{ route('game.show', ['game_id' => $record['game']]) }}" class="card block p-4 transition hover:shadow-lift focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 sm:p-5 motion-reduce:transition-none">
                            <p class="text-[11px] font-bold uppercase tracking-wider text-stone-600">Highest game score</p>
                            <p class="mt-1"><span class="text-4xl font-extrabold leading-none tabular-nums">{{ $record['score'] }}</span></p>
                            <p class="mt-2 truncate text-sm text-stone-600"><strong class="font-bold text-stone-800">{{ $record['player'] }}</strong> &middot; {{ $on($record) }}</p>
                        </a></li>
                    @endif
                    @if ($stats['closest_game'])
                        @php($record = $stats['closest_game'])
                        <li><a href="{{ route('game.show', ['game_id' => $record['game']]) }}" class="card block p-4 transition hover:shadow-lift focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 sm:p-5 motion-reduce:transition-none">
                            <p class="text-[11px] font-bold uppercase tracking-wider text-stone-600">Closest game</p>
                            <p class="mt-1"><span class="text-4xl font-extrabold leading-none tabular-nums">{{ $record['margin'] === 0 ? 'Tied' : $record['margin'] }}</span>@if ($record['margin'] > 0)<span class="ml-1.5 text-base font-bold text-stone-600">{{ $record['margin'] === 1 ? 'point' : 'points' }}</span>@endif</p>
                            <p class="mt-2 truncate text-sm text-stone-600">@if ($record['margin'] === 0)<strong class="font-bold text-stone-800">{{ $record['winner'] }}</strong> on {{ $record['score'] }}@else<strong class="font-bold text-stone-800">{{ $record['winner'] }}</strong> {{ $record['score'] }} to {{ $record['runner_up'] }} {{ $record['runner_up_score'] }}@endif &middot; {{ $on($record) }}</p>
                        </a></li>
                    @endif
                    @if ($stats['biggest_win'] && $stats['biggest_win']['margin'] > 0)
                        @php($record = $stats['biggest_win'])
                        <li><a href="{{ route('game.show', ['game_id' => $record['game']]) }}" class="card block p-4 transition hover:shadow-lift focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 sm:p-5 motion-reduce:transition-none">
                            <p class="text-[11px] font-bold uppercase tracking-wider text-stone-600">Biggest win</p>
                            <p class="mt-1"><span class="text-4xl font-extrabold leading-none tabular-nums">{{ $record['margin'] }}</span><span class="ml-1.5 text-base font-bold text-stone-600">points</span></p>
                            <p class="mt-2 truncate text-sm text-stone-600"><strong class="font-bold text-stone-800">{{ $record['winner'] }}</strong> {{ $record['score'] }} to {{ $record['runner_up'] }} {{ $record['runner_up_score'] }} &middot; {{ $on($record) }}</p>
                        </a></li>
                    @endif
                    @if ($stats['lowest_win'])
                        @php($record = $stats['lowest_win'])
                        <li><a href="{{ route('game.show', ['game_id' => $record['game']]) }}" class="card block p-4 transition hover:shadow-lift focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 sm:p-5 motion-reduce:transition-none">
                            <p class="text-[11px] font-bold uppercase tracking-wider text-stone-600">Lowest winning score</p>
                            <p class="mt-1"><span class="text-4xl font-extrabold leading-none tabular-nums">{{ $record['score'] }}</span></p>
                            <p class="mt-2 truncate text-sm text-stone-600"><strong class="font-bold text-stone-800">{{ $record['player'] }}</strong> &middot; {{ $on($record) }}</p>
                        </a></li>
                    @endif
                    @if ($stats['most_bingos'])
                        @php($record = $stats['most_bingos'])
                        <li><a href="{{ route('game.show', ['game_id' => $record['game']]) }}" class="card block p-4 transition hover:shadow-lift focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 sm:p-5 motion-reduce:transition-none">
                            <p class="text-[11px] font-bold uppercase tracking-wider text-stone-600">Most bingos in a game</p>
                            <p class="mt-1"><span class="text-4xl font-extrabold leading-none tabular-nums">{{ $record['count'] }}</span></p>
                            <p class="mt-2 truncate text-sm text-stone-600"><strong class="font-bold text-stone-800">{{ $record['player'] }}</strong> &middot; {{ $on($record) }}</p>
                        </a></li>
                    @endif
                </ul>
            </section>

            <section class="mt-10" aria-labelledby="players-heading">
                <h2 id="players-heading" class="text-lg font-extrabold tracking-tight">Players</h2>

                <div class="mt-3 overflow-x-auto rounded-2xl bg-white shadow-card ring-1 ring-stone-200/70 focus-visible:outline-2 focus-visible:outline-brand-600" role="region" aria-label="Player statistics" tabindex="0">
                    <table class="w-full min-w-[40rem] text-left text-sm">
                        <thead class="bg-stone-50 text-xs uppercase tracking-wider text-stone-600">
                            <tr>
                                <th scope="col" class="px-4 py-2.5 font-bold">Player</th>
                                <th scope="col" class="px-3 py-2.5 text-right font-bold">Games</th>
                                <th scope="col" class="px-3 py-2.5 text-right font-bold">Wins</th>
                                <th scope="col" class="px-3 py-2.5 text-right font-bold">Average</th>
                                <th scope="col" class="px-3 py-2.5 text-right font-bold">Best game</th>
                                <th scope="col" class="px-3 py-2.5 text-right font-bold">Best word</th>
                                <th scope="col" class="px-3 py-2.5 text-right font-bold">Average word</th>
                                <th scope="col" class="px-4 py-2.5 text-right font-bold">Bingos</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100 tabular-nums">
                            @foreach ($stats['players'] as $line)
                                <tr>
                                    <th scope="row" class="px-4 py-3 font-bold">
                                        <span class="flex items-center gap-2.5"><x-avatar :name="$line['name']" :index="$tones[$line['id']] ?? 0" class="h-8 w-8 text-sm" /><span class="truncate">{{ $line['name'] }}</span></span>
                                    </th>
                                    <td class="px-3 py-3 text-right">{{ $line['games'] }}</td>
                                    <td class="px-3 py-3 text-right">{{ $line['wins'] }}<span class="ml-1 text-xs text-stone-600">{{ round($line['win_rate'] * 100) }}%</span></td>
                                    <td class="px-3 py-3 text-right">{{ number_format($line['average'], 1) }}</td>
                                    <td class="px-3 py-3 text-right">{{ $line['best_game'] }}</td>
                                    <td class="px-3 py-3 text-right">@if ($line['best_word']){{ $line['best_word']['score'] }}<span class="ml-1 text-xs font-semibold uppercase tracking-wide text-stone-600">{{ $line['best_word']['word'] }}</span>@else &ndash; @endif</td>
                                    <td class="px-3 py-3 text-right">{{ $line['average_word'] === null ? '–' : number_format($line['average_word'], 1) }}</td>
                                    <td class="px-4 py-3 text-right">{{ $line['bingos'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="mt-2 px-1 text-xs text-stone-600">A game that ends level is a win for everyone on the top score.</p>
            </section>
        @endif
    </div>
</x-layouts.app>
