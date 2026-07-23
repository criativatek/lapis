# Deployment — lapis.criativatek.com

Alvo de produção identificado: **VPS Contabo (`161.97.80.63`) com CloudPanel**,
site `lapis.criativatek.com` atrás de **Cloudflare**. Serve neste momento a página
default do CloudPanel («Hello World :-)»). SSH aberto (porta 22), painel na 8443.

> **Segredos nunca no repositório.** As credenciais do painel/SSH ficam num gestor
> de palavras-passe; a configuração de produção vive no `.env` **no servidor**, não
> no git. Este documento descreve o processo — sem passwords.

## Estado / bloqueador

O deploy ainda não foi executado. O acesso SSH com o utilizador `lapis` e a
palavra-passe fornecida foi **recusado** (`Permission denied (publickey,password)`).
Provável causa: as credenciais dadas são do **painel CloudPanel** (:8443), e o
**Site User** tem uma palavra-passe SSH/SFTP própria (ou só aceita chave). Para
desbloquear, uma de:

1. No CloudPanel → **Sites → lapis.criativatek.com → SSH/SFTP**: obter/redefinir a
   palavra-passe do Site User, **ou**
2. Adicionar uma **chave SSH pública** ao Site User (recomendado — sem passwords).

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
php artisan db:seed --class=EntitlementsSeeder --force   # reference data, obrigatório
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
- [ ] `EntitlementsSeeder` correu (sem ele ninguém tem acesso a módulos).
- [ ] `APP_DEBUG=false`; sem stack traces expostas.
- [ ] Backup da base de dados agendado (CloudPanel → Backups).
