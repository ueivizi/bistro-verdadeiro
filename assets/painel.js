/*
 * Troca só o conteúdo da página, sem recarregar a moldura.
 *
 * Os formulários e links marcados com data-vivo continuam sendo formulários e
 * links de verdade: se este arquivo não carregar, se o navegador for antigo ou
 * se a rede falhar no meio, o clique volta a ser uma navegação comum e a tela
 * funciona igual. Nada aqui é a única forma de fazer alguma coisa.
 *
 * O servidor decide o que devolver pelo cabeçalho X-Fragmento. As conferências
 * de login e de papel acontecem antes disso, iguais nos dois caminhos — este
 * arquivo muda quanto HTML chega, nunca quem pode pedir o quê.
 */
(function () {
    'use strict';

    var alvo = document.getElementById('conteudo');
    var recado = document.getElementById('recado');

    if (!alvo || !window.fetch || !window.DOMParser || !window.history.pushState) {
        return;
    }

    // "Dados · Bistrô Verdadeiro" -> guarda o "Bistrô Verdadeiro" para recompor
    // o título a cada troca.
    var sufixo = document.title.split(' · ').slice(1).join(' · ');

    var pedidoAtual = null;

    function mesmaOrigem(url) {
        try {
            return new URL(url, window.location.href).origin === window.location.origin;
        } catch (e) {
            return false;
        }
    }

    function ocupado(estaOcupado) {
        alvo.setAttribute('aria-busy', estaOcupado ? 'true' : 'false');
    }

    /*
     * Diz em uma linha o que mudou, para quem não está vendo a tela.
     *
     * Os seletores são tentados em ordem, um de cada vez. Uma lista só
     * ('.contagem, h1') devolveria o primeiro na ordem do documento, que é
     * sempre o h1 — o título da página, que justamente não mudou.
     */
    function anunciar(no) {
        if (!recado) {
            return;
        }

        var ondeOlhar = ['.contagem', '.destaque-linha', '.medida-valor', 'h1'];

        for (var i = 0; i < ondeOlhar.length; i++) {
            var achado = no.querySelector(ondeOlhar[i]);

            if (achado) {
                recado.textContent = achado.textContent.replace(/\s+/g, ' ').trim();
                return;
            }
        }

        recado.textContent = '';
    }

    function trocar(html, url) {
        var novo = new DOMParser()
            .parseFromString(html, 'text/html')
            .getElementById('conteudo');

        // Sem o div esperado, a resposta não é o que este código sabe tratar
        // (tela de login, erro do servidor, proxy no meio). Navega de verdade.
        if (!novo) {
            window.location.href = url;
            return false;
        }

        alvo.replaceWith(novo);
        alvo = novo;

        if (novo.dataset.titulo) {
            document.title = sufixo ? novo.dataset.titulo + ' · ' + sufixo : novo.dataset.titulo;
        }

        anunciar(novo);
        ocupado(false);

        return true;
    }

    /**
     * Busca e troca. `historico` diz o que fazer com a barra de endereço:
     * 'empurrar' guarda um passo novo, 'trocar' reescreve o atual, e null não
     * mexe (é o caso do POST, que não cabe numa URL).
     */
    function carregar(url, opcoes, historico) {
        if (pedidoAtual) {
            pedidoAtual.abort();
        }

        var controle = new AbortController();
        pedidoAtual = controle;

        var pedido = Object.assign({
            credentials: 'same-origin',
            signal: controle.signal
        }, opcoes || {});

        pedido.headers = Object.assign({ 'X-Fragmento': '1' }, pedido.headers || {});

        ocupado(true);

        fetch(url, pedido)
            .then(function (resposta) {
                // A sessão caiu, ou o papel não alcança a página: o servidor
                // respondeu com um 302 e o fetch já o seguiu. Vai de verdade
                // para onde ele mandou, em vez de fingir que deu certo.
                if (resposta.redirected) {
                    window.location.href = resposta.url;
                    return null;
                }

                if (!resposta.ok) {
                    throw new Error('resposta ' + resposta.status);
                }

                return resposta.text();
            })
            .then(function (html) {
                if (html === null) {
                    return;
                }

                if (trocar(html, url) && historico) {
                    window.history[historico === 'empurrar' ? 'pushState' : 'replaceState'](
                        { vivo: true }, '', url
                    );
                }

                pedidoAtual = null;
            })
            .catch(function (erro) {
                if (erro.name === 'AbortError') {
                    return;
                }

                // Qualquer outra falha devolve o controle ao navegador, que
                // sabe mostrar a própria tela de erro.
                ocupado(false);
                pedidoAtual = null;
                window.location.href = url;
            });
    }

    function urlComFormulario(formulario) {
        var campos = new URLSearchParams();

        new FormData(formulario).forEach(function (valor, nome) {
            if (typeof valor === 'string' && valor !== '') {
                campos.append(nome, valor);
            }
        });

        var base = formulario.getAttribute('action') || window.location.pathname;
        var consulta = campos.toString();

        return consulta ? base + '?' + consulta : base;
    }

    document.addEventListener('submit', function (evento) {
        var formulario = evento.target.closest('form[data-vivo]');

        if (!formulario || evento.defaultPrevented) {
            return;
        }

        var metodo = (formulario.getAttribute('method') || 'get').toLowerCase();

        if (metodo === 'post') {
            evento.preventDefault();
            // O corpo de um POST não cabe na URL (a caixa de valores digitados
            // chega a milhares de números), então o endereço fica onde está.
            carregar(formulario.getAttribute('action') || window.location.pathname, {
                method: 'POST',
                body: new FormData(formulario)
            }, null);
            return;
        }

        var url = urlComFormulario(formulario);

        if (!mesmaOrigem(url)) {
            return;
        }

        evento.preventDefault();
        carregar(url, null, 'empurrar');
    });

    document.addEventListener('click', function (evento) {
        // Clique com modificador é intenção de abrir noutro lugar; não roubar.
        if (evento.defaultPrevented || evento.button !== 0
            || evento.metaKey || evento.ctrlKey || evento.shiftKey || evento.altKey) {
            return;
        }

        var link = evento.target.closest('a[data-vivo]');

        if (!link || link.getAttribute('target') || link.hasAttribute('download')) {
            return;
        }

        // Dentro de um SVG, link.href é um SVGAnimatedString, não texto — as
        // barras dos gráficos são links de verdade, e sem isto elas viriam
        // como "[object SVGAnimatedString]". Ler o atributo serve para os dois.
        var destino = link.getAttribute('href');

        if (!destino || destino.charAt(0) === '#') {
            return;
        }

        var url = new URL(destino, window.location.href).href;

        if (!mesmaOrigem(url)) {
            return;
        }

        evento.preventDefault();
        carregar(url, null, 'empurrar');
    });

    window.addEventListener('popstate', function () {
        carregar(window.location.href, null, null);
    });

    // Marca o passo inicial como nosso, para o primeiro "voltar" depois de uma
    // troca não sair da página.
    window.history.replaceState({ vivo: true }, '', window.location.href);
}());
