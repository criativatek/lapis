# ADR-0011 — A conversa de suporte vive no Lapispro, e o email é só o aviso

- **Status:** Accepted — implementado em `feat/central-support-v1`, com a
  release **0.101.0** preparada nessa branch. Ainda **não** integrado em `main`
  e **sem deploy**.
- **Date:** 2026-08-30
- **Não altera:** [ADR-0002](0002-container-resolved-tenancy.md). As tabelas
  novas são lidas entre organizações e não levam o global scope, pela mesma
  razão e com a mesma disciplina que `App\Support\Commercial` já aplica.
- **Não altera:** [ADR-0008](0008-plan-versions.md). O suporte humano **não é
  uma capability**, não entra em nenhuma `PlanVersion` e não é lido por
  `App\Support\Entitlements\Entitlements`. Base, Pro e Institucional têm o mesmo
  suporte, e a composição continua 13 / 28 / 34.
- **Reservado:** ADR-0010 pertence aos créditos de IA, que são outra fatia e
  outra decisão.

## Context

Até aqui, um professor com um problema tinha um endereço de email e mais nada.
`suporte@lapispro.com` aparecia nos Termos e na Política de Privacidade como o
canal, e o produto não sabia que ele existia: nenhuma tabela, nenhum ecrã,
nenhuma forma de um operador ver o que estava por responder.

Isso tem três consequências práticas, e todas se pagam com o cliente:

1. **Não há fila.** Um email por responder é indistinguível de um respondido, e
   um pedido perdido é indistinguível de um que nunca chegou.
2. **A conversa não tem casa.** O histórico vive em caixas de correio pessoais,
   que ninguém pode auditar, exportar nem apagar quando o prazo de retenção
   chegar.
3. **Não há retenção nenhuma.** Um pedido de suporte contém, quase sempre, mais
   dados pessoais do que qualquer outro texto que um professor escreve — «o
   aluno X não aparece na turma Y» —, e não havia janela, nem apagamento, nem
   sequer a possibilidade de responder a um pedido de eliminação.

A Central de Suporte existe para dar casa às três coisas.

## Decision

### 1. Três tabelas, fora da tenancy

`support_requests`, `support_messages` e `support_notification_deliveries`.
Migração aditiva; nenhuma coluna existente é alterada.

**Fora do global scope de organização**, como `vouchers` e `founder_seats`: um
pedido de suporte é da PESSOA e da plataforma, não do inquilino. Ver §4.

### 2. Quatro estados, e nenhum a mais

`open` → `in_progress` → `waiting_for_user` → `resolved`.

**Não existe `closed`.** Dois estados finais que ninguém sabe distinguir é a
mesma armadilha que `CommercialCondition` documenta entre `Other` e NULL: quem
os escreve hesita, quem os lê adivinha, e a estatística mistura os dois.
`waiting_since` só existe em `waiting_for_user`, e a restrição da base de dados
diz isso em SQL.

### 3. Quem vê o quê — e o que uma referência NÃO é

- **Autenticado** vê apenas os pedidos que ELE próprio abriu (`user_id`).
- **Um colega da mesma organização NÃO vê.** Nem o administrador institucional.
  `organization_id` é **contexto para o operador**, nunca uma chave de
  autorização — é a única coluna deste domínio que parece tenancy e não é.
- **Platform-admin** vê todos, pelo middleware administrativo que já existe.
- **Guest cria e mais nada.** Não há portal, não há GET público, não há URL
  assinada, não há recuperação pela referência.

**`SUP-XXXXXX` não é autenticação.** É um número de protocolo, para a pessoa
dizer ao telefone e para o operador procurar — legível de propósito e, por isso
mesmo, adivinhável. Qualquer ecrã que a aceitasse como credencial entregaria o
histórico de outra pessoa a quem contasse até seiscentos mil.

### 4. A conversa canónica é a do Lapispro

`support_messages` é a fonte da verdade. O email é **notificação e recurso**,
nunca o registo.

**Não há processamento de email de entrada na V1.** Responder ao email chega à
caixa de suporte e **não** entra no histórico do pedido — e os emails dizem-no
com todas as letras, em vez de deixarem a pessoa descobrir que a resposta se
perdeu.

### 5. O email sai depois do commit, e nunca desfaz o pedido

O pedido é gravado primeiro. Só depois se tenta enviar, **de forma síncrona**,
dentro de `DB::afterCommit()`. Uma falha de SMTP **nunca** faz rollback do
ticket: o pedido existe, e o que falhou foi o aviso.

**Sem fila e sem worker, e isso é a razão do desenho e não um atalho.** Em
produção, `QUEUE_CONNECTION=database` e não há processo nenhum a consumir a
tabela `jobs`: o crontab tem `schedule:run` e o backup, mais nada. Um mailable
`ShouldQueue` seria escrito na base de dados e **nunca enviado**, sem que nada
se queixasse — que é exactamente o modo de falha que esta decisão recusa.

### 6. O retry é durável, visível e manual

Log não chega: uma falha só registada num ficheiro é uma falha perdida.

`support_notification_deliveries` guarda **estado de entrega, nunca conteúdo**:
tipo de notificação (enum fechado), destinatário por papel, tentativas, datas e
um `failure_code` de vocabulário fechado. **Nunca** o corpo, nunca o assunto,
nunca a mensagem da excepção, nunca a resposta do servidor, nunca credenciais.

O backoffice mostra o que ficou por entregar e oferece **«Reenviar»** —
platform-admin apenas. O reenvio **reconstrói o email a partir do estado
canónico do pedido**; nada é lido desta tabela para compor a mensagem. Sem
worker e sem polling: o retry é um acto de uma pessoa que viu a pendência.

### 7. Retenção: 23 / 30 / 24

- `open` e `in_progress` — **sem expiração automática**. Um pedido nosso não
  caduca por o termos deixado parado.
- `waiting_for_user` — lembrete aos **23 dias**, uma só vez; auto-resolve aos
  **30 dias**, com uma mensagem `system` a dizer porquê.
- `resolved` — conteúdo completo durante **24 meses** desde `resolved_at`.

`support:retention`, agendado às **03:50**, dez minutos depois de
`retention:execute` (03:40) e fora da hora certa, pelas mesmas razões que o
vizinho já documenta. Idempotente, uma request por unidade de trabalho, e a
falha de uma não interrompe as outras.

### 8. Anonimização a sério, sem placeholders

Passados os 24 meses e sem hold activo, os campos identificantes vão a **NULL**
e as mensagens e as entregas são **APAGADAS**.

**Sem `Pedido anonimizado`, sem `anonimizado-…@invalido.local`, sem `Conteúdo
removido`.** Isto diverge de propósito de `AnonymiseClosedAccount`, que usa
marcas porque a linha do utilizador tem de continuar a satisfazer `NOT NULL` e a
unicidade do email. Aqui as colunas **nascem nullable** precisamente para que a
ausência possa ser ausência. Um placeholder é um valor: ocupa espaço, aparece em
listagens, e alguém acaba por o ler como se fosse um facto.

Sobrevive o que serve estatística e não identifica ninguém: `reference`,
`category`, `source`, `status`, `app_version`, os carimbos temporais, o
`technical_code` — que é enum fechado e por isso não pode conter um nome — e os
campos do hold **excepto a nota**.

### 9. O hold é uma excepção provada, não um campo de texto

`retention_hold_reason_code` é um vocabulário **fechado**: `legal_dispute`,
`fraud_investigation`, `statutory_obligation`, `formal_proceeding`, `other`. A
nota é opcional, **interna**, e nunca sai para auditoria nem para email.

O hold **bloqueia apenas a anonimização** — nunca o lembrete, nunca o
auto-resolve. **Libertá-lo não reinicia o relógio**: a janela conta sempre de
`resolved_at`, por isso um hold libertado depois dos 24 meses é anonimizado na
execução seguinte, e não ganha mais dois anos por ter sido levantado.

### 10. A criação de um pedido não tem autor no rasto

`AuditLog::recordPlatformWithoutCauser()` — método novo, com assinatura **sem**
parâmetro de causer, para que passar um seja impossível e não apenas
desaconselhado. Força `organization_id = NULL` e `causer_id = NULL`.

O evento de criação usa-o **tanto para guest como para autenticado**. Parece
estranho perder o autor de um acto que o tem, e é deliberado: um evento de
auditoria é imutável, e um evento imutável que aponta para o utilizador e para a
organização é um identificador que a anonimização dos 24 meses **não conseguiria
apagar**. A escolha é entre saber quem abriu um pedido de 2026 e conseguir
cumprir a promessa de o anonimizar em 2028.

Os actos do **operador** — responder, mudar estado, resolver, aplicar e libertar
hold — mantêm o `causer_id`: são responsabilidade de quem os pratica, e não são
dados do titular.

**O rasto nunca guarda conteúdo livre**: nem `description`, nem corpo de
mensagem, nem corpo de email, nem `retention_hold_note`, nem stack traces, nem
tokens. Um teste com sentinela prova-o em vez de o prometer.

### 11. `lapis.support.inbox` — operacional, distinto do legal

A Central lê `config('lapis.support.inbox')`. **Zero hardcode** em controladores,
acções ou mailables.

Hoje aponta para o mesmo endereço que `lapis.legal.support_email`, e um teste
afirma-o — mas **não são o mesmo conceito**: um é para onde a aplicação
encaminha respostas; o outro é o canal que os Termos declaram. Fundi-los faria
com que mudar de ferramenta de suporte obrigasse a reescrever um documento
legal.

**`platform_settings.support_email` não é fonte desta funcionalidade** e não é
tocada nesta release. Fica como dívida de limpeza, sem fallback automático — um
fallback silencioso entre duas fontes é como se descobre, um ano depois, que os
emails saíam com a marca errada.

### 12. Reabrir é um direito de quem escreveu

Um utilizador autenticado pode responder a um pedido `resolved` que ele próprio
abriu. Ao fazê-lo, o pedido volta a `open`, e `resolved_at`, `waiting_since` e
`waiting_reminder_sent_at` voltam a NULL.

Consequência deliberada: **o relógio dos 24 meses pára** e só recomeça no
próximo `resolved_at`. Um pedido activo nunca é anonimizado.

O guest não reabre, porque não tem por onde — e é o preço coerente de §3.

### 13. Sem SLA, sem níveis, sem premium

O suporte humano é o mesmo nos três planos. Não há prioridade paga na V1, e por
isso não há nada a declarar em nenhuma `PlanVersion`.

### 14. A confirmação de receção identifica o pedido pela CATEGORIA (0.102.0)

A confirmação automática que sai quando alguém abre um pedido tem de dizer **de
que pedido se trata** — quem escreveu duas vezes na mesma semana precisa de
saber qual deles é este. A escolha óbvia seria o `subject`, e é a escolha
errada.

O campo que o formulário mostra como **«Resumo»** é `subject`, e é **texto
livre**. É o primeiro sítio onde alguém escreve «o aluno João não aparece na
turma 5.ºB» sem pensar duas vezes — mais depressa até do que na descrição, que
pelo menos parece um formulário. Pô-lo num email fá-lo atravessar servidores que
não são nossos e ficar numa caixa de entrada que não controlamos, que é
exactamente o que §4 e o próprio `SupportNotificationMail` já recusam para a
descrição e para as mensagens do fio.

**Fica a categoria.** `SupportCategory` é vocabulário fechado: chega para
distinguir dois pedidos da mesma pessoa e não pode conter o nome de ninguém. O
resumo continua a existir onde está protegido — no pedido, dentro da aplicação,
atrás de autenticação.

**Excepção deliberada e única: o primeiro nome de quem pediu.** A confirmação
cumprimenta pelo primeiro nome, e esse é o único dado pessoal que os emails
desta Central acrescentam. Não é conteúdo do pedido, e vai para a caixa de
correio da própria pessoa — a regra que estes emails cumprem é sobre **o que foi
escrito no pedido**, que pode falar de alunos, não sobre reconhecer quem o
escreveu. Sem nome, a mensagem cumprimenta na mesma, sem inventar um.

**Sem prazo.** «Com a maior brevidade possível» é uma intenção; «em 24 horas» é
um contrato que ninguém assinou, e que se lê de volta no dia em que a resposta
demora 26. §13 já dizia que não há SLA; a confirmação é o sítio onde seria mais
fácil criar um por descuido, e um teste recusa a lista de promessas de prazo.

### 15. O ecrã não afirma um email que não saiu (0.102.0)

Depois de criar um pedido, a interface lê o estado real da entrega em
`support_notification_deliveries` — o mesmo registo durável de §6 — antes de
dizer seja o que for sobre o email.

O pedido ficou registado: isso é certo, e é o que importa. A confirmação por
email é **outra coisa**, e pode ter falhado. Dizer «enviámos uma confirmação»
quando o servidor a recusou põe a pessoa à espera de algo que não vem e, quando
não vier, a duvidar do pedido inteiro — que está perfeitamente guardado. Numa
falha, o ecrã diz que o pedido ficou registado e que a confirmação não pôde ser
enviada, e é tudo.

Para um visitante isto pesa mais: sem portal, o email era o único sítio onde ele
voltaria a ver este pedido, e se não saiu a referência no ecrã passa a ser tudo
o que ele tem.

**Não há segunda fonte deste facto.** Depois de um reenvio bem sucedido no
backoffice, a mesma linha passa a dizer «entregue» sem que nada mais tenha de
ser actualizado.

## Consequences

- Um pedido de suporte passa a ter fila, histórico, prazo e apagamento.
- Um professor sem conta continua a poder pedir ajuda — e continua sem portal.
- Uma falha de SMTP deixa de ser invisível: fica na fila do backoffice, com um
  botão.
- Passados dois anos, um pedido resolvido deixa mesmo de identificar quem o
  escreveu, e continua a contar para a estatística de quantos pedidos daquela
  categoria existiram.
- O preço: sem processamento de email de entrada, uma resposta enviada por email
  não entra no histórico. É uma limitação real da V1, dita ao utilizador no
  rodapé de cada mensagem em vez de descoberta por ele.

## Alternatives considered

- **Fila de jobs para o email.** Recusada: não há worker em produção, e criá-lo
  é infraestrutura com o mesmo peso que o `lapis-ssr` acabou de exigir. Envio
  síncrono após commit dá a mesma garantia ao ticket sem inventar um processo.
- **Portal para guest com URL assinada.** Recusada: obriga a manter o endereço
  de email como credencial de acesso durante toda a vida do pedido, e transforma
  a caixa de correio da pessoa numa chave. Quem quer histórico cria conta.
- **Placeholders na anonimização.** Recusada — ver §8.
- **`retention_hold_reason` em texto livre.** Recusada: uma excepção a uma
  promessa de eliminação tem de ser classificável e contável, e texto livre não
  é nenhuma das duas coisas.
- **Suporte como capability do Pro.** Recusada: um professor no Base que não
  consegue entrar na conta precisa de suporte mais do que qualquer outro.
