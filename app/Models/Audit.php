<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Request;
use Throwable;

final class Audit
{
    public static function log(string $action, string $entity, string $entityId, array $details = []): void
    {
        $user = Auth::user();
        try {
            Database::execute(
                'INSERT INTO audit_log (user_id, username, action, entity, entity_id, details, ip)
                 VALUES (:uid, :u, :a, :e, :eid, :d, :ip)',
                [
                    'uid' => $user['id'] ?? null,
                    'u' => $user['username'] ?? 'system',
                    'a' => $action,
                    'e' => $entity,
                    'eid' => $entityId,
                    'd' => json_encode($details, JSON_UNESCAPED_SLASHES),
                    'ip' => Request::ip(),
                ]
            );
        } catch (Throwable $e) {
            Logger::error('Audit log write failed', ['action' => $action, 'error' => $e->getMessage()]);
        }
    }

    public static function recent(int $limit = 15, ?array $user = null): array
    {
        [$scope, $params] = $user !== null && $user['role_name'] !== 'admin'
            ? [' AND a.username = :u', ['u' => $user['username']]]
            : ['', []];
        $params['limit'] = $limit;
        return Database::fetchAll(
            "SELECT a.*, u.full_name AS actor_name
             FROM audit_log a LEFT JOIN users u ON u.id = a.user_id
             WHERE 1=1 {$scope}
             ORDER BY a.created_at DESC LIMIT :limit",
            $params
        );
    }

    public static function query(array $f, int $page, int $perPage, array $params = []): array
    {
        $where = ['1=1'];
        if (!empty($f['user'])) {
            $where[] = 'a.username ILIKE :user';
            $params['user'] = '%' . $f['user'] . '%';
        }
        if (!empty($f['action'])) {
            $where[] = 'a.action = :action';
            $params['action'] = $f['action'];
        }
        if (!empty($f['entity'])) {
            $where[] = 'a.entity = :entity';
            $params['entity'] = $f['entity'];
        }
        if (!empty($f['entity_id'])) {
            $where[] = 'a.entity_id = :entity_id';
            $params['entity_id'] = $f['entity_id'];
        }
        if (!empty($f['from'])) {
            $where[] = 'a.created_at >= :from';
            $params['from'] = $f['from'] . ' 00:00:00';
        }
        if (!empty($f['to'])) {
            $where[] = 'a.created_at <= :to';
            $params['to'] = $f['to'] . ' 23:59:59';
        }
        $whereSql = implode(' AND ', $where);

        $total = (int) Database::fetchColumn("SELECT COUNT(*) FROM audit_log a WHERE {$whereSql}", $params);
        $offset = max(0, ($page - 1) * $perPage);
        $p = $params;
        $p['limit'] = $perPage;
        $p['offset'] = $offset;
        $rows = Database::fetchAll(
            "SELECT a.*, u.full_name AS actor_name
             FROM audit_log a LEFT JOIN users u ON u.id = a.user_id
             WHERE {$whereSql}
             ORDER BY a.created_at DESC
             LIMIT :limit OFFSET :offset",
            $p
        );
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    public static function actionsBetween(string $from, string $to, ?array $actions = null): array
    {
        $where = 'a.created_at >= :from AND a.created_at <= :to';
        $params = ['from' => $from . ' 00:00:00', 'to' => $to . ' 23:59:59'];
        if ($actions !== null) {
            $in = [];
            foreach ($actions as $i => $a) {
                $in[] = ':act' . $i;
                $params['act' . $i] = $a;
            }
            $where .= ' AND a.action IN (' . implode(',', $in) . ')';
        }
        return Database::fetchAll(
            "SELECT a.* FROM audit_log a WHERE {$where} ORDER BY a.created_at ASC",
            $params
        );
    }

    public static function distinctActions(): array
    {
        return Database::fetchAll('SELECT DISTINCT action FROM audit_log ORDER BY action');
    }
}
