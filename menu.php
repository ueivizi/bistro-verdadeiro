<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/sessao.php';
require_once __DIR__ . '/includes/mineracao.php';

iniciar_sessao_segura();
exigir_login();

function descricao_da_operacao(string $chave): string
{
    return match ($chave) {
        'maior'      => 'O atendimento que rendeu mais dinheiro em gorjeta.',
        'percentual' => 'Quem deixou a maior fatia da própria conta.',
        'ranking'    => 'As dez melhores noites do garçom, em ordem.',
        'dia'        => 'Qual dia da semana rende mais e quanto.',
        'resumo'     => 'Soma, média, mediana e extremos das gorjetas.',
        default      => '',
    };
}

$papel      = papel_atual();
$permitidas = operacoes_permitidas();
$destaque   = null;
$falha      = null;

try {
    $destaque = minerar(pode_operacao('maior') ? 'maior' : 'resumo')['dados'];
} catch (Throwable $e) {
    $falha = $e->getMessage();
}

$titulo = 'Início';
require __DIR__ . '/includes/topo.php';
?>

<h1>Olá, <?= h(explode(' ', (string) $_SESSION['nome'])[0]) ?>.</h1>
<p class="intro">
    O painel analisa os 244 atendimentos registrados pelo garçom do salão.
    Escolha o que você quer descobrir na base.
</p>

<p class="cartao-papel">
    <span class="cartao-papel-rotulo"><?= h($papel['rotulo']) ?></span>
    <span class="cartao-papel-texto"><?= h($papel['resumo']) ?></span>
</p>

<?php if ($falha !== null): ?>
    <p class="aviso aviso-erro"><?= h($falha) ?></p>
<?php elseif ($destaque !== null && pode_operacao('maior')): ?>
    <p class="destaque-linha">
        Maior gorjeta já registrada:
        <strong><?= h(moeda((float) $destaque['gorjeta'])) ?></strong>
        em uma conta de <?= h(moeda((float) $destaque['conta'])) ?>,
        <?= h(minusculo(traduzir((string) $destaque['periodo']))) ?>
        de <?= h(minusculo(traduzir((string) $destaque['dia']))) ?>.
    </p>
<?php elseif ($destaque !== null): ?>
    <p class="destaque-linha">
        O salão recebeu <strong><?= h(moeda((float) $destaque['soma'])) ?></strong>
        em gorjetas ao longo de <?= (int) $destaque['atendimentos'] ?> atendimentos,
        com média de <?= h(moeda((float) $destaque['media'])) ?> por mesa.
    </p>
<?php endif; ?>

<ul class="opcoes">
    <?php foreach ($permitidas as $chave => $rotulo): ?>
        <li>
            <a href="gorjetas.php?op=<?= h(urlencode($chave)) ?>">
                <span class="opcao-titulo"><?= h($rotulo) ?></span>
                <span class="opcao-texto"><?= h(descricao_da_operacao($chave)) ?></span>
            </a>
        </li>
    <?php endforeach; ?>

    <?php if (pode('ver_base')): ?>
        <li>
            <a href="base.php">
                <span class="opcao-titulo">Base de dados</span>
                <span class="opcao-texto">De onde vêm os números e o que cada coluna significa.</span>
            </a>
        </li>
    <?php endif; ?>
</ul>

<?php require __DIR__ . '/includes/rodape.php'; ?>
