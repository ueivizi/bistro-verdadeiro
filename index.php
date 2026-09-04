<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/sessao.php';
require_once __DIR__ . '/includes/tentativas.php';

iniciar_sessao_segura();

if (!empty($_SESSION['autenticado'])) {
    redirecionar('menu.php');
}

$motivos = [
    'login'       => ['Entre com seu usuário para acessar o painel.', 'aviso'],
    'inatividade' => ['Sessão encerrada por inatividade. Entre novamente.', 'aviso'],
    'expirada'    => ['Sessão expirada pelo tempo máximo. Entre novamente.', 'aviso'],
    'invalida'    => ['Sua sessão foi encerrada por segurança. Entre novamente.', 'erro'],
    'saiu'        => ['Você saiu do sistema.', 'ok'],
];

$aviso  = obter_aviso();
$motivo = (string) ($_GET['motivo'] ?? '');

if ($aviso === null && isset($motivos[$motivo])) {
    $aviso = ['texto' => $motivos[$motivo][0], 'tipo' => $motivos[$motivo][1]];
}

$bloqueadoAte = tentativas_bloqueio_ate();
$bloqueado    = $bloqueadoAte > time();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Entrar · <?= h(NOME_SISTEMA) ?></title>
<link rel="icon" href="assets/logo.svg" type="image/svg+xml">
<link rel="stylesheet" href="assets/estilo.css">
</head>
<body class="tela-login">

<main class="cartao-login">
    <img class="logo-login" src="assets/logo.svg" alt="" width="72" height="72">

    <h1 class="marca-nome grande"><?= h(NOME_SISTEMA) ?></h1>
    <p class="subtitulo">Painel de gorjetas do salão</p>

    <?php if ($aviso !== null): ?>
        <p class="aviso aviso-<?= h($aviso['tipo']) ?>"><?= h($aviso['texto']) ?></p>
    <?php endif; ?>

    <?php if ($bloqueado): ?>
        <p class="aviso aviso-erro">
            Muitas tentativas seguidas. Aguarde
            <?= (int) ceil(($bloqueadoAte - time()) / 60) ?> minuto(s) para tentar de novo.
        </p>
    <?php endif; ?>

    <form method="post" action="autenticar.php" autocomplete="off">
        <?= campo_csrf() ?>

        <label for="usuario">Usuário</label>
        <input type="text" id="usuario" name="usuario" required autofocus
               maxlength="40" spellcheck="false"
               <?= $bloqueado ? 'disabled' : '' ?>>

        <label for="senha">Senha</label>
        <input type="password" id="senha" name="senha" required maxlength="120"
               <?= $bloqueado ? 'disabled' : '' ?>>

        <button type="submit" class="botao" <?= $bloqueado ? 'disabled' : '' ?>>Entrar</button>
    </form>
</main>

</body>
</html>
