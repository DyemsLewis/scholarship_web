<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PageController extends Controller
{
    public function home(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->roleRedirect($request, includeApplicant: false)) {
            return $redirect;
        }

        return view('landing');
    }

    public function login(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->roleRedirect($request)) {
            return $redirect;
        }

        return view('login');
    }

    public function register(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->roleRedirect($request)) {
            return $redirect;
        }

        return view('register');
    }

    public function providerRegister(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->roleRedirect($request)) {
            return $redirect;
        }

        return view('provider-register');
    }

    public function forgotPassword(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->roleRedirect($request)) {
            return $redirect;
        }

        return view('forgot-password');
    }

    public function resetPassword(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->roleRedirect($request)) {
            return $redirect;
        }

        return view('reset-password');
    }

    public function accountSetup(Request $request): View|RedirectResponse
    {
        if ($request->user()?->isAdmin()) {
            return redirect()->route('admin.index');
        }

        if ($request->user()?->isProvider()) {
            return redirect()->route('provider.index');
        }

        return view('account-setup');
    }

    private function roleRedirect(Request $request, bool $includeApplicant = true): ?RedirectResponse
    {
        if ($request->user()?->isAdmin()) {
            return redirect()->route('admin.index');
        }

        if ($request->user()?->isProvider()) {
            return redirect()->route('provider.index');
        }

        if ($includeApplicant && $request->user()) {
            return redirect()->route('dashboard');
        }

        return null;
    }
}
