<?php

declare(strict_types=1);

require_once __DIR__ . '/sessao.php';

/**
 * Gráficos em SVG, montados aqui no PHP.
 *
 * Sem biblioteca e sem JavaScript, por dois motivos. O primeiro é a CSP do
 * sistema: script-src e style-src são 'self', então nem CDN nem <style> solto
 * dentro do SVG passariam, e afrouxar a CSP para caber uma biblioteca de
 * gráfico seria trocar uma proteção real por conveniência. O segundo é que
 * dependência é superfície de ataque permanente, e três formas de gráfico não
 * pagam esse preço.
 *
 * Por isso tudo aqui usa atributo de apresentação do SVG (fill, stroke,
 * font-size), que é atributo XML e não CSS — a CSP não tem o que barrar. O que
 * é texto passa por h(); o que é coordenada é convertido para número antes de
 * entrar na string.
 *
 * Cada marca carrega aria-label, que dá o nome acessível sem disparar a dica
 * nativa do navegador, e data-dica, que assets/graficos.js usa para desenhar a
 * dica própria. A escolha entre <title> e aria-label é uma troca: <title>
 * funciona sem JavaScript, mas aparece com atraso e apareceria junto da dica
 * própria, duas caixas para a mesma informação. Quem não tem JavaScript
 * continua com o rótulo direto em cada barra e a tabela equivalente embaixo de
 * cada figura, que é onde o dado está por extenso de qualquer jeito.
 */

const GRAFICO_LARGURA = 720;

// Uma cor por gráfico, nunca várias lado a lado: assim não existe par de
// séries para alguém com daltonismo confundir. Todas passam de 3:1 contra o
// papel, que é o que se exige de forma cheia.
const COR_BARRA      = '#1c4b3d';
const COR_HISTOGRAMA = '#a97c26';
const COR_PONTO      = '#7a2b34';
const COR_TENDENCIA  = '#12332a';
const COR_GRADE      = '#ddd9cb';
const COR_TEXTO      = '#5c6660';
const COR_SUPERFICIE = '#f5f2e8';

/**
 * Envolve uma forma com o que a torna interativa.
 *
 * Quando vem uma URL, a marca vira um link de verdade — <a> com href, que o
 * SVG entende igual ao HTML. Sem JavaScript ele navega; com JavaScript o
 * data-vivo faz a troca acontecer sem recarregar. É o mesmo clique nos dois
 * casos, não dois caminhos diferentes.
 *
 * Ponto de dispersão passa $focavel = false: são centenas deles, e cada um
 * virar uma parada de tabulação tornaria o teclado inútil na página.
 */
function marca_interativa(string $forma, string $dica, ?string $url = null, bool $focavel = true): string
{
    $rotulo = ' role="img" aria-label="' . h($dica) . '" data-dica="' . h($dica) . '"'
            . ($focavel ? ' tabindex="0"' : ' aria-hidden="true"');

    if ($url === null) {
        return '<g class="marca"' . $rotulo . '>' . $forma . '</g>';
    }

    return '<a class="marca marca-ligada" data-vivo href="' . h($url) . '"' . $rotulo . '>'
         . $forma . '</a>';
}

/** Passo de eixo que cai em número redondo: 1, 2, 2,5, 5 ou 10 vezes a escala. */
function passo_agradavel(float $intervalo, int $alvo = 5): float
{
    if ($intervalo <= 0.0) {
        return 1.0;
    }

    $bruto       = $intervalo / max(1, $alvo);
    $magnitude   = 10 ** floor(log10($bruto));
    $normalizado = $bruto / $magnitude;

    $escolhido = match (true) {
        $normalizado <= 1.0 => 1.0,
        $normalizado <= 2.0 => 2.0,
        $normalizado <= 2.5 => 2.5,
        $normalizado <= 5.0 => 5.0,
        default             => 10.0,
    };

    return $escolhido * $magnitude;
}

/** Barra horizontal com a ponta de dados arredondada e a base reta. */
function caminho_barra_horizontal(float $x, float $y, float $largura, float $altura, float $raio = 4.0): string
{
    if ($largura <= 0.0 || $altura <= 0.0) {
        return '';
    }

    $raio = min($raio, $largura, $altura / 2);
    $fim  = $x + $largura;

    return sprintf(
        'M %.2f %.2f H %.2f A %.2f %.2f 0 0 1 %.2f %.2f V %.2f A %.2f %.2f 0 0 1 %.2f %.2f H %.2f Z',
        $x, $y,
        $fim - $raio,
        $raio, $raio, $fim, $y + $raio,
        $y + $altura - $raio,
        $raio, $raio, $fim - $raio, $y + $altura,
        $x
    );
}

/** Barra vertical com o topo arredondado e o pé assentado na linha de base. */
function caminho_barra_vertical(float $x, float $base, float $largura, float $altura, float $raio = 4.0): string
{
    if ($largura <= 0.0 || $altura <= 0.0) {
        return '';
    }

    $raio = min($raio, $largura / 2, $altura);
    $topo = $base - $altura;

    return sprintf(
        'M %.2f %.2f V %.2f A %.2f %.2f 0 0 1 %.2f %.2f H %.2f A %.2f %.2f 0 0 1 %.2f %.2f V %.2f Z',
        $x, $base,
        $topo + $raio,
        $raio, $raio, $x + $raio, $topo,
        $x + $largura - $raio,
        $raio, $raio, $x + $largura, $topo + $raio,
        $base
    );
}

function abrir_svg(int $altura, string $titulo, string $descricao): string
{
    $idTitulo = 'g' . substr(hash('sha256', $titulo . $descricao), 0, 8);

    return sprintf(
        '<svg class="grafico" viewBox="0 0 %d %d" role="img" aria-labelledby="%s-t %s-d"'
        . ' xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMidYMid meet">'
        . '<title id="%s-t">%s</title><desc id="%s-d">%s</desc>',
        GRAFICO_LARGURA, $altura,
        $idTitulo, $idTitulo, $idTitulo, h($titulo), $idTitulo, h($descricao)
    );
}

function texto_svg(float $x, float $y, string $conteudo, string $ancora = 'start',
                   float $tamanho = 13.0, string $cor = COR_TEXTO, string $peso = 'normal'): string
{
    return sprintf(
        '<text x="%.2f" y="%.2f" text-anchor="%s" font-size="%.1f" fill="%s" font-weight="%s">%s</text>',
        $x, $y, $ancora, $tamanho, $cor, $peso, h($conteudo)
    );
}

/**
 * Barras horizontais, uma cor só.
 *
 * $itens: [['rotulo' => 'Sábado', 'valor' => 2.99, 'nota' => '87 atendimentos'], ...]
 *
 * Todas as barras saem da mesma cor de propósito. Pintar a maior de outra cor
 * seria deixar a posição no ranking mandar na cor, e aí o gráfico muda de cara
 * quando o filtro muda, sem que os dados daquele item tenham mudado.
 */
function grafico_barras(array $itens, string $titulo, callable $formatar,
                        ?callable $ligacao = null): string
{
    if ($itens === []) {
        return '';
    }

    $esquerda = 132.0;
    $direita  = 104.0;
    $linha    = 38.0;
    $topo     = 14.0;
    $altura   = (int) ($topo * 2 + count($itens) * $linha);
    $util     = GRAFICO_LARGURA - $esquerda - $direita;

    $maior = 0.0;
    foreach ($itens as $item) {
        $maior = max($maior, (float) $item['valor']);
    }

    $svg = abrir_svg($altura, $titulo, count($itens) . ' categorias comparadas por valor.');

    // Grade atrás das barras, discreta: serve de régua, não de desenho.
    $passo = passo_agradavel($maior, 4);

    for ($marca = 0.0; $marca <= $maior + $passo / 2; $marca += $passo) {
        $x = $esquerda + ($maior > 0 ? $marca / $maior * $util : 0);
        $svg .= sprintf(
            '<line x1="%.2f" y1="%.2f" x2="%.2f" y2="%.2f" stroke="%s" stroke-width="1"/>',
            $x, $topo - 4, $x, $altura - $topo, COR_GRADE
        );
    }

    foreach (array_values($itens) as $i => $item) {
        $valor = (float) $item['valor'];
        // 2px de folga entre barras vizinhas, para as formas não se colarem.
        $y     = $topo + $i * $linha + 5;
        $corpo = $linha - 12;
        $comp  = $maior > 0 ? $valor / $maior * $util : 0.0;

        $svg .= texto_svg($esquerda - 12, $y + $corpo / 2 + 4, (string) $item['rotulo'], 'end', 13.5, '#1b2420');

        if ($comp > 0) {
            $dica = $item['rotulo'] . ': ' . $formatar($valor)
                  . (($item['nota'] ?? '') !== '' ? ' · ' . $item['nota'] : '');

            $svg .= marca_interativa(
                '<path class="marca-forma" d="'
                    . caminho_barra_horizontal($esquerda, $y, $comp, $corpo)
                    . '" fill="' . COR_BARRA . '"/>',
                $dica,
                $ligacao === null ? null : $ligacao($item)
            );
        }

        $svg .= texto_svg($esquerda + $comp + 10, $y + $corpo / 2 + 4, $formatar($valor),
                          'start', 13.5, '#1b2420', '600');

        if (($item['nota'] ?? '') !== '') {
            $svg .= texto_svg($esquerda - 12, $y + $corpo / 2 + 18, (string) $item['nota'], 'end', 11.0);
        }
    }

    return $svg . '</svg>';
}

/**
 * Histograma: quantos valores caem em cada faixa.
 *
 * Devolve ['svg' => string, 'faixas' => [['de'=>, 'ate'=>, 'quantos'=>], ...]]
 * porque a tabela equivalente da tela precisa exatamente das mesmas faixas —
 * duas contas separadas acabariam divergindo.
 */
function grafico_histograma(array $valores, int $quantasFaixas, string $titulo,
                            callable $formatar, ?callable $ligacao = null): array
{
    $valores = array_values(array_filter($valores, 'is_finite'));

    if ($valores === []) {
        return ['svg' => '', 'faixas' => []];
    }

    $quantasFaixas = max(3, min(30, $quantasFaixas));
    $menor         = min($valores);
    $maior         = max($valores);

    // Série de valor único não tem largura de faixa: abre uma janela em volta
    // para a barra ter onde aparecer.
    if ($maior - $menor <= 0.0) {
        $menor -= 0.5;
        $maior += 0.5;
    }

    $largura = ($maior - $menor) / $quantasFaixas;
    $faixas  = [];

    for ($i = 0; $i < $quantasFaixas; $i++) {
        $faixas[] = [
            'de'      => $menor + $i * $largura,
            'ate'     => $menor + ($i + 1) * $largura,
            'quantos' => 0,
        ];
    }

    foreach ($valores as $valor) {
        $indice = (int) floor(($valor - $menor) / $largura);
        $indice = max(0, min($quantasFaixas - 1, $indice));
        $faixas[$indice]['quantos']++;
    }

    $esquerda = 52.0;
    $direita  = 16.0;
    $topo     = 34.0;
    $base     = 306.0;
    $alturaG  = 362;
    $util     = GRAFICO_LARGURA - $esquerda - $direita;

    $maiorCont = 0;
    foreach ($faixas as $faixa) {
        $maiorCont = max($maiorCont, $faixa['quantos']);
    }

    $passo   = passo_agradavel((float) $maiorCont, 4);
    $tetoEixo = max($passo, ceil($maiorCont / $passo) * $passo);

    $svg = abrir_svg($alturaG, $titulo, 'Distribuição de ' . count($valores)
        . ' valores em ' . $quantasFaixas . ' faixas.');

    for ($marca = 0.0; $marca <= $tetoEixo + $passo / 2; $marca += $passo) {
        $y = $base - ($tetoEixo > 0 ? $marca / $tetoEixo * ($base - $topo) : 0);
        $svg .= sprintf(
            '<line x1="%.2f" y1="%.2f" x2="%.2f" y2="%.2f" stroke="%s" stroke-width="1"/>',
            $esquerda, $y, GRAFICO_LARGURA - $direita, $y, COR_GRADE
        );
        $svg .= texto_svg($esquerda - 10, $y + 4, (string) (int) round($marca), 'end', 11.5);
    }

    $larguraFaixa = $util / $quantasFaixas;

    foreach ($faixas as $i => $faixa) {
        $alturaBarra = $tetoEixo > 0 ? $faixa['quantos'] / $tetoEixo * ($base - $topo) : 0.0;
        $x           = $esquerda + $i * $larguraFaixa + 1;
        $corpo       = max(1.0, $larguraFaixa - 2);

        if ($alturaBarra > 0) {
            $dica = $formatar($faixa['de']) . ' a ' . $formatar($faixa['ate']) . ': '
                  . $faixa['quantos'] . ($faixa['quantos'] === 1 ? ' atendimento' : ' atendimentos');

            $svg .= marca_interativa(
                '<path class="marca-forma" d="'
                    . caminho_barra_vertical($x, $base, $corpo, $alturaBarra)
                    . '" fill="' . COR_HISTOGRAMA . '"/>',
                $dica,
                $ligacao === null ? null : $ligacao($faixa)
            );
        }

        // Rótulo em toda faixa vira borrão; um a cada dois se lê.
        if ($i % 2 === 0 || $quantasFaixas <= 8) {
            $svg .= texto_svg($x + $corpo / 2, $base + 20, $formatar($faixa['de']), 'middle', 11.0);
        }
    }

    $svg .= sprintf(
        '<line x1="%.2f" y1="%.2f" x2="%.2f" y2="%.2f" stroke="%s" stroke-width="1.5"/>',
        $esquerda, $base, GRAFICO_LARGURA - $direita, $base, COR_TEXTO
    );

    $svg .= texto_svg(GRAFICO_LARGURA / 2, $alturaG - 8, 'faixas de valor', 'middle', 11.5);
    $svg .= texto_svg($esquerda - 10, $topo - 14, 'atendimentos', 'end', 11.5);

    return ['svg' => $svg . '</svg>', 'faixas' => $faixas];
}

/**
 * Dispersão com reta de tendência por mínimos quadrados.
 *
 * Devolve ['svg' => string, 'inclinacao' => ?float, 'intercepto' => ?float,
 * 'correlacao' => ?float] — a reta e o r de Pearson saem do mesmo cálculo que
 * desenhou a linha, então o número embaixo do gráfico é o da linha de cima.
 */
function grafico_dispersao(array $pontos, string $rotuloX, string $rotuloY,
                           callable $formatarX, callable $formatarY): array
{
    $vazio = ['svg' => '', 'inclinacao' => null, 'intercepto' => null, 'correlacao' => null];

    if (count($pontos) < 2) {
        return $vazio;
    }

    $xs = array_map(static fn(array $p): float => (float) $p['x'], $pontos);
    $ys = array_map(static fn(array $p): float => (float) $p['y'], $pontos);

    $menorX = min($xs);
    $maiorX = max($xs);
    $menorY = min($ys);
    $maiorY = max($ys);

    if ($maiorX - $menorX <= 0.0 || $maiorY - $menorY <= 0.0) {
        return $vazio;
    }

    $esquerda = 62.0;
    $direita  = 20.0;
    $topo     = 34.0;
    $base     = 362.0;
    $alturaG  = 418;
    $utilX    = GRAFICO_LARGURA - $esquerda - $direita;
    $utilY    = $base - $topo;

    $paraX = static fn(float $v): float => $esquerda + ($v - $menorX) / ($maiorX - $menorX) * $utilX;
    $paraY = static fn(float $v): float => $base - ($v - $menorY) / ($maiorY - $menorY) * $utilY;

    $svg = abrir_svg($alturaG, $rotuloY . ' contra ' . $rotuloX,
        count($pontos) . ' atendimentos, um ponto cada, com a reta de tendência.');

    foreach ([['x', $menorX, $maiorX], ['y', $menorY, $maiorY]] as [$eixo, $min, $max]) {
        $passo = passo_agradavel($max - $min, 5);
        $marca = ceil($min / $passo) * $passo;

        while ($marca <= $max + $passo / 100) {
            if ($eixo === 'x') {
                $x = $paraX($marca);
                $svg .= sprintf('<line x1="%.2f" y1="%.2f" x2="%.2f" y2="%.2f" stroke="%s" stroke-width="1"/>',
                    $x, $topo, $x, $base, COR_GRADE);
                $svg .= texto_svg($x, $base + 20, $formatarX($marca), 'middle', 11.0);
            } else {
                $y = $paraY($marca);
                $svg .= sprintf('<line x1="%.2f" y1="%.2f" x2="%.2f" y2="%.2f" stroke="%s" stroke-width="1"/>',
                    $esquerda, $y, GRAFICO_LARGURA - $direita, $y, COR_GRADE);
                $svg .= texto_svg($esquerda - 10, $y + 4, $formatarY($marca), 'end', 11.0);
            }

            $marca += $passo;
        }
    }

    // Mínimos quadrados sobre os mesmos pontos que estão desenhados.
    $n      = count($pontos);
    $mediaX = array_sum($xs) / $n;
    $mediaY = array_sum($ys) / $n;

    $covariancia = 0.0;
    $varX        = 0.0;
    $varY        = 0.0;

    for ($i = 0; $i < $n; $i++) {
        $dx = $xs[$i] - $mediaX;
        $dy = $ys[$i] - $mediaY;
        $covariancia += $dx * $dy;
        $varX        += $dx * $dx;
        $varY        += $dy * $dy;
    }

    $inclinacao = $varX > 0.0 ? $covariancia / $varX : null;
    $intercepto = $inclinacao === null ? null : $mediaY - $inclinacao * $mediaX;
    $correlacao = ($varX > 0.0 && $varY > 0.0) ? $covariancia / sqrt($varX * $varY) : null;

    // Pontos antes da reta, para a reta ficar legível por cima da nuvem.
    foreach ($pontos as $ponto) {
        $forma = sprintf(
            '<circle class="marca-forma" cx="%.2f" cy="%.2f" r="4.5" fill="%s"'
            . ' fill-opacity="0.42" stroke="%s" stroke-width="1"/>',
            $paraX((float) $ponto['x']), $paraY((float) $ponto['y']),
            COR_PONTO, COR_SUPERFICIE
        );

        $svg .= marca_interativa(
            $forma,
            $rotuloX . ': ' . $formatarX((float) $ponto['x']) . ' · '
                . $rotuloY . ': ' . $formatarY((float) $ponto['y'])
                . (($ponto['extra'] ?? '') !== '' ? ' · ' . $ponto['extra'] : ''),
            null,
            false
        );
    }

    if ($inclinacao !== null && $intercepto !== null) {
        $svg .= sprintf(
            '<line x1="%.2f" y1="%.2f" x2="%.2f" y2="%.2f" stroke="%s" stroke-width="2"'
            . ' stroke-linecap="round"><title>%s</title></line>',
            $paraX($menorX), $paraY($inclinacao * $menorX + $intercepto),
            $paraX($maiorX), $paraY($inclinacao * $maiorX + $intercepto),
            COR_TENDENCIA,
            h('Reta de tendência por mínimos quadrados')
        );
    }

    $svg .= sprintf('<line x1="%.2f" y1="%.2f" x2="%.2f" y2="%.2f" stroke="%s" stroke-width="1.5"/>',
        $esquerda, $base, GRAFICO_LARGURA - $direita, $base, COR_TEXTO);
    $svg .= sprintf('<line x1="%.2f" y1="%.2f" x2="%.2f" y2="%.2f" stroke="%s" stroke-width="1.5"/>',
        $esquerda, $topo, $esquerda, $base, COR_TEXTO);

    $svg .= texto_svg(GRAFICO_LARGURA / 2, $alturaG - 8, $rotuloX, 'middle', 11.5);
    $svg .= texto_svg($esquerda - 10, $topo - 14, $rotuloY, 'end', 11.5);

    return [
        'svg'        => $svg . '</svg>',
        'inclinacao' => $inclinacao,
        'intercepto' => $intercepto,
        'correlacao' => $correlacao,
    ];
}
