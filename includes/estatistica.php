<?php

declare(strict_types=1);

require_once __DIR__ . '/sessao.php';

/**
 * Medidas estatísticas sobre uma lista de números.
 *
 * Todas as funções daqui recebem a série já limpa por serie_de_numeros() e
 * devolvem null quando a série está vazia — nenhuma inventa zero para uma
 * conta que não existe.
 */

/** Deixa passar só número finito. Texto, NAN e INF caem fora. */
function serie_de_numeros(array $valores): array
{
    $serie = [];

    foreach ($valores as $valor) {
        if (is_int($valor) || is_float($valor)) {
            $numero = (float) $valor;
        } elseif (is_string($valor)) {
            $convertido = filter_var(trim($valor), FILTER_VALIDATE_FLOAT);

            if ($convertido === false) {
                continue;
            }

            $numero = (float) $convertido;
        } else {
            continue;
        }

        if (!is_finite($numero)) {
            continue;
        }

        $serie[] = $numero;
    }

    return $serie;
}

/**
 * Lê números de um texto digitado pela pessoa.
 *
 * Separadores aceitos: quebra de linha, espaço, tabulação, ponto-e-vírgula e
 * vírgula. A vírgula é ambígua em português — "3,50" é um número e "3,50" numa
 * lista pode ser dois. A regra é: se TODOS os pedaços tiverem a forma de
 * número com uma vírgula só e nenhum tiver ponto, a vírgula é decimal; fora
 * disso, ela separa a lista. O que o sistema entendeu volta na tela para
 * conferência, que é a rede de segurança de verdade.
 *
 * Devolve ['valores' => float[], 'recusados' => string[], 'cortou' => bool].
 */
/**
 * Desfaz a notação brasileira cheia — 1.234.567,89 — antes de qualquer outra
 * decisão. Esse formato não se confunde com nada: ponto de milhar sempre vem
 * em grupos de exatamente três dígitos, coisa que um decimal nunca faz.
 */
function normalizar_numero_digitado(string $bruto): string
{
    if (preg_match('/^-?\d{1,3}(?:\.\d{3})+(?:,\d+)?$/', $bruto) === 1) {
        return str_replace(['.', ','], ['', '.'], $bruto);
    }

    return $bruto;
}

function numeros_do_texto(string $texto, int $maximo = MAX_VALORES_DIGITADOS): array
{
    $pedacos = preg_split('/[\s;]+/u', trim($texto), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $pedacos = array_map(static fn(string $p): string => trim($p, ','), $pedacos);
    $pedacos = array_values(array_filter($pedacos, static fn(string $p): bool => $p !== ''));
    $pedacos = array_map('normalizar_numero_digitado', $pedacos);

    $temPonto     = false;
    $temVirgula   = false;
    $todosDecimal = true;

    foreach ($pedacos as $pedaco) {
        if (str_contains($pedaco, '.')) {
            $temPonto = true;
        }

        if (str_contains($pedaco, ',')) {
            $temVirgula = true;
        }

        if (preg_match('/^-?\d+(?:,\d+)?$/', $pedaco) !== 1) {
            $todosDecimal = false;
        }
    }

    $virgulaEDecimal = $temVirgula && !$temPonto && $todosDecimal;

    $itens = [];

    foreach ($pedacos as $pedaco) {
        if ($virgulaEDecimal) {
            $itens[] = str_replace(',', '.', $pedaco);
            continue;
        }

        foreach (explode(',', $pedaco) as $parte) {
            if (trim($parte) !== '') {
                $itens[] = trim($parte);
            }
        }
    }

    $valores   = [];
    $recusados = [];
    $cortou    = false;

    foreach ($itens as $item) {
        if (count($valores) >= $maximo) {
            $cortou = true;
            break;
        }

        $numero = filter_var($item, FILTER_VALIDATE_FLOAT);

        if ($numero === false || !is_finite((float) $numero)) {
            if (count($recusados) < 20) {
                $recusados[] = $item;
            }
            continue;
        }

        $valores[] = (float) $numero;
    }

    return ['valores' => $valores, 'recusados' => $recusados, 'cortou' => $cortou];
}

// ---------------------------------------------------------------------------
// Medidas de posição
// ---------------------------------------------------------------------------

function soma_da_serie(array $serie): float
{
    return array_sum($serie);
}

function media_da_serie(array $serie): ?float
{
    $total = count($serie);

    return $total === 0 ? null : array_sum($serie) / $total;
}

function mediana_da_serie(array $serie): ?float
{
    $total = count($serie);

    if ($total === 0) {
        return null;
    }

    sort($serie);
    $meio = intdiv($total, 2);

    return $total % 2 === 1
        ? $serie[$meio]
        : ($serie[$meio - 1] + $serie[$meio]) / 2;
}

/**
 * Valores mais repetidos.
 *
 * Devolve ['valores' => [], 'vezes' => 0] quando ninguém se repete — série sem
 * repetição não tem moda, e apontar um valor qualquer como moda seria mentira.
 */
function moda_da_serie(array $serie): array
{
    $vazia = ['valores' => [], 'vezes' => 0];

    if ($serie === []) {
        return $vazia;
    }

    $contagem = [];

    foreach ($serie as $valor) {
        $chave = (string) $valor;
        $contagem[$chave] = ($contagem[$chave] ?? 0) + 1;
    }

    $maior = max($contagem);

    if ($maior < 2) {
        return $vazia;
    }

    $moda = [];

    foreach ($contagem as $chave => $vezes) {
        if ($vezes === $maior) {
            $moda[] = (float) $chave;
        }
    }

    sort($moda);

    return ['valores' => $moda, 'vezes' => $maior];
}

/** Todas as medidas de posição de uma vez, ou null se a série está vazia. */
function medidas_de_posicao(array $serie): ?array
{
    $total = count($serie);

    if ($total === 0) {
        return null;
    }

    return [
        'contagem'  => $total,
        'soma'      => soma_da_serie($serie),
        'media'     => media_da_serie($serie),
        'mediana'   => mediana_da_serie($serie),
        'moda'      => moda_da_serie($serie),
        'minimo'    => min($serie),
        'maximo'    => max($serie),
        'amplitude' => max($serie) - min($serie),
    ];
}
