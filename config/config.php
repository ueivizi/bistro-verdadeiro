<?php

declare(strict_types=1);

const NOME_SISTEMA = 'Bistrô Verdadeiro';
const NOME_SESSAO  = 'BISTROSESSID';

const TEMPO_INATIVIDADE   = 20 * 60;
const TEMPO_MAXIMO_SESSAO = 2 * 60 * 60;
const INTERVALO_ROTACAO   = 15 * 60;

const MAX_TENTATIVAS_USUARIO = 5;
const BLOQUEIO_USUARIO       = 120;
const MAX_TENTATIVAS_IP      = 15;
const BLOQUEIO_IP            = 300;
const JANELA_TENTATIVAS      = 15 * 60;

define('CAMINHO_BASE',     dirname(__DIR__));
define('PASTA_DADOS',      CAMINHO_BASE . DIRECTORY_SEPARATOR . 'dados');
define('PASTA_VAR',        CAMINHO_BASE . DIRECTORY_SEPARATOR . 'var');
define('ARQUIVO_DADOS',    PASTA_DADOS  . DIRECTORY_SEPARATOR . 'gorjetas.csv');
define('SCRIPT_MINERACAO', CAMINHO_BASE . DIRECTORY_SEPARATOR . 'scripts'
                                        . DIRECTORY_SEPARATOR . 'mineracao.sh');

const USUARIOS = [
    'admin' => [
        'nome'  => 'Ana Ribeiro',
        'papel' => 'gerente',
        'senha' => '$2y$10$suna1YQzGGd385rhpARCce5ZhlYJ6A8nCNnslDi9ltvs/VyBH88A.',
    ],
    'aluno' => [
        'nome'  => 'Visitante',
        'papel' => 'consulta',
        'senha' => '$2y$10$qyCixBnMecq4YiOuAvXM2Oc4hdqBDBegCO7g7FTD3T.427Ee75aY2',
    ],
];

const PAPEIS = [
    'gerente' => [
        'rotulo'       => 'Gerente do salão',
        'resumo'       => 'Acesso completo: todas as análises, a base bruta e a saída do shell script.',
        'operacoes'    => ['maior', 'percentual', 'ranking', 'dia', 'resumo'],
        'ver_base'     => true,
        'ver_terminal' => true,
    ],
    'consulta' => [
        'rotulo'       => 'Consulta',
        'resumo'       => 'Somente os números agregados do salão. Não vê mesas individuais nem a base bruta.',
        'operacoes'    => ['dia', 'resumo'],
        'ver_base'     => false,
        'ver_terminal' => false,
    ],
];

const OPERACOES = [
    'maior'      => 'Maior gorjeta em reais',
    'percentual' => 'Maior gorjeta proporcional à conta',
    'ranking'    => 'As dez maiores gorjetas',
    'dia'        => 'Comparativo por dia da semana',
    'resumo'     => 'Resumo estatístico da base',
];

const DIAS_VALIDOS     = ['Thur', 'Fri', 'Sat', 'Sun'];
const PERIODOS_VALIDOS = ['Lunch', 'Dinner'];

const TRADUCAO = [
    'Thur'   => 'Quinta',
    'Fri'    => 'Sexta',
    'Sat'    => 'Sábado',
    'Sun'    => 'Domingo',
    'Lunch'  => 'Almoço',
    'Dinner' => 'Jantar',
    'Male'   => 'Homem',
    'Female' => 'Mulher',
    'Yes'    => 'Sim',
    'No'     => 'Não',
];
