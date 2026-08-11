<?php

declare(strict_types=1);

final class LinesController
{
    public function __construct(
        private LineRepository $repo,
        private LineService $service,
        private PDO $panelDb,
    ) {}

    private function view(string $name, array $vars = []): string
    {
        extract($vars, EXTR_SKIP);
        ob_start();
        require XDS_ROOT.'/app/Views/lines/'.$name.'.php';
        return (string)ob_get_clean();
    }

    public function index(): never
    {
        $q = trim((string)($_GET['q'] ?? ''));
        $limit = min(250, max(25, (int)($_GET['limit'] ?? 100)));
        $lines = $this->repo->list($q, $limit);
        audit($this->panelDb, 'view_module', 'module', 'lines', ['limit'=>$limit]);
        render('Linhas', $this->view('index', compact('lines','q','limit')), 'Assinaturas e credenciais de clientes');
        exit;
    }

    public function create(): never
    {
        $error = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                requireCsrf();
                $id = $this->service->create($_POST);
                audit($this->panelDb, 'line_create', 'line', (string)$id, ['username'=>$_POST['username'] ?? '']);
                xdsLog('info', 'Linha criada', ['line_id'=>$id,'user'=>currentUser()['username'] ?? null]);
                header('Location: /line-edit?id='.$id.'&created=1');
                exit;
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }
        $defaults = [
            'username'=>'u'.bin2hex(random_bytes(4)),
            'password'=>bin2hex(random_bytes(5)),
            'exp_date'=>date('Y-m-d\TH:i', time()+30*86400),
            'max_connections'=>1,
            'member_id'=>0,
            'enabled'=>1,
            'admin_enabled'=>1,
            'is_trial'=>0,
            'is_restreamer'=>0,
            'contact'=>'',
            'admin_notes'=>'',
        ];
        render('Adicionar Cliente', $this->view('form', ['mode'=>'create','line'=>$defaults,'error'=>$error]), 'Nova linha de acesso no XC_VM');
        exit;
    }

    public function edit(): never
    {
        $id = max(0, (int)($_GET['id'] ?? $_POST['id'] ?? 0));
        $line = $this->repo->find($id);
        if (!$line) { http_response_code(404); render('Editar Linha','<div class="alert alert-warning">Linha não encontrada.</div>'); exit; }
        $error = '';
        $success = isset($_GET['created']) ? 'Cliente criado com sucesso.' : '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                requireCsrf();
                $result = $this->service->update($id, $_POST);
                audit($this->panelDb, 'line_update', 'line', (string)$id, ['before'=>$result['before'],'after'=>$result['after']]);
                xdsLog('info', 'Linha atualizada', ['line_id'=>$id,'user'=>currentUser()['username'] ?? null]);
                $line = $result['after'];
                $success = 'Linha atualizada com sucesso.';
            } catch (Throwable $e) {
                $error = $e->getMessage();
                $line = array_merge($line, $_POST);
            }
        }
        render('Editar Linha', $this->view('form', ['mode'=>'edit','line'=>$line,'error'=>$error,'success'=>$success]), 'Alterações gravadas diretamente no engine XC_VM e auditadas pelo XDS');
        exit;
    }

    public function audit(): never
    {
        $id = max(0, (int)($_GET['id'] ?? 0));
        $line = $this->repo->find($id);
        if (!$line) { http_response_code(404); render('Auditoria da Linha','<div class="alert alert-warning">Linha não encontrada.</div>'); exit; }
        $logs = $this->repo->auditRows($id);
        audit($this->panelDb, 'view_line_audit', 'line', (string)$id);
        render('Auditoria da Linha', $this->view('audit', compact('line','logs')), 'Histórico administrativo da linha #'.$id);
        exit;
    }

    public function delete(): never
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
        requireCsrf();
        $id = max(0, (int)($_POST['id'] ?? 0));
        $result = $this->service->softDelete($id, currentUser()['id'] ?? null);
        audit($this->panelDb, 'line_delete_reversible', 'line', (string)$id, ['trash_id'=>$result['trash_id'],'batch_id'=>$result['batch_id'],'username'=>$result['line']['username'] ?? '']);
        xdsLog('info', 'Linha movida para lixeira', ['line_id'=>$id,'trash_id'=>$result['trash_id']]);
        header('Location: /module?name=lines&deleted=1');
        exit;
    }

    public function trash(): never
    {
        $items = $this->repo->trashItems();
        render('Lixeira de Linhas', $this->view('trash', compact('items')), 'Clientes removidos de forma reversível');
        exit;
    }

    public function restore(): never
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
        requireCsrf();
        $trashId = max(0, (int)($_POST['trash_id'] ?? 0));
        $result = $this->service->restore($trashId);
        audit($this->panelDb, 'line_restore', 'line', (string)$result['line_id'], ['trash_id'=>$trashId,'username'=>$result['username']]);
        xdsLog('info', 'Linha restaurada da lixeira', ['line_id'=>$result['line_id'],'trash_id'=>$trashId]);
        header('Location: /line-trash?restored=1');
        exit;
    }
}
