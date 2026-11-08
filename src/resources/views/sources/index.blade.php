<x-app-layout>
    <x-slot name="header"><div class="flex flex-wrap items-center justify-between gap-4"><div><p class="mb-1 text-xs uppercase tracking-widest text-slate-400">Конфігурація</p><h1 class="page-title">Файли Google Sheets</h1></div><a class="btn" href="{{ route('sources.create') }}">+ Додати файл</a></div></x-slot>
    <div class="page"><p class="mb-6 text-sm text-slate-500">Налаштуйте джерела, вкладки й колонки. Дані зберігаються щоденними знімками.</p>
        <div class="table-wrap"><table class="data-table"><thead><tr><th>Файл</th><th>Статус</th><th>Вкладок</th><th><span class="sr-only">Дії</span></th></tr></thead><tbody>
        @forelse($sources as $source)<tr><td><a class="link" href="{{ route('sources.show', $source) }}">{{ $source->name }}</a><p class="mt-1 max-w-xs truncate font-mono text-xs text-slate-400">{{ $source->spreadsheet_id }}</p></td><td><x-status-badge :status="$source->is_active ? 'active' : 'inactive'" /></td><td>{{ $source->tabs_count }}</td><td class="text-right"><a class="link" href="{{ route('sources.edit', $source) }}">Редагувати</a></td></tr>
        @empty<tr><td colspan="4"><div class="empty"><p class="font-medium text-slate-700">Додайте перше джерело даних</p><p class="mt-2">Вставте посилання на Google Sheets, а потім налаштуйте вкладки.</p><a class="btn mt-5" href="{{ route('sources.create') }}">Додати файл</a></div></td></tr>@endforelse
        </tbody></table></div><div class="mt-6">{{ $sources->links() }}</div>
    </div>
</x-app-layout>
