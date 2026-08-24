<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Models\Audit;
use App\Models\ReportRun;
use RuntimeException;

final class WeeklyReport
{
    private const TRACKED_ACTIONS = [
        'asset.check_out',
        'asset.check_in',
        'asset.transfer',
        'asset.repair',
        'asset.broken',
        'asset.lost',
        'asset.dispose',
        'asset.sold',
        'asset.donate',
        'asset.create',
        'asset.update',
        'asset.delete',
    ];

    public static function previousWeekRange(?string $fromDate = null): array
    {
        if ($fromDate !== null) {
            $today = new \DateTimeImmutable($fromDate);
        } else {
            $today = new \DateTimeImmutable('today');
        }
        $n = (int) $today->format('N'); // Mon=1 .. Sun=7
        // Monday of the PREVIOUS week: (days elapsed since Monday) + 7 days back.
        $offsetDays = $n + 6;
        $monday = $today->modify('-' . $offsetDays . ' days');
        $friday = $monday->modify('+4 days');
        return [$monday->format('Y-m-d'), $friday->format('Y-m-d')];
    }

    public static function generate(?array $user = null, ?string $asOfDate = null): array
    {
        [$monday, $friday] = self::previousWeekRange($asOfDate);
        $entries = Audit::actionsBetween($monday, $friday, self::TRACKED_ACTIONS);

        $byAction = [];
        $rows = [];
        foreach ($entries as $entry) {
            $details = json_decode((string) $entry['details'], true) ?: [];
            $byAction[$entry['action']] = ($byAction[$entry['action']] ?? 0) + 1;
            $rows[] = [
                'date' => date('M j', strtotime($entry['created_at'])),
                'time' => date('g:i A', strtotime($entry['created_at'])),
                'user' => $entry['username'],
                'action' => action_label($entry['action']),
                'asset' => $entry['entity_id'] !== ''
                    ? (string) ($details['asset_tag'] ?? $entry['entity_id'])
                    : (string) ($details['username'] ?? $entry['entity_id']),
                'details' => self::detailSummary($entry['action'], $details),
            ];
        }

        $columns = [
            ['key' => 'date', 'label' => 'Date', 'type' => 'date'],
            ['key' => 'time', 'label' => 'Time'],
            ['key' => 'user', 'label' => 'User'],
            ['key' => 'action', 'label' => 'Action'],
            ['key' => 'asset', 'label' => 'Asset / Item'],
            ['key' => 'details', 'label' => 'Details'],
        ];

        $name = 'Weekly Activity Report (' . date('M j', strtotime($monday)) . ' – ' . date('M j, Y', strtotime($friday)) . ')';
        $summary = [
            'range' => ['from' => $monday, 'to' => $friday],
            'total' => count($rows),
            'by_action' => $byAction,
        ];

        $filePdf = Export::pdf($name, 'weekly', $columns, $rows);
        $fileExcel = Export::excel($name, 'weekly', $columns, $rows, false);

        $id = ReportRun::store($name, 'weekly', ['from' => $monday, 'to' => $friday], $filePdf, $fileExcel, count($rows), $summary, $user);

        return ReportRun::find($id) ?? ['id' => $id, 'name' => $name, 'version' => 1];
    }

    private static function detailSummary(string $action, array $d): string
    {
        $parts = [];
        $map = [
            'to' => 'to',
            'from' => 'from',
            'due_date' => 'due',
            'reason' => 'reason',
            'disposal_location' => 'location',
            'remaining_cost' => 'remaining cost',
            'sold_to' => 'sold to',
            'sold_price' => 'price',
            'donated_to' => 'donated to',
            'donated_value' => 'value',
            'work_order' => 'WO',
        ];
        foreach ($map as $key => $label) {
            if (isset($d[$key]) && $d[$key] !== null && $d[$key] !== '') {
                $parts[] = $label . ': ' . $d[$key];
            }
        }
        return implode(' · ', $parts);
    }
}
