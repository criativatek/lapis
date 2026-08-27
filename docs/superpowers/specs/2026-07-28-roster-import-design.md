# Importação de lista de turma (Excel + Word) — desenho

- **Data:** 2026-07-28
- **Estado:** aprovado em brainstorming, a aguardar plano de implementação
- **Relaciona-se com:** `docs/domain-model.md` §13 Q6 ("Intuitivo import" — Excel/XLSX era o formato assumido pela spec original; nenhuma implementação existia até agora), `docs/adr/0004-pedagogical-calculation-rules.md` (Q5, mesma questão sob outro rótulo)

## Contexto

O professor tem hoje de inscrever alunos numa turma um a um (`classes/Show.vue`, formulário "Adicionar aluno" → `EnrollmentController::store()` → `StudentEnrollmentService::enrollNew()`). O Intuitivo (o sistema de gestão escolar usado pela escola) permite exportar a relação de turma em Excel (`.xls`) e as fotos dos alunos em Word (`.doc`, na prática um documento OOXML moderno apesar da extensão). Esta funcionalidade permite importar os dois de uma vez.

Esta é a primeira vez que a Questão Q6 (§13 do `domain-model.md`, "importação Intuitivo") avança: nunca tinha havido um ficheiro real para desenhar o parser contra. Os ficheiros de exemplo fornecidos durante o desenho continham dados reais e não anonimizados de 30 alunos menores de uma turma real (nomes, datas de nascimento, fotografias). Foram usados só para confirmar a estrutura de colunas e do documento — **nenhum nome, data ou foto reais entram no repositório, em testes, ou em exemplos desta especificação.** Os testes automatizados usam ficheiros de fixture com dados fictícios, criados de propósito com a mesma estrutura de colunas.

## Decisões tomadas no brainstorming

1. **Fotos ficam guardadas no Lapispro** (não é só uma ajuda visual no momento do upload — decisão A, escolhida depois de comparação lado a lado).
2. **Repetente, ASE, PLNM** entram como uma nota de texto livre por inscrição (`enrollments.import_note`), tal como vêm do ficheiro, sem afetar nenhum cálculo. **NEE nunca é lido nem guardado** — exclusão arquitetural já existente (`docs/domain-model.md` linha 186: "Sem dados de saúde, NEE ou categorias especiais... uma futura `student_support_measures` exige especificação, fundamento e proteção reforçada próprios").
3. **Formatos aceites: Excel (lista) + Word (fotos).** PDF não é suportado nesta funcionalidade — confirmado que o Intuitivo exporta ambos os ficheiros também nestes formatos, estruturalmente muito mais fiáveis de ler do que um relatório PDF impresso.
4. **Sem subsistema `import_jobs`** — nada de histórico persistente de importações nem relatório de erros gravado em tabela própria (isso ficou desenhado em `domain-model.md` §10.4/§12 mas explicitamente descartado para já, por ser um âmbito muito maior do que o pedido). O que existe é um fluxo simples: carregar → pré-visualizar → confirmar.
5. **As fotos são opcionais.** O ecrã de upload pergunta explicitamente "Queres associar fotos?" (Sim/Não) — só com Sim é que aparece o campo para o ficheiro Word. Sem fotos, a pré-visualização e a inscrição seguem na mesma, sem `photo_path`.

## Ficheiros reais inspecionados (estrutura, confirmada durante o desenho)

**Excel (`.xls`, formato binário legado BIFF, lido pela PhpSpreadsheet sem problemas):**
- Cabeçalho de colunas não está na linha 1 — está algures a meio de um relatório impresso incorporado na folha (título da escola, ano letivo, etc. antes). O parser localiza a linha de cabeçalho **por conteúdo** (procura células com "N.º MATR." e "NOME"), nunca por número de linha fixo.
- Colunas confirmadas: N.º MATR., NOME, IDADE, DATA NASC. (serial de data do Excel, não texto — usar `PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject()`), SIT., REPET., ASE, NEE (nunca lida), EMR, PLNM, N.º PROC.
- Fim dos dados: uma linha "Total Alunos - N" depois da última linha de aluno — o parser para aí, nunca tenta ler além.

**Word (`.doc`, na prática um zip OOXML):**
- Cada foto está numa célula de tabela (`<w:tc>`) como imagem VML (`<v:imagedata r:pict="...">`), e o nome/estado (ex.: "MT", "TR" a vermelho) está na célula ao lado como HTML embutido via `<w:altChunk r:id="...">` — um mecanismo de "Alternative Format Import Part" do próprio Word, não uma imagem com legenda simples.
- A correspondência foto↔nome faz-se resolvendo os `r:id`/`r:pict` contra `word/_rels/document.xml.rels`, nunca por posição/ordem assumida no ficheiro zip (que por acaso até bate certo neste exemplo, mas não é uma garantia estrutural).
- Não vale a pena usar uma biblioteca de alto nível como o PHPWord para isto — o suporte a VML e `altChunk` é marginal nessas bibliotecas. Um leitor próprio, de baixo nível (`ZipArchive` + `DOMDocument`/XPath sobre `document.xml` e o `.rels`), é mais fiável para esta estrutura específica.

## Arquitetura

```
Turma (Show.vue) → botão "Importar lista"
  ↓
Formulário: ficheiro Excel (obrigatório) + "Queres associar fotos?" (Sim/Não)
  → se Sim, campo para ficheiro Word
  ↓
POST /classes/{class}/roster-imports  (upload único)
  servidor: RosterFileParser lê o Excel → linhas normalizadas
            se houver Word: PhotoFileParser extrai pares (foto, texto do
            nome) pela estrutura da tabela do próprio documento — nunca
            por posição no ficheiro nem por semelhança de nomes. Cada
            par é depois casado com a linha do Excel cujo NOME
            corresponde ao texto extraído (os dois ficheiros vêm do
            mesmo sistema, por isso a grafia deve bater certo); um par
            sem correspondência exata fica sinalizado, nunca associado
            "pelo mais parecido"
  → guarda as fotos (se houver) num disco privado temporário,
    identificado por um token (pasta storage/app/private/roster-imports/{token}/)
  → responde com a página de pré-visualização (Inertia), token incluído
  ↓
Pré-visualização: uma linha por aluno detetado — nome, data nasc., n.º,
situação, nota (Repetente/ASE/PLNM), foto associada (se houver)
  professor revê: corrige nome, troca foto mal associada, exclui linha
  ↓
POST /classes/{class}/roster-imports/{token}/confirm
  cada linha (possivelmente editada) passa por
  StudentEnrollmentService::enrollNew() — o mesmo caminho do "Adicionar
  aluno" manual (pseudónimo, cifra do nome, blind index, tudo igual)
  → fotos confirmadas movem-se do temporário para o armazenamento
    definitivo; pasta temporária apagada por completo a seguir
  ↓
Redireciona para a turma, com um resumo (N inscritos, M ignorados)
```

Não existe tabela `import_jobs`: o "token" é só o nome de uma pasta temporária no disco (`local` disk, já não acessível pela web), com um TTL curto — nada de novo a persistir na base de dados além do que já existia, mais as duas colunas descritas abaixo.

## Alterações ao modelo de dados

| Tabela | Coluna nova | Tipo | Notas |
|---|---|---|---|
| `student_identities` | `photo_path` | `VARCHAR(255)` nullable | Só um apontador para o ficheiro no disco privado. Nunca um URL público. |
| `enrollments` | `import_note` | `VARCHAR(255)` nullable | Repetente/ASE/PLNM tal como vieram do ficheiro. Texto livre, nunca entra em nenhuma fórmula ou regra. |

`StudentEnrollmentService::enrollNew()` passa a aceitar também `birth_date` (a coluna já existe em `student_identities`, criada pelo desenho original mas nunca preenchida pelo formulário manual) e os dois campos novos acima, todos opcionais — o fluxo manual de "Adicionar aluno" continua a funcionar exatamente como hoje, sem esses campos.

Mapeamento de colunas do ficheiro:
- N.º MATR. → `enrollments.class_number`
- NOME → `student_identities.display_name` (cifrado, como já é)
- DATA NASC. → `student_identities.birth_date`
- N.º PROC. → `student_identities.school_number` (já existe, hoje morto na UI)
- SIT. (X/TR/MT/...) → `enrollments.status`, mapeado para o enum existente (`active`/`transferred_out`/...); um valor não reconhecido fica `active` com aviso na pré-visualização, nunca falha silenciosamente
- REPET. + ASE + PLNM → concatenados em `enrollments.import_note` (ex.: "Repetente · ASE: B · PLNM")
- NEE → **nunca lida**
- IDADE → não guardada (é derivável de `birth_date`; guardar as duas seria dados duplicados que podem divergir)

## Acesso às fotos

Uma foto só é servida por uma rota autenticada e autorizada (`GET /students/{student}/photo`), que verifica a policy da turma/organização e faz *stream* do ficheiro do disco privado — nunca um link direto tipo `/storage/...`. Equivalente à proteção do nome (nunca exposto em bruto), sem cifrar os bytes da imagem em repouso nesta primeira versão — possível reforço futuro, não bloqueador.

## Tratamento de erros na pré-visualização

- **Foto sem correspondência de nome** (ou vice-versa): linha sem foto, com aviso — nunca associa "a que calhar"; o professor associa/corrige manualmente.
- **Nome duplicado** (no ficheiro ou já existente na turma): linha assinalada, excluída por defeito da importação; o professor decide incluir.
- **Coluna em falta ou linha incompleta**: o campo fica vazio na pré-visualização — nunca inventa um valor.
- **Ficheiro no formato errado** (ex.: PDF em vez de Excel/Word): erro claro antes da pré-visualização.
- **Nenhuma linha detetada**: mensagem clara, sem avançar para uma pré-visualização vazia.

Nada disto bloqueia o processo a meio — o professor vê tudo e decide linha a linha antes de confirmar.

## Testes

- **Unitário**: `RosterFileParser` (mapeamento Excel→linha normalizada, localização do cabeçalho por conteúdo, tratamento de datas seriais) e `PhotoFileParser` (resolução de relacionamentos OOXML, emparelhamento nome↔foto), cada um contra pequenos ficheiros de fixture fictícios criados para o teste.
- **Feature**: fluxo completo com fixtures pequenas (3-4 alunos fictícios) — com fotos, sem fotos, upload → pré-visualização → confirmar → alunos/inscrições criados corretamente, ficheiros temporários desaparecem a seguir.
- **Feature**: autorização — outra organização/turma não consegue importar nem ver fotos de alunos que não são seus.
- **Feature**: rejeição de linha inválida (nome duplicado, ficheiro no formato errado) sem afetar as restantes linhas.

As fixtures de teste são geradas de propósito (nomes e datas fictícios, imagens de exemplo genéricas) — nunca os ficheiros reais partilhados durante o desenho desta funcionalidade.

## Fora de âmbito (deliberado)

- Dados de NEE — exclusão arquitetural já existente, não revisitada aqui.
- Suporte a PDF como formato de entrada.
- Histórico/auditoria de importações passadas (`import_jobs`).
- Cifra dos bytes da fotografia em repouso (fica só protegida por acesso autenticado/autorizado).
- Sugestão automática de escala 1-5 vs 0-20 com base no ano de escolaridade (não pedido, fora do âmbito desta funcionalidade).
