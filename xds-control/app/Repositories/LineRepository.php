<?php

declare(strict_types=1);

final class LineRepository
{
    public function __construct(
        private PDO $engineDb,
        private PDO $panelDb,
    ) {}

    public function list(string $query = '', int $limit = 100): array
    {
        $limit = max(25, min(250, $limit));
        $trashed = $this->activeTrashLineIds();
        $params = [];
        $where = [];

        if ($query !== '') {
            $where[] = '(l.username LIKE ? OR l.last_ip LIKE ? OR l.contact LIKE ?)';
            $params[] = '%'.$query.'%';
            $params[] = '%'.$query.'%';
            $params[] = '%'.$query.'%';
        }

        if ($trashed) {
            $marks = implode(',', array_fill(0, count($trashed), '?'));
            $where[] = 'l.id NOT IN ('.$marks.')';
            foreach ($trashed as $id) $params[] = $id;
        }

        $sql = 'SELECT l.id,l.username,l.password,l.member_id,l.admin_enabled,l.enabled,l.is_trial,l.is_restreamer,l.max_connections,l.exp_date,l.last_activity,l.last_ip,l.contact,(SELECT COUNT(*) FROM lines_live ll WHERE ll.user_id=l.id) online FROM `lines` l';
        if ($where) $sql .= ' WHERE '.implode(' AND ', $where);
        $sql .= ' ORDER BY l.id DESC LIMIT '.$limit;

        $st = $this->engineDb->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public function find(int $id): ?array
    {
        $st = $this->engineDb->prepare('SELECT * FROM `lines` WHERE id=? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch();
        return $row ?: null;
    }

    public function usernameExists(string $username, ?int $ignoreId = null): bool
    {
        $sql = 'SELECT id FROM `lines` WHERE username=?';
        $params = [$username];
        if ($ignoreId !== null) {
            $sql .= ' AND id<>?';
            $params[] = $ignoreId;
        }
        $sql .= ' LIMIT 1';
        $st = $this->engineDb->prepare($sql);
        $st->execute($params);
        return (bool)$st->fetchColumn();
    }

    public function create(array $data): int
    {
        $sql = 'INSERT INTO `lines` (member_id,username,password,exp_date,admin_enabled,enabled,admin_notes,max_connections,is_restreamer,is_trial,contact) VALUES (?,?,?,?,?,?,?,?,?,?,?)';
        $st = $this->engineDb->prepare($sql);
        $st->execute([
            $data['member_id'], $data['username'], $data['password'], $data['exp_date'],
            $data['admin_enabled'], $data['enabled'], $data['admin_notes'], $data['max_connections'],
            $data['is_restreamer'], $data['is_trial'], $data['contact'],
        ]);
        return (int)$this->engineDb->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $sql = 'UPDATE `lines` SET username=?,password=?,exp_date=?,admin_enabled=?,enabled=?,admin_notes=?,max_connections=?,is_restreamer=?,is_trial=?,contact=? WHERE id=? LIMIT 1';
        $st = $this->engineDb->prepare($sql);
        $st->execute([
            $data['username'], $data['password'], $data['exp_date'], $data['admin_enabled'], $data['enabled'],
            $data['admin_notes'], $data['max_connections'], $data['is_restreamer'], $data['is_trial'], $data['contact'], $id,
        ]);
    }

    public function softDelete(int $id): void
    {
        $st = $this->engineDb->prepare('UPDATE `lines` SET enabled=0,admin_enabled=0 WHERE id=? LIMIT 1');
        $st->execute([$id]);
    }

    public function restoreDeletionState(int $id, array $snapshot): void
    {
        $st = $this->engineDb->prepare('UPDATE `lines` SET enabled=?,admin_enabled=? WHERE id=? LIMIT 1');
        $st->execute([(int)($snapshot['enabled'] ?? 0), (int)($snapshot['admin_enabled'] ?? 0), $id]);
    }

    public function activeTrashLineIds(): array
    {
        try {
            return array_map('intval', $this->panelDb->query('SELECT line_id FROM line_trash WHERE restored_at IS NULL AND permanently_deleted_at IS NULL')->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable) {
            return [];
        }
    }

    public function trashItems(): array
    {
        $sql = 'SELECT id,line_id,username,snapshot_json,batch_id,deleted_by,deleted_at FROM line_trash WHERE restored_at IS NULL AND permanently_deleted_at IS NULL ORDER BY id DESC';
        return $this->panelDb->query($sql)->fetchAll();
    }

    public function trashItem(int $trashId): ?array
    {
        $st = $this->panelDb->prepare('SELECT * FROM line_trash WHERE id=? AND restored_at IS NULL AND permanently_deleted_at IS NULL LIMIT 1');
        $st->execute([$trashId]);
        $row = $st->fetch();
        return $row ?: null;
    }

    public function createDeleteSnapshot(array $line, ?int $adminUserId): array
    {
        $uuid = sprintf('%s-%s-4%s-%s%s-%s', bin2hex(random_bytes(4)), bin2hex(random_bytes(2)), bin2hex(random_bytes(2)), dechex(random_int(8,11)), bin2hex(random_bytes(1)), bin2hex(random_bytes(6)));
        $this->panelDb->beginTransaction();
        try {
            $b = $this->panelDb->prepare("INSERT INTO line_operation_batches (batch_uuid,operation_type,status,admin_user_id,description,affected_count) VALUES (?,'delete','applied',?,?,1)");
            $b->execute([$uuid, $adminUserId, 'Exclusão reversível da linha #'.$line['id']]);
            $batchId = (int)$this->panelDb->lastInsertId();
            $json = json_encode($line, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);

            $s = $this->panelDb->prepare("INSERT INTO line_operation_snapshots (batch_id,line_id,username,operation_type,before_json) VALUES (?,?,?,'delete',?)");
            $s->execute([$batchId, $line['id'], $line['username'] ?? null, $json]);

            $t = $this->panelDb->prepare('INSERT INTO line_trash (line_id,username,snapshot_json,batch_id,deleted_by) VALUES (?,?,?,?,?)');
            $t->execute([$line['id'], $line['username'] ?? null, $json, $batchId, $adminUserId]);
            $trashId = (int)$this->panelDb->lastInsertId();
            $this->panelDb->commit();
            return ['trash_id'=>$trashId,'batch_id'=>$batchId,'batch_uuid'=>$uuid];
        } catch (Throwable $e) {
            if ($this->panelDb->inTransaction()) $this->panelDb->rollBack();
            throw $e;
        }
    }

    public function markRestored(int $trashId, ?int $batchId): void
    {
        $this->panelDb->beginTransaction();
        try {
            $st = $this->panelDb->prepare('UPDATE line_trash SET restored_at=NOW() WHERE id=? LIMIT 1');
            $st->execute([$trashId]);
            if ($batchId) {
                $b = $this->panelDb->prepare("UPDATE line_operation_batches SET status='reverted',reverted_at=NOW() WHERE id=? LIMIT 1");
                $b->execute([$batchId]);
            }
            $this->panelDb->commit();
        } catch (Throwable $e) {
            if ($this->panelDb->inTransaction()) $this->panelDb->rollBack();
            throw $e;
        }
    }

    public function auditRows(int $lineId): array
    {
        $st = $this->panelDb->prepare("SELECT id,admin_user_id,action,ip_address,metadata_json,created_at FROM audit_logs WHERE entity_type='line' AND entity_id=? ORDER BY id DESC LIMIT 300");
        $st->execute([(string)$lineId]);
        return $st->fetchAll();
    }
}
