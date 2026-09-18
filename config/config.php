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
define('ARQUIVO_BASE_ATIVA', PASTA_VAR   . DIRECTORY_SEPARATOR . 'gorjetas-ativa.csv');
define('ARQUIVO_BASE_ANTERIOR', PASTA_VAR . DIRECTORY_SEPARATOR . 'gorjetas-anterior.csv');
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
        'ver_dados'    => true,
        'ver_analises' => true,
        'ver_graficos' => true,
        'enviar_dados' => true,
        'ver_terminal' => true,
    ],
    'consulta' => [
        'rotulo'       => 'Consulta',
        'resumo'       => 'Somente os números agregados do salão. Não vê mesas individuais nem a base bruta.',
        'operacoes'    => ['dia', 'resumo'],
        'ver_base'     => false,
        'ver_dados'    => false,
        'ver_analises' => true,
        'ver_graficos' => true,
        'enviar_dados' => false,
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

// Caminho do bash que roda scripts/mineracao.sh. Vazio deixa o sistema
// procurar sozinho: primeiro o Git Bash pelo caminho de instalação, depois o
// PATH. Preencha só se o bash desta máquina estiver fora do lugar de sempre.
const CAMINHO_BASH = '';

const DIAS_VALIDOS     = ['Thur', 'Fri', 'Sat', 'Sun'];
const PERIODOS_VALIDOS = ['Lunch', 'Dinner'];
const SEXOS_VALIDOS    = ['Male', 'Female'];
const FUMANTES_VALIDOS = ['Yes', 'No'];

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

// ---------------------------------------------------------------------------
// Forma da base
// ---------------------------------------------------------------------------
// Esta é a única descrição do que é um atendimento válido. Toda entrada de
// dado — a leitura do CSV, os filtros da tela, o que chegar por upload — é
// conferida contra ela antes de virar registro. Campo fora daqui não entra.
//
// 'sinonimos' existe só para aceitar planilha escrita em português: o valor
// gravado é sempre o canônico da lista 'valores', nunca o que a pessoa enviou.

const COLUNAS_BASE = [
    'total_bill' => [
        'rotulo'  => 'Conta',
        'tipo'    => 'decimal',
        'min'     => 0.01,
        'max'     => 99999.99,
        'ajuda'   => 'Valor total da conta da mesa.',
        'cabecalhos' => ['total_bill', 'conta', 'valor', 'total', 'valor_conta', 'valor da conta'],
    ],
    'tip' => [
        'rotulo'  => 'Gorjeta',
        'tipo'    => 'decimal',
        'min'     => 0.0,
        'max'     => 99999.99,
        'ajuda'   => 'Gorjeta deixada pelo cliente.',
        'cabecalhos' => ['tip', 'gorjeta', 'caixinha'],
    ],
    'sex' => [
        'rotulo'    => 'Cliente',
        'tipo'      => 'enum',
        'valores'   => SEXOS_VALIDOS,
        'sinonimos' => [
            'm' => 'Male',   'masculino' => 'Male',   'homem'  => 'Male',
            'f' => 'Female', 'feminino'  => 'Female', 'mulher' => 'Female',
        ],
        'ajuda'   => 'Sexo de quem pagou a conta.',
        'cabecalhos' => ['sex', 'sexo', 'cliente', 'genero', 'gênero'],
    ],
    'smoker' => [
        'rotulo'    => 'Fumante',
        'tipo'      => 'enum',
        'valores'   => FUMANTES_VALIDOS,
        'sinonimos' => [
            's' => 'Yes', 'sim' => 'Yes', '1' => 'Yes', 'true'  => 'Yes',
            'n' => 'No',  'nao' => 'No',  'não' => 'No', '0' => 'No', 'false' => 'No',
        ],
        'ajuda'   => 'Havia fumante na mesa.',
        'cabecalhos' => ['smoker', 'fumante', 'fumantes'],
    ],
    'day' => [
        'rotulo'    => 'Dia',
        'tipo'      => 'enum',
        'valores'   => DIAS_VALIDOS,
        'sinonimos' => [
            'quinta'  => 'Thur', 'qui' => 'Thur', 'thu'     => 'Thur', 'thursday' => 'Thur',
            'sexta'   => 'Fri',  'sex' => 'Fri',  'friday'  => 'Fri',
            'sabado'  => 'Sat',  'sáb' => 'Sat',  'sab'     => 'Sat', 'saturday' => 'Sat',
            'domingo' => 'Sun',  'dom' => 'Sun',  'sunday'  => 'Sun',
        ],
        'ajuda'   => 'Dia da semana do atendimento.',
        'cabecalhos' => ['day', 'dia', 'dia_semana', 'dia da semana'],
    ],
    'time' => [
        'rotulo'    => 'Período',
        'tipo'      => 'enum',
        'valores'   => PERIODOS_VALIDOS,
        'sinonimos' => [
            'almoco' => 'Lunch',  'almoço' => 'Lunch',
            'jantar' => 'Dinner', 'janta'  => 'Dinner', 'noite' => 'Dinner',
        ],
        'ajuda'   => 'Almoço ou jantar.',
        'cabecalhos' => ['time', 'periodo', 'período', 'turno', 'refeicao', 'refeição'],
    ],
    'size' => [
        'rotulo'  => 'Pessoas',
        'tipo'    => 'inteiro',
        'min'     => 1,
        'max'     => 50,
        'ajuda'   => 'Quantas pessoas estavam à mesa.',
        'cabecalhos' => ['size', 'pessoas', 'tamanho', 'mesa', 'qtd_pessoas', 'pessoas à mesa'],
    ],
];

// Colunas numéricas que a tela de análises pode medir. As duas últimas não
// existem no arquivo: saem de conta e gorjeta na hora da leitura.
const COLUNAS_NUMERICAS = [
    'tip'        => ['rotulo' => 'Gorjeta',                 'formato' => 'moeda'],
    'total_bill' => ['rotulo' => 'Conta',                   'formato' => 'moeda'],
    'percentual' => ['rotulo' => 'Gorjeta sobre a conta',   'formato' => 'porcento'],
    'por_pessoa' => ['rotulo' => 'Gorjeta por pessoa',      'formato' => 'moeda'],
    'size'       => ['rotulo' => 'Pessoas à mesa',          'formato' => 'numero'],
];

// Teto de valores que a caixa de digitação aceita de uma vez.
const MAX_VALORES_DIGITADOS = 5000;

// Colunas pelas quais a tabela pode ser ordenada.
const ORDENACOES_VALIDAS = [
    'n', 'total_bill', 'tip', 'percentual', 'por_pessoa', 'sex', 'smoker', 'day', 'time', 'size',
];

// Colunas numéricas que aceitam filtro por faixa (de / até).
const FAIXAS_FILTRAVEIS = [
    'total_bill' => 'Conta (R$)',
    'tip'        => 'Gorjeta (R$)',
    'percentual' => 'Proporção (%)',
    'size'       => 'Pessoas à mesa',
];

const PAGINAS_VALIDAS = [25, 50, 100, 250];
const LINHAS_POR_PAGINA_PADRAO = 50;

// Limites do envio de arquivo. Conferidos no servidor, nunca só no formulário:
// MAX_FILE_SIZE do HTML é sugestão ao navegador, não controle.
const MAX_BYTES_UPLOAD  = 2 * 1024 * 1024;
const MAX_LINHAS_UPLOAD = 5000;
const MAX_ERROS_MOSTRADOS = 30;
const MAX_PREVIA_LINHAS   = 25;

// Teto de linhas que a base ativa aceita guardar. Impede que um arquivo
// grande demais derrube a leitura por falta de memória.
const MAX_REGISTROS_BASE = 20000;
