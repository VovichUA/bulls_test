<nav x-data="{ open: false }" class="border-b border-gray-200 bg-white">
    <div class="mx-auto flex max-w-7xl items-center justify-between px-4 py-4 sm:px-6 lg:px-8">
        <a href="{{ route('sources.index') }}" class="text-xl font-bold tracking-tight">Bulls <span class="font-normal text-gray-500">/ Sheets</span></a>
        <button type="button" class="rounded border px-3 py-2 sm:hidden" @click="open = !open" :aria-expanded="open">Меню</button>
        <div class="hidden items-center gap-6 sm:flex">
            @foreach(['sources.index' => 'Файли', 'tabs.index' => 'Вкладки', 'targets.index' => 'Мапінг', 'runs.index' => 'Журнал'] as $route => $label)
                @php($active = match ($route) {
                    'sources.index' => request()->routeIs('sources.*') && !request()->routeIs('sources.tabs.*'),
                    'tabs.index' => request()->routeIs('tabs.index', 'sources.tabs.*'),
                    'targets.index' => request()->routeIs('targets.*', 'tabs.targets.*'),
                    default => request()->routeIs('runs.*'),
                })
                <a href="{{ route($route) }}" class="text-sm {{ $active ? 'font-semibold text-emerald-700' : 'text-gray-600' }}" @if($active) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
            @if(auth()->user()->is_admin)<a href="{{ route('users.index') }}" class="text-sm text-gray-600">Користувачі</a>@endif
            <form method="POST" action="{{ route('logout') }}">@csrf <button class="text-sm text-gray-500">Вийти</button></form>
        </div>
    </div>
    <div x-show="open" x-cloak class="space-y-3 border-t px-4 py-4 sm:hidden">
        @foreach(['sources.index' => 'Файли', 'tabs.index' => 'Вкладки', 'targets.index' => 'Мапінг', 'runs.index' => 'Журнал'] as $route => $label)<a class="block" href="{{ route($route) }}">{{ $label }}</a>@endforeach
        @if(auth()->user()->is_admin)<a class="block" href="{{ route('users.index') }}">Користувачі</a>@endif
        <form method="POST" action="{{ route('logout') }}">@csrf <button>Вийти</button></form>
    </div>
</nav>
