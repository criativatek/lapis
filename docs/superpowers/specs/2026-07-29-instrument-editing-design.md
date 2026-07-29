# Edição de instrumentos de avaliação — desenho

## Contexto

Hoje só é possível **criar** um instrumento de avaliação (`instruments/Create.vue` →
`InstrumentController::store()` → `InstrumentBuilder::create()`). Não existe
nenhuma forma de editar um instrumento já criado — nem as suas perguntas
(`instrument_items`), nem os pesos/cotações (`points_possible`), nem os
metadados do próprio instrumento (título, `total_points`, `weight`, etc.).

Existe já um método `InstrumentBuilder::update()` (`app/Services/Assessment/InstrumentBuilder.php`,
linhas 44-60) — mas está morto: nenhum controller/rota/frontend o invoca, não
tem testes, e a sua própria implementação apaga TODAS as perguntas do
instrumento e recria-as do zero. Isto falha com um erro de base de dados assim
que qualquer nota já tiver sido lançada, porque `student_item_scores` tem uma
FK `ON DELETE RESTRICT` para `instrument_items`. O comentário no próprio código
("ponytail" = hack temporário conhecido) já assinalava isto como um problema
por resolver.

Este documento substitui esse método morto por uma atualização real, baseada
em diferença (diff), e acrescenta em simultâneo o fluxo de anulação/reversão
de um instrumento — pedido explicitamente para entrar no mesmo âmbito.

## Regras decididas (não inventadas — confirmadas com o dono do produto)

1. **Um instrumento é sempre editável, mesmo depois de já ter notas lançadas.**
   Perguntas, pesos e metadados podem mudar a qualquer momento; os resultados
   recalculam-se automaticamente a partir da versão atual. Se já existir uma
   classificação confirmada/publicada que dependia dos números antigos, essa
   classificação fica dessincronizada da nova versão **sem aviso** — aceite
   explicitamente, não é tratado por este desenho.
2. **Remover uma pergunta só é possível enquanto ela não tiver nenhuma nota
   lançada.** Uma pergunta com pelo menos uma nota em `student_item_scores`
   fica bloqueada para remoção; o professor tem de limpar essas notas na
   grelha primeiro.
3. **A cotação (`points_possible`) de uma pergunta não pode ser reduzida
   abaixo da maior nota já lançada nela.** Evita um resultado a mostrar mais
   de 100%.
4. **Anulação de instrumento entra neste âmbito.** Anular exige motivo
   obrigatório; enquanto anulado, o instrumento fica só-leitura (sem editar
   perguntas/pesos/metadados, sem lançar notas). É **reversível**: uma ação
   "Reverter anulação" repõe o estado anterior e o instrumento volta a ficar
   editável/pontuável — o ciclo anular→reverter→editar→anular pode repetir-se
   à vontade.
5. **Autorização inalterada.** Continua a usar-se
   `Gate::authorize('update', $instrument->schoolClass)` — qualquer professor
   atribuído à turma, sem distinção adicional. Não é criada nenhuma
   `InstrumentPolicy` nova.

### Fora de âmbito (explicitamente, para não voltar a ser confundido depois)

- Mudar o `scoring_mode` de uma pergunta já pontuada não tem uma regra
  especial própria — segue a regra geral (sempre editável); não se bloqueia
  por si só, ao contrário da remoção e da redução de `points_possible`.
- Não existe limite ao número de vezes que um instrumento pode ser
  anulado/revertido.
- Não se persiste nenhum histórico de "quem editou o quê" além do que já
  existe (auditoria genérica, se houver) — este documento não acrescenta
  auditoria específica de edições de instrumento.

## Alterações ao modelo de dados

Nova coluna em `instruments`, nullable: `status_before_cancellation VARCHAR(16)`.
Guarda o `status` no momento da anulação, para a reversão o poder repor
exatamente (não se deriva por heurística — não há forma fiável de adivinhar
se um instrumento anulado estava em `prepared`, `in_correction`, `completed`,
etc.). É `NULL` sempre que o instrumento não está anulado.

## Backend

### `InstrumentBuilder::update()` — reescrito como diff real

Substitui o "apagar tudo e recriar" por uma transação que:

1. Atualiza os atributos do próprio instrumento.
2. Para cada pergunta **submetida com `ulid`** (uma pergunta existente que se
   mantém, possivelmente editada): atualiza `label`, `points_possible`,
   `scoring_mode`, `is_bonus`, `code`; substitui por completo as suas
   `item_domain_allocations` (apagar+recriar é seguro aqui — nada tem uma FK
   `RESTRICT` sobre alocações).
3. Para cada pergunta **submetida sem `ulid`**: cria-a de novo (com as suas
   alocações), tal como em `create()`.
4. Para cada pergunta **existente cujo `ulid` NÃO está no conjunto submetido**
   (candidata a remoção): antes de apagar seja o que for, verifica se tem
   alguma linha em `student_item_scores`. Se **qualquer** candidata a remoção
   tiver notas, a atualização inteira é rejeitada — nada é apagado, nada é
   parcialmente aplicado — com um erro que identifica a(s) pergunta(s) em
   causa (por `code`/`label`).
5. Para cada pergunta a atualizar cujo `points_possible` está a **descer**:
   verifica a maior nota já lançada nela (`MAX(points_earned)` em
   `student_item_scores` para esse item); se o novo valor for inferior,
   rejeita com um erro identificando a pergunta e o valor mínimo aceitável.
6. Corre `guard()` (já existente, inalterado) sobre o conjunto final de
   perguntas — soma de alocações por domínio a 100%, soma de `points_possible`
   a bater com `total_points` quando definido.

Todas as verificações de rejeição (passos 4 e 5) correm **antes** de qualquer
escrita na base de dados — a validação é completa primeiro, a transação só
aplica mudanças depois de tudo aprovado.

### Rotas e controller

```php
Route::get('instruments/{instrument}/edit', [InstrumentController::class, 'edit'])->name('instruments.edit');
Route::put('instruments/{instrument}', [InstrumentController::class, 'update'])->name('instruments.update');
Route::post('instruments/{instrument}/cancel', [InstrumentController::class, 'cancel'])->name('instruments.cancel');
Route::post('instruments/{instrument}/revert-cancellation', [InstrumentController::class, 'revertCancellation'])->name('instruments.revert-cancellation');
```

- `edit()`: `Gate::authorize('update', $instrument->schoolClass)`; rejeita
  (redireciona com erro) se `status === cancelled` — instrumento anulado é
  só-leitura. Renderiza `instruments/Edit` com os mesmos dados que `Create`
  usa mais a lista de perguntas atuais, cada uma com o seu `ulid` e um novo
  campo calculado `has_scores: bool`.
- `update()`: mesma autorização e mesmo bloqueio por `cancelled`; usa
  `InstrumentRequest` (ver abaixo); chama `InstrumentBuilder::update()`;
  erros de validação/rejeição voltam via `back()->withErrors(...)`.
- `cancel()`: valida `reason` (string, obrigatório); rejeita se já estiver
  `cancelled`; grava `status_before_cancellation = status atual`, depois
  `status = cancelled`, `cancelled_at = now()`, `cancelled_by = utilizador
  atual`, `cancellation_reason = reason`.
- `revertCancellation()`: rejeita se não estiver `cancelled`; repõe
  `status` a partir de `status_before_cancellation`; limpa
  `cancelled_at`/`cancelled_by`/`cancellation_reason`/`status_before_cancellation`
  para `null`.
- `saveScores()` (já existente): ganha uma guarda nova — rejeita
  (`back()->withErrors(...)`) se `status === cancelled`.

### `InstrumentRequest`

Já é partilhado por criação e edição (sem regras diferentes por modo, como já
está hoje). Ganha `items.*.ulid` (nullable, string; quando presente tem de
pertencer a este instrumento — validado via regra `exists`/customizada
equivalente ao padrão `BelongsToCurrentOrganization` já usado no resto do
projeto).

## Frontend

- **`InstrumentForm.vue`** (novo, extraído da lógica de formulário hoje em
  `Create.vue`) — replica o padrão já existente em
  `resources/js/pages/assessment-profiles/ProfileForm.vue`, partilhado por
  `Create.vue` e `Edit.vue`.
- **`Create.vue`**: passa a ser um invólucro fino à volta de
  `InstrumentForm.vue` (modo criação) — sem mudança de comportamento visível.
- **`Edit.vue`** (novo): invólucro fino à volta de `InstrumentForm.vue` (modo
  edição), recebendo o instrumento atual e as suas perguntas (cada uma com
  `ulid`, valores atuais, e `has_scores`).
- Em modo edição, cada linha de pergunta existente com `has_scores: true` tem
  o botão de remover **desativado**, com texto explicativo (tooltip) — evita
  que o professor só descubra o bloqueio depois de submeter.
- **`Grid.vue`** ganha:
  - Um link "Editar instrumento" (para `instruments/{ulid}/edit`) — oculto
    enquanto `status === cancelled`.
  - Um botão "Anular instrumento" que abre um diálogo pequeno a pedir o
    motivo (obrigatório) e a confirmar antes de submeter — oculto enquanto já
    `cancelled`.
  - Quando `status === cancelled`: mostra um aviso claro ("Instrumento
    anulado — motivo: ...") e um botão "Reverter anulação"; todos os campos
    de nota ficam desativados (a grelha já não aceita edição).

## Testes

- **Unidade** (`InstrumentBuilder::update()`): pergunta mantida/editada;
  pergunta nova adicionada; pergunta sem notas removida com sucesso; pergunta
  com notas rejeitada na remoção (nada apagado); descer `points_possible`
  abaixo da nota existente rejeitado (nada alterado); `guard()` continua a
  aplicar-se (soma de alocações, soma de pontos).
- **Feature**: fluxo completo de editar um instrumento sem notas (livre);
  editar um instrumento com notas (adicionar pergunta funciona; subir
  cotação de pergunta pontuada funciona; as duas rejeições specificadas
  devolvem erro de validação, não 500); anular exige motivo e bloqueia
  `edit`/`update`/`saveScores`; reverter só funciona enquanto `cancelled` e
  repõe o estado anterior exato; autorização (professor não atribuído à
  turma não pode editar/anular/reverter — mesmo padrão dos testes já
  existentes para `SchoolClassPolicy`).
