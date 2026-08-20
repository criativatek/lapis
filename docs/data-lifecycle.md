# Ciclo de vida dos dados — retenção, encerramento, backups

Fatia 4. Este documento descreve a **política técnica de conservação de dados**
do LÁPIS: durante quanto tempo cada tipo de dado permanece identificável,
recuperável, ou é alvo de limpeza técnica — e o que, desta fatia, está
efetivamente implementado versus apenas configurado/documentado.

## O que esta política cobre e o que não cobre

Este documento descreve o que o LÁPIS **se propõe** a fazer aos dados ao longo
do tempo, como decisão de produto e de engenharia. **Não é um parecer
jurídico.** O LÁPIS aplica períodos de conservação definidos de acordo com a
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
deixam de estar recuperáveis.

`App\Support\Retention\ClosureRetention::isPersonalAccountRecoverable()`
implementa esta fronteira com precisão: dia 59 ainda é recuperável (`true`);
dia 60 já não é (`false`) — a comparação é estritamente "menor que", nunca
"menor ou igual".

**Esta fatia não implementa um fluxo real de pedido de encerramento nem
qualquer apagamento automático.** Não existe coluna `closure_requested_at` em
`users` nem em `organizations` — seria esquema morto sem uma funcionalidade
real a escrevê-lo. `ClosureRetention` recebe o instante de encerramento como
parâmetro, pronta para ser ligada a uma funcionalidade futura de encerramento
de conta quando essa existir.

## Organização institucional encerrada: 90 dias

Mesma lógica, janela maior — 90 dias
(`config('retention.institutional_closure_days')`) — porque mais pessoas
dependem dos dados de uma organização institucional do que de uma conta
pessoal. `ClosureRetention::isInstitutionalOrganizationRecoverable()` aplica a
mesma fronteira estrita: dia 89 recuperável, dia 90 já não. Também aqui, sem
fluxo de encerramento real implementado — apenas a lógica de fronteira,
pronta a ser usada.

## Logs técnicos: 90 dias / Auditoria de segurança/institucional: 3 anos

Alvos de política: logs técnicos da aplicação (`storage/logs`) durante 90 dias
(`config('retention.technical_log_days')`); o registo de auditoria de
segurança/institucional (`audit_events`) durante 3 anos
(`config('retention.security_audit_years')`) — mais tempo do que os logs
técnicos, por ser o registo de quem fez o quê, não um detalhe operacional.

**Nenhum dos dois está atualmente aplicado em código.** Não existe rotação de
logs nem job de limpeza de `audit_events` no LÁPIS a esta data. Isto é uma
lacuna documentada e trabalho futuro — não algo já em execução. Os valores em
`config/retention.php` são o alvo a implementar, não uma descrição do
comportamento atual do sistema.

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

## O que NÃO é apagado automaticamente por esta fatia

- Esta fatia não apaga nada. É arquitetura de classificação e configuração —
  não introduz nenhum job de purga, anonimização ou eliminação de dados reais.
- Sair de uma organização ou ser removido de uma turma nunca apaga dados
  pedagógicos — ver a funcionalidade de ciclo de vida de membros (implementada
  em paralelo a esta fatia) para o que acontece à associação de uma pessoa a
  uma organização/turma; os dados de avaliação em si não são tocados por isso.
- A única limpeza automática introduzida por esta fatia é a de ficheiros ZIP
  de exportação de dados expirados — um artefacto de conveniência gerado
  para o utilizador, nunca o dado de origem. `app/Console/Commands/PruneDataExports.php`
  remove o ficheiro do disco privado depois de `expires_at` e limpa o
  `disk_path` da linha correspondente; `DataExportPolicy` já recusa o
  download antes disso, mesmo que a limpeza ainda não tenha corrido. Agendado
  em `routes/console.php` (`data-exports:prune`, a cada hora, junto dos
  restantes prune commands do produto). Não apaga nem toca em nenhum dado de
  origem — só o ZIP gerado.

## Configuração de retenção

Os valores por omissão vivem em `config/retention.php` e são lidos através de
`App\Support\Retention\RetentionPolicy`. **Não são configuráveis por
professor** — são uma política de plataforma, uniforme para toda a
instalação.

Overrides ao nível da organização/instituição (por exemplo, uma escola com
obrigação contratual de conservar dados por mais tempo) são uma extensão
futura possível, limitada pelos limites legais/contratuais aplicáveis — não
implementada nesta fatia.
