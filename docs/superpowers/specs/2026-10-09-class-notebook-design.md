# Caderno da turma — primeira versão

**Branch:** `feat/class-notebook` · **Base:** `origin/main` 0.160.0 (`1693682f`)
**Versão alvo:** 0.161.0 · **Estado:** especificação para a primeira entrega

Um espaço privado do professor para observações e informações gerais sobre a
turma enquanto grupo — sem obrigar a associar o registo a um aluno. Substitui
os apontamentos dispersos noutros suportes por um caderno simples: abrir →
adicionar registo → escrever → guardar.

## 1. O que já existe (e não se recria)

| Peça | Uso aqui |
|---|---|
| `SchoolClass` (`classes`) — já pertence a um `academic_year_id` | O registo liga-se à **turma**; o ano letivo vem da turma. Não se duplica `academic_year_id`. |
| `SchoolClassPolicy::view` (`teaches`: qualquer papel em `class_teachers`) | Condição de acesso à turma — a mesma da página da turma e da caracterização. |
| `BelongsToOrganization` + `HasUlids` | Isolamento por organização e ULID no URL. |
| `ClassCharacterisation` (resumo único da turma) | **Não é tocada.** O caderno é outra coisa: vários registos datados, privados. |
| `RefusesDuringImpersonation` | Escritas recusadas durante uma sessão de acesso técnico. |
| `SchoolClassHistory::RELATIONS` | A tabela nova entra aqui: bloqueia a eliminação definitiva da turma. |
| Paginação do diretório de alunos (`paginate()->withQueryString()` + `links`) | Mesmo padrão. |
| Guarda de alterações por gravar de `classes/Characterisation.vue` | Mesmo padrão (`beforeunload` + `router.on('before')`). |
| `onHttpException` / `onNetworkError` de `lessons/Index.vue` | Mesmo padrão para falhas de gravação: o texto fica, nova tentativa possível. |

## 2. Dados — `class_notebook_entries`

| Coluna | Tipo | Notas |
|---|---|---|
| `id` | bigint PK | |
| `ulid` | ulid, único | Chave de rota. |
| `organization_id` | FK `organizations`, RESTRICT | Global scope do tenant. |
| `class_id` | FK `classes`, RESTRICT | A turma (e, por ela, o ano letivo). |
| `author_id` | FK `users`, RESTRICT | Quem criou — o único que alguma vez o lê. Nunca muda. |
| `title` | varchar(160), nulo | Opcional. |
| `body` | text, não nulo | Obrigatório. Limites: 20 000 caracteres **e** 65 535 bytes (o mesmo duplo limite de `ResultsAnalysisNote`). |
| `is_pinned` | boolean, default false | Fixar não é editar: não toca em `edited_at` nem em `lock_version`. |
| `lock_version` | unsigned int, default 0 | Bloqueio otimista do conteúdo (dois separadores do mesmo professor). |
| `edited_at` | datetime, nulo | Última alteração **do conteúdo** (título/registo). `null` = nunca editado. |
| `created_at`, `updated_at` | timestamps | `created_at` ordena. `updated_at` é técnico (muda também ao fixar). |
| `deleted_at` | soft delete | Ver §5. |

Índice `class_notebook_entries_listing_idx` em `(class_id, author_id, is_pinned, created_at)` —
serve a listagem e a FK de `class_id`.

Migração `2026_11_17_000100_create_class_notebook_entries_table` (a sequência
monótona do repositório: o topo era `2026_11_16_000100`). `down()` apaga a
tabela. Sem CHECK, sem colunas geradas, sem backfill: não toca em nenhum
registo existente.

## 3. Ordenação, pesquisa, paginação

- **Ordem:** `is_pinned desc`, `created_at desc`, `id desc`. Dentro de cada
  grupo a data de **criação** manda — editar um registo antigo nunca o promove
  a recente.
- **Pesquisa** `?q=`: `title LIKE %q% OR body LIKE %q%`, com `%`, `_` e `\`
  escapados, **sempre** dentro de `class_id = turma AND author_id = eu` (e do
  tenant). Em MySQL a colação `utf8mb4_unicode_ci` torna-a insensível a
  maiúsculas e acentos.
- **Paginação:** 20 por página, `withQueryString()`. Uma página para lá da
  última (por exemplo, depois de eliminar o último registo da página 3)
  redireciona para a última página que existe.

## 4. Privacidade e autorização — no servidor, em todas as operações

Rotas dentro do grupo `module:classes` (todos os planos que têm Turmas — sem
impacto na composição comercial):

| Método | URL | Nome |
|---|---|---|
| GET | `classes/{class}/notebook` | `classes.notebook.index` |
| POST | `classes/{class}/notebook` | `classes.notebook.store` |
| PUT | `classes/{class}/notebook/{notebookEntry}` | `classes.notebook.update` |
| PATCH | `classes/{class}/notebook/{notebookEntry}/pin` | `classes.notebook.pin` |
| DELETE | `classes/{class}/notebook/{notebookEntry}` | `classes.notebook.destroy` |

As rotas com `{notebookEntry}` usam `scopeBindings()` através de
`SchoolClass::notebookEntries()`: um registo de outra turma, mesmo meu, dá 404.

Em cada pedido, por esta ordem:

1. **Tenant** — global scope. Uma turma ou um registo de outra organização não
   resolve: 404.
2. **Turma** — `Gate::authorize('view', $class)`: só quem tem a turma em
   `class_teachers` (qualquer papel). Um colega da mesma organização que não
   ensina a turma: 403, como na página da turma.
3. **Autoria** — `ClassNotebookEntryPolicy` (`view`/`update`/`delete`):
   `author_id === user.id`, senão `Response::denyAsNotFound()`. Um colega da
   **mesma turma** recebe 404 — nem a existência do registo se confirma.
4. **Listagem e contagens** — sempre `where author_id = user.id`. Nunca há uma
   consulta ao caderno sem autor.
5. **Escritas** (`store`, `update`, `pin`, `destroy`) — `refuseDuringImpersonation()`,
   no `authorize()` dos Form Requests (antes da validação) e de novo no
   controlador.

**Suporte técnico — DECIDIDO a 2026-10-10.** Durante um apoio pedido, quem
presta assistência técnica **lê** o caderno como o professor o vê (lista,
pesquisa, paginação, contagem no cartão da turma), nos termos do Acordo de
Tratamento de Dados, e **nunca o altera**: criar, editar, fixar, desafixar e
eliminar são recusados com 403 — também com dados inválidos, sem mensagem de
validação —, e a exportação e a importação de dados também recusam a sessão
de suporte, pelo que o caderno não sai por aí. **O ecrã não tem indicação de
privacidade** (0.161.1, decisão do utilizador): tudo no caderno é privado, e o
acesso do suporte é o de toda a conta, não uma particularidade do caderno. Na
0.161.0 dizia «Privado — acessível ao suporte durante o apoio técnico.».
Testes: `an_impersonation_session_can_read_the_whole_notebook` e
`an_impersonation_session_can_never_write_to_the_notebook`
(`ClassNotebookTest`), e o modo só de leitura do ecrã em `Notebook.test.ts`.

Alunos e encarregados de educação não têm conta na aplicação — não há caminho
nenhum até estas rotas.

**Sem `audit_events`.** A «Auditoria da organização» é lida pelo responsável da
organização; um evento «registo criado no caderno da 7.º A» expunha-lhe a
existência e o ritmo dos registos privados de outro professor. A
rastreabilidade fica na própria linha (`author_id`, `created_at`, `edited_at`,
`deleted_at`), que só o autor lê (e o suporte durante um apoio, sem poder alterar).

## 5. Eliminação, arquivo, ano letivo

- **Eliminar** pede confirmação (diálogo) e faz **soft delete** — a política
  que o projeto já aplica aos registos pedagógicos (`docs/domain-model.md`:
  «retirar da interface ativa sem eliminar», como `evidence_records`). Não há
  «recuperar» na interface, tal como não há para os outros registos.
- **Turma arquivada** — arquivar só tira a turma das listas por omissão e não
  congela nada (`ArchiveSchoolClass`). O caderno continua igual para o autor:
  consultar, pesquisar e escrever. O ecrã diz que a turma está arquivada.
- **Eliminação definitiva da turma** — `class_notebook_entries` entra em
  `SchoolClassHistory::RELATIONS` («registos do caderno da turma»): bloqueia,
  inclusive numa turma em preparação. A FK é RESTRICT e conta também linhas
  eliminadas (soft delete), como em `evidence_records`. A cascata faria um colega
  dono da turma eliminar os meus registos — exatamente o que o caderno promete
  que não acontece.
- **Outro ano letivo / outra turma** — nada é copiado. Uma turma nova do ano
  seguinte, com o mesmo nome, começa com o caderno vazio.

## 6. Fora desta versão (deliberadamente)

Partilha com colegas, anexos, categorias, notificações, tarefas, IA, inclusão
em relatórios ou na caracterização, alterações aos registos individuais dos
alunos. E ainda:

- **Backup / exportação** — ~~não viaja~~ **viaja desde o schema v14**, ver §9.
- **Encerramento de conta** — as linhas ficam, como todo o conteúdo
  (`AnonymiseClosedAccount` apaga identidades, não conteúdo); deixam de ser
  legíveis por quem quer que seja, porque só o autor as lia.

## 7. Interface

**Página da turma (`classes/Show.vue`)** — um cartão «Caderno da turma» logo a
seguir ao da caracterização pedagógica, **sempre visível** (o caderno não
precisa de alunos): texto de apoio, a
contagem dos *meus* registos e o botão «Abrir caderno». Sem entrada no menu
principal.

**Página do caderno (`classes/Notebook.vue`)**, `max-w-3xl`:

- Cabeçalho: «Caderno da turma», a turma (rótulo · disciplina · ano letivo),
  «Regista e consulta observações, informações gerais e assuntos a acompanhar
  sobre a turma.», «Voltar à turma», e o
  botão principal «Adicionar registo».
- **Compositor** (no topo, abre com «Adicionar registo»): «Título» (opcional) e
  «Registo» (textarea, foco automático, parágrafos preservados). «Guardar»
  (também Ctrl/⌘+Enter) e «Cancelar».
  - Registo vazio ou só espaços: mensagem no campo, sem pedido; o servidor
    recusa na mesma.
  - A gravar: botão desativado com «A guardar…».
  - Sucesso: o compositor fecha, toast «Registo guardado.».
  - Erro de validação: mensagem junto ao campo; o texto fica.
  - Falha de rede ou do servidor: «Não foi possível guardar: … O teu texto
    continua aqui — tenta outra vez.»; o texto fica e «Guardar» volta a tentar.
  - Cancelar sem alterações fecha; com alterações pergunta antes de descartar.
- **Lista**: fixados primeiro, depois do mais recente para o mais antigo. Cada
  registo: título (se houver), conteúdo com quebras de linha preservadas e
  palavras longas partidas, «Criado em …» e «Editado em …» (se houver),
  marca «Fixado». Ações com alvos de 44 px: Fixar/Desafixar, Editar, Eliminar.
  - Editar transforma o cartão no mesmo formulário; envia `lock_version`. Se o
    registo mudou noutro separador, o servidor recusa e o texto fica.
  - Eliminar abre um diálogo de confirmação («Eliminar registo?»).
- **Pesquisa** (só quando há registos): campo «Pesquisar no caderno…», Enter
  pesquisa, «Limpar pesquisa». Sem resultados: «Nenhum registo corresponde a
  «…».».
- **Vazio**: «Ainda não tens registos neste caderno.» com «Adicionar registo».
- **Sair com alterações por guardar** (link, paginação, pesquisa, fechar o
  separador) pergunta antes. As escritas da própria página (guardar, fixar,
  eliminar) preservam o estado e não perguntam.

## 8. Validação

- PHPUnit: criação com/sem título, parágrafos, vazio/espaços/NBSP recusados,
  limites, edição (ordem estável, `edited_at`, `lock_version`, conflito),
  fixar/desafixar (ordem, sem `edited_at`), eliminação (soft delete, some da
  lista), pesquisa (título/conteúdo, só o meu caderno nesta turma, curingas
  escapados), paginação, privacidade (colega da mesma turma 404 em tudo;
  colega fora da turma 403; outra organização 404; registo meu por URL de outra
  turma 404), arquivo (consultável e preservado), ano seguinte vazio,
  impersonação (lê, não escreve), bloqueio da eliminação definitiva da turma,
  contagem no cartão da turma só com os meus.
- Vitest da página.
- Browser (Playwright, SQLite isolada, dados fictícios): fluxo completo em
  1280 px e 375 px, capturas.
- Migração provada em MySQL 8.0.43 (migrate → estrutura → rollback → migrate).

## 9. Backup e restauro — schema v14

O caderno entra no backup exportável (`backup-lapis.json` e
`Exportacao-Lapispro.xlsx`) com uma versão de formato nova: `CURRENT = 14`. Um
backup v2–v13 continua legível e simplesmente não traz a coleção (`?? []`):
zero registos restaurados, nunca um erro, e nada é apagado no destino.

### 9.1 O que sai — e de quem

`class_notebook_entries`, uma linha por registo:

| Campo | Notas |
|---|---|
| `ulid` | identidade |
| `class_ulid` | a turma; o ano letivo vem da turma (`classes[].academic_year`) |
| `author_email` | o autor — por construção, quem exporta |
| `title` | `null` quando não há título |
| `body` | texto tal como está, parágrafos incluídos |
| `is_pinned` | booleano |
| `created_at`, `updated_at`, `edited_at` | ISO 8601; `edited_at` `null` = nunca editado |

**Nunca sai:** `id`, `organization_id`, `class_id`, `author_id`,
`lock_version`, `deleted_at`.

**Âmbito:** `author_id = quem exporta` **e** `class_id ∈ turmas que ensina`
(`class_teachers`), dentro da organização atual, **sem os eliminados** (o
*scope* do `SoftDeletes`, como os `evidence_records`). Um colega da mesma turma,
o responsável da organização ou outra organização nunca obtêm o caderno de
outro professor pela exportação: cada um só exporta o seu. Folha «Caderno da
turma» no XLSX (ano letivo, turma, título, registo, fixado, criado em, editado
em) e uma linha no «Resumo».

### 9.2 Validação (`ValidateBackupPayload`)

Lista branca: as nove chaves acima. Recusas, cada uma com uma frase fixa e
**sem nunca repetir título ou texto**: identificação ou turma inválidas; texto
em falta ou só espaços (a mesma regra do formulário, NBSP e espaço de largura
zero incluídos); texto acima de 20 000 caracteres ou 65 535 bytes
(`ClassNotebookEntry::bodyLimitViolation()`); título que não é texto ou passa
de 160 caracteres (vazio ⇒ `null`); `is_pinned` que não é booleano (ausente ⇒
`false`); data malformada; `ulid` repetido no mesmo ficheiro (fica o primeiro).

### 9.3 Plano (`BuildClassNotebookEntriesPlan`) — por esta ordem

1. **Autor primeiro.** `author_email` tem de ser o de quem confirma
   (`resolveAuthor()`, sem distinguir maiúsculas). Se não for, a linha é
   `invalid` — «escrito por outra conta; o caderno é privado de quem o
   escreve» — **antes de qualquer consulta ao destino**. É a diferença para o
   resto do backup, onde um autor por resolver fica a `null` e a linha entra:
   aqui a autoria é o que decide quem lê, e um registo sem autor ou atribuído a
   quem importa seria transferir texto privado para outra pessoa.
2. **Mesmo `ulid` no destino** (mesma organização, **incluindo eliminados**):
   - de outro autor ⇒ `invalid`, frase neutra — sem comparar conteúdo, para
     não servir de oráculo;
   - eliminado ⇒ `conflict` — «foi eliminado do caderno depois da exportação e
     não é reposto». Eliminar foi uma decisão do professor; o restauro não a
     desfaz nem cria um duplicado;
   - noutra turma ⇒ `conflict` (nunca reassociado);
   - título, texto e fixação iguais ⇒ `existing`; diferentes ⇒ `conflict`
     (a versão do destino nunca é sobrescrita).
3. **Turma**: tem de ser `new` ou `existing` neste mesmo plano; senão
   `invalid`.
4. **Clonagem idempotente**: numa turma `existing`, um registo do mesmo autor,
   com o mesmo `created_at`, título e texto (eliminados incluídos) ⇒
   `existing` (ou `conflict` se eliminado). Assim reimportar um backup clonado
   com `ulid` novo não duplica.
5. Caso contrário ⇒ `new`, com `preserve_ulid = false` se o `ulid` existir
   noutra organização.

As linhas do plano usam `entry_title`/`entry_body`, nunca `title`/`label`/`name`
— a pré-visualização rotula linhas por esses campos, e um título nunca deve
aparecer em «Pontos a rever». Linhas `invalid`/`conflict`/`existing` não levam
título nem texto.

### 9.4 Escrita (`WriteClassNotebookEntries`)

Só `new`. `author_id` = quem confirma (já provado no plano); `class_id` pelo
mapa de turmas `new ∪ existing`; título, texto, fixação e as três datas tal
como estavam (normalizadas para o fuso da aplicação, como em
`WriteResultsAnalysisNotes`); `lock_version = 0` (o contador recomeça nesta
instalação); `deleted_at` nulo. `class_notebook_entries_created` no resumo.

### 9.5 Notas

- Numa organização institucional, uma turma criada pelo restauro fica sem
  professor (regra existente); os registos ficam guardados e o autor vê-os
  quando voltar a ser professor da turma.
- O texto fica em `data_imports.canonical_snapshot` até a importação ser
  podada, como qualquer outra coleção (`PruneDataImports`); só quem pediu a
  importação a vê.
- O dump diário da base (`scripts/backup-database.sh`) é integral, sem lista
  de tabelas: a tabela nova entra sem alteração ao script (provado em MySQL
  8.0.43 com o próprio script).
