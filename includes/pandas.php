<?php

declare(strict_types=1);

require_once __DIR__ . '/dados.php';
require_once __DIR__ . '/estatistica.php';

/**
 * Ponte PHP -> scripts/analise_pandas.py.
 *
 * Mesmo desenho da ponte para o shell script em includes/mineracao.php: o PHP
 * não calcula nada aqui, ele chama o programa em Python, recebe JSON e formata.
 * Quem agrupa, descreve e correlaciona é o pandas.
 *
 * Duas coisas valem ser ditas sobre a chamada:
 *
 *  - Operação, agrupamento, medida e figura vêm de listas fixas em
 *    config/config.php, conferidas aqui antes de virarem argumento, e cada
 *    argumento passa por escapeshellarg(). O próprio Python confere de novo,
 *    com as choices do argparse. Nada que venha da URL vira comando.
 *
 *  - Não existe sudo em lugar nenhum. O script só lê um CSV e escreve um PNG
 *    numa pasta que o servidor já pode escrever; dar root a um processo que
 *    recebe argumento de fora seria trocar uma falha de leitura por uma falha
 *    de máquina inteira.
 *
 * Se nenhum python com pandas responder, o sistema não trava: os mesmos
 * cálculos são refeitos em PHP e a tela avisa de onde vieram.
 */

// ---------------------------------------------------------------------------
// Descobrir o python
// ---------------------------------------------------------------------------

/**
 * Caminho do python que tem pandas instalado, ou null se nenhum respondeu.
 *
 * Não basta o executável existir: o candidato só é aceito depois de importar
 * o pandas de verdade. Um python sem pandas responderia ao `--version` e
 * falharia na primeira análise, o que é pior do que não existir.
 */
function caminho_do_python(): ?string
{
    static $resolvido = null;
    static $versao    = null;
    static $procurado = false;

    if ($procurado) {
        return $resolvido;
    }

    $procurado  = true;
    $desligadas = array_map('trim', explode(',', (string) ini_get('disable_functions')));

    if (!function_exists('shell_exec') || in_array('shell_exec', $desligadas, true)) {
        return $resolvido = null;
    }

    foreach (candidatos_de_python() as $candidato) {
        $respondeu = python_com_pandas($candidato);

        if ($respondeu !== null) {
            $versao = $respondeu;
            return $resolvido = $candidato;
        }
    }

    return $resolvido = null;
}

function versao_do_pandas(): ?string
{
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    $python = caminho_do_python();

    if ($python === null) {
        return null;
    }

    return $cache = python_com_pandas($python);
}

/** Onde procurar, em ordem de preferência. */
function candidatos_de_python(): array
{
    $lista = [];

    if (CAMINHO_PYTHON !== '') {
        $lista[] = CAMINHO_PYTHON;
    }

    if (DIRECTORY_SEPARATOR === '\\') {
        // No Windows o python quase nunca está no PATH do serviço do Apache,
        // mesmo estando no PATH do terminal. Por isso ele é procurado pelos
        // lugares de instalação antes.
        $padroes = [];

        foreach (['LOCALAPPDATA', 'ProgramFiles', 'ProgramW6432', 'ProgramFiles(x86)'] as $variavel) {
            $raiz = getenv($variavel);

            if (is_string($raiz) && $raiz !== '') {
                $raiz      = rtrim($raiz, '\\');
                $padroes[] = $raiz . '\\Programs\\Python\\Python3*\\python.exe';
                $padroes[] = $raiz . '\\Python\\pythoncore-*\\python.exe';
                $padroes[] = $raiz . '\\Python3*\\python.exe';
            }
        }

        $padroes[] = 'C:\\Python3*\\python.exe';

        foreach ($padroes as $padrao) {
            $achados = glob($padrao, GLOB_NOSORT);

            if (is_array($achados)) {
                // Versão mais nova primeiro: Python313 antes de Python39.
                rsort($achados, SORT_NATURAL);
                $lista = array_merge($lista, $achados);
            }
        }
    }

    // Por último o PATH, que é o caminho certo no Linux e no macOS.
    $lista[] = 'python3';
    $lista[] = 'python';

    return array_values(array_unique($lista));
}

/**
 * Devolve a versão do pandas que este python enxerga, ou null.
 *
 * -I isola o interpretador: ele ignora PYTHONPATH e não põe a pasta do script
 * no caminho de import. Sem isso, um arquivo chamado json.py largado ao lado
 * do script seria importado no lugar da biblioteca padrão.
 */
function python_com_pandas(string $caminho): ?string
{
    if ($caminho !== 'python' && $caminho !== 'python3' && !is_file($caminho)) {
        return null;
    }

    $saida = @shell_exec(
        escapeshellarg($caminho) . ' -I -c '
        . escapeshellarg("import pandas;print('pandas', pandas.__version__)")
        . ' 2>&1'
    );

    if (!is_string($saida) || !preg_match('/^pandas (\d[\w.+-]*)/m', trim($saida), $casou)) {
        return null;
    }

    return $casou[1];
}

function pandas_disponivel(): bool
{
    return caminho_do_python() !== null;
}

/** Texto que explica por que o plano B em PHP entrou em cena. */
function explicacao_sem_pandas(): string
{
    $procurados = implode("\n  ", candidatos_de_python());

    return "Nenhum python com pandas respondeu, então os mesmos agrupamentos foram\n"
         . "refeitos em PHP para a tela não quebrar. Os números são os mesmos; o que\n"
         . "falta é o pandas fazendo a conta.\n\n"
         . "Procurei nesta ordem:\n  " . $procurados . "\n\n"
         . "Instale com:\n"
         . "  python3 -m pip install pandas matplotlib\n"
         . "  (Debian/Ubuntu: sudo apt install python3-pandas python3-matplotlib)\n\n"
         . "Se o python estiver em outro lugar, aponte o caminho em CAMINHO_PYTHON,\n"
         . "dentro de config/config.php.";
}

// ---------------------------------------------------------------------------
// Conferência dos pedidos
// ---------------------------------------------------------------------------

/** Agrupamentos que este papel pode pedir. */
function agrupamentos_permitidos(): array
{
    $permitidos = [];

    foreach (AGRUPAMENTOS_PANDAS as $chave => $meta) {
        if (!$meta['restrito'] || pode('ver_dados')) {
            $permitidos[$chave] = $meta;
        }
    }

    return $permitidos;
}

function agrupamento_valido(string $pedido): string
{
    return array_key_exists($pedido, agrupamentos_permitidos()) ? $pedido : 'periodo';
}

function medida_valida(string $pedida): string
{
    return array_key_exists($pedida, MEDIDAS_PANDAS) ? $pedida : 'conta';
}

function operacao_pandas_valida(string $pedida): string
{
    return array_key_exists($pedida, OPERACOES_PANDAS) ? $pedida : 'periodo';
}

function figura_valida(string $pedida): string
{
    return array_key_exists($pedida, FIGURAS_PANDAS) ? $pedida : 'dispersao';
}

// ---------------------------------------------------------------------------
// Chamada
// ---------------------------------------------------------------------------

/**
 * Roda uma análise e devolve
 * ['origem' => 'pandas'|'php', 'dados' => array, 'bruto' => string,
 *  'comando' => string, 'versao' => ?string].
 *
 * 'bruto' é a saída de texto do programa, exatamente como ela sai no terminal.
 * Ela vai para a tela junto do comando que a gerou, para dar para conferir que
 * a conta veio mesmo do Python.
 */
function analisar_com_pandas(
    string $operacao,
    string $grupo = 'periodo',
    string $medida = 'conta',
    int $linhas = 5
): array {
    if (!array_key_exists($operacao, OPERACOES_PANDAS)) {
        throw new InvalidArgumentException('Análise não reconhecida.');
    }

    if (!array_key_exists($grupo, AGRUPAMENTOS_PANDAS)) {
        throw new InvalidArgumentException('Agrupamento não reconhecido.');
    }

    if (!array_key_exists($medida, MEDIDAS_PANDAS)) {
        throw new InvalidArgumentException('Medida não reconhecida.');
    }

    $linhas = max(1, min(50, $linhas));
    $base   = caminho_da_base();

    if (!pandas_disponivel()) {
        return [
            'origem'  => 'php',
            'dados'   => analisar_em_php($operacao, $grupo, $medida, $linhas, $base),
            'bruto'   => explicacao_sem_pandas(),
            'comando' => '',
            'versao'  => null,
        ];
    }

    $comando = comando_pandas([
        '-a', $base,
        '-o', $operacao,
        '-g', $grupo,
        '-m', $medida,
        '-n', (string) $linhas,
    ]);

    $json  = shell_exec($comando . ' -j 2>&1');
    $bruto = shell_exec($comando . ' 2>&1');

    $decodificado = json_decode((string) $json, true);

    if (!is_array($decodificado) || !array_key_exists('resultado', $decodificado)) {
        // O detalhe vai para o log, não para a tela: a saída crua do processo
        // pode trazer caminho de servidor e rastro de exceção do Python.
        error_log(sprintf(
            'bistro: analise_pandas.py não devolveu JSON (%s) — %s',
            json_last_error_msg(),
            trim(substr((string) $json, 0, 2000))
        ));

        throw new FalhaDeDominio(
            "O programa em pandas não devolveu um resultado válido. "
            . "O detalhe foi registrado no log de erro do PHP; para ver na mão, "
            . "rode no terminal:\n" . $comando
        );
    }

    return [
        'origem'  => 'pandas',
        'dados'   => $decodificado['resultado'],
        'bruto'   => trim((string) $bruto),
        'comando' => $comando,
        'versao'  => $decodificado['pandas'] ?? versao_do_pandas(),
    ];
}

/** Monta a linha de comando com cada argumento escapado um a um. */
function comando_pandas(array $argumentos): string
{
    $partes = [
        escapeshellarg((string) caminho_do_python()),
        '-I',
        escapeshellarg(SCRIPT_PANDAS),
    ];

    foreach ($argumentos as $argumento) {
        $partes[] = escapeshellarg((string) $argumento);
    }

    return implode(' ', $partes);
}

// ---------------------------------------------------------------------------
// Figuras do matplotlib
// ---------------------------------------------------------------------------

/**
 * Desenha uma figura e devolve o caminho do PNG, ou null se não deu.
 *
 * O arquivo nasce em var/, com nome sorteado por random_bytes() — nada do que
 * veio da URL entra no nome. Quem chamou é dono do arquivo e apaga depois de
 * mandar os bytes para o navegador; var/ não é servida pelo Apache, então o
 * PNG nunca tem URL própria.
 */
function figura_pandas(string $tipo, string $grupo = 'periodo', string $medida = 'conta'): ?string
{
    if (!array_key_exists($tipo, FIGURAS_PANDAS)
        || !array_key_exists($grupo, AGRUPAMENTOS_PANDAS)
        || !array_key_exists($medida, MEDIDAS_PANDAS)) {
        throw new InvalidArgumentException('Figura não reconhecida.');
    }

    if (!pandas_disponivel()) {
        return null;
    }

    if (!is_dir(PASTA_VAR)) {
        @mkdir(PASTA_VAR, 0775, true);
    }

    if (!is_dir(PASTA_VAR) || !is_writable(PASTA_VAR)) {
        throw new FalhaDeDominio(
            'A pasta var/ não está gravável, e é nela que a figura é desenhada.'
        );
    }

    limpar_figuras_esquecidas();

    $destino = PASTA_VAR . DIRECTORY_SEPARATOR . 'fig-' . bin2hex(random_bytes(16)) . '.png';

    $comando = comando_pandas([
        '-a', caminho_da_base(),
        '-f', $tipo,
        '-g', $grupo,
        '-m', $medida,
        '-s', $destino,
    ]);

    $saida = shell_exec($comando . ' 2>&1');

    if (!is_file($destino) || filesize($destino) === 0) {
        @unlink($destino);
        error_log('bistro: figura do matplotlib falhou — ' . trim((string) $saida));

        throw new FalhaDeDominio('Não foi possível desenhar a figura no servidor.');
    }

    return $destino;
}

/**
 * Apaga PNG que ficou para trás quando uma requisição morreu no meio. Sem
 * isso var/ cresceria sozinha, uma figura por acidente.
 */
function limpar_figuras_esquecidas(int $segundos = 300): void
{
    $achados = glob(PASTA_VAR . DIRECTORY_SEPARATOR . 'fig-*.png', GLOB_NOSORT);

    if (!is_array($achados)) {
        return;
    }

    $limite = time() - $segundos;

    foreach ($achados as $arquivo) {
        if (@filemtime($arquivo) < $limite) {
            @unlink($arquivo);
        }
    }
}

// ---------------------------------------------------------------------------
// Plano B em PHP
// ---------------------------------------------------------------------------
// Os mesmos números, calculados aqui, para a tela continuar de pé numa
// máquina sem pandas. A forma do resultado é igual à do JSON do Python, para
// a página não precisar saber de onde veio.

function analisar_em_php(string $operacao, string $grupo, string $medida, int $linhas, string $base): array
{
    $registros = ler_base($base);

    return match ($operacao) {
        'periodo'    => periodo_em_php($registros),
        'grupo'      => agrupar_em_php($registros, $grupo, $medida),
        'visao'      => visao_em_php($registros, $linhas),
        'correlacao' => correlacao_em_php($registros, $linhas),
        default      => [
            'visao'      => visao_em_php($registros, $linhas),
            'periodo'    => periodo_em_php($registros),
            'grupo'      => agrupar_em_php($registros, $grupo, $medida),
            'correlacao' => correlacao_em_php($registros, $linhas),
        ],
    };
}

/** Valor de uma coluna do registro, pelo nome que o Python usa. */
function valor_pandas(array $registro, string $nomePython): float|string|int
{
    $colunas = AGRUPAMENTOS_PANDAS[$nomePython]['coluna']
        ?? MEDIDAS_PANDAS[$nomePython]['coluna']
        ?? null;

    if ($colunas === null) {
        throw new InvalidArgumentException('Coluna não reconhecida.');
    }

    return $registro[$colunas];
}

function agrupar_em_php(array $registros, string $por, string $medida): array
{
    $meta  = MEDIDAS_PANDAS[$medida];
    $todos = [];
    $baldes = [];

    foreach ($registros as $registro) {
        $chave = (string) valor_pandas($registro, $por);
        $valor = (float) valor_pandas($registro, $medida);

        $baldes[$chave][] = $valor;
        $todos[]          = $valor;
    }

    $mediaGeral = $todos === [] ? 0.0 : array_sum($todos) / count($todos);
    $grupos     = [];

    foreach ($baldes as $chave => $valores) {
        $serie  = serie_de_numeros($valores);
        $media  = media_da_serie($serie) ?? 0.0;
        $desvio = desvio_padrao_da_serie($serie, true);

        $grupos[] = [
            'grupo'        => $chave,
            'rotulo'       => traduzir($chave),
            'atendimentos' => count($serie),
            'media'        => round($media, 2),
            'soma'         => round(array_sum($serie), 2),
            'mediana'      => round(mediana_da_serie($serie) ?? 0.0, 2),
            'desvio'       => $desvio === null ? null : round($desvio, 2),
            'minimo'       => round(min($serie), 2),
            'maximo'       => round(max($serie), 2),
            'contra_geral' => round($media - $mediaGeral, 2),
        ];
    }

    usort($grupos, static fn(array $a, array $b): int => $b['media'] <=> $a['media']);

    return [
        'por'           => $por,
        'por_rotulo'    => AGRUPAMENTOS_PANDAS[$por]['rotulo'],
        'medida'        => $medida,
        'medida_rotulo' => $meta['rotulo'],
        'formato'       => $meta['formato'],
        'media_geral'   => round($mediaGeral, 2),
        'atendimentos'  => count($registros),
        'grupos'        => $grupos,
    ];
}

function periodo_em_php(array $registros): array
{
    $resultado = agrupar_em_php($registros, 'periodo', 'conta');
    $grupos    = $resultado['grupos'];

    $resultado['comparacao'] = null;

    if (count($grupos) >= 2) {
        $maior     = $grupos[0];
        $menor     = $grupos[count($grupos) - 1];
        $diferenca = $maior['media'] - $menor['media'];

        $resultado['comparacao'] = [
            'maior'         => $maior['rotulo'],
            'menor'         => $menor['rotulo'],
            'media_maior'   => $maior['media'],
            'media_menor'   => $menor['media'],
            'diferenca'     => round($diferenca, 2),
            'diferenca_pct' => $menor['media'] > 0
                ? round($diferenca / $menor['media'] * 100, 2)
                : 0.0,
        ];
    }

    return $resultado;
}

function visao_em_php(array $registros, int $quantas): array
{
    // A mesma ordem de colunas que o Python mostra no head().
    $ordem  = ['conta', 'gorjeta', 'genero', 'fumante', 'dia_da_semana', 'periodo', 'quantos_na_mesa'];
    $cabeca = [];

    foreach (array_slice($registros, 0, $quantas) as $registro) {
        $linha = [];

        foreach ($ordem as $nome) {
            $valor   = valor_pandas($registro, $nome);
            $linha[] = is_string($valor) ? traduzir($valor) : $valor;
        }

        $cabeca[] = $linha;
    }

    $medidas  = ['count', 'mean', 'std', 'min', '25%', '50%', '75%', 'max'];
    $valores  = array_fill(0, count($medidas), []);

    foreach (NUMERICAS_PANDAS as $nome) {
        $serie = serie_de_numeros(array_map(
            static fn(array $r): float => (float) valor_pandas($r, $nome),
            $registros
        ));

        $linha = [
            (float) count($serie),
            media_da_serie($serie) ?? 0.0,
            desvio_padrao_da_serie($serie, true) ?? 0.0,
            $serie === [] ? 0.0 : min($serie),
            percentil_da_serie($serie, 0.25) ?? 0.0,
            percentil_da_serie($serie, 0.50) ?? 0.0,
            percentil_da_serie($serie, 0.75) ?? 0.0,
            $serie === [] ? 0.0 : max($serie),
        ];

        foreach ($linha as $posicao => $numero) {
            $valores[$posicao][] = round((float) $numero, 4);
        }
    }

    return [
        'colunas' => $ordem,
        'head'    => $cabeca,
        'describe' => [
            'medidas' => $medidas,
            'colunas' => NUMERICAS_PANDAS,
            'valores' => $valores,
        ],
        'atendimentos' => count($registros),
    ];
}

function correlacao_em_php(array $registros, int $quantas): array
{
    $series = [];

    foreach (NUMERICAS_PANDAS as $nome) {
        $series[$nome] = array_map(
            static fn(array $r): float => (float) valor_pandas($r, $nome),
            $registros
        );
    }

    $matriz = [];
    $pares  = [];

    foreach (NUMERICAS_PANDAS as $i => $a) {
        $linha = [];

        foreach (NUMERICAS_PANDAS as $j => $b) {
            $r       = pearson($series[$a], $series[$b]);
            $linha[] = round($r, 4);

            if ($j > $i) {
                $pares[] = ['a' => $a, 'b' => $b, 'r' => round($r, 4)];
            }
        }

        $matriz[] = $linha;
    }

    usort($pares, static fn(array $x, array $y): int => abs($y['r']) <=> abs($x['r']));

    $ordenados = $registros;
    usort($ordenados, static fn(array $a, array $b): int => $b['tip'] <=> $a['tip']);

    $maiores = [];

    foreach (array_slice($ordenados, 0, $quantas) as $r) {
        $maiores[] = [
            'conta'      => round((float) $r['total_bill'], 2),
            'gorjeta'    => round((float) $r['tip'], 2),
            'percentual' => round((float) $r['percentual'], 2),
            'dia'        => traduzir((string) $r['day']),
            'periodo'    => traduzir((string) $r['time']),
            'pessoas'    => (int) $r['size'],
        ];
    }

    return [
        'colunas' => NUMERICAS_PANDAS,
        'matriz'  => $matriz,
        'pares'   => $pares,
        'maiores' => $maiores,
    ];
}

/** Correlação de Pearson entre duas séries do mesmo tamanho. */
function pearson(array $x, array $y): float
{
    $total = min(count($x), count($y));

    if ($total < 2) {
        return 0.0;
    }

    $mediaX = array_sum(array_slice($x, 0, $total)) / $total;
    $mediaY = array_sum(array_slice($y, 0, $total)) / $total;

    $produto = 0.0;
    $somaX   = 0.0;
    $somaY   = 0.0;

    for ($i = 0; $i < $total; $i++) {
        $dx = $x[$i] - $mediaX;
        $dy = $y[$i] - $mediaY;

        $produto += $dx * $dy;
        $somaX   += $dx * $dx;
        $somaY   += $dy * $dy;
    }

    if ($somaX <= 0.0 || $somaY <= 0.0) {
        return 0.0;
    }

    return $produto / sqrt($somaX * $somaY);
}
