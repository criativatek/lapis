# ADR-0008 — O plano é uma identidade; a versão é o que se vende

- **Status:** Accepted — implementado em `feat/plan-versions`. Ainda **não**
  integrado em `main`, sem release e sem deploy.
- **Date:** 2026-08-29
- **Toca em:** `App\Support\Entitlements` (Lote 2) e `App\Support\Limits` (Lote 3),
  sem reabrir nenhuma das duas decisões — só a fonte de onde leem.
- **Não altera:** [ADR-0002](0002-container-resolved-tenancy.md). Todas as
  consultas novas continuam explicitamente `withoutGlobalScope('organization')`
  onde as atuais já estão.

## Context

**Hoje um plano *é* a sua composição atual.** `module_plan` é `sync()`ada pelo
`EntitlementsSeeder` a cada execução e `plans.limits` é uma coluna JSON mutável.
Mudar o que o Pro vende muda, retroativamente e sem registo, o que **todos** os
subscritores de Pro têm. Não existe forma de manter alguém no que comprou.

**E isso já hoje lê o passado através do presente.**
`Entitlements::retainReadOnlyAfterDowngrade()` percorre as subscrições
**históricas** da organização e lê `$subscription->plan->modules` — ou seja, a
composição de *hoje* de um plano em que a organização esteve há meses. A resposta
a «o que é que esta pessoa podia fazer quando escreveu isto?» muda sozinha
sempre que o seeder corre. O método `retainReadOnlyAfterDowngrade()` nasceu em
`664c36f` e ainda **não** está em produção: a leitura retroativa do histórico
chega com a 0.87. É por isso que a janela está aberta — o defeito e a correção
cabem na mesma release, e nenhum histórico foi ainda interpretado por ele. Fica
abaixo como requisito crítico.

**A janela para corrigir é agora.** Não há clientes pagantes. Depois da primeira
venda paga, qualquer alteração de composição passa a ser matéria contratual e o
backfill deixa de poder assumir o que assume aqui.

## Requisito crítico — a interpretação histórica do acesso não pode mudar

O defeito descrito acima é o requisito nº 1 deste ADR, e não um efeito lateral
agradável. Enquanto existir, **alterar o `EntitlementsSeeder` altera
retroativamente o que a aplicação diz que uma organização podia fazer no
passado** — sem migração, sem registo e sem ninguém decidir isso.

O PlanVersion tem de eliminar este comportamento, e a eliminação tem de ser
demonstrada por um teste que hoje não existe:

```
PlanVersionHistoryTest::a_composicao_futura_nao_reescreve_o_passado()

  1. A organização subscreve Pro v1 (v1 inclui `lessons`).
  2. Downgrade para Base. `retainReadOnlyAfterDowngrade()` resolve
     `lessons` para ReadOnly, lido da v1 que ela realmente teve.
  3. Publica-se Pro v2, SEM `lessons`.
  4. Asserção: o mapa de acessos da organização é byte a byte o mesmo
     do passo 2. `lessons` continua ReadOnly — resolvido contra a v1 que
     a organização teve, nunca contra a v2 que nunca teve.
```

Sem o passo 4 a passar, o resto deste ADR não está implementado.

## Ordem obrigatória face à 0.87

O realinhamento Base/Pro (`664c36f`, ainda por publicar em produção) **é** uma
alteração de composição feita pelo seeder — exatamente a classe de mudança que
este ADR existe para tornar segura. Isso cria uma ordem que não é indiferente:

**A composição realinhada tem de ser a v1, não uma v2.** Se o PlanVersion
aterrar em produção antes do realinhamento, o grandfathering-por-defeito congela
todas as organizações existentes na composição *anterior* — e o realinhamento é
uma **correção** (uma organização Base não conseguia abrir o calendário que ela
própria tinha definido), não uma melhoria comercial.

Grandfathering de um defeito é o modo de falha desta decisão. Ou o
realinhamento vai a produção primeiro e o backfill copia o resultado, ou a
migração de backfill corre depois do seeder realinhado na mesma janela de
deploy. Não há terceira opção aceitável.

## Decision

### 1. `plans` é identidade; `plan_versions` é a oferta

- `plans` mantém `key`, `name`, `sort_order` — a identidade comercial estável
  («Pro»), que é o que a landing, o checkout, os filtros do backoffice e os
  emails nomeiam.
- `plan_versions`: `plan_id`, `version` (inteiro, crescente por plano), `limits`,
  `composition_hash`, `published_at`, `retired_at` (nullable), `notes`.
- `module_plan_version` substitui `module_plan`.

**Uma versão publicada é imutável.** Nunca se faz `sync()` sobre os seus módulos
nem `update` sobre os seus `limits`. Mudar a oferta é publicar a versão seguinte.
Publicar Pro v2 não altera nenhuma subscrição em Pro v1.

### 2. A subscrição aponta para a versão, e `plan_id` fica

`organization_subscriptions` ganha `plan_version_id`. `plan_id` **não** é
removida: o backoffice filtra por chave de plano, o CSV comercial exporta-a,
`CommercialListing` faz eager-load de `plan`, os emails leem `plan->name`.
Tirá-la obrigava a tocar em cerca de quinze sítios para não ganhar nada.

As duas colunas não podem discordar, e isso é garantido **pela base de dados e
não por convenção**: índice único em `plan_versions (id, plan_id)` e chave
estrangeira **composta** de `organization_subscriptions (plan_version_id,
plan_id)` para `plan_versions (id, plan_id)`. `restrictOnDelete`: uma versão com
subscritores não se apaga.

### 3. `Plan::modules()` desaparece

Não fica a coexistir com a da versão. Se ficasse, cada chamador não migrado
continuaria a responder — com o valor errado, em silêncio, que é exatamente o
defeito que este ADR existe para fechar. Removida, cada chamador não migrado
parte. O único sítio onde `modules()` existe passa a ser `PlanVersion`.

Todos os consumidores passam a `subscription → planVersion → modules/limits`, ou
ao resolvedor central (`Entitlements`, `Limits`) que já faz esse caminho por eles.

### 4. O seeder passa a publicar, não a atualizar

O `EntitlementsSeeder` continua a ser o sítio onde a composição de Base / Pro /
Institucional está escrita — isso não muda e não deve mudar. O que muda é o que
ele faz com ela:

1. garante o catálogo de módulos (`Module::updateOrCreate`, como hoje);
2. calcula o `composition_hash` (chaves de módulo ordenadas mais `limits`
   canonicalizado);
3. compara-o com a última versão publicada desse plano;
4. cria a versão N+1 **apenas** se a composição realmente diferir;
5. **nunca** modifica uma versão já publicada.

Correr o seeder duas vezes não cria duas versões; correr depois de uma alteração
cria exatamente uma. É isto que mantém `db:seed` seguro em produção e que torna
«a oferta mudou?» uma comparação em vez de uma leitura atenta.

### 5. Quem resolve, resolve pela versão

- `Entitlements::resolve()` — os dois ramos (em vigor e suspensa) e
  `retainReadOnlyAfterDowngrade()` — passa a `planVersion.modules`. É aqui que o
  grandfathering acontece de facto, e é aqui que o requisito crítico fecha.
- `Limits::planInForce()` devolve a `PlanVersion` e lê `planVersion.limits`;
  `AiQuota` lê `limits['ai_pool']` da versão.
- `HomeController::plans()` lê sempre a **versão corrente publicada**. A landing
  vende o que está à venda hoje — nunca a versão fixada de um subscritor.

### 6. Quem escreve continua a receber um `Plan`

`ChangeOrganizationPlan::to(Organization $o, Plan|PlanVersion $target)`.

Com um `Plan`, resolve a versão corrente publicada — que é a resposta certa para
uma venda de hoje, e o que mantém `ActivateProTrial`,
`CreatePersonalOrganization`, `CreateInstitutionalOrganization` e o
`AdminAccountController` a compilar sem uma linha alterada. Com uma
`PlanVersion`, usa exatamente essa.

**A ambiguidade que os testes têm de excluir** é a de uma migração deliberada
passar por uma renovação acidental. Dois casos, ambos obrigatórios:

- `to($org, $planPro)` numa organização já em Pro **v1**, com **v2 publicada**:
  não é um no-op. A regra `$unchanged` atual compara `plan_id`; passa a comparar
  `plan_version_id`. Uma organização em v1 movida para «Pro» acaba em v2, com
  linha nova e história contínua — e o teste tem de o afirmar, porque a
  alternativa silenciosa (no-op por o plano ser o mesmo) deixaria o operador a
  julgar que migrou alguém que não migrou.
- `to($org, $proV1)` numa organização já em Pro v1: no-op, como hoje.

### 7. A fallback dormente do trial é fixada na criação

`startProTrial()` pré-cria a linha Base datada do fim do trial. Fica com a versão
de Base **corrente no início do trial**, não com uma resolvida no fim. Duas
razões: nada tem de «acordar» para a resolver — que é precisamente a propriedade
que a linha dormente existe para ter — e, se o Base mudar durante o trial, a
organização aterra no Base que lhe foi prometido. Se isso for indesejável num
caso concreto, é um `to()` de um operador, explícito e registado.

### 8. A condição comercial contratada tem de deixar prova, e não é a PlanVersion que a guarda

`plan_versions` versiona **direitos funcionais**. A condição comercial acordada é
um facto diferente, com outro relógio, e fica no seu próprio sítio. Ver a secção
seguinte para a auditoria completa; a decisão é:

**Campos snapshot mínimos em `organization_subscriptions`** — quatro colunas
novas, escritas uma vez na criação da subscrição e tratadas como o
`SubscriptionPayment` já trata as suas: imutáveis depois de existirem, com um
guarda `updating` no modelo.

| Coluna | Tipo | Significado |
|---|---|---|
| `contracted_price_cents` | `unsignedInteger` nullable | O que foi acordado, em cêntimos. NULL = nunca houve preço; `0` = acordado como gratuito. NULL ≠ 0, a mesma disciplina que `commercial_condition` já aplica a NULL vs `Other`. |
| `contracted_currency` | `char(3)` nullable | Obrigatória sempre que o preço não é NULL. |
| `billing_period` | `string(20)` nullable | Enum `BillingPeriod`: `annual`, `none`. **Não existe `monthly`** — `commercial.ts:14-16` diz que não existe produto mensal, e inventar o valor no enum era inventar o produto. |
| `commercial_term_ends_at` | `timestamp` nullable | Até quando vale a **condição**. Distinta de `ends_at`, que é até quando vale o **acesso**. |

`commercial_term_ends_at` é a coluna que justifica a secção inteira. É ela que
responde a «gratuito no ano letivo 2026/27»: um Base criado hoje leva
`contracted_price_cents = 0` e `commercial_term_ends_at = 2027-08-31`, enquanto
`ends_at` continua NULL. O acesso não termina; a condição sim, e saber quem tem
de ser contactado passa a ser uma consulta em vez de arqueologia.

**Nada disto é lido por `Entitlements` nem por `isInForce()`**, pela mesma razão
que `commercial_condition` não é. Se `commercial_term_ends_at` chegasse ao
resolvedor, isto deixava de ser prova comercial e passava a ser expiração
automática — uma decisão muito maior, que não está a ser tomada aqui.

Acrescenta-se um caso ao enum: `CommercialCondition::Promotional` («condição
promocional por tempo limitado»). O Base gratuito de 2026/27 não é `Standard` —
`Standard` está documentado como «Pro at list price», e usá-lo faria
`normallyPaid()` devolver `true` para contas que não devem nada.

## Auditoria: o que a condição comercial já preserva, e o que falta

| Facto | Onde está hoje | Imutável? | Suficiente? |
|---|---|---|---|
| Condição da adesão | `organization_subscriptions.commercial_condition` + `_note` | Não, mas **toda a alteração é auditada** (`commercial.condition_set`, com anterior → nova, autor e `plan_id`) | Sim, para o rótulo |
| Trial | `status = Trial` | Sim — `supersede()` nunca relabela | Sim |
| Preço **pedido** | `SubscriptionPayment.amount_cents` da linha `pending` que o `RequestBankTransferPayment` cria | Sim — o modelo recusa reescrevê-lo | Só no caminho de checkout online |
| Preço **recebido** | `SubscriptionPayment.amount_cents`, `status = paid` | Sim | Sim |
| Moeda | `SubscriptionPayment.currency` | Sim | Só havendo pagamento |
| Período pago | `SubscriptionPayment.period_starts_at` / `period_ends_at` | Sim | **Opcionais e nunca preenchidos pelo checkout** |
| Periodicidade (anual) | Em lado nenhum da BD — só em `commercial.ts` e no FAQ | — | **Falta** |
| Base gratuito 2026/27 | Em lado nenhum da BD — só em `commercial.ts:69` e no FAQ | — | **Falta** |
| Vigência comercial | `ends_at`, mas `ChangeOrganizationPlan::to()` cria sempre `ends_at = null` | — | **Falta** |

A conclusão honesta é que o sistema já preserva **bem** o dinheiro e **mal** a
promessa. `SubscriptionPayment` é imutável por construção e o `AuditLog` cobre
as mudanças de condição; o que não existe em lado nenhum é o que foi *acordado*
quando não houve (ainda) pagamento — que é precisamente o caso do Base gratuito,
do trial, da concessão administrativa e da janela de 14 dias entre o pedido de
transferência e a confirmação.

### Porque não uma tabela versionada de condições comerciais (opção B)

Porque `organization_subscriptions` já **é** essa tabela. `ChangeOrganizationPlan`
garante no máximo uma subscrição em vigor, cada mudança cria uma linha nova com
`starts_at`/`ends_at`, e nada é apagado. Uma tabela de versões de condição
modelaria uma segunda história do mesmo objeto, com a garantia de que as duas
divergiriam. As quatro colunas acima cabem na história que já existe.

### Porque não colar o preço à PlanVersion

Preço e composição mudam em relógios diferentes. Um aumento de preço não altera
direitos e não deve gerar uma versão funcional nova; publicar a v2 do Pro por
causa de +5 € faria toda a base de subscritores parecer «desatualizada» num
ecrã que devia estar vazio. E a condição de Fundador é um preço **sem** plano
próprio — colá-la à versão obrigava a uma versão Fundador, que é exatamente o
erro que o `CommercialCondition` foi escrito para evitar.

## O backfill

### Parte 1 — `plan_version_id`

Versão 1 de cada plano = a composição canónica no momento da migração, copiada de
`module_plan` e `plans.limits` tal como estão. O `plan_version_id` de **todas** as
subscrições — incluindo as fechadas — passa a apontar para a versão 1 do seu
próprio `plan_id`.

**A migração não pode alterar o acesso de ninguém.** Não é uma expectativa, é uma
consequência da construção: a v1 é uma cópia da composição que todos os
resolvedores já leem hoje.

```
PlanVersionBackfillTest::o_mapa_efetivo_nao_muda()

  Fixtures que cobrem: Base, Pro, Institucional, trial em curso,
  trial expirado com fallback dormente, suspensa, com override
  enabled=true, com override enabled=false, Pro→Base já descida,
  e organização sem subscrição nenhuma.

  Para cada uma: grava `accessStatesFor($org)` e `limitFor($org, $k)`
  para todos os `LimitKey` ANTES da migração; corre a migração; assere
  igualdade EXATA depois. Não «equivalente»: igual.
```

As subscrições históricas são incluídas de propósito.
`retainReadOnlyAfterDowngrade()` lê-as; deixá-las a NULL obrigaria a um caminho
de fallback que voltava a ler o presente — o defeito a corrigir.

O que **não** é tocado, e porquê:

- **`status`, `starts_at`, `ends_at`** — nenhuma linha muda de janela nem de
  estado. Trials em curso continuam trials, com os mesmos dias.
- **`commercial_condition`** — Membro Fundador é Pro com uma condição, e a
  condição nunca influenciou o entitlement. Continua a não influenciar.
- **`organization_module_overrides`** — continuam a aplicar-se por cima, no fim,
  e continuam a ser a válvula de escape. É por elas que se dá a uma organização
  algo que a versão fixada não tem, sem inventar uma versão só para ela.

Uma só migração: coluna nullable, backfill, `nullable(false)`, chave estrangeira
composta.

### O rollback só é seguro enquanto ainda não há história

As migrações do PlanVersion são reversíveis **num único estado**: aquele que
elas próprias deixam ao correr pela primeira vez, com **uma versão por plano**.
Nesse estado, `down()` reconstrói `module_plan` e `plans.limits` a partir da
única versão publicada e não se perde nada — é a inversa exata do backfill.

A partir do momento em que existe uma **v2**, deixa de ser assim, e não por
falta de cuidado na implementação: o schema antigo tem literalmente uma
composição por plano, que é a limitação que este ADR existe para levantar. Um
rollback nesse ponto teria de escolher uma composição e deitar as outras fora, e
`organization_subscriptions` perderia a coluna que diz qual foi a oferta que
cada cliente contratou — prova contratual que nenhum `up()` posterior consegue
reconstruir, porque a informação deixou de existir.

Por isso **o `down()` recusa-se explicitamente** quando encontra mais do que uma
versão por plano, ou subscritores do mesmo plano espalhados por versões
diferentes. Falha alto, com uma mensagem que diz porquê. Não há caminho de
downgrade a seguir a isso, e escrever um seria inventar precisamente o facto que
se acabou de apagar: quem quiser voltar atrás depois de haver história
multiversão restaura um backup, que é a única operação que preserva o que estava
lá.

### Parte 2 — snapshot comercial

**Migração separada, no mesmo lote, a correr depois.** As duas têm critérios de
aceitação diferentes e cada um tem de ser verificável sozinho: a primeira promete
«nada muda de acesso»; a segunda promete «nada de comercial é inventado».

O backfill da segunda é **NULL em todas as colunas, em todas as linhas** — o
mesmo precedente que a migração `2026_09_08_000100` já fixou por escrito:
preencher seria inventar história comercial que a base de dados nunca teve.

Fica **por decidir com o operador**, e deliberadamente fora da migração: se as
contas Base já existentes devem receber `commercial_term_ends_at = 2027-08-31`
por terem aderido sob a promessa da landing. É uma decisão comercial, não uma
consequência técnica, e é um `UPDATE` guardado por um evento de auditoria no dia
em que for tomada. Não havendo clientes pagantes, adiá-la não custa nada.

## Consequences

**Publicar a v2 do Pro não toca em nenhuma subscrição existente.** Grandfathering
deixa de ser uma funcionalidade e passa a ser a ausência de uma ação — o caso por
defeito, o que não precisa de ser lembrado. Mover subscritores para a frente
passa a exigir um ato explícito, que é a ordem certa das duas coisas.

`retired_at` marca uma versão como já não vendável sem afetar quem está nela.
Uma versão nunca se apaga, e a chave estrangeira garante-o.

Custos: mais uma tabela e um join em `Entitlements::resolve()` (já eager-loaded,
continua a ser uma query por organização). O backoffice passa a ter de mostrar em
que versão está cada subscrição — sem isso, «o Pro» deixa de ser uma resposta
completa a um operador. `CatalogCoherenceTest` e
`EntitlementsSeeder::moduleKeys()` acompanham; a maioria dos testes não muda,
porque `tests/Concerns/SubscribesOrganizations.php` passa a ser o único sítio que
resolve uma versão.

O guarda `updating` novo em `OrganizationSubscription` tem de ser escrito com
cuidado: `ChangeOrganizationPlan` faz `forceFill()->save()` em vários sítios, mas
só sobre `starts_at`, `ends_at` e `status`. O guarda cobre apenas as três colunas
de snapshot imutáveis, e não colide.

## Fora de âmbito, deliberadamente

**A migração em massa de subscritores.** O `to(PlanVersion)` torna possível
migrar uma subscrição individual, explicitamente, e isso chega para esta fase.
Um mecanismo massivo só deve existir quando houver uma política real de migração,
e então com dry-run, critérios declarados, auditoria por linha, resultado
verificável e segurança de reversão. Construí-lo antes disso seria construir a
ferramenta antes de saber a regra que ela aplica.

**Expiração automática por `commercial_term_ends_at`.** A coluna existe para
provar e para alimentar uma fila de trabalho do operador. Ligá-la ao resolvedor
é outra decisão, com outro risco.

## Ordem de implementação

1. Schema, modelos e migração de backfill do `plan_version_id`. Sem alteração de
   comportamento; `PlanVersionBackfillTest` é o critério de aceitação.
2. Leitores passam à versão: `Entitlements`, `Limits`, `AiQuota`,
   `HomeController`. `Plan::modules()` é removida **neste** passo — é o que faz o
   analisador encontrar o que falta, em vez de o deixar a responder errado.
   `PlanVersionHistoryTest` entra aqui.
3. Seeder passa a publicador (hash e idempotência).
4. `ChangeOrganizationPlan::to(Plan|PlanVersion)`, os dois testes de
   desambiguação da decisão 6, e a versão visível no backoffice.
5. Snapshot comercial: migração separada, enum `BillingPeriod`, caso
   `CommercialCondition::Promotional`, guarda de imutabilidade, e escrita nos
   caminhos que criam subscrições.

De 1 a 5 antes da primeira venda paga, respeitando a ordem obrigatória face à
0.87. Retirada de versões e migração em massa quando forem precisas.
