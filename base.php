<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/sessao.php';
require_once __DIR__ . '/includes/mineracao.php';

iniciar_sessao_segura();
exigir_login();
exigir_permissao('ver_base');

$amostra = [];
$total   = 0;
$erro    = null;

try {
    $registros = ler_registros(caminho_da_base(), '', '');
    $total     = count($registros);
    $amostra   = array_slice($registros, 0, 8);
} catch (Throwable $e) {
    $erro = $e->getMessage();
}

$colunas = [
    'total_bill' => 'Valor total da conta da mesa.',
    'tip'        => 'Gorjeta deixada pelo cliente.',
    'sex'        => 'Sexo de quem pagou a conta.',
    'smoker'     => 'Havia fumante na mesa.',
    'day'        => 'Dia da semana do atendimento.',
    'time'       => 'Almoço ou jantar.',
    'size'       => 'Quantas pessoas estavam à mesa.',
];

$titulo = 'Base de dados';
require __DIR__ . '/includes/topo.php';
?>

<h1>A base</h1>

<p class="intro">
    O arquivo <code>dados/gorjetas.csv</code> guarda <?= (int) $total ?> atendimentos
    anotados por um garçom ao longo de alguns meses. É a base de gorjetas usada em
    estatística desde os anos 1990, e cada linha é uma mesa fechada.
</p>

<?php if ($erro !== null): ?>
    <p class="aviso aviso-erro"><?= h($erro) ?></p>
<?php else: ?>

    <h2>O que cada coluna guarda</h2>
    <dl class="colunas">
        <?php foreach ($colunas as $nome => $texto): ?>
            <div><dt><code><?= h($nome) ?></code></dt><dd><?= h($texto) ?></dd></div>
        <?php endforeach; ?>
    </dl>

    <h2>Primeiras linhas</h2>
    <table class="tabela">
        <thead>
            <tr>
                <th scope="col">Conta</th>
                <th scope="col">Gorjeta</th>
                <th scope="col">Cliente</th>
                <th scope="col">Fumante</th>
                <th scope="col">Dia</th>
                <th scope="col">Período</th>
                <th scope="col">Pessoas</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($amostra as $r): ?>
            <tr>
                <td class="numero"><?= h(moeda((float) $r['conta'])) ?></td>
                <td class="numero"><?= h(moeda((float) $r['gorjeta'])) ?></td>
                <td><?= h(traduzir((string) $r['sexo'])) ?></td>
                <td><?= h(traduzir((string) $r['fumante'])) ?></td>
                <td><?= h(traduzir((string) $r['dia'])) ?></td>
                <td><?= h(traduzir((string) $r['periodo'])) ?></td>
                <td class="numero"><?= (int) $r['pessoas'] ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <p class="nota">
        A leitura do arquivo é feita com <code>fgetcsv()</code>, que respeita as aspas
        dos campos de texto. Nas análises, quem faz esse trabalho é o
        <code>tr</code> junto do <code>awk</code>, dentro do shell script.
    </p>

<?php endif; ?>

<?php require __DIR__ . '/includes/rodape.php'; ?>
