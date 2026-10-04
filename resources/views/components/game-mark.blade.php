@props(['game' => null])
{{-- The mark of the game in config/app/game.php, a page can ask for another game's mark --}}
<svg {{ $attributes->merge(['class' => 'h-5 w-5']) }} viewBox="0 0 24 24" aria-hidden="true"><use href="#m-{{ $game ?? config('app.game.mark') }}"/></svg>
