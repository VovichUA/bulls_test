<!DOCTYPE html>
<html lang="uk">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}"><title>{{ config('app.name', 'Bulls') }} · Вхід</title>@vite(['resources/css/app.css', 'resources/js/app.js'])</head>
<body class="min-h-screen bg-slate-50 font-sans text-slate-900 antialiased">
    <main class="flex min-h-screen flex-col items-center justify-center px-4 py-12">
        <a href="/" class="mb-8 text-2xl font-bold tracking-tight">Bulls <span class="font-normal text-slate-400">/ Sheets</span></a>
        <div class="card w-full max-w-md p-6 sm:p-8">{{ $slot }}</div>
        <p class="mt-6 text-center text-xs text-slate-400">Керування даними Google Sheets</p>
    </main>
</body>
</html>
