<?php if(isset($_GET['restored'])): ?><div class="alert alert-success"><i class="bi bi-arrow-counterclockwise me-2"></i>Cliente restaurado com sucesso.</div><?php endif; ?>
<div class="d-flex justify-content-between align-items-center mb-3"><div><div class="fw-semibold">Lixeira de Linhas</div><div class="xds-muted">Clientes bloqueados pelo XDS e disponíveis para restauração.</div></div><a class="btn btn-outline-secondary" href="<?= e(moduleUrl('lines')) ?>"><i class="bi bi-arrow-left me-1"></i>Voltar para Linhas</a></div>
<div class="card xds-panel"><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover xds-op-table mb-0"><thead><tr><th>ID</th><th>Linha</th><th>Usuário</th><th>Excluído por</th><th>Data</th><th>Ações</th></tr></thead><tbody>
<?php foreach($items as $r): ?>
<tr><td><?= e($r['id']) ?></td><td>#<?= e($r['line_id']) ?></td><td><strong><?= e($r['username'] ?: '—') ?></strong></td><td><?= e($r['deleted_by'] ?: '—') ?></td><td><?= e($r['deleted_at']) ?></td><td><form method="post" action="/line-restore" class="d-inline"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="trash_id" value="<?= e($r['id']) ?>"><button class="btn btn-sm btn-success"><i class="bi bi-arrow-counterclockwise me-1"></i>Restaurar</button></form></td></tr>
<?php endforeach; ?>
<?php if(!$items): ?><tr><td colspan="6" class="text-center py-5 text-body-secondary">A lixeira está vazia.</td></tr><?php endif; ?>
</tbody></table></div></div></div>
