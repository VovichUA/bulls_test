@if($stalePendingCount > 0)
<div role="alert" class="mb-6 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-900">
    <p class="font-semibold">Є pending старше {{ $staleMinutes }} хвилин: {{ $stalePendingCount }}. Перевірте воркер.</p>
    <p>Без воркера задачі не обробляються. Завершені як expired планові спроби витрачають денний бюджет; ручні — ні.</p>
</div>
@endif
