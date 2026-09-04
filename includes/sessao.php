<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/config.php';

function iniciar_sessao_segura(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.gc_maxlifetime', (string) TEMPO_MAXIMO_SESSAO);

    session_name(NOME_SESSAO);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
    enviar_cabecalhos_seguranca();
}

function enviar_cabecalhos_seguranca(): void
{
    if (headers_sent()) {
        return;
    }

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header("Content-Security-Policy: default-src 'self'; style-src 'self'; "
         . "script-src 'self'; img-src 'self' data:; form-action 'self'; "
         . "frame-ancestors 'none'; base-uri 'self'");
    header('Cache-Control: no-store, no-cache, must-revalidate');
}

function autenticar_usuario(string $login, array $dados): void
{
    session_regenerate_id(true);

    $_SESSION['autenticado'] = true;
    $_SESSION['login']       = $login;
    $_SESSION['nome']        = $dados['nome'];
    $_SESSION['papel']       = $dados['papel'];
    $_SESSION['criada_em']   = time();
    $_SESSION['ultimo_uso']  = time();
    $_SESSION['rotacao_em']  = time();
    $_SESSION['impressao']   = impressao_do_cliente();
}

function impressao_do_cliente(): string
{
    return hash('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? '') . '|' . NOME_SESSAO);
}

function exigir_login(): void
{
    if (empty($_SESSION['autenticado'])) {
        encerrar_sessao();
        redirecionar('index.php?motivo=login');
    }

    if (!hash_equals((string) ($_SESSION['impressao'] ?? ''), impressao_do_cliente())) {
        encerrar_sessao();
        redirecionar('index.php?motivo=invalida');
    }

    $agora = time();

    if ($agora - (int) $_SESSION['ultimo_uso'] > TEMPO_INATIVIDADE) {
        encerrar_sessao();
        redirecionar('index.php?motivo=inatividade');
    }

    if ($agora - (int) $_SESSION['criada_em'] > TEMPO_MAXIMO_SESSAO) {
        encerrar_sessao();
        redirecionar('index.php?motivo=expirada');
    }

    if ($agora - (int) $_SESSION['rotacao_em'] > INTERVALO_ROTACAO) {
        session_regenerate_id(true);
        $_SESSION['rotacao_em'] = $agora;
    }

    $_SESSION['ultimo_uso'] = $agora;
}

function encerrar_sessao(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies') && !headers_sent()) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'] ?? 'Lax',
        ]);
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

function papel_atual(): array
{
    $chave = (string) ($_SESSION['papel'] ?? '');

    return PAPEIS[$chave] ?? PAPEIS['consulta'];
}

function pode(string $permissao): bool
{
    return (bool) (papel_atual()[$permissao] ?? false);
}

function pode_operacao(string $operacao): bool
{
    return in_array($operacao, papel_atual()['operacoes'], true);
}

function operacoes_permitidas(): array
{
    return array_intersect_key(OPERACOES, array_flip(papel_atual()['operacoes']));
}

function exigir_permissao(string $permissao): void
{
    if (!pode($permissao)) {
        definir_aviso('Seu perfil não tem acesso a essa área do painel.', 'erro');
        redirecionar('menu.php');
    }
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf'];
}

function campo_csrf(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function validar_csrf(?string $enviado): bool
{
    return is_string($enviado)
        && !empty($_SESSION['csrf'])
        && hash_equals($_SESSION['csrf'], $enviado);
}

function h(mixed $valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirecionar(string $destino): never
{
    if (!headers_sent()) {
        header('Location: ' . $destino);
    }
    exit;
}

function definir_aviso(string $texto, string $tipo = 'erro'): void
{
    $_SESSION['aviso'] = ['texto' => $texto, 'tipo' => $tipo];
}

function obter_aviso(): ?array
{
    if (empty($_SESSION['aviso'])) {
        return null;
    }

    $aviso = $_SESSION['aviso'];
    unset($_SESSION['aviso']);

    return $aviso;
}

function traduzir(string $termo): string
{
    return TRADUCAO[$termo] ?? $termo;
}

function minusculo(string $texto): string
{
    return function_exists('mb_strtolower')
        ? mb_strtolower($texto, 'UTF-8')
        : strtolower($texto);
}

function moeda(float $valor): string
{
    return 'R$ ' . number_format($valor, 2, ',', '.');
}
