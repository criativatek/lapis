# Deployment — lapis.criativatek.com

Alvo de produção identificado: **VPS Contabo (`161.97.80.63`) com CloudPanel**,
site `lapis.criativatek.com` atrás de **Cloudflare**. Serve neste momento a página
default do CloudPanel («Hello World :-)»). SSH aberto (porta 22), painel na 8443.

> **Segredos nunca no repositório.** As credenciais do painel/SSH ficam num gestor
> de palavras-passe; a configuração de produção vive no `.env` **no servidor**, não
> no git. Este documento descreve o processo — sem passwords.

## Estado

**Em produção** desde 2026-07-27: `https://lapis.criativatek.com` serve o LÁPIS,
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
sozinho, ou o `php artisan about`, que traz a mesma informação na secção LÁPIS.

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

Instalada no **crontab do `lapis-deploy`** (`crontab -e` como esse utilizador —
é quem é dono do código e pertence ao grupo `lapis`, pelo que consegue apagar
o que o php-fpm escreveu em `storage/app/private/*`, a `770`):

```cron
# LAPIS scheduler — corre o Laravel Scheduler ao minuto. Ver docs/deployment.md.
# Nao duplicar: as tarefas vivem em routes/console.php, nao aqui.
* * * * * umask 002; cd /home/lapis/htdocs/lapis.criativatek.com && /usr/bin/php artisan schedule:run >> storage/logs/scheduler.log 2>&1
```

- **`/usr/bin/php`** (→ `php8.4`), caminho absoluto: o cron não tem o `PATH` de
  uma shell de login.
- **`umask 002`**: sem ele, um ficheiro criado pelo cron sai `644` e o php-fpm
  (utilizador `lapis`) deixa de lhe conseguir escrever. Com ele sai `664` e
  ambos os utilizadores partilham os ficheiros pelo grupo `lapis`.
- **Persistente por natureza** — um crontab de utilizador é lido do
  `/var/spool/cron/crontabs` pelo `cron`, que arranca no boot. Não precisa de
  nada em `systemd` nem em `supervisor`.

### Reinstalar sem duplicar

Correr isto três vezes deixa **três linhas**, não nove — preserva qualquer outra
entrada do utilizador e reescreve só a nossa:

```bash
APP=/home/lapis/htdocs/lapis.criativatek.com
KEPT="$(crontab -l 2>/dev/null | grep -vE 'LAPIS scheduler|artisan schedule:run|routes/console\.php' || true)"
{ printf '%s\n' "$KEPT" | sed '/^$/d'
  echo "# LAPIS scheduler — corre o Laravel Scheduler ao minuto. Ver docs/deployment.md."
  echo "# Nao duplicar: as tarefas vivem em routes/console.php, nao aqui."
  echo "* * * * * umask 002; cd $APP && /usr/bin/php artisan schedule:run >> storage/logs/scheduler.log 2>&1"
} | crontab -
crontab -l | grep -c 'artisan schedule:run'   # tem de dizer 1
crontab -l | wc -l                            # tem de dizer 3
```

**O filtro tem de apanhar as TRÊS linhas do bloco, não só a do comando.** A
primeira versão filtrava por «LAPIS scheduler» e por «artisan schedule:run» — e
a segunda linha de comentário não correspondia a nenhum dos dois, pelo que
acumulava uma cópia por cada reinstalação. Inofensivo (é um comentário) e
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

## Nota — build de assets sem Node no servidor

Se não houver Node no servidor, correr localmente antes de enviar:

```powershell
npm ci
npm run build   # gera public/build
```

E enviar `public/build/` (e `public/hot` ausente) para o servidor via SFTP/rsync.
Manter `APP_ENV=production` para o Vite servir os assets compilados, não o dev server.

## Checklist pós-deploy

- [ ] `https://lapis.criativatek.com` mostra o LÁPIS (não o «Hello World»).
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
- [ ] Backup da base de dados agendado (CloudPanel → Backups).
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
