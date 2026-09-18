<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/importacao.php';

iniciar_sessao_segura();
exigir_login();
exigir_permissao('enviar_dados');

/**
 * O envio é feito em dois passos de propósito: o primeiro lê e confere, o
 * segundo grava. Entre os dois, a pessoa vê a tabela do que entendemos do
 * arquivo dela e quais linhas ficaram de fora, com o motivo de cada uma.
 * Gravar direto seria mais curto e deixaria alguém descobrir depois que a
 * planilha tinha a coluna trocada.
 *
 * O que fica guardado entre os dois passos são os registros já validados na
 * sessão — nunca o arquivo enviado, que não é escrito em lugar nenhum.
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validar_csrf($_POST['csrf'] ?? null)) {
        definir_aviso('O formulário expirou antes de ser enviado. Refaça o pedido.', 'erro');
        redirecionar('upload.php');
    }

    $acao = (string) ($_POST['acao'] ?? '');

    try {
        switch ($acao) {
            case 'analisar':
                $caminho = receber_arquivo_enviado($_FILES['arquivo'] ?? null);
                $lido    = importar_csv($caminho);

                if ($lido['registros'] === [] && $lido['erros'] === []) {
                    definir_aviso('O arquivo não trouxe nenhuma linha de atendimento.', 'aviso');
                    redirecionar('upload.php');
                }

                $_SESSION['previa'] = [
                    'registros' => $lido['registros'],
                    'erros'     => $lido['erros'],
                    'lidas'     => $lido['lidas'],
                    'cortou'    => $lido['cortou'],
                    'cabecalho' => $lido['cabecalho'],
                    'separador' => $lido['separador'],
                    // Só para mostrar na tela. Não vira caminho nem chave.
                    'nome'      => substr((string) ($_FILES['arquivo']['name'] ?? 'arquivo.csv'), 0, 120),
                ];

                redirecionar('upload.php');
                // no break

            case 'gravar':
                $previa = $_SESSION['previa'] ?? null;

                if (!is_array($previa) || $previa['registros'] === []) {
                    definir_aviso('Não há nada conferido para gravar. Envie o arquivo de novo.', 'erro');
                    redirecionar('upload.php');
                }

                $modo = ((string) ($_POST['modo'] ?? 'anexar')) === 'substituir' ? 'substituir' : 'anexar';

                $gravados = $modo === 'substituir'
                    ? substituir_base($previa['registros'])
                    : anexar_registros($previa['registros']);

                unset($_SESSION['previa']);

                definir_aviso(
                    $modo === 'substituir'
                        ? "Base substituída: agora ela tem {$gravados} atendimentos."
                        : "{$gravados} atendimentos acrescentados à base.",
                    'ok'
                );
                redirecionar('upload.php');
                // no break

            case 'descartar':
                unset($_SESSION['previa']);
                definir_aviso('Conferência descartada. Nada foi gravado.', 'aviso');
                redirecionar('upload.php');
                // no break

            case 'manual':
                $conferido = validar_registro([
                    'total_bill' => (string) ($_POST['total_bill'] ?? ''),
                    'tip'        => (string) ($_POST['tip'] ?? ''),
                    'sex'        => (string) ($_POST['sex'] ?? ''),
                    'smoker'     => (string) ($_POST['smoker'] ?? ''),
                    'day'        => (string) ($_POST['day'] ?? ''),
                    'time'       => (string) ($_POST['time'] ?? ''),
                    'size'       => (string) ($_POST['size'] ?? ''),
                ]);

                if ($conferido['registro'] === null) {
                    definir_aviso('Não deu para lançar: ' . implode(' ', $conferido['erros']), 'erro');
                    redirecionar('upload.php');
                }

                anexar_registros([$conferido['registro']]);
                definir_aviso('Atendimento lançado na base.', 'ok');
                redirecionar('upload.php');
                // no break

            case 'restaurar':
                restaurar_semente();
                unset($_SESSION['previa']);
                definir_aviso('A base voltou ao arquivo original de 244 atendimentos.', 'ok');
                redirecionar('upload.php');
                // no break

            case 'desfazer':
                desfazer_ultima();
                definir_aviso('A base voltou ao estado anterior.', 'ok');
                redirecionar('upload.php');
                // no break

            default:
                redirecionar('upload.php');
        }
    } catch (Throwable $e) {
        definir_aviso($e->getMessage(), 'erro');
        redirecionar('upload.php');
    }
}

$previa    = $_SESSION['previa'] ?? null;
$gravavel  = false;
$total     = 0;
$erroBase  = null;

try {
    $total    = count(ler_base());
    $gravavel = base_e_gravavel();
} catch (Throwable $e) {
    $erroBase = $e->getMessage();
}

$titulo = 'Enviar dados';
require __DIR__ . '/includes/topo.php';
?>

<h1>Enviar dados de vendas</h1>

<p class="intro">
    A base de trabalho tem hoje <strong><?= (int) $total ?></strong> atendimentos.
    Você pode acrescentar um arquivo inteiro de uma vez, lançar uma venda à mão,
    ou devolver tudo ao arquivo original — o <code>dados/gorjetas.csv</code>
    versionado nunca é escrito, então o caminho de volta está sempre aberto.
</p>

<?php if ($erroBase !== null): ?>
    <p class="aviso aviso-erro"><?= h($erroBase) ?></p>
<?php endif; ?>

<?php if (!$gravavel): ?>
    <p class="aviso aviso-erro">
        A base de trabalho em <code>var/</code> não está gravável, então nada pode ser
        acrescentado agora. Dê permissão de escrita nessa pasta para o usuário que
        roda o Apache.
    </p>
<?php endif; ?>

<?php if (is_array($previa)): ?>

    <h2>Confira antes de gravar</h2>

    <p class="contagem">
        De <code><?= h($previa['nome']) ?></code>, lido com
        <?= h(nome_do_separador($previa['separador'])) ?> como separador
        <?= $previa['cabecalho'] ? 'e com linha de cabeçalho' : 'e sem linha de cabeçalho' ?>:
        <strong><?= count($previa['registros']) ?></strong>
        <?= count($previa['registros']) === 1 ? 'atendimento pronto' : 'atendimentos prontos' ?><?php
        if ($previa['erros'] !== []): ?> e <strong><?= count($previa['erros']) ?></strong>
        <?= count($previa['erros']) === 1 ? 'linha recusada' : 'linhas recusadas' ?><?php
        endif; ?>.
    </p>

    <?php if ($previa['cortou']): ?>
        <p class="aviso aviso-aviso">
            O arquivo passou de <?= (int) MAX_LINHAS_UPLOAD ?> linhas. Considerei só as primeiras.
        </p>
    <?php endif; ?>

    <?php if ($previa['erros'] !== []): ?>
        <div class="recusadas">
            <h3>Linhas que ficaram de fora</h3>
            <ul>
                <?php foreach ($previa['erros'] as $erro): ?>
                    <li>
                        <span class="recusada-linha">Linha <?= (int) $erro['linha'] ?></span>
                        <?= h(implode(' ', $erro['motivos'])) ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p class="nota">
                Nenhuma delas entra na base. Corrija no arquivo e envie de novo, ou
                siga em frente sem elas.
            </p>
        </div>
    <?php endif; ?>

    <?php if ($previa['registros'] !== []): ?>

        <div class="tabela-rolagem">
            <table class="tabela">
                <caption>
                    <?= count($previa['registros']) <= MAX_PREVIA_LINHAS
                        ? 'Os atendimentos que vão entrar'
                        : 'Os primeiros ' . MAX_PREVIA_LINHAS . ' de '
                          . count($previa['registros']) . ' atendimentos que vão entrar' ?>
                </caption>
                <thead>
                    <tr>
                        <th scope="col">#</th>
                        <th scope="col">Conta</th>
                        <th scope="col">Gorjeta</th>
                        <th scope="col">Proporção</th>
                        <th scope="col">Cliente</th>
                        <th scope="col">Fumante</th>
                        <th scope="col">Dia</th>
                        <th scope="col">Período</th>
                        <th scope="col">Pessoas</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach (array_slice($previa['registros'], 0, MAX_PREVIA_LINHAS) as $i => $r): ?>
                    <tr>
                        <td class="numero discreto"><?= $i + 1 ?></td>
                        <td class="numero"><?= h(moeda((float) $r['total_bill'])) ?></td>
                        <td class="numero forte"><?= h(moeda((float) $r['tip'])) ?></td>
                        <td class="numero"><?= h(number_format((float) $r['percentual'], 2, ',', '.')) ?>%</td>
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

        <div class="acoes-previa">
            <form method="post" action="upload.php">
                <?= campo_csrf() ?>
                <input type="hidden" name="acao" value="gravar">
                <input type="hidden" name="modo" value="anexar">
                <button type="submit" class="botao" <?= $gravavel ? '' : 'disabled' ?>>
                    Acrescentar aos <?= (int) $total ?> atuais
                </button>
            </form>

            <form method="post" action="upload.php">
                <?= campo_csrf() ?>
                <input type="hidden" name="acao" value="gravar">
                <input type="hidden" name="modo" value="substituir">
                <button type="submit" class="botao botao-cuidado" <?= $gravavel ? '' : 'disabled' ?>>
                    Substituir a base inteira
                </button>
            </form>

            <form method="post" action="upload.php">
                <?= campo_csrf() ?>
                <input type="hidden" name="acao" value="descartar">
                <button type="submit" class="botao-vazado">Descartar</button>
            </form>
        </div>

        <p class="nota">
            <strong>Acrescentar</strong> soma estas linhas ao que já existe.
            <strong>Substituir</strong> troca a base inteira por elas — os
            <?= (int) $total ?> atendimentos de agora saem. A base de antes fica
            guardada e dá para voltar com um clique logo abaixo.
        </p>

    <?php endif; ?>

<?php else: ?>

    <h2>Enviar um arquivo</h2>

    <form class="envio" method="post" action="upload.php" enctype="multipart/form-data">
        <?= campo_csrf() ?>
        <input type="hidden" name="acao" value="analisar">
        <input type="hidden" name="MAX_FILE_SIZE" value="<?= (int) MAX_BYTES_UPLOAD ?>">

        <div>
            <label for="arquivo">Arquivo CSV</label>
            <input type="file" id="arquivo" name="arquivo" accept=".csv,text/csv,text/plain" required>
            <p class="ajuda-campo">
                Até <?= round(MAX_BYTES_UPLOAD / 1024 / 1024, 1) ?> MB e
                <?= (int) MAX_LINHAS_UPLOAD ?> linhas. Separador vírgula,
                ponto-e-vírgula ou tabulação — o sistema descobre sozinho.
                O arquivo é lido e descartado; ele não fica guardado no servidor.
            </p>
        </div>

        <button type="submit" class="botao">Conferir o arquivo</button>
    </form>

    <h3>As colunas que a base espera</h3>

    <div class="tabela-rolagem">
        <table class="tabela">
            <caption>Na ordem do arquivo, se ele vier sem cabeçalho</caption>
            <thead>
                <tr>
                    <th scope="col">Coluna</th>
                    <th scope="col">O que guarda</th>
                    <th scope="col">O que aceita</th>
                    <th scope="col">Também reconhece o cabeçalho</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach (COLUNAS_BASE as $nome => $meta): ?>
                <tr>
                    <th scope="row"><code><?= h($nome) ?></code></th>
                    <td><?= h($meta['ajuda']) ?></td>
                    <td>
                        <?php if ($meta['tipo'] === 'enum'): ?>
                            <?= h(implode(', ', $meta['valores'])) ?>
                        <?php else: ?>
                            número de <?= h((string) $meta['min']) ?> a <?= h((string) $meta['max']) ?>
                        <?php endif; ?>
                    </td>
                    <td class="discreto"><?= h(implode(', ', array_slice($meta['cabecalhos'], 1))) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <p class="nota">
        Planilha em português passa: <code>Sábado</code>, <code>Almoço</code>,
        <code>Mulher</code> e <code>Sim</code> são reconhecidos e gravados como
        <code>Sat</code>, <code>Lunch</code>, <code>Female</code> e <code>Yes</code>.
        Decimal com vírgula também. O que não estiver na lista de valores é
        recusado com o motivo, linha por linha — nada entra pela metade.
    </p>

    <h2>Lançar uma venda à mão</h2>

    <form class="filtros filtros-grade" method="post" action="upload.php">
        <?= campo_csrf() ?>
        <input type="hidden" name="acao" value="manual">

        <div>
            <label for="m-total_bill">Conta (R$)</label>
            <input type="number" step="0.01" min="0.01" id="m-total_bill" name="total_bill" required>
        </div>

        <div>
            <label for="m-tip">Gorjeta (R$)</label>
            <input type="number" step="0.01" min="0" id="m-tip" name="tip" required>
        </div>

        <?php foreach (['sex', 'smoker', 'day', 'time'] as $campo): ?>
            <div>
                <label for="m-<?= h($campo) ?>"><?= h(COLUNAS_BASE[$campo]['rotulo']) ?></label>
                <select name="<?= h($campo) ?>" id="m-<?= h($campo) ?>" required>
                    <?php foreach (COLUNAS_BASE[$campo]['valores'] as $valor): ?>
                        <option value="<?= h($valor) ?>"><?= h(traduzir($valor)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endforeach; ?>

        <div>
            <label for="m-size">Pessoas à mesa</label>
            <input type="number" step="1" min="1" max="<?= (int) COLUNAS_BASE['size']['max'] ?>"
                   id="m-size" name="size" value="2" required>
        </div>

        <div class="filtros-acoes">
            <button type="submit" class="botao" <?= $gravavel ? '' : 'disabled' ?>>Lançar</button>
        </div>
    </form>

<?php endif; ?>

<h2>Voltar atrás</h2>

<div class="acoes-previa">
    <form method="post" action="upload.php">
        <?= campo_csrf() ?>
        <input type="hidden" name="acao" value="restaurar">
        <button type="submit" class="botao botao-cuidado" <?= $gravavel ? '' : 'disabled' ?>>
            Restaurar a base original
        </button>
    </form>

    <?php if (existe_copia_anterior()): ?>
        <form method="post" action="upload.php">
            <?= campo_csrf() ?>
            <input type="hidden" name="acao" value="desfazer">
            <button type="submit" class="botao-vazado">Desfazer a última troca</button>
        </form>
    <?php endif; ?>
</div>

<p class="nota">
    <strong>Restaurar</strong> devolve a base aos 244 atendimentos do arquivo
    versionado e joga fora o que foi acrescentado depois.
    <strong>Desfazer</strong> volta à cópia guardada antes da última substituição
    ou restauração — um passo, não um histórico.
</p>

<?php require __DIR__ . '/includes/rodape.php'; ?>
