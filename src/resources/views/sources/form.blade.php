<x-app-layout>
    <x-slot name="header"><a class="link text-sm" href="{{ route('sources.index') }}">← Файли</a><h1 class="page-title mt-2">{{ $source->exists ? 'Налаштування файлу' : 'Новий файл' }}</h1></x-slot>
    <div class="page"><form method="POST" action="{{ $source->exists ? route('sources.update', $source) : route('sources.store') }}" class="card card-body max-w-2xl space-y-6">@csrf @if($source->exists)@method('PUT')@endif
        <label class="field" for="source-name">Назва<input id="source-name" name="name" class="input" value="{{ old('name', $source->name) }}" required maxlength="255" placeholder="Наприклад, Продажі"></label>
        <label class="field" for="spreadsheet-id">Посилання або ID Google Sheets<input id="spreadsheet-id" name="spreadsheet_id" class="input font-mono" value="{{ old('spreadsheet_id', $source->spreadsheet_id) }}" required placeholder="https://docs.google.com/spreadsheets/d/..."><span class="hint block">Надайте Service Account доступ на перегляд файлу. Ключі доступу через цю форму не завантажуються.</span></label>
        <input type="hidden" name="is_active" value="0"><label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $source->is_active)) class="rounded border-slate-300 text-emerald-800">Активний файл — дозволити планові та ручні запуски</label>
        <div class="flex gap-3"><button class="btn">Зберегти</button><a class="btn-secondary" href="{{ $source->exists ? route('sources.show', $source) : route('sources.index') }}">Скасувати</a></div>
    </form></div>
</x-app-layout>
