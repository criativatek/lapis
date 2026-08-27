#!/usr/bin/env bash
#
# Backup diário da base de dados do LÁPIS.
#
# PORQUÊ ESTE FICHEIRO EXISTE. Até 2026-08-27 os únicos dumps em produção eram
# manuais, feitos à mão antes de alguns deploys — o mais recente tinha dois dias
# e era anterior aos dois deploys desse mesmo dia. Uma base com dados de alunos
# sem cópia recuperável não é um risco operacional, é uma perda de dados à
# espera de acontecer.
#
# ESTÁ VERSIONADO, e corre a partir da própria pasta da aplicação, para que um
# deploy o mantenha atualizado sozinho. O que ele PRODUZ é que vive fora da
# aplicação — ver BACKUP_DIR.
#
# CREDENCIAIS: lidas de ~/.my.cnf (0600), nunca da linha de comando. Uma
# password num argumento aparece no `ps` de qualquer utilizador da máquina.
#
# NUNCA PUBLICA UM FICHEIRO PARCIAL. Escreve para `.tmp`, verifica que não está
# vazio, que o gzip abre e que o dump tem o rodapé que o mysqldump só escreve
# quando chega ao fim — e só então renomeia. Um dump truncado que passe por bom
# é pior do que não ter dump nenhum, porque só se descobre no dia da
# recuperação.
#
# A RETENÇÃO SÓ CORRE DEPOIS DE O NOVO BACKUP ESTAR VÁLIDO. Se o dump falhar, o
# script sai antes de apagar seja o que for: o backup de ontem sobrevive.
#
# Uso:  bash scripts/backup-database.sh
# Cron: ver docs/deployment.md, secção «Backups da base de dados».

set -euo pipefail

APP_DIR="${LAPIS_APP_DIR:-/home/lapis/htdocs/lapis.criativatek.com}"
BACKUP_DIR="${LAPIS_BACKUP_DIR:-/home/lapis/backups}"
DEFAULTS_FILE="${LAPIS_MYSQL_DEFAULTS:-$HOME/.my.cnf}"
LOG_FILE="$BACKUP_DIR/backup.log"

# Quantos manter. Diários cobrem o incidente recente; os do dia 1 de cada mês
# cobrem o erro que só se nota meses depois. Com ~50 KB por dump comprimido,
# 42 ficheiros são ~2 MB por ano — a política é escolhida pela utilidade, não
# pelo espaço.
KEEP_DAILY_DAYS="${LAPIS_KEEP_DAILY_DAYS:-30}"
KEEP_MONTHLY_COUNT="${LAPIS_KEEP_MONTHLY_COUNT:-12}"

started_at="$(date +%s)"
stamp="$(date +%Y%m%d-%H%M%S)"
day_of_month="$(date +%d)"

# Um backup do dia 1 é o mensal desse mês. O nome carrega a distinção para que
# a retenção não tenha de adivinhar nada a partir de datas de ficheiro.
if [ "$day_of_month" = "01" ]; then
    kind="monthly"
else
    kind="daily"
fi

final="$BACKUP_DIR/lapis-$kind-$stamp.sql.gz"
tmp="$final.tmp"

log() { printf '%s  %s\n' "$(date '+%Y-%m-%d %H:%M:%S %Z')" "$1" >> "$LOG_FILE"; }

# Qualquer saída por erro passa por aqui, incluindo um `set -e` disparado a meio.
# O ficheiro temporário nunca fica para trás a parecer um backup.
on_error() {
    local code=$?
    rm -f "$tmp"
    log "FALHOU  exit=$code  ficheiro=$(basename "$final")  (nada foi apagado; o backup anterior mantém-se)"
    exit "$code"
}
trap on_error ERR

[ -r "$DEFAULTS_FILE" ] || { echo "Sem $DEFAULTS_FILE legível (0600, secção [mysqldump])." >&2; exit 2; }
mkdir -p "$BACKUP_DIR"

database="$(sed -n 's/^DB_DATABASE=//p' "$APP_DIR/.env" | head -1 | tr -d '"'"'")"
[ -n "$database" ] || { echo "DB_DATABASE não encontrado em $APP_DIR/.env" >&2; exit 2; }

umask 027

# --single-transaction: consistente em InnoDB sem trancar a aplicação.
# --no-tablespaces: o utilizador da aplicação não tem PROCESS global, e sem
#   esta opção o mysqldump 8 recusa-se a começar.
# --set-gtid-purged=OFF: sem isto o dump traz um SET @@GLOBAL.gtid_purged que
#   exige SUPER para restaurar — precisamente o que não teremos no dia mau.
# --routines/--triggers/--events: hoje não há nenhum; no dia em que houver, o
#   backup não passa a estar silenciosamente incompleto.
mysqldump \
    --defaults-file="$DEFAULTS_FILE" \
    --single-transaction \
    --quick \
    --no-tablespaces \
    --set-gtid-purged=OFF \
    --routines \
    --triggers \
    --events \
    --default-character-set=utf8mb4 \
    "$database" | gzip -9 > "$tmp"

# Três verificações, porque cada uma apanha uma falha diferente: disco cheio a
# meio, gzip corrompido, e dump interrompido depois de comprimir bem.
[ -s "$tmp" ] || { echo "Dump vazio." >&2; exit 3; }
gzip -t "$tmp" || { echo "gzip inválido." >&2; exit 4; }
gzip -dc "$tmp" | tail -5 | grep -q '^-- Dump completed' || { echo "Dump sem rodapé — truncado." >&2; exit 5; }

mv "$tmp" "$final"
chmod 640 "$final"

# ---- Retenção. Só aqui, e só com um backup novo já válido no disco. --------
deleted=0
while IFS= read -r old; do
    rm -f "$old" && deleted=$((deleted + 1))
done < <(find "$BACKUP_DIR" -maxdepth 1 -name 'lapis-daily-*.sql.gz' -type f -mtime "+$KEEP_DAILY_DAYS")

# Mensais: mantém os N mais recentes, apaga o resto. Contagem em vez de idade,
# porque «12 meses» com meses de 28 a 31 dias é uma conta que não vale a pena.
while IFS= read -r old; do
    rm -f "$old" && deleted=$((deleted + 1))
done < <(find "$BACKUP_DIR" -maxdepth 1 -name 'lapis-monthly-*.sql.gz' -type f -printf '%T@ %p\n' \
    | sort -rn | tail -n "+$((KEEP_MONTHLY_COUNT + 1))" | cut -d' ' -f2-)

size="$(stat -c %s "$final")"
duration=$(( $(date +%s) - started_at ))
sha="$(sha256sum "$final" | cut -c1-16)"

log "OK  $(basename "$final")  ${size} bytes  ${duration}s  sha256:${sha}…  retencao_removeu=${deleted}"
echo "Backup concluído: $final (${size} bytes, ${duration}s)"
