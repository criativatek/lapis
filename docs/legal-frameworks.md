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

### A designação do tipo é estampada, não relida

Um relatório agrupa intervenções por tipo e dá um rótulo a cada grupo. Esse
rótulo **não pode** vir do enum ao vivo: renomear um label reescreveria a forma
como registos feitos anos antes são descritos, na primeira vez que alguém
regerasse o relatório.

Também **não pode** vir do `title`. Esta base de código já tentou isso e
reverteu: `title` é escrito como `strategy_label ?? type->label()`, por isso
muitas vezes nem é a designação de um tipo, e em linhas importadas guarda o que
um processo antigo lá pôs — foi assim que «Legado sem dominio» chegou a um
documento impresso como se fosse uma espécie de ação pedagógica. O `title` não
consegue distinguir uma designação de texto livre.

Por isso existe `interventions.intervention_type_label`: uma coluna que só
alguma vez contém uma designação. Toda a leitura passa por
`Intervention::typeLabel()` — **snapshot primeiro, enum ao vivo como fallback**.

O fallback não é uma resposta degradada: é a resposta certa para um registo
feito antes de a coluna existir, e é o que todos os relatórios fizeram para
todos os registos até agora. Nada regride por ficar a null.

O **agrupamento continua a ser pelo código**, nunca pela designação — dois
registos do mesmo tipo não se separam em dois grupos só porque foram escritos
em momentos diferentes.

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

## Vigência

`ValidityWindow::covers()` é a única implementação de «esta versão vigorava
nesta data», e todos os frameworks respondem por ela. É uma função pura sobre
os dois limites, e não um trait, porque um trait herda as assinaturas
estreitadas da classe que o usa — em Portugal `validFrom()` não é nullable, o
ramo do null ficava morto ali e vivo noutro lado, e é essa a forma de regra que
acaba subtilmente diferente em dois sítios.

**Ambos os limites são inclusivos.**

- `validFrom` — um diploma que entra em vigor numa data está em vigor **nessa**
  data. O DL 54/2018 foi publicado a 6 de julho de 2018 e entrou em vigor no dia
  seguinte: `validFrom` é 2018-07-07, e uma intervenção datada de 2018-07-07 é
  coberta por ele.
- `validUntil` — nomeia o **último dia** de vigência, não o primeiro dia de
  não-vigência. Um limite exclusivo lê-se como uma data em que a lei ao mesmo
  tempo se aplica e não se aplica, consoante a quem se pergunte, e o erro de um
  dia fica invisível até exatamente um registo cair nele.

Duas versões consecutivas diferem por um dia: uma acaba a 2030-08-31, a
seguinte começa a 2030-09-01. Nunca partilham uma data e nenhuma data cai entre
elas — é isso que permite ao registry tratar **qualquer** data partilhada como
erro de configuração.

Um limite ausente é um limite ausente: `null` significa sem limite desse lado,
nunca «ainda não em vigor». A comparação é por **dia de calendário**, nunca por
instante.

Consequência a conhecer: uma intervenção datada antes de 2018-07-07 resolve
para **nenhum** framework. É a resposta honesta — o regime não existia — e o
registo continua editável, porque a validação não rejeita uma medida que a lei
aplicável não consegue posicionar (guarda apenas o nível submetido).

## Duas versões para a mesma data: falha explícita

`LegalFrameworkRegistry::find()` recolhe **todos** os candidatos aplicáveis
antes de escolher. Se houver mais do que um, lança
`OverlappingLegalFrameworksException` em vez de devolver o primeiro.

Uma data é regida por exatamente um regime. Responder pela ordem do array faria
a leitura jurídica de todos os registos dessa data depender da ordem por que
alguém escreveu um construtor — em silêncio, e mudando no dia em que essa ordem
mudasse. A alternativa a falhar alto não é «um default sensato»: é uma
classificação legal sobre uma criança escolhida por ordem de array.

Um draft a partilhar data com o regime em vigor **não** é sobreposição: é o
estado normal enquanto uma revisão se prepara. O filtro de estado corre antes.

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
- **Ainda não existe alerta automático** que compare a estampa
  `legal_framework_code` de um registo com o framework que a data resolve. A
  incoerência é detetável (é para isso que a estampa serve), mas quem a quiser
  ver tem de a procurar.
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
