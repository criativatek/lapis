# Apreciação qualitativa por instrumento — desenho

## Contexto

A grelha de correção de um instrumento (`resources/js/pages/instruments/Grid.vue`)
mostra hoje, por aluno, só a soma de pontos (`17`) sobre o total do instrumento
(`/20`) — nunca uma percentagem, e nunca nenhuma apreciação qualitativa
("Fraco", "Bom", etc.).

Já existe, noutro sítio da aplicação, um mecanismo completo para converter uma
percentagem numa apreciação qualitativa: as bandas de uma `Scale` (commit
`09eb31e`, `ScaleLevel.band_min_normalized`/`band_max_normalized`), hoje usadas
só pelo motor de cálculo (`CalculationEngine::combine()`) para o **resultado
final da turma/período** — nunca para um instrumento isolado. Este documento
estende essa mesma conversão a cada instrumento, sem alterar nem duplicar as
bandas já decididas.

## Regras decididas

1. **A escala usada é sempre a do perfil de avaliação da turma** (a mesma já
   associada a `assessment_profile_versions.scale_id`, carregada hoje por
   `ClassResultsCalculator::forScope()`) — nunca uma escala escolhida por
   instrumento. Isto é o que garante que seja "transversal a todos os
   instrumentos da turma", tal como pedido — e também o que faz uma escola
   diferente, com uma escala personalizada diferente, ver rótulos diferentes
   sem qualquer mudança de código.
2. **Sem invenção de bandas novas.** Os rótulos e limites são exatamente os
   que já estão configurados nessa escala (ex.: "Escala 1 a 5" já tem Fraco
   0–19%, Insuficiente 20–49%, Suficiente 50–69%, Bom 70–89%, Muito Bom
   90–100%, decididos anteriormente). Este documento não muda nenhum limite
   nem nenhum rótulo já existente.
3. **Quando a escala da turma não tem bandas configuradas** (ex.: "Escala 0 a
   20", "Percentagem", ou uma escala personalizada sem bandas) **ou a turma
   ainda não tem perfil de avaliação associado**: a coluna de percentagem
   continua a aparecer, mas a coluna de apreciação qualitativa fica em branco
   ("—") para essa turma — mesmo comportamento de fallback já decidido
   noutro sítio (§13 Q1), agora só estendido a este novo local.
4. **A percentagem calcula-se só sobre as perguntas já corrigidas até ao
   momento**, nunca sobre o total do instrumento — consistente com a regra já
   estabelecida "vazio não é zero" (CLAUDE.md, §13.3): uma pergunta ainda por
   corrigir não entra no denominador, nem como zero. a apreciação qualitativa
   correspondente **aparece sempre, mesmo com correção parcial**, e
   atualiza-se à medida que mais perguntas são corrigidas.
5. Pontos de bónus (`is_bonus`) somam ao numerador mas não ao denominador —
   a percentagem pode ultrapassar 100%. Isto replica o tratamento de bónus já
   existente no motor de cálculo para o resultado combinado (a implementação
   deve confirmar e espelhar exatamente essa mesma convenção, não inventar
   uma nova).

## Onde aparece

Na grelha (`Grid.vue`), a coluna "Total" existente passa a mostrar também a
percentagem (ex.: `17/20 · 85%`), e uma **coluna nova, imediatamente à
direita, chamada "Apreciação Qualitativa"**, mostra só o rótulo (ex.: "Bom"),
ou "—" quando não há bandas aplicáveis (regra 3).

## Backend

`InstrumentController::show()` (o método que já alimenta `Grid.vue`) passa a
carregar, tal como `ClassResultsCalculator::forScope()` já faz, as bandas da
escala do perfil de avaliação da turma:

```php
$scaleBands = $instrument->schoolClass->profileVersion?->scale
    ?->levels()
    ->whereNotNull('band_min_normalized')
    ->whereNotNull('band_max_normalized')
    ->orderBy('sequence')
    ->get()
    ->map(fn ($level) => [
        'label' => $level->label,
        'band_min' => (string) $level->band_min_normalized,
        'band_max' => (string) $level->band_max_normalized,
    ])
    ->all() ?? [];
```

Enviado como uma nova prop `scaleBands: {label: string, band_min: string,
band_max: string}[]` para `instruments/Grid`. Vazio (`[]`) cobre os dois casos
da regra 3 (sem perfil associado, ou perfil com escala sem bandas) sem
distinção — o frontend não precisa de saber a razão, só que não há bandas.

Não é preciso nenhuma escrita nova na base de dados — isto é puramente
leitura e apresentação. Não há migração nesta funcionalidade.

## Frontend

Em `Grid.vue`:

- Novo `percentFor(student): number | null` — mesma lógica de "vazio não é
  zero" que `totalFor()` já usa (percorre `items`, soma `points_earned` das
  células `assessed`), mas também soma `points_possible` (exceto para itens
  `is_bonus`) só dos itens já avaliados, devolvendo
  `(pontosGanhos / pontosPossíveis) * 100` arredondado a uma casa decimal, ou
  `null` quando nada foi avaliado ainda (mesma condição de `totalFor()`).
- Novo `qualitativeLabelFor(student): string | null` — usa `percentFor()` e
  percorre `props.scaleBands` à procura da banda cujo intervalo (inclusive
  dos dois lados, tal como `CalculationEngine::combine()` já faz em PHP)
  contém a percentagem; devolve o `label` da banda encontrada, ou `null` se
  `scaleBands` estiver vazio ou nenhuma banda bater certo.
- A célula "Total" existente ganha a percentagem: `{{ totalFor(student)
  }}/{{ instrument.total_points }} · {{ percentFor(student) }}%` (só quando
  `totalFor(student) !== null`, mantendo o "—" já existente quando nada foi
  avaliado).
- Nova coluna "Apreciação Qualitativa" (cabeçalho `<th>` + célula `<td>` por
  aluno), mostrando `qualitativeLabelFor(student)` ou "—" quando `null`.
- O indicador "parcial" já existente mantém-se inalterado — a percentagem e a
  apreciação aparecem **ao lado** dele, não o substituem; o professor continua
  a ver quando a correção ainda não está completa.

## Testes

- **Feature** (`InstrumentControllerTest` ou equivalente): `show()` envia
  `scaleBands` corretamente quando a turma tem perfil com escala com bandas;
  envia `[]` quando a turma não tem perfil associado; envia `[]` quando a
  escala associada não tem bandas configuradas (ex. "Escala 0 a 20").
- **Frontend/lógica** (se este projeto tiver testes de componente Vue para
  `Grid.vue` — confirmar a convenção existente; caso contrário, cobrir a
  lógica de `percentFor`/`qualitativeLabelFor` via um teste de feature que
  invoca `show()` com cenários completos e parciais e verifica os valores
  enviados, já que a lógica de apresentação em si vive no componente): pontos
  bónus somam ao numerador sem entrar no denominador; percentagem parcial
  calculada só sobre os itens já avaliados; apreciação correta para uma
  percentagem exatamente numa fronteira de banda (ex. 19.5% exatos); "—"
  quando não há bandas aplicáveis.
