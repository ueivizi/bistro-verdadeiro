/*
 * Dica de valor nos gráficos.
 *
 * Cada marca traz data-dica com o texto pronto, montado no PHP e já escapado.
 * Este arquivo só lê esse atributo e o mostra numa caixa perto do cursor —
 * nada é remontado aqui, e por isso não há como o texto virar marcação: ele é
 * escrito com textContent, nunca com innerHTML.
 *
 * O mesmo vale para o foco pelo teclado: as barras têm tabindex, e focar numa
 * mostra a dica no mesmo lugar em que o mouse a mostraria. Os pontos da nuvem
 * ficam de fora disso de propósito — são centenas, e cada um virar uma parada
 * de tabulação tornaria a página impossível de atravessar pelo teclado.
 *
 * Sem este arquivo os gráficos continuam legíveis: cada barra traz o valor
 * escrito ao lado, e embaixo de cada figura há a tabela equivalente.
 */
(function () {
    'use strict';

    if (!document.body || !window.requestAnimationFrame) {
        return;
    }

    var dica = null;
    var presa = null;

    function caixa() {
        if (!dica) {
            dica = document.createElement('div');
            dica.className = 'dica-grafico';
            dica.setAttribute('role', 'presentation');
            dica.hidden = true;
            document.body.appendChild(dica);
        }

        return dica;
    }

    function posicionar(x, y) {
        var c = caixa();
        var largura = c.offsetWidth;
        var altura = c.offsetHeight;
        var folga = 14;

        var esquerda = x + folga;
        var topo = y + folga;

        // Perto da borda direita ou de baixo, a caixa passa para o outro lado
        // do cursor em vez de sair da tela.
        if (esquerda + largura > window.innerWidth - 8) {
            esquerda = x - largura - folga;
        }

        if (topo + altura > window.innerHeight - 8) {
            topo = y - altura - folga;
        }

        c.style.left = Math.max(8, esquerda) + 'px';
        c.style.top = Math.max(8, topo) + 'px';
    }

    function mostrar(texto, x, y) {
        var c = caixa();
        c.textContent = texto;
        c.hidden = false;
        posicionar(x, y);
    }

    function esconder() {
        if (dica) {
            dica.hidden = true;
        }
    }

    function marcaDe(alvo) {
        return alvo && alvo.closest ? alvo.closest('.marca[data-dica]') : null;
    }

    document.addEventListener('mousemove', function (evento) {
        var marca = marcaDe(evento.target);

        if (!marca) {
            if (!presa) {
                esconder();
            }
            return;
        }

        mostrar(marca.getAttribute('data-dica'), evento.clientX, evento.clientY);
    });

    document.addEventListener('mouseleave', esconder, true);

    // Teclado: a dica acompanha o foco, ancorada na própria marca.
    document.addEventListener('focusin', function (evento) {
        var marca = marcaDe(evento.target);

        if (!marca) {
            return;
        }

        var area = marca.getBoundingClientRect();
        presa = marca;
        mostrar(marca.getAttribute('data-dica'), area.left + area.width / 2, area.top);
    });

    document.addEventListener('focusout', function () {
        presa = null;
        esconder();
    });

    document.addEventListener('keydown', function (evento) {
        if (evento.key === 'Escape') {
            presa = null;
            esconder();
        }
    });

    // Rolar com a dica presa na tela a deixaria apontando para o lugar errado.
    window.addEventListener('scroll', esconder, { passive: true });
}());
