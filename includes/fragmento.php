<?php

declare(strict_types=1);

/**
 * Renderização parcial.
 *
 * Toda página interna embrulha o seu conteúdo num <div id="conteudo">. Numa
 * visita normal, topo.php desenha a moldura inteira em volta dele. Quando o
 * pedido traz o cabeçalho X-Fragmento, só esse div é devolvido, e o JavaScript
 * troca no lugar — o cabeçalho, o menu e o rodapé não são refeitos, e a rolagem
 * e o foco ficam onde estavam.
 *
 * O cabeçalho é um invento nosso, o que já é uma garantia: um formulário de
 * outro site não consegue mandá-lo sem passar por preflight de CORS, que este
 * servidor não responde. De todo jeito ele não muda nada do que importa — as
 * conferências de login e de papel rodam antes, iguais nos dois caminhos, e a
 * única diferença é quanto HTML sai. Fragmento não é uma porta lateral: é a
 * mesma porta, com menos moldura.
 */
function pedido_de_fragmento(): bool
{
    return ($_SERVER['HTTP_X_FRAGMENTO'] ?? '') === '1';
}
