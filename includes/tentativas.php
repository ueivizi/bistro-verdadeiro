<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/config.php';

function tentativas_endereco(): string
{
    $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));

    return $ip !== '' ? $ip : 'desconhecido';
}

function tentativas_chave_usuario(string $login): string
{
    return 'u' . hash('sha256', strtolower(trim($login)) . '|' . tentativas_endereco());
}

function tentativas_chave_ip(): string
{
    return 'i' . hash('sha256', tentativas_endereco());
}

function tentativas_expurgar(array $dados): array
{
    $agora = time();
    $limpo = [];

    foreach ($dados as $chave => $registro) {
        if (!is_array($registro)) {
            continue;
        }

        $ultima = (int) ($registro['u'] ?? 0);
        $ate    = (int) ($registro['a'] ?? 0);

        if ($ate > $agora || $agora - $ultima < JANELA_TENTATIVAS) {
            $limpo[$chave] = ['f' => (int) ($registro['f'] ?? 0), 'u' => $ultima, 'a' => $ate];
        }
    }

    return $limpo;
}

function tentativas_transacao(callable $acao): mixed
{
    if (!is_dir(PASTA_VAR)) {
        @mkdir(PASTA_VAR, 0775, true);
    }

    if (!is_dir(PASTA_VAR)) {
        return $acao([])[1];
    }

    $arquivo = @fopen(PASTA_VAR . DIRECTORY_SEPARATOR . 'tentativas.json', 'c+');

    if ($arquivo === false) {
        return $acao([])[1];
    }

    if (!flock($arquivo, LOCK_EX)) {
        fclose($arquivo);

        return $acao([])[1];
    }

    $bruto = stream_get_contents($arquivo);
    $dados = json_decode((string) $bruto, true);
    $dados = is_array($dados) ? tentativas_expurgar($dados) : [];

    [$novo, $retorno] = $acao($dados);

    if ($novo !== null) {
        ftruncate($arquivo, 0);
        rewind($arquivo);
        fwrite($arquivo, (string) json_encode($novo));
        fflush($arquivo);
    }

    flock($arquivo, LOCK_UN);
    fclose($arquivo);

    return $retorno;
}

function tentativas_bloqueio_ate(string $login = ''): int
{
    return tentativas_transacao(static function (array $dados) use ($login): array {
        $agora = time();
        $ate   = (int) ($dados[tentativas_chave_ip()]['a'] ?? 0);

        if ($login !== '') {
            $ate = max($ate, (int) ($dados[tentativas_chave_usuario($login)]['a'] ?? 0));
        }

        return [null, $ate > $agora ? $ate : 0];
    });
}

function tentativas_registrar_falha(string $login): array
{
    return tentativas_transacao(static function (array $dados) use ($login): array {
        $agora = time();

        $chaveUsuario = tentativas_chave_usuario($login);
        $usuario      = $dados[$chaveUsuario] ?? ['f' => 0, 'u' => 0, 'a' => 0];

        $usuario['f'] = (int) $usuario['f'] + 1;
        $usuario['u'] = $agora;

        if ($usuario['f'] >= MAX_TENTATIVAS_USUARIO) {
            $usuario['a'] = $agora + BLOQUEIO_USUARIO;
            $usuario['f'] = 0;
        }

        $dados[$chaveUsuario] = $usuario;

        $chaveIp = tentativas_chave_ip();
        $endereco = $dados[$chaveIp] ?? ['f' => 0, 'u' => 0, 'a' => 0];

        $endereco['f'] = (int) $endereco['f'] + 1;
        $endereco['u'] = $agora;

        if ($endereco['f'] >= MAX_TENTATIVAS_IP) {
            $endereco['a'] = $agora + BLOQUEIO_IP;
            $endereco['f'] = 0;
        }

        $dados[$chaveIp] = $endereco;

        $ate = max((int) $usuario['a'], (int) $endereco['a']);

        return [$dados, [
            'ate'       => $ate > $agora ? $ate : 0,
            'restantes' => max(0, MAX_TENTATIVAS_USUARIO - (int) $usuario['f']),
        ]];
    });
}

function tentativas_limpar(string $login): void
{
    tentativas_transacao(static function (array $dados) use ($login): array {
        unset($dados[tentativas_chave_usuario($login)], $dados[tentativas_chave_ip()]);

        return [$dados, null];
    });
}
