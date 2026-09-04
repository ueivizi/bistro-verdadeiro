<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/sessao.php';
require_once __DIR__ . '/includes/tentativas.php';

iniciar_sessao_segura();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirecionar('index.php');
}

if (!validar_csrf($_POST['csrf'] ?? null)) {
    definir_aviso('Formulário inválido ou expirado. Tente novamente.');
    redirecionar('index.php');
}

$usuario = trim((string) ($_POST['usuario'] ?? ''));
$senha   = (string) ($_POST['senha'] ?? '');

if ($usuario === '' || $senha === '') {
    definir_aviso('Preencha usuário e senha.');
    redirecionar('index.php');
}

$bloqueadoAte = tentativas_bloqueio_ate($usuario);

if ($bloqueadoAte > time()) {
    definir_aviso(
        'Muitas tentativas seguidas. Tente de novo em '
        . (int) ceil(($bloqueadoAte - time()) / 60) . ' minuto(s).'
    );
    redirecionar('index.php');
}

$dados = USUARIOS[$usuario] ?? null;

$hash = $dados['senha']
    ?? '$2y$10$ZtA0UVb0g3GchQ89HYhia.qEqpzclmKRK3C0xtYkNLrsDvaN5kgCC';

$senhaConfere = password_verify($senha, $hash);

if ($dados === null || !$senhaConfere) {
    $estado = tentativas_registrar_falha($usuario);

    if ($estado['ate'] > time()) {
        definir_aviso(
            'Muitas tentativas seguidas. Acesso suspenso por '
            . (int) ceil(($estado['ate'] - time()) / 60) . ' minuto(s).'
        );
    } else {
        definir_aviso(
            "Usuário ou senha incorretos. Tentativas restantes: {$estado['restantes']}."
        );
    }

    usleep(300000);
    redirecionar('index.php');
}

tentativas_limpar($usuario);
autenticar_usuario($usuario, $dados);

redirecionar('menu.php');
