<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Logger;
use App\Models\Audit;
use RuntimeException;

final class Backup
{
    public static function pgBinary(string $name): string
    {
        $which = trim((string) shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null'));
        return $which !== '' && is_executable($which) ? $which : $name;
    }

    public static function run(string $label = ''): array
    {
        $dir = Config::get('storage.backups');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $stamp = date('Ymd_His') . ($label !== '' ? '_' . preg_replace('/[^A-Za-z0-9_-]/', '', $label) : '');
        $dumpFile = $dir . '/atr_db_' . $stamp . '.dump';
        $uploadsFile = $dir . '/atr_uploads_' . $stamp . '.tar.gz';

        $db = Config::get('db');
        $env = 'PGPASSWORD=' . escapeshellarg((string) $db['pass']);
        $pgDump = escapeshellarg(self::pgBinary('pg_dump'));

        $cmd = $env . ' ' . $pgDump
            . ' -h ' . escapeshellarg((string) $db['host'])
            . ' -p ' . escapeshellarg((string) $db['port'])
            . ' -U ' . escapeshellarg((string) $db['user'])
            . ' -Fc -f ' . escapeshellarg($dumpFile) . ' ' . escapeshellarg((string) $db['name']);

        exec($cmd . ' 2>&1', $output, $rc);
        if ($rc !== 0) {
            Logger::error('Backup failed', ['rc' => $rc, 'output' => $output]);
            throw new RuntimeException('Backup failed: ' . implode(' ', array_slice($output, -3)));
        }

        $uploadsDir = Config::get('storage.uploads');
        if (is_dir($uploadsDir) && count(glob($uploadsDir . '/*') ?: []) > 0) {
            exec('tar -czf ' . escapeshellarg($uploadsFile) . ' -C ' . escapeshellarg($uploadsDir) . ' . 2>&1', $out2, $rc2);
            if ($rc2 !== 0) {
                Logger::error('Uploads archive failed', ['output' => $out2]);
            }
        } else {
            $uploadsFile = null;
        }

        self::prune();
        Audit::log('backup.run', 'backup', basename($dumpFile), ['size' => filesize($dumpFile)]);
        Logger::info('Backup completed', ['file' => $dumpFile]);
        return ['dump' => $dumpFile, 'uploads' => $uploadsFile];
    }

    public static function all(): array
    {
        $dir = Config::get('storage.backups');
        $files = [];
        foreach (glob($dir . '/atr_db_*.dump') ?: [] as $file) {
            $files[] = [
                'path' => $file,
                'name' => basename($file),
                'size' => filesize($file),
                'mtime' => date('M j, Y g:i A', filemtime($file)),
                'id' => basename($file, '.dump'),
            ];
        }
        usort($files, static fn ($a, $b) => strcmp($b['name'], $a['name']));
        return $files;
    }

    public static function restore(string $id): void
    {
        $dir = Config::get('storage.backups');
        $file = $dir . '/' . basename($id) . '.dump';
        if (!is_file($file)) {
            throw new RuntimeException('Backup file not found.');
        }
        $db = Config::get('db');
        $env = 'PGPASSWORD=' . escapeshellarg((string) $db['pass']);
        $pgRestore = escapeshellarg(self::pgBinary('pg_restore'));

        $cmd = $env . ' ' . $pgRestore
            . ' -h ' . escapeshellarg((string) $db['host'])
            . ' -p ' . escapeshellarg((string) $db['port'])
            . ' -U ' . escapeshellarg((string) $db['user'])
            . ' --clean --if-exists --no-owner'
            . ' -d ' . escapeshellarg((string) $db['name'])
            . ' ' . escapeshellarg($file);

        exec($cmd . ' 2>&1', $output, $rc);
        if ($rc !== 0) {
            Logger::error('Restore failed', ['rc' => $rc, 'output' => $output]);
            throw new RuntimeException('Restore failed: ' . implode(' ', array_slice($output, -5)));
        }

        $uploadsArchive = glob($dir . '/atr_uploads_' . date('Ymd_His', filemtime($file)) . '_.tar.gz') ?: [];
        if ($uploadsArchive !== []) {
            exec('tar -xzf ' . escapeshellarg($uploadsArchive[0]) . ' -C ' . escapeshellarg(Config::get('storage.uploads')) . ' 2>&1', $out2, $rc2);
            if ($rc2 !== 0) {
                Logger::error('Uploads restore failed', ['output' => $out2]);
            }
        }

        Audit::log('backup.restore', 'backup', $id, []);
        Logger::info('Restore completed', ['file' => $file]);
    }

    private static function prune(): void
    {
        $keep = (int) Config::get('limits.backup_keep');
        $files = self::all();
        foreach (array_slice($files, $keep) as $old) {
            @unlink($old['path']);
        }
    }
}
