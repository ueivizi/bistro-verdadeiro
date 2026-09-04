#!/usr/bin/env bash

set -euo pipefail

export LC_ALL=C

ARQUIVO=""
OPERACAO="maior"
DIA=""
PERIODO=""
LIMITE=10
JSON=0

uso() {
    cat <<'AJUDA'
mineracao.sh - mineracao da base de gorjetas de um bistro.

A base (dados/gorjetas.csv) traz 244 atendimentos registrados por um garcom:

  total_bill , tip , sex , smoker , day , time , size
  valor conta, gorjeta, sexo, fumante, dia, periodo, pessoas

Uso:
  ./mineracao.sh -a dados/gorjetas.csv -o maior
  ./mineracao.sh -a dados/gorjetas.csv -o ranking -n 5 -d Sat
  ./mineracao.sh -a dados/gorjetas.csv -o resumo -p Dinner -j

Operacoes (-o):
  maior       maior gorjeta em valor absoluto
  percentual  maior gorjeta em relacao ao valor da conta
  ranking     as N maiores gorjetas (-n, padrao 10)
  dia         totais e medias por dia da semana
  resumo      estatisticas gerais da base filtrada

Filtros:  -d Thur|Fri|Sat|Sun     -p Lunch|Dinner
Saida:    texto (padrao) ou JSON com -j
AJUDA
}

erro() {
    echo "mineracao.sh: $1" >&2
    exit 2
}

while getopts ":a:o:d:p:n:jh" opcao; do
    case "$opcao" in
        a) ARQUIVO="$OPTARG" ;;
        o) OPERACAO="$OPTARG" ;;
        d) DIA="$OPTARG" ;;
        p) PERIODO="$OPTARG" ;;
        n) LIMITE="$OPTARG" ;;
        j) JSON=1 ;;
        h) uso; exit 0 ;;
        \?) erro "opção desconhecida: -$OPTARG" ;;
        :)  erro "a opção -$OPTARG exige um valor" ;;
    esac
done

[ -n "$ARQUIVO" ] || erro "informe a base com -a (ex.: -a dados/gorjetas.csv)"
[ -f "$ARQUIVO" ] || erro "base não encontrada: $ARQUIVO"
[ -r "$ARQUIVO" ] || erro "sem permissão de leitura em: $ARQUIVO"

case "$OPERACAO" in
    maior|percentual|ranking|dia|resumo) ;;
    *) erro "operação inválida: $OPERACAO" ;;
esac

case "$LIMITE" in
    ''|*[!0-9]*) erro "o limite (-n) deve ser um número inteiro" ;;
esac
[ "$LIMITE" -ge 1 ] || erro "o limite (-n) deve ser maior que zero"

registros() {
    tail -n +2 -- "$ARQUIVO" \
        | tr -d '"' \
        | awk -F, -v dia="$DIA" -v per="$PERIODO" '
            NF >= 7 && $1 + 0 > 0 &&
            (dia == "" || tolower($5) == tolower(dia)) &&
            (per == "" || tolower($6) == tolower(per))
        '
}

com_percentual() {
    registros | awk -F, '{ printf "%s,%.4f\n", $0, ($2 / $1) * 100 }'
}

registro_json() {
    awk -F, '{
        printf "{\"conta\":%.2f,\"gorjeta\":%.2f,\"sexo\":\"%s\",\"fumante\":\"%s\"," \
               "\"dia\":\"%s\",\"periodo\":\"%s\",\"pessoas\":%d,\"percentual\":%.2f}",
               $1, $2, $3, $4, $5, $6, $7, $8
    }'
}

cabecalho_texto() {
    printf '%-9s %-9s %-8s %-8s %-8s %-8s %-8s %s\n' \
        'CONTA' 'GORJETA' '%CONTA' 'DIA' 'PERIODO' 'SEXO' 'FUMANTE' 'PESSOAS'
    printf '%s\n' '--------------------------------------------------------------------------'
}

linha_texto() {
    awk -F, '{
        printf "%-9.2f %-9.2f %-8.2f %-8s %-8s %-8s %-8s %d\n",
               $1, $2, $8, $5, $6, $3, $4, $7
    }'
}

op_maior() {
    com_percentual | sort -t, -k2,2nr | awk 'NR == 1'
}

op_percentual() {
    com_percentual | sort -t, -k8,8nr | awk 'NR == 1'
}

op_ranking() {
    com_percentual | sort -t, -k2,2nr | awk -v n="$LIMITE" 'NR <= n'
}

op_dia() {
    com_percentual | awk -F, '
        {
            dia = $5
            if (!(dia in vistos)) { vistos[dia] = 1; ordem[++total] = dia }
            qtd[dia]++
            soma[dia]   += $2
            conta[dia]  += $1
            if ($2 > maior[dia]) maior[dia] = $2
        }
        END {
            for (i = 1; i <= total; i++) {
                d = ordem[i]
                printf "%s,%d,%.2f,%.2f,%.2f,%.2f\n",
                       d, qtd[d], soma[d], soma[d] / qtd[d], maior[d],
                       (conta[d] > 0 ? soma[d] / conta[d] * 100 : 0)
            }
        }
    '
}

op_resumo() {
    registros | cut -d, -f2 | sort -n | awk '
        { valores[NR] = $1; soma += $1 }
        END {
            if (NR == 0) { print "0,0,0,0,0,0"; exit }
            meio = int((NR + 1) / 2)
            mediana = (NR % 2 ? valores[meio] : (valores[meio] + valores[meio + 1]) / 2)
            printf "%d,%.2f,%.2f,%.2f,%.2f,%.2f\n",
                   NR, soma, soma / NR, mediana, valores[1], valores[NR]
        }
    '
}

filtros_json() {
    printf '{"dia":"%s","periodo":"%s"}' "$DIA" "$PERIODO"
}

saida_json() {
    local corpo="$1"
    printf '{"operacao":"%s","arquivo":"%s","filtros":%s,"resultado":%s}\n' \
        "$OPERACAO" "$(basename -- "$ARQUIVO")" "$(filtros_json)" "$corpo"
}

titulo_texto() {
    local extra=""
    [ -n "$DIA" ]     && extra=" | dia: $DIA"
    [ -n "$PERIODO" ] && extra="$extra | período: $PERIODO"

    echo "Base: $(basename -- "$ARQUIVO")$extra"
    echo "Registros analisados: $(registros | wc -l | tr -d ' ')"
    echo
}

case "$OPERACAO" in

    maior|percentual)
        linha="$(op_maior)"
        [ "$OPERACAO" = "percentual" ] && linha="$(op_percentual)"

        if [ -z "$linha" ]; then
            [ "$JSON" -eq 1 ] && saida_json "null" || echo "Nenhum registro atende aos filtros."
            exit 0
        fi

        if [ "$JSON" -eq 1 ]; then
            saida_json "$(printf '%s' "$linha" | registro_json)"
        else
            titulo_texto
            [ "$OPERACAO" = "maior" ] \
                && echo "Maior gorjeta em valor absoluto" \
                || echo "Maior gorjeta proporcional ao valor da conta"
            echo
            cabecalho_texto
            printf '%s\n' "$linha" | linha_texto
        fi
        ;;

    ranking)
        linhas="$(op_ranking)"

        if [ "$JSON" -eq 1 ]; then
            corpo="$(printf '%s\n' "$linhas" | awk 'NF' | while IFS= read -r l; do
                printf '%s' "$l" | registro_json
                printf ','
            done)"
            saida_json "[${corpo%,}]"
        else
            titulo_texto
            echo "As $LIMITE maiores gorjetas"
            echo
            cabecalho_texto
            printf '%s\n' "$linhas" | awk 'NF' | linha_texto
        fi
        ;;

    dia)
        linhas="$(op_dia)"

        if [ "$JSON" -eq 1 ]; then
            corpo="$(printf '%s\n' "$linhas" | awk 'NF' | awk -F, '{
                printf "{\"dia\":\"%s\",\"atendimentos\":%d,\"soma\":%.2f," \
                       "\"media\":%.2f,\"maior\":%.2f,\"percentual\":%.2f},",
                       $1, $2, $3, $4, $5, $6
            }')"
            saida_json "[${corpo%,}]"
        else
            titulo_texto
            echo "Gorjetas por dia da semana"
            echo
            printf '%-8s %-14s %-12s %-12s %-12s %s\n' \
                'DIA' 'ATENDIMENTOS' 'SOMA' 'MEDIA' 'MAIOR' '%CONTA'
            printf '%s\n' '----------------------------------------------------------------------'
            printf '%s\n' "$linhas" | awk 'NF' | awk -F, '{
                printf "%-8s %-14d %-12.2f %-12.2f %-12.2f %.2f\n", $1, $2, $3, $4, $5, $6
            }'
        fi
        ;;

    resumo)
        linha="$(op_resumo)"

        if [ "$JSON" -eq 1 ]; then
            corpo="$(printf '%s' "$linha" | awk -F, '{
                printf "{\"atendimentos\":%d,\"soma\":%.2f,\"media\":%.2f," \
                       "\"mediana\":%.2f,\"minima\":%.2f,\"maxima\":%.2f}",
                       $1, $2, $3, $4, $5, $6
            }')"
            saida_json "$corpo"
        else
            titulo_texto
            echo "Resumo das gorjetas"
            echo
            printf '%s' "$linha" | awk -F, '{
                printf "Atendimentos ....... %d\n", $1
                printf "Soma ............... %.2f\n", $2
                printf "Media .............. %.2f\n", $3
                printf "Mediana ............ %.2f\n", $4
                printf "Menor .............. %.2f\n", $5
                printf "Maior .............. %.2f\n", $6
            }'
        fi
        ;;
esac
