<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/dados.php';
require_once __DIR__ . '/includes/estatistica.php';

iniciar_sessao_segura();
exigir_login();
exigir_permissao('ver_analises');

// A caixa de valores digitados pode ser grande demais para caber numa URL,
// então o formulário manda por POST. O caminho do dataset continua chegando
// por GET, para o endereço da análise poder ser guardado e compartilhado.
$porPost  = $_SERVER['REQUEST_METHOD'] === 'POST';
$entrada  = $porPost ? $_POST : $_GET;
$avisoCsrf = false;

if ($porPost && !validar_csrf($_POST['csrf'] ?? null)) {
    $entrada   = [];
    $avisoCsrf = true;
}

/**
 * Quem não pode ver mesa individual também não pode recortar a média por sexo
 * do cliente ou por fumante — senão bastaria pedir a média de um grupo de um
 * para descobrir a linha. A conferência fica aqui, colada na leitura, e não
 * só no formulário.
 */
function filtros_do_papel(array $entrada): array
{
    $filtros = normalizar_filtros($entrada);

    if (pode('ver_dados')) {
        return $filtros;
    }

    foreach (['sex', 'smoker'] as $campo) {
        $filtros[$campo] = '';
    }

    foreach (array_keys(FAIXAS_FILTRAVEIS) as $campo) {
        $filtros[$campo . '_min'] = null;
        $filtros[$campo . '_max'] = null;
    }

    return $filtros;
}

function formatar_medida(?float $valor, string $formato, int $casas = 2): string
{
    if ($valor === null) {
        return '—';
    }

    return match ($formato) {
        'moeda'    => moeda(round($valor, $casas)),
        'porcento' => number_format($valor, $casas, ',', '.') . '%',
        default    => number_format($valor, $casas, ',', '.'),
    };
}

$fonte = ((string) ($entrada['fonte'] ?? 'base')) === 'digitados' ? 'digitados' : 'base';

$coluna = (string) ($entrada['coluna'] ?? 'tip');
if (!array_key_exists($coluna, COLUNAS_NUMERICAS)) {
    $coluna = 'tip';
}

$filtros  = filtros_do_papel($entrada);
$digitado = (string) ($entrada['valores'] ?? '');

$serie     = [];
$formato   = 'numero';
$origem    = '';
$recusados = [];
$cortou    = false;
$erro      = null;

try {
    if ($fonte === 'digitados') {
        $lido      = numeros_do_texto($digitado);
        $serie     = $lido['valores'];
        $recusados = $lido['recusados'];
        $cortou    = $lido['cortou'];
        $origem    = count($serie) . ' ' . (count($serie) === 1 ? 'valor digitado' : 'valores digitados');
    } else {
        $selecionados = filtrar_registros(ler_base(), $filtros);
        $serie        = serie_de_numeros(valores_da_coluna($selecionados, $coluna));
        $formato      = COLUNAS_NUMERICAS[$coluna]['formato'];
        $origem       = COLUNAS_NUMERICAS[$coluna]['rotulo'] . ' de '
                      . count($selecionados) . ' '
                      . (count($selecionados) === 1 ? 'atendimento' : 'atendimentos');
    }
} catch (Throwable $e) {
    $erro = $e->getMessage();
}

$medidas   = $erro === null ? medidas_de_posicao($serie) : null;
$descricao = $fonte === 'base' ? descrever_filtros($filtros) : [];

$camposCategoria = pode('ver_dados') ? ['sex', 'smoker', 'day', 'time'] : ['day', 'time'];

$titulo = 'Análises';
require __DIR__ . '/includes/topo.php';
?>

<h1>Análises</h1>

<p class="intro">
    Escolha de onde vêm os números — uma coluna da base, do jeito que os filtros
    a recortarem, ou uma lista digitada por você — e o painel calcula em cima
    exatamente daquela série.
</p>

<?php if ($avisoCsrf): ?>
    <p class="aviso aviso-erro">
        O formulário expirou antes de ser enviado. Ele foi recarregado limpo; refaça o pedido.
    </p>
<?php endif; ?>

<form class="analise-forma" method="post" action="analises.php">
    <?= campo_csrf() ?>

    <fieldset class="fontes">
        <legend>De onde vêm os valores</legend>

        <div class="fonte<?= $fonte === 'base' ? ' fonte-ativa' : '' ?>">
            <label class="fonte-cabeca">
                <input type="radio" name="fonte" value="base" <?= $fonte === 'base' ? 'checked' : '' ?>>
                <span>
                    <strong>Da base do bistrô</strong>
                    <small>Uma coluna dos atendimentos, recortada pelos filtros.</small>
                </span>
            </label>

            <div class="fonte-campos">
                <div>
                    <label for="coluna">Coluna</label>
                    <select name="coluna" id="coluna">
                        <?php foreach (COLUNAS_NUMERICAS as $chave => $meta): ?>
                            <option value="<?= h($chave) ?>" <?= $chave === $coluna ? 'selected' : '' ?>>
                                <?= h($meta['rotulo']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php foreach ($camposCategoria as $campo): ?>
                    <div>
                        <label for="a-<?= h($campo) ?>"><?= h(COLUNAS_BASE[$campo]['rotulo']) ?></label>
                        <select name="<?= h($campo) ?>" id="a-<?= h($campo) ?>">
                            <option value="">Todos</option>
                            <?php foreach (COLUNAS_BASE[$campo]['valores'] as $valor): ?>
                                <option value="<?= h($valor) ?>" <?= $filtros[$campo] === $valor ? 'selected' : '' ?>>
                                    <?= h(traduzir($valor)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endforeach; ?>

                <?php if (pode('ver_dados')): ?>
                    <?php foreach (FAIXAS_FILTRAVEIS as $campo => $rotulo): ?>
                        <div class="faixa">
                            <label for="a-<?= h($campo) ?>-min"><?= h($rotulo) ?></label>
                            <div class="faixa-campos">
                                <input type="number" step="any" inputmode="decimal" placeholder="de"
                                       id="a-<?= h($campo) ?>-min" name="<?= h($campo) ?>_min"
                                       value="<?= $filtros[$campo . '_min'] === null ? '' : h($filtros[$campo . '_min']) ?>">
                                <span aria-hidden="true">–</span>
                                <input type="number" step="any" inputmode="decimal" placeholder="até"
                                       aria-label="<?= h($rotulo) ?>, valor máximo"
                                       name="<?= h($campo) ?>_max"
                                       value="<?= $filtros[$campo . '_max'] === null ? '' : h($filtros[$campo . '_max']) ?>">
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="fonte<?= $fonte === 'digitados' ? ' fonte-ativa' : '' ?>">
            <label class="fonte-cabeca">
                <input type="radio" name="fonte" value="digitados" <?= $fonte === 'digitados' ? 'checked' : '' ?>>
                <span>
                    <strong>Valores que eu digitar</strong>
                    <small>Qualquer lista de números, venha ela da base ou não.</small>
                </span>
            </label>

            <div class="fonte-campos">
                <div class="campo-largo">
                    <label for="valores">Números</label>
                    <textarea id="valores" name="valores" rows="5"
                              placeholder="3,50  4,20  1,00&#10;ou um por linha&#10;ou 3.5, 4.2, 1.0"><?= h($digitado) ?></textarea>
                    <p class="ajuda-campo">
                        Separe por espaço, vírgula, ponto-e-vírgula ou quebra de linha.
                        Decimal com vírgula ou com ponto, os dois servem. Até
                        <?= (int) MAX_VALORES_DIGITADOS ?> valores.
                    </p>
                </div>
            </div>
        </div>
    </fieldset>

    <button type="submit" class="botao">Calcular</button>
</form>

<?php if ($erro !== null): ?>

    <p class="aviso aviso-erro"><?= h($erro) ?></p>

<?php elseif ($medidas === null): ?>

    <p class="aviso aviso-aviso">
        <?= $fonte === 'digitados'
            ? 'Nenhum número foi reconhecido no que você digitou.'
            : 'Nenhum atendimento sobrou depois dos filtros — não há o que calcular.' ?>
    </p>

<?php else: ?>

    <?php if ($recusados !== []): ?>
        <p class="aviso aviso-aviso">
            Ignorei o que não era número:
            <?php foreach ($recusados as $i => $r): ?><code><?= h($r) ?></code><?= $i < count($recusados) - 1 ? ', ' : '' ?><?php endforeach; ?>.
        </p>
    <?php endif; ?>

    <?php if ($cortou): ?>
        <p class="aviso aviso-aviso">
            A lista passou de <?= (int) MAX_VALORES_DIGITADOS ?> valores. Considerei só os primeiros.
        </p>
    <?php endif; ?>

    <section class="medida-destaque" aria-label="Média">
        <p class="medida-rotulo">Média</p>
        <p class="medida-valor"><?= h(formatar_medida($medidas['media'], $formato)) ?></p>
        <p class="medida-legenda">
            soma de <?= h(formatar_medida($medidas['soma'], $formato)) ?>
            dividida por <?= (int) $medidas['contagem'] ?>
            <?= $medidas['contagem'] === 1 ? 'valor' : 'valores' ?>
        </p>
    </section>

    <p class="contagem">
        Calculado sobre <strong><?= h($origem) ?></strong>.
        <?php if ($descricao !== []): ?>
            <span class="marcadores">
                <?php foreach ($descricao as $parte): ?>
                    <span class="marcador"><?= h($parte) ?></span>
                <?php endforeach; ?>
            </span>
        <?php endif; ?>
    </p>

    <h2>As outras medidas de posição</h2>

    <dl class="resumo">
        <div><dt>Quantos valores</dt><dd><?= (int) $medidas['contagem'] ?></dd></div>
        <div><dt>Soma</dt><dd><?= h(formatar_medida($medidas['soma'], $formato)) ?></dd></div>
        <div><dt>Média</dt><dd><?= h(formatar_medida($medidas['media'], $formato)) ?></dd></div>
        <div><dt>Mediana</dt><dd><?= h(formatar_medida($medidas['mediana'], $formato)) ?></dd></div>
        <div>
            <dt>Moda</dt>
            <dd>
                <?php if ($medidas['moda']['valores'] === []): ?>
                    nenhum valor se repete
                <?php else: ?>
                    <?php $partes = array_map(
                        static fn(float $v): string => formatar_medida($v, $formato),
                        $medidas['moda']['valores']
                    ); ?>
                    <?= h(implode(' · ', $partes)) ?>
                    <small>(<?= (int) $medidas['moda']['vezes'] ?>×)</small>
                <?php endif; ?>
            </dd>
        </div>
        <div><dt>Menor</dt><dd><?= h(formatar_medida($medidas['minimo'], $formato)) ?></dd></div>
        <div><dt>Maior</dt><dd><?= h(formatar_medida($medidas['maximo'], $formato)) ?></dd></div>
        <div><dt>Amplitude</dt><dd><?= h(formatar_medida($medidas['amplitude'], $formato)) ?></dd></div>
    </dl>

    <?php if ($fonte === 'digitados' || pode('ver_dados')): ?>
        <details class="serie">
            <summary>Ver os <?= (int) $medidas['contagem'] ?> valores que entraram na conta</summary>
            <p class="serie-valores">
                <?php foreach ($serie as $valor): ?>
                    <span><?= h(formatar_medida($valor, $formato)) ?></span>
                <?php endforeach; ?>
            </p>
        </details>
    <?php endif; ?>

    <p class="nota">
        A média é sensível a valor extremo: uma única gorjeta muito alta puxa ela
        para cima sem mexer na mediana. Quando as duas se afastam, é sinal de que
        a base tem cauda — compare sempre as duas antes de concluir alguma coisa.
    </p>

<?php endif; ?>

<?php require __DIR__ . '/includes/rodape.php'; ?>
