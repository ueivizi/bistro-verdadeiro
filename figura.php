<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/pandas.php';

/**
 * Serve uma figura do matplotlib como imagem.
 *
 * O PNG é desenhado agora, em var/, mandado para o navegador e apagado. Ele
 * nunca ganha URL própria: var/ é negada pelo .htaccess, e o único caminho
 * até a imagem passa por aqui, depois do login e da conferência de papel.
 *
 * Esta página devolve bytes, não HTML. Por isso ela termina em exit() em
 * todos os caminhos, e nada é impresso antes dos cabeçalhos.
 */

iniciar_sessao_segura();
exigir_login();
exigir_permissao('ver_graficos');

$tipo   = figura_valida((string) ($_GET['fig'] ?? 'dispersao'));
$grupo  = agrupamento_valido((string) ($_GET['g'] ?? 'periodo'));
$medida = medida_valida((string) ($_GET['m'] ?? 'conta'));

try {
    $arquivo = figura_pandas($tipo, $grupo, $medida);
} catch (Throwable $e) {
    responder_sem_imagem(500, mensagem_para_usuario($e));
}

if ($arquivo === null) {
    responder_sem_imagem(
        503,
        'Nenhum python com pandas respondeu neste servidor, então não há figura para desenhar.'
    );
}

$bytes = @file_get_contents($arquivo);
@unlink($arquivo);

if ($bytes === false || $bytes === '') {
    responder_sem_imagem(500, 'A figura foi desenhada mas não deu para ler o arquivo.');
}

if (!headers_sent()) {
    header('Content-Type: image/png');
    header('Content-Length: ' . strlen($bytes));
    header('Content-Disposition: inline; filename="' . $tipo . '.png"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
}

echo $bytes;
exit;

/**
 * Sai com um texto curto em vez de uma imagem. O <img> da página quebra, e é
 * a legenda abaixo dele que explica o que aconteceu — melhor do que devolver
 * um PNG que diz "deu erro" e que o navegador guardaria como se fosse figura.
 */
function responder_sem_imagem(int $codigo, string $motivo): never
{
    if (!headers_sent()) {
        http_response_code($codigo);
        header('Content-Type: text/plain; charset=UTF-8');
        header('Cache-Control: private, no-store');
    }

    echo $motivo, "\n";
    exit;
}
