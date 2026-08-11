<?php if (isset($_GET['deleted'])): ?>
<div class="alert alert-success"><i class="bi bi-trash3 me-2"></i>Cliente movido para a lixeira. A exclusão pode ser revertida.</div>
<?php endif; ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div>
    <div class="fw-semibold">Gerenciamento de clientes</div>
    <div class="xds-muted">Crie, pesquise, edite, audite e remova linhas com recuperação.</div>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-danger" href="/line-trash"><i class="bi bi-trash3 me-1"></i>Lixeira</a>
    <a class="btn btn-success" href="/line-create"><i class="bi bi-person-plus me-1"></i>Adicionar Cliente</a>
  </div>
</div>
<form class="xds-filterbar mb-3">
  <input type="hidden" name="name" value="lines">
  <div class="row g-2 align-items-end">
    <div class="col-12 col-md-7"><label class="form-label">Pesquisar</label><input class="form-control" name="q" value="<?= e($q) ?>" placeholder="Usuário, IP ou contato"></div>
    <div class="col-5 col-md-2"><label class="form-label">Mostrar</label><select class="form-select" name="limit"><?php foreach([50,100,250] as $n): ?><option value="<?= $n ?>"<?= $limit===$n?' selected':'' ?>><?= $n ?></option><?php endforeach; ?></select></div>
    <div class="col-7 col-md-3"><button class="btn btn-primary w-100"><i class="bi bi-search me-1"></i>Pesquisar linhas</button></div>
  </div>
</form>
<div class="card xds-panel"><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover xds-op-table mb-0">
<thead><tr><th>ID</th><th>Usuário</th><th>Senha</th><th>Proprietário</th><th>Status</th><th>Online</th><th>Trial</th><th>Restreamer</th><th>Conexões</th><th>Expiração</th><th>Última conexão</th><th>Ações</th></tr></thead>
<tbody>
<?php foreach($lines as $r): $active=(int)$r['enabled']===1&&(int)$r['admin_enabled']===1; ?>
<tr>
<td><strong>#<?= e($r['id']) ?></strong></td>
<td><span class="main-cell"><?= e($r['username']) ?></span></td>
<td><code><?= e($r['password']) ?></code></td>
<td><?= e($r['member_id'] ?: '—') ?></td>
<td><?= statusDot($active,'Ativa','Bloqueada') ?></td>
<td><span class="badge text-bg-<?= (int)$r['online']>0?'success':'secondary' ?>"><?= e($r['online']) ?></span></td>
<td><?= badgeBool($r['is_trial']) ?></td>
<td><?= badgeBool($r['is_restreamer']) ?></td>
<td><?= e($r['max_connections']) ?></td>
<td><?= e(fmtTime((int)$r['exp_date'])) ?></td>
<td><?= e(fmtTime((int)$r['last_activity'])) ?></td>
<td><div class="btn-group btn-group-sm">
<a class="btn btn-primary" href="/line-edit?id=<?= e($r['id']) ?>" title="Editar"><i class="bi bi-pencil-square"></i></a>
<a class="btn btn-outline-info" href="/line-audit?id=<?= e($r['id']) ?>" title="Auditoria"><i class="bi bi-clock-history"></i></a>
<button class="btn btn-outline-danger" type="button" data-bs-toggle="modal" data-bs-target="#deleteLine<?= e($r['id']) ?>" title="Excluir"><i class="bi bi-trash3"></i></button>
</div>
<div class="modal fade" id="deleteLine<?= e($r['id']) ?>" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Mover para a lixeira</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body">O cliente <strong><?= e($r['username']) ?></strong> será bloqueado e removido da lista normal. Você poderá restaurá-lo depois.</div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><form method="post" action="/line-delete"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="id" value="<?= e($r['id']) ?>"><button class="btn btn-danger"><i class="bi bi-trash3 me-1"></i>Excluir</button></form></div></div></div></div>
</td>
</tr>
<?php endforeach; ?>
<?php if(!$lines): ?><tr><td colspan="12" class="text-center py-5 text-body-secondary">Nenhuma linha encontrada.</td></tr><?php endif; ?>
</tbody></table></div></div></div>
