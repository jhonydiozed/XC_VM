<?php
$isEdit = ($mode ?? 'create') === 'edit';
$expInput = '';
if (!empty($line['exp_date'])) {
    $expInput = is_numeric($line['exp_date']) ? date('Y-m-d\TH:i',(int)$line['exp_date']) : (string)$line['exp_date'];
}
$checked = fn($v) => ((int)$v===1?' checked':'');
?>
<?php if(!empty($error)): ?><div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i><?= e($error) ?></div><?php endif; ?>
<?php if(!empty($success)): ?><div class="alert alert-success"><i class="bi bi-check-circle me-2"></i><?= e($success) ?></div><?php endif; ?>
<form method="post" class="card xds-panel">
<div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2"><div><strong><?= $isEdit?'Linha #'.e($line['id']):'Adicionar Cliente / Linha' ?></strong><?php if($isEdit): ?><div class="xds-muted">Último IP: <?= e($line['last_ip'] ?? '—') ?> · Última atividade: <?= e(fmtTime((int)($line['last_activity'] ?? 0))) ?></div><?php endif; ?></div><div class="d-flex gap-2"><?php if($isEdit): ?><a class="btn btn-outline-info btn-sm" href="/line-audit?id=<?= e($line['id']) ?>"><i class="bi bi-clock-history me-1"></i>Auditoria</a><?php endif; ?><a class="btn btn-outline-secondary btn-sm" href="<?= e(moduleUrl('lines')) ?>"><i class="bi bi-arrow-left me-1"></i>Voltar</a></div></div>
<div class="card-body"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><?php if($isEdit): ?><input type="hidden" name="id" value="<?= e($line['id']) ?>"><?php endif; ?><div class="row g-3">
<div class="col-md-6"><label class="form-label">Usuário</label><input class="form-control" name="username" maxlength="255" required value="<?= e($line['username'] ?? '') ?>"></div>
<div class="col-md-6"><label class="form-label">Senha</label><input class="form-control" name="password" maxlength="255" required value="<?= e($line['password'] ?? '') ?>"></div>
<div class="col-md-4"><label class="form-label">Expiração</label><input class="form-control" type="datetime-local" name="exp_date" value="<?= e($expInput) ?>"></div>
<div class="col-md-4"><label class="form-label">Máx. Conexões</label><input class="form-control" type="number" min="1" max="10000" name="max_connections" value="<?= e($line['max_connections'] ?? 1) ?>"></div>
<div class="col-md-4"><label class="form-label">Proprietário / ID</label><input class="form-control" type="number" min="0" name="member_id" value="<?= e($line['member_id'] ?? 0) ?>"<?= $isEdit?' readonly':'' ?>></div>
<div class="col-12"><div class="row g-2">
<div class="col-6 col-lg-3"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="enabled" id="enabled"<?= $checked($line['enabled'] ?? 1) ?>><label class="form-check-label" for="enabled">Linha ativa</label></div></div>
<div class="col-6 col-lg-3"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="admin_enabled" id="admin_enabled"<?= $checked($line['admin_enabled'] ?? 1) ?>><label class="form-check-label" for="admin_enabled">Liberada pelo admin</label></div></div>
<div class="col-6 col-lg-3"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_trial" id="is_trial"<?= $checked($line['is_trial'] ?? 0) ?>><label class="form-check-label" for="is_trial">Trial</label></div></div>
<div class="col-6 col-lg-3"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_restreamer" id="is_restreamer"<?= $checked($line['is_restreamer'] ?? 0) ?>><label class="form-check-label" for="is_restreamer">Restreamer</label></div></div>
</div></div>
<div class="col-md-6"><label class="form-label">Contato</label><textarea class="form-control" name="contact" rows="3"><?= e($line['contact'] ?? '') ?></textarea></div>
<div class="col-md-6"><label class="form-label">Notas administrativas</label><textarea class="form-control" name="admin_notes" rows="3"><?= e($line['admin_notes'] ?? '') ?></textarea></div>
</div></div>
<div class="card-footer d-flex justify-content-end gap-2"><a href="<?= e(moduleUrl('lines')) ?>" class="btn btn-outline-secondary">Cancelar</a><button class="btn <?= $isEdit?'btn-primary':'btn-success' ?>"><i class="bi <?= $isEdit?'bi-save':'bi-person-plus' ?> me-1"></i><?= $isEdit?'Salvar alterações':'Criar cliente' ?></button></div>
</form>
