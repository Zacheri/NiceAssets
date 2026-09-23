<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Models\Audit;
use App\Models\User;
use App\Themes;

class AccountController
{
    public function themeUpdate(): void
    {
        $user = Auth::requireLogin();
        $preset = Themes::normalizePreset((string) Request::post('preset', ''));
        $base = Themes::PRESETS[$preset];
        $colors = [];
        foreach (Themes::COLOR_TOKENS as $token) {
            $value = Themes::normalizeColor((string) Request::post('color_' . $token, ''));
            if ($value !== '' && $value !== ($base[$token] ?? null)) {
                $colors[$token] = $value;
            }
        }
        User::setTheme((int) $user['id'], ['preset' => $preset, 'colors' => $colors]);
        Audit::log('user.theme', 'user', (string) $user['id'], ['preset' => $preset, 'colors' => $colors]);
        Auth::flash('success', 'Theme saved.');
        Response::back();
    }
}
