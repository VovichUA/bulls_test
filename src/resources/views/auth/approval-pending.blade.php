<x-guest-layout>
    <h1 class="text-2xl font-semibold">Очікуємо схвалення</h1>
    <p class="mt-4 text-gray-600">Реєстрацію завершено. Адміністратор має схвалити доступ до спільної панелі керування.</p>
    <p class="mt-2 text-sm text-gray-500">{{ auth()->user()->email }}</p>
    <div class="mt-6 flex items-center justify-between">
        <a class="underline" href="{{ route('approval.pending') }}">Оновити статус</a>
        <form method="POST" action="{{ route('logout') }}">@csrf <x-primary-button>Вийти</x-primary-button></form>
    </div>
</x-guest-layout>
