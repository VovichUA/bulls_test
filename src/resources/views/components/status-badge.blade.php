@props(['status'])
@php
    $value = $status instanceof \BackedEnum ? $status->value : $status;
    $labels = ['pending' => 'У черзі', 'running' => 'Виконується', 'succeeded' => 'Успішно', 'failed' => 'Помилка', 'skipped' => 'Пропущено', 'active' => 'Активний', 'inactive' => 'Неактивний'];
    $style = match($value) { 'succeeded', 'active' => 'bg-emerald-50 text-emerald-800', 'failed' => 'bg-red-50 text-red-700', 'running' => 'bg-blue-50 text-blue-700', 'pending' => 'bg-amber-50 text-amber-800', default => 'bg-slate-100 text-slate-600' };
@endphp
<span {{ $attributes->class(['badge', $style]) }}>{{ $labels[$value] ?? $value }}</span>
