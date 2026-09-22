<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Config;
use App\Core\Database;
use RuntimeException;

final class Photo
{
    private const ALLOWED = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public static function all(string $kind = 'asset'): array
    {
        return Database::fetchAll(
            'SELECT p.*, u.full_name AS uploaded_by,
                    (SELECT COUNT(*) FROM asset_photos ap WHERE ap.photo_id = p.id)::int AS usage_count,
                    (SELECT a.asset_tag FROM asset_photos ap JOIN assets a ON a.id = ap.asset_id
                     WHERE ap.photo_id = p.id LIMIT 1) AS first_asset
              FROM photos p LEFT JOIN users u ON u.id = p.created_by
              WHERE p.kind = :kind
              ORDER BY p.created_at DESC
              LIMIT 500',
            ['kind' => $kind]
        );
    }

    public static function find(int $id): ?array
    {
        return Database::fetchOne('SELECT * FROM photos WHERE id = :id', ['id' => $id]);
    }

    public static function path(array $photo): string
    {
        return Config::get('storage.uploads') . '/' . $photo['filename'];
    }

    public static function upload(array $files, string $variety, ?array $user, string $kind = 'asset'): array
    {
        $maxBytes = (int) ((float) Config::get('limits.photo_max_mb')) * 1024 * 1024;
        $normalized = [];
        $names = $files['name'] ?? [];
        if (!is_array($names)) {
            $names = [$names];
        }
        foreach (array_keys($names) as $i) {
            $normalized[] = [
                'name' => $files['name'][$i] ?? '',
                'type' => $files['type'][$i] ?? '',
                'tmp_name' => $files['tmp_name'][$i] ?? '',
                'error' => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size' => (int) ($files['size'][$i] ?? 0),
            ];
        }
        $created = [];
        foreach ($normalized as $file) {
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                continue;
            }
            $size = (int) $file['size'];
            if ($size <= 0 || $size > $maxBytes) {
                throw new RuntimeException('Photo too large (max ' . Config::get('limits.photo_max_mb') . ' MB).');
            }
            $info = @getimagesize($file['tmp_name']);
            if ($info === false) {
                throw new RuntimeException('File ' . basename($file['name']) . ' is not a valid image.');
            }
            $ext = match ($info['mime']) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                'image/gif' => 'gif',
                default => null,
            };
            if ($ext === null) {
                throw new RuntimeException('Unsupported image type: ' . $info['mime']);
            }
            $filename = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
            $dest = Config::get('storage.uploads') . '/' . $filename;
            if (!move_uploaded_file($file['tmp_name'], $dest)) {
                throw new RuntimeException('Could not save uploaded photo.');
            }
            $id = Database::insert(
                'INSERT INTO photos (filename, original_name, variety, mime, size, kind, created_by)
                 VALUES (:f, :o, :v, :m, :s, :k, :cb)',
                [
                    'f' => $filename,
                    'o' => basename($file['name']),
                    'v' => trim($variety),
                    'm' => $info['mime'],
                    's' => $size,
                    'k' => $kind,
                    'cb' => $user['id'] ?? null,
                ]
            );
            $created[] = Database::fetchOne('SELECT * FROM photos WHERE id = :id', ['id' => $id]);
        }
        if ($created !== []) {
            Audit::log('photo.upload', 'photo', (string) count($created), [
                'variety' => $variety,
                'kind' => $kind,
                'files' => array_column($created, 'filename'),
            ]);
        }
        return $created;
    }

    public static function delete(int $id, ?array $user): void
    {
        $photo = self::find($id);
        if ($photo === null) {
            throw new RuntimeException('Photo not found.');
        }
        Database::execute('DELETE FROM asset_photos WHERE photo_id = :id', ['id' => $id]);
        Database::execute('DELETE FROM photos WHERE id = :id', ['id' => $id]);
        $path = self::path($photo);
        if (is_file($path)) {
            @unlink($path);
        }
        Audit::log('photo.delete', 'photo', (string) $id, ['filename' => $photo['filename']]);
    }
}
