<?php

declare(strict_types=1);

require_once __DIR__ . '/sessao.php';

function shell_disponivel(): bool
{
    static $disponivel = null;

    if ($disponivel !== null) {
        return $disponivel;
    }

    $desligadas = array_map('trim', explode(',', (string) ini_get('disable_functions')));

    if (!function_exists('shell_exec') || in_array('shell_exec', $desligadas, true)) {
        return $disponivel = false;
    }

    $teste = @shell_exec('bash -c "echo ok" 2>&1');

    return $disponivel = (is_string($teste) && trim($teste) === 'ok');
}

function caminho_da_base(): string
{
    $arquivo = realpath(ARQUIVO_DADOS);
    $pasta   = realpath(PASTA_DADOS);

    if ($arquivo === false || $pasta === false || !str_starts_with($arquivo, $pasta)) {
        throw new RuntimeException('Base de dados não encontrada em dados/gorjetas.csv.');
    }

    return $arquivo;
}

function minerar(string $operacao, string $dia = '', string $periodo = '', int $limite = 10): array
{
    if (!array_key_exists($operacao, OPERACOES)) {
        throw new InvalidArgumentException('Operação de mineração não reconhecida.');
    }

    if ($dia !== '' && !in_array($dia, DIAS_VALIDOS, true)) {
        throw new InvalidArgumentException('Dia da semana inválido.');
    }

    if ($periodo !== '' && !in_array($periodo, PERIODOS_VALIDOS, true)) {
        throw new InvalidArgumentException('Período inválido.');
    }

    $limite = max(1, min(50, $limite));
    $base   = caminho_da_base();

    if (!shell_disponivel()) {
        return [
            'origem'  => 'php',
            'dados'   => minerar_em_php($operacao, $dia, $periodo, $limite, $base),
            'bruto'   => "O bash não está acessível a partir do PHP nesta máquina.\n"
                       . "Os mesmos cálculos foram refeitos em PHP para a tela não quebrar.\n"
                       . "Para usar o script original, rode o Apache no Linux/WSL ou\n"
                       . "instale o Git Bash e deixe o bash no PATH do sistema.",
            'comando' => '',
        ];
    }

    $partes = [
        'bash',
        escapeshellarg(SCRIPT_MINERACAO),
        '-a', escapeshellarg($base),
        '-o', escapeshellarg($operacao),
        '-n', escapeshellarg((string) $limite),
    ];

    if ($dia !== '') {
        $partes[] = '-d';
        $partes[] = escapeshellarg($dia);
    }

    if ($periodo !== '') {
        $partes[] = '-p';
        $partes[] = escapeshellarg($periodo);
    }

    $comando = implode(' ', $partes);

    $json  = shell_exec($comando . ' -j 2>&1');
    $bruto = shell_exec($comando . ' 2>&1');

    $decodificado = json_decode((string) $json, true);

    if (!is_array($decodificado) || !array_key_exists('resultado', $decodificado)) {
        throw new RuntimeException(
            "O script de mineração não devolveu um resultado válido.\n" . trim((string) $json)
        );
    }

    return [
        'origem'  => 'shell',
        'dados'   => $decodificado['resultado'],
        'bruto'   => trim((string) $bruto),
        'comando' => $comando,
    ];
}

function ler_registros(string $arquivo, string $dia, string $periodo): array
{
    $registros = [];
    $handle    = fopen($arquivo, 'r');

    if ($handle === false) {
        throw new RuntimeException('Não foi possível abrir a base de dados.');
    }

    fgetcsv($handle, 0, ',', '"', '');

    while (($linha = fgetcsv($handle, 0, ',', '"', '')) !== false) {
        if (count($linha) < 7 || (float) $linha[0] <= 0) {
            continue;
        }

        if ($dia !== '' && $linha[4] !== $dia) {
            continue;
        }

        if ($periodo !== '' && $linha[5] !== $periodo) {
            continue;
        }

        $conta   = (float) $linha[0];
        $gorjeta = (float) $linha[1];

        $registros[] = [
            'conta'      => $conta,
            'gorjeta'    => $gorjeta,
            'sexo'       => $linha[2],
            'fumante'    => $linha[3],
            'dia'        => $linha[4],
            'periodo'    => $linha[5],
            'pessoas'    => (int) $linha[6],
            'percentual' => round($gorjeta / $conta * 100, 2),
        ];
    }

    fclose($handle);

    return $registros;
}

function minerar_em_php(string $operacao, string $dia, string $periodo, int $limite, string $base): mixed
{
    $registros = ler_registros($base, $dia, $periodo);

    if ($registros === []) {
        return in_array($operacao, ['ranking', 'dia'], true) ? [] : null;
    }

    switch ($operacao) {
        case 'maior':
            usort($registros, fn($a, $b) => $b['gorjeta'] <=> $a['gorjeta']);
            return $registros[0];

        case 'percentual':
            usort($registros, fn($a, $b) => $b['percentual'] <=> $a['percentual']);
            return $registros[0];

        case 'ranking':
            usort($registros, fn($a, $b) => $b['gorjeta'] <=> $a['gorjeta']);
            return array_slice($registros, 0, $limite);

        case 'dia':
            $grupos = [];
            foreach ($registros as $r) {
                $d = $r['dia'];
                $grupos[$d] ??= ['dia' => $d, 'atendimentos' => 0, 'soma' => 0.0,
                                 'contas' => 0.0, 'maior' => 0.0];
                $grupos[$d]['atendimentos']++;
                $grupos[$d]['soma']   += $r['gorjeta'];
                $grupos[$d]['contas'] += $r['conta'];
                $grupos[$d]['maior']   = max($grupos[$d]['maior'], $r['gorjeta']);
            }

            $saida = [];
            foreach ($grupos as $g) {
                $saida[] = [
                    'dia'          => $g['dia'],
                    'atendimentos' => $g['atendimentos'],
                    'soma'         => round($g['soma'], 2),
                    'media'        => round($g['soma'] / $g['atendimentos'], 2),
                    'maior'        => round($g['maior'], 2),
                    'percentual'   => round($g['soma'] / $g['contas'] * 100, 2),
                ];
            }
            return $saida;

        case 'resumo':
        default:
            $valores = array_column($registros, 'gorjeta');
            sort($valores);
            $total = count($valores);
            $meio  = intdiv($total, 2);

            return [
                'atendimentos' => $total,
                'soma'         => round(array_sum($valores), 2),
                'media'        => round(array_sum($valores) / $total, 2),
                'mediana'      => round($total % 2
                                    ? $valores[$meio]
                                    : ($valores[$meio - 1] + $valores[$meio]) / 2, 2),
                'minima'       => round(min($valores), 2),
                'maxima'       => round(max($valores), 2),
            ];
    }
}
