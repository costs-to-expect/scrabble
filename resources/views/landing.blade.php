@php
    $name = config('app.game.name');
    $title = $name.' Game Scorer — Online Score Sheets for Game Night';
    $description = 'Score '.$name.' online with friends and family. Words, bingos and who is winning on one screen, live stats for every game, no app to install, powered by the Costs to Expect API.';
@endphp
<x-layouts.guest :title="$title"
                 :description="$description"
                 width="max-w-5xl">
    <x-slot:head>
        <link rel="canonical" href="{{ url('/') }}">

        <meta property="og:type" content="website">
        <meta property="og:site_name" content="{{ $name }} Game Scorer">
        <meta property="og:title" content="{{ $title }}">
        <meta property="og:description" content="{{ $description }}">
        <meta property="og:url" content="{{ url('/') }}">
        <meta property="og:image" content="{{ asset('images/card.png') }}">
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:title" content="{{ $title }}">
        <meta name="twitter:description" content="{{ $description }}">
        <meta name="twitter:image" content="{{ asset('images/card.png') }}">
    </x-slot:head>

    {{-- ============ Hero: the pitch and a score sheet to try ============ --}}
    <section class="rounded-4xl bg-gradient-to-br from-brand-50 via-white to-white p-5 ring-1 ring-brand-100 sm:p-10" aria-labelledby="hero-heading">
        <div class="grid items-center gap-8 lg:grid-cols-[minmax(0,1fr)_24rem] lg:gap-12">
            <div>
                <p class="inline-flex items-center gap-2 rounded-full bg-white px-3.5 py-1.5 text-sm font-bold text-brand-800 shadow-card ring-1 ring-brand-100"><x-game-mark class="h-4 w-4" />Free, and no app to install</p>
                <h1 id="hero-heading" class="mt-5 text-4xl font-extrabold tracking-tight sm:text-5xl lg:text-6xl">{{ $name }} Game Scorer</h1>
                <p class="mt-4 max-w-xl text-lg text-stone-700">A game scorer powered by the Costs to Expect API.</p>
                <p class="mt-2 max-w-xl text-stone-600">Yep, you read that right, a game scorer, you still need to find the letters and play the words. We keep count of the words, the bingos and who is winning.</p>
                <div class="mt-7 flex flex-col gap-3 sm:flex-row">
                    <a href="{{ route('register.view') }}" class="btn btn-primary w-full sm:w-auto sm:min-w-44">Register</a>
                    <a href="{{ route('sign-in.view') }}" class="btn btn-secondary w-full sm:w-auto sm:min-w-44">Sign in</a>
                </div>
                <p class="mt-4 text-sm text-stone-600">Not sure yet? Have a go at the score sheet, it works right here.</p>
            </div>

            <section id="demo" data-limits="{{ json_encode(\App\Support\ScoreRules::limits()) }}" class="overflow-hidden rounded-3xl bg-white shadow-lift ring-1 ring-stone-200" aria-labelledby="demo-heading">
                <div class="flex items-center justify-between gap-3 border-b border-stone-100 bg-stone-50 px-5 py-2.5">
                    <h2 id="demo-heading" class="inline-flex items-center gap-2 text-sm font-extrabold"><x-icon name="sparkles" class="h-4 w-4 text-brand-700" />Try the score sheet</h2>
                    <p class="text-xs text-stone-600">Nothing is saved</p>
                </div>

                <div class="flex items-end gap-3 px-5 pb-2.5 pt-3.5">
                    <div class="min-w-0">
                        <p class="text-[11px] font-bold uppercase tracking-wider text-stone-600">Total <span data-demo="turns" class="font-semibold normal-case tracking-normal">&middot; 0 turns</span></p>
                        <p data-demo="total" class="mt-0.5 inline-block origin-left text-4xl font-extrabold leading-none tabular-nums" aria-live="polite">0</p>
                    </div>
                    <dl class="ml-auto flex items-end gap-4 text-right">
                        <div><dt class="text-[10px] font-bold uppercase tracking-wider text-stone-600">Best</dt><dd data-demo="best" class="text-base font-bold tabular-nums">&ndash;</dd></div>
                        <div><dt class="text-[10px] font-bold uppercase tracking-wider text-stone-600">Average</dt><dd data-demo="average" class="text-base font-bold tabular-nums">&ndash;</dd></div>
                        <div><dt class="text-[10px] font-bold uppercase tracking-wider text-stone-600">Bingos</dt><dd data-demo="bingos" class="text-base font-bold tabular-nums">0</dd></div>
                    </dl>
                </div>

                <div class="px-4 pb-3">
                    <button type="button" data-demo="add" class="btn btn-primary btn-block h-14 text-base font-extrabold"><x-icon name="plus" class="h-5 w-5" />Add a turn</button>
                    <p data-demo="tip" class="mt-2.5 flex items-start gap-2 text-sm text-stone-700"><span class="mt-0.5 text-brand-700"><x-icon name="bulb" class="h-5 w-5" /></span><span data-demo="tip-text"></span></p>
                </div>

                <noscript><p class="border-t border-stone-100 px-5 py-4 text-sm text-stone-700">The score sheet draws itself, switch JavaScript on to try it.</p></noscript>
                <ul data-demo="list" class="divide-y divide-stone-100 border-t border-stone-100"></ul>

                <div class="flex justify-end border-t border-stone-100 px-4 py-1.5">
                    <button type="button" data-demo="reset" class="min-h-11 rounded-xl px-3 text-sm font-bold text-brand-700 hover:text-brand-900 focus-visible:outline-2 focus-visible:outline-brand-600">Start over</button>
                </div>
            </section>
        </div>
    </section>

    {{-- ============ A game night, step by step ============ --}}
    <section id="walk" class="group/walk mt-16 sm:mt-24" aria-labelledby="walk-heading">
        <div class="mx-auto max-w-2xl text-center">
            <h2 id="walk-heading" class="text-3xl font-extrabold tracking-tight sm:text-4xl">How a game night goes</h2>
            <p class="mt-3 text-lg text-stone-700">One of you sets it up, and keeps the score on one screen. Or everybody adds their own.</p>
        </div>

        <div class="mt-10 grid gap-10 md:group-data-[js]/walk:grid-cols-2 md:group-data-[js]/walk:gap-16">
            <ol class="space-y-10 md:group-data-[js]/walk:space-y-0">
                @foreach ([
                    ['Pick who&rsquo;s playing', 'One of you signs in and chooses the players, two to four of them. Played last night? Play again with the same people in one tap.', 'new-game.png', 'A screen shot of choosing the players for a new game'],
                    ['Add a turn, the screen does the rest', 'Type the word, enter what it scored and tap add. Played all seven tiles? One tap for the 50 point bingo. Everyone is on the same screen and whoever is next up is ready, so keeping score never gets in the way of the game.', 'score-sheet.png', 'A screen shot of the score sheet for Scrabble, with a player\'s turns and their best and lowest word'],
                    ['Or let it count the tiles', 'Switch on Tile by tile and type the word. Every letter becomes a tile, tap one to say it sits on a double or triple square, is a blank or was already on the board, and the score adds itself up. It is optional, and a fun way for children to see how the points are made.', 'tile-by-tile.png', 'A screen shot of scoring the word QUIZ tile by tile, with a triple letter square under the Q and a double word square under the Z'],
                    ['Or let everyone add their own', 'Every player gets a link to their own score sheet and can add their own words on their own phone, no app and no sign up. It is up to you, you can keep all the score yourself and never share a thing.', 'share-links.png', 'A screen shot of sharing a score sheet link with each player'],
                    ['Finish the game, see the numbers', 'When the bag is empty and someone has played out, finish the game. The highest and lowest word, the longest word, every bingo and who won are kept in your history and your stats.', 'game-stats.png', 'A screen shot of the overview of a finished game with the highlights of the game'],
                ] as [$title, $text, $image, $alt])
                    <li data-step @if ($loop->first) data-active @endif class="group/step md:group-data-[js]/walk:flex md:group-data-[js]/walk:min-h-[70vh] md:group-data-[js]/walk:items-center">
                        <div>
                            <span class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-brand-50 text-lg font-extrabold text-brand-800 ring-1 ring-brand-100 transition group-data-[active]/step:bg-brand-700 group-data-[active]/step:text-white group-data-[active]/step:ring-brand-700 motion-reduce:transition-none" aria-hidden="true">{{ $loop->iteration }}</span>
                            <h3 class="mt-3 text-2xl font-extrabold tracking-tight"><span class="sr-only">Step {{ $loop->iteration }}: </span>{!! $title !!}</h3>
                            <p class="mt-2 max-w-md text-stone-700">{!! $text !!}</p>

                            {{-- A phone under the step on a small screen, one phone beside all of them on a large one --}}
                            <div class="mt-6 flex justify-center rounded-3xl bg-brand-50 px-6 pt-6 md:group-data-[js]/walk:hidden">
                                <img src="{{ asset('images/'.$image) }}" width="300" height="600" loading="lazy" alt="{{ $alt }}" class="h-auto w-60 rounded-t-3xl shadow-lift ring-1 ring-stone-200">
                            </div>
                        </div>
                    </li>
                @endforeach
            </ol>

            <div class="hidden md:group-data-[js]/walk:block" aria-label="The screens, in step with the text" role="group">
                <div class="sticky top-24 mx-auto w-72">
                    <div class="rounded-[2.5rem] bg-stone-900 p-2 shadow-lift">
                        <div class="relative aspect-[1/2] overflow-hidden rounded-[2rem] bg-white">
                            @foreach (['new-game.png' => 'A screen shot of choosing the players for a new game', 'score-sheet.png' => 'A screen shot of the score sheet for Scrabble, with a player\'s turns and their best and lowest word', 'tile-by-tile.png' => 'A screen shot of scoring the word QUIZ tile by tile, with a triple letter square under the Q and a double word square under the Z', 'share-links.png' => 'A screen shot of sharing a score sheet link with each player', 'game-stats.png' => 'A screen shot of the overview of a finished game with the highlights of the game'] as $image => $alt)
                                <img data-shot @if ($loop->first) data-active @else aria-hidden="true" @endif src="{{ asset('images/'.$image) }}" width="300" height="600" loading="lazy" alt="{{ $alt }}" class="absolute inset-0 h-full w-full object-cover object-top opacity-0 transition-opacity duration-300 data-[active]:opacity-100 motion-reduce:transition-none">
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ============ The numbers ============ --}}
    <section class="mt-16 sm:mt-24" aria-labelledby="stats-heading">
        <div class="mx-auto max-w-2xl text-center">
            <h2 id="stats-heading" class="text-3xl font-extrabold tracking-tight sm:text-4xl">Every word, counted</h2>
            <p class="mt-3 text-lg text-stone-700">Every game you finish adds to your stats, for the game and for every player.</p>
        </div>

        <ul class="mt-8 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ([
                ['star', 'Highest scoring word', 'The best play of the night, and the best of all time, with who played it.'],
                ['minus', 'Lowest scoring word', 'The smallest score that was still a word. Someone had to play it.'],
                ['trophy', 'Longest word', 'How many letters, and who found them.'],
                ['stats', 'Averages and records', 'Average word, bingos, wins, the closest game and the biggest win.'],
            ] as [$icon, $heading, $text])
                <li class="card p-5 sm:p-5">
                    <span class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-brand-50 text-brand-700 ring-1 ring-brand-100"><x-icon :name="$icon" class="h-5 w-5" /></span>
                    <h3 class="mt-3 font-extrabold tracking-tight">{{ $heading }}</h3>
                    <p class="mt-1 text-sm text-stone-700">{{ $text }}</p>
                </li>
            @endforeach
        </ul>
    </section>

    {{-- ============ Ready? ============ --}}
    <section class="mt-16 rounded-4xl bg-gradient-to-br from-brand-700 to-brand-900 p-8 text-center text-white shadow-lift sm:mt-24 sm:p-12" aria-labelledby="ready-heading">
        <h2 id="ready-heading" class="text-3xl font-extrabold tracking-tight sm:text-4xl">Ready for game night?</h2>
        <p class="mx-auto mt-3 max-w-lg text-brand-100">Register, pick the players and start scoring. It takes about a minute.</p>
        <div class="mt-7 flex flex-col items-center justify-center gap-3 sm:flex-row">
            <a href="{{ route('register.view') }}" class="inline-flex min-h-12 w-full items-center justify-center rounded-2xl bg-white px-8 text-base font-extrabold text-brand-800 hover:bg-brand-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white sm:w-auto sm:min-w-44">Register</a>
            <a href="{{ route('sign-in.view') }}" class="inline-flex min-h-12 w-full items-center justify-center rounded-2xl bg-white/15 px-8 text-base font-bold text-white hover:bg-white/25 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white sm:w-auto sm:min-w-44">Sign in</a>
        </div>
    </section>

    @push('scripts')
        <script src="{{ asset('js/tiles.js') }}?v={{ config('app.version.js') }}" defer></script>
        <script src="{{ asset('js/turn-entry.js') }}?v={{ config('app.version.js') }}" defer></script>
        <script src="{{ asset('js/landing.js') }}?v={{ config('app.version.js') }}" defer></script>
    @endpush
</x-layouts.guest>
