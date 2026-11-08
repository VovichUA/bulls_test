<x-app-layout>
    <x-slot name="header"><p class="mb-1 text-xs uppercase tracking-widest text-slate-400">Адміністрування</p><h1 class="page-title">Користувачі та доступ</h1></x-slot>
    <div class="page"><p class="mb-6 max-w-3xl text-sm text-slate-500">Схвалення відкриває доступ до спільних файлів, мапінгів і журналу. Воно не надає права керувати користувачами.</p>
        <form class="mb-6 flex items-end gap-3" method="GET"><label class="field" for="user-status">Статус<select class="input" id="user-status" name="status"><option value="">Усі</option><option value="pending" @selected(request('status') === 'pending')>Очікують схвалення</option><option value="approved" @selected(request('status') === 'approved')>Схвалені</option></select></label><button class="btn-secondary">Застосувати</button></form>
        <div class="table-wrap"><table class="data-table"><thead><tr><th>Користувач</th><th>Реєстрація (UTC)</th><th>Схвалено (UTC)</th><th>Доступ</th></tr></thead><tbody>
        @forelse($users as $user)<tr><td><p class="font-medium">{{ $user->name }}</p><p class="mt-1 text-xs text-slate-500">{{ $user->email }}</p></td><td class="whitespace-nowrap text-slate-500">{{ $user->created_at }}</td><td>{{ $user->approved_at ?? 'Очікує схвалення' }}<p class="mt-1 text-xs text-slate-500">{{ $user->approver?->name }}</p></td><td>
            @if($user->is_admin)<span class="badge bg-slate-100 text-slate-600">Адміністратор</span>
            @elseif($user->approved_at)<form method="POST" action="{{ route('users.revoke', $user) }}" onsubmit="return confirm('Відкликати доступ користувача до спільної панелі?')">@csrf <button class="btn-danger">Відкликати доступ</button></form>
            @else<form method="POST" action="{{ route('users.approve', $user) }}">@csrf <button class="btn">Схвалити доступ</button></form>@endif
        </td></tr>@empty<tr><td colspan="4" class="empty">Користувачів за цим фільтром немає.</td></tr>@endforelse
        </tbody></table></div><div class="mt-6">{{ $users->links() }}</div>
    </div>
</x-app-layout>
