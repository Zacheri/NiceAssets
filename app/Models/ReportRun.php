<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class ReportRun
{
    public static function store(
        string $name,
        string $type,
        array $params,
        ?string $filePdf,
        ?string $fileExcel,
        int $rowCount,
        array $summary,
        ?array $user
    ): int {
        $version = (int) Database::fetchColumn(
            "SELECT COALESCE(MAX(version), 0) + 1 FROM report_runs
             WHERE report_type = :t
               AND to_char(generated_at, 'IYYY') = to_char(now(), 'IYYY')
               AND to_char(generated_at, 'IW') = to_char(now(), 'IW')",
            ['t' => $type]
        );
        $id = Database::insert(
            'INSERT INTO report_runs (name, report_type, params, generated_by, version, file_pdf, file_excel, row_count, summary)
             VALUES (:n, :t, :p, :b, :v, :pdf, :xlsx, :rc, :s)',
            [
                'n' => $name,
                't' => $type,
                'p' => json_encode($params, JSON_UNESCAPED_SLASHES),
                'b' => $user['id'] ?? null,
                'v' => $version,
                'pdf' => $filePdf,
                'xlsx' => $fileExcel,
                'rc' => $rowCount,
                's' => json_encode($summary, JSON_UNESCAPED_SLASHES),
            ]
        );
        Audit::log('report.generate', 'report_run', (string) $id, ['name' => $name, 'type' => $type, 'version' => $version]);
        return (int) $id;
    }

    public static function list(string $type = '', int $limit = 30): array
    {
        $where = '1=1';
        $params = [];
        if ($type !== '') {
            $where = 'report_type = :t';
            $params['t'] = $type;
        }
        $params['limit'] = $limit;
        return Database::fetchAll(
            'SELECT r.*, u.full_name AS generated_by_name
             FROM report_runs r LEFT JOIN users u ON u.id = r.generated_by
             WHERE ' . $where . '
             ORDER BY r.generated_at DESC
             LIMIT :limit',
            $params
        );
    }

    public static function find(int $id): ?array
    {
        return Database::fetchOne(
            'SELECT r.*, u.full_name AS generated_by_name
             FROM report_runs r LEFT JOIN users u ON u.id = r.generated_by
             WHERE r.id = :id',
            ['id' => $id]
        );
    }
}
