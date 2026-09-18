<?php

declare(strict_types=1);

require_once __DIR__ . '/sessao.php';

/**
 * Leitura, validação e filtragem da base de atendimentos.
 *
 * Duas cópias do arquivo convivem:
 *
 *   dados/gorjetas.csv      semente, versionada no git, nunca escrita;
 *   var/gorjetas-ativa.csv  base de trabalho, criada a partir da semente na
 *                           primeira leitura, é quem recebe lançamentos novos.
 *
 * Assim o repositório não fica sujo a cada venda registrada e sempre existe um
 * ponto de retorno. Se var/ não for gravável, o sistema lê a semente direto e
 * segue funcionando em modo somente-leitura.
 */

/** Confere que um caminho realmente cai dentro da pasta esperada. */
function caminho_contido(string $arquivo, string $pasta, string $rotulo): string
{
    $real = realpath($arquivo);
    $raiz = realpath($pasta);

    if ($real === false || $raiz === false
        || !str_starts_with($real, rtrim($raiz, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException($rotulo . ' não foi encontrada onde deveria estar.');
    }

    return $real;
}

function caminho_da_semente(): string
{
    return caminho_contido(ARQUIVO_DADOS, PASTA_DADOS, 'A base original');
}

function base_ativa_existe(): bool
{
    return is_file(ARQUIVO_BASE_ATIVA);
}

/**
 * Caminho da base de trabalho. Cria a cópia a partir da semente se ainda não
 * existir; se não der para criar, devolve a própria semente.
 */
function caminho_da_base(): string
{
    if (!is_dir(PASTA_VAR)) {
        @mkdir(PASTA_VAR, 0775, true);
    }

    if (!base_ativa_existe() && is_dir(PASTA_VAR)) {
        @copy(caminho_da_semente(), ARQUIVO_BASE_ATIVA);
    }

    if (!base_ativa_existe()) {
        return caminho_da_semente();
    }

    return caminho_contido(ARQUIVO_BASE_ATIVA, PASTA_VAR, 'A base de trabalho');
}

function base_e_gravavel(): bool
{
    return base_ativa_existe() && is_writable(ARQUIVO_BASE_ATIVA);
}

// ---------------------------------------------------------------------------
// Validação
// ---------------------------------------------------------------------------

/** Vira a linha posicional do CSV em array com as chaves canônicas. */
function linha_para_associativo(array $linha): array
{
    $assoc = [];

    foreach (array_keys(COLUNAS_BASE) as $posicao => $nome) {
        $assoc[$nome] = isset($linha[$posicao]) ? trim((string) $linha[$posicao]) : '';
    }

    return $assoc;
}

/**
 * Casa um texto com um dos valores permitidos da coluna. Devolve sempre o
 * valor canônico da lista, nunca o que veio de fora, ou null se não bater.
 */
function casar_enum(string $entrada, array $meta): ?string
{
    $chave = minusculo(trim($entrada));

    foreach ($meta['valores'] as $valor) {
        if (minusculo($valor) === $chave) {
            return $valor;
        }
    }

    $sinonimo = $meta['sinonimos'][$chave] ?? null;

    return (is_string($sinonimo) && in_array($sinonimo, $meta['valores'], true))
        ? $sinonimo
        : null;
}

/**
 * Confere um atendimento cru contra COLUNAS_BASE.
 *
 * Devolve ['registro' => array|null, 'erros' => string[]]. Só sai registro
 * quando a lista de erros está vazia — nada é aproveitado pela metade.
 */
function validar_registro(array $bruto): array
{
    $erros   = [];
    $valores = [];

    foreach (COLUNAS_BASE as $nome => $meta) {
        $entrada = trim((string) ($bruto[$nome] ?? ''));

        if ($entrada === '') {
            $erros[] = $meta['rotulo'] . ': faltou o valor.';
            continue;
        }

        if ($meta['tipo'] === 'enum') {
            $casado = casar_enum($entrada, $meta);

            if ($casado === null) {
                $erros[] = $meta['rotulo'] . ': "' . $entrada . '" não é um valor aceito ('
                         . implode(', ', $meta['valores']) . ').';
                continue;
            }

            $valores[$nome] = $casado;
            continue;
        }

        $numero = $meta['tipo'] === 'inteiro'
            ? filter_var($entrada, FILTER_VALIDATE_INT)
            : filter_var(str_replace(',', '.', $entrada), FILTER_VALIDATE_FLOAT);

        if ($numero === false) {
            $erros[] = $meta['rotulo'] . ': "' . $entrada . '" não é um número.';
            continue;
        }

        if ($numero < $meta['min'] || $numero > $meta['max']) {
            $erros[] = $meta['rotulo'] . ': ' . $entrada . ' está fora da faixa aceita ('
                     . $meta['min'] . ' a ' . $meta['max'] . ').';
            continue;
        }

        $valores[$nome] = $meta['tipo'] === 'inteiro' ? (int) $numero : round((float) $numero, 2);
    }

    if ($erros !== []) {
        return ['registro' => null, 'erros' => $erros];
    }

    return ['registro' => derivar_registro($valores), 'erros' => []];
}

/** Acrescenta ao registro os números que não estão no arquivo. */
function derivar_registro(array $v): array
{
    $conta   = (float) $v['total_bill'];
    $gorjeta = (float) $v['tip'];
    $pessoas = max(1, (int) $v['size']);

    return [
        'total_bill' => $conta,
        'tip'        => $gorjeta,
        'sex'        => $v['sex'],
        'smoker'     => $v['smoker'],
        'day'        => $v['day'],
        'time'       => $v['time'],
        'size'       => (int) $v['size'],
        'percentual' => $conta > 0 ? round($gorjeta / $conta * 100, 2) : 0.0,
        'por_pessoa' => round($gorjeta / $pessoas, 2),
    ];
}

/** Devolve o registro na ordem de colunas do CSV, pronto para gravar. */
function registro_para_linha(array $registro): array
{
    $linha = [];

    foreach (array_keys(COLUNAS_BASE) as $nome) {
        $linha[] = $registro[$nome];
    }

    return $linha;
}

// ---------------------------------------------------------------------------
// Leitura
// ---------------------------------------------------------------------------

/**
 * Lê a base inteira. Linha que não passa na validação é descartada em
 * silêncio aqui — quem precisa do motivo (o upload) chama validar_registro().
 */
function ler_base(?string $arquivo = null): array
{
    $arquivo ??= caminho_da_base();
    $handle   = @fopen($arquivo, 'r');

    if ($handle === false) {
        throw new RuntimeException('Não foi possível abrir a base de dados.');
    }

    $registros = [];
    $numero    = 0;

    while (($linha = fgetcsv($handle, 0, ',', '"', '')) !== false) {
        $numero++;

        if ($numero === 1 && !is_numeric(trim((string) ($linha[0] ?? '')))) {
            continue;
        }

        if (count($registros) >= MAX_REGISTROS_BASE) {
            break;
        }

        $conferido = validar_registro(linha_para_associativo($linha));

        if ($conferido['registro'] === null) {
            continue;
        }

        $registro      = $conferido['registro'];
        $registro['n'] = count($registros) + 1;
        $registros[]   = $registro;
    }

    fclose($handle);

    return $registros;
}

// ---------------------------------------------------------------------------
// Filtros
// ---------------------------------------------------------------------------

/**
 * Traduz o que veio da URL em filtros. Nada é aproveitado sem conferência:
 * texto fora da lista de valores vira "sem filtro", número inválido vira null.
 */
function normalizar_filtros(array $entrada): array
{
    $filtros = [];

    foreach (['sex', 'smoker', 'day', 'time'] as $campo) {
        $pedido = trim((string) ($entrada[$campo] ?? ''));

        $filtros[$campo] = in_array($pedido, COLUNAS_BASE[$campo]['valores'], true)
            ? $pedido
            : '';
    }

    foreach (array_keys(FAIXAS_FILTRAVEIS) as $campo) {
        foreach (['min', 'max'] as $ponta) {
            $bruto = trim((string) ($entrada[$campo . '_' . $ponta] ?? ''));

            if ($bruto === '') {
                $filtros[$campo . '_' . $ponta] = null;
                continue;
            }

            $numero = filter_var(str_replace(',', '.', $bruto), FILTER_VALIDATE_FLOAT);
            $filtros[$campo . '_' . $ponta] = $numero === false ? null : (float) $numero;
        }
    }

    return $filtros;
}

function algum_filtro_ativo(array $filtros): bool
{
    foreach ($filtros as $valor) {
        if ($valor !== '' && $valor !== null) {
            return true;
        }
    }

    return false;
}

function filtrar_registros(array $registros, array $filtros): array
{
    $selecionados = [];

    foreach ($registros as $registro) {
        if (registro_passa($registro, $filtros)) {
            $selecionados[] = $registro;
        }
    }

    return $selecionados;
}

function registro_passa(array $registro, array $filtros): bool
{
    foreach (['sex', 'smoker', 'day', 'time'] as $campo) {
        if (($filtros[$campo] ?? '') !== '' && $registro[$campo] !== $filtros[$campo]) {
            return false;
        }
    }

    foreach (array_keys(FAIXAS_FILTRAVEIS) as $campo) {
        $minimo = $filtros[$campo . '_min'] ?? null;
        $maximo = $filtros[$campo . '_max'] ?? null;
        $valor  = (float) $registro[$campo];

        if ($minimo !== null && $valor < $minimo) {
            return false;
        }

        if ($maximo !== null && $valor > $maximo) {
            return false;
        }
    }

    return true;
}

/** Descrição curta dos filtros em vigor, para mostrar na tela. */
function descrever_filtros(array $filtros): array
{
    $partes = [];

    foreach (['sex', 'smoker', 'day', 'time'] as $campo) {
        if (($filtros[$campo] ?? '') !== '') {
            $partes[] = COLUNAS_BASE[$campo]['rotulo'] . ': ' . traduzir($filtros[$campo]);
        }
    }

    foreach (FAIXAS_FILTRAVEIS as $campo => $rotulo) {
        $minimo = $filtros[$campo . '_min'] ?? null;
        $maximo = $filtros[$campo . '_max'] ?? null;

        if ($minimo !== null && $maximo !== null) {
            $partes[] = $rotulo . ': de ' . $minimo . ' a ' . $maximo;
        } elseif ($minimo !== null) {
            $partes[] = $rotulo . ': a partir de ' . $minimo;
        } elseif ($maximo !== null) {
            $partes[] = $rotulo . ': até ' . $maximo;
        }
    }

    return $partes;
}

// ---------------------------------------------------------------------------
// Ordenação e paginação
// ---------------------------------------------------------------------------

function ordenar_registros(array $registros, string $coluna, string $direcao): array
{
    if (!in_array($coluna, ORDENACOES_VALIDAS, true)) {
        $coluna = 'n';
    }

    $sinal = $direcao === 'desc' ? -1 : 1;

    usort($registros, static function (array $a, array $b) use ($coluna, $sinal): int {
        $x = $a[$coluna];
        $y = $b[$coluna];

        $comparacao = (is_string($x) && is_string($y)) ? strcmp($x, $y) : $x <=> $y;

        return $comparacao === 0 ? ($a['n'] <=> $b['n']) : $sinal * $comparacao;
    });

    return $registros;
}

function direcao_valida(string $direcao): string
{
    return $direcao === 'desc' ? 'desc' : 'asc';
}

function coluna_de_ordem(string $pedida): string
{
    return in_array($pedida, ORDENACOES_VALIDAS, true) ? $pedida : 'n';
}

function linhas_por_pagina(mixed $pedido): int
{
    $numero = (int) $pedido;

    return in_array($numero, PAGINAS_VALIDAS, true) ? $numero : LINHAS_POR_PAGINA_PADRAO;
}

function paginar(array $registros, int $pagina, int $porPagina): array
{
    $total   = count($registros);
    $paginas = max(1, (int) ceil($total / $porPagina));
    $pagina  = max(1, min($paginas, $pagina));

    return [
        'linhas'     => array_slice($registros, ($pagina - 1) * $porPagina, $porPagina),
        'pagina'     => $pagina,
        'paginas'    => $paginas,
        'total'      => $total,
        'primeira'   => $total === 0 ? 0 : ($pagina - 1) * $porPagina + 1,
        'ultima'     => min($total, $pagina * $porPagina),
        'por_pagina' => $porPagina,
    ];
}

/** Só os números de uma coluna, para alimentar os cálculos. */
function valores_da_coluna(array $registros, string $coluna): array
{
    if (!array_key_exists($coluna, COLUNAS_NUMERICAS)) {
        throw new InvalidArgumentException('Coluna não disponível para cálculo.');
    }

    return array_map(static fn(array $r): float => (float) $r[$coluna], $registros);
}

/** Agrupa e conta por uma coluna de categoria (dia, período, sexo, fumante). */
function contar_por(array $registros, string $coluna): array
{
    if (!in_array($coluna, ['sex', 'smoker', 'day', 'time'], true)) {
        throw new InvalidArgumentException('Coluna não agrupável.');
    }

    $grupos = [];

    foreach (COLUNAS_BASE[$coluna]['valores'] as $valor) {
        $grupos[$valor] = [
            'valor'        => $valor,
            'atendimentos' => 0,
            'soma'         => 0.0,
            'contas'       => 0.0,
            'media'        => 0.0,
        ];
    }

    foreach ($registros as $registro) {
        $chave = $registro[$coluna];
        $grupos[$chave]['atendimentos']++;
        $grupos[$chave]['soma']   += $registro['tip'];
        $grupos[$chave]['contas'] += $registro['total_bill'];
    }

    foreach ($grupos as $chave => $grupo) {
        $grupos[$chave]['soma']   = round($grupo['soma'], 2);
        $grupos[$chave]['contas'] = round($grupo['contas'], 2);
        $grupos[$chave]['media']  = $grupo['atendimentos'] > 0
            ? round($grupo['soma'] / $grupo['atendimentos'], 2)
            : 0.0;
    }

    return $grupos;
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

/** Monta uma URL da própria tela preservando filtros e trocando o que mudou. */
function url_com(string $pagina, array $atuais, array $mudancas): string
{
    $parametros = array_merge($atuais, $mudancas);

    foreach ($parametros as $chave => $valor) {
        if ($valor === '' || $valor === null) {
            unset($parametros[$chave]);
        }
    }

    return $parametros === []
        ? $pagina
        : $pagina . '?' . http_build_query($parametros);
}
