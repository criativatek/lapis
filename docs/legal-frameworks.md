# Enquadramento legal das Estratégias e Medidas

Como o Lapispro separa **o que o professor fez** de **como a lei lê o que ele fez** —
e porque é que essa separação é o que permite a legislação mudar sem reescrever
registos antigos.

Leitura obrigatória antes de mexer no catálogo de Estratégias e Medidas.

## As duas camadas

| Camada | Onde vive | Muda quando |
|---|---|---|
| **Conceito pedagógico** | `App\Models\InterventionType` | Muda a prática pedagógica |
| **Enquadramento legal** | `App\Support\Interventions\*Framework` | Muda a lei |

`InterventionType` é **internacional e não conhece lei nenhuma**. «Apoio à
planificação textual» significa o mesmo em Lisboa e em Lyon. O que difere é se
alguma jurisdição enquadra legalmente esse ato — e isso vive inteiramente atrás
de `InterventionLegalFramework`.

Consequência prática: **não existe** `InterventionTypePortugal` nem
`InterventionType2027`. Uma alteração legislativa é um *framework* novo, nunca um
fork do catálogo pedagógico.

## As quatro famílias

Um item do catálogo pertence a uma de quatro famílias (`App\Models\CatalogueFamily`):

| Família | O que é | Pode ter nível? |
|---|---|---|
| `support_measure` | Medida nomeada pelo diploma em vigor | **Sim** — só esta |
| `pedagogical_strategy` | Ensino corrente | Não |
| `evaluation_adaptation` | Adaptação ao processo de avaliação | Não |
| `resource_support` | Apoio ou recurso mobilizado (CRI, técnico especializado) | Não |

A família **não é propriedade do item** — é propriedade do item *lido por um
framework*. `InterventionLegalFramework::familyFor()` é quem responde. Sob
`NullLegalFramework` tudo é `pedagogical_strategy`, porque sem lei nada pode ser
uma medida legal.

Uma sugestão (`LegalMappingMode::Contextual`) é **estratégia pedagógica** até o
professor confirmar. Chamar-lhe medida antes disso seria a aplicação a decidir um
facto jurídico sobre uma criança.

> **Dívida conhecida:** nenhum item do catálogo atual é `resource_support`. O
> Lapispro nunca ofereceu CRI nem recursos especializados como itens próprios, e
> inventá-los é precisamente o que o projeto proíbe (§1). A família existe para
> que a distinção seja representável no dia em que um desses itens for
> adicionado, e está exercitada nos testes.

## Identidade estável

Aquilo que fica guardado é sempre o **código** (`snake_case`), nunca o label:

- `InterventionType::TutorialSupport->value` → `'tutorial_support'`
- `SupportMeasureCode::TutorialSupport->value` → `'tutorial_support'`

**Renomear um label é seguro. Mudar um código reescreve o significado de todos os
registos já feitos sob ele.** Foi assim que «Reforço das aprendizagens» passou a
«Antecipação e reforço das aprendizagens» — a designação do artigo 9.º, alínea d)
— sem tocar em `learning_reinforcement`.

O `title` de uma intervenção é um **snapshot** do label no momento da escrita,
por isso um registo feito antes de um rename continua a dizer o que dizia.

**Mas o que lê o enum ao vivo não é snapshot.** `StudentReportSource` agrupa as
intervenções por tipo e tira o rótulo do grupo do enum, por isso um relatório
**regerado** sobre um registo antigo passa a usar a designação nova. No caso
deste rename isso é o resultado pretendido — a medida sempre foi o artigo 9.º,
alínea d) — mas é uma alteração à forma como registos antigos são descritos, e
não só os novos. Um rename de label é barato para a identidade dos dados e
**não é neutro** para o texto dos relatórios: decidir um é decidir os dois.

## Versionamento

`InterventionLegalFramework` descreve **uma jurisdição durante um período da sua
história**:

```
code()              'pt-inclusive-education-2018'   ← identifica a VERSÃO, nunca reutilizar
title()             «Regime jurídico da educação inclusiva»
legalReference()    o diploma, com os que o alteraram
validFrom()/validUntil()
status()            draft | future | active | historical
coversDate($date)   se vigorava nesta data
levelFor($measure)  o nível que ESTA versão dá à medida, ou null se não a nomeia
legalReferenceFor() artigo, número, alínea, designação legal, vigência
```

`LegalFrameworkRegistry::find()` **salta qualquer versão cujo `status()` não seja
aplicável**. Uma proposta legislativa pode estar registada, ser testada e estar
pronta para o dia em que entrar em vigor, sem nunca chegar a um professor. O
guarda está no registry e não nos *call sites*, para que não dependa de alguém se
lembrar de um `if`.

`NullLegalFramework` é um estado **válido**, não degradado: uma escola numa
jurisdição sem framework regista intervenções normalmente, só sem enquadramento
legal. Nunca empresta a taxonomia de outro país.

## História

Três mecanismos, todos necessários:

1. **A data manda.** `LegalFrameworkResolver` resolve pelo `started_on` da
   intervenção, **nunca por hoje**. Uma intervenção de 2026 editada em 2030
   continua a ser lida sob o regime de 2026.
2. **O código é estampado.** `legal_framework_code`, em `interventions` e em
   `intervention_support_measures`, guarda a versão sob a qual o enquadramento
   foi decidido. A leitura continua a resolver pela data; a estampa é o que torna
   uma resolução errada **detetável** em vez de silenciosa.
3. **O nível é guardado.** `support_measure_level` não é redundância: é o
   snapshot de como a lei lia aquela medida naquela data. Recalculá-lo na leitura
   reclassificaria registos antigos no dia em que a lei mudasse.

Uma estampa preservada **nunca é re-estampada**. Quando uma edição não diz nada
sobre enquadramento, o enquadramento antigo sobrevive *com a sua estampa
original*.

## O nível é derivado, nunca escolhido

O nível de uma medida é fixado pelo diploma que a nomeia. Pedir ao professor que
escolha «Seletiva» e depois «Apoio tutorial» é pedir-lhe para repetir algo que a
lei já decidiu — e deixa os dois discordarem.

- **UI:** um só select, com as medidas agrupadas por nível (`optgroup`). O nível é
  lido a partir da medida escolhida.
- **Servidor:** `levelFor()` deriva o nível a partir do framework aplicável à
  data. O `level` enviado pelo cliente continua a ser aceite (clientes antigos,
  importação) mas é **verificado, nunca aceite em confiança**.
- **Níveis mobilizados:** obtêm-se das medidas selecionadas, cumulativamente.
  Nunca há uma segunda lista escrita à mão.

## Como acrescentar uma versão legislativa futura

1. Nova classe que implemente `InterventionLegalFramework`, com `code()` **novo**
   (nunca reutilizar o de outra versão) e `status()` = `Draft` ou `Future`.
2. Registá-la em `LegalFrameworkRegistry`. O registry recusa aplicá-la enquanto o
   estado não for `Active`/`Historical` — pode ser registada e testada sem risco.
3. Dar à versão anterior um `validUntil()` e `status()` = `Historical`. **Não a
   apagar**: é ela que continua a responder por tudo o que foi registado antes.
4. Medidas novas: uma `case` em `SupportMeasureCode` **e** uma migration aditiva
   que alargue o `CHECK` de `intervention_support_measures.support_measure_code`.
   O `CHECK` tem uma cópia literal da lista — é a terceira fonte da verdade e
   custa uma migration por medida nova.
5. `supportMeasureLevels()` devolve *payload* (strings), não enums, por isso uma
   versão futura pode **mostrar** uma medida que o enum ainda não tem. Só
   **persistir** exige o passo 4.

## Como revogar ou substituir uma medida sem apagar história

Nunca se remove a `case` do enum. Na versão que a revoga:

- `levelFor()` devolve `null`;
- `legalReferenceFor()` devolve uma `LegalReference` com
  `LegalReferenceStatus::Revoked` (ou `Superseded` + `supersededBy`).

Resultado: a medida **desaparece dos dropdowns** (`supportMeasureLevels()` filtra
por `status->isSelectable()`) e **continua a resolver** para label e citação, para
que um registo antigo não passe a renderizar um vazio. Nenhuma linha é tocada.

## Fronteiras

- **IA/Gemini:** o catálogo de medidas e níveis **não é injetado** em nenhum
  prompt. `InterventionStrategySuggester` nunca escreve uma `Intervention`, e
  `RewriteGuard`/`IncidentRewriteGuard` bloqueiam «medidas universais/seletivas/
  adicionais» em texto reescrito por IA. Não mexer sem autorização.
- **Entitlements:** o enquadramento legal não é uma funcionalidade comercial.
  Nenhuma medida, nível ou família depende do plano.

## Dívida conhecida

Deliberadamente fora do âmbito desta fatia — que é a fundação, não a
reformulação visual da página.

- **A página ainda não está organizada por família.** O payload transporta
  `family`, `family_label` e `may_carry_measure_level` por tipo, e a lista
  marca com uma etiqueta tudo o que não é estratégia pedagógica. Agrupar o ecrã
  por família, e mostrar `article`/`citation`/`designation` por medida (já
  presentes no payload, ainda não renderizados), é a fatia seguinte.
- **`coversDate()` da versão portuguesa devolve `true` para toda a linha
  temporal**, e `LegalFrameworkRegistry::find()` devolve o primeiro match
  aplicável. No dia em que existir uma segunda versão, **estreitar este método
  ao mesmo tempo** que a nova é registada — senão intervenções de 2019 passam a
  resolver para quem estiver primeiro no array. A estampa
  `legal_framework_code` torna isso detetável; ainda não existe um alerta
  automático que compare a estampa com o framework resolvido.
- **`LegalMapping::level()`** cai em `SupportMeasureCode::currentPortugueseLevel()`
  quando não recebe um override, por isso o payload do catálogo leva o nível *de
  hoje* mesmo para um framework histórico. Hoje coincidem. Quem acrescentar uma
  segunda versão passa o nível explicitamente nas fábricas
  `LegalMapping::direct()`/`contextual()`.
- **`SupportMeasureCode::level()`** sobrevive como alias de
  `currentPortugueseLevel()` e `forLevel()` usa-o. Ambos significam «hoje»;
  qualquer leitura que tenha de estar certa para o passado usa
  `InterventionLegalFramework::levelFor()`.
- **O catálogo está triplicado**: o enum PHP, o `Rule::enum` da validação e a
  lista literal dentro do `CHECK` de `intervention_support_measures`. Uma medida
  nova custa uma migration. É o preço de ter a garantia no motor de base de
  dados, e o CI (MySQL) é quem a verifica — o SQLite local ignora `CHECK`.
- **Sem itens `resource_support`.** CRI e apoios especializados não existem hoje
  no catálogo; acrescentá-los é uma decisão pedagógica, não técnica.
