<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\Photo;
use App\Models\Setting;
use App\Themes;

class AuthController
{
    public function login(): void
    {
        if (Auth::user() !== null) {
            Response::redirect('/');
        }
        $logoId = (string) Setting::get('brand.logo_photo_id', '');
        $logoPhoto = $logoId !== '' ? Photo::find((int) $logoId) : null;
        View::output(View::render('auth/login', [
            'title' => 'Sign in',
            'error' => $_SESSION['flash']['error'] ?? null,
            'logo_photo' => $logoPhoto !== null && $logoPhoto['kind'] === 'asset' ? $logoPhoto : null,
            'theme' => Themes::resolve(
                null,
                Setting::get('theme.default_preset', Themes::DEFAULT_PRESET),
                Setting::get('theme.available', null)
            ),
        ], false));
    }

    public function loginPost(): void
    {
        $username = (string) Request::post('username', '');
        $password = (string) Request::post('password', '');
        if ($username === '' || $password === '') {
            Auth::flash('error', 'Enter a username and password.');
            Response::redirect('/login');
        }
        if (Auth::attempt($username, $password)) {
            $intended = (string) ($_SESSION['intended'] ?? '/');
            unset($_SESSION['intended']);
            Response::redirect($intended);
        }
        Auth::flash('error', 'Invalid credentials, or the account is locked. Try again in a few minutes.');
        Response::redirect('/login');
    }

    public function logout(): void
    {
        $user = Auth::user();
        if ($user !== null) {
            \App\Models\User::log('auth.logout', 'user', (string) $user['id'], ['username' => $user['username']]);
        }
        Auth::clear();
        session_destroy();
        Response::redirect('/login');
    }
}
