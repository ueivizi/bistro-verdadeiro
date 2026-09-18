<?php

declare(strict_types=1);

/**
 * Separa a falha que o sistema sabe explicar da falha que ele não sabe.
 *
 * As telas mostram o texto de uma FalhaDeDominio porque esse texto foi escrito
 * para ser lido por quem usa o painel: "a base em var/ não está gravável",
 * "o arquivo passou de 2 MB". São frases nossas, conferidas uma a uma, e
 * nenhuma delas carrega caminho de servidor, nome de arquivo ou estado interno.
 *
 * Qualquer outra exceção é tratada como desconhecida e sai como uma frase
 * genérica. Exceção do próprio PHP devolve no getMessage() o argumento que
 * recebeu — DateMalformedStringException, por exemplo, ecoa a string inteira —
 * e repassar isso para a tela é entregar ao visitante um pedaço do que se
 * passa dentro do servidor, em troca de nada. O texto de verdade vai para o
 * log de erro do PHP, onde quem administra encontra.
 */
class FalhaDeDominio extends RuntimeException
{
}

function mensagem_para_usuario(Throwable $e): string
{
    if ($e instanceof FalhaDeDominio) {
        return $e->getMessage();
    }

    error_log(sprintf(
        'bistro: falha inesperada %s em %s:%d — %s',
        $e::class,
        $e->getFile(),
        $e->getLine(),
        $e->getMessage()
    ));

    return 'Algo deu errado ao processar esse pedido. O detalhe foi registrado no log do servidor.';
}
