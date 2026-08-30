# Deployment — lapis.criativatek.com

Alvo de produção identificado: **VPS Contabo (`161.97.80.63`) com CloudPanel**,
site `lapis.criativatek.com` atrás de **Cloudflare**. Serve neste momento a página
default do CloudPanel («Hello World :-)»). SSH aberto (porta 22), painel na 8443.

> **Segredos nunca no repositório.** As credenciais do painel/SSH ficam num gestor
> de palavras-passe; a configuração de produção vive no `.env` **no servidor**, não
> no git. Este documento descreve o processo — sem passwords.

## Estado

**Em produção** desde 2026-07-27: `https://lapis.criativatek.com` serve o Lapispro,
migrações + os três seeders de referência (`ReferenceDataSeeder` — ver «Passos»
abaixo) corridos, Cloudflare + HTTPS ativos. Backoffice `/admin` no ar.

O acesso SSH faz-se pelo **SSH User `lapis-deploy`** — um utilizador dedicado a
este site, criado em CloudPanel → Sites → SSH/FTP com home própria
(`/home/lapis-deploy`). **Não** usar o Site User `lapis` (recusa password/chave
pelo painel) nem o antigo `deploy`, que é um nome genérico partilhado com outros
sites deste VPS e cuja `authorized_keys` pertence a outra equipa (armadilha 6).
Entrada em `~/.ssh/config`:

```
Host lapis-prod
    HostName 161.97.80.63
    User lapis-deploy
    IdentityFile ~/.ssh/lapis_deploy
    IdentitiesOnly yes
```

Duas condições têm de estar satisfeitas para um deploy correr, e **as duas já
falharam em produção**: a chave tem de estar em `authorized_keys2` (armadilha 6)
e o `lapis-deploy` tem de ser **dono** dos ficheiros da aplicação (armadilha 8).

**Versão em produção: 0.45.9** (commit `74e8ff2`) desde 2026-08-22, confirmado
ao vivo via `php artisan lapis:release-check` / `php artisan about`. Antes
disso, 0.37.0 desde 2026-08-20 (Relatórios, Acompanhamento do Aluno,
Estratégias e Medidas, reorganização da navegação; três migrations aditivas, e
duas dependências novas — `dompdf/dompdf` e `phpoffice/phpword` — que tornam o
`composer install` do passo 5 obrigatório e não opcional); antes dessa, 0.36.0
desde 2026-08-18 (Identidade da escola).
**Confirmar sempre a versão real no servidor (`grep version config/app.php`)
antes de assumir de onde parte o deploy**, já que o servidor não tem `.git` e
nada indica de fora qual o commit que lá está — o `build.json` do pacote e o
`lapis:release-check` respondem-no sem depender de memória.

## Atualizações (redeploy de código) — o fluxo que funciona

Não voltar a fazer `git clone`. O `.env`/`APP_KEY` vivem **só no servidor** —
reescrevê-los invalida sessões, 2FA e a password SMTP cifrada. Enviar só código:

> **O pacote é construído a partir dos ficheiros versionados no Git, não a
> partir da working directory.** Nada entra por estar em disco. Entram os
> ficheiros que o `git ls-files` conhece, mais dois artefactos gerados e
> nomeados de propósito — `build.json` e `public/build/` —, e mais nada.

```bash
# 0. Assets: o servidor tem Node 12, demasiado antigo para o build. Compilar
#    SEMPRE localmente antes de empacotar.
npm run build

# 1. Carimbar e empacotar, num só comando. NÃO voltar a construir o tar à mão —
#    ver «Porquê a allowlist» abaixo. Recusa se o repositório não corresponder
#    ao HEAD (working tree OU staging), verifica o pacote depois de o criar, e
#    falha em vez de produzir um pacote suspeito.
php artisan lapis:build-package

# 2. O comando já verificou o pacote. Isto é só o que se quer ver com os olhos
#    antes de enviar — versão, carimbo e as migrations desta release:
tar -xzOf update.tgz config/app.php | grep "'version'"
tar -xzOf update.tgz build.json
tar -tzf update.tgz | grep migrations/ | tail -3

# 3. Testar propriedade e escrita ANTES de entrar em manutenção (armadilha 8).
#    Um tar que não consegue escrever falha ficheiro a ficheiro e o script
#    segue em frente: mais vale descobrir isto com o site no ar.
ssh lapis-prod 'cd /home/lapis/htdocs/lapis.criativatek.com &&
  stat -c "%U:%G %a %n" app config database public resources routes &&
  for d in . app config database public resources routes storage bootstrap/cache; do
    touch "$d/.wtest" 2>/dev/null && rm -f "$d/.wtest" && echo "OK $d" || echo "FALHA $d"
  done'
#    Todos têm de dar OK e pertencer a lapis-deploy:lapis. Se não, ver armadilha 8.

# 4. Enviar o pacote (ainda sem manutenção — encurta a indisponibilidade):
scp update.tgz lapis-prod:/home/lapis-deploy/update.tgz

# 5. Manutenção, extração e reconstrução:
ssh lapis-prod 'bash -s' <<'EOF'
cd /home/lapis/htdocs/lapis.criativatek.com || exit 1
php artisan down --retry=60 || exit 1
tar xzf /home/lapis-deploy/update.tgz --no-overwrite-dir --no-same-permissions --no-same-owner || true
test -f artisan || { echo "artisan ausente"; exit 1; }
grep "'version'" config/app.php          # TEM de mostrar já a versão nova
rm -f bootstrap/cache/{config,packages,services,routes-v7}.php   # armadilha 1
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction || exit 1
php artisan migrate:status | grep Pending    # confirmar ANTES de migrar
php artisan migrate --force || exit 1
# Dados de referência, em TODOS os deploys e não só no primeiro (armadilha 9).
# Idempotente: updateOrCreate + sync. Não toca em subscrições nem em dados
# académicos.
php artisan db:seed --class=ReferenceDataSeeder --force || exit 1
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan up
EOF

# 5b. Reiniciar o render no servidor — tem o bundle em memória e continuaria a
#     servir o da release anterior.
ssh lapis-prod 'sudo systemctl restart lapis-ssr'

# 6. O deploy SÓ está concluído depois disto. Substituir pela versão e commit
#    que se pretendia enviar (`git rev-parse --short HEAD` local). Sai != 0 e
#    diz o que difere se a aplicação estiver a correr outra coisa.
ssh lapis-prod 'cd /home/lapis/htdocs/lapis.criativatek.com &&
  php artisan lapis:release-check --expect-version=0.31.0 --expect-commit=f39c084'
```

**Um deploy sem o passo 6 não está confirmado, está suposto.** Foi assim que a
0.29.1 ficou por instalar sem ninguém dar por isso: a versão subiu no git, o
deploy nunca aconteceu, e durante um mês a única forma de saber o que estava lá
era lembrar-se. O `--expect-version` apanha o caso em que a aplicação continua
na versão antiga (incluindo com o `config:cache` velho, porque a versão é lida
da configuração carregada e não do ficheiro); o `--expect-commit` apanha o caso
mais traiçoeiro, em que o número da versão bate certo mas o código é de outro
commit. Sem carimbo no pacote, o comando falha em vez de encolher os ombros.

Para saber o que lá está sem comparar nada — `php artisan lapis:release-check`
sozinho, ou o `php artisan about`, que traz a mesma informação na secção Lapispro.

**Confirmar sempre que a extração escreveu mesmo.** O `tar` devolve estado de
erro global mas o script continua, e `grep version` sozinho não prova que os
outros 1300 ficheiros foram substituídos. A verificação decisiva é comparar
checksums entre local e servidor:

```bash
md5sum config/app.php composer.lock public/build/manifest.json
ssh lapis-prod 'cd /home/lapis/htdocs/lapis.criativatek.com &&
  md5sum config/app.php composer.lock public/build/manifest.json'
```

## Porquê a allowlist (e porque não voltar ao `tar --exclude`)

Até 2026-08-15 o pacote era um `tar` da working directory com uma lista de
`--exclude`. **O `tar` não lê o `.gitignore`** — está escrito na armadilha 1
desde o início —, por isso entrava tudo o que ninguém se tivesse lembrado de
nomear. Um teste com uma sonda untracked confirmou-o: um ficheiro arbitrário na
raiz viajava para produção.

Não era hipotético. Foram assim para produção 6 MB de ferramentas de IA, um
`storage/app` local que poluiu as fotografias reais dos alunos, e — descoberto
a 2026-08-15, lá desde 31 de julho — quatro **exportações reais não
anonimizadas** de alunos, na pasta `Ficheiros avulsos/` que o `.gitignore`
marca como «never commit». Todos eram ficheiros que ninguém pôs na lista, que é
precisamente aquilo contra o que uma denylist não protege.

A regra é agora ao contrário: **nada entra a não ser que o git o conheça**, mais
`build.json` e `public/build/`. Um ficheiro local novo não chega a produção por
ter sido esquecido, porque ser esquecido passou a ser o estado seguro. O
`lapis:build-package` monta a lista, cria o tar e depois **lê o pacote de volta**
para confirmar que nada proibido entrou e nada essencial faltou.

Isto resolve estruturalmente as armadilhas 1 e 3 abaixo: os ficheiros de
`bootstrap/cache/` e o conteúdo de `storage/app/` são gitignorados, logo já não
são sequer alcançáveis. Ficam registadas porque explicam o porquê — e porque a
lição sobre `composer install` (armadilha 2) continua a valer.

**Armadilhas que já partiram o site (ou o armazenamento):**

1. **Nunca enviar `bootstrap/cache/`.** O cache local lista providers de dev
   (Laravel\Pail) que não existem em produção (`--no-dev`) → `Class ... not found`
   no `config:cache`. Hoje impossível: é gitignorado e a allowlist não lhe toca
   (só o `.gitignore` que define a pasta viaja). Se alguma vez acontecer: apagar
   `bootstrap/cache/{packages,services,config}.php` no servidor + `composer install`.
2. **Correr sempre `composer install`** depois de extrair — regenera o cache de
   providers para o conjunto de produção. Saltar isto foi o que expôs a armadilha 1.
3. **Nunca enviar `storage/app/`.** Contém conteúdo carregado (fotos de alunos,
   pastas temporárias de importação) específico de cada ambiente — nunca faz
   parte do código. Em 2026-07-31 o `storage/app` local (com ficheiros de teste
   de dias anteriores) foi enviado por engano para produção, poluindo
   `storage/app/private/student-photos` e `roster-imports` com dezenas de
   ficheiros irrelevantes. Hoje impossível pela mesma razão: é gitignorado. Se
   alguma vez acontecer, os ficheiros a remover no servidor identificam-se pela
   data de modificação (`stat -c '%y %n' storage/app/private/*/*`) — qualquer
   coisa mais antiga do que o próprio deploy é suspeita.
4. **Qualquer pasta dentro de `storage/app/private/` tem de ter permissão de
   escrita para o grupo (`g+w`), não só para o dono.** O site corre como user
   `lapis`, mas o `deploy` (usado para gerir ficheiros por SSH) só partilha o
   GRUPO `lapis` com ele — não é dono de nada. Uma pasta criada ou alterada
   por `deploy` (ex.: ao limpar ficheiros da armadilha 3) fica `750`
   (`rwxr-x---`): o grupo só lê, não escreve. A app corre como `lapis` e falha
   com `UnableToCreateDirectory` ao tentar criar uma subpasta lá dentro — foi
   o que aconteceu em 2026-07-31 logo a seguir à limpeza da armadilha 3.
   Sempre que se mexer nestas pastas via `deploy`, terminar com
   `chmod -R g+rwX storage/app/private/roster-imports storage/app/private/student-photos`
   (ou confirmar que já são `770`) antes de dar como resolvido.
5. **Correr só o `EntitlementsSeeder` no primeiro deploy deixou os outros dois
   seeders de referência por seedar.** `instrument_types` ficou vazio em
   produção — o dropdown "Tipo" ao criar um instrumento não tinha nenhuma
   opção. `SystemScalesSeeder` calhou já ter sido corrido por outra via, mas
   podia não ter sido. Corrigido a correr `InstrumentTypesSeeder` diretamente
   (idempotente); os «Passos» abaixo já apontam para `ReferenceDataSeeder`
   (os três juntos) em vez de só o `EntitlementsSeeder`.
6. **O SSH user `deploy` não é exclusivo do LAPIS — a sua `authorized_keys` é
   reescrita e a nossa chave desaparece.** Este VPS aloja mais sites (há crons
   de `criativatek-track2lab` e `xapp`), e `deploy` é um nome genérico
   partilhado: `/home/deploy/.ssh/authorized_keys` acabou a conter três chaves
   de outra equipa (`vladyslavkotyk`, `micael`, `fabio`) que **nunca** foram
   as que o painel do LAPIS mostrava para este mesmo utilizador. Ou seja, o
   painel de um site e o ficheiro em disco estavam a descrever conjuntos
   disjuntos de chaves.
   Consequências observadas em 2026-08-13, ao longo de horas: a chave colada
   no CloudPanel (`lapis.criativatek.com` → SSH/FTP → `deploy` → SSH Keys)
   aparecia guardada na interface, sobrevivia a recarregamentos da página, mas
   **nunca chegava ao disco**; adicionada à mão como `root` funcionava, e era
   apagada minutos depois. Diagnosticar isto pela permissão do ficheiro é
   perder tempo — `namei -l` e `sshd -T` mostravam tudo correto (dono `deploy`,
   grupo `lapis`, `700`/`600`, `pubkeyauthentication yes`). O sinal fiável é
   o **tamanho** de `authorized_keys`: ~90 bytes por chave ed25519, portanto
   271 bytes = 3 chaves = a nossa já lá não está.
   **Criar um SSH user dedicado não chega.** Foi o que se fez primeiro
   (`lapis-deploy`, com home própria) e a 2026-08-14 o painel reescreveu-lhe a
   `authorized_keys` **na mesma**, com as mesmas três chaves de terceiros — 24
   segundos depois de a nossa chave ter funcionado. O nome partilhado não era a
   causa; o painel gere aquele ficheiro em qualquer utilizador SSH que conheça.
   **Solução que resulta: `~/.ssh/authorized_keys2`.** O `sshd` lê os dois
   ficheiros (`AuthorizedKeysFile .ssh/authorized_keys .ssh/authorized_keys2`),
   e o CloudPanel só gere o primeiro. Pôr lá a chave de deploy, com `600` e dono
   correto, e o acesso deixa de desaparecer:
   ```bash
   # como root, uma vez:
   install -o lapis-deploy -g lapis -m 700 -d /home/lapis-deploy/.ssh
   printf '%s\n' 'ssh-ed25519 AAAA... deploy@lapis' > /home/lapis-deploy/.ssh/authorized_keys2
   chown lapis-deploy:lapis /home/lapis-deploy/.ssh/authorized_keys2
   chmod 600 /home/lapis-deploy/.ssh/authorized_keys2
   ```
   Continua por esclarecer, e é uma questão de proteção de dados e não de
   comodidade, **porque é que as chaves de `vladyslavkotyk`, `micael` e `fabio`
   são injetadas num utilizador deste site**: pertencem ao grupo `lapis`, logo
   leem o `.env` — credenciais da base de dados e `APP_KEY` — e por aí os dados
   dos alunos.
7. **Tentativas repetidas de SSH fazem o `fail2ban` banir o IP de origem.**
   Ainda em 2026-08-13, depois de várias falhas de autenticação seguidas
   (consequência da armadilha 6), o IP passou de `Permission denied` a
   `Connection timed out` — sintoma diferente, causa diferente. Confirmar com
   `fail2ban-client status sshd` (o IP aparece em «Banned IP list») e resolver
   com `fail2ban-client set sshd unbanip <ip>`; para não voltar a acontecer
   durante uma sessão de trabalho, `fail2ban-client set sshd addignoreip <ip>`.
   O `sshd` deste servidor tem também `MaxAuthTries 3`
   (`/etc/ssh/sshd_config.d/99-hardening.conf`): não redirecionar um ficheiro
   para o `stdin` do `ssh` sem a chave a funcionar, senão o cliente tenta usar
   os bytes do ficheiro como password e esgota as tentativas.
8. **O utilizador de deploy tem de ser DONO do código, senão o `tar` extrai
   zero ficheiros e o deploy segue em frente a mentir.** A 2026-08-14, no deploy
   da 0.29.0, os diretórios (`app`, `config`, `database`, `public`, …) ainda
   pertenciam ao antigo user `deploy` com modo `750`: o grupo `lapis` lê e
   atravessa, **não escreve**. O `lapis-deploy` está no grupo mas não é dono de
   nada, portanto não pode substituir nem criar ficheiros lá dentro. Resultado:
   845 ficheiros recusados com `Cannot open: File exists` e `Permission denied`,
   `tests/Unit/Interventions/` nem chegou a ser criada, e `config/app.php`
   continuou na versão anterior — com o site em manutenção e o script a dar a
   extração por concluída. Não há `sudo` para o `lapis-deploy`; **corrigir como
   `root`, uma vez**:
   ```bash
   cd /home/lapis/htdocs/lapis.criativatek.com
   chown -R lapis-deploy:lapis .                    # código: dono é quem faz deploy
   chown -R lapis:lapis storage bootstrap/cache     # devolver ao user do site
   chmod -R g+rwX storage bootstrap/cache           # app E deploy escrevem aqui
   ```
   O código fica do `lapis-deploy` (que o substitui a cada deploy) com grupo
   `lapis` (que o web lê); `storage` e `bootstrap/cache` ficam do `lapis` — o
   utilizador do processo web — com escrita para o grupo, porque **ambos** lá
   escrevem: a aplicação em runtime e o deploy no `config:cache`/`view:cache`.
   O `umask` do `lapis-deploy` é `0007`, logo os ficheiros novos saem `640`/`660`
   com grupo `lapis` — o web lê sem se mexer em mais nada.
   **Nunca `chmod 777`, nem `chmod -R 777`, nem sequer «só para desbloquear»:**
   dá escrita a qualquer utilizador do VPS — e este VPS aloja outros sites, com
   outras equipas. O problema aqui nunca é o modo ser restritivo de mais, é a
   *propriedade* estar errada; `777` mascara isso e deixa o código da aplicação
   gravável por terceiros. A permissão mais aberta legítima neste servidor é
   `770` (`storage`, `bootstrap/cache`), sempre com grupo `lapis`.
9. **Os seeders de referência têm de correr em TODOS os deploys, não só no
   primeiro.** Um módulo novo (`modules` + `module_plan`) é **dados de
   referência**, não schema: alterar o `EntitlementsSeeder` sem o executar deixa
   os planos existentes sem a nova capability, e a funcionalidade fica invisível
   para toda a gente — incluindo para quem paga Pro. Nada falha, nada avisa; o
   botão simplesmente não aparece.
   Aconteceu a 2026-08-15 em desenvolvimento com `correction_grid_import`: o
   seeder tinha a capability em Pro e Institucional, a base tinha 25 módulos em
   vez de 26, e as três organizações Pro/Institucional resolviam `false`. Os
   testes não o apanham — usam SQLite em memória e semeiam-se a si próprios, por
   isso passam todos enquanto a base real fica para trás.
   O passo `db:seed --class=ReferenceDataSeeder --force` no fluxo acima resolve
   isto: é idempotente (`updateOrCreate` + `sync`), não toca em subscrições nem
   em dados académicos, e custa menos de um segundo. Correr **sempre**, mesmo
   quando a release "não mexeu em planos" — quem diz isso é a mesma pessoa que
   não se lembra de ter acrescentado um módulo há três semanas.

O `tar x` usa `--no-same-owner/permissions` para não tentar impor donos e modos
do ambiente local; o `|| true` engole o aviso de `chmod` na própria pasta `.`.
Esse `|| true` é também o que torna a armadilha 8 silenciosa — daí a verificação
por checksum acima.

## Pré-requisitos no servidor (confirmar no CloudPanel)

- **PHP 8.4** selecionado para o site (CloudPanel → Site → Settings → PHP Version).
- **MySQL**: criar base de dados + utilizador (CloudPanel → Databases). Guardar as
  credenciais para o `.env`.
- **Node**: o servidor tem **v12.22.9**, demasiado antigo para o Vite. Os assets
  são **sempre** compilados localmente e enviados em `public/build` — ver nota no fim.
- **Document root** do site = `.../htdocs/lapis.criativatek.com/public` (Laravel serve
  a partir de `public/`, não da raiz).

## Laravel Scheduler (cron) — obrigatório

**Sem esta entrada de cron, cinco tarefas de limpeza existem no código e nunca
correm.** Estiveram assim até 2026-08-27, e o que ficava por apagar não era
inócuo: pastas temporárias de importação de pautas com **fotografias de
alunos**, grelhas de correção, grelhas INOVAR com **nomes, números de processo
e notas**, e os ZIP de «Exportar os meus dados» — que o produto promete manter
apenas 24h. Uma instalação sem cron acumula tudo isso indefinidamente.

As tarefas estão em [`routes/console.php`](../routes/console.php), todas
`hourly()`. **Nunca as duplicar no crontab**: o crontab invoca uma única
entrada, e é o Laravel que decide o que está devido.

### A entrada

> **Os marcadores dizem `LAPIS` e continuam a dizer.** O produto passou a
> chamar-se Lapispro em 0.79.0, mas estes delimitadores identificam blocos que
> **já existem no crontab do servidor**, e a instalação idempotente apaga por
> intervalo de marcador (`sed '/^# >>> LAPIS/,/^# <<< LAPIS/d'`). Renomeá-los
> aqui deixaria de encontrar os blocos instalados: a limpeza não apagaria nada,
> a instalação acrescentaria um segundo par, e passaria a haver duas entradas de
> scheduler a correr de minuto a minuto. O mesmo vale para `LAPIS_KEEP_*` em
> `scripts/backup-database.sh` e para `/home/lapis`.

Instalada no **crontab do `lapis-deploy`** (`crontab -e` como esse utilizador —
é quem é dono do código e pertence ao grupo `lapis`, pelo que consegue apagar
o que o php-fpm escreveu em `storage/app/private/*`, a `770`):

```cron
# >>> LAPIS scheduler >>> (gerido por docs/deployment.md; nao editar a mao)
* * * * * umask 002; cd /home/lapis/htdocs/lapis.criativatek.com && /usr/bin/php artisan schedule:run >> storage/logs/scheduler.log 2>&1
# <<< LAPIS scheduler <<<
```

- **`/usr/bin/php`** (→ `php8.4`), caminho absoluto: o cron não tem o `PATH` de
  uma shell de login.
- **`umask 002`**: sem ele, um ficheiro criado pelo cron sai `644` e o php-fpm
  (utilizador `lapis`) deixa de lhe conseguir escrever. Com ele sai `664` e
  ambos os utilizadores partilham os ficheiros pelo grupo `lapis`.
- **Persistente por natureza** — um crontab de utilizador é lido do
  `/var/spool/cron/crontabs` pelo `cron`, que arranca no boot. Não precisa de
  nada em `systemd` nem em `supervisor`.

### Reinstalar sem duplicar — os dois blocos de uma vez

O crontab do `lapis-deploy` tem **dois** blocos do Lapispro, ambos delimitados por
marcadores. Correr isto três vezes deixa **seis linhas**, não dezoito:

```bash
APP=/home/lapis/htdocs/lapis.criativatek.com
KEPT="$(crontab -l 2>/dev/null | sed '/^# >>> LAPIS/,/^# <<< LAPIS/d' || true)"
{ printf '%s\n' "$KEPT" | sed '/^$/d'
  echo "# >>> LAPIS scheduler >>> (gerido por docs/deployment.md; nao editar a mao)"
  echo "* * * * * umask 002; cd $APP && /usr/bin/php artisan schedule:run >> storage/logs/scheduler.log 2>&1"
  echo "# <<< LAPIS scheduler <<<"
  echo "# >>> LAPIS backup >>> (gerido por docs/deployment.md; nao editar a mao)"
  echo "17 4 * * * bash $APP/scripts/backup-database.sh >> /home/lapis/backups/backup.log 2>&1"
  echo "# <<< LAPIS backup <<<"
} | crontab -
crontab -l | grep -c 'artisan schedule:run'   # 1
crontab -l | grep -c 'backup-database.sh'     # 1
crontab -l | wc -l                            # 6
```

**Apagar por intervalo entre marcadores, nunca por conteúdo linha a linha.** As
duas primeiras versões deste procedimento filtravam por texto de *algumas*
linhas do bloco — e a linha de comentário que não correspondia a nenhum dos
padrões acumulava uma cópia por cada reinstalação. Aconteceu duas vezes, com
blocos diferentes, pela mesma razão. É inofensivo (são comentários) e
exatamente o tipo de coisa que ninguém repara durante um ano.

### Como confirmar que está mesmo a correr

```bash
crontab -l                       # a entrada, uma só vez
php artisan schedule:list        # as cinco tarefas e o «Next Due»
stat -c '%y %s' storage/logs/scheduler.log   # mtime dentro do último minuto
tail -20 storage/logs/scheduler.log
```

**A linha no crontab não é prova de nada.** A prova é o `mtime` do
`scheduler.log` a avançar sozinho, e a tarefa horária a aparecer no ficheiro
depois de passar o minuto `:00`, assim:

```
  2026-08-27 09:00:03 Running ['artisan' roster-imports:prune] ....... 1s DONE
```

**Onde é que um erro aparece.** O Laravel corre cada tarefa agendada com o seu
próprio `> /dev/null 2>&1`, pelo que a *saída* de cada comando não vai para o
`scheduler.log` — vai o **veredito**, `DONE` ou `FAIL`, nesta linha. Uma
exceção continua a ser registada normalmente em `storage/logs/laravel.log`.
Para ver o que um comando específico imprime, correr esse comando à mão.

### Fuso horário

O sistema está em **Europe/Berlin**; a aplicação em **Europe/Lisbon**
(`config/app.php`), uma hora de diferença. **Não é um problema para estas cinco
tarefas**: o cron dispara ao minuto independentemente do fuso, e o Laravel
avalia `hourly()` no fuso *da aplicação* — que é o minuto `:00` em ambos. Passa
a importar no dia em que existir uma tarefa com hora fixa (`dailyAt('03:00')`),
que correria às 03:00 de Lisboa, ou seja 04:00 do relógio do servidor.

### `scheduler.log`

Cresce ~70 KB/dia (duas linhas por minuto quando nada está devido). **Não tem
rotação configurada** — acrescentá-lo ao `logrotate` exige root e fica por
fazer; entretanto, truncá-lo é seguro a qualquer momento.

## Backups da base de dados

**Até 2026-08-27 não havia backup automático nenhum.** Os únicos dumps eram
manuais, feitos à mão antes de alguns deploys; o mais recente tinha dois dias e
era anterior aos dois deploys desse mesmo dia. Uma base com dados de alunos sem
cópia recuperável não é um risco operacional, é uma perda de dados à espera de
acontecer.

| | |
|---|---|
| Script | [`scripts/backup-database.sh`](../scripts/backup-database.sh) — versionado, corre da própria pasta da aplicação, atualizado por cada deploy |
| Frequência | Diária, **04:17 do relógio do servidor** (03:17 em Lisboa) |
| Destino | `/home/lapis/backups/` — **fora da aplicação e fora do web root** |
| Formato | `lapis-{daily,monthly}-YYYYMMDD-HHMMSS.sql.gz` (gzip -9) |
| Permissões | ficheiros `640 lapis-deploy:lapis`; pasta `770 lapis:lapis` |
| Retenção | diários **30 dias**; mensais (dia 1) os **12 mais recentes** |
| Credenciais | `~/.my.cnf` do `lapis-deploy`, modo `600` |
| Log | `/home/lapis/backups/backup.log` |

### Credenciais — nunca na linha de comando

A password vive em `/home/lapis-deploy/.my.cnf` (`0600`), com secções
`[client]` e `[mysqldump]`. **Não a passar em argumento**: uma password num
argumento aparece no `ps` de qualquer utilizador da máquina. Para recriar o
ficheiro a partir do `.env` da aplicação, sem nunca a imprimir:

```bash
APP=/home/lapis/htdocs/lapis.criativatek.com
get() { sed -n "s/^$1=//p" "$APP/.env" | head -1 | sed -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'\$/\1/"; }
umask 077
printf '[client]\nuser=%s\npassword=%s\nhost=%s\nport=%s\n\n[mysqldump]\nuser=%s\npassword=%s\nhost=%s\nport=%s\n' \
  "$(get DB_USERNAME)" "$(get DB_PASSWORD)" "$(get DB_HOST)" "$(get DB_PORT)" \
  "$(get DB_USERNAME)" "$(get DB_PASSWORD)" "$(get DB_HOST)" "$(get DB_PORT)" > ~/.my.cnf
chmod 600 ~/.my.cnf
```

### O que o script garante

Escreve para `.tmp`, e só renomeia depois de três verificações: ficheiro não
vazio, `gzip -t` válido, e o rodapé `-- Dump completed` que o `mysqldump` só
escreve quando chega ao fim. **Um dump truncado que passe por bom é pior do que
não ter dump nenhum**, porque só se descobre no dia da recuperação. A retenção
corre **depois** de o novo backup estar válido — se o dump falhar, o script sai
antes de apagar seja o que for e o backup de ontem sobrevive.

Opções do `mysqldump` que não são cosméticas: `--no-tablespaces` (o utilizador
da aplicação não tem `PROCESS` global e sem isto o dump nem começa) e
`--set-gtid-purged=OFF` (sem isto o dump traz um `SET @@GLOBAL.gtid_purged` que
exige `SUPER` para restaurar — precisamente o que não haverá no dia mau).

### Cron

Gerido em blocos delimitados, junto com o do scheduler:

```cron
# >>> LAPIS backup >>> (gerido por docs/deployment.md; nao editar a mao)
17 4 * * * bash /home/lapis/htdocs/lapis.criativatek.com/scripts/backup-database.sh >> /home/lapis/backups/backup.log 2>&1
# <<< LAPIS backup <<<
```

### Verificar o último backup

```bash
tail -5 /home/lapis/backups/backup.log      # OK/FALHOU, tamanho, duração, sha256
ls -lt /home/lapis/backups/lapis-*.sql.gz | head -3
F=$(ls -1t /home/lapis/backups/lapis-*.sql.gz | head -1)
gzip -t "$F" && echo "gzip válido"
gzip -dc "$F" | tail -2 | grep '^-- Dump completed' && echo "dump completo"
gzip -dc "$F" | grep -c '^CREATE TABLE'     # tem de bater com o nº de tabelas
```

Uma linha `FALHOU` no log traz o `exit code`: `2` credenciais/`.env`, `3` dump
vazio, `4` gzip inválido, `5` dump truncado. Em qualquer desses casos **o
backup anterior está intacto** — resolver a causa e correr o script à mão.

### Restaurar — e a regra que não se quebra

**A base restaurada é sempre uma base temporária, e a aplicação nunca aponta
para ela.** Não alterar `DB_DATABASE` em produção; não correr a aplicação
contra a base de teste. A validação faz-se com as ferramentas de base de dados.

O utilizador `lapis` tem `ALL PRIVILEGES ON lapis.*` e **não pode criar bases**,
pelo que o ensaio de restauro não se faz no próprio servidor com essas
credenciais. Duas vias:

1. **CloudPanel → Databases**, criar `lapis_restore_test_YYYYMMDD_HHMM` com
   utilizador próprio, restaurar, comparar, e apagar a base no painel. Fica no
   mesmo motor de produção — é a via mais fiel.
2. **Estação de trabalho**: trazer o dump e restaurar num MySQL local. Foi o que
   se fez em 2026-08-27. Isolamento total; a ressalva é que o motor local pode
   não ser a mesma versão, pelo que prova que o dump carrega e está íntegro sem
   ser um ensaio no mesmo motor.

```bash
# 1. trazer e confirmar que não se corrompeu em trânsito
scp lapis-prod:/home/lapis/backups/lapis-daily-XXXX.sql.gz .
sha256sum lapis-daily-XXXX.sql.gz     # comparar com o do servidor

# 2. base temporária, nome que não se confunde com produção
mysql -h 127.0.0.1 -P 3308 -u root -e \
  "CREATE DATABASE \`lapis_restore_test_YYYYMMDD_HHMM\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"

# 3. restaurar (exit 0 e stderr vazio)
gzip -dc lapis-daily-XXXX.sql.gz | mysql -h 127.0.0.1 -P 3308 -u root lapis_restore_test_YYYYMMDD_HHMM

# 4. comparar — a MESMA consulta nos dois lados, e juntar POR CHAVE
LC_ALL=C sort prod.txt > prod.sorted; LC_ALL=C sort restored.txt > restored.sorted
LC_ALL=C join -t $'\t' prod.sorted restored.sorted | awk -F'\t' '$2!=$3'

# 5. apagar a base temporária e a cópia local do dump
mysql -h 127.0.0.1 -P 3308 -u root -e "DROP DATABASE \`lapis_restore_test_YYYYMMDD_HHMM\`;"
rm -f lapis-daily-XXXX.sql.gz
```

**Juntar por chave, não por posição.** As duas bases podem ordenar `ORDER BY 1`
com collations diferentes, e um `paste` alinhado por linha produz então trinta e
quatro «diferenças» que não existem — foi exatamente o que aconteceu à primeira
tentativa. `join` na chave, ou não se está a comparar nada.

Comparar, no mínimo: nº de tabelas, colunas, foreign keys e índices; `migrations`;
e as contagens de `users`, `organizations`, `classes`, `students`,
`enrollments`, `instruments`, `classifications`, `reports`, `evidence_records`,
`interventions`. **Nunca extrair nomes, emails ou números de aluno** — o
objetivo é estrutura e contagens. Para as colunas cifradas basta confirmar que
o comprimento e o prefixo do ciphertext se mantêm; não é preciso decifrar nada.

### Backup antes de deploy

O **diário é a rede principal**. Um dump pré-deploy adicional é obrigatório
apenas quando o deploy traz **migrations que alteram ou apagam dados
existentes**, ou um comando de correção de dados. Um deploy só de frontend,
copy ou documentação **não** precisa de dump — obrigar a um só cria lixo e
ninguém o leva a sério ao fim de duas semanas.

```bash
bash scripts/backup-database.sh   # à mão, antes de um deploy com migrations de risco
```

### Risco residual: não há cópia fora deste servidor

Os backups vivem no mesmo disco da base de dados. Protegem contra erro humano,
migration má e corrupção lógica — **não** contra perda do servidor. Uma cópia
offsite é a próxima melhoria operacional (P1); não foi criada aqui para não
introduzir um serviço externo sem decisão.

`backup.log` cresce ~1 linha por dia e não precisa de rotação tão cedo.

## Passos (SSH como Site User `lapis`)

```bash
cd ~/htdocs/lapis.criativatek.com

# 1. Código (usar um deploy key adicionado ao repo GitHub, ou HTTPS + token)
git clone git@github.com:criativatek/lapis.git .

# 2. Dependências PHP (produção)
composer install --no-dev --optimize-autoloader

# 3. Assets (se houver Node no servidor)
npm ci && npm run build

# 4. Ambiente
cp .env.example .env
#   editar .env: APP_ENV=production · APP_DEBUG=false
#   APP_URL=https://lapis.criativatek.com · APP_KEY (passo 5)
#   DB_* (base criada no CloudPanel) · locale/timezone já vêm certos
php artisan key:generate

# 5. Base de dados
php artisan migrate --force
php artisan db:seed --class=ReferenceDataSeeder --force
#   ReferenceDataSeeder = EntitlementsSeeder + SystemScalesSeeder + InstrumentTypesSeeder.
#   Os três são dados de referência obrigatórios (ver DatabaseSeeder::run(), que
#   os corre juntos por este exato motivo) — sem eles ninguém tem acesso a
#   módulos, não há escalas para os perfis de avaliação, e o dropdown "Tipo" ao
#   criar um instrumento fica vazio. Correr só o EntitlementsSeeder (como este
#   documento dizia antes de 2026-07-31) deixa os outros dois por seedar sem
#   qualquer aviso — foi exatamente o que aconteceu em produção.
#   NÃO correr DemoDataSeeder em produção (dados fictícios)

# 6. Caches + storage
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan storage:link

# 7. Permissões de escrita
chmod -R ug+rw storage bootstrap/cache
```

## Cloudflare / TLS

- Cloudflare já está à frente do domínio. Emitir certificado no CloudPanel
  (Let's Encrypt) **ou** usar um Origin Certificate do Cloudflare; SSL mode do
  Cloudflare em **Full (strict)**.
- Fortify (2FA/passkeys) exige HTTPS e `APP_URL` correto — confirmar após o deploy.
- WebAuthn/passkeys dependem do domínio (RP ID) — testar login + registo de passkey
  em produção.

## SSR (render no servidor) — instalado em produção desde 0.99.10

Sem SSR, `/` respondia 13 KB sem um único `<h1>`: o conteúdo só existia depois
de o browser correr o JavaScript. O Google renderiza-o tarde e com orçamento;
o Bing, o LinkedIn, o WhatsApp e os bots de LLM não o renderizam de todo. Com
SSR, as mesmas páginas respondem 45–185 KB com o texto todo.

**Se o SSR parar, o site não parte** — o Inertia volta a render no cliente,
a página abre na mesma e só quem indexa perde. Foi testado assim: com o
serviço parado, `/planos` responde 200 com 13,8 KB.

### O que está instalado no servidor

| Peça | Onde |
|---|---|
| Node 22 (nvm, conta `lapis`) | `/home/lapis/.nvm/versions/node/v22.23.2/bin/node` |
| Bundle | `bootstrap/ssr/ssr.js` (viaja no pacote; `ssr.noExternal` no `vite.config.ts` mete as dependências lá dentro, por isso **não** é preciso `node_modules` no servidor) |
| Serviço | `/etc/systemd/system/lapis-ssr.service`, `Restart=always`, `enabled` |
| Registo | `/home/lapis/logs/ssr.log` |
| Porta | 13714, só localhost — o ufw tem `deny 13714/tcp` explícito |
| Interruptor | `INERTIA_SSR_ENABLED` no `.env` |

O Node do sistema continua a ser o 12 e não foi tocado; o 22 vive na conta
`lapis` e é usado só por este serviço.

### Em cada deploy, reiniciar o serviço

O Node tem o bundle **em memória**. Sem reinício, continua a servir o da
release anterior — e nada se queixa. A seguir ao `php artisan up`:

```bash
ssh lapis-prod 'sudo systemctl restart lapis-ssr'
```

### Confirmar

```bash
# 1 = está a renderizar; 0 = está em baixo (a página continua a abrir)
curl -s https://lapispro.com/ | grep -c "<h1"

ssh lapis-prod 'systemctl is-active lapis-ssr; tail -3 /home/lapis/logs/ssr.log'
```

### Desligar (se alguma vez for preciso)

```bash
ssh lapis-prod 'cd /home/lapis/htdocs/lapis.criativatek.com &&
  sudo -u lapis-deploy sed -i "s/^INERTIA_SSR_ENABLED=true/INERTIA_SSR_ENABLED=false/" .env &&
  sudo -u lapis-deploy php artisan config:cache &&
  sudo systemctl stop lapis-ssr'
```

### Duas armadilhas que custaram uma release cada

1. **O bundle não viajava.** A 0.93.0 acrescentou `bootstrap/ssr` à constante
   `GENERATED` do `BuildPackageCommand` — que não é lida por nada. A lista do
   pacote é `git ls-files` + carimbo + `public/build`. Corrigido na 0.99.9 com
   `ssrBundle()` e um teste que o afirma.
2. **O bundle procurava `node_modules`.** O Vite externaliza as dependências
   em SSR por omissão; em produção não há árvore de `node_modules`. Corrigido
   na 0.99.10 com `ssr.noExternal`.

### Testes

Os testes PHP correm com `INERTIA_SSR_ENABLED=false` (`phpunit.xml`). Com o
SSR ligado, o `<title>` servido é o do `<Head>` do Vue e não o do blade — é
por isso que as páginas de marketing recebem `seoTitle` do servidor
(`PublicPages`) e o `<Head>` o usa: os dois dizem a mesma coisa.

## Nota — build de assets sem Node no servidor

Se não houver Node no servidor, correr localmente antes de enviar:

```powershell
npm ci
npm run build   # gera public/build
```

E enviar `public/build/` (e `public/hot` ausente) para o servidor via SFTP/rsync.
Manter `APP_ENV=production` para o Vite servir os assets compilados, não o dev server.

## Checklist pós-deploy

- [ ] `https://lapis.criativatek.com` mostra o Lapispro (não o «Hello World»).
- [ ] Registo/login funcionam; 2FA e passkey testados em HTTPS.
- [ ] Os três seeders de `ReferenceDataSeeder` correram: `EntitlementsSeeder`
      (sem ele ninguém tem acesso a módulos), `SystemScalesSeeder` (sem ele não
      há escalas para os perfis de avaliação), `InstrumentTypesSeeder` (sem
      ele o dropdown "Tipo" ao criar um instrumento fica vazio).
- [ ] `APP_DEBUG=false`; sem stack traces expostas.
- [ ] **A extração escreveu mesmo**: checksums iguais entre local e servidor
      (`config/app.php`, `composer.lock`, `public/build/manifest.json`) — ver
      armadilha 8. Confirmar a versão sozinha não chega.
- [ ] **Assets novos servidos**: o `app-*.js` referido no HTML devolve 200 e o
      tamanho bate certo com o do `npm run build`. Se o nginx servir o bundle
      antigo, a página carrega mas o comportamento é o da versão anterior.
- [ ] **Permissões**: `storage`, `bootstrap/cache` e `storage/app/private/*` a
      `770` com grupo `lapis`; código a `750`/`640` do `lapis-deploy`. Nenhum `777`.
- [ ] Nada sensível servido pela web: `/.env`, `/composer.json`,
      `/storage/logs/laravel.log` e `/.git/config` devolvem 403/404.
- [ ] `storage/logs/laravel.log` sem entradas novas de `ERROR`, `SQLSTATE`,
      `Permission denied` ou `Vite manifest` depois do deploy.
- [ ] **Backup diário da BD a correr**: `tail -3 /home/lapis/backups/backup.log`
      mostra um `OK` do próprio dia, e `crontab -l` mostra o bloco
      `>>> LAPIS backup >>>` **uma só vez** — ver «Backups da base de dados».
- [ ] **Cron do scheduler instalado e a correr**: `crontab -l` mostra a entrada
      **uma só vez**, `php artisan schedule:list` mostra as cinco tarefas, e o
      `mtime` de `storage/logs/scheduler.log` avança sozinho. Sem isto as
      limpezas de ficheiros temporários com dados pessoais nunca correm — ver
      «Laravel Scheduler (cron)» acima.
- [ ] **`db:seed --class=ReferenceDataSeeder --force` correu neste deploy**
      (armadilha 9). Uma capability nova só existe depois disto; sem ela, a
      funcionalidade fica invisível mesmo para quem tem plano para a usar.
- [ ] **`lapis:release-check --expect-version=… --expect-commit=…` passou** (passo 6).
      Enquanto não passar, não se sabe o que está em produção — sabe-se o que se
      quis enviar, que não é a mesma coisa.
