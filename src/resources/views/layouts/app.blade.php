<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'Bulls') }} · Google Sheets</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-50 font-sans text-slate-900 antialiased">
    <a href="#main-content" class="sr-only focus:not-sr-only">Перейти до вмісту</a>
    @include('layouts.navigation')
    @isset($header)<header class="border-b border-slate-200 bg-white"><div class="page py-6">{{ $header }}</div></header>@endisset
    <main id="main-content">
        @if(session('status') || session('error') || $errors->any())
        <div class="page pb-0">
            @if(session('status'))<div role="status" class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">{{ session('status') }}</div>@endif
            @if(session('error'))<div role="alert" class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">{{ session('error') }}</div>@endif
            @if($errors->any())<div role="alert" class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800"><p class="font-semibold">Перевірте дані форми</p><ul class="mt-2 list-inside list-disc">@foreach(array_slice($errors->all(), 0, 20) as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        </div>@endif
        {{ $slot }}
    </main>
    <footer class="page border-t border-slate-200 text-xs text-slate-400">Спільний простір · Google Sheets → щоденні знімки · {{ auth()->user()->email }}</footer>
</body>
</html>
