#!/bin/bash
#
# Quem está ligado ao MySQL quando o balcão começa a encher.
#
# PORQUE EXISTE: a 2026-09-05 às 08:41 as ligações chegaram a 513 contra um
# tecto de 512, e um site deste servidor levou com "Too many connections".
# Quando fomos ver, o pico já tinha passado e levou a prova com ele: o
# `Max_used_connections` diz QUANTAS foram e QUANDO, nunca DE QUEM.
#
# Sem isto, qualquer correcção é adivinhação — e duas teorias plausíveis
# (os schedulers em coro, os pools sobredimensionados) já foram medidas e
# desmentidas: em repouso o servidor anda nas 2 a 4 ligações.
#
# CUSTO: uma query por minuto, a mesma que se corre à mão sem impacto nenhum.
# Só escreve quando há alguma coisa a dizer.

LIMIAR=${LIMIAR:-60}
REGISTO=/var/log/mysql-conn-watch.log
TAMANHO_MAXIMO=$((5 * 1024 * 1024))

ligacoes=$(mysql -u root -N -e "SHOW STATUS LIKE 'Threads_connected';" 2>/dev/null | awk '{print $2}')

# Sem resposta do MySQL não há nada a registar — e um erro aqui não pode
# transformar-se em ruído de cron de minuto a minuto.
[ -z "$ligacoes" ] && exit 0
[ "$ligacoes" -lt "$LIMIAR" ] && exit 0

# O retrato só vale se disser de quem são as ligações: por utilizador (que no
# CloudPanel é o site), por base de dados e por estado.
{
    echo "=== $(date '+%Y-%m-%d %H:%M:%S') — ${ligacoes} ligações (limiar ${LIMIAR}) ==="
    mysql -u root -e "
        SELECT USER AS utilizador, DB AS base, COMMAND AS comando, COUNT(*) AS n
        FROM information_schema.PROCESSLIST
        GROUP BY USER, DB, COMMAND
        ORDER BY n DESC
        LIMIT 15;" 2>/dev/null
    echo "--- as cinco mais antigas (uma ligação presa aparece aqui) ---"
    mysql -u root -e "
        SELECT USER AS utilizador, DB AS base, TIME AS segundos, COMMAND AS comando,
               LEFT(COALESCE(INFO, ''), 80) AS consulta
        FROM information_schema.PROCESSLIST
        ORDER BY TIME DESC
        LIMIT 5;" 2>/dev/null
    echo
} >> "$REGISTO"

# O registo não pode ser ele próprio um problema no disco.
if [ -f "$REGISTO" ] && [ "$(stat -c %s "$REGISTO")" -gt "$TAMANHO_MAXIMO" ]; then
    tail -c $((TAMANHO_MAXIMO / 2)) "$REGISTO" > "${REGISTO}.tmp" && mv "${REGISTO}.tmp" "$REGISTO"
fi
