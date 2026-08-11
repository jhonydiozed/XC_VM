<?php

declare(strict_types=1);

final class LineService
{
    public function __construct(private LineRepository $repo) {}

    public function normalizeInput(array $input): array
    {
        $username = trim((string)($input['username'] ?? ''));
        $password = trim((string)($input['password'] ?? ''));
        if ($username === '' || $password === '') throw new RuntimeException('Usuário e senha são obrigatórios.');
        if (mb_strlen($username) > 255 || mb_strlen($password) > 255) throw new RuntimeException('Usuário ou senha excede 255 caracteres.');

        $exp = null;
        $expRaw = trim((string)($input['exp_date'] ?? ''));
        if ($expRaw !== '') {
            $dt = DateTime::createFromFormat('Y-m-d\TH:i', $expRaw);
            if (!$dt) throw new RuntimeException('Data de expiração inválida.');
            $exp = $dt->getTimestamp();
        }

        return [
            'member_id' => max(0, (int)($input['member_id'] ?? 0)),
            'username' => $username,
            'password' => $password,
            'exp_date' => $exp,
            'admin_enabled' => isset($input['admin_enabled']) ? 1 : 0,
            'enabled' => isset($input['enabled']) ? 1 : 0,
            'admin_notes' => trim((string)($input['admin_notes'] ?? '')),
            'max_connections' => max(1, min(10000, (int)($input['max_connections'] ?? 1))),
            'is_restreamer' => isset($input['is_restreamer']) ? 1 : 0,
            'is_trial' => isset($input['is_trial']) ? 1 : 0,
            'contact' => trim((string)($input['contact'] ?? '')),
        ];
    }

    public function create(array $input): int
    {
        $data = $this->normalizeInput($input);
        if ($this->repo->usernameExists($data['username'])) throw new RuntimeException('Já existe uma linha com este usuário.');
        return $this->repo->create($data);
    }

    public function update(int $id, array $input): array
    {
        $before = $this->repo->find($id);
        if (!$before) throw new RuntimeException('Linha não encontrada.');
        $data = $this->normalizeInput($input);
        if ($this->repo->usernameExists($data['username'], $id)) throw new RuntimeException('Já existe outra linha com este usuário.');
        $this->repo->update($id, $data);
        return ['before'=>$before,'after'=>$this->repo->find($id)];
    }

    public function softDelete(int $id, ?int $adminUserId): array
    {
        $line = $this->repo->find($id);
        if (!$line) throw new RuntimeException('Linha não encontrada.');
        $snapshot = $this->repo->createDeleteSnapshot($line, $adminUserId);
        try {
            $this->repo->softDelete($id);
        } catch (Throwable $e) {
            throw new RuntimeException('Snapshot criado, mas o bloqueio no engine falhou: '.$e->getMessage(), 0, $e);
        }
        return ['line'=>$line] + $snapshot;
    }

    public function restore(int $trashId): array
    {
        $trash = $this->repo->trashItem($trashId);
        if (!$trash) throw new RuntimeException('Item da lixeira não encontrado ou já restaurado.');
        $snapshot = json_decode((string)$trash['snapshot_json'], true, 512, JSON_THROW_ON_ERROR);
        $lineId = (int)$trash['line_id'];
        $line = $this->repo->find($lineId);
        if (!$line) throw new RuntimeException('A linha original não existe mais no engine; restauração física ainda não está habilitada.');
        $this->repo->restoreDeletionState($lineId, $snapshot);
        $this->repo->markRestored($trashId, isset($trash['batch_id']) ? (int)$trash['batch_id'] : null);
        return ['line_id'=>$lineId,'username'=>$snapshot['username'] ?? $trash['username'] ?? ''];
    }
}
