<?php

declare(strict_types=1);

require_once __DIR__ . '/dados.php';

/**
 * Descobre por qual bash o script deve rodar.
 *
 * Chamar 'bash' e confiar no PATH não serve no Windows: o lançador do WSL mora
 * em C:\Windows\System32 e vem antes do Git na lista, então 'bash' resolve
 * para ele. Sem distro instalada, o WSL responde
 *
 *   execvpe(/bin/bash) failed: No such file or directory
 *
 * e o sistema concluiria que não há shell nenhum — caindo no plano B em PHP com
 * o Git Bash instalado do lado, sem ninguém entender o porquê. Por isso o Git
 * Bash é procurado pelo caminho, antes de tentar o PATH.
 *
 * Devolve o caminho do bash que respondeu, ou null se nenhum respondeu.
 */
function caminho_do_bash(): ?string
{
    static $resolvido = null;
    static $procurado = false;

    if ($procurado) {
        return $resolvido;
    }

    $procurado  = true;
    $desligadas = array_map('trim', explode(',', (string) ini_get('disable_functions')));

    if (!function_exists('shell_exec') || in_array('shell_exec', $desligadas, true)) {
        return $resolvido = null;
    }

    foreach (candidatos_de_bash() as $candidato) {
        if (bash_responde($candidato)) {
            return $resolvido = $candidato;
        }
    }

    return $resolvido = null;
}

/** Onde procurar, em ordem de preferência. */
function candidatos_de_bash(): array
{
    $lista = [];

    if (CAMINHO_BASH !== '') {
        $lista[] = CAMINHO_BASH;
    }

    if (DIRECTORY_SEPARATOR === '\\') {
        $raizes = [
            getenv('ProgramW6432'),
            getenv('ProgramFiles'),
            getenv('ProgramFiles(x86)'),
            'C:\\Program Files',
            'C:\\Program Files (x86)',
        ];

        foreach ($raizes as $raiz) {
            if (!is_string($raiz) || $raiz === '') {
                continue;
            }

            $raiz    = rtrim($raiz, '\\');
            $lista[] = $raiz . '\\Git\\bin\\bash.exe';
            $lista[] = $raiz . '\\Git\\usr\\bin\\bash.exe';
        }
    }

    // Por último o PATH, que é o caminho certo no Linux e no macOS.
    $lista[] = 'bash';

    return array_values(array_unique($lista));
}

/** Só aceita um bash que responda de verdade, não um que apenas exista. */
function bash_responde(string $caminho): bool
{
    if ($caminho !== 'bash' && !is_file($caminho)) {
        return false;
    }

    $saida = @shell_exec(
        escapeshellarg($caminho) . ' -c ' . escapeshellarg('echo ok') . ' 2>&1'
    );

    return is_string($saida) && trim($saida) === 'ok';
}

function shell_disponivel(): bool
{
    return caminho_do_bash() !== null;
}

/** Texto que explica por que o plano B em PHP entrou em cena. */
function explicacao_do_plano_b(): string
{
    $procurados = implode("\n  ", candidatos_de_bash());

    return "Nenhum bash respondeu, então os mesmos cálculos foram refeitos em PHP\n"
         . "para a tela não quebrar. O resultado é o mesmo; o que falta é o script.\n\n"
         . "Procurei nesta ordem:\n  " . $procurados . "\n\n"
         . "No Windows, instale o Git Bash — ele é encontrado pelo caminho de\n"
         . "instalação, sem depender do PATH. Se ele estiver em outro lugar,\n"
         . "aponte o caminho em CAMINHO_BASH, dentro de config/config.php.";
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
        escapeshellarg((string) caminho_do_bash()),
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

/**
 * Mesma leitura de includes/dados.php, devolvida com os nomes em português que
 * as telas de mineração já usavam desde o começo.
 */
function ler_registros(string $arquivo, string $dia, string $periodo): array
{
    $filtros = normalizar_filtros(['day' => $dia, 'time' => $periodo]);

    $registros = [];

    foreach (filtrar_registros(ler_base($arquivo), $filtros) as $r) {
        $registros[] = [
            'conta'      => $r['total_bill'],
            'gorjeta'    => $r['tip'],
            'sexo'       => $r['sex'],
            'fumante'    => $r['smoker'],
            'dia'        => $r['day'],
            'periodo'    => $r['time'],
            'pessoas'    => $r['size'],
            'percentual' => $r['percentual'],
        ];
    }

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
