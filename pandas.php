<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/pandas.php';

iniciar_sessao_segura();
exigir_login();
exigir_permissao('ver_pandas');

/**
 * As análises em Python, dentro do navegador.
 *
 * Esta tela não calcula nada: ela chama scripts/analise_pandas.py uma vez, com
 * a operação "tudo", e desenha o JSON que volta. A saída de texto do mesmo
 * comando fica à mostra no fim da página, junto do comando que a gerou, para
 * dar para conferir que a conta veio mesmo do pandas.
 */

$permitidos = agrupamentos_permitidos();

$grupo  = agrupamento_valido((string) ($_GET['g'] ?? 'dia_da_semana'));
$medida = medida_valida((string) ($_GET['m'] ?? 'gorjeta'));
$linhas = (int) ($_GET['n'] ?? 5);
$linhas = max(3, min(20, $linhas));

$erro     = null;
$execucao = null;

try {
    $execucao = analisar_com_pandas('tudo', $grupo, $medida, $linhas);
} catch (Throwable $e) {
    $erro = mensagem_para_usuario($e);
}

/** Formata um número do jeito que a medida pede. */
function formatar_pandas(mixed $valor, string $formato, int $casas = 2): string
{
    if ($valor === null || !is_numeric($valor)) {
        return '—';
    }

    return match ($formato) {
        'moeda'    => moeda(round((float) $valor, $casas)),
        'porcento' => number_format((float) $valor, $casas, ',', '.') . '%',
        default    => number_format((float) $valor, $casas, ',', '.'),
    };
}

/** Os nomes que o describe() do pandas usa, em português. */
function nome_da_medida(string $chave): string
{
    return match ($chave) {
        'count' => 'Atendimentos',
        'mean'  => 'Média',
        'std'   => 'Desvio padrão',
        'min'   => 'Mínimo',
        '25%'   => '1º quartil (25%)',
        '50%'   => 'Mediana (50%)',
        '75%'   => '3º quartil (75%)',
        'max'   => 'Máximo',
        default => $chave,
    };
}

function rotulo_da_coluna(string $chave): string
{
    return MEDIDAS_PANDAS[$chave]['rotulo']
        ?? AGRUPAMENTOS_PANDAS[$chave]['rotulo']
        ?? $chave;
}

/** Endereço de uma figura, com o recorte atual embutido. */
function url_da_figura(string $tipo, string $grupo, string $medida): string
{
    return 'figura.php?' . http_build_query(['fig' => $tipo, 'g' => $grupo, 'm' => $medida]);
}

$comPandas = $execucao !== null && $execucao['origem'] === 'pandas';

$titulo = 'Pandas';
require __DIR__ . '/includes/topo.php';
?>

<h1>Análise com pandas</h1>

<p class="intro">
    Os mesmos atendimentos, agora lidos por um programa em Python. O PHP não
    refaz nenhuma conta desta tela: ele chama
    <code>scripts/analise_pandas.py</code>, recebe o resultado em JSON e
    formata. Quem agrupa, descreve e correlaciona é o
    <strong>pandas</strong>; quem desenha as figuras é o
    <strong>matplotlib</strong>.
</p>

<?php if ($erro !== null): ?>

    <p class="aviso aviso-erro"><?= h($erro) ?></p>

<?php else: ?>

    <p class="cartao-papel">
        <span class="cartao-papel-rotulo">
            <?= $comPandas ? 'pandas ' . h((string) $execucao['versao']) : 'plano B em PHP' ?>
        </span>
        <span class="cartao-papel-texto">
            <?php if ($comPandas): ?>
                O Python respondeu e os números desta tela saíram dele.
            <?php else: ?>
                Nenhum python com pandas respondeu nesta máquina, então os mesmos
                agrupamentos foram refeitos em PHP para a tela não quebrar. As
                figuras do matplotlib ficam de fora — elas só existem com o Python.
            <?php endif; ?>
        </span>
    </p>

    <?php
    $periodo    = $execucao['dados']['periodo'];
    $porGrupo   = $execucao['dados']['grupo'];
    $visao      = $execucao['dados']['visao'];
    $correlacao = $execucao['dados']['correlacao'];
    $comparacao = $periodo['comparacao'] ?? null;

    // O r de conta contra gorjeta, para a legenda da nuvem citar o mesmo
    // número que está na tabela de correlação.
    $rContaGorjeta = null;

    foreach ($correlacao['pares'] as $par) {
        if (in_array($par['a'], ['conta', 'gorjeta'], true)
            && in_array($par['b'], ['conta', 'gorjeta'], true)) {
            $rContaGorjeta = (float) $par['r'];
            break;
        }
    }
    ?>

    <!-- ------------------------------------------------------------------ -->
    <!-- A quinta funcionalidade da atividade                                -->
    <!-- ------------------------------------------------------------------ -->

    <h2>Período × valor médio da conta</h2>

    <p class="intro">
        O agrupamento que a atividade pede: os atendimentos separados por
        período e o valor médio da conta em cada um. Em pandas é uma linha —
        <code>df.groupby("periodo")["conta"].mean()</code> — e é ela que
        responde qual turno fatura mais por mesa.
    </p>

    <div class="medidas-destaque">
        <?php foreach ($periodo['grupos'] as $indice => $g): ?>
            <section class="medida-destaque<?= $indice > 0 ? ' medida-secundaria' : '' ?>"
                     aria-label="<?= h($g['rotulo']) ?>">
                <p class="medida-rotulo"><?= h($g['rotulo']) ?></p>
                <p class="medida-valor"><?= h(formatar_pandas($g['media'], 'moeda')) ?></p>
                <p class="medida-legenda">
                    média de <?= (int) $g['atendimentos'] ?> mesas ·
                    mediana <?= h(formatar_pandas($g['mediana'], 'moeda')) ?> ·
                    soma <?= h(formatar_pandas($g['soma'], 'moeda')) ?>
                </p>
            </section>
        <?php endforeach; ?>
    </div>

    <?php if ($comparacao !== null): ?>
        <p class="destaque-linha">
            O <strong><?= h(minusculo($comparacao['maior'])) ?></strong> rende
            <strong><?= h(formatar_pandas($comparacao['diferenca'], 'moeda')) ?></strong>
            a mais por mesa que o <?= h(minusculo($comparacao['menor'])) ?> —
            <?= h(formatar_pandas($comparacao['diferenca_pct'], 'porcento')) ?>
            de diferença sobre a conta média do turno mais fraco.
        </p>

        <p class="nota">
            Conta média maior não é lucro maior: o turno mais cheio pode ter
            mesa maior, prato mais caro ou simplesmente mais gente. A leitura
            útil é cruzar isto com os outros agrupamentos aqui embaixo —
            quantas pessoas sentaram, que dia era, quanto sobrou de gorjeta.
        </p>
    <?php endif; ?>

    <details class="tabela-equivalente">
        <summary>Ver como tabela</summary>
        <table class="tabela">
            <thead>
                <tr>
                    <th scope="col">Período</th>
                    <th scope="col">Atendimentos</th>
                    <th scope="col">Conta média</th>
                    <th scope="col">Mediana</th>
                    <th scope="col">Desvio padrão</th>
                    <th scope="col">Menor</th>
                    <th scope="col">Maior</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($periodo['grupos'] as $g): ?>
                    <tr>
                        <th scope="row"><?= h($g['rotulo']) ?></th>
                        <td class="numero"><?= (int) $g['atendimentos'] ?></td>
                        <td class="numero forte"><?= h(formatar_pandas($g['media'], 'moeda')) ?></td>
                        <td class="numero"><?= h(formatar_pandas($g['mediana'], 'moeda')) ?></td>
                        <td class="numero"><?= h(formatar_pandas($g['desvio'], 'moeda')) ?></td>
                        <td class="numero"><?= h(formatar_pandas($g['minimo'], 'moeda')) ?></td>
                        <td class="numero"><?= h(formatar_pandas($g['maximo'], 'moeda')) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </details>

    <!-- ------------------------------------------------------------------ -->
    <!-- Qualquer outro agrupamento                                          -->
    <!-- ------------------------------------------------------------------ -->

    <h2>Qualquer agrupamento, qualquer medida</h2>

    <p class="intro">
        O período é um caso do mesmo <code>groupby</code>. Troque a coluna que
        agrupa e a coluna medida e o programa em Python é chamado de novo com
        os dois nomes.
    </p>

    <form class="filtros filtros-grade" method="get" action="pandas.php" data-vivo>
        <div>
            <label for="g">Agrupar por</label>
            <select name="g" id="g">
                <?php foreach ($permitidos as $chave => $meta): ?>
                    <option value="<?= h($chave) ?>" <?= $chave === $grupo ? 'selected' : '' ?>>
                        <?= h($meta['rotulo']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label for="m">Medir</label>
            <select name="m" id="m">
                <?php foreach (MEDIDAS_PANDAS as $chave => $meta): ?>
                    <option value="<?= h($chave) ?>" <?= $chave === $medida ? 'selected' : '' ?>>
                        <?= h($meta['rotulo']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label for="n">Linhas do head e do top</label>
            <input type="number" id="n" name="n" min="3" max="20" value="<?= (int) $linhas ?>">
        </div>

        <div class="filtros-acoes">
            <button type="submit" class="botao">Calcular</button>
            <a class="botao-vazado" href="pandas.php">Limpar</a>
        </div>
    </form>

    <h3><?= h($porGrupo['medida_rotulo']) ?> por <?= h(minusculo($porGrupo['por_rotulo'])) ?></h3>

    <div class="tabela-rolagem">
        <table class="tabela">
            <thead>
                <tr>
                    <th scope="col"><?= h($porGrupo['por_rotulo']) ?></th>
                    <th scope="col">Atendimentos</th>
                    <th scope="col">Média</th>
                    <th scope="col">Mediana</th>
                    <th scope="col">Desvio padrão</th>
                    <th scope="col">Soma</th>
                    <th scope="col">Contra a média geral</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($porGrupo['grupos'] as $g): ?>
                    <tr>
                        <th scope="row"><?= h($g['rotulo']) ?></th>
                        <td class="numero"><?= (int) $g['atendimentos'] ?></td>
                        <td class="numero forte"><?= h(formatar_pandas($g['media'], $porGrupo['formato'])) ?></td>
                        <td class="numero"><?= h(formatar_pandas($g['mediana'], $porGrupo['formato'])) ?></td>
                        <td class="numero"><?= h(formatar_pandas($g['desvio'], $porGrupo['formato'])) ?></td>
                        <td class="numero"><?= h(formatar_pandas($g['soma'], $porGrupo['formato'])) ?></td>
                        <td class="numero">
                            <?= $g['contra_geral'] > 0 ? '+' : '' ?><?= h(formatar_pandas($g['contra_geral'], $porGrupo['formato'])) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th scope="row">Todos os atendimentos</th>
                    <td class="numero"><?= (int) $porGrupo['atendimentos'] ?></td>
                    <td class="numero forte"><?= h(formatar_pandas($porGrupo['media_geral'], $porGrupo['formato'])) ?></td>
                    <td colspan="4"></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <p class="nota">
        A última coluna é a distância de cada grupo até a média de todo o salão.
        É nela que o padrão aparece: grupo muito acima ou muito abaixo é o que
        vale investigar, e a quantidade de atendimentos ao lado diz se dá para
        confiar na diferença ou se ela é de poucas mesas.
    </p>

    <?php if ($comPandas): ?>
        <figure class="figura">
            <img src="<?= h(url_da_figura('grupo', $grupo, $medida)) ?>"
                 alt="Gráfico de barras com a média de <?= h(minusculo($porGrupo['medida_rotulo'])) ?> por <?= h(minusculo($porGrupo['por_rotulo'])) ?>, desenhado pelo matplotlib."
                 width="880" height="550" loading="lazy">
            <figcaption>
                Desenhado pelo <code>matplotlib</code> no servidor, com o backend
                <code>Agg</code> — o que grava a figura direto num PNG, sem abrir
                janela nenhuma. Se a imagem não aparecer, o Python não conseguiu
                desenhar; o motivo fica no log de erro do PHP.
            </figcaption>
        </figure>
    <?php endif; ?>

    <!-- ------------------------------------------------------------------ -->
    <!-- head() e describe()                                                 -->
    <!-- ------------------------------------------------------------------ -->

    <h2>Estatísticas descritivas</h2>

    <p class="intro">
        O <code>describe()</code> do pandas, sobre as colunas numéricas dos
        <?= (int) $visao['atendimentos'] ?> atendimentos da base ativa. Contagem,
        média, desvio padrão, extremos e os três quartis, de uma vez.
    </p>

    <div class="tabela-rolagem">
        <table class="tabela">
            <thead>
                <tr>
                    <th scope="col">Medida</th>
                    <?php foreach ($visao['describe']['colunas'] as $coluna): ?>
                        <th scope="col"><?= h(rotulo_da_coluna((string) $coluna)) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($visao['describe']['medidas'] as $i => $nome): ?>
                    <tr>
                        <th scope="row"><?= h(nome_da_medida((string) $nome)) ?></th>
                        <?php foreach ($visao['describe']['valores'][$i] as $j => $valor): ?>
                            <?php $colunaAtual = (string) $visao['describe']['colunas'][$j]; ?>
                            <td class="numero">
                                <?php if ($nome === 'count'): ?>
                                    <?= (int) $valor ?>
                                <?php else: ?>
                                    <?= h(formatar_pandas(
                                        $valor,
                                        MEDIDAS_PANDAS[$colunaAtual]['formato'] ?? 'numero'
                                    )) ?>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if (pode('ver_dados')): ?>
        <details class="tabela-equivalente">
            <summary>Ver as primeiras <?= (int) $linhas ?> linhas (<code>head()</code>)</summary>
            <div class="tabela-rolagem">
                <table class="tabela">
                    <thead>
                        <tr>
                            <?php foreach ($visao['colunas'] as $coluna): ?>
                                <th scope="col"><?= h((string) $coluna) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($visao['head'] as $linha): ?>
                            <tr>
                                <?php foreach ($linha as $celula): ?>
                                    <td class="<?= is_numeric($celula) ? 'numero' : '' ?>">
                                        <?= h(is_float($celula) ? number_format($celula, 2, ',', '.') : (string) $celula) ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </details>
    <?php else: ?>
        <p class="nota">
            As linhas individuais do <code>head()</code> não aparecem no seu
            perfil: elas trazem o sexo de quem pagou e se havia fumante à mesa,
            que são dados de mesa, não números do salão.
        </p>
    <?php endif; ?>

    <!-- ------------------------------------------------------------------ -->
    <!-- Correlação                                                          -->
    <!-- ------------------------------------------------------------------ -->

    <h2>Correlação entre as grandezas numéricas</h2>

    <p class="intro">
        O <code>corr()</code> cruza cada coluna numérica com todas as outras.
        Perto de <strong>1</strong>, as duas sobem juntas; perto de
        <strong>−1</strong>, uma sobe quando a outra desce; perto de
        <strong>0</strong>, uma não diz quase nada sobre a outra.
    </p>

    <div class="tabela-rolagem">
        <table class="tabela">
            <thead>
                <tr>
                    <th scope="col"></th>
                    <?php foreach ($correlacao['colunas'] as $coluna): ?>
                        <th scope="col"><?= h(rotulo_da_coluna((string) $coluna)) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($correlacao['colunas'] as $i => $coluna): ?>
                    <tr>
                        <th scope="row"><?= h(rotulo_da_coluna((string) $coluna)) ?></th>
                        <?php foreach ($correlacao['matriz'][$i] as $valor): ?>
                            <td class="numero">
                                <?= h(number_format((float) $valor, 4, ',', '.')) ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <h3>Os pares que mais andam juntos</h3>

    <dl class="resumo">
        <?php foreach (array_slice($correlacao['pares'], 0, 3) as $par): ?>
            <div>
                <dt><?= h(rotulo_da_coluna((string) $par['a'])) ?>
                    × <?= h(rotulo_da_coluna((string) $par['b'])) ?></dt>
                <dd><?= h(number_format((float) $par['r'], 4, ',', '.')) ?></dd>
            </div>
        <?php endforeach; ?>
    </dl>

    <p class="nota">
        Correlação não é causa. Mesa grande gasta mais e deixa mais gorjeta, e
        isso sozinho já faz conta e gorjeta subirem juntas sem nenhuma
        generosidade extra — repare que <em>pessoas à mesa</em> aparece
        correlacionada com as duas.
    </p>

    <h3>As <?= count($correlacao['maiores']) ?> maiores gorjetas da base</h3>

    <table class="tabela">
        <thead>
            <tr>
                <th scope="col">Conta</th>
                <th scope="col">Gorjeta</th>
                <th scope="col">Sobre a conta</th>
                <th scope="col">Dia</th>
                <th scope="col">Período</th>
                <th scope="col">Pessoas</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($correlacao['maiores'] as $m): ?>
                <tr>
                    <td class="numero"><?= h(moeda((float) $m['conta'])) ?></td>
                    <td class="numero forte"><?= h(moeda((float) $m['gorjeta'])) ?></td>
                    <td class="numero"><?= h(formatar_pandas($m['percentual'], 'porcento')) ?></td>
                    <td><?= h((string) $m['dia']) ?></td>
                    <td><?= h((string) $m['periodo']) ?></td>
                    <td class="numero"><?= (int) $m['pessoas'] ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- ------------------------------------------------------------------ -->
    <!-- Figuras do matplotlib                                               -->
    <!-- ------------------------------------------------------------------ -->

    <h2>As figuras do matplotlib</h2>

    <?php if (!$comPandas): ?>

        <p class="aviso aviso-aviso">
            As figuras só existem com o Python: é o <code>matplotlib</code> que
            as desenha. Sem ele a tela fica com as tabelas e com os gráficos em
            SVG da aba <a href="graficos.php">Gráficos</a>, que o PHP gera sozinho.
        </p>

    <?php else: ?>

        <p class="intro">
            As duas figuras da aula, geradas aqui no servidor e entregues ao
            navegador como PNG. O arquivo nasce em <code>var/</code>, vai para a
            resposta e é apagado — ele não fica guardado nem tem endereço próprio.
        </p>

        <figure class="figura">
            <img src="<?= h(url_da_figura('dispersao', $grupo, $medida)) ?>"
                 alt="Nuvem de pontos do valor da conta contra o valor da gorjeta, com a reta de tendência."
                 width="880" height="550" loading="lazy">
            <figcaption>
                <strong>Valor da conta × gorjeta.</strong> Cada ponto é uma mesa
                fechada; a reta escura é a de mínimos quadrados. A nuvem subindo
                para a direita é o mesmo
                <?= h(number_format((float) $rContaGorjeta, 4, ',', '.')) ?>
                da tabela de correlação, desenhado.
            </figcaption>
        </figure>

        <figure class="figura">
            <img src="<?= h(url_da_figura('pessoas', $grupo, $medida)) ?>"
                 alt="Nuvem de pontos de quantas pessoas estavam à mesa contra o valor da gorjeta."
                 width="880" height="550" loading="lazy">
            <figcaption>
                <strong>Pessoas à mesa × gorjeta.</strong> O eixo horizontal é
                discreto — mesas de 1 a 6 lugares — então os pontos se empilham em
                colunas. A altura de cada coluna mostra o espalhamento das gorjetas
                naquele tamanho de mesa, que a média sozinha esconde.
            </figcaption>
        </figure>

        <figure class="figura">
            <img src="<?= h(url_da_figura('correlacao', $grupo, $medida)) ?>"
                 alt="Mapa de cores da matriz de correlação entre as grandezas numéricas."
                 width="880" height="550" loading="lazy">
            <figcaption>
                <strong>Mapa de correlação.</strong> A mesma matriz da tabela
                acima, em cor: verde para quem sobe junto, vermelho para quem anda
                ao contrário. A diagonal é sempre 1 — toda coluna é perfeitamente
                correlacionada consigo mesma.
            </figcaption>
        </figure>

    <?php endif; ?>

    <!-- ------------------------------------------------------------------ -->
    <!-- A saída crua                                                        -->
    <!-- ------------------------------------------------------------------ -->

    <?php if (pode('ver_terminal')): ?>
        <section class="terminal" aria-label="Saída do programa em Python">
            <h2 class="terminal-titulo">
                A saída do programa em Python
                <span class="selo selo-<?= $comPandas ? 'ok' : 'aviso' ?>">
                    <?= $comPandas ? 'rodou no pandas' : 'plano B em PHP' ?>
                </span>
            </h2>

            <?php if ($comPandas): ?>
                <p class="terminal-nota">O comando que o PHP executou:</p>
                <pre class="terminal-comando"><?= h($execucao['comando']) ?></pre>
                <p class="terminal-nota">O que o <code>pandas</code> devolveu:</p>
            <?php endif; ?>

            <pre class="terminal-saida"><?= h($execucao['bruto']) ?></pre>

            <?php if ($comPandas): ?>
                <p class="nota">
                    É a mesma saída que aparece rodando o programa direto no
                    terminal. As tabelas acima desta caixa não são outra conta: o
                    PHP pede o JSON do mesmo comando, com <code>-j</code>, e desenha
                    o que volta.
                </p>
            <?php endif; ?>
        </section>
    <?php endif; ?>

<?php endif; ?>

<?php require __DIR__ . '/includes/rodape.php'; ?>
