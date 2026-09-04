<?php

declare(strict_types=1);

if (empty($_SESSION['autenticado'])) {
    exit;
}

$titulo      = $titulo ?? NOME_SISTEMA;
$paginaAtual = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$papel       = papel_atual();
$analise     = (string) array_key_first(operacoes_permitidas());
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($titulo) ?> · <?= h(NOME_SISTEMA) ?></title>
<link rel="icon" href="assets/logo.svg" type="image/svg+xml">
<link rel="stylesheet" href="assets/estilo.css">
</head>
<body>

<header class="barra">
    <div class="barra-conteudo">
        <a class="marca" href="menu.php">
            <img class="marca-logo" src="assets/logo.svg" alt="" width="42" height="42">
            <span class="marca-texto">
                <span class="marca-nome"><?= h(NOME_SISTEMA) ?></span>
                <span class="marca-sub">painel de gorjetas</span>
            </span>
        </a>

        <div class="sessao">
            <span class="sessao-texto">
                <span class="sessao-usuario"><?= h($_SESSION['nome']) ?></span>
                <span class="sessao-papel"><?= h($papel['rotulo']) ?></span>
            </span>
            <form method="post" action="sair.php">
                <?= campo_csrf() ?>
                <button class="sair" type="submit">Sair</button>
            </form>
        </div>
    </div>

    <nav class="menu-topo" aria-label="Seções do sistema">
        <div class="menu-topo-conteudo">
            <a href="menu.php"<?= $paginaAtual === 'menu.php' ? ' aria-current="page"' : '' ?>>Início</a>
            <a href="gorjetas.php?op=<?= h(urlencode($analise)) ?>"<?= $paginaAtual === 'gorjetas.php' ? ' aria-current="page"' : '' ?>>Mineração</a>
            <?php if (pode('ver_base')): ?>
                <a href="base.php"<?= $paginaAtual === 'base.php' ? ' aria-current="page"' : '' ?>>Base de dados</a>
            <?php endif; ?>
        </div>
    </nav>
</header>

<main class="pagina">

<?php $avisoPendente = obter_aviso(); ?>
<?php if ($avisoPendente !== null): ?>
    <p class="aviso aviso-<?= h($avisoPendente['tipo']) ?>"><?= h($avisoPendente['texto']) ?></p>
<?php endif; ?>

