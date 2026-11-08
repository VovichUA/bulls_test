<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class UserApprovalController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate(['status' => ['nullable', 'in:pending,approved']]);
        $users = User::with('approver')->when(($filters['status'] ?? '') === 'pending', fn ($query) => $query->whereNull('approved_at'))
            ->when(($filters['status'] ?? '') === 'approved', fn ($query) => $query->whereNotNull('approved_at'))->latest('id')->paginate(20)->withQueryString();

        return view('users.index', compact('users'));
    }

    public function approve(Request $request, User $user): RedirectResponse
    {
        DB::transaction(function () use ($request, $user): void {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($user->approved_at === null) {
                $user->forceFill(['approved_at' => now(), 'approved_by' => $request->user()->id])->save();
            }
        });

        return back()->with('status', 'Доступ користувача схвалено.');
    }

    public function revoke(User $user): RedirectResponse
    {
        abort_if($user->is_admin, 403);
        $user->forceFill(['approved_at' => null, 'approved_by' => null])->save();

        return back()->with('status', 'Доступ користувача відкликано.');
    }
}
