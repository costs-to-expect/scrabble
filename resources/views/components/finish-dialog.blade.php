@props(['boardId', 'standings' => [], 'live' => false])
{{-- Finishing is by hand, a game of Scrabble ends when the bag is empty and someone has played out. The standings are
     drawn here for a page that knows them, and by the game screen's script (live) for the page that keeps score. --}}
@php($ranked = \App\Support\GameBoard::ranked($standings))
<x-sheet id="finish-dialog" title="Finish this game?" subtitle="Scores are locked once the game is finished.">
    <ul id="finish-list" class="divide-y divide-stone-100 rounded-2xl ring-1 ring-stone-200" @if ($live) aria-live="polite" @endif>
        @foreach ($ranked as $rank => $player)
            <li class="flex items-center gap-3 px-3 py-2.5">
                <span class="w-5 text-center text-sm font-bold text-stone-500">{{ $rank + 1 }}</span>
                <x-avatar :name="$player['name']" :index="$player['tone']" class="h-8 w-8 text-sm" />
                <span class="flex-1 font-semibold">{{ $player['name'] }}@if ($player['leader']) <x-icon name="crown" class="ml-1 inline h-4 w-4 text-amber-500" /><span class="sr-only">Leading</span>@endif</span>
                <span class="text-lg font-extrabold tabular-nums">{{ $player['score'] }}</span>
            </li>
        @endforeach
    </ul>

    @if ($live)
        <x-alert id="finish-unsaved" type="warning" class="mt-3" hidden>A turn has not been saved yet. Finish the game once it has, or it will not count.</x-alert>
    @endif
    <x-alert type="info" class="mt-3">Played out? Before you finish, use <strong>Adjust</strong> to take the tiles left on each rack off that player&rsquo;s score, and to add them for whoever went out.</x-alert>

    <div class="mt-5 grid gap-2.5">
        <form action="{{ route('game.complete.action', ['game_id' => $boardId]) }}" method="POST">
            @csrf
            <button type="submit" data-finish class="btn btn-primary btn-block">Finish game</button>
        </form>
        <form action="{{ route('game.complete.play-again.action', ['game_id' => $boardId]) }}" method="POST">
            @csrf
            <button type="submit" data-finish class="btn btn-soft btn-block">Finish and play again</button>
        </form>
        <button type="button" data-dialog-close data-autofocus class="btn btn-quiet btn-block">Keep playing</button>
    </div>
</x-sheet>
