# Pesquisa nos sumários e reorganização de «Aulas e Sumários» — desenho

2026-10-11 · versão 0.162.0 · branch `feat/lessons-summary-search`

## O pedido

Encontrar os registos de um conteúdo nos sumários («em que aulas dei frações
equivalentes?»), para preparar aulas e elementos de avaliação — e, porque a
página já tem muitos comandos, arrumá-la por função ao mesmo tempo.

## O que se pesquisa — e o que não

- **Só `lesson_summaries.content`**, o texto do sumário. Nunca `private_notes`,
  `resources`, `homework` nem o plano da aula: não são o sumário e as notas
  privadas não podem aparecer como se fossem.
- **Todo o ano letivo selecionado** (o do contexto global), em todas as turmas
  que o professor leciona nesse ano — incluindo as arquivadas, que continuam a
  ser dele. A semana do calendário não entra na pesquisa.
- **No servidor.** O browser só tem a semana aberta; pesquisar aí seria mentir
  sobre o âmbito.

## Correspondência

Uma função só, `SummarySearchText`, decide o que corresponde, quantas vezes e
onde destacar — filtro, contagem e destaque nunca divergem.

- Normalização carácter a carácter: minúsculas; letras latinas acentuadas → a
  letra base (`ç`→`c`, `ã`→`a`…); marcas combinantes e caracteres de
  formatação invisíveis removidos (texto decomposto vale o mesmo que o
  composto; o hífen opcional e o espaço de largura zero colados de um Word ou
  PDF não partem palavras); apóstrofos tipográficos → `'`; qualquer sequência
  de espaços, tabulações e quebras de linha → um espaço.
- **Sem `intl`.** A extensão não está declarada no `composer.json` nem é usada
  pelo código, e o resultado não pode depender de ela existir no servidor.
- Uma expressão com várias palavras procura **essa sequência exata**, por esta
  ordem — também quando o professor partiu a linha entre as palavras.
- A correspondência é **«contém»** (como o Ctrl+F): `fraç` encontra «frações».
- As aspas à volta da expressão («», "", “”) são ignoradas: a procura já é a
  da expressão exata.
- Mínimo de 2 caracteres depois de normalizar; máximo de 200 no pedido.
- `%`, `_`, `\` e HTML são texto: a correspondência é feita em PHP
  (`str_contains`/`substr_count`), nunca num `LIKE`, e o destaque viaja como
  segmentos de texto, nunca como HTML.

### Porque não um `LIKE` nem uma coluna normalizada

- `LIKE` com `utf8mb4_unicode_ci` ignora acentos em MySQL mas não em SQLite —
  os testes locais não provariam a regra — e não junta palavras separadas por
  uma quebra de linha. Também aceita equivalências que a contagem e o destaque
  não veriam (`º` = `o`), o que daria resultados com «0 ocorrências».
- Uma coluna normalizada obrigava a uma migração com preenchimento de todas as
  linhas existentes, a um caminho de escrita que nunca a deixasse desatualizada
  e a decidir o seu lugar no backup.
- O universo de uma pesquisa é limitado: as aulas COM sumário de UM professor
  num ano letivo (na ordem do milhar). O servidor lê `id` + `content` desse
  universo já filtrado (turmas, datas, estado) e decide em memória. Se um dia
  isto deixar de chegar, o passo seguinte é a coluna normalizada — com a mesma
  função a preenchê-la.

## Estado da aula na pesquisa

Os estados que existem (`lessons.status` + `lessons.outcome`, 0.146.0):

| Filtro | Regra |
|---|---|
| Lecionadas | `outcome = taught`, ou `outcome` nulo e `status = taught` (`Lesson::isTaught`) |
| Preparadas | `outcome` nulo e `status` ≠ `taught` — abertas, por lecionar |
| Outras ocorrências | `outcome` ∈ {`teacher_absent`, `class_external_activity`} |

O ecrã oferece **Lecionadas · Preparadas · Ambas** sempre à vista, e «Incluir
professor ausente e outras atividades» em «Mais filtros». **Por omissão,
Lecionadas** — a última escolha fica lembrada neste browser.

Encontrar uma expressão numa aula preparada **não confirma** que foi dada: o
cartão mostra sempre a pílula de estado, e o cabeçalho di-lo quando as
preparadas estão incluídas. A ausência de resultados diz que não há sumários
com aqueles critérios — nunca que o conteúdo não foi lecionado.

## API — `GET /lessons/search` (`lessons.search`, `module:lessons`)

Só leitura, JSON. Pedido: `q`, `classes` (ULIDs separados por vírgulas),
`states` (`taught,prepared,other`; por omissão `taught`), `from`, `to`
(`Y-m-d`, dias locais de Lisboa), `page`.

- **Autorização:** a mesma regra da semana (`LessonRowBuilder::accessible`) —
  aulas das turmas que ESTE professor leciona no ano selecionado, dentro da
  organização atual — e, além dela, sempre a lista explícita dessas turmas
  (`whereIn class_id`), que deixa o MySQL ir pelo índice da turma. As turmas
  pedidas são a interseção com essas; só ULIDs alheios ou desconhecidos dão
  zero resultados, nunca «todas as turmas».
- `throttle:120,1`: a pesquisa corre a cada pausa na escrita, e abortar o
  pedido no browser não pára a leitura no servidor.
- **Contagens** saem do mesmo conjunto autorizado e filtrado.
- Ordem: mais recentes primeiro (`starts_at` desc, `id` desc). Cada aula é um
  resultado: T1 e T2 com o mesmo número e o mesmo texto são dois.
- 20 por página; uma página para lá da última devolve a última.

Resposta: `term`, `academic_year` e `classes` (o âmbito EFETIVO — o ano
letivo da sessão e as turmas aplicadas; se o ano mudou noutro separador, o ecrã
não mostra resultados com o âmbito errado e pede para recarregar), `total`
(aulas), `occurrences` (ocorrências em todas as aulas encontradas), `page`, `last_page`, `per_page`, `results[]` com a
identidade da aula (turma, grupo, disciplina, tom, data, número, estado) e o
sumário completo em `segments: [{text, match}]` cuja concatenação é o texto
original.

## Ecrã

Zonas, por esta ordem:

1. **Cabeçalho** — «Aulas e Sumários», o ano letivo, «Inserir aula» (ação
   principal) e «Mais ações» (Selecionar aulas, Marcar aulas como lecionadas…,
   Atualizar aulas desta semana, Sequências de aulas, sumários completos ou
   compactos).
2. **Consulta** — Semana / Por turma / Horário e o campo «Pesquisar nos
   sumários…». Durante a pesquisa, o seletor de vista dá lugar a «Voltar ao
   calendário».
3. **Filtros** — turmas à vista e «Mais filtros». Na pesquisa: turmas, estado
   (sempre à vista) e, em «Mais filtros», o período e as outras ocorrências.
4. **Navegação temporal** — Anterior / Semana atual / Seguinte, a semana e os
   factos dela, junto do calendário. Não aparece durante a pesquisa.
5. **Conteúdo** — o calendário habitual ou os resultados.

Seleção: «Selecionar aulas» passa para «Mais ações»; a barra contextual tem a
contagem, «Marcar selecionadas como lecionadas», «Limpar seleção» e «Sair da
seleção».

### Estado da pesquisa no URL

`q`, `q_classes`, `q_states` (`taught|prepared|both`), `q_other`, `q_from`,
`q_to`, `q_page` vivem POR CIMA dos parâmetros do calendário, que a pesquisa
nunca altera. Por isso:

- «Voltar ao calendário» e limpar o campo retiram só os `q*` — volta a mesma
  semana, vista e filtros de antes (e a posição de scroll).
- Abrir uma aula e regressar repõe a expressão, os filtros e a página; os
  últimos resultados ficam em `sessionStorage` para aparecerem logo, e são
  revalidados no servidor. A chave leva o utilizador, a organização e o ano
  letivo, e terminar a sessão apaga-a: num computador partilhado ninguém vê a
  resposta guardada de outra pessoa.
- A revalidação ao montar (`router.get` do próprio URL) usa `preserveUrl`:
  entrar na pesquisa ou sair dela enquanto ela corre não é desfeito pela
  resposta. Se o URL mudar por fora, o campo acompanha a expressão.
- Uma escrita feita na página durante a pesquisa (inserir uma aula, marcar
  como lecionadas) recarrega as aulas e revalida os resultados.
- A pesquisa começa com as turmas que o calendário estava a filtrar (na vista
  Por turma, a turma aberta), num filtro próprio (`q_classes`): mudar as turmas
  na pesquisa não muda o calendário.
- A semana do calendário nunca muda para a data de um resultado.

Pesquisa durante a escrita com 350 ms de pausa (Enter pesquisa logo, Escape
limpa). Cada pedido novo aborta o anterior e uma resposta que não seja a do
último pedido é ignorada. Enquanto a nova não chega, a lista anterior fica
esbatida e sem contagens; se o pedido falhar, sai.

Entrar na pesquisa SUBSTITUI a entrada do histórico (não a acrescenta):
«Voltar ao calendário» é o caminho de regresso, e acrescentar uma entrada
deixaria duas iguais no histórico depois de voltar.
