<?php

declare(strict_types=1);

require_once XDS_ROOT.'/app/Repositories/LineRepository.php';
require_once XDS_ROOT.'/app/Services/LineService.php';
require_once XDS_ROOT.'/app/Controllers/LinesController.php';

$lineRepository = new LineRepository($engineDb, $panelDb);
$lineService = new LineService($lineRepository);
$linesController = new LinesController($lineRepository, $lineService, $panelDb);

if ($path === '/module' && (string)($_GET['name'] ?? '') === 'lines') {
    $linesController->index();
}

match ($path) {
    '/line-create' => $linesController->create(),
    '/line-edit' => $linesController->edit(),
    '/line-audit' => $linesController->audit(),
    '/line-delete' => $linesController->delete(),
    '/line-trash' => $linesController->trash(),
    '/line-restore' => $linesController->restore(),
    default => null,
};
