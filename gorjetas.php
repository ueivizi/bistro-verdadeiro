<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/sessao.php';
require_once __DIR__ . '/includes/mineracao.php';

iniciar_sessao_segura();
exigir_login();

$permitidas = operacoes_permitidas();
$padrao     = (string) array_key_first($permitidas);
$pedida     = (string) ($_GET['op'] ?? '');

if ($pedida !== '' && array_key_exists($pedida, OPERACOES) && !pode_operacao($pedida)) {
    definir_aviso('Seu perfil não tem acesso a essa análise.', 'erro');
    redirecionar('menu.php');
}

$operacao = pode_operacao($pedida) ? $pedida : $padrao;
$dia      = (string) ($_GET['dia'] ?? '');
$periodo  = (string) ($_GET['periodo'] ?? '');
$limite   = (int)    ($_GET['n'] ?? 10);

if (!in_array($dia, DIAS_VALIDOS, true)) {
    $dia = '';
}
if (!in_array($periodo, PERIODOS_VALIDOS, true)) {
    $periodo = '';
}

$resultado = null;
$erro      = null;

try {
    $execucao  = minerar($operacao, $dia, $periodo, $limite);
    $resultado = $execucao['dados'];
} catch (Throwable $e) {
    $erro = $e->getMessage();
}

$titulo = OPERACOES[$operacao];
require __DIR__ . '/includes/topo.php';
?>

<h1><?= h(OPERACOES[$operacao]) ?></h1>

<form class="filtros" method="get" action="gorjetas.php">
    <div>
        <label for="op">Análise</label>
        <select name="op" id="op">
            <?php foreach ($permitidas as $chave => $rotulo): ?>
                <option value="<?= h($chave) ?>" <?= $chave === $operacao ? 'selected' : '' ?>>
                    <?= h($rotulo) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div>
        <label for="dia">Dia</label>
        <select name="dia" id="dia">
            <option value="">Todos</option>
            <?php foreach (DIAS_VALIDOS as $d): ?>
                <option value="<?= h($d) ?>" <?= $d === $dia ? 'selected' : '' ?>>
                    <?= h(traduzir($d)) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div>
        <label for="periodo">Período</label>
        <select name="periodo" id="periodo">
            <option value="">Todos</option>
            <?php foreach (PERIODOS_VALIDOS as $p): ?>
                <option value="<?= h($p) ?>" <?= $p === $periodo ? 'selected' : '' ?>>
                    <?= h(traduzir($p)) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <?php if ($operacao === 'ranking'): ?>
        <div>
            <label for="n">Quantas linhas</label>
            <input type="number" id="n" name="n" min="1" max="50" value="<?= (int) $limite ?>">
        </div>
    <?php endif; ?>

    <button type="submit" class="botao">Minerar</button>
</form>

<?php if ($erro !== null): ?>

    <p class="aviso aviso-erro"><?= h($erro) ?></p>

<?php elseif ($resultado === null || $resultado === []): ?>

    <p class="aviso aviso-aviso">
        Nenhum atendimento com esses filtros. O bistrô só serve almoço de quinta a sexta —
        tente outra combinação.
    </p>

<?php elseif ($operacao === 'maior' || $operacao === 'percentual'): ?>

    <?php
    $r          = $resultado;
    $emDestaque = $operacao === 'maior'
        ? moeda((float) $r['gorjeta'])
        : number_format((float) $r['percentual'], 2, ',', '.') . '%';
    ?>

    <section class="comanda" aria-label="Atendimento vencedor">
        <p class="comanda-rotulo">Mesa de <?= (int) $r['pessoas'] ?>
            · <?= h(traduzir((string) $r['periodo'])) ?>
            de <?= h(traduzir((string) $r['dia'])) ?></p>

        <p class="comanda-valor"><?= h($emDestaque) ?></p>
        <p class="comanda-legenda">
            <?= $operacao === 'maior'
                ? 'maior gorjeta da base'
                : 'a maior fatia deixada sobre uma conta' ?>
        </p>

        <dl class="comanda-linhas">
            <div><dt>Conta</dt><dd><?= h(moeda((float) $r['conta'])) ?></dd></div>
            <div><dt>Gorjeta</dt><dd><?= h(moeda((float) $r['gorjeta'])) ?></dd></div>
            <div><dt>Proporção</dt>
                 <dd><?= h(number_format((float) $r['percentual'], 2, ',', '.')) ?>%</dd></div>
            <div><dt>Cliente</dt><dd><?= h(traduzir((string) $r['sexo'])) ?></dd></div>
            <div><dt>Fumante</dt><dd><?= h(traduzir((string) $r['fumante'])) ?></dd></div>
            <div><dt>Pessoas à mesa</dt><dd><?= (int) $r['pessoas'] ?></dd></div>
        </dl>
    </section>

<?php elseif ($operacao === 'ranking'): ?>

    <table class="tabela">
        <caption>Atendimentos com as maiores gorjetas, do maior para o menor</caption>
        <thead>
            <tr>
                <th scope="col">#</th>
                <th scope="col">Gorjeta</th>
                <th scope="col">Conta</th>
                <th scope="col">Proporção</th>
                <th scope="col">Dia</th>
                <th scope="col">Período</th>
                <th scope="col">Pessoas</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($resultado as $i => $r): ?>
            <tr<?= $i === 0 ? ' class="primeira"' : '' ?>>
                <td><?= $i + 1 ?></td>
                <td class="numero forte"><?= h(moeda((float) $r['gorjeta'])) ?></td>
                <td class="numero"><?= h(moeda((float) $r['conta'])) ?></td>
                <td class="numero"><?= h(number_format((float) $r['percentual'], 2, ',', '.')) ?>%</td>
                <td><?= h(traduzir((string) $r['dia'])) ?></td>
                <td><?= h(traduzir((string) $r['periodo'])) ?></td>
                <td class="numero"><?= (int) $r['pessoas'] ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

<?php elseif ($operacao === 'dia'): ?>

    <?php
    $maiorMedia = 0.0;
    foreach ($resultado as $r) {
        $maiorMedia = max($maiorMedia, (float) $r['media']);
    }
    ?>

    <table class="tabela">
        <caption>Cada dia da semana em que o salão abriu</caption>
        <thead>
            <tr>
                <th scope="col">Dia</th>
                <th scope="col">Atendimentos</th>
                <th scope="col">Total em gorjetas</th>
                <th scope="col">Média por mesa</th>
                <th scope="col">Maior do dia</th>
                <th scope="col">Sobre a conta</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($resultado as $r): ?>
            <tr<?= (float) $r['media'] === $maiorMedia ? ' class="primeira"' : '' ?>>
                <th scope="row"><?= h(traduzir((string) $r['dia'])) ?></th>
                <td class="numero"><?= (int) $r['atendimentos'] ?></td>
                <td class="numero"><?= h(moeda((float) $r['soma'])) ?></td>
                <td class="numero forte"><?= h(moeda((float) $r['media'])) ?></td>
                <td class="numero"><?= h(moeda((float) $r['maior'])) ?></td>
                <td class="numero"><?= h(number_format((float) $r['percentual'], 2, ',', '.')) ?>%</td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

<?php elseif ($operacao === 'resumo'): ?>

    <dl class="resumo">
        <div><dt>Atendimentos analisados</dt><dd><?= (int) $resultado['atendimentos'] ?></dd></div>
        <div><dt>Total recebido em gorjetas</dt>
             <dd><?= h(moeda((float) $resultado['soma'])) ?></dd></div>
        <div><dt>Média por atendimento</dt>
             <dd><?= h(moeda((float) $resultado['media'])) ?></dd></div>
        <div><dt>Mediana</dt><dd><?= h(moeda((float) $resultado['mediana'])) ?></dd></div>
        <div><dt>Menor gorjeta</dt><dd><?= h(moeda((float) $resultado['minima'])) ?></dd></div>
        <div><dt>Maior gorjeta</dt><dd><?= h(moeda((float) $resultado['maxima'])) ?></dd></div>
    </dl>

    <p class="nota">
        A média fica acima da mediana: poucas gorjetas altas puxam o resultado para cima,
        enquanto a mesa comum deixa menos do que a média sugere.
    </p>

<?php endif; ?>

<?php if ($erro === null && pode('ver_terminal')): ?>
    <details class="terminal">
        <summary>Ver a saída do shell script</summary>

        <?php if ($execucao['origem'] === 'shell'): ?>
            <p class="terminal-nota">Comando executado pelo PHP:</p>
            <pre class="terminal-comando"><?= h($execucao['comando']) ?></pre>
        <?php endif; ?>

        <pre class="terminal-saida"><?= h($execucao['bruto']) ?></pre>
    </details>
<?php endif; ?>

<?php require __DIR__ . '/includes/rodape.php'; ?>
