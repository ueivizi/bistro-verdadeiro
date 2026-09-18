<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/dados.php';

iniciar_sessao_segura();
exigir_login();
exigir_permissao('ver_dados');

$filtros = normalizar_filtros($_GET);
$ordem   = coluna_de_ordem((string) ($_GET['ordem'] ?? 'n'));
$sentido = direcao_valida((string) ($_GET['sentido'] ?? 'asc'));
$porPag  = linhas_por_pagina($_GET['por'] ?? LINHAS_POR_PAGINA_PADRAO);
$pagina  = max(1, (int) ($_GET['p'] ?? 1));

$erro         = null;
$selecionados = [];
$totalBase    = 0;
$fatia        = ['linhas' => [], 'pagina' => 1, 'paginas' => 1, 'total' => 0,
                 'primeira' => 0, 'ultima' => 0, 'por_pagina' => $porPag];

try {
    $todos        = ler_base();
    $totalBase    = count($todos);
    $selecionados = ordenar_registros(filtrar_registros($todos, $filtros), $ordem, $sentido);
    $fatia        = paginar($selecionados, $pagina, $porPag);
} catch (Throwable $e) {
    $erro = mensagem_para_usuario($e);
}

// Parâmetros que precisam sobreviver a qualquer link desta tela.
$estado = array_filter(
    array_merge($filtros, ['ordem' => $ordem, 'sentido' => $sentido, 'por' => $porPag]),
    static fn($v): bool => $v !== '' && $v !== null
);

/** Cabeçalho de coluna que também é o botão de ordenar. */
function cabecalho_ordenavel(string $coluna, string $rotulo, string $ordem,
                             string $sentido, array $estado, string $classe = ''): string
{
    $ativa   = $ordem === $coluna;
    $proximo = ($ativa && $sentido === 'asc') ? 'desc' : 'asc';
    $seta    = $ativa ? ($sentido === 'asc' ? ' ↑' : ' ↓') : '';
    $url     = url_com('dados.php', $estado, ['ordem' => $coluna, 'sentido' => $proximo, 'p' => null]);

    return '<th scope="col" class="' . h($classe) . ($ativa ? ' ordenada' : '') . '">'
         . '<a class="ordenavel" data-vivo href="' . h($url) . '">' . h($rotulo) . $seta . '</a></th>';
}

$descricao = descrever_filtros($filtros);

$titulo = 'Dados';
require __DIR__ . '/includes/topo.php';
?>

<h1>Todos os atendimentos</h1>

<p class="intro">
    A base inteira, linha por linha, do jeito que o sistema a enxerga depois de
    conferir cada campo. Use os filtros para recortar o salão por cliente,
    fumante, dia, período, tamanho da mesa ou faixa de valores.
</p>

<?php if ($erro !== null): ?>
    <p class="aviso aviso-erro"><?= h($erro) ?></p>
<?php else: ?>

<form class="filtros filtros-grade" method="get" action="dados.php" data-vivo>
    <input type="hidden" name="ordem" value="<?= h($ordem) ?>">
    <input type="hidden" name="sentido" value="<?= h($sentido) ?>">

    <?php foreach (['sex', 'smoker', 'day', 'time'] as $campo): ?>
        <div>
            <label for="f-<?= h($campo) ?>"><?= h(COLUNAS_BASE[$campo]['rotulo']) ?></label>
            <select name="<?= h($campo) ?>" id="f-<?= h($campo) ?>">
                <option value="">Todos</option>
                <?php foreach (COLUNAS_BASE[$campo]['valores'] as $valor): ?>
                    <option value="<?= h($valor) ?>" <?= $filtros[$campo] === $valor ? 'selected' : '' ?>>
                        <?= h(traduzir($valor)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endforeach; ?>

    <?php foreach (FAIXAS_FILTRAVEIS as $campo => $rotulo): ?>
        <div class="faixa">
            <label for="f-<?= h($campo) ?>-min"><?= h($rotulo) ?></label>
            <div class="faixa-campos">
                <input type="number" step="any" inputmode="decimal" placeholder="de"
                       id="f-<?= h($campo) ?>-min" name="<?= h($campo) ?>_min"
                       value="<?= $filtros[$campo . '_min'] === null ? '' : h($filtros[$campo . '_min']) ?>">
                <span aria-hidden="true">–</span>
                <input type="number" step="any" inputmode="decimal" placeholder="até"
                       aria-label="<?= h($rotulo) ?>, valor máximo"
                       name="<?= h($campo) ?>_max"
                       value="<?= $filtros[$campo . '_max'] === null ? '' : h($filtros[$campo . '_max']) ?>">
            </div>
        </div>
    <?php endforeach; ?>

    <div>
        <label for="f-por">Linhas por página</label>
        <select name="por" id="f-por">
            <?php foreach (PAGINAS_VALIDAS as $quantas): ?>
                <option value="<?= (int) $quantas ?>" <?= $quantas === $porPag ? 'selected' : '' ?>>
                    <?= (int) $quantas ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="filtros-acoes">
        <button type="submit" class="botao">Filtrar</button>
        <a class="botao-vazado" href="dados.php">Limpar</a>
    </div>
</form>

<p class="contagem">
    <?php if ($fatia['total'] === 0): ?>
        Nenhum atendimento com esses filtros.
    <?php else: ?>
        Mostrando <strong><?= (int) $fatia['primeira'] ?>–<?= (int) $fatia['ultima'] ?></strong>
        de <strong><?= (int) $fatia['total'] ?></strong> atendimentos
        <?= $fatia['total'] === $totalBase ? '' : '(a base tem ' . (int) $totalBase . ')' ?>.
    <?php endif; ?>

    <?php if ($descricao !== []): ?>
        <span class="marcadores">
            <?php foreach ($descricao as $parte): ?>
                <span class="marcador"><?= h($parte) ?></span>
            <?php endforeach; ?>
        </span>
    <?php endif; ?>
</p>

<?php if ($fatia['total'] > 0): ?>

<?php if (pode('ver_analises')): ?>
    <p class="atalho-analise">
        <a class="botao-vazado" href="<?= h(url_com('analises.php', $filtros, ['fonte' => 'base', 'coluna' => 'tip'])) ?>">
            Analisar esta seleção →
        </a>
        <span>Leva os mesmos <?= $descricao === [] ? 'critérios' : 'filtros' ?> para a aba de Análises.</span>
    </p>
<?php endif; ?>

<div class="tabela-rolagem">
    <table class="tabela">
        <caption>Clique no título de uma coluna para ordenar por ela</caption>
        <thead>
            <tr>
                <?= cabecalho_ordenavel('n', '#', $ordem, $sentido, $estado) ?>
                <?= cabecalho_ordenavel('total_bill', 'Conta', $ordem, $sentido, $estado, 'col-num') ?>
                <?= cabecalho_ordenavel('tip', 'Gorjeta', $ordem, $sentido, $estado, 'col-num') ?>
                <?= cabecalho_ordenavel('percentual', 'Proporção', $ordem, $sentido, $estado, 'col-num') ?>
                <?= cabecalho_ordenavel('por_pessoa', 'Por pessoa', $ordem, $sentido, $estado, 'col-num') ?>
                <?= cabecalho_ordenavel('sex', 'Cliente', $ordem, $sentido, $estado) ?>
                <?= cabecalho_ordenavel('smoker', 'Fumante', $ordem, $sentido, $estado) ?>
                <?= cabecalho_ordenavel('day', 'Dia', $ordem, $sentido, $estado) ?>
                <?= cabecalho_ordenavel('time', 'Período', $ordem, $sentido, $estado) ?>
                <?= cabecalho_ordenavel('size', 'Pessoas', $ordem, $sentido, $estado, 'col-num') ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($fatia['linhas'] as $r): ?>
            <tr>
                <td class="numero discreto"><?= (int) $r['n'] ?></td>
                <td class="numero"><?= h(moeda((float) $r['total_bill'])) ?></td>
                <td class="numero forte"><?= h(moeda((float) $r['tip'])) ?></td>
                <td class="numero"><?= h(number_format((float) $r['percentual'], 2, ',', '.')) ?>%</td>
                <td class="numero"><?= h(moeda((float) $r['por_pessoa'])) ?></td>
                <td><?= h(traduzir((string) $r['sex'])) ?></td>
                <td><?= h(traduzir((string) $r['smoker'])) ?></td>
                <td><?= h(traduzir((string) $r['day'])) ?></td>
                <td><?= h(traduzir((string) $r['time'])) ?></td>
                <td class="numero"><?= (int) $r['size'] ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if ($fatia['paginas'] > 1): ?>
    <nav class="paginacao" aria-label="Páginas da tabela">
        <?php if ($fatia['pagina'] > 1): ?>
            <a data-vivo href="<?= h(url_com('dados.php', $estado, ['p' => $fatia['pagina'] - 1])) ?>">← Anterior</a>
        <?php else: ?>
            <span class="desligado">← Anterior</span>
        <?php endif; ?>

        <span class="paginacao-posicao">
            Página <?= (int) $fatia['pagina'] ?> de <?= (int) $fatia['paginas'] ?>
        </span>

        <?php if ($fatia['pagina'] < $fatia['paginas']): ?>
            <a data-vivo href="<?= h(url_com('dados.php', $estado, ['p' => $fatia['pagina'] + 1])) ?>">Próxima →</a>
        <?php else: ?>
            <span class="desligado">Próxima →</span>
        <?php endif; ?>
    </nav>
<?php endif; ?>

<p class="nota">
    <em>Proporção</em> e <em>por pessoa</em> não estão no arquivo: saem da conta e
    da gorjeta na hora da leitura. Linha do CSV que não passe na conferência de
    tipo, faixa ou lista de valores não entra nesta tabela.
</p>

<?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/includes/rodape.php'; ?>
