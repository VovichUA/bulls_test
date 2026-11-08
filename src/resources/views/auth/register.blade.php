<x-guest-layout>
    <h1 class="page-title">Створіть обліковий запис</h1><p class="mt-2 mb-6 text-sm text-slate-500">Доступ до спільної панелі відкриється після схвалення адміністратором.</p>
    <form method="POST" action="{{ route('register') }}" class="space-y-5">@csrf
        <label class="field" for="name">Ім’я<input id="name" class="input" name="name" value="{{ old('name') }}" required maxlength="255" autofocus autocomplete="name"><x-input-error :messages="$errors->get('name')" class="mt-2" /></label>
        <label class="field" for="email">Email<input id="email" class="input" type="email" name="email" value="{{ old('email') }}" required autocomplete="username"><x-input-error :messages="$errors->get('email')" class="mt-2" /></label>
        <label class="field" for="password">Пароль<input id="password" class="input" type="password" name="password" required minlength="12" autocomplete="new-password"><span class="hint block">Щонайменше 12 символів.</span><x-input-error :messages="$errors->get('password')" class="mt-2" /></label>
        <label class="field" for="password_confirmation">Повторіть пароль<input id="password_confirmation" class="input" type="password" name="password_confirmation" required minlength="12" autocomplete="new-password"></label>
        <button class="btn w-full">Зареєструватися</button>
    </form>
    <p class="mt-6 text-sm text-slate-500">Уже зареєстровані? <a class="link" href="{{ route('login') }}">Увійти</a></p>
</x-guest-layout>
