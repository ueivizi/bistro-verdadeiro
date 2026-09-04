<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/sessao.php';

iniciar_sessao_segura();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validar_csrf($_POST['csrf'] ?? null)) {
    redirecionar('menu.php');
}

encerrar_sessao();
redirecionar('index.php?motivo=saiu');
