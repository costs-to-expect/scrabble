<x-layouts.base :title="config('app.game.name').' Game Scorer: Score sheet'" body-class="pb-0">
    <x-score-sheet :config="$config" :game-id="$game_id" :standings="$standings" :share-tokens="$share_tokens" />
</x-layouts.base>
