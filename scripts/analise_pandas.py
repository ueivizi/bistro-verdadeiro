#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
analise_pandas.py - analise da base de gorjetas com pandas.

E o par em Python do scripts/mineracao.sh: roda sozinho no terminal e tambem
e chamado pelo PHP, que so formata o que volta daqui. Quem le o CSV, agrupa e
calcula e o pandas.

    python3 scripts/analise_pandas.py -a dados/gorjetas.csv -o periodo
    python3 scripts/analise_pandas.py -a dados/gorjetas.csv -o grupo -g dia_da_semana -m gorjeta
    python3 scripts/analise_pandas.py -a dados/gorjetas.csv -o correlacao -j
    python3 scripts/analise_pandas.py -a dados/gorjetas.csv -o tudo
    python3 scripts/analise_pandas.py -a dados/gorjetas.csv -f dispersao -s fig.png

Operacoes (-o): periodo, grupo, visao, correlacao, tudo
Agrupamentos (-g): periodo, dia_da_semana, genero, fumante, quantos_na_mesa
Medidas (-m): conta, gorjeta, percentual, por_pessoa, quantos_na_mesa
Figuras (-f): dispersao, pessoas, grupo, correlacao   (exigem -s arquivo.png)

Sem -j a saida e texto, do jeito que sai no terminal. Com -j e JSON, que e o
formato que o PHP consome.

Nada aqui monta comando de shell nem abre rede: o programa recebe caminhos
como argumento, le o CSV e escreve na saida padrao (ou grava um PNG no caminho
que lhe foi passado). Os nomes de coluna que podem ser pedidos de fora sao
conferidos contra as listas fixas abaixo, pelo proprio argparse - nome fora
delas nem chega a virar acesso a dado nenhum.
"""

from __future__ import annotations

import argparse
import json
import os
import sys

# A saida sai sempre em UTF-8, qualquer que seja o console.
#
# No Windows o Python escreve no code page do console - cp1252 por padrao - e
# "Nao" sai como o byte 0xE3 solto. Quem le isso pelo terminal nem percebe,
# mas o json_decode() do PHP recusa a resposta inteira, porque JSON invalido
# em UTF-8 nao e JSON. Fixar a codificacao aqui resolve dos dois lados: o
# terminal continua legivel e o PHP recebe algo que ele consegue decodificar.
for _fluxo in (sys.stdout, sys.stderr):
    if hasattr(_fluxo, "reconfigure"):
        _fluxo.reconfigure(encoding="utf-8")

try:
    import pandas as pd
except ImportError:
    sys.stderr.write(
        "analise_pandas.py: o pandas nao esta instalado para este Python.\n"
        "Instale com:  python3 -m pip install pandas matplotlib\n"
        "No Debian/Ubuntu:  sudo apt install python3-pandas python3-matplotlib\n"
    )
    sys.exit(3)


# ---------------------------------------------------------------------------
# Forma da base
# ---------------------------------------------------------------------------
# O arquivo vem com os nomes originais em ingles. Padronizar os nomes das
# colunas e o passo 2 da atividade - e o que esta tabela faz, e e por estes
# nomes que o resto do programa chama os dados.

NOMES_PADRONIZADOS = {
    "total_bill": "conta",
    "tip": "gorjeta",
    "sex": "genero",
    "smoker": "fumante",
    "day": "dia_da_semana",
    "time": "periodo",
    "size": "quantos_na_mesa",
}

# Mesma traducao que o PHP usa na tela, para os dois lados falarem igual.
TRADUCAO = {
    "Mon": "Segunda", "Tue": "Terça", "Wed": "Quarta",
    "Thur": "Quinta", "Fri": "Sexta", "Sat": "Sábado", "Sun": "Domingo",
    "Lunch": "Almoço", "Dinner": "Jantar",
    "Male": "Homem", "Female": "Mulher",
    "Yes": "Sim", "No": "Não",
}

# Allowlist: so estes nomes podem ser pedidos de fora.
AGRUPAMENTOS = {
    "periodo":         "Período (almoço/jantar)",
    "dia_da_semana":   "Dia da semana",
    "genero":          "Gênero de quem pagou",
    "fumante":         "Fumante à mesa",
    "quantos_na_mesa": "Quantas pessoas à mesa",
}

MEDIDAS = {
    "conta":           ("Valor da conta",        "moeda"),
    "gorjeta":         ("Valor da gorjeta",      "moeda"),
    "percentual":      ("Gorjeta sobre a conta", "porcento"),
    "por_pessoa":      ("Gorjeta por pessoa",    "moeda"),
    "quantos_na_mesa": ("Pessoas à mesa",   "numero"),
}

COLUNAS_NUMERICAS = ["conta", "gorjeta", "percentual", "por_pessoa", "quantos_na_mesa"]

FIGURAS = {
    "dispersao":  "Valor da conta x Valor da gorjeta",
    "pessoas":    "Pessoas à mesa x Valor da gorjeta",
    "grupo":      "Média por grupo",
    "correlacao": "Mapa de correlação",
}

# As mesmas cores dos graficos em SVG do painel, para as figuras do Python nao
# destoarem do resto da tela.
COR_BARRA      = "#1c4b3d"
COR_PONTO      = "#7a2b34"
COR_TENDENCIA  = "#12332a"
COR_GRADE      = "#ddd9cb"
COR_TEXTO      = "#5c6660"
COR_SUPERFICIE = "#f5f2e8"


def erro(mensagem, codigo=2):
    sys.stderr.write("analise_pandas.py: " + mensagem + "\n")
    sys.exit(codigo)


# ---------------------------------------------------------------------------
# 1. Carregar o arquivo CSV   2. Padronizar os nomes das colunas
# ---------------------------------------------------------------------------

def carregar(caminho):
    if not os.path.isfile(caminho):
        erro("base nao encontrada: " + caminho)

    try:
        df = pd.read_csv(caminho)
    except Exception as falha:              # arquivo truncado, vazio, binario
        erro("nao deu para ler o CSV (" + type(falha).__name__ + ").")

    # Remove espaco dos nomes das colunas: "conta " e "conta" tem de ser a
    # mesma coluna, senao a chamada pelo nome nao acha nada.
    df.columns = df.columns.str.strip().str.strip('"')
    df = df.rename(columns=NOMES_PADRONIZADOS)

    faltando = [c for c in NOMES_PADRONIZADOS.values() if c not in df.columns]
    if faltando:
        erro("a base nao tem as colunas: " + ", ".join(faltando))

    for coluna in ("conta", "gorjeta", "quantos_na_mesa"):
        df[coluna] = pd.to_numeric(df[coluna], errors="coerce")

    df = df.dropna(subset=["conta", "gorjeta", "quantos_na_mesa"])
    df = df[(df["conta"] > 0) & (df["quantos_na_mesa"] > 0)]

    if df.empty:
        erro("a base nao tem nenhum atendimento valido.")

    # Colunas derivadas: nao estao no arquivo, saem de conta e gorjeta.
    df["percentual"] = df["gorjeta"] / df["conta"] * 100
    df["por_pessoa"] = df["gorjeta"] / df["quantos_na_mesa"]

    for coluna in ("genero", "fumante", "dia_da_semana", "periodo"):
        df[coluna] = df[coluna].astype(str).str.strip().str.strip('"')

    return df


def rotular(valor):
    """Nome do grupo em portugues, quando houver traducao."""
    texto = str(valor)
    return TRADUCAO.get(texto, texto)


def formatar(valor, formato):
    if valor is None or pd.isna(valor):
        return "—"
    if formato == "moeda":
        return "R$ " + ("%.2f" % valor).replace(".", ",")
    if formato == "porcento":
        return ("%.2f" % valor).replace(".", ",") + "%"
    return ("%.2f" % valor).replace(".", ",")


# ---------------------------------------------------------------------------
# Agrupamento - o coracao da atividade
# ---------------------------------------------------------------------------

def agrupar(df, por, medida):
    """
    Agrupa os registros por uma coluna e resume a medida escolhida em cada
    grupo. E o df.groupby(por)[medida].mean() da aula, com as outras medidas
    do grupo junto para a leitura nao depender so da media.
    """
    resumo = df.groupby(por)[medida].agg(
        ["count", "mean", "sum", "median", "std", "min", "max"]
    )
    resumo = resumo.sort_values("mean", ascending=False)

    media_geral = float(df[medida].mean())
    rotulo, formato = MEDIDAS[medida]

    linhas = []
    for chave, linha in resumo.iterrows():
        media = float(linha["mean"])
        linhas.append({
            "grupo":         str(chave),
            "rotulo":        rotular(chave),
            "atendimentos":  int(linha["count"]),
            "media":         round(media, 2),
            "soma":          round(float(linha["sum"]), 2),
            "mediana":       round(float(linha["median"]), 2),
            "desvio":        None if pd.isna(linha["std"]) else round(float(linha["std"]), 2),
            "minimo":        round(float(linha["min"]), 2),
            "maximo":        round(float(linha["max"]), 2),
            "contra_geral":  round(media - media_geral, 2),
        })

    return {
        "por":           por,
        "por_rotulo":    AGRUPAMENTOS[por],
        "medida":        medida,
        "medida_rotulo": rotulo,
        "formato":       formato,
        "media_geral":   round(media_geral, 2),
        "atendimentos":  int(len(df)),
        "grupos":        linhas,
    }


def quinta_funcionalidade(df):
    """
    A relacao entre o periodo (almoco/jantar) e o valor medio da conta.

    E um agrupamento como os outros - o que ele tem de especial e a leitura:
    os dois periodos sao comparados entre si, com a diferenca em reais e em
    porcento, que e o que responde "qual turno fatura mais por mesa".
    """
    resultado = agrupar(df, "periodo", "conta")
    grupos = resultado["grupos"]

    comparacao = None
    if len(grupos) >= 2:
        maior, menor = grupos[0], grupos[-1]
        diferenca = maior["media"] - menor["media"]
        comparacao = {
            "maior":         maior["rotulo"],
            "menor":         menor["rotulo"],
            "media_maior":   maior["media"],
            "media_menor":   menor["media"],
            "diferenca":     round(diferenca, 2),
            "diferenca_pct": round(diferenca / menor["media"] * 100, 2) if menor["media"] else 0.0,
        }

    resultado["comparacao"] = comparacao
    return resultado


# ---------------------------------------------------------------------------
# Visao geral e correlacao
# ---------------------------------------------------------------------------

def visao(df, quantas=5):
    """As primeiras linhas (head) e as estatisticas descritivas (describe)."""
    cabeca = df[list(NOMES_PADRONIZADOS.values())].head(quantas)
    descricao = df[COLUNAS_NUMERICAS].describe()

    return {
        "colunas": list(cabeca.columns),
        "head": [
            [rotular(v) if isinstance(v, str) else v for v in linha]
            for linha in cabeca.itertuples(index=False, name=None)
        ],
        "describe": {
            "medidas": [str(i) for i in descricao.index],
            "colunas": [str(c) for c in descricao.columns],
            "valores": [[round(float(v), 4) for v in linha] for linha in descricao.values],
        },
        "atendimentos": int(len(df)),
    }


def correlacao(df, quantas=5):
    """
    A matriz de correlacao entre as grandezas numericas e as N maiores
    gorjetas da base.
    """
    matriz = df[COLUNAS_NUMERICAS].corr()

    maiores = df.nlargest(quantas, "gorjeta")
    topo = [
        {
            "conta":      round(float(r.conta), 2),
            "gorjeta":    round(float(r.gorjeta), 2),
            "percentual": round(float(r.percentual), 2),
            "dia":        rotular(r.dia_da_semana),
            "periodo":    rotular(r.periodo),
            "pessoas":    int(r.quantos_na_mesa),
        }
        for r in maiores.itertuples(index=False)
    ]

    # Os pares, sem a diagonal e sem repetir o espelho, do mais forte ao mais
    # fraco: e esta lista que responde "o que anda junto com o que".
    pares = []
    for i, a in enumerate(COLUNAS_NUMERICAS):
        for b in COLUNAS_NUMERICAS[i + 1:]:
            pares.append({"a": a, "b": b, "r": round(float(matriz.loc[a, b]), 4)})
    pares.sort(key=lambda p: abs(p["r"]), reverse=True)

    return {
        "colunas": COLUNAS_NUMERICAS,
        "matriz":  [[round(float(v), 4) for v in linha] for linha in matriz.values],
        "pares":   pares,
        "maiores": topo,
    }


# ---------------------------------------------------------------------------
# Figuras - matplotlib sem interface grafica
# ---------------------------------------------------------------------------

def desenhar(df, tipo, saida, por, medida):
    """
    Gera a figura e grava num PNG.

    O backend 'Agg' e o que torna isto possivel do lado do servidor: ele
    desenha direto no arquivo, sem abrir janela. Na aula o grafico exigia uma
    maquina com interface grafica; aqui o Apache roda sem tela nenhuma, e e o
    navegador que mostra a imagem pronta.
    """
    import matplotlib
    matplotlib.use("Agg")
    import matplotlib.pyplot as plt

    pasta = os.path.dirname(os.path.abspath(saida)) or "."
    if not os.path.isdir(pasta):
        erro("a pasta da figura nao existe: " + pasta)
    if not saida.lower().endswith(".png"):
        erro("a figura precisa ser gravada num arquivo .png")

    figura, eixo = plt.subplots(figsize=(8, 5))
    figura.patch.set_facecolor(COR_SUPERFICIE)
    eixo.set_facecolor(COR_SUPERFICIE)

    if tipo == "dispersao":
        eixo.scatter(df["conta"], df["gorjeta"], color=COR_PONTO, alpha=0.65, s=28)
        if len(df) >= 2 and df["conta"].nunique() > 1:
            inclinacao, intercepto = _reta(df["conta"], df["gorjeta"])
            limites = [float(df["conta"].min()), float(df["conta"].max())]
            eixo.plot(limites, [inclinacao * x + intercepto for x in limites],
                      color=COR_TENDENCIA, linewidth=1.8)
        eixo.set_title("Valor da Conta x Gorjeta")
        eixo.set_xlabel("Valor da Conta (R$)")
        eixo.set_ylabel("Valor da Gorjeta (R$)")

    elif tipo == "pessoas":
        eixo.scatter(df["quantos_na_mesa"], df["gorjeta"], color=COR_BARRA, alpha=0.6, s=34)
        eixo.set_title("Pessoas à Mesa x Gorjeta")
        eixo.set_xlabel("Quantas pessoas estavam à mesa")
        eixo.set_ylabel("Valor da Gorjeta (R$)")

    elif tipo == "grupo":
        resumo = df.groupby(por)[medida].mean().sort_values(ascending=False)
        nomes = [rotular(i) for i in resumo.index]
        eixo.bar(nomes, resumo.values, color=COR_BARRA, width=0.55)
        for posicao, valor in enumerate(resumo.values):
            eixo.text(posicao, valor, formatar(float(valor), MEDIDAS[medida][1]),
                      ha="center", va="bottom", fontsize=9, color=COR_TEXTO)
        eixo.set_title(MEDIDAS[medida][0] + " médio por " + AGRUPAMENTOS[por].lower())
        eixo.set_xlabel(AGRUPAMENTOS[por])
        eixo.set_ylabel(MEDIDAS[medida][0])
        eixo.margins(y=0.15)

    else:  # correlacao
        matriz = df[COLUNAS_NUMERICAS].corr()
        imagem = eixo.imshow(matriz.values, cmap="RdYlGn", vmin=-1, vmax=1)
        eixo.set_xticks(range(len(COLUNAS_NUMERICAS)))
        eixo.set_xticklabels(COLUNAS_NUMERICAS, rotation=30, ha="right", fontsize=8)
        eixo.set_yticks(range(len(COLUNAS_NUMERICAS)))
        eixo.set_yticklabels(COLUNAS_NUMERICAS, fontsize=8)
        for i in range(len(COLUNAS_NUMERICAS)):
            for j in range(len(COLUNAS_NUMERICAS)):
                eixo.text(j, i, "%.2f" % matriz.values[i][j],
                          ha="center", va="center", fontsize=8, color="#20231f")
        figura.colorbar(imagem, ax=eixo, shrink=0.8)
        eixo.set_title("Correlação entre as grandezas numéricas")

    if tipo in ("dispersao", "pessoas", "grupo"):
        eixo.grid(True, color=COR_GRADE, linewidth=0.8)
        eixo.set_axisbelow(True)

    for lado in eixo.spines.values():
        lado.set_color(COR_GRADE)
    eixo.tick_params(colors=COR_TEXTO)

    figura.tight_layout()
    figura.savefig(saida, dpi=110, facecolor=figura.get_facecolor())
    plt.close(figura)

    return {"figura": tipo, "titulo": FIGURAS[tipo], "arquivo": saida,
            "pontos": int(len(df))}


def _reta(x, y):
    """Minimos quadrados, so com pandas - a mesma reta que o SVG do PHP traca."""
    media_x, media_y = float(x.mean()), float(y.mean())
    variacao = float(((x - media_x) ** 2).sum())
    if variacao == 0:
        return 0.0, media_y
    inclinacao = float(((x - media_x) * (y - media_y)).sum()) / variacao
    return inclinacao, media_y - inclinacao * media_x


# ---------------------------------------------------------------------------
# Saida em texto - e o que aparece rodando direto no terminal
# ---------------------------------------------------------------------------

def texto_grupo(resultado):
    formato = resultado["formato"]
    linhas = [resultado["medida_rotulo"].upper() + " POR " + resultado["por_rotulo"].upper(), ""]
    linhas.append("%-22s %13s %13s %13s %13s" %
                  ("GRUPO", "ATENDIMENTOS", "MEDIA", "MEDIANA", "SOMA"))
    linhas.append("-" * 79)
    for g in resultado["grupos"]:
        linhas.append("%-22s %13d %13s %13s %13s" % (
            g["rotulo"], g["atendimentos"],
            formatar(g["media"], formato),
            formatar(g["mediana"], formato),
            formatar(g["soma"], formato),
        ))
    linhas.append("-" * 79)
    linhas.append("%-22s %13d %13s" % (
        "TODOS", resultado["atendimentos"], formatar(resultado["media_geral"], formato)))
    return "\n".join(linhas)


def texto_periodo(resultado):
    partes = [texto_grupo(resultado)]
    c = resultado.get("comparacao")
    if c:
        partes.append("")
        partes.append("%s rende %s a mais por mesa que %s (%s%% de diferenca)." % (
            c["maior"],
            formatar(c["diferenca"], "moeda"),
            c["menor"].lower(),
            ("%.2f" % c["diferenca_pct"]).replace(".", ","),
        ))
    return "\n".join(partes)


def texto_visao(resultado):
    linhas = ["DADOS DO BISTRO", ""]
    linhas.append(" | ".join("%-15s" % c for c in resultado["colunas"]))
    for linha in resultado["head"]:
        linhas.append(" | ".join("%-15s" % v for v in linha))
    linhas.append("")
    linhas.append("ESTATISTICAS DESCRITIVAS:")
    linhas.append("")
    d = resultado["describe"]
    linhas.append("%-8s" % "" + "".join("%16s" % c for c in d["colunas"]))
    for nome, valores in zip(d["medidas"], d["valores"]):
        linhas.append("%-8s" % nome + "".join("%16.4f" % v for v in valores))
    return "\n".join(linhas)


def texto_correlacao(resultado):
    colunas = resultado["colunas"]
    linhas = ["CORRELACAO ENTRE AS GRANDEZAS NUMERICAS", ""]
    linhas.append("%-18s" % "" + "".join("%17s" % c for c in colunas))
    for nome, valores in zip(colunas, resultado["matriz"]):
        linhas.append("%-18s" % nome + "".join("%17.4f" % v for v in valores))
    linhas.append("")
    linhas.append("PARES MAIS FORTES:")
    for p in resultado["pares"][:5]:
        linhas.append("  %-18s x %-18s r = %7.4f" % (p["a"], p["b"], p["r"]))
    linhas.append("")
    linhas.append("TOP %d MAIORES GORJETAS:" % len(resultado["maiores"]))
    linhas.append("  %-12s %-12s %-10s %-10s %-10s %s" %
                  ("CONTA", "GORJETA", "%CONTA", "DIA", "PERIODO", "PESSOAS"))
    for m in resultado["maiores"]:
        linhas.append("  %-12.2f %-12.2f %-10.2f %-10s %-10s %d" % (
            m["conta"], m["gorjeta"], m["percentual"], m["dia"], m["periodo"], m["pessoas"]))
    return "\n".join(linhas)


# ---------------------------------------------------------------------------

def construir_argumentos():
    p = argparse.ArgumentParser(
        prog="analise_pandas.py",
        description="Analise da base de gorjetas com pandas.",
    )
    p.add_argument("-a", "--arquivo", required=True, help="o CSV da base")
    p.add_argument("-o", "--operacao", default="tudo",
                   choices=["periodo", "grupo", "visao", "correlacao", "tudo"])
    p.add_argument("-g", "--grupo", default="periodo", choices=list(AGRUPAMENTOS))
    p.add_argument("-m", "--medida", default="conta", choices=list(MEDIDAS))
    p.add_argument("-n", "--linhas", type=int, default=5,
                   help="linhas do head e do top de gorjetas (1 a 50)")
    p.add_argument("-f", "--figura", choices=list(FIGURAS),
                   help="desenha a figura em vez de calcular")
    p.add_argument("-s", "--saida", help="arquivo .png onde a figura e gravada")
    p.add_argument("-j", "--json", action="store_true", help="devolve JSON")
    return p


def main(argv):
    args = construir_argumentos().parse_args(argv)
    linhas = max(1, min(50, args.linhas))

    df = carregar(args.arquivo)

    if args.figura:
        if not args.saida:
            erro("a figura precisa de -s com o caminho do .png")
        resultado = desenhar(df, args.figura, args.saida, args.grupo, args.medida)
        if args.json:
            print(json.dumps({"operacao": "figura", "resultado": resultado},
                             ensure_ascii=False))
        else:
            print("Figura gravada em " + resultado["arquivo"]
                  + " (" + str(resultado["pontos"]) + " atendimentos).")
        return 0

    if args.operacao == "periodo":
        resultado = quinta_funcionalidade(df)
        saida_texto = texto_periodo(resultado)
    elif args.operacao == "grupo":
        resultado = agrupar(df, args.grupo, args.medida)
        saida_texto = texto_grupo(resultado)
    elif args.operacao == "visao":
        resultado = visao(df, linhas)
        saida_texto = texto_visao(resultado)
    elif args.operacao == "correlacao":
        resultado = correlacao(df, linhas)
        saida_texto = texto_correlacao(resultado)
    else:
        resultado = {
            "visao":      visao(df, linhas),
            "periodo":    quinta_funcionalidade(df),
            "grupo":      agrupar(df, args.grupo, args.medida),
            "correlacao": correlacao(df, linhas),
        }
        saida_texto = "\n\n".join([
            texto_visao(resultado["visao"]),
            texto_periodo(resultado["periodo"]),
            texto_grupo(resultado["grupo"]),
            texto_correlacao(resultado["correlacao"]),
        ])

    if args.json:
        print(json.dumps({
            "operacao":  args.operacao,
            "arquivo":   os.path.basename(args.arquivo),
            "pandas":    pd.__version__,
            "resultado": resultado,
        }, ensure_ascii=False, allow_nan=False))
    else:
        print("Base: " + os.path.basename(args.arquivo)
              + "   |   pandas " + pd.__version__
              + "   |   atendimentos: " + str(len(df)))
        print()
        print(saida_texto)

    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
