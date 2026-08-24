<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Models\UserPref;

class PrefController
{
    public function store(): void
    {
        $user = Auth::requireLogin();
        $key = (string) Request::post('key', '');
        $value = (string) Request::post('value', '');
        if ($key === 'grid_cols') {
            $value = (string) min(6, max(1, (int) $value));
        } elseif ($key === 'per_page') {
            $value = (string) min((int) \App\Core\Config::get('limits.per_page_max'), max(6, (int) $value));
        } else {
            Response::json(['ok' => false], 400);
        }
        UserPref::set((int) $user['id'], $key, $value);
        Response::json(['ok' => true, 'value' => $value]);
    }
}
