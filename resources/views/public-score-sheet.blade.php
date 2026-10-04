<x-layouts.base :title="'Hey '.$player_name.', play '.config('app.game.name').' with us!'" :noindex="true" body-class="pb-0">
    <x-score-sheet :config="$config" :public="true" />
</x-layouts.base>
