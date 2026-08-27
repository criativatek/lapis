# Encerramento de conta e de organização — ciclo de vida recuperável

Fatia 5. Este documento descreve o **mecanismo** de encerramento voluntário e
recuperável de contas pessoais e de organizações institucionais. A **política**
de números (60/90 dias, e o resto da retenção técnica) está em
[docs/data-lifecycle.md](data-lifecycle.md); aqui está como o fluxo funciona.

## O que isto NÃO é

Três conceitos distintos, propositadamente nunca confundidos no código:

| | O quê | Quem decide | Reversível | Onde |
|---|---|---|---|---|
| **Desativação** | Corta o acesso de uma pessoa | Operador (backoffice) | Sim, imediatamente | `users.deactivated_at`, `EnsureUserIsActive` |
| **Encerramento** | Pedido voluntário de fecho, com janela de recuperação | A própria pessoa / o responsável da organização | Sim, dentro da janela | `closure_requested_at` / `scheduled_deletion_at` — este documento |
| **Eliminação definitiva** | Apagar mesmo | Excecional, administrativo | Não | `App\Actions\Users\DeleteUserAccount` |

A desativação (`users.deactivated_at`) e a eliminação definitiva
(`DeleteUserAccount`) já existiam antes desta fatia e **não foram
reaproveitadas** para implementar o encerramento — têm colunas, guardas e
fluxos próprios. Ver `app/Http/Middleware/EnsureUserIsActive.php` para a
desativação.

## O modelo: dois timestamps, sem estado próprio

```
users.closure_requested_at    | organizations.closure_requested_at
users.scheduled_deletion_at   | organizations.scheduled_deletion_at
```

Nenhuma tabela de estado nova, nenhuma enum de lifecycle. A leitura é direta:

| | `closure_requested_at` | `scheduled_deletion_at` |
|---|---|---|
| Ativa | `null` | `null` |
| Encerramento pedido | preenchido | preenchido |
| Recuperada/cancelada | `null` (limpo) | `null` (limpo) |
| Elegível para eliminação | preenchido | `<= now()` |

`scheduled_deletion_at` é calculado **uma vez**, no momento do pedido, como
`closure_requested_at + N dias`, lendo `RetentionPolicy` nesse instante — e
fica assim independentemente de a configuração mudar depois. Isto é
deliberado: um pedido já em curso mantém o prazo que foi prometido; um
operador que altere `config/retention.php` no futuro não move a meta a meio
do caminho de ninguém.

A recuperabilidade em si, porém, **não** é decidida por comparação direta a
`scheduled_deletion_at` — é sempre `App\Support\Retention\ClosureRetention`
(já existente, já testada antes desta fatia:
`tests/Unit/Retention/ClosureRetentionTest.php`) que decide, comparando
`closure_requested_at` com a política **atual**. Nas condições normais os
dois cálculos coincidem exatamente; `ClosureRetention` é a autoridade única.

Cancelar **limpa** os dois campos a `null` em vez de gravar um terceiro
estado "cancelado" — não há necessidade de o distinguir de "nunca foi
pedido", e o evento de auditoria (`account.closure_cancelled` /
`organization.closure_cancelled`) já guarda quando e por quem, com timestamp
próprio.

## Conta pessoal

**Pedir:** `POST /settings/account-closure` →
`App\Actions\Accounts\RequestPersonalAccountClosure`. Sempre sobre
`$request->user()` — nunca há um `{user}` na rota, por isso não existe
superfície para pedir o encerramento de outra pessoa por esta via.

**Guarda de ownership institucional (§29):** se a pessoa é `owner_id` de
alguma organização `institutional`, o pedido é recusado com uma mensagem
explícita — nunca se fica sem responsável. A pessoa tem de transferir a
responsabilidade primeiro (`TeamController::transferOwnership`, Fatia 4).

**Cancelar:** `DELETE /settings/account-closure` →
`App\Actions\Accounts\CancelPersonalAccountClosure`. Recusado fora da janela
de recuperação (`AccountClosureException::noLongerRecoverable`).

**UI:** `resources/js/components/AccountClosure.vue`, na página
`/settings/profile`, no lugar do antigo botão de eliminação imediata em
inglês (`DeleteUser.vue`, removido — ver "Sobre a eliminação imediata"
abaixo). Mostra o pedido/estado/contagem decrescente, nunca decide em JS se é
recuperável — só apresenta o que o backend manda.

## Organização institucional

**Pedir:** `POST /team/closure` → `App\Actions\Organizations\RequestOrganizationClosure`.
Owner-only (`OrganizationMembershipPolicy::requestClosure`, o mesmo padrão de
`remove`/`transferOwnership` da Fatia 4 — `manages()`: `type ===
institutional && $user->owns($organization)`). Um membro recebe 403; um
pedido cross-tenant também (o alvo é sempre a organização corrente resolvida
pelo tenant, nunca um parâmetro de rota).

**Efeito nos membros (§11):** nada é removido. Nenhuma membership, nenhum
`owner_id`, nenhuma turma, nenhum aluno, nenhuma avaliação. O único efeito é
bloquear escrita nova (ver "O que fica bloqueado" abaixo) — para **todos**,
incluindo o próprio owner. Um banner global (`ClosureBanners.vue`, ver
abaixo) avisa qualquer membro, mesmo sem acesso a `/team` (owner-only).

**Cancelar:** `DELETE /team/closure`, owner-only, mesma janela de
recuperação.

**UI:** página `institution/Index.vue`, na rota `institution.index`, com a
secção reutilizável `components/organization/ClosureCard.vue`. A página
«Equipa» mantém apenas a gestão de membros e convites.

## O que fica bloqueado durante a janela

`App\Http\Middleware\EnsureAccountIsOperational`, registado globalmente no
grupo `web` (depois de `ResolveOrganization`, antes de `HandleInertiaRequests`).

A regra é deliberadamente simples: **nunca bloqueia GET/HEAD** — ler continua
sempre possível, o que evita quebrar navegação ou recargas parciais do
Inertia de forma imprevisível. Para pedidos que mutam (POST/PUT/PATCH/DELETE),
se a conta ou a organização corrente estiver em encerramento, só uma lista
explícita de nomes de rota é permitida — tudo o resto recebe 403:

- `logout`
- `account.closure.request` / `account.closure.cancel`
- `organization.closure.request` / `organization.closure.cancel`
- `organizations.switch`
- `data-exports.store`
- `user-password.update`, `password.confirm.store`
- `two-factor.*`, `passkey.*` (prefixos — Fortify/Laravel Passkeys; ver §31,
  "não quebrar passkeys")

Uma lista de permissões, não de bloqueios: o conjunto de rotas seguras
durante o encerramento é pequeno e conhecido: o inverso — enumerar tudo o que
deve ficar bloqueado — cresceria a cada módulo pedagógico novo e falharia
silenciosamente no dia em que alguém esquecesse de o atualizar.

## Multi-organização (§28-§30)

O bloqueio da CONTA aplica-se a qualquer organização em que a pessoa esteja a
trabalhar nesse momento — pedir o encerramento da própria conta impede
atividade em qualquer lado até ser cancelado. O bloqueio de uma ORGANIZAÇÃO
aplica-se apenas a essa organização: mudar para a organização Pessoal, ou
para outra instituição de que a pessoa também seja membro
(`POST /organizations/switch`, sempre permitido), funciona normalmente. Ver
`tests/Feature/Organizations/OrganizationClosureTest.php::closing_one_institution_does_not_affect_the_owners_personal_organization_or_a_second_institution`.

## Exportação durante a janela

Sem bypass de autorização: a pessoa exporta exatamente o que já podia
exportar antes de pedir o encerramento (`DataExportPolicy` inalterada). A
janela apenas garante que `data-exports.store` continua na lista de
permissões do middleware acima.

## Banners e sinalização (§13-§14)

`resources/js/components/ClosureBanners.vue`, montado em `AppLayout.vue`
(o mesmo local do banner de impersonação, para o mesmo tipo de aviso
transversal), lê os props partilhados `accountClosure` /
`organizationClosure` (`HandleInertiaRequests::share()`). O backend decide
`days_remaining` e `recoverable`
(`App\Support\Retention\ClosureStatusPresenter`, construído sobre
`ClosureRetention` já existente); o cliente só apresenta.

## Auditoria

Quatro eventos novos, todos via `App\Services\Audit\AuditLog` (já existente):
`account.closure_requested`, `account.closure_cancelled`,
`organization.closure_requested`, `organization.closure_cancelled`.
Deliberadamente sem eventos `*.deletion_eligible` — seriam derivados do
próprio `scheduled_deletion_at` e re-gravá-los a cada execução do scheduler
duplicaria o trail sem acrescentar informação nova.

## Elegibilidade e pré-visualização (§8, §18-§20) — nunca elimina

`App\Support\Retention\DeletionEligibility` — cross-tenant por natureza
(`withoutGlobalScope('organization')`, o mesmo padrão já usado nos
relatórios administrativos existentes) — lista, só de leitura:

- contas pessoais e organizações institucionais em encerramento, com dias
  restantes e se já são elegíveis para eliminação;
- exportações expiradas ainda por limpar (a mesma verificação que
  `data-exports:prune` já aplica);
- anos letivos fora da janela de retenção pedagógica, por organização
  (`AcademicYearRetentionClassifier`, já existente);
- contagem de eventos de auditoria fora da janela de segurança (3 anos) —
  só a contagem, nunca a listagem, e nunca purgados.

`php artisan retention:status` (opção `--json`) expõe tudo isto. **Não
apaga nada** — é intencionalmente um comando dry-run. O seu par, o comando
que age, é `retention:execute`, descrito a seguir.

## Execução do encerramento — `retention:execute`

Até 2026-08-27 esta secção não existia, e era esse o buraco: pedir o
encerramento começava uma contagem de 60 dias que chegava a zero **e não
fazia nada**. Os dados ficavam onde estavam, indefinidamente.

### Porque anonimiza em vez de apagar

Não é uma escolha de conveniência, é o que o esquema permite. Há **34 chaves
estrangeiras `ON DELETE RESTRICT` para `users`** (todos os `created_by`,
`confirmed_by`, `reviewed_by`, `activated_by`…) e **40 para
`organizations`**. A decisiva: `audit_events.causer_id` é RESTRICT em todas
as organizações — e o próprio `RequestPersonalAccountClosure` grava
`account.closure_requested` com essa pessoa como causer. **Pedir o
encerramento escreve a linha que torna impossível apagar essa conta.**

Isto não é um acidente para contornar. `AdminAccountController::destroy` já
recusa apagar uma conta com dados e diz porquê; o princípio está escrito lá:
apagar histórico para fazer um delete passar «não está em cima da mesa
(§22.4, §31)». `DeleteUserAccount` sempre só funcionou numa conta que nunca
chegou a ser nada, e é por isso que **não** é o executor.

Então a identidade sai e as linhas ficam. O esquema já pôs os dados pessoais
em sítios nomeados — `users` para o professor, `student_identities`
(`display_name` e `school_number` cifrados) para o aluno, com `students` a
guardar só um pseudónimo. Esvaziar esses é o que apagamento significa aqui:
uma classificação, um registo ou um relatório sobrevivem, e deixam de dizer
respeito a pessoa identificável.

### O que faz, por categoria

| Categoria | Ação |
|---|---|
| `users` — nome, email, `email_verified_at` | **Anonimizado**: nome genérico, email `anonimizado-{ulid}@invalido.local` |
| `users` — password, `remember_token`, 2FA | **Invalidado**: password passa a um valor que não é hash válido, 2FA e token limpos |
| `users.deactivated_at`, `anonymized_at` | **Carimbados**: `EnsureUserIsActive` recusa a sessão; `anonymized_at` é o que torna o comando idempotente |
| `passkeys`, `sessions` (do utilizador) | **Eliminados** |
| `student_identities` da organização pessoal | **Eliminados** — é isto que remove toda a PII de aluno |
| `organization_identities` da organização pessoal | **Eliminados** |
| Fotografias de aluno dessa organização | **Eliminadas** do disco |
| `organizations.name` da organização pessoal | **Anonimizado** (a organização fica: 40 chaves RESTRICT apontam-lhe) |
| Turmas, avaliações, registos, relatórios | **Preservados**, agora sem se referirem a pessoa identificável |
| Organização institucional de que seja membro | **Intocada**, incluindo a membership |

### Organizações institucionais

Quem é responsável (`owner_id`) de uma instituição **não consegue sequer
pedir** o encerramento (`RequestPersonalAccountClosure` recusa e manda
transferir a responsabilidade primeiro). Logo, na execução, a pessoa só pode
ser membro — e a saída de um membro não pode levar as turmas, os alunos nem
as avaliações de uma escola. **A membership fica deliberadamente**: a conta já
não consegue autenticar-se, e remover a linha deixaria `class_teachers`
(também RESTRICT) órfão para aulas que aconteceram mesmo.

> **A armadilha que isto já apanhou.** `StudentIdentity` **não** usa
> `BelongsToOrganization`: tem coluna `organization_id` mas nenhum scope
> global. Um `runFor($org, fn () => StudentIdentity::query()->delete())`
> **não filtra nada** e esvazia a tabela de todas as organizações da
> plataforma. Aconteceu na primeira versão desta ação e foi o teste de
> isolamento que o apanhou. Qualquer consulta a este modelo tem de filtrar
> `organization_id` explicitamente.

### Ordem: ficheiros primeiro, base de dados depois

Os caminhos das fotografias vivem nas linhas que estão prestes a ser
apagadas. Uma paragem entre os dois passos deixa linhas órfãs a apontar para
ficheiros que já não existem — e a execução seguinte termina o trabalho,
porque é idempotente. A ordem inversa deixaria ficheiros que ninguém
voltaria a encontrar.

### Comando e agendamento

```bash
php artisan retention:execute --dry-run   # conta e lista, sem alterar nada
php artisan retention:execute             # executa
php artisan retention:execute --limit=50  # lote menor
```

Agendado **diariamente às 03:40** (fuso da aplicação, Europe/Lisbon) em
`routes/console.php`, com `withoutOverlapping()`. Diário e não horário: a
janela mede-se em dias.

**O dry run não imprime dados pessoais** — só ids internos e a data do
pedido. É algo que um operador corre para decidir se avança, não uma forma de
ler os nomes de quem pediu para ser esquecido.

### Falhas

Cada conta é a sua própria unidade de trabalho. Uma que falhe é registada no
log (só o id, nunca a pessoa), contada, e o comando **continua para a
seguinte** — depois sai com código diferente de zero, para que o scheduler
não reporte `DONE` por cima de um encerramento que não aconteceu. Como a
operação é idempotente, a execução seguinte volta a tentar.

Uma fotografia que se recuse a ser apagada é registada e **não aborta** o
encerramento: deixar a conta identificável porque um ficheiro estava
bloqueado seria o pior dos dois resultados.

### Auditoria

Um evento `account.closure_executed` por encerramento, com `subject` a conta
e **`causer` a null** — ninguém fez isto, foi um agendamento; atribuí-lo à
pessoa que está a ser anonimizada seria falso e seria guardar o id dela em
mais um sítio. As propriedades são **contagens**, nunca nomes, emails ou
dados de aluno.

### Nunca correr à mão contra contas reais

Este é o único comando da aplicação que remove a identidade de uma pessoa, e
não tem confirmação interativa. Correr `retention:execute` em produção sem
autorização explícita é executar apagamentos definitivos. Para inspecionar,
usar sempre `--dry-run` ou `retention:status`.

Visibilidade equivalente, só de leitura, no backoffice
(`AdminAccountController::show`, `admin/AccountShow.vue`): o estado de
encerramento do dono e da própria organização, sem qualquer botão novo —
"eliminar já" está deliberadamente fora do âmbito desta fatia (§19).

## Sobre a eliminação imediata (`profile.destroy`)

O LÁPIS já tinha, desde o scaffold inicial (Fatia 0), um "Delete account"
de auto-serviço imediato — `DELETE /settings/profile`, confirmação por
password, sem janela de recuperação. Ficou por traduzir (ainda em inglês) e
nunca foi adaptado ao domínio do LÁPIS.

Manter as duas vias lado a lado — uma imediata e irreversível, outra
recuperável — anularia o propósito desta fatia: ninguém escolheria a
recuperável se a instantânea continuasse ali ao lado. A via de auto-serviço
descoberta passou a ser exclusivamente `AccountClosure.vue` (o pedido
recuperável); o componente `DeleteUser.vue` foi removido por ter ficado sem
utilização.

A rota, o controller (`ProfileController::destroy`) e a ação
(`DeleteUserAccount`) em si **não foram removidos** — continuam a existir e
testados, porque `DeleteUserAccount` é a mesma ação que o backoffice usa
para a eliminação administrativa excecional (`AdminAccountController::destroy`,
Fatia 0). Só deixou de ser a via avançada por defeito.

Esta fatia acrescentou-lhe uma guarda que faltava (§35, dívida da Fatia 4):
`DeleteUserAccount::delete()` recusa explicitamente, com
`AccountClosureException::ownsInstitution()`, apagar quem é dono de uma
organização institucional — antes, isso só falhava com uma `QueryException`
crua da restrição de chave estrangeira (`organizations.owner_id RESTRICT`),
com o SQL como primeira UX.

## O que NÃO está implementado

Deliberadamente fora do âmbito desta fatia (§83) — nenhum destes itens tem
código, nem parcial:

- purga real de dados pedagógicos ao fim da janela;
- anonimização irreversível (ver [docs/data-lifecycle.md](data-lifecycle.md#anonimização--dívida-explícita));
- override de retenção por organização/contrato;
- emails automáticos de aviso (pedido, 30/7/1 dias) — ver nota abaixo;
- restauro a partir do `backup-lapis.json` de uma exportação;
- retenção contratual por cliente, legal hold, arquivo permanente.

### Sobre os emails de aviso (§32)

Não implementados nesta fatia. Preparar `Notification`/`Mailable` sem os
testar nem ativar teria criado infraestrutura morta ou, pior, uma
notificação que parece funcionar mas nunca foi verificada a chegar. A
prioridade desta fatia foi o par UI + `retention:status`, que dá a um
operador a mesma informação por consulta ativa. Fica como dívida futura
explícita, não como omissão silenciosa.
