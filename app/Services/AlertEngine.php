<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use App\Core\Mailer;
use App\Models\Asset;
use App\Models\Setting;
use App\Models\User;

final class AlertEngine
{
    public static function dashboard(?array $user = null): array
    {
        $alerts = [];

        foreach (Asset::warrantyExpiring($user) as $w) {
            $days = (int) $w['days_left'];
            $tier = $days <= 30 ? '30d' : ($days <= 60 ? '60d' : '90d');
            $alerts[] = [
                'key' => 'warranty:' . $w['id'] . ':' . $tier,
                'type' => 'warranty',
                'severity' => $days <= 30 || $tier === '90d' ? 'important' : 'informational',
                'title' => 'Warranty expiring in ' . $days . ' days',
                'message' => ($w['brand'] ?? '') . ' ' . ($w['model_number'] ?? '') . ' (' . $w['asset_tag'] . ') expires ' . date_fmt($w['warranty_expiration']),
                'link' => '/assets/' . $w['id'],
            ];
        }

        foreach (Asset::availableQtyByCategory() as $row) {
            if ((int) $row['qty'] < (int) $row['low_stock_threshold']) {
                $alerts[] = [
                    'key' => 'lowstock:' . $row['id'],
                    'type' => 'low_stock',
                    'severity' => 'important',
                    'title' => 'Low stock: ' . $row['name'],
                    'message' => $row['qty'] . ' available of threshold ' . $row['low_stock_threshold'],
                    'link' => '/assets?category_id=' . $row['id'] . '&status=available',
                ];
            }
        }

        $threshold = (int) Setting::get('multi_asset_threshold', 2);
        foreach (Asset::multiAssignments($threshold, $user) as $m) {
            $alerts[] = [
                'key' => 'multi:' . $m['person_id'] . ':' . md5($m['brand'] . '|' . $m['model_number']),
                'type' => 'multi_asset',
                'severity' => 'informational',
                'title' => ($m['count']) . ' similar assets assigned',
                'message' => $m['full_name'] . ' has ' . $m['count'] . 'x ' . ($m['brand'] ?? '') . ' ' . $m['model_number'] . ' (' . $m['tags'] . ')',
                'link' => '/assets?assigned_person_id=' . $m['person_id'],
            ];
        }

        foreach (Asset::fullyDepreciAssets($user) as $d) {
            $alerts[] = [
                'key' => 'depreciated:' . $d['id'],
                'type' => 'depreciation',
                'severity' => 'important',
                'title' => 'Fully depreciated: ' . $d['asset_tag'],
                'message' => ($d['brand'] ?? '') . ' ' . ($d['model_number'] ?? '') . ' (purchased ' . date_fmt($d['purchase_date']) . ') has no remaining book value',
                'link' => '/assets/' . $d['id'],
            ];
        }

        foreach (Asset::overdue($user) as $o) {
            $alerts[] = [
                'key' => 'overdue:' . $o['id'],
                'type' => 'overdue',
                'severity' => 'informational',
                'title' => 'Overdue: ' . $o['asset_tag'],
                'message' => ($o['brand'] ?? '') . ' ' . ($o['model_number'] ?? '') . ' assigned to ' . ($o['assigned_name'] ?? '—') . ', ' . $o['days_overdue'] . ' days past due',
                'link' => '/assets/' . $o['id'],
            ];
        }

        usort($alerts, static fn ($a, $b) => $a['severity'] === $b['severity']
            ? strcmp($a['title'], $b['title'])
            : ($a['severity'] === 'important' ? -1 : 1));
        return $alerts;
    }

    public static function sweep(?array $actor = null): array
    {
        $sent = [];
        $today = date('Ymd');

        foreach (Asset::warrantyExpiring() as $w) {
            $key = 'warranty90:' . $w['id'] . ':' . $today;
            if (!self::firstToday($key, 'warranty')) {
                continue;
            }
            $emailSent = self::broadcast(
                'Warranty expiring: ' . $w['asset_tag'],
                'The warranty on ' . ($w['brand'] ?? '') . ' ' . ($w['model_number'] ?? '') . ' (' . $w['asset_tag'] . ') expires on '
                    . date_fmt($w['warranty_expiration']) . ' (' . $w['days_left'] . ' days).',
                [$w['asset_tag'] . ' — expires ' . date_fmt($w['warranty_expiration']), 'Assigned to: ' . ($w['assigned_name'] ?? 'n/a')],
                '/assets/' . $w['id']
            );
            $sent[] = ['type' => 'warranty', 'asset' => $w['asset_tag'], 'email' => $emailSent];
        }

        foreach (Asset::availableQtyByCategory() as $row) {
            if ((int) $row['qty'] >= (int) $row['low_stock_threshold']) {
                continue;
            }
            $key = 'lowstock:' . $row['id'] . ':' . $today;
            if (!self::firstToday($key, 'low_stock')) {
                continue;
            }
            $emailSent = self::broadcast(
                'Low stock alert: ' . $row['name'],
                'Available quantity for ' . $row['name'] . ' is ' . $row['qty'] . ', below the threshold of ' . $row['low_stock_threshold'] . '.',
                ['Available: ' . $row['qty'], 'Threshold: ' . $row['low_stock_threshold']],
                '/assets?category_id=' . $row['id'] . '&status=available'
            );
            $sent[] = ['type' => 'low_stock', 'category' => $row['name'], 'email' => $emailSent];
        }

        $threshold = (int) Setting::get('multi_asset_threshold', 2);
        foreach (Asset::multiAssignments($threshold) as $m) {
            $key = 'multi:' . $m['user_id'] . ':' . md5($m['brand'] . '|' . $m['model_number']) . ':' . $today;
            if (!self::firstToday($key, 'multi_asset')) {
                continue;
            }
            $sent[] = [
                'type' => 'multi_asset',
                'user' => $m['full_name'],
                'email' => false,
                'note' => 'Dashboard-only alert per policy',
            ];
        }

        foreach (Asset::fullyDepreciAssets() as $d) {
            $key = 'depreciated:' . $d['id'] . ':' . $today;
            if (!self::firstToday($key, 'depreciation')) {
                continue;
            }
            $emailSent = self::broadcast(
                'Fully depreciated: ' . $d['asset_tag'],
                ($d['brand'] ?? '') . ' ' . ($d['model_number'] ?? '') . ' (' . $d['asset_tag'] . ') has reached the end of its 5-year depreciation schedule.',
                ['Purchase date: ' . date_fmt($d['purchase_date']), 'Original cost: ' . money($d['purchase_cost'])],
                '/assets/' . $d['id']
            );
            $sent[] = ['type' => 'depreciation', 'asset' => $d['asset_tag'], 'email' => $emailSent];
        }

        if ($sent !== []) {
            Logger::info('Alert sweep complete', ['sent' => $sent]);
        }
        return $sent;
    }

    private static function firstToday(string $key, string $type): bool
    {
        $inserted = Database::execute(
            'INSERT INTO alert_log (dedupe_key, type) VALUES (:k, :t) ON CONFLICT (dedupe_key) DO NOTHING',
            ['k' => $key, 't' => $type]
        );
        return $inserted > 0;
    }

    private static function broadcast(string $title, string $message, array $lines, string $link): bool
    {
        $anySent = false;
        $users = User::activeAll();
        foreach ($users as $recipient) {
            if (!Setting::emailEnabledForRole((string) $recipient['role_name'])) {
                continue;
            }
            if (trim((string) $recipient['email']) === '') {
                continue;
            }
            $sent = Mailer::send(
                (string) $recipient['email'],
                (string) $recipient['full_name'],
                $title,
                Mailer::alertHtml($title, $message, $lines, $link)
            );
            $anySent = $anySent || $sent;
        }
        return $anySent;
    }
}
