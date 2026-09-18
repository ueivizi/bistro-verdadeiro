<?php

declare(strict_types=1);

require_once __DIR__ . '/dados.php';

/**
 * Leitura de um CSV enviado pela pessoa.
 *
 * A decisão que sustenta a segurança daqui: o arquivo enviado nunca é gravado
 * em lugar nenhum. Ele é lido do temporário do PHP, cada linha é conferida
 * contra COLUNAS_BASE, e o que entra na base são registros montados por
 * validar_registro() — não o conteúdo do arquivo. Com isso não existe arquivo
 * do usuário dentro da pasta servida pelo Apache, e toda a família de ataque
 * que depende de "subir um .php e abrir pela URL" simplesmente não tem onde
 * acontecer. O nome original também não é usado para nada: não vira caminho,
 * não vira chave, só aparece na tela depois de escapado.
 */

/** Traduz o código de erro do próprio PHP para uma frase que ajuda. */
function erro_de_upload(int $codigo): ?string
{
    return match ($codigo) {
        UPLOAD_ERR_OK         => null,
        UPLOAD_ERR_INI_SIZE,
        UPLOAD_ERR_FORM_SIZE  => 'O arquivo passou do tamanho que o servidor aceita ('
                               . round(MAX_BYTES_UPLOAD / 1024 / 1024, 1) . ' MB).',
        UPLOAD_ERR_PARTIAL    => 'O envio foi interrompido no meio. Tente de novo.',
        UPLOAD_ERR_NO_FILE    => 'Nenhum arquivo foi escolhido.',
        UPLOAD_ERR_NO_TMP_DIR => 'O servidor está sem pasta temporária para receber o envio.',
        UPLOAD_ERR_CANT_WRITE => 'O servidor não conseguiu gravar o arquivo temporário.',
        UPLOAD_ERR_EXTENSION  => 'Uma extensão do PHP barrou este envio.',
        default               => 'O envio falhou por um motivo que o servidor não soube explicar.',
    };
}

/**
 * Confere o que chegou em $_FILES e devolve o caminho temporário.
 *
 * O tamanho é conferido aqui, no servidor. O MAX_FILE_SIZE do formulário é um
 * pedido ao navegador, e quem posta direto no endpoint simplesmente não o
 * manda. is_uploaded_file() garante que o caminho veio mesmo de um upload
 * desta requisição, e não de um caminho do servidor colado no lugar.
 */
function receber_arquivo_enviado(mixed $arquivo): string
{
    if (!is_array($arquivo) || !isset($arquivo['tmp_name'], $arquivo['error'])) {
        throw new FalhaDeDominio('Nenhum arquivo chegou ao servidor.');
    }

    if (is_array($arquivo['tmp_name'])) {
        throw new FalhaDeDominio('Envie um arquivo de cada vez.');
    }

    $falha = erro_de_upload((int) $arquivo['error']);

    if ($falha !== null) {
        throw new FalhaDeDominio($falha);
    }

    $caminho = (string) $arquivo['tmp_name'];

    if (!is_uploaded_file($caminho)) {
        throw new FalhaDeDominio('O arquivo indicado não veio de um envio desta página.');
    }

    if ((int) ($arquivo['size'] ?? 0) > MAX_BYTES_UPLOAD || filesize($caminho) > MAX_BYTES_UPLOAD) {
        throw new FalhaDeDominio(
            'O arquivo passou de ' . round(MAX_BYTES_UPLOAD / 1024 / 1024, 1) . ' MB.'
        );
    }

    return $caminho;
}

/**
 * Lê o arquivo para memória e acerta BOM, codificação e quebra de linha.
 *
 * Planilha exportada no Windows costuma vir em Windows-1252, e aí "Sábado" e
 * "Almoço" chegariam quebrados e seriam recusados por um motivo que não é
 * culpa de quem enviou.
 */
function abrir_conteudo_normalizado(string $caminho): mixed
{
    $conteudo = @file_get_contents($caminho, false, null, 0, MAX_BYTES_UPLOAD + 1);

    if ($conteudo === false) {
        throw new FalhaDeDominio('Não foi possível ler o arquivo enviado.');
    }

    if (strlen($conteudo) > MAX_BYTES_UPLOAD) {
        throw new FalhaDeDominio(
            'O arquivo passou de ' . round(MAX_BYTES_UPLOAD / 1024 / 1024, 1) . ' MB.'
        );
    }

    if (str_starts_with($conteudo, "\xEF\xBB\xBF")) {
        $conteudo = substr($conteudo, 3);
    }

    if (function_exists('mb_check_encoding') && !mb_check_encoding($conteudo, 'UTF-8')) {
        $conteudo = mb_convert_encoding($conteudo, 'UTF-8', 'Windows-1252');
    }

    $conteudo = str_replace(["\r\n", "\r"], "\n", $conteudo);

    $fluxo = fopen('php://temp', 'r+');

    if ($fluxo === false) {
        throw new FalhaDeDominio('O servidor não conseguiu preparar a leitura do arquivo.');
    }

    fwrite($fluxo, $conteudo);
    rewind($fluxo);

    return $fluxo;
}

/** Descobre o separador olhando a primeira linha: vírgula, ponto-e-vírgula ou tabulação. */
function descobrir_separador(mixed $fluxo): string
{
    $primeira = (string) fgets($fluxo);
    rewind($fluxo);

    $candidatos = [',' => substr_count($primeira, ','),
                   ';' => substr_count($primeira, ';'),
                   "\t" => substr_count($primeira, "\t")];

    arsort($candidatos);
    $vencedor = array_key_first($candidatos);

    return $candidatos[$vencedor] > 0 ? (string) $vencedor : ',';
}

/**
 * Tenta reconhecer a primeira linha como cabeçalho.
 *
 * Devolve um mapa coluna => posição, ou null se a linha não parece cabeçalho
 * (e aí as colunas são lidas pela ordem do arquivo). Só entra no mapa nome que
 * está na lista de 'cabecalhos' da coluna: texto desconhecido é ignorado, não
 * vira coluna nova.
 */
function mapear_cabecalho(array $primeira): ?array
{
    $mapa = [];

    foreach ($primeira as $posicao => $celula) {
        $nome = minusculo(trim((string) $celula, " \t\"'"));

        if ($nome === '') {
            continue;
        }

        foreach (COLUNAS_BASE as $coluna => $meta) {
            if (isset($mapa[$coluna])) {
                continue;
            }

            if (in_array($nome, array_map('minusculo', $meta['cabecalhos']), true)) {
                $mapa[$coluna] = (int) $posicao;
                break;
            }
        }
    }

    // Cabeçalho pela metade é pior que cabeçalho nenhum: a coluna que faltou
    // seria lida da posição errada sem ninguém perceber.
    return count($mapa) === count(COLUNAS_BASE) ? $mapa : null;
}

/**
 * Lê o arquivo inteiro e devolve o que entrou e o que não entrou.
 *
 * ['registros' => array[], 'erros' => [['linha' => int, 'motivos' => string[]]],
 *  'lidas' => int, 'cortou' => bool, 'cabecalho' => bool, 'separador' => string]
 */
function importar_csv(string $caminho): array
{
    $fluxo      = abrir_conteudo_normalizado($caminho);
    $separador  = descobrir_separador($fluxo);
    $registros  = [];
    $erros      = [];
    $lidas      = 0;
    $cortou     = false;
    $mapa       = null;
    $temCabecalho = false;
    $numero     = 0;

    try {
        while (($linha = fgetcsv($fluxo, 0, $separador, '"', '')) !== false) {
            $numero++;

            // Linha em branco não é erro de ninguém.
            if ($linha === [null] || array_filter($linha, static fn($c): bool => trim((string) $c) !== '') === []) {
                continue;
            }

            if ($numero === 1) {
                $mapa = mapear_cabecalho($linha);

                if ($mapa !== null) {
                    $temCabecalho = true;
                    continue;
                }

                // Sem mapa completo, se a primeira célula não é número a linha
                // ainda é um cabeçalho — só que com nomes que não reconhecemos.
                if (!is_numeric(trim((string) ($linha[0] ?? '')))) {
                    $temCabecalho = true;
                    continue;
                }
            }

            if (count($registros) >= MAX_LINHAS_UPLOAD) {
                $cortou = true;
                break;
            }

            $lidas++;

            $bruto = $mapa === null
                ? linha_para_associativo($linha)
                : array_map(
                    static fn(int $posicao): string => trim((string) ($linha[$posicao] ?? '')),
                    $mapa
                );

            $conferido = validar_registro($bruto);

            if ($conferido['registro'] === null) {
                if (count($erros) < MAX_ERROS_MOSTRADOS) {
                    $erros[] = ['linha' => $numero, 'motivos' => $conferido['erros']];
                }
                continue;
            }

            $registros[] = $conferido['registro'];
        }
    } finally {
        fclose($fluxo);
    }

    return [
        'registros' => $registros,
        'erros'     => $erros,
        'lidas'     => $lidas,
        'cortou'    => $cortou,
        'cabecalho' => $temCabecalho,
        'separador' => $separador,
    ];
}

/** Nome do separador, para dizer na tela o que foi entendido. */
function nome_do_separador(string $separador): string
{
    return match ($separador) {
        ';'  => 'ponto-e-vírgula',
        "\t" => 'tabulação',
        default => 'vírgula',
    };
}
