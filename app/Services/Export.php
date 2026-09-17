<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Logger;
use Dompdf\Dompdf;
use Dompdf\Options;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;

final class Export
{
    private const PDF_MAX_ROWS = 2000;

    private static function reportsDir(): string
    {
        $dir = Config::get('storage.reports');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return $dir;
    }

    public static function pdf(
        string $title,
        string $type,
        array $columns,
        array $rows,
        array $options = []
    ): string {
        $showPhotos = !empty($options['photos']) && count($rows) <= (int) Config::get('limits.report_pdf_photo_rows');
        $truncated = count($rows) > self::PDF_MAX_ROWS;
        $rows = array_slice($rows, 0, self::PDF_MAX_ROWS);

        $head = '';
        foreach ($columns as $col) {
            $head .= '<th>' . e($col['label']) . '</th>';
        }
        $body = '';
        foreach ($rows as $row) {
            $body .= '<tr>';
            if ($showPhotos) {
                $photo = $row[$columns[0]['key']] ?? null;
                $path = is_string($photo) && $photo !== '' ? Config::get('storage.uploads') . '/' . rawurldecode($photo) : null;
                $body .= '<td class="thumb">' . ($path && is_file($path)
                    ? '<img src="' . e($path) . '" style="width:44px;height:44px;object-fit:cover;border-radius:4px">'
                    : '') . '</td>';
            }
            foreach ($columns as $col) {
                $raw = $row[$col['key']] ?? '';
                $body .= '<td>' . self::cellHtml($col, $raw) . '</td>';
            }
            $body .= '</tr>';
        }

        $meta = [
            'title' => $title,
            'generated' => date('F j, Y g:i A'),
            'rows' => count($rows) . ($truncated ? ' of ' . (int) $options['total_rows'] : ''),
            'generator' => 'Nice Assets ' . Config::get('app_version'),
            'version' => $options['run_version'] ?? '',
        ];

        $html = '<!doctype html><html><head><meta charset="utf-8"><style>'
            . 'body{font-family:Helvetica,Arial,sans-serif;font-size:9px;color:#0f172a}'
            . 'h1{font-size:16px;margin:0 0 2px}'
            . '.meta{color:#64748b;font-size:8px;margin-bottom:10px}'
            . 'table{width:100%;border-collapse:collapse}'
            . 'th{background:#0f172a;color:#fff;text-align:left;padding:5px 6px;font-size:8.5px}'
            . 'td{border-bottom:1px solid #e2e8f0;padding:4px 6px;vertical-align:middle}'
            . 'tr:nth-child(even) td{background:#f8fafc}'
            . '.thumb{width:52px}'
            . '</style></head><body>'
            . '<h1>' . e($meta['title']) . '</h1>'
            . '<div class="meta">Generated ' . e($meta['generated'])
            . ' · ' . e($meta['rows']) . ' row(s)'
            . ($meta['version'] !== '' ? ' · Version ' . e($meta['version']) : '')
            . ' · ' . e($meta['generator']) . '</div>'
            . '<table><thead><tr>' . ($showPhotos ? '<th>Photo</th>' : '') . $head . '</tr></thead>'
            . '<tbody>' . $body . '</tbody></table>'
            . ($truncated ? '<p style="color:#b45309">Showing first ' . self::PDF_MAX_ROWS . ' rows. Use the Excel export for the full listing.</p>' : '')
            . '</body></html>';

        $file = self::reportsDir() . '/' . self::safeFile('report_' . $type . '.pdf');
        try {
            $optionsObj = new Options();
            $optionsObj->setChroot(BASE_PATH);
            $optionsObj->setIsFontSubsettingEnabled(true);
            $dompdf = new Dompdf($optionsObj);
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4', 'landscape');
            $dompdf->render();
            file_put_contents($file, $dompdf->output());
        } catch (\Throwable $e) {
            Logger::error('PDF export failed', ['error' => $e->getMessage()]);
            throw new RuntimeException('PDF export failed: ' . $e->getMessage(), 0, $e);
        }
        return $file;
    }

    public static function excel(
        string $title,
        string $type,
        array $columns,
        array $rows,
        bool $withFormulas,
        array $options = []
    ): string {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Report');

        $sheet->setCellValue('A1', $title);
        $sheet->mergeCells('A1:' . self::colName(count($columns)) . '1');
        $titleFont = $sheet->getStyle('A1')->getFont();
        $titleFont->setSize(14);
        $titleFont->setBold(true);
        $sheet->setCellValue(
            'A2',
            'Generated ' . date('F j, Y g:i A')
            . (($options['run_version'] ?? '') !== '' ? ' · Version ' . $options['run_version'] : '')
            . ' · Nice Assets ' . Config::get('app_version')
            . ($withFormulas ? ' · Live formulas' : ' · Static values')
        );

        $headerRow = 4;
        $colIndex = 1;
        $keyToCol = [];
        foreach ($columns as $col) {
            $cell = self::colName($colIndex) . $headerRow;
            $sheet->setCellValue($cell, $col['label']);
            $headerFont = $sheet->getStyle($cell)->getFont();
            $headerFont->setBold(true);
            $headerFont->getColor()->setARGB('FFFFFFFF');
            $headerFill = $sheet->getStyle($cell)->getFill();
            $headerFill->setFillType(Fill::FILL_SOLID);
            $headerFill->getStartColor()->setARGB('FF0F172A');
            $keyToCol[$col['key']] = self::colName($colIndex);
            $colIndex++;
        }

        $valueCol = $keyToCol['current_value'] ?? null;
        $costCol = $keyToCol['purchase_cost'] ?? null;
        $dateCol = $keyToCol['purchase_date'] ?? null;

        $r = $headerRow + 1;
        foreach ($rows as $row) {
            $ci = 1;
            foreach ($columns as $col) {
                $key = $col['key'];
                $cell = self::colName($ci) . $r;
                $raw = $row[$key] ?? '';
                if ($withFormulas && $key === 'current_value' && $valueCol && $costCol && $dateCol) {
                    $costVal = (float) ($row['purchase_cost'] ?? 0);
                    if (empty($row['purchase_date']) || $costVal <= 0) {
                        $sheet->setCellValue($cell, 0);
                    } else {
                        $formula = '=ROUND(MAX(0,' . $costCol . $r
                            . '-(' . $costCol . $r . '/60)*MIN(60,DATEDIF(' . $dateCol . $r . ',TODAY(),"M"))),2)';
                        $sheet->setCellValue($cell, $formula);
                    }
                } else {
                    $value = self::cellValue($col, $raw);
                    $sheet->setCellValue($cell, $value);
                    if ($value instanceof \DateTimeInterface) {
                        $sheet->getStyle($cell)->getNumberFormat()->setFormatCode('m/d/yyyy');
                    }
                }
                $ci++;
            }
            $r++;
        }

        $sheet->freezePane('A' . ($headerRow + 1));
        $sheet->getColumnDimension('A')->setWidth(18);

        $file = self::reportsDir() . '/' . self::safeFile('report_' . $type . '.xlsx');
        $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($file);
        $spreadsheet->disconnectWorksheets();
        return $file;
    }

    public static function assetSheet(array $asset, string $qrFile): string
    {
        $dep = $asset['depreciation'] ?? [];
        $photo = $asset['photos'][0] ?? null;
        $photoPath = $photo ? Config::get('storage.uploads') . '/' . $photo['filename'] : null;

        $rows = [
            ['Asset Tag', $asset['asset_tag']],
            ['Serial Number', $asset['serial_number'] ?? '—'],
            ['Model Number', $asset['model_number'] ?? '—'],
            ['Brand', $asset['brand'] ?? '—'],
            ['Category', $asset['category_name'] ?? '—'],
            ['Department', $asset['department_name'] ?? '—'],
            ['Site', $asset['site_name'] ?? '—'],
            ['Location', ($asset['location_name'] ?? '—') . ($asset['location_code'] ? ' (' . $asset['location_code'] . ')' : '')],
            ['Status', status_label($asset['status'])],
            ['Status Reason', $asset['status_reason'] ?? '—'],
            ['Assigned To', $asset['assigned_name'] ?? ($asset['assigned_dept_name'] ?? '—')],
            ['Due Date', date_fmt($asset['due_date'])],
            ['Purchase Date', date_fmt($asset['purchase_date'])],
            ['Purchase Cost', money($asset['purchase_cost'])],
            ['Warranty Expires', date_fmt($asset['warranty_expiration'])],
            ['Sub-Quantity', (string) $asset['sub_quantity']],
            ['Current Value', $asset['purchase_date'] ? money($dep['value'] ?? $asset['purchase_cost']) : money($asset['purchase_cost'])],
            ['Depreciated', $dep['fully'] ? 'Fully depreciated' : ($dep['remaining_months'] ?? '—') . ' months remaining'],
            ['Work Order', $asset['wo_number'] ?? '—'],
            ['Created', date_fmt($asset['created_at'], 'M j, Y g:i A') . ' by ' . ($asset['created_by_name'] ?? '—')],
            ['Last Updated', date_fmt($asset['updated_at'], 'M j, Y g:i A')],
        ];
        if ($asset['status'] === 'disposed') {
            $rows[] = ['Disposed At', ($asset['disposal_location'] ?? '—') . ' on ' . date_fmt($asset['disposal_date'])];
            $rows[] = ['Remaining Cost', $asset['disposal_remaining_cost'] !== null ? money($asset['disposal_remaining_cost']) : '—'];
        }
        if ($asset['status'] === 'sold') {
            $rows[] = ['Sold To', $asset['sold_to'] ?? '—'];
            $rows[] = ['Sale Price', $asset['sold_price'] !== null ? money($asset['sold_price']) : '—'];
        }
        if ($asset['status'] === 'donated') {
            $rows[] = ['Donated To', $asset['donated_to'] ?? '—'];
            $rows[] = ['Donation Value', $asset['donated_value'] !== null ? money($asset['donated_value']) : '—'];
        }

        $table = '';
        foreach ($rows as [$label, $value]) {
            $table .= '<tr><td class="k">' . e($label) . '</td><td>' . e($value) . '</td></tr>';
        }

        $photoHtml = ($photoPath && is_file($photoPath))
            ? '<img src="' . e($photoPath) . '" style="width:160px;height:120px;object-fit:cover;border-radius:8px;border:1px solid #e2e8f0">'
            : '<div style="width:160px;height:120px;border:1px dashed #cbd5e1;border-radius:8px;display:flex;align-items:center;justify-content:center;color:#94a3b8;font-size:11px">No photo</div>';
        $qrHtml = is_file($qrFile)
            ? '<img src="' . e($qrFile) . '" style="width:120px;height:120px">'
            : '';

        $html = '<!doctype html><html><head><meta charset="utf-8"><style>'
            . 'body{font-family:Helvetica,Arial,sans-serif;font-size:11px;color:#0f172a;margin:24px}'
            . 'h1{font-size:20px;margin:0}'
            . '.sub{color:#64748b;margin:2px 0 16px}'
            . '.top{display:flex;gap:24px;align-items:flex-start;margin-bottom:18px}'
            . 'table.fields{border-collapse:collapse;flex:1}'
            . 'table.fields td{border:1px solid #e2e8f0;padding:5px 10px}'
            . 'td.k{background:#f8fafc;font-weight:bold;width:160px;color:#334155}'
            . '.foot{margin-top:18px;color:#94a3b8;font-size:9px}'
            . '</style></head><body>'
            . '<h1>' . e($asset['brand'] . ' ' . $asset['model_number']) . '</h1>'
            . '<div class="sub">' . e($asset['asset_tag']) . ' · ' . e($asset['category_name'] ?? '') . ' · ' . e(status_label($asset['status'])) . '</div>'
            . '<div class="top">' . $photoHtml . '<div style="text-align:center">' . $qrHtml
            . '<div style="font-size:9px;color:#64748b;margin-top:4px">Scan tag</div></div>'
            . '<table class="fields">' . $table . '</table></div>'
            . '<div class="foot">Generated ' . date('F j, Y g:i A') . ' · Nice Assets ' . e(Config::get('app_version'))
            . ' · Depreciation: 5-year linear from purchase date</div>'
            . '</body></html>';

        $file = self::reportsDir() . '/sheet_' . preg_replace('/[^A-Za-z0-9_-]/', '', $asset['asset_tag']) . '_' . date('YmdHis') . '.pdf';
        $options = new Options();
        $options->setChroot(BASE_PATH);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4');
        $dompdf->render();
        file_put_contents($file, $dompdf->output());
        return $file;
    }

    private static function cellHtml(array $col, mixed $raw): string
    {
        if (is_bool($raw)) {
            $raw = $raw ? 'Yes' : 'No';
        }
        return e((string) ($raw ?? ''));
    }

    private static function cellValue(array $col, mixed $raw): mixed
    {
        if (is_bool($raw)) {
            return $raw ? 'Yes' : 'No';
        }
        if ($raw === null || $raw === '') {
            return '';
        }
        $type = (string) ($col['type'] ?? '');
        if ($type === 'date') {
            $ts = strtotime((string) $raw);
            return $ts === false ? (string) $raw : new \DateTimeImmutable('@' . $ts)->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        }
        if ($type === 'money' && is_numeric($raw)) {
            return (float) $raw;
        }
        if (is_numeric($raw) && $type === 'number') {
            return (float) $raw;
        }
        return (string) $raw;
    }

    private static function safeFile(string $name): string
    {
        return date('Ymd_His') . '_' . substr(bin2hex(random_bytes(3)), 0, 6) . '_' . preg_replace('/[^A-Za-z0-9._-]/', '', $name);
    }

    private static function colName(int $index): string
    {
        $name = '';
        while ($index > 0) {
            $index--;
            $name = chr(65 + ($index % 26)) . $name;
            $index = intdiv($index, 26);
        }
        return $name;
    }
}
