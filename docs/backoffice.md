# Backoffice de plataforma (`/admin`)

Área do **operador do SaaS** (criativatek), fora do scope de tenant. Serve para gerir
todas as organizações/professores e configurar o email do sistema. Um professor normal
recebe **403** — o acesso exige a flag `is_platform_admin`.

> Toda a ação de operador que toca numa conta fica na **trilha de auditoria da org-alvo**
> (`audit_events`, via `CurrentOrganization::runFor`). Nada aqui é silencioso.
>
> Uma ação que **não** toca numa conta — configurar a IA da plataforma — vai para a
> mesma tabela com `organization_id` a NULL, via `AuditLog::recordPlatform()`. Não
> pertence a nenhum tenant e não aparece na trilha de nenhum.

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

Depois: login normal em `/login`, e o **dropdown da conta** (canto inferior da
sidebar, no nome do utilizador) passa a mostrar **«Administração da plataforma»**,
que abre `/admin` — a rota canónica `admin.accounts.index`.

## Como se lá chega

O link é desenhado a partir de **um único booleano partilhado**,
`auth.is_platform_admin` (`HandleInertiaRequests::share`). É a única coisa que o
cliente sabe sobre o backoffice: não vai no payload nenhuma lista de contas,
nenhuma contagem, nada que o payload de um professor comum não pudesse também
transportar.

- **Só o operador o vê.** `is_platform_admin` é uma propriedade da PESSOA, não da
  organização. Não é `organization.is_owner` e não é o módulo `institution_admin`:
  um administrador institucional continua a ver «Administração Institucional» na
  sidebar — que administra o *tenant* dele — e nunca vê esta entrada.
- **Esconder o link não é o controlo de acesso.** `EnsurePlatformAdmin` continua a
  ser a autoridade; um `true` forjado no cliente compra um link para um 403.
- **Não é preciso terminar sessão.** A flag é lida da BD a cada pedido, e o prop é
  partilhado em cada resposta Inertia — assim que o `lapis:make-admin` corre, a
  navegação seguinte já mostra a entrada.

Dentro do backoffice, o `AdminLayout` tem a sua própria navegação (Contas · Nova
conta · Comercial · Email (SMTP) · Inteligência Artificial) e **«Voltar ao
Lapispro»**, que devolve o operador ao `/dashboard` da aplicação normal.

## O que se faz lá

| Página | Ações |
|---|---|
| **Contas** (`/admin`) | Lista **todas** as organizações (cross-org), com dono, plano, estado da subscrição e verificação. Pesquisa por nome/email, paginada. |
| **Detalhe da conta** | Verificar email do dono · mudar plano (Base/Pro/Institucional) · suspender/reativar subscrição · conceder/revogar admin · **impersonar**. |
| **Nova conta** (`/admin/accounts/create`) | Provisiona professor+organização+plano de uma vez. Email já verificado (contas provisionadas saltam a verificação). Password opcional — em branco gera uma temporária. |
| **Comercial** (`/admin/commercial`) | Contas, subscrições, condição comercial e **receita real** — ver abaixo. |
| **Email (SMTP)** (`/admin/settings`) | Configura o email do sistema **e o endereço de contacto público** — ver abaixo. |
| **Inteligência Artificial** (`/admin/ai`) | Liga/desliga a IA, escolhe fornecedor e modelo, define timeout, teto de tokens de resposta, rate limits e quotas, guarda/substitui a credencial e testa a ligação — ver abaixo. |

**Mudar plano** cria uma **nova subscrição** com `starts_at` mais recente (a antiga fica no
histórico) e faz `flush()` aos entitlements. Duas subscrições no mesmo segundo desempatam
por `id` — a mais recente ganha.

## Comercial (`/admin/commercial`)

> **Plano ≠ condição comercial ≠ pagamento.** É a regra que esta área existe para tornar
> difícil de quebrar.

**Três coisas diferentes, em três sítios diferentes:**

| Conceito | Onde vive | O que responde |
|---|---|---|
| **Plano** | `organization_subscriptions.plan_id` | O que a conta **pode usar**. |
| **Condição comercial** | `organization_subscriptions.commercial_condition` | Em que **termos** lá chegou. |
| **Pagamento** | `subscription_payments` | Dinheiro que **entrou mesmo**. |

**«Membro Fundador» não é um plano.** É `plan = pro` + `commercial_condition = founder`, e
um Fundador tem direito exactamente aos mesmos módulos que um Pro standard.
`App\Support\Entitlements\Entitlements` **nunca lê** a coluna — marcar uma conta como
Fundadora não lhe dá nada.

**`trial` não é armazenado.** Deriva-se de `status = trial`, que
`ChangeOrganizationPlan::supersede()` já preserva para sempre — ver
`App\Support\Commercial\SubscriptionCondition::keyOf()`, que é a precedência canónica
(status Trial → coluna → NULL) e o único sítio onde ela está escrita.

**NULL é «Origem não registada», não «standard».** Nenhuma subscrição existente foi
preenchida retroactivamente: uma conta Pro pode ter chegado ali por concessão, por um
trial que converteu ou por uma condição de lançamento, e a base de dados nunca guardou a
evidência. O operador marca cada uma à mão. **A condição nunca é inferida pelo valor
pago.**

### Como se calcula a receita

```
receita = SUM(subscription_payments.amount_cents) WHERE status = 'paid'
          agrupada por paid_at (a data em que o dinheiro chegou)
```

`pending`, `failed`, `cancelled` e `refunded` **não contam** —
`App\Models\PaymentStatus::countsAsRevenue()` é a única autoridade, e é um `match` sem
braço `default`, para que acrescentar um estado sem decidir se é receita seja um erro de
compilação e não um total silenciosamente errado.

**Nunca** `nº de contas Pro × 44,90`. Sem pagamentos registados, o valor correcto é **0 €**
— e o painel diz porquê.

### Registar e corrigir pagamentos

Não há gateway. Um pagamento entra por **registo manual** de algo que já foi recebido
(transferência, MB WAY). Registar um pagamento **não mexe no plano**.

**Um pagamento registado é imutável onde interessa.** `App\Models\SubscriptionPayment`
recusa, no `booted()`, qualquer update que toque no valor, na moeda, na `paid_at`, na
organização, no período, na condição ou em quem o registou. Só o grupo do estado se move:

| Correcção | Estado | Quando |
|---|---|---|
| **Reembolso** | `refunded` | O dinheiro voltou ao cliente. Só a partir de `paid`. **Apenas total** — o esquema tem um valor só, e um reembolso parcial não seria representável com honestidade. |
| **Anulação** | `cancelled` | O registo estava errado (lançado duas vezes, conta errada, valor errado). |

Ambas exigem **motivo** e ficam com **autoria e data**. Corrigir um valor mal lançado é
anular com motivo e registar o certo — duas linhas e uma trilha, nunca uma linha que mudou
de sentido. Um pagamento já corrigido não pode ser corrigido outra vez.

É isto que torna «o preço histórico é preservado» uma propriedade do esquema e não uma
promessa num documento: quando o preço do Pro mudar, não existe caminho de código que
consiga levar o novo valor a um pagamento antigo.

### Vouchers

**Existe um motor de vouchers** (`vouchers` / `voucher_redemptions`), com três famílias
comerciais V1: **preço fixo**, **desconto percentual** e **gratuito até uma data**. Um
voucher move o preço e o termo do contrato — as quatro colunas de prova comercial — e
**nunca** módulos nem versões de plano; `Entitlements` não lê nenhuma destas tabelas.

- **Emissão e desativação** em `Admin > Comercial > Vouchers`. Um voucher emitido é
  imutável: errou-se, desativa-se e emite-se outro, com autoria no rasto.
- **Resgate** só pelo produto: os com preço no checkout (reserva com a janela da
  transferência, confirmada quando o dinheiro entra; caducada, é apagada com rasto e a
  capacidade volta), o `free_until` na página do plano (nasce confirmado). Uma
  organização resgata cada código **uma vez**; a capacidade conta-se das linhas vivas
  sob lock, sem contador desnormalizado.
- **Fundador e voucher não acumulam**: ganha o preço mais baixo, no empate ganha a
  oferta normal (o código fica por usar), e um contrato de voucher nunca toma um lugar.
- **O texto legado continua texto.** `subscription_payments.voucher_code` escrito à mão
  num pagamento manual não é validado nem convertido em resgate — é testemunho, e a
  ficha assinala-o como tal. Nenhum histórico foi inventado.

### Institucional

Sem adesão self-service e **sem preço automático**. Aparece como plano na listagem; não
gera receita nenhuma até alguém registar um pagamento real.

### Privacidade (§19)

A área comercial **não transporta um único dado pedagógico**. O entitlement em vigor vai
como **contagem**, não como lista de chaves, para que `students`/`classes`/`reports` não
apareçam sequer como nomes num payload comercial. Os únicos dados pessoais são o nome e o
email do titular — o mínimo para gerir uma subscrição.
`CommercialPrivacyAndExportTest` guarda isto.

### Exportação

CSV da listagem, **respeitando os filtros no ecrã**, só para superadmin, com BOM UTF-8
(senão o Excel abre «Condição» como mojibake) e sem uma única coluna pedagógica.

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

### Endereço de contacto público

No mesmo ecrã, e **distinto do remetente do sistema**: `contact_email` é o endereço para
onde uma instituição escreve, e é o destino do botão **«Falar connosco»** do plano
Institucional na página pública.

- Enquanto estiver **vazio**, esse botão **não é apresentado** — o cartão fica com o preço
  e o texto complementar, e nada aponta para um endereço que ninguém lê. Nunca cai para o
  `mail_from_address` (esse é o no-reply do sistema).
- Validado como email no servidor: um erro de escrita não chega a produzir um `mailto:`
  partido para todos os visitantes.
- É o **primeiro passo antes de anunciar a página pública** — ver
  `HomeController` e `resources/js/components/landing/LandingPricing.vue`.

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

## Inteligência Artificial (`/admin/ai`)

O motor que atende as funcionalidades assistidas por IA. Detalhe e porquês em
[ADR-0007](adr/0007-ai-core-one-gateway-one-policy.md); o que uma funcionalidade
nova consome está em [ai-core-contract.md](ai-core-contract.md).

**Estado inicial de uma instalação nova:** IA inativa, credencial por configurar.
Toda a aplicação funciona nesse estado — é o estado pretendido, não uma pendência.

**O que se configura:** interruptor geral · fornecedor (Gemini · compatível com
`/chat/completions` · simulado, fora de produção) · modelo · timeout · teto de
tokens de resposta · pedidos por minuto (utilizador e organização) · quotas por
capability (utilizador/dia, organização/mês).

**O que está guardado sobrepõe-se ao `.env`; o que não está cai para lá.** Mesmo
arranjo do SMTP acima. Todas as colunas são nullable e null significa «não decidido
aqui» — a exceção é o interruptor geral, em que `false` bate um `.env` configurado,
porque «desligar» tem de significar desligado.

### A credencial

- Cifrada na base de dados (cast `encrypted`), escrita por um único método, lida por
  um único método (o `AppServiceProvider`, ao arranque).
- **Nunca volta ao ecrã.** Não há rota que a devolva. O painel mostra
  «configurada», a data, e os **últimos 4 caracteres** quando a chave tem 20 ou
  mais. A ação chama-se **«Substituir credencial»**, não «Mostrar chave».
- Não aparece em logs, exceções, payloads Inertia nem linhas de auditoria.
- **Em produção prefira um gestor de segredos externo**, com `LAPIS_AI_KEY`
  injetada no deploy e este campo vazio. Uma coluna cifrada só é tão forte quanto a
  `APP_KEY` que a decifra, e a `APP_KEY` vive no mesmo ficheiro, na mesma máquina.

### «Testar ligação»

Faz um pedido **real** ao fornecedor com as definições em vigor. Custa tokens, fica
medido em `ai_usage_events` — sem organização, portanto sem consumir a quota de
escola nenhuma — e é o único ecrã do produto que diz **qual** foi a falha
(«a credencial foi rejeitada», «o fornecedor não respondeu a tempo»). Um professor
vê sempre a mensagem genérica; um operador a depurar uma chave precisa da
específica.

### Auditoria

Ao contrário do resto desta página, uma alteração à configuração de IA **não
pertence a nenhum tenant** — afeta todas as organizações. Vai para `audit_events`
com `organization_id` a NULL, via `AuditLog::recordPlatform()`, e é invisível na
trilha de qualquer organização.

Eventos: `ai.settings_updated` (com os campos alterados e os valores novos),
`ai.credential_created`, `ai.credential_replaced`, `ai.credential_removed`,
`ai.connection_tested`. **Nenhum deles guarda a credencial** — nem o valor, nem o
comprimento, nem um hash.

### Ainda não há credencial Gemini

E, mais importante, **nenhum plano concede as capabilities novas**
(`help_assistant`, `ai_pedagogical_analysis`): a composição comercial é uma decisão
por tomar. Configurar o motor aqui não faz aparecer nada a ninguém enquanto isso não
mudar — ver [ai-core-contract.md §10](ai-core-contract.md#10-decisões-pendentes).
Para um piloto, o caminho suportado é um override por organização.

## Verificar end-to-end

1. `lapis:make-admin <email>` → login → dropdown da conta → «Administração da
   plataforma» → `/admin` → «Voltar ao Lapispro» → `/dashboard`. Com um professor
   normal, a entrada não existe e `/admin` escrito à mão dá 403.
2. Configurar SMTP (587/TLS acima) → «Enviar email de teste» para um inbox real → toast verde + email chega.
3. Registar um professor em `/register` com email real → recebe o email de verificação.
4. `/admin/ai` → fornecedor «Simulado», modelo qualquer, IA ativa → «Testar ligação»
   → toast verde. Recarregar: a credencial continua a dizer só «configurada».

## Suporte

`Admin > Suporte` fecha o ciclo todo: fila ordenada pelo trabalho (abertos,
em curso, à espera, resolvidos), filtros por estado, assunto, classificação
técnica e suspensão, pesquisa por **referência ou email** — nunca pelo corpo
do pedido —, ficha com o histórico completo, resposta, mudança de estado,
classificação, suspensão/retoma da eliminação e reenvio de notificações.

- **Quem vê o quê.** Um professor vê apenas os pedidos que ele próprio abriu.
  Um colega da mesma organização não vê; o administrador institucional também
  não. `organization_id` é contexto para quem responde, nunca autorização. Um
  visitante cria e mais nada: não há portal, e `SUP-XXXXXX` não é credencial.
- **`technical_code` é do operador.** Nasce NULL e nada o infere — nem do
  texto, nem da categoria escolhida, nem da rota. NULL é «ninguém olhou»;
  `unclassified` é «alguém olhou e não encaixa».
- **Notificações por entregar aparecem no topo, fora dos filtros.** Não há
  worker: um aviso que falhou só volta a sair se uma pessoa carregar em
  «Reenviar», e para isso tem de o ver sem o procurar. O reenvio reconstrói o
  email a partir do pedido — a tabela de entregas não guarda conteúdo nenhum,
  só tipo, destinatário por papel, tentativas e um código de falha fechado.
- **A nota da suspensão vive só nesta ficha.** Não vai para auditoria nem para
  email, e é o único campo do hold que a anonimização apaga.

Ver [ADR-0011](adr/0011-support-centre.md) e [data-lifecycle.md](data-lifecycle.md).
