<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use RuntimeException;

final class Person
{
    public static function all(): array
    {
        return Database::fetchAll(
            'SELECT p.*, d.name AS department_name,
                    pp.filename AS portrait_filename, pp.original_name AS portrait_name,
                    (SELECT COUNT(*) FROM assets a
                     WHERE a.assigned_to_person_id = p.id AND a.status = \'checked_out\')::int AS checked_out_count
              FROM persons p
              LEFT JOIN departments d ON d.id = p.department_id
              LEFT JOIN photos pp ON pp.id = p.portrait_photo_id
              ORDER BY p.is_terminated ASC, p.full_name'
        );
    }

    public static function picker(): array
    {
        return Database::fetchAll(
            'SELECT id, full_name FROM persons WHERE is_terminated = false ORDER BY full_name'
        );
    }

    public static function find(int $id): ?array
    {
        return Database::fetchOne(
            'SELECT p.*, d.name AS department_name,
                    pp.filename AS portrait_filename, pp.original_name AS portrait_name
              FROM persons p
              LEFT JOIN departments d ON d.id = p.department_id
              LEFT JOIN photos pp ON pp.id = p.portrait_photo_id
              WHERE p.id = :id',
            ['id' => $id]
        );
    }

    public static function create(array $d): int
    {
        if (trim($d['full_name'] ?? '') === '') {
            throw new RuntimeException('Full name is required.');
        }
        $id = Database::insert(
            'INSERT INTO persons (full_name, job_title, personal_email, work_email, phone, address, department_id, portrait_photo_id, notes, is_terminated)
             VALUES (:n, :jt, :pe, :we, :ph, :ad, :dep, :port, :notes, :term)',
            self::params($d)
        );
        Audit::log('person.create', 'person', (string) $id, ['full_name' => trim($d['full_name'])]);
        return (int) $id;
    }

    public static function update(int $id, array $d): void
    {
        $existing = self::find($id);
        if ($existing === null) {
            throw new RuntimeException('Person not found.');
        }
        if (trim($d['full_name'] ?? '') === '') {
            throw new RuntimeException('Full name is required.');
        }
        Database::execute(
            'UPDATE persons SET full_name = :n, job_title = :jt, personal_email = :pe, work_email = :we,
             phone = :ph, address = :ad, department_id = :dep, portrait_photo_id = :port, notes = :notes,
             is_terminated = :term, updated_at = now() WHERE id = :id',
            array_merge(self::params($d), ['id' => $id])
        );
        Audit::log('person.update', 'person', (string) $id, ['full_name' => trim($d['full_name'])]);
    }

    public static function toggleTerminated(int $id): bool
    {
        $existing = self::find($id);
        if ($existing === null) {
            throw new RuntimeException('Person not found.');
        }
        $terminated = empty($existing['is_terminated']);
        Database::execute(
            'UPDATE persons SET is_terminated = :t, updated_at = now() WHERE id = :id',
            ['t' => $terminated ? 1 : 0, 'id' => $id]
        );
        Audit::log(
            $terminated ? 'person.terminate' : 'person.reinstate',
            'person',
            (string) $id,
            ['full_name' => $existing['full_name']]
        );
        return $terminated;
    }

    public static function delete(int $id): void
    {
        $existing = self::find($id);
        if ($existing === null) {
            throw new RuntimeException('Person not found.');
        }
        Database::execute('DELETE FROM persons WHERE id = :id', ['id' => $id]);
        Audit::log('person.delete', 'person', (string) $id, ['full_name' => $existing['full_name']]);
    }

    private static function params(array $d): array
    {
        return [
            'n' => trim($d['full_name']),
            'jt' => trim($d['job_title'] ?? ''),
            'pe' => trim($d['personal_email'] ?? ''),
            'we' => trim($d['work_email'] ?? ''),
            'ph' => trim($d['phone'] ?? ''),
            'ad' => trim($d['address'] ?? ''),
            'dep' => !empty($d['department_id']) ? (int) $d['department_id'] : null,
            'port' => self::portraitPhotoId($d['portrait_photo_id'] ?? null),
            'notes' => trim($d['notes'] ?? ''),
            'term' => !empty($d['is_terminated']) ? 1 : 0,
        ];
    }

    private static function portraitPhotoId(mixed $value): ?int
    {
        $id = (int) $value;
        if ($id <= 0) {
            return null;
        }
        $photo = Photo::find($id);
        if ($photo === null || $photo['kind'] !== 'portrait') {
            return null;
        }
        return $id;
    }
}
