<x-guest-layout>
    <h1 class="page-title">З поверненням</h1><p class="mt-2 mb-6 text-sm text-slate-500">Увійдіть до панелі керування імпортом.</p>
    <x-auth-session-status class="mb-4" :status="session('status')" />
    <form method="POST" action="{{ route('login') }}" class="space-y-5">@csrf
        <label class="field" for="email">Email<input id="email" class="input" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username"><x-input-error :messages="$errors->get('email')" class="mt-2" /></label>
        <label class="field" for="password">Пароль<input id="password" class="input" type="password" name="password" required autocomplete="current-password"><x-input-error :messages="$errors->get('password')" class="mt-2" /></label>
        <label class="flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" name="remember" class="rounded border-slate-300 text-emerald-800 focus:ring-emerald-700">Запам’ятати мене</label>
        <button class="btn w-full">Увійти</button>
    </form>
    <p class="mt-6 text-sm text-slate-500">Немає облікового запису? <a class="link" href="{{ route('register') }}">Зареєструватися</a></p>
</x-guest-layout>
