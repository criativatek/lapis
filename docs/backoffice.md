# Backoffice de plataforma (`/admin`)

Área do **operador do SaaS** (criativatek), fora do scope de tenant. Serve para gerir
todas as organizações/professores e configurar o email do sistema. Um professor normal
recebe **403** — o acesso exige a flag `is_platform_admin`.

> Toda a ação de operador que toca numa conta fica na **trilha de auditoria da org-alvo**
> (`audit_events`, via `CurrentOrganization::runFor`). Nada aqui é silencioso.

## Tornar-se admin de plataforma

Não há UI para o primeiro admin (ovo-e-galinha) — faz-se por comando, no servidor:

```bash
php artisan lapis:make-admin pedro@exemplo.com      # concede
php artisan lapis:make-admin pedro@exemplo.com --revoke   # revoga
```

Em produção corre-se via plink (ver [deployment.md](deployment.md)):

```bash
plink -ssh -hostkey SHA256:5a6uWUkxyqr3DhZCwveJEviWXDAOVQ72ndMgqDYpjHs -batch \
  -pw '<pw-deploy>' deploy@161.97.80.63 \
  'cd /home/lapis/htdocs/lapis.criativatek.com && php artisan lapis:make-admin <email>'
```

`is_platform_admin` **nunca** é mass-assignable (não está em `#[Fillable]`) — só se muda
por este comando ou pelo botão «conceder admin» dentro do backoffice. A flag é uma coluna
booleana em `users`, não um papel/tabela de permissões (YAGNI: há um só tipo de operador).

Depois: login normal em `/login` e o menu leva a `/admin`.

## O que se faz lá

| Página | Ações |
|---|---|
| **Contas** (`/admin`) | Lista **todas** as organizações (cross-org), com dono, plano, estado da subscrição e verificação. Pesquisa por nome/email, paginada. |
| **Detalhe da conta** | Verificar email do dono · mudar plano (Base/Pro/Institucional) · suspender/reativar subscrição · conceder/revogar admin · **impersonar**. |
| **Nova conta** (`/admin/accounts/create`) | Provisiona professor+organização+plano de uma vez. Email já verificado (contas provisionadas saltam a verificação). Password opcional — em branco gera uma temporária. |
| **Email (SMTP)** (`/admin/settings`) | Configura o email do sistema — ver abaixo. |

**Mudar plano** cria uma **nova subscrição** com `starts_at` mais recente (a antiga fica no
histórico) e faz `flush()` aos entitlements. Duas subscrições no mesmo segundo desempatam
por `id` — a mais recente ganha.

## Impersonar (suporte)

No detalhe de uma conta, «Impersonar» faz o operador ver a app **como aquele professor**,
para dar apoio. Enquanto dura, um **banner âmbar** persistente («A ver a app como X —
Terminar») fica no topo; «Terminar» devolve a sessão de operador. **Nunca impersona outro
admin.** Início e fim são **auditados** na org-alvo — é a ação mais sensível.

## Email do sistema (SMTP)

Usado para verificação de conta, reset de password e convites. Sem SMTP real, os registos
ficam presos em `/email/verify`.

- Guardado na tabela `platform_settings` (linha única), password **cifrada** (cast
  `encrypted`), e **sobrepõe o `.env`** em runtime no boot (`AppServiceProvider`). O
  mailer do sistema é **sempre `smtp`** — não há campo «Mailer» (era um footgun).
- A password é **write-only**: a página mostra «•••• definida», e deixar em branco mantém
  a guardada.
- Botão **«Enviar email de teste»** com destinatário à escolha («Enviar teste para») —
  reporta sucesso/erro num toast com a mensagem exata do servidor SMTP.

### Encriptação → porta

O formulário tem `Encriptação` (Nenhuma/TLS/SSL). O mapeamento para o Symfony Mailer:

| Encriptação | Porta típica | Scheme real |
|---|---|---|
| **SSL** (TLS implícito) | 465 | `smtps` |
| **TLS** (STARTTLS) | 587 | `smtp` (default, STARTTLS) |
| Nenhuma | 25/2525 | `smtp` |

Escolher `SSL` mas apontar a uma porta que só faz STARTTLS (ou vice-versa) → `Connection
refused`. O toast diz qual é o caso.

### criativatek.com (o mailserver de produção)

Sondado a partir do VPS (2026-07-27): `mail.criativatek.com` (→ 164.68.96.200, o MX do
domínio) **só escuta 587**. 465/25/2525 estão fechados. Config correta:

- **Host:** `mail.criativatek.com`
- **Porta:** **587**
- **Encriptação:** **TLS**
- **Utilizador:** `lapis@criativatek.com` (+ password do webmail)
- **Remetente:** `lapis@criativatek.com`

> O outbound 465 do VPS **não** está bloqueado (a 465 do Gmail abre) — é mesmo o host
> `mail.criativatek.com` que não corre SSL implícito. Por isso 587/TLS, não 465/SSL.

## Verificar end-to-end

1. `lapis:make-admin <email>` → login → `/admin`.
2. Configurar SMTP (587/TLS acima) → «Enviar email de teste» para um inbox real → toast verde + email chega.
3. Registar um professor em `/register` com email real → recebe o email de verificação.
