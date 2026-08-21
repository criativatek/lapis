# Restauro e importação segura de backup

Fatia 6, estendida na Fatia 6.1. Este documento descreve o **mecanismo** do
restauro — passos, autorização, transação, limpeza. O **esquema** do próprio
ficheiro — que coleções existem, o que cada uma transporta, o que cada
versão sabe restaurar — está em [docs/backup-schema.md](backup-schema.md).

O fluxo simétrico — gerar o ficheiro que este documento descreve como
restaurável — está em `App\Actions\DataExports\GenerateDataExport` e não
tem documento próprio; a Fatia 5 documentou apenas a política de retenção
associada, em [docs/data-lifecycle.md](data-lifecycle.md).

## Princípio

**É melhor não importar do que importar incorretamente.** Sempre que o
restauro encontrar um risco de perda de dados, quebra de isolamento entre
organizações, conflito irresolúvel ou ambiguidade de titularidade, a linha em
causa é classificada `invalid` e mostrada na pré-visualização — nunca
inferida, nunca escrita à força. O professor decide; o sistema propõe.

## O que é um backup do LÁPIS

O ficheiro carregado é ou o ZIP completo que `GenerateDataExport` produz
(`technicalBackup()`), ou apenas o `backup-lapis.json` lá dentro,
carregado isoladamente. Extensões aceites: `.zip`, `.json`. Limite de
carregamento: 20 MB (`max:20480` no Form Request) — antes de qualquer leitura
do conteúdo.

## Schema version

Tabela completa e razão de cada versão em
[docs/backup-schema.md](backup-schema.md#versões-de-esquema).
`App\Support\Import\Backup\BackupSchemaCompatibility::for()` classifica a
versão declarada antes de mais nada acontecer — nenhum ponto do pipeline
ramifica com base em `schema_version`; uma coleção ausente de um backup mais
antigo resolve simplesmente para vazia. Não existe normalizador de versões
anteriores a 2 — não há nenhuma em circulação, então não foi construído
(§56 do briefing original foi deliberadamente deixado de fora).

A Fatia 6.1 subiu o `schema_version` de 3 para 4, acrescentando toda a
estrutura e factos de avaliação e acompanhamento pedagógico — o que antes
era sempre `unsupported` (elementos de avaliação, classificações) agora
restaura-se de facto. Backups v2/v3 já emitidos continuam legíveis.

## Upload → pré-visualização → confirmação

Três passos, três responsabilidades, nenhuma escrita pedagógica antes da
terceira:

1. **Upload** (`POST /data-imports`) — guarda o ficheiro no disco privado
   (`data-imports/`, nunca `public`), lê-o, valida-o e constrói o
   `canonical_snapshot`. Cria uma linha `data_imports` com estado
   `validated`. Nada pedagógico é escrito aqui.
2. **Pré-visualização** (`GET /data-imports/{ulid}`) — reconstrói o plano de
   importação **de novo, a cada visita**, contra o estado atual da base de
   dados — nunca contra uma versão em cache. Mostra, por domínio, quantas
   linhas são novas / existentes / em conflito / inválidas / não suportadas.
3. **Confirmação** (`POST /data-imports/{ulid}/confirm`) — reconstrói o
   plano **outra vez**, agora dentro de uma transação com a linha
   `data_imports` e a organização de destino bloqueadas (`lockForUpdate`), e
   escreve apenas as linhas classificadas `new`.

Rebuilder duas vezes (preview e confirmação) e nunca confiar num plano
antigo é o que torna o mesmo backup seguro de importar duas vezes, e o que
fecha a corrida de dois pedidos de confirmação em simultâneo — o segundo
encontra `canBeConfirmed() === false` sob o mesmo bloqueio e recusa.

## Segurança de ficheiro

`App\Services\Import\Backup\ReadBackupUpload` nunca chama
`ZipArchive::extractTo()` — lê apenas a entrada `backup-lapis.json` via
`getFromName()`, depois de validar o arquivo entrada a entrada:

- **Zip Slip**: qualquer nome de entrada com `..`, ou que comece por `/` ou
  `\`, rejeita o ficheiro inteiro. Testado com travessia de diretório,
  caminho absoluto Unix e caminho absoluto Windows.
- **Zip Bomb**: máximo 500 entradas; máximo 20 MB descomprimidos por
  entrada; máximo 50 MB descomprimidos no total. Os limites são lidos do
  `stat` de cada entrada — nunca é preciso descomprimir para os aplicar.
- Ficheiro corrompido, ou ZIP sem `backup-lapis.json` lá dentro: rejeitado
  com uma mensagem em português, nunca um erro técnico.

Depois disto, `App\Support\Import\Backup\SecretScanner` percorre o JSON
descodificado **antes** de qualquer whitelisting, à procura de qualquer
chave — a qualquer profundidade — que contenha `password`, `remember_token`,
`two_factor`, `passkey`, `session`, `api_key`/`api_token`, `smtp`,
`invitation_token`, `token_hash`, `token`, `csrf`, `encryption_key`,
`app_key` ou `secret`. Encontrar uma só destas rejeita o ficheiro inteiro —
um genuíno backup do LÁPIS nunca as contém a esta profundidade, e o custo de
um falso positivo (pedir para tentar de novo) é seguro; o custo de um falso
negativo não seria.

`App\Services\Import\Backup\ValidateBackupPayload` é a segunda linha de
defesa, e a real: nunca copia uma chave não reconhecida para o
`canonical_snapshot`. Só os campos explicitamente listados por domínio (uma
lista de permissões própria para cada uma das coleções descritas em
[docs/backup-schema.md](backup-schema.md)) alguma vez chegam lá — o
`SecretScanner` é defesa em profundidade, não a única barreira.

## O que é efetivamente restaurado

Desde a Fatia 6.2, todo o grafo pedagógico — anos letivos, disciplinas,
turmas, alunos, inscrições, períodos letivos, escalas, tipos de elemento,
domínios, perfis de avaliação e as suas versões, elementos de avaliação e
itens, pontuações, classificações, autoavaliações, registos pedagógicos,
estratégias e medidas, e relatórios finalizados. A referência coleção a
coleção — o que cada uma transporta e as regras de correspondência — está
em [docs/backup-schema.md](backup-schema.md); este documento mantém-se ao
nível do mecanismo.

Uma exceção permanece deliberada:

| Domínio | Comportamento |
|---|---|
| Resultados calculados (médias, evolução, estatísticas) | **Nunca exportados nem importados.** São sempre recalculados depois do restauro pelos serviços canónicos — ver o princípio em [docs/backup-schema.md](backup-schema.md) |

Anos letivos e disciplinas foram só correspondência até à Fatia 6.2, porque
o backup só transportava o rótulo/nome como texto — sem `starts_on`/
`ends_on` de um ano, sem código de uma disciplina — e inventar esses dados
violaria a mesma regra que proíbe inventar fórmulas de avaliação. A Fatia
6.2 estendeu primeiro o exportador com os dados reais, e só depois deixou
o importador criá-los: hoje seguem exatamente o mesmo padrão de identidade
e clonagem que qualquer outro domínio (secção seguinte).

## Identidade e o `ulid` global

Cada linha nova preserva o `ulid` original do backup (`forceFill()` antes de
`save()` — `HasUniqueIds` só gera um novo `ulid` quando o campo está
vazio). É isto que torna o mesmo backup idempotente: importado duas vezes, a
segunda vez encontra tudo como `existing` e não escreve nada.

Os `ulid` restauráveis têm unicidade **global**, não por organização. Quando
o `ulid` do backup continua a existir noutra organização, o plano faz uma
leitura cross-tenant apenas para detetar essa origem e procura, exclusivamente
no destino, uma linha com a chave natural ou de conteúdo documentada no
esquema. Uma correspondência idêntica é `existing`; dados divergentes são
`conflict`; sem correspondência, a linha é `new` mas o writer deixa o atributo
`ulid` vazio para o Laravel gerar uma identidade nova. A referência ao `ulid`
de origem existe apenas no plano em memória para ligar o grafo nessa execução.

Assim, um backup pode ser clonado para outra organização enquanto a origem
permanece intacta. A segunda importação no mesmo destino é idempotente pelas
chaves naturais/conteúdo, sem tabela persistida de mapeamento. Nenhuma linha da
organização de origem é atualizada, apagada ou reutilizada como destino.

## Conflitos — nunca merge silencioso

Uma linha cujo `ulid` já existe no destino, mas cujos dados divergem (rótulo
de turma diferente, código de aluno diferente, estado de inscrição
diferente), é classificada `conflict`. A pré-visualização mostra-a; a
confirmação **nunca a escreve** — nem substitui o registo existente, nem
cria um duplicado. As únicas ações possíveis sobre um conflito, hoje, são
ignorá-lo (não confirmar essa linha) ou cancelar a importação inteira. Não
existe mesclagem de campo a campo nesta fatia.

Uma segunda origem de "existente" independente do `ulid`: um aluno com o
mesmo `pseudonym_code`, ou uma turma com a mesma combinação
ano/disciplina/rótulo, também conta como conflito — não apenas duplicados
por identidade técnica.

## Organização de destino, tenancy e autorização

A importação escreve sempre na organização atualmente resolvida
(`CurrentOrganization`) — nunca numa organização escolhida pelo conteúdo do
próprio backup. `DataImportPolicy::create()` é ao nível de membro, o mesmo
nível de `SchoolClassPolicy::create()`: um restauro é, em substância, criação
de turmas/alunos/inscrições em massa, e esta aplicação nunca reservou essa
ação ao dono.

Ver, confirmar e cancelar uma importação são restritos a quem a pediu
(`requested_by`) — mesmo o dono da organização não pode agir sobre a
importação de outro membro. Uma organização diferente da que pediu nunca
encontra a linha (o âmbito global por tenant devolve 404, não 403).

**O dono institucional não ganha acesso pedagógico global só por
importar.** Numa organização institucional, toda turma criada por um
restauro fica sem professor atribuído — exatamente como qualquer turma
criada manualmente sem ninguém a lecionar ainda —, visível em
`SchoolClass::needingReassignment()` e em `/classes/reassignment`. Numa
organização pessoal, o próprio importador é atribuído automaticamente como
professor, porque nesse contexto não há mais ninguém a quem atribuir.

Bloqueado durante impersonação (`RefusesDuringImpersonation` — nenhuma ação
de suporte pode carregar, confirmar ou cancelar um restauro em nome de
outra pessoa) e durante uma janela de encerramento de conta ou de
organização (`EnsureAccountIsOperational` — ao contrário da exportação,
o restauro **não** está na lista de exceções permitidas durante o
encerramento). Ler a pré-visualização continua sempre permitido nos dois
casos — só a escrita é bloqueada.

## O que nunca é importado

Nunca, independentemente do que o ficheiro contenha:

- Membros, convites, ou o dono da organização
- Contas de utilizador (`users`) — restaurar dados pedagógicos não é
  restaurar pessoas
- Palavras-passe, segredos de 2FA, passkeys, tokens de sessão ou de convite
- Configuração de plataforma: planos, módulos, entitlements, SMTP, escalas
  de sistema

Estas exclusões não dependem só do `SecretScanner` — `ValidateBackupPayload`
nunca sequer whitelist um campo destes domínios para começar. O
`SecretScanner` é a rede de segurança para o caso de um destes campos
aparecer onde não devia.

## Transação e idempotência

`App\Actions\DataImports\ExecuteDataImport::execute()` corre inteiro dentro
de `DB::transaction()`. Qualquer falha a meio — uma restrição de unicidade
inesperada, uma linha corrompida que passou a validação de campo mas colide
na escrita — reverte tudo o que essa execução teria criado; nunca fica uma
turma restaurada sem os alunos que a acompanhavam. A linha `data_imports`
fica com estado `failed` e uma mensagem em português; o detalhe técnico vai
para `storage/logs/laravel.log`, nunca para o ecrã.

Importar o mesmo backup duas vezes não cria duplicados: a segunda execução
reconstrói o plano contra o estado já restaurado pela primeira, encontra
tudo `existing`, e não escreve nada.

## Limpeza e retenção

O ficheiro carregado expira ao fim do mesmo prazo que uma exportação de
dados fica disponível (`RetentionPolicy::dataExportAvailabilityHours()`,
hoje 24h) — reaproveitado deliberadamente, para não introduzir um segundo
número de configuração a explicar. `php artisan data-imports:prune`, agendado
de hora a hora junto dos restantes comandos de limpeza do produto, fecha
(`cancelled`) qualquer importação ainda aberta passado esse prazo e remove o
ficheiro do disco; numa segunda passagem, remove também qualquer ficheiro
órfão com mais de um dia sem nenhuma linha `data_imports` a apontar para
ele. O `canonical_snapshot` em si nunca é apagado — é o registo do que o
backup dizia, e é o que torna seguro apagar o ficheiro bruto.

Confirmar ou cancelar uma importação apaga o ficheiro imediatamente,
independentemente do prazo — não há razão para manter um backup em disco
depois de já ter sido lido para a base de dados, ou depois de o professor
ter desistido dele.

## Auditoria

`data_import.uploaded`, `data_import.completed`, `data_import.failed` e
`data_import.cancelled` — gravados via `App\Services\Audit\AuditLog`, na
organização de destino, com o utilizador que pediu como causador.

## Limitações conhecidas e dívida futura

Deliberadamente fora do âmbito desta fatia (ver §101 do briefing original):

- Correspondência difusa de alunos (por nome semelhante, por exemplo) — só
  correspondência exata por `ulid` ou `pseudonym_code`
- Resolução interativa de conflitos campo a campo — hoje é tudo-ou-nada por
  linha
- Restauro de membros, convites, ou do dono da organização
- Criação automática de contas de utilizador
- Importação de backups de terceiros fora do formato do LÁPIS
- Assinatura criptográfica do backup

Dívida futura específica do esquema pedagógico (`ReportTemplate`,
`calculation_snapshot_id` histórico, `enrollment_instrument_applicability`)
está documentada em
[docs/backup-schema.md](backup-schema.md#dívida-futura-fora-do-âmbito-desta-fatia-de-propósito).
