# Caracterização pedagógica da turma e importação assistida — desenho

- **Data:** 2026-09-20
- **Estado:** desenho aprovado para implementação nesta branch (`feat/class-pedagogical-characterization`)
- **Base:** `fade39b` (main, 0.147.0)
- **Relaciona-se com:** `docs/roster-import.md` (o fluxo carregar → pré-visualizar → confirmar
  que esta funcionalidade repete), `docs/domain-model.md` §2.2 (turmas, inscrições,
  identidades), `app/Models/SupportMeasureLevel.php` e `app/Models/SupportMeasureCode.php`
  (o enquadramento do Decreto-Lei 54/2018 que já existe).

## Contexto

O professor precisa de registar, turma a turma, o que sabe sobre cada aluno que é
relevante para o acompanhamento pedagógico — potencialidades, interesses,
necessidades, barreiras, participação. Hoje não há onde: os campos existentes são
de avaliação (resultados, classificações) ou de intervenção formal
(`interventions`), e nenhum dos dois serve para «o que é preciso saber sobre este
aluno para trabalhar com ele».

Chama-se **caracterização pedagógica**, não «caracterização inicial»: a informação
pode nascer em setembro, mas atualiza-se o ano inteiro, e o nome não deve sugerir
que há um momento certo para a escrever.

## Princípio

**O professor decide; o sistema propõe.** É a mesma regra do resto da aplicação
(CLAUDE.md, `docs/roster-import.md` §3.3), e aqui tem uma consequência forte na
importação: nada do que um ficheiro diz entra na base de dados sem alguém ter
olhado para a proposta e confirmado.

---

## 1. Onde vive

Não há menu lateral novo. A caracterização é uma página da turma, a seguir à
lista de alunos:

```
Turmas → turma → «Caracterização pedagógica»
```

`classes/Show.vue` ganha uma ligação, abaixo da lista de alunos, para
`classes.characterisation.show`.

---

## 2. O que se guarda

### 2.1 Por aluno — o essencial

A caracterização individual é o valor principal da funcionalidade. Vive **na
inscrição**, não no aluno:

> Nunca se liga um facto pedagógico a `student_id` diretamente, porque o facto
> pertence ao par (aluno, turma) e não ao aluno — `docs/domain-model.md` §2.2.

Ancorar em `enrollment_id` dá de graça o âmbito da turma **e** do ano letivo
(a inscrição pertence a uma turma, que pertence a um ano letivo), e faz com que
um aluno que muda de turma não leve consigo a caracterização escrita por outro
professor noutro contexto.

Secções (todas `TEXT NULL`, todas opcionais):

| Secção | Coluna | Porquê esta e não outra |
|---|---|---|
| Caracterização geral | `summary` | O texto livre de quem não quer preencher campos. |
| Potencialidades | `strengths` | §7 — conceito reutilizável por um futuro instrumento legal. |
| Interesses | `interests` | idem |
| Necessidades | `needs` | idem |
| Barreiras à aprendizagem e à inclusão | `barriers` | idem |
| Participação | `participation` | §3 — aspeto de participação. |

As quatro do meio são deliberadamente as palavras que a proposta de educação
inclusiva usa. **Isto não é um PDI e não se apresenta como documento legal** —
é só o cuidado de não escolher uma estrutura que impeça reaproveitar o texto
mais tarde. Nenhuma delas é obrigatória e nenhuma alimenta cálculo nenhum.

### 2.2 Por turma — o acessório

`class_characterisations`, 1:1 com `classes`, uma única secção `summary`. Existe
porque há coisas que são da turma e não de ninguém em particular. **Não
substitui os campos individuais** e a interface não a apresenta como alternativa
a preenchê-los: aparece recolhida, acima da lista, como um cartão secundário.

### 2.3 Histórico

Uma caixa que sobrescreve tudo sem deixar rasto perde trabalho e perde contexto.
Mas guardar um retrato completo a cada gravação multiplica armazenamento de texto
sobre menores por cada vírgula corrigida.

O meio-termo: **cada revisão guarda só o valor anterior das secções que mudaram.**

`characterisation_revisions` (polimórfica, como `audit_events`):

| Coluna | Notas |
|---|---|
| `characterisable_type` / `characterisable_id` | aponta a `EnrollmentCharacterisation` ou `ClassCharacterisation` |
| `changed_sections` | JSON — lista de chaves de secção |
| `previous_values` | JSON — só as secções em `changed_sections`, com o texto que lá estava |
| `author_id` | FK `users` · `ON DELETE RESTRICT` · nullable |
| `source` | `VARCHAR(16)` CHECK `('manual','import')` |
| `import_batch_id` | FK `characterisation_import_batches` · nullable |
| `created_at` | sem `updated_at` — uma revisão não se edita |

Imutável por contrato: `static::updating()` lança `LogicException`, como
`AuditEvent` e `CalculationSnapshot`.

Na interface o histórico **não** aparece aberto. Cada aluno mostra a data da
última atualização e quem a fez; «Ver histórico» abre o resto. Uma gravação que
não muda nada não cria revisão nenhuma.

---

## 3. Siglas e códigos legais

### 3.1 O que já existe e não se duplica

O enquadramento do Decreto-Lei 54/2018 **já está no código**:

- `SupportMeasureLevel`: `universal` / `selective` / `additional` — ou seja
  **MU / MS / MA**.
- `SupportMeasureCode`: entre outros, `non_significant_curricular_adaptation`
  (**ACNS**), `significant_curricular_adaptation` (**ACS**),
  `psychopedagogical_support`, `tutorial_support`.
- `InterventionLegalFramework` / `PortugalInclusiveEducationFramework` — já é
  uma interface com uma implementação. A costura para trocar de enquadramento
  existe desde antes desta branch.

A Janela A está a construir um catálogo jurídico versionado. **Esta branch não
cria um catálogo concorrente.** O que cria é um resolvedor com interface própria,
`LegalCodeResolver`, cuja implementação atual
(`DecreeLaw54CodeResolver`) lê os enums acima. Quando o catálogo versionado
existir, troca-se a implementação no container e mais nada.

### 3.2 A regra que manda em tudo

`SupportMeasureLevel` diz, no seu próprio docblock:

> Recording this on an intervention says "this action fits pedagogically at this
> level" — it does NOT say the student is formally covered by measures at that
> level. […] only the second is a legal determination the app must never infer
> on its own.

Uma coluna de folha de cálculo com «MU a) b) e)» é precisamente o segundo facto.
Portanto o que a importação grava **não é uma determinação da aplicação**: é o
registo atribuído de **o que o documento da escola dizia**, com o token original
ao lado, confirmado por uma pessoa. A interface escreve isso por extenso —
«o ficheiro importado indicava» — e nunca «este aluno tem».

### 3.3 Dicionário

`AcronymDictionary`. Cada entrada tem `token`, `expansion` (pode ser `null`),
`scope` (`national` | `institutional`) e `confirmed`.

**Confirmadas** — só as que se derivam do que já está neste repositório:

| Token | Resolve para | Evidência |
|---|---|---|
| `MU` | `SupportMeasureLevel::Universal` | label «Medida universal» |
| `MS` | `SupportMeasureLevel::Selective` | label «Medida seletiva» |
| `MA` | `SupportMeasureLevel::Additional` | label «Medida adicional» |
| `ACNS` | `SupportMeasureCode::NonSignificantCurricularAdaptation` | label «Adaptação curricular não significativa» |
| `ACS` | `SupportMeasureCode::SignificantCurricularAdaptation` | label «Adaptação curricular significativa» |
| `PLNM` | sem medida; expansão «Português Língua Não Materna» | coluna do ficheiro real, `docs/superpowers/specs/2026-07-28-roster-import-design.md` |

O resolvedor também reconhece as **etiquetas por extenso** de qualquer
`SupportMeasureCode` (ex.: «Apoio psicopedagógico»), porque essas são o próprio
label do enum.

**Conhecidas mas por confirmar** — `RTP`, `PEI`, `PIT`, `CRI`, `PEL`, `SPO`,
`DEE`, `ATE`, `CAA`, `GAAF`. Entram no dicionário com `expansion = null`.
O sistema sabe que são siglas; **não sabe o que querem dizer e não inventa**.
Chegam à pré-visualização como «sigla reconhecida, significado por confirmar» e
o destino é (D) — decisão humana. Inventar a expansão só porque é plausível é
exatamente o que o docblock de `SupportMeasureCode` proíbe.

Siglas fora do dicionário: `scope = institutional`, tratadas como locais, sempre
não reconhecidas, sempre (D).

### 3.4 Confiança

Três estados, e um contexto que os move:

| Entrada | Estado | Porquê |
|---|---|---|
| `ACNS` | **reconhecida** | resolve sozinha para nível + código |
| `MS b) + ACNS` | **reconhecida** | `ACNS` resolve; `MS` confirma o nível; `b)` fica como anotação por resolver |
| `MU a) b) e)` numa coluna sem cabeçalho útil | **ambígua** | sabe-se o nível, não se sabe que medida |
| `b)` isolado, coluna sem nível | **ambígua** | §13 — não se adivinha |
| `RTP` | **não reconhecida** | sigla conhecida, significado por confirmar |
| `XPTO` | **não reconhecida** | sigla local |

As alíneas (`a)`, `b)`, `e)`) **nunca** resolvem para um código. Não há neste
repositório nenhuma correspondência entre alínea e medida, e escrevê-la de
cabeça seria inventá-la. Viajam como `unresolved_annotation`, visíveis na
pré-visualização, guardadas tal e qual.

---

## 4. Importação assistida

### 4.1 Formatos

Só o que a infraestrutura já suporta com segurança:

- **Colar uma tabela** (TSV/CSV do Excel ou do Google Sheets) — sem dependência nenhuma.
- **CSV** — leitura nativa.
- **XLSX** — `phpoffice/phpspreadsheet ^5.9`, já usado pelo importador de pautas.

**PDF e imagem ficam de fora.** Exigiriam OCR, que é um subsistema inteiro, e a
arquitetura não precisa deles para ficar preparada: o parser recebe uma grelha
(`TableGrid`), e qualquer origem futura que saiba produzir uma grelha entra sem
tocar no resto. Fica documentado como etapa futura, não implementado.

**Sem IA.** O parser é determinístico — cabeçalhos, regex, dicionário, contexto
de coluna. Nada desta funcionalidade atravessa a fronteira de IA. Se um dia
ajudar, entra como proposta adicional sobre o mesmo `TableGrid`, nunca como o
caminho por omissão.

### 4.2 Nada é estagiado

O importador de pautas escreve fotos num disco privado temporário porque tem
binários que não cabem num pedido. Aqui não há binários — há texto sobre menores.
Então **não se estagia nada**: nem ficheiro guardado, nem pasta temporária, nem
linha `*_imports` em estado intermédio.

```
POST classes/{class}/characterisation-imports/preview
     ↳ lê o ficheiro (ou o texto colado) em memória, classifica colunas,
       resolve códigos, casa linhas com inscrições, devolve a proposta.
       NÃO escreve nada. A resposta é a pré-visualização.

POST classes/{class}/characterisation-imports
     ↳ recebe as decisões explícitas do professor, linha a linha.
       Revalida tudo do lado do servidor. Escreve, numa transação.
```

O ficheiro carregado é lido e descartado no mesmo pedido. Não fica cópia — §17:
guardar o documento completo não é necessário para o que a proveniência precisa
de responder.

Consequência direta, e é a mais importante: **não existe caminho de código que
escreva caracterização a partir de um parsing.** A escrita só sabe receber
decisões.

### 4.3 Correspondência com alunos

`MatchCharacterisationRows`, pela ordem de `MatchRosterToEnrollments`:

1. **N.º de processo** (`student_identities.school_number`) → `confident`.
2. **Nome normalizado, exatamente uma inscrição** → `confident`.
3. **Nome por subsequência de palavras, exatamente uma inscrição** → `possible`.
4. Mais do que uma candidata → `ambiguous`, com as candidatas.
5. Nenhuma → `not_found`.

`school_number` e `display_name` estão cifrados, por isso nada se compara em SQL:
a pauta lê-se **uma vez** para dois índices em memória (o mesmo motivo e o mesmo
custo do importador de pautas — trinta alunos). Isto é também o que evita o N+1.

Só `confident` chega pré-selecionada. `possible`, `ambiguous` e `not_found`
chegam por resolver, e o professor aponta a inscrição certa ou ignora a linha.
**Nunca se cria um aluno**: esta funcionalidade caracteriza quem já está
inscrito. Quem não está, inscreve-se pela importação de pautas, que é onde essa
decisão pertence.

### 4.4 Destinos

Uma coluna não vai toda para um campo. Cada célula é classificada:

| Destino | Onde aterra | Regra |
|---|---|---|
| **(A) Caracterização** | secção de `enrollment_characterisations` escolhida pelo papel da coluna | texto descritivo |
| **(B) Medidas** | `enrollment_characterisation_source_measures` — par (nível, código) **tipado**, com `raw_token` e `unresolved_annotation` | só o que o resolvedor reconheceu |
| **(C) Apoios/recursos** | **lado nenhum** — classificados como `CatalogueFamily::SupportResource` e mostrados na pré-visualização | não há entidade de «recurso» no modelo, e não se inventa uma **nem se degrada o recurso para necessidade** |
| **(D) Não reconhecido** | **lado nenhum** — fica na pré-visualização | decisão humana; se for ignorado, desaparece |

(B) guarda tipado em vez de texto porque é o que torna a informação reutilizável
por um futuro instrumento legal (§7) sem a reinterpretar. Guarda sempre o
`raw_token` ao lado, para que se veja o que o ficheiro dizia mesmo quando a
interpretação mudar.

**(C) não é (A) por conveniência.** Uma coluna «Apoios» classificava como
«Necessidades», e isso escrevia um Centro de Recursos para a Inclusão dentro da
necessidade de uma criança — uma afirmação que ninguém fez, produzida pela
coluna que por acaso existia. «Um apoio mobilizado» e «uma necessidade do
aluno» são factos diferentes. Uma coluna de apoios passa a ter papel próprio
(`ColumnRole::Resources`) e **nenhuma secção**: o que lá vem é classificado
como `CatalogueFamily::SupportResource`, mostrado na pré-visualização com o
token original e a nota de que não há destino estruturado, e não é gravado.

Saber **que tipo de coisa** algo é não é o mesmo que ter **onde o pôr**, e é
por isso que a família vale a pena ser registada mesmo sem destino: quando
existir uma entidade de apoios/recursos, poderá reutilizar esta classificação
sem reinterpretar texto histórico. Se o professor quiser mesmo guardar aquilo
como caracterização, escreve-o na secção que escolher — é uma decisão dele,
explícita, e não uma inferência da importação.

**Nenhuma `Intervention` é criada pela importação.** Uma intervenção é uma ação
do professor, com datas, objetivo e revisões; deduzi-la de uma célula seria
inventar uma medida. Promover uma medida de origem a intervenção continua a ser
um ato manual, no ecrã das intervenções.

### 4.5 Proveniência

`characterisation_import_batches` — uma linha por importação confirmada:
`ulid`, `organization_id`, `class_id`, `source_kind` (`paste`/`csv`/`xlsx`),
`original_filename` (nulo quando é texto colado), `row_count`, `confirmed_by`,
`confirmed_at`.

É o mínimo que responde «de onde veio isto, quando e por quem». Não guarda o
documento nem as linhas rejeitadas.

---

## 5. Texto de ajuda

No topo da secção individual, uma vez, não por campo:

> Registe aspetos relevantes para o acompanhamento pedagógico do aluno.
> A informação pode ser atualizada ao longo do ano letivo.

E, junto dos campos:

> Evite incluir dados pessoais que não sejam necessários para o acompanhamento
> pedagógico.

Não se inventa consentimento nenhum, não se pede autorização que não existe, e
não se promete base legal que não foi decidida.

---

## 6. Privacidade

`docs/domain-model.md` linha 186 exclui, desde o desenho, dados de saúde, NEE e
categorias especiais, e diz que uma futura `student_support_measures` exigiria
«especificação, fundamento e proteção reforçada próprios».

Esta branch **não cria essa tabela**. O que cria é:

- texto pedagógico escrito pelo professor, com aviso de minimização à vista;
- e, na importação, o registo atribuído do que o documento da escola dizia,
  em termos do catálogo que já existe.

Não é a mesma coisa que declarar o estatuto formal do aluno, e a interface não o
apresenta como tal (§3.2). Se a Janela A decidir que este registo pertence ao
instrumento legal, migra para lá pela interface do resolvedor — que é a razão de
ela existir.

O resto segue o que a aplicação já faz: `BelongsToOrganization` em todas as
tabelas novas, `Gate::authorize('view'|'update', $class)` em todas as rotas,
`module:classes` no grupo, e `AuditLog::record()` em cada escrita
(`characterisation.updated`, `characterisation.imported`).

---

## 7. Interface

Uma página, três zonas, pensada para 25–30 alunos sem se tornar infinita:

1. **Cartão da turma** (recolhido) — a caracterização geral, secundária.
2. **Barra de ações** — pesquisa por nome + «Importar caracterização».
3. **Lista de alunos** — um `Collapsible` por aluno. Fechado mostra nome, n.º,
   data da última atualização (ou a etiqueta «sem caracterização») e o número de
   medidas de origem. Aberto mostra as secções e edita-as.

Só um aluno abre de cada vez. A edição grava por aluno, com o gesto de confirmação
do projeto, e não há gravação automática ao fechar.

A importação abre num `Dialog`: colar/ficheiro → pré-visualização (uma linha por
aluno, com o estado da correspondência e a proposta por destino, cada linha com
«Confirmar» / «Editar» / «Ignorar») → confirmar. Sem scroll horizontal a 390px:
a 390 a pré-visualização passa de tabela a cartões empilhados.

---

## 8. Fora de âmbito, de propósito

- **PDI** e qualquer apresentação disto como documento legal.
- **OCR**, PDF e imagem.
- **IA** a interpretar a tabela.
- **Criar alunos** pela importação.
- **Criar intervenções** pela importação.
- **Catálogo jurídico versionado** — é a Janela A.
- **`student_support_measures`** — continua a exigir o que sempre exigiu.
