<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Config;

function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function url(string $path = '/'): string
{
    return Config::get('base_url') . $path;
}

function asset_url(string $path): string
{
    $file = BASE_PATH . '/public/theme/' . ltrim($path, '/');
    $v = is_file($file) ? (string) filemtime($file) : '0';
    return url('/theme/' . ltrim($path, '/') . '?v=' . $v);
}

function contains_host(string $value): bool
{
    return (bool) preg_match('#^https?://#i', $value);
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . e(\App\Core\CSRF::token()) . '">';
}

function old(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $default;
    return e(is_string($value) ? $value : $default);
}

function money(mixed $value): string
{
    return '$' . number_format((float) $value, 2);
}

function date_fmt(?string $value, string $format = 'M j, Y'): string
{
    if ($value === null || $value === '' || $value === '0000-00-00') {
        return '—';
    }
    $ts = strtotime($value);
    return $ts === false ? '—' : date($format, $ts);
}

function status_label(string $status): string
{
    return match ($status) {
        'available' => 'Available',
        'checked_out' => 'Checked Out',
        'in_repair' => 'In Repair',
        'broken' => 'Broken',
        'lost' => 'Lost',
        'disposed' => 'Disposed',
        'sold' => 'Sold',
        'donated' => 'Donated',
        default => $status,
    };
}

function status_class(string $status): string
{
    return 'status-' . e($status);
}

function photo_url(?array $photo): string
{
    if ($photo === null) {
        return asset_url('img/placeholder.svg');
    }
    return url('/uploads/' . rawurlencode($photo['filename']));
}

function action_label(string $action): string
{
    return match ($action) {
        'asset.create' => 'Created',
        'asset.update' => 'Updated',
        'asset.delete' => 'Deleted',
        'asset.check_out' => 'Checked out',
        'asset.check_in' => 'Checked in',
        'asset.transfer' => 'Transferred',
        'asset.status' => 'Status change',
        'asset.repair' => 'Sent to repair',
        'asset.broken' => 'Marked broken',
        'asset.lost' => 'Marked lost',
        'asset.dispose' => 'Disposed',
        'asset.sold' => 'Sold',
        'asset.donate' => 'Donated',
        'asset.replicate' => 'Replicated',
        'asset.email' => 'Emailed',
        'asset.photo' => 'Photo changed',
        'work_order.create' => 'Work order created',
        'work_order.complete' => 'Work order completed',
        'photo.upload' => 'Photo uploaded',
        'photo.delete' => 'Photo deleted',
        'auth.login' => 'Logged in',
        'auth.logout' => 'Logged out',
        default => $action,
    };
}

function is_weekend(): bool
{
    return (int) date('N') === 6;
}

function request_query(): string
{
    return isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? '?' . $_SERVER['QUERY_STRING'] : '';
}
