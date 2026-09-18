<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/dados.php';
require_once __DIR__ . '/includes/estatistica.php';
require_once __DIR__ . '/includes/grafico.php';

iniciar_sessao_segura();
exigir_login();
exigir_permissao('ver_graficos');

$filtros   = filtros_do_papel($_GET);
$descricao = descrever_filtros($filtros);

// Mesma regra da aba de análises: quem não vê mesa individual não agrupa por
// sexo do cliente nem por fumante.
$agrupaveis = pode('ver_dados') ? ['day', 'time', 'sex', 'smoker'] : ['day', 'time'];

$categoria = (string) ($_GET['categoria'] ?? 'day');
if (!in_array($categoria, $agrupaveis, true)) {
    $categoria = 'day';
}

$coluna = (string) ($_GET['coluna'] ?? 'tip');
if (!array_key_exists($coluna, COLUNAS_NUMERICAS)) {
    $coluna = 'tip';
}

$faixas = (int) ($_GET['faixas'] ?? 12);
$faixas = max(3, min(30, $faixas));

$erro         = null;
$selecionados = [];

try {
    $selecionados = filtrar_registros(ler_base(), $filtros);
} catch (Throwable $e) {
    $erro = $e->getMessage();
}

$formatos = [
    'moeda'    => static fn(float $v): string => moeda(round($v, 2)),
    'porcento' => static fn(float $v): string => number_format($v, 1, ',', '.') . '%',
    'numero'   => static fn(float $v): string => number_format($v, 1, ',', '.'),
];

$formatarColuna = $formatos[COLUNAS_NUMERICAS[$coluna]['formato']];
$formatarMoeda  = $formatos['moeda'];

$titulo = 'Gráficos';
require __DIR__ . '/includes/topo.php';
?>

<h1>Gráficos</h1>

<p class="intro">
    Os mesmos atendimentos, desenhados. Os três gráficos leem o recorte dos
    filtros abaixo — mexa neles e as três figuras se refazem juntas.
</p>

<?php if ($erro !== null): ?>
    <p class="aviso aviso-erro"><?= h($erro) ?></p>
<?php else: ?>

<form class="filtros filtros-grade" method="get" action="graficos.php">
    <div>
        <label for="categoria">Comparar por</label>
        <select name="categoria" id="categoria">
            <?php foreach ($agrupaveis as $chave): ?>
                <option value="<?= h($chave) ?>" <?= $chave === $categoria ? 'selected' : '' ?>>
                    <?= h(COLUNAS_BASE[$chave]['rotulo']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div>
        <label for="coluna">Medida</label>
        <select name="coluna" id="coluna">
            <?php foreach (COLUNAS_NUMERICAS as $chave => $meta): ?>
                <option value="<?= h($chave) ?>" <?= $chave === $coluna ? 'selected' : '' ?>>
                    <?= h($meta['rotulo']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <?php foreach ($agrupaveis as $campo): ?>
        <div>
            <label for="g-<?= h($campo) ?>"><?= h(COLUNAS_BASE[$campo]['rotulo']) ?></label>
            <select name="<?= h($campo) ?>" id="g-<?= h($campo) ?>">
                <option value="">Todos</option>
                <?php foreach (COLUNAS_BASE[$campo]['valores'] as $valor): ?>
                    <option value="<?= h($valor) ?>" <?= $filtros[$campo] === $valor ? 'selected' : '' ?>>
                        <?= h(traduzir($valor)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endforeach; ?>

    <div>
        <label for="faixas">Faixas do histograma</label>
        <input type="number" id="faixas" name="faixas" min="3" max="30" value="<?= (int) $faixas ?>">
    </div>

    <div class="filtros-acoes">
        <button type="submit" class="botao">Desenhar</button>
        <a class="botao-vazado" href="graficos.php">Limpar</a>
    </div>
</form>

<p class="contagem">
    <?php if ($selecionados === []): ?>
        Nenhum atendimento com esses filtros — não há o que desenhar.
    <?php else: ?>
        <strong><?= count($selecionados) ?></strong> atendimentos nos três gráficos.
    <?php endif; ?>

    <?php if ($descricao !== []): ?>
        <span class="marcadores">
            <?php foreach ($descricao as $parte): ?>
                <span class="marcador"><?= h($parte) ?></span>
            <?php endforeach; ?>
        </span>
    <?php endif; ?>
</p>

<?php if ($selecionados !== []): ?>

    <?php
    $grupos = contar_por($selecionados, $categoria);
    $itens  = [];
    $serie  = serie_de_numeros(valores_da_coluna($selecionados, $coluna));

    foreach ($grupos as $valor => $grupo) {
        if ($grupo['atendimentos'] === 0) {
            continue;
        }

        $daCategoria = array_values(array_filter(
            $selecionados,
            static fn(array $r): bool => $r[$categoria] === $valor
        ));

        $valores = valores_da_coluna($daCategoria, $coluna);
        $media   = array_sum($valores) / count($valores);

        $itens[] = [
            'rotulo'  => traduzir((string) $valor),
            'valor'   => $media,
            'nota'    => $grupo['atendimentos'] . ' atendimento'
                       . ($grupo['atendimentos'] === 1 ? '' : 's'),
            'quantos' => $grupo['atendimentos'],
        ];
    }
    ?>

    <h2><?= h(COLUNAS_NUMERICAS[$coluna]['rotulo']) ?> média por
        <?= h(minusculo(COLUNAS_BASE[$categoria]['rotulo'])) ?></h2>

    <figure class="figura">
        <?= grafico_barras(
            $itens,
            COLUNAS_NUMERICAS[$coluna]['rotulo'] . ' média por '
                . minusculo(COLUNAS_BASE[$categoria]['rotulo']),
            $formatarColuna
        ) ?>
        <figcaption>
            Quantos atendimentos sustentam cada média vai escrito embaixo do nome.
            Média de poucas mesas balança muito; a barra não conta isso sozinha.
        </figcaption>
    </figure>

    <details class="tabela-equivalente">
        <summary>Ver como tabela</summary>
        <table class="tabela">
            <thead>
                <tr>
                    <th scope="col"><?= h(COLUNAS_BASE[$categoria]['rotulo']) ?></th>
                    <th scope="col">Atendimentos</th>
                    <th scope="col"><?= h(COLUNAS_NUMERICAS[$coluna]['rotulo']) ?> média</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($itens as $item): ?>
                    <tr>
                        <th scope="row"><?= h($item['rotulo']) ?></th>
                        <td class="numero"><?= (int) $item['quantos'] ?></td>
                        <td class="numero forte"><?= h($formatarColuna((float) $item['valor'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </details>

    <?php $histograma = grafico_histograma(
        $serie,
        $faixas,
        'Distribuição de ' . minusculo(COLUNAS_NUMERICAS[$coluna]['rotulo']),
        $formatarColuna
    ); ?>

    <h2>Como <?= h(minusculo(COLUNAS_NUMERICAS[$coluna]['rotulo'])) ?> se distribui</h2>

    <figure class="figura">
        <?= $histograma['svg'] ?>
        <figcaption>
            Cada barra é uma faixa de valor e a altura é quantos atendimentos
            caíram nela. É aqui que a cauda aparece: se a direita se estica fina
            e comprida, é ela que puxa a média para longe da mediana.
        </figcaption>
    </figure>

    <details class="tabela-equivalente">
        <summary>Ver como tabela</summary>
        <table class="tabela">
            <thead>
                <tr>
                    <th scope="col">Faixa</th>
                    <th scope="col">Atendimentos</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($histograma['faixas'] as $faixa): ?>
                    <tr>
                        <th scope="row">
                            <?= h($formatarColuna((float) $faixa['de'])) ?>
                            a <?= h($formatarColuna((float) $faixa['ate'])) ?>
                        </th>
                        <td class="numero"><?= (int) $faixa['quantos'] ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </details>

    <?php
    $pontos = array_map(
        static fn(array $r): array => ['x' => $r['total_bill'], 'y' => $r['tip']],
        $selecionados
    );

    $dispersao = grafico_dispersao($pontos, 'Conta', 'Gorjeta', $formatarMoeda, $formatarMoeda);
    ?>

    <h2>Gorjeta contra conta, mesa por mesa</h2>

    <?php if ($dispersao['svg'] === ''): ?>

        <p class="aviso aviso-aviso">
            Com esse recorte não sobrou variação suficiente para desenhar a nuvem —
            são poucos pontos, ou todos caem no mesmo valor.
        </p>

    <?php else: ?>

        <figure class="figura">
            <?= $dispersao['svg'] ?>
            <figcaption>
                Cada ponto é uma mesa fechada. A linha escura é a reta de mínimos
                quadrados que melhor acompanha a nuvem.
            </figcaption>
        </figure>

        <dl class="resumo">
            <div>
                <dt>Correlação (r de Pearson)</dt>
                <dd><?= h(number_format((float) $dispersao['correlacao'], 4, ',', '.')) ?></dd>
            </div>
            <div>
                <dt>A cada R$ 1,00 a mais na conta</dt>
                <dd>+<?= h(moeda(round((float) $dispersao['inclinacao'], 2))) ?> de gorjeta</dd>
            </div>
            <div>
                <dt>Onde a reta cruza o zero</dt>
                <dd><?= h(moeda(round((float) $dispersao['intercepto'], 2))) ?></dd>
            </div>
        </dl>

        <p class="nota">
            Um <em>r</em> perto de 1 diz que os pontos se arrumam bem em torno da
            reta; perto de 0, que conta e gorjeta pouco têm a ver uma com a outra.
            Ele mede o quanto a reta acerta, e não que uma coisa cause a outra —
            mesa grande gasta mais e costuma deixar mais, e isso já explicaria a
            subida sem nenhuma generosidade extra.
        </p>

    <?php endif; ?>

<?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/includes/rodape.php'; ?>
