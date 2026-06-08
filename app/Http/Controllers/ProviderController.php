<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProviderController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->route('login');
        }

        abort_unless($request->user()->isProvider() || $request->user()->isAdmin(), 403);

        return view('provider');
    }

    public function profile(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isProvider() || $request->user()?->isAdmin(), 403);

        return response()->json([
            'user' => $request->user()->only([
                'id',
                'name',
                'email',
                'username',
                'contact_number',
                'role',
            ]),
            'stats' => [
                'scholarships' => 0,
                'applications' => 0,
                'drafts' => 0,
            ],
        ]);
    }
}
