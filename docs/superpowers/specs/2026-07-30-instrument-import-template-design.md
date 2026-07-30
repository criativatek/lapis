# Importar estrutura de outro instrumento — desenho

## Contexto

Hoje, criar um instrumento de avaliação (`resources/js/pages/instruments/Create.vue`
→ `InstrumentController::store()` → `InstrumentBuilder::create()`) exige sempre
introduzir as questões (código, enunciado, cotação, distribuição por domínios)
de raiz — mesmo quando um professor aplica exatamente o mesmo teste a várias
turmas da mesma disciplina (ex.: "Teste de Compreensão Leitora" em 7.º A e
7.º B).

Este documento acrescenta a possibilidade de importar a estrutura de questões
de um instrumento já existente como ponto de partida, poupando essa
reintrodução manual. **Nunca importa notas de alunos** — só a estrutura
(questões, cotações, distribuição por domínios).

## Regras decididas

1. **Âmbito**: o seletor mostra só instrumentos de turmas que o professor
   atual ensina (mesmo critério já usado em `Instrument::index()` — `whereHas('schoolClass.teachers', ...)`),
   nunca instrumentos de colegas.
2. **Só a mesma disciplina**: o seletor só mostra instrumentos cuja turma de
   origem tem a mesma `subject_id` da turma onde o novo instrumento está a
   ser criado. Isto garante que a distribuição por domínios de cada questão
   (que referencia `domain_id`) é sempre válida na turma de destino — um
   domínio pertence à disciplina, nunca a uma turma específica, por isso
   "mesma disciplina" chega para garantir compatibilidade, sem precisar de
   remapear nada por nome.
3. **O que é copiado**: as questões do instrumento escolhido — `code`,
   `label`, `points_possible`, `is_bonus`, e a distribuição por domínios de
   cada uma (`domain_id`, `allocation_percent`, copiados tal e qual, já que a
   regra 2 garante que os domínios existem na turma de destino) — mais
   `total_points` e `allow_bonus` do instrumento de origem (ligados à soma
   das cotações das questões, tal como `InstrumentBuilder::guard()` já
   valida). O título do instrumento de origem também é copiado, como valor
   inicial.
4. **O que NUNCA é copiado**: notas de alunos (óbvio, mas explícito), período
   letivo, tipo de instrumento, data de aplicação, `counts_toward_classification`,
   `purpose`, `weight` — todos continuam a pedir-se de novo em cada
   instrumento, como já acontece hoje.
5. **O nome fica sempre editável**: importar não bloqueia o campo do título —
   o professor pode mudá-lo antes de guardar (e, quando a funcionalidade de
   edição de instrumentos estiver disponível, também depois de guardado).
6. **Sem novo pedido ao servidor por importação**: a lista de instrumentos
   importáveis (com as suas questões já incluídas) vem toda no carregamento
   inicial da página "Novo instrumento" — escolher um para importar é uma
   operação só no cliente (copia os dados já recebidos para o formulário),
   sem round-trip adicional.

## Dependência de sequência

Esta funcionalidade mexe no mesmo formulário (`Create.vue`, que o plano de
edição de instrumentos em curso está a extrair para `InstrumentForm.vue`,
partilhado com uma nova `Edit.vue`). Implementar isto antes desse plano
terminar duplicaria trabalho e arriscaria conflitos. **Só avança depois do
plano `docs/superpowers/plans/2026-07-29-instrument-editing.md` estar
completo e mesclado.**

## Backend

`InstrumentController::create()` (o método que já renderiza `instruments/Create`
— ou `instruments/Create`/`Edit` via `InstrumentForm.vue`, dependendo do que o
plano de edição já tiver mudado até lá) passa a enviar uma nova prop
`importableInstruments`:

```php
'importableInstruments' => Instrument::query()
    ->whereHas('schoolClass.teachers', fn ($query) => $query->whereKey($this->user()->getKey()))
    ->whereHas('schoolClass', fn ($query) => $query->where('subject_id', $class->subject_id))
    ->with(['schoolClass', 'items.domainAllocations'])
    ->orderByDesc('applied_on')
    ->get()
    ->map(fn (Instrument $source) => [
        'ulid' => $source->ulid,
        'title' => $source->title,
        'class_label' => $source->schoolClass->label,
        'applied_on' => $source->applied_on->toDateString(),
        'total_points' => $source->total_points === null ? null : (float) $source->total_points,
        'allow_bonus' => $source->allow_bonus,
        'items' => $source->items->map(fn (InstrumentItem $item) => [
            'code' => $item->code,
            'label' => $item->label,
            'points_possible' => (float) $item->points_possible,
            'is_bonus' => $item->is_bonus,
            'domains' => $item->domainAllocations->map(fn ($allocation) => [
                'domain_id' => $allocation->domain_id,
                'allocation_percent' => (float) $allocation->allocation_percent,
            ]),
        ]),
    ]),
```

Não é preciso nenhuma rota nova nem nenhuma escrita nova na base de dados —
isto é puramente leitura, servida junto com a página que já existe. A turma
atual (`$class`, já disponível em `create(SchoolClass $class)`) é o que
decide a disciplina a filtrar.

## Frontend

Em `Create.vue` (ou `InstrumentForm.vue`, o que existir nessa altura), um
novo controlo "Importar de outro instrumento" (um `<select>` simples, listando
`title · class_label · applied_on` para desambiguar, com uma opção "Nenhum"
por defeito). Ao escolher um:

- `form.title` recebe o título do instrumento escolhido (continua um campo
  normal, editável a seguir).
- `form.total_points` e `form.allow_bonus` recebem os valores do instrumento
  escolhido.
- `form.items` é substituído pela lista de questões do instrumento escolhido
  (mesma forma que `ItemRow` já usa — `code`, `label`, `points_possible`,
  `is_bonus`, `domains`), pronta a editar como qualquer questão introduzida
  manualmente.
- Escolher "Nenhum" (ou nunca escolher nada) deixa o formulário exatamente
  como está hoje, sem qualquer instrumento pré-carregado.

Isto é uma operação síncrona, sem submissão de formulário nem pedido ao
servidor — os dados já vieram todos na prop `importableInstruments`.

## Testes

- **Feature**: `create()` envia `importableInstruments` só com instrumentos
  de turmas do professor atual, e só da mesma disciplina da turma atual —
  um instrumento de outra disciplina, ou de outro professor, não aparece.
  A forma de cada instrumento importável inclui as questões com as suas
  distribuições por domínios corretas.
- **Frontend**: sem ambiente de testes automatizado (já confirmado nesta
  sessão) — verificação manual: escolher um instrumento a importar preenche
  título/cotação total/bónus/questões corretamente; mudar o título depois de
  importar funciona normalmente; escolher "Nenhum" ou não tocar no seletor não
  altera nada; submeter o formulário depois de importar cria o instrumento
  exatamente com as questões copiadas (e nenhuma nota de aluno, porque essas
  nunca fizeram parte da prop).
