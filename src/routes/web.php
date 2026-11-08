<?php

use App\Http\Controllers\ManualSyncController;
use App\Http\Controllers\SheetSourceController;
use App\Http\Controllers\SheetTabController;
use App\Http\Controllers\SyncRunController;
use App\Http\Controllers\SyncTargetController;
use App\Http\Controllers\UserApprovalController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route(auth()->check() ? (auth()->user()->approved_at ? 'sources.index' : 'approval.pending') : 'login'));
Route::get('/dashboard', fn () => redirect()->route('sources.index'))->middleware(['auth', 'approved'])->name('dashboard');
Route::get('/approval-pending', function () {
    if (auth()->user()->fresh()->approved_at !== null) {
        return redirect()->route('sources.index');
    }

    return view('auth.approval-pending');
})->middleware('auth')->name('approval.pending');

Route::middleware(['auth', 'approved'])->scopeBindings()->group(function () {
    Route::resource('sources', SheetSourceController::class);
    Route::get('/tabs', [SheetTabController::class, 'index'])->name('tabs.index');
    Route::resource('sources.tabs', SheetTabController::class)->except('index');
    Route::get('/targets', [SyncTargetController::class, 'index'])->name('targets.index');
    Route::resource('tabs.targets', SyncTargetController::class)->except('index');
    Route::post('/sources/{source}/check', [SheetSourceController::class, 'check'])->middleware('throttle:google-helpers')->name('sources.check');
    Route::post('/sources/{source}/discover', [SheetSourceController::class, 'discover'])->middleware('throttle:google-helpers')->name('sources.discover');
    Route::post('/tabs/{tab}/headers', [SheetTabController::class, 'headers'])->middleware('throttle:google-helpers')->name('tabs.headers');
    Route::get('/runs', [SyncRunController::class, 'index'])->name('runs.index');
    Route::get('/runs/{run}', [SyncRunController::class, 'show'])->name('runs.show');
    Route::post('/sync', [ManualSyncController::class, 'store'])->name('sync.store');
    Route::middleware('admin')->group(function () {
        Route::get('/admin/users', [UserApprovalController::class, 'index'])->name('users.index');
        Route::post('/admin/users/{user}/approve', [UserApprovalController::class, 'approve'])->name('users.approve');
        Route::post('/admin/users/{user}/revoke', [UserApprovalController::class, 'revoke'])->name('users.revoke');
    });
});
require __DIR__.'/auth.php';
