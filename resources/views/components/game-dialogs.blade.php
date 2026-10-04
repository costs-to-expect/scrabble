@props(['board', 'standings', 'shareTokens', 'live' => false])
{{-- The dialogs for a game in progress: share the public links, finish, remove a player and delete. A page that scores
     passes live, its script keeps the standings in the finish dialog up to date. --}}
<x-sheet id="share-dialog" title="Share score sheets" subtitle="Each player has their own link and can add their own words on their own phone. Anyone who has a link can score for that player, so only send it to them.">
    <ul class="divide-y divide-stone-100 rounded-2xl ring-1 ring-stone-200">
        @foreach ($standings as $player)
            @php($token = $shareTokens[$board['id']][$player['id']] ?? null)
            <li class="flex items-center gap-3 p-3">
                <x-avatar :name="$player['name']" :index="$player['tone']" class="h-10 w-10 text-base" />
                <span class="min-w-0 flex-1 truncate font-bold">{{ $player['name'] }}</span>
                @if ($token)
                    <button type="button" data-copy="{{ route('public.score-sheet', ['token' => $token]) }}" @if ($loop->first) data-autofocus @endif
                            class="inline-flex min-h-11 min-w-32 items-center justify-center gap-1.5 rounded-xl bg-brand-50 px-3.5 py-2.5 text-sm font-bold text-brand-800 hover:bg-brand-100 focus-visible:outline-2 focus-visible:outline-brand-600">
                        <x-icon name="link" class="h-4 w-4" /><span data-copy-label>Copy link</span>
                    </button>
                @else
                    <span class="text-xs text-stone-600">No link</span>
                @endif
            </li>
        @endforeach
    </ul>
    <button type="button" data-dialog-close class="btn btn-quiet btn-block mt-4">Done</button>
</x-sheet>

<x-finish-dialog :board-id="$board['id']" :standings="$standings" :live="$live" />

<x-sheet id="remove-dialog" title="Remove a player" subtitle="Their score sheet and share link are removed straight away, it can&rsquo;t be undone.">
    <ul class="divide-y divide-stone-100 rounded-2xl ring-1 ring-stone-200">
        @foreach ($standings as $player)
            <li class="flex items-center gap-3 p-3">
                <x-avatar :name="$player['name']" :index="$player['tone']" class="h-10 w-10 text-base" />
                <span class="min-w-0 flex-1 truncate font-bold">{{ $player['name'] }}</span>
                <form action="{{ route('game.player.delete', ['game_id' => $board['id'], 'player_id' => $player['id']]) }}" method="POST">
                    @csrf
                    <button type="submit" class="btn btn-danger-soft min-h-11 px-4 py-2" aria-label="Remove {{ $player['name'] }} from the game">Remove</button>
                </form>
            </li>
        @endforeach
    </ul>
    <button type="button" data-dialog-close data-autofocus class="btn btn-quiet btn-block mt-4">Cancel</button>
</x-sheet>

<x-sheet id="delete-dialog" title="Delete this game?" subtitle="This removes the game and every score in it. It can&rsquo;t be undone.">
    <div class="grid gap-2.5">
        <form action="{{ route('game.delete.action', ['game_id' => $board['id']]) }}" method="POST">
            @csrf
            <button type="submit" class="btn btn-danger btn-block">Delete game</button>
        </form>
        <button type="button" data-dialog-close data-autofocus class="btn btn-quiet btn-block">Cancel</button>
    </div>
</x-sheet>
