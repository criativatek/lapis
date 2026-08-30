# Ciclo de vida dos dados — retenção, encerramento, backups

Fatia 4, estendido na Fatia 5. Este documento descreve a **política técnica de
conservação de dados** do Lapispro: durante quanto tempo cada tipo de dado
permanece identificável, recuperável, ou é alvo de limpeza técnica — e o que
está efetivamente implementado versus apenas configurado/documentado.

O fluxo real de pedido/cancelamento de encerramento (contas pessoais e
organizações institucionais) está descrito em detalhe em
[docs/account-closure.md](account-closure.md) — este documento mantém a
política de retenção; aquele descreve o mecanismo.

A Fatia 6 acrescenta o caminho inverso da exportação — restaurar um backup
gerado pelo próprio Lapispro — descrito em
[docs/data-import.md](data-import.md): o que é efetivamente restaurado, o que
nunca é importado, e a limpeza automática do ficheiro carregado.

## O que esta política cobre e o que não cobre

Este documento descreve o que o Lapispro **se propõe** a fazer aos dados ao longo
do tempo, como decisão de produto e de engenharia. **Não é um parecer
jurídico.** O Lapispro aplica períodos de conservação definidos de acordo com a
finalidade dos dados e as obrigações aplicáveis — mas este documento não
afirma qual é o período legalmente exigido para nenhuma categoria de dado, e
não deve ser lido como tal. Os números aqui descritos são uma política de
produto, ajustável à medida que o enquadramento evolui, e cada escola ou
agrupamento pode ter obrigações adicionais próprias (retenção de processos de
aluno, arquivo escolar, etc.) que este documento não substitui nem cobre.

Configuração dos números: `config/retention.php`. Leitura tipada:
`App\Support\Retention\RetentionPolicy`.

## Dados pedagógicos: ano letivo atual + 3 anteriores

Notas, evidências, propostas de classificação e relatórios permanecem
identificáveis durante o ano letivo atual mais os três anteriores — quatro
anos letivos no total (`config('retention.pedagogical_previous_years_retained')
= 3`).

A contagem é feita em **anos letivos**, nunca em tempo de calendário nem por
comparação direta de `created_at`. `App\Support\Retention\AcademicYearRetentionClassifier`
ordena os anos de uma organização por `starts_on` descendente — a mesma
convenção de ordenação já usada no resto do código — e classifica cada ano
como dentro ou fora da janela de retenção relativamente a um "ano atual" de
referência.

Isto é deliberado: um registo introduzido tarde para um ano anterior (por
exemplo, uma avaliação lançada em setembro sobre o ano letivo que acabou de
fechar) pertence ao ano letivo a que diz respeito, não ao momento em que foi
digitado. Comparar por `created_at` classificaria mal exatamente esses casos.

`AcademicYearRetentionClassifier::currentYearFor()` é uma heurística auxiliar,
só de leitura, para escolher o "ano atual" em relatórios/pré-visualizações: o
ano com `status = Active` quando existe exatamente um; caso contrário, o mais
recente por `starts_on`. Nunca é usada para desencadear qualquer ação
destrutiva — apenas para classificação e apresentação.

**Esta fatia não apaga, não anonimiza e não arquiva nada fora da janela.** O
classificador devolve `within_retention: true/false` por ano — nunca
`eligible_for_deletion`. O que fazer com um ano fora da janela (arquivar,
pseudonimizar, apagar) é uma decisão de produto ainda não tomada.

## Conta pessoal encerrada: 60 dias

Quando uma conta pessoal é encerrada, os dados permanecem totalmente
recuperáveis e exportáveis durante 60 dias
(`config('retention.personal_account_closure_days')`). A partir do dia 60,
deixam de estar recuperáveis e a conta passa a **elegível para eliminação**
(nunca eliminada automaticamente — ver "O que NÃO é apagado" abaixo).

`App\Support\Retention\ClosureRetention::isPersonalAccountRecoverable()`
implementa esta fronteira com precisão: dia 59 ainda é recuperável (`true`);
dia 60 já não é (`false`) — a comparação é estritamente "menor que", nunca
"menor ou igual".

**Fatia 5 liga esta lógica a um fluxo real** — `users.closure_requested_at` /
`scheduled_deletion_at`, pedido e cancelamento em `/settings/account-closure`.
Detalhe completo, incluindo a guarda de ownership institucional e o bloqueio
de atividade normal durante a janela, em
[docs/account-closure.md](account-closure.md).

## Organização institucional encerrada: 90 dias

Mesma lógica, janela maior — 90 dias
(`config('retention.institutional_closure_days')`) — porque mais pessoas
dependem dos dados de uma organização institucional do que de uma conta
pessoal. `ClosureRetention::isInstitutionalOrganizationRecoverable()` aplica a
mesma fronteira estrita: dia 89 recuperável, dia 90 já não.

**Fatia 5 liga esta lógica a um fluxo real**, simétrico ao da conta pessoal —
`organizations.closure_requested_at` / `scheduled_deletion_at`, pedido e
cancelamento restritos ao responsável (owner), em `/team/closure`. Detalhe
completo em [docs/account-closure.md](account-closure.md).

## Logs técnicos: 90 dias / Auditoria de segurança/institucional: 3 anos

Alvos de política: logs técnicos da aplicação (`storage/logs`) durante 90 dias
(`config('retention.technical_log_days')`); o registo de auditoria de
segurança/institucional (`audit_events`) durante 3 anos
(`config('retention.security_audit_years')`) — mais tempo do que os logs
técnicos, por ser o registo de quem fez o quê, não um detalhe operacional.

**Nenhum dos dois está atualmente aplicado em código — e a Fatia 5
deliberadamente não muda isto.** Não existe rotação de logs nem job de
limpeza de `audit_events` no Lapispro a esta data; nenhum foi adicionado. Isto é
uma lacuna documentada e trabalho futuro — não algo já em execução. Os
valores em `config/retention.php` são o alvo a implementar, não uma descrição
do comportamento atual do sistema.

Os logs técnicos do Laravel (`storage/logs`) são infraestrutura operacional
fora da aplicação (rotação de ficheiros, logrotate ou equivalente ao nível do
servidor) — não algo que este código deva gerir sozinho; alterar essa
infraestrutura está fora do âmbito de qualquer fatia funcional. `audit_events`
é o registo de segurança/auditoria institucional: a Fatia 5 acrescenta uma
**contagem de pré-visualização**, cross-tenant deliberadamente (o mesmo
`withoutGlobalScope('organization')` já usado nos relatórios administrativos
existentes), de quantos eventos têm mais de 3 anos
(`App\Support\Retention\DeletionEligibility::auditEventsOutsideRetention()`,
exposta em `php artisan retention:status`, só de leitura) — mas **nunca purga
um único evento**. O trail de auditoria é crítico para segurança e permanece
intocado; não existe comando de purga para `audit_events` nesta fatia.

## Backups técnicos: 30–60 dias

Rotação de backups técnicos da base de dados: entre 30 e 60 dias
(`config('retention.technical_backup_rotation_days_min')` /
`..._max`). É expressa como intervalo porque o número exato é uma decisão de
infraestrutura/operações, não uma decisão por pedido.

Backup técnico é responsabilidade operacional da plataforma (disaster
recovery); exportação de dados é um pedido do utilizador, gerada sob pedido
mediante ação explícita, e não substitui nem é substituída pelo backup
técnico — são mecanismos com finalidades diferentes. **Esta fatia não altera
a infraestrutura real de backup** — os valores em `config/retention.php` são
apenas a política documentada, não uma alteração ao que já corre em
produção.

Exportações de dados geradas pelo utilizador ficam disponíveis para download
durante 24 horas (`config('retention.data_export_availability_hours')`) antes
de limpeza automática — ver secção seguinte.

## Anonimização — dívida explícita

Pseudonimização — por exemplo, substituir um nome por um identificador
mantendo os registos ligáveis entre si através desse identificador — **não é
anonimização**. Anonimização real e verificada exige que a reidentificação
deixe de ser possível, mesmo cruzando com outras fontes de dados.

**Este código não tem, hoje, nenhum mecanismo capaz de garantir anonimização
real e irreversível.** Até que tal mecanismo exista e seja verificado, este
documento — e o produto — nunca deve afirmar que um dado foi "anonimizado".
As únicas afirmações válidas são "eliminado" (o registo deixou de existir) ou
"pseudonimizado" (o identificador direto foi substituído, mas os registos
continuam ligáveis), e só quando forem verdadeiramente verdade. Anonimização
real fica marcada como dívida técnica explícita, para trabalho futuro.

## O que NÃO é apagado automaticamente

- Nenhuma fatia até esta apaga dados pedagógicos, de conta, ou institucionais.
  É arquitetura de classificação, configuração e — desde a Fatia 5 — de
  pedido/cancelamento de encerramento. Continua sem existir nenhum job de
  purga, anonimização ou eliminação real de dados.
- Sair de uma organização ou ser removido de uma turma nunca apaga dados
  pedagógicos — ver [docs/membership-lifecycle.md](membership-lifecycle.md)
  para o que acontece à associação de uma pessoa a uma organização/turma; os
  dados de avaliação em si não são tocados por isso.
- Pedir o encerramento de uma conta pessoal ou de uma organização
  institucional (Fatia 5) também não apaga nada — ver
  [docs/account-closure.md](account-closure.md). O único efeito imediato é
  bloquear escrita pedagógica nova; ao fim da janela de recuperação, o registo
  fica **elegível**, nunca eliminado automaticamente.
- A única limpeza automática de ficheiros que este produto executa é a de ZIPs
  de exportação de dados expirados — um artefacto de conveniência gerado
  para o utilizador, nunca o dado de origem. `app/Console/Commands/PruneDataExports.php`
  remove o ficheiro do disco privado depois de `expires_at` e limpa o
  `disk_path` da linha correspondente; `DataExportPolicy` já recusa o
  download antes disso, mesmo que a limpeza ainda não tenha corrido. Agendado
  em `routes/console.php` (`data-exports:prune`, a cada hora, junto dos
  restantes prune commands do produto). Não apaga nem toca em nenhum dado de
  origem — só o ZIP gerado.

  A Fatia 5 corrigiu um bug real neste comando: ao correr a partir do
  scheduler (sem pedido HTTP), nenhuma organização está resolvida no
  container, e a query batch (`update(['disk_path' => null])`) sobre
  `DataExport` — tenant-scoped por natureza — lançava
  `TenantNotResolvedException` a cada execução, silenciosamente, desde que a
  Fatia 4 o agendou. O teste existente não apanhou isto porque corria logo a
  seguir a um pedido HTTP simulado, que deixava um tenant "preso" no
  container. Corrigido com `withoutGlobalScope('organization')` — o mesmo
  padrão já usado nos relatórios administrativos cross-tenant — e coberto por
  um teste que força `CurrentOrganization::forget()` antes de correr o
  comando, replicando exatamente a condição real do scheduler.
- A Fatia 6 acrescenta a mesma limpeza para o caminho inverso: `php artisan
  data-imports:prune`, também de hora a hora, fecha qualquer restauro de
  backup ainda por confirmar depois de expirado e remove o ficheiro
  carregado do disco privado — nunca os dados já restaurados. Detalhe em
  [docs/data-import.md](data-import.md).

## A regra do ponteiro (0.101.3)

> **Nunca limpar `stored_path`/`disk_path` antes de o ficheiro estar
> comprovadamente removido.**

Não é uma preferência de estilo. O ponteiro é o **único registo de quem eram
os dados** que um upload privado continha: uma vez a `null`, um ficheiro que
sobreviva em disco deixa de ser atribuível a qualquer organização, e a limpeza
retroativa passa a ser feita por data de modificação e a olho. Um ficheiro
retido é um problema; um ficheiro retido *e* órfão é um problema pior.

O que tornava isto fácil de errar: o adaptador local do Flysystem começa o seu
`delete()` por `file_exists()` e sai em silêncio quando este é falso — mas
`file_exists()` responde falso tanto a «não existe» como a «este diretório não
me deixa ver», e só o primeiro é prova. Um diretório privado nasce `0700` (ver
armadilha 10 em [deployment.md](deployment.md)), portanto a segunda hipótese é
real e foi exatamente o que aconteceu em produção.

A resposta está em `App\Support\Storage\RemovalOutcome`, que dá três respostas
em vez de duas — `Removed`, `AlreadyAbsent`, `Failed` — e onde `AlreadyAbsent`
só é devolvido quando a ausência é **verificável**. Tudo o resto, incluindo
«não consegui perceber», é `Failed`, e um `Failed` deixa a linha exatamente
como estava para a execução seguinte tentar de novo.

### Estados finais de `DataImport`, e o ficheiro de cada um

`DataImportStatus::isFinal()` diz que «the uploaded file has no reason to exist
past this point». Até 0.101.3 nada agia sobre essa frase no caso `Failed`.

| Estado | Final? | Quem remove o ficheiro |
|---|---|---|
| `uploaded` / `validated` | não | `data-imports:prune`, passagem 1, depois de `expires_at` |
| `imported` | sim | `DataImportController::confirm()` ao concluir |
| `cancelled` | sim | `DataImportController::destroy()` ao cancelar |
| `failed` | sim | `DataImportController::confirm()` no `catch` — **novo em 0.101.3**; antes ficava indefinidamente |

E por baixo de todos, a **passagem 2** do `data-imports:prune`: qualquer estado
final que ainda tenha `stored_path` depois de expirado tem o ficheiro removido
e o ponteiro limpo, **sem reescrever o estado**. Um import que falhou, falhou;
o que se corrige é o ficheiro que não devia ter-lhe sobrevivido. É a rede por
baixo dos três caminhos da tabela, para o caso de qualquer um deles não ter
conseguido apagar na altura.

### Os irmãos, e onde o desenho é legitimamente diferente

`correction-imports:prune`, `data-exports:prune`, `roster-imports:prune` e
`inovar-exports:prune` passaram a ter a mesma proteção de listagem, o mesmo
delete verificado e o mesmo código de saída. Duas diferenças ficam de pé de
propósito:

- **`roster-imports` e `inovar-exports` não têm ponteiro em base de dados** —
  são pastas por token, identificadas por data de modificação. A regra do
  ponteiro não se lhes aplica; a da listagem sim.
- **`ImportCorrectionGrid` limpa `stored_path` dentro da transação e só depois
  apaga o ficheiro.** É deliberado e está documentado no próprio ficheiro: se a
  transação reverter depois desse ponto, a linha aponta para nada, que é a
  direção segura de falhar. O ficheiro que sobreviva a essa janela **fica sem
  ponteiro** — e é por isso que a varredura de órfãos o apanha na passagem
  seguinte. **Não foi uniformizado**: mexer nas fronteiras de transação de um
  serviço de escrita académica não pertence a uma fatia sobre retenção de
  ficheiros, e o controlo compensatório existe e está testado.

## Configuração de retenção

Os valores por omissão vivem em `config/retention.php` e são lidos através de
`App\Support\Retention\RetentionPolicy`. **Não são configuráveis por
professor** — são uma política de plataforma, uniforme para toda a
instalação.

Overrides ao nível da organização/instituição (por exemplo, uma escola com
obrigação contratual de conservar dados por mais tempo) são uma extensão
futura possível, limitada pelos limites legais/contratuais aplicáveis — não
implementada nesta fatia.

## Central de Suporte (ADR-0011)

| O quê | Janela | Onde vive o número |
| --- | --- | --- |
| Pedido `open` ou `in_progress` | **sem expiração** | — |
| Pedido `waiting_for_user` — lembrete | **23 dias** | `retention.support_waiting_reminder_days` |
| Pedido `waiting_for_user` — auto-resolve | **30 dias** | `retention.support_waiting_auto_resolve_days` |
| Pedido `resolved` — conteúdo completo | **24 meses** de `resolved_at` | `retention.support_resolved_months_retained` |

Executado por `support:retention`, agendado às **03:50** — dez minutos depois de
`retention:execute`, porque os dois podem tocar nas mesmas contas e correr em
série evita que a ordem seja uma questão de sorte.

**A anonimização é verdadeira.** Passados os 24 meses sem suspensão em vigor,
`requester_name`, `requester_email`, `user_id`, `organization_id`, `subject`,
`description`, `technical_reference`, `technical_route` e `retention_hold_note`
vão a **NULL**; as mensagens e os registos de entrega são **apagados**. Sem
marcas de substituição — as colunas nascem nullable exactamente para isto, ao
contrário de `AnonymiseClosedAccount`, que usa marcas porque a linha do
utilizador tem restrições `NOT NULL` a satisfazer.

Sobrevive o que serve estatística e não identifica ninguém: `reference`,
`category`, `source`, `status`, `app_version`, os carimbos temporais, o
`technical_code` (vocabulário fechado) e os campos da suspensão **excepto a
nota**.

**Suspensão (`retention hold`).** Motivo de vocabulário fechado — `legal_dispute`,
`fraud_investigation`, `statutory_obligation`, `formal_proceeding`, `other` —
aplicada e libertada por platform-admin. Trava **apenas** a anonimização: o
lembrete e o auto-resolve continuam a correr. **Libertar não reinicia o
relógio**: a janela conta sempre de `resolved_at`, e um hold levantado depois
dos 24 meses é anonimizado na execução seguinte.

**Reabrir para o relógio.** Uma resposta de quem abriu um pedido resolvido põe
`resolved_at` a NULL: enquanto estiver activo, nada é anonimizado, e a contagem
só recomeça no próximo `resolved_at`.

### Matriz de retenção — Central de Suporte

| Dados | Titular | Estado | Contagem começa | Prazo | Finalidade | Operação final | Suspensão | Backups |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Pedido e mensagens | Quem escreveu (com ou sem conta) | `open` / `in_progress` | — | **sem prazo** | Responder ao pedido em curso | nenhuma | n/a | rotação documentada |
| Pedido e mensagens | idem | `waiting_for_user` | `waiting_since` | **23 dias** | Lembrar quem não respondeu | email de lembrete, uma vez (`waiting_reminder_sent_at`) | **não trava** | idem |
| Pedido e mensagens | idem | `waiting_for_user` | `waiting_since` | **30 dias** | Fechar o que ficou sem resposta | `resolved`, `auto_resolved = true`, mensagem de sistema | **não trava** | idem |
| Pedido, mensagens, entregas | idem | `resolved` | `resolved_at` | **24 meses** | Conservar o histórico enquanto útil | anonimização (ver abaixo) | **trava** | idem |

**A operação final aos 24 meses**, executada por `support:retention` (03:50):

- **NULL** — `requester_name`, `requester_email`, `user_id`, `organization_id`,
  `subject`, `description`, `technical_reference`, `technical_route`,
  `retention_hold_note`.
- **DELETE** — `support_messages`, `support_notification_deliveries`.
- **Sobrevive** — `reference`, `category`, `source`, `status`, `app_version`,
  `technical_code`, carimbos temporais, e os campos da suspensão excepto a nota.

**Reabertura.** Uma resposta de quem abriu um pedido resolvido põe `resolved_at`
a NULL: a contagem dos 24 meses **para**, e só recomeça no próximo
`resolved_at`. Um pedido activo nunca é anonimizado.

**Suspensão.** Motivo de lista fechada (`legal_dispute`, `fraud_investigation`,
`statutory_obligation`, `formal_proceeding`, `other`), aplicada e levantada só
por platform-admin. Trava **apenas** a anonimização — o lembrete e o
auto-resolve correm à mesma. Levantá-la **não reinicia o relógio**: a janela
conta de `resolved_at`, e uma suspensão levantada depois dos 24 meses é
anonimizada na execução seguinte. A nota interna é o único campo do hold que a
anonimização apaga.

**Backups.** Não se declara aqui um prazo próprio para a Central: aplica-se a
rotação de cópias já documentada nesta página, e um pedido eliminado pode
subsistir numa cópia até essa cópia ser substituída. Nenhum prazo novo é
afirmado porque nenhum existe em código para o sustentar.
