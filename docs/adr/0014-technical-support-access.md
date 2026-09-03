# ADR-0014 — Acesso técnico reforça a impersonação existente, não a substitui

- **Status:** Accepted — implementado nesta janela, ainda **não integrado em
  `main`** e **sem deploy**.
- **Date:** 2026-09-03
- **Não altera:** [ADR-0002](0002-container-resolved-tenancy.md). O gate
  continua inteiramente server-side e nenhuma rota passa a resolver a
  organização por outra via; a impersonação troca *quem está autenticado*,
  nunca o mecanismo que resolve o tenant.
- **Não altera:** [ADR-0008](0008-plan-versions.md). `is_support_technician`
  não é uma capability de plano, não entra em nenhuma `PlanVersion` e não
  distingue Base, Pro ou Institucional — a composição continua 13 / 28 / 34.

## Context

A auditoria feita antes de tocar em código encontrou um mecanismo de
impersonação já em produção (`AdminImpersonateController`), gated apenas por
`is_platform_admin`, e o Acordo de Tratamento de Dados público já a descrevia
em substância: *"a aplicação permite que um operador da plataforma aceda a uma
conta para prestar assistência"*. Construir um segundo mecanismo paralelo
teria contradito a própria promessa do DPA e duplicado uma peça já testada.

Mas o mecanismo existente tinha um problema real, não cosmético:
`AuditLog::record()` fazia `$causer ??= auth()->user()`. Os eventos de início
e fim da impersonação ficavam corretamente atribuídos ao admin, porque são
registados no instante exato em que `auth()->user()` ainda é ele. Qualquer
ação normal praticada **durante** a sessão — editar uma turma, criar um
relatório, o que for — ficava atribuída ao professor impersonado, porque por
essa altura `auth()->user()` já é ele. Um developer podia agir dentro da conta
de um professor e o registo de atividade dizia que foi o professor a agir.

Também não havia autorização distinta: qualquer `is_platform_admin` —
comercial, técnico, o que fosse — podia impersonar qualquer conta.

## Decision

### 1. Reforçar, não substituir

`AdminImpersonateController` continua a ser o único caminho para "tornar-se"
outro utilizador. `Auth::login($target)` continua a fazer a troca — e o
`SessionGuard::login()` do próprio Laravel já regenera o ID de sessão nesse
momento (`migrate(true)` dentro de `updateSession()`), o que já mitigava
fixação de sessão antes desta ADR existir; não foi preciso escrever nada para
isso.

### 2. `is_support_technician` é uma capability distinta, não um alias de `is_platform_admin`

Uma segunda coluna boolean em `users`, no mesmo estilo de `is_platform_admin`:
nunca mass-assignable, sem entrada em `#[Fillable]`. O gate de arranque da
impersonação (`EnsureSupportTechnician`, aplicado só à rota
`accounts.impersonate`) exige as duas — ser admin da plataforma continua
necessário para todo o backoffice, mas deixa de bastar sozinho para esta ação
específica.

### 3. Autorizado por defeito para quem já é interno, revogável sem perder o resto

Um administrador interno da HORIZONLEVEL fica autorizado ao acesso técnico
**por defeito** quando é promovido a `is_platform_admin` — via
`lapis:make-admin` ou via `toggleAdmin` no backoffice, os dois únicos
caminhos que concedem o flag original, ambos passam agora a conceder os dois
juntos. A migration faz o mesmo backfill para quem já era admin interno antes
desta ADR.

A permissão continua distinta e **pode ser retirada individualmente** sem
tocar em `is_platform_admin`: `lapis:grant-support-access {email} --revoke`
mexe só na segunda coluna. Um admin interno a quem essa permissão foi retirada
mantém o resto do backoffice — contas, comercial, IA, suporte — e perde apenas
a capacidade de iniciar uma sessão de acesso técnico.

Não existe UI para conceder `is_support_technician` isoladamente a alguém que
não seja já `is_platform_admin` — só o Artisan. Evita que um admin se
autoconceda a permissão por um caminho que a interface expõe.

### 4. Motivo com categoria fechada, nunca texto obrigatório

`StartSupportAccessRequest` exige `category` (um enum de sete valores:
assistência técnica, diagnóstico, manutenção, segurança, incidente,
verificação técnica, outro) e aceita `ticket_reference` e `note`, os dois
opcionais. Nenhum pedido de suporte é exigido — o acesso não depende de ter
existido um.

### 5. A correção do ator real, no único sítio que importa

`AuditLog::record()` passa a resolver o causer através de
`resolveCauser()`: quando existe `impersonator_id` em sessão, o causer é
sempre o developer, e se o utilizador autenticado no momento (o professor)
for diferente, essa distinção fica gravada em
`properties['acting_as_user_id']`. Fora de uma sessão de impersonação, o
comportamento é idêntico ao de antes desta ADR — a mudança só existe quando
`impersonator_id` está presente.

`recordPlatform()` e `recordPlatformWithoutCauser()` não foram tocados: são
atos do próprio backoffice, nunca alcançados através de uma sessão de
impersonação.

### 6. Um identificador de sessão junta início e fim

`support_access_id` é um ULID gerado no arranque, guardado em sessão e
incluído nas `properties` de `admin.impersonation_started` e
`admin.impersonation_stopped` — os dois eventos de uma mesma sessão de acesso
técnico passam a poder ser correlacionados sem depender do ID de sessão PHP,
que muda no `migrate()` do login.

### 7. Acesso completo, auditoria correta — não uma cisão leitura/escrita

Deliberadamente não se separou o acesso técnico em modos de leitura e
escrita. O modelo já em produção era de acesso total durante a impersonação,
e o próprio DPA já o descrevia assim; construir uma segunda dimensão de
permissões só para esta ação seria complexidade sem correspondência no resto
da aplicação. `RefusesDuringImpersonation` continua a impedir ações de
governação (sair, remover, transferir a organização) independentemente desta
ADR. O que muda é que qualquer escrita feita durante o acesso fica
corretamente atribuída a quem a fez.

## Consequences

- Os nomes dos eventos de auditoria (`admin.impersonation_started`,
  `admin.impersonation_stopped`) e a chave de sessão `impersonator_id`
  mantêm-se — continuidade do registo de atividade já em produção.
- Os três documentos legais públicos (Termos, Privacidade, Acordo de
  Tratamento de Dados) passam a descrever explicitamente pessoal
  especificamente autorizado, as finalidades enumeradas, e que o acesso não
  depende de pedido prévio. Versão `2026-09-03` nos três, sem reaceitação
  forçada: a Política volta a mostrar o aviso não bloqueante já existente
  (`User::shouldSeePrivacyNotice()`), os Termos e o Acordo seguem o padrão já
  em vigor de não forçar nova aceitação em alterações não materiais.
- Um developer sem 2FA/passkey próprios da conta impersonada nunca é
  desafiado por eles — comportamento herdado do mecanismo original, não
  introduzido por esta ADR, e fora do âmbito desta janela mudar.

## Alternatives considered

- **Construir um mecanismo novo e separado, dedicado a "acesso técnico".**
  Recusada: o DPA já descrevia o mecanismo existente, e um segundo caminho
  para "tornar-se" um utilizador é mais superfície de ataque e mais coisa
  para manter coerente entre si, não menos.
- **Ler `properties['acting_as_user_id']` a partir de cada chamador, em vez de
  corrigir `AuditLog::record()` uma vez.** Recusada: dezenas de sítios chamam
  `record()` sem passar `causer`; a correção central é a única que não
  depende de ninguém se lembrar dela ao escrever a próxima.
- **Separar leitura e escrita durante o acesso técnico.** Recusada por agora
  — ver secção 7 da Decision.
