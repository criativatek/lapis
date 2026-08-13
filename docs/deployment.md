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
abaixo) corridos, Cloudflare + HTTPS ativos. Backoffice `/admin` no ar. O acesso
SSH faz-se pelo **SSH User `deploy`** (criado em CloudPanel → Sites → SSH/FTP),
não pelo Site User `lapis` (esse recusa password/chave pelo painel). De Windows
usa-se **plink** (PuTTY) com o hostkey pinado — ver «Atualizações». Em alternativa,
o OpenSSH do Git Bash com uma entrada em `~/.ssh/config` (`Host lapis-prod` →
`HostName 161.97.80.63`, `User deploy`, `IdentityFile`, `IdentitiesOnly yes`)
funciona igualmente e permite canalizar o tar por `stdin` — **desde que a chave
esteja mesmo autorizada em disco: ver armadilha 6**.

**Versão em produção: 0.28.0** desde 2026-08-13. Esse deploy apanhou três
versões de uma vez (0.26.0 Registos, 0.27.0 ligações de autoavaliação, 0.28.0
Avaliações) porque a produção tinha ficado na 0.25.0 — **confirmar sempre a
versão real no servidor (`grep version config/app.php`) antes de assumir de
onde parte o deploy**, já que o servidor não tem `.git` e nada indica de fora
qual o commit que lá está.

## Atualizações (redeploy de código) — o fluxo que funciona

Não voltar a fazer `git clone`. O `.env`/`APP_KEY` vivem **só no servidor** —
reescrevê-los invalida sessões, 2FA e a password SMTP cifrada. Enviar só código:

```bash
# 1. Empacotar local (Git Bash). Excluir SEMPRE bootstrap/cache, .env e
#    storage/app — este último é conteúdo carregado por utilizadores
#    (fotos de alunos, ficheiros temporários de importação), específico de
#    CADA ambiente. Nunca deve viajar num pacote de código: já aconteceu
#    (2026-07-31) o storage/app local ser enviado para produção e poluir o
#    armazenamento real com ficheiros de teste locais.
tar --force-local -czf update.tgz \
  --exclude=.git --exclude=node_modules --exclude=vendor \
  --exclude=.env --exclude=.env.production \
  --exclude='storage/logs/*.log' --exclude='storage/framework/cache/data/*' \
  --exclude=storage/app \
  --exclude=bootstrap/cache --exclude=.claude -C d:/HERD/LAPIS .

# 2. Extrair + reconstruir no servidor via plink (hostkey pinado, sem prompt):
plink -ssh -hostkey SHA256:5a6uWUkxyqr3DhZCwveJEviWXDAOVQ72ndMgqDYpjHs -batch \
  -pw '<pw-do-deploy>' deploy@161.97.80.63 \
  'cd /home/lapis/htdocs/lapis.criativatek.com &&
   tar xzf - --no-overwrite-dir --no-same-permissions --no-same-owner || true &&
   test -f artisan &&
   composer install --no-dev --optimize-autoloader --no-interaction &&
   php artisan migrate --force &&
   php artisan config:cache && php artisan route:cache && php artisan view:cache' \
  < update.tgz
```

**Armadilhas que já partiram o site (ou o armazenamento):**

1. **Nunca enviar `bootstrap/cache/`.** O cache local lista providers de dev
   (Laravel\Pail) que não existem em produção (`--no-dev`) → `Class ... not found`
   no `config:cache`. Excluir do tar (acima). Se acontecer: apagar
   `bootstrap/cache/{packages,services,config}.php` no servidor + `composer install`.
2. **Correr sempre `composer install`** depois de extrair — regenera o cache de
   providers para o conjunto de produção. Saltar isto foi o que expôs a armadilha 1.
3. **Nunca enviar `storage/app/`.** Contém conteúdo carregado (fotos de alunos,
   pastas temporárias de importação) específico de cada ambiente — nunca faz
   parte do código. Em 2026-07-31 o `storage/app` local (com ficheiros de teste
   de dias anteriores) foi enviado por engano para produção, poluindo
   `storage/app/private/student-photos` e `roster-imports` com dezenas de
   ficheiros irrelevantes. Excluir sempre do tar (acima); se acontecer, os
   ficheiros a remover no servidor identificam-se pela data de modificação
   (`stat -c '%y %n' storage/app/private/*/*`) — qualquer coisa mais antiga
   do que o próprio deploy é suspeita.
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
   **Solução:** criar no CloudPanel um SSH user com nome **único** para este
   site (ex.: `lapis-deploy`, com a sua própria home), em vez de reutilizar o
   `deploy` partilhado, e registar a chave aí. Enquanto isso não for feito, o
   único fluxo que resulta é a janela curta: acrescentar a chave à mão como
   `root` e correr o deploy **imediatamente** a seguir, num único comando.
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

O `tar x` usa `--no-same-owner/permissions` porque a pasta é do user `lapis`, não
do `deploy`; o `|| true` engole o aviso de permissões em `.`.

## Pré-requisitos no servidor (confirmar no CloudPanel)

- **PHP 8.4** selecionado para o site (CloudPanel → Site → Settings → PHP Version).
- **MySQL**: criar base de dados + utilizador (CloudPanel → Databases). Guardar as
  credenciais para o `.env`.
- **Node** (para `npm run build`). Se o servidor não tiver Node, **buildar os assets
  localmente** e enviar `public/build` — ver nota no fim.
- **Document root** do site = `.../htdocs/lapis.criativatek.com/public` (Laravel serve
  a partir de `public/`, não da raiz).

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
- [ ] Backup da base de dados agendado (CloudPanel → Backups).
