# ADR-0009 — «Os primeiros 250» é um lugar, e um lugar é uma linha

- **Status:** Accepted — implementado em `feat/commercial-conditions`. Ainda
  **não** integrado em `main`, sem release e sem deploy.
- **Date:** 2026-08-29
- **Depende de:** [ADR-0008](0008-plan-versions.md) §8, que criou as quatro
  colunas de prova comercial em `organization_subscriptions` e deixou
  explicitamente por implementar o contador dos 250.
- **Não altera:** [ADR-0002](0002-container-resolved-tenancy.md). A tabela nova
  é lida entre organizações e não leva o global scope, pela mesma razão e com a
  mesma disciplina que `App\Support\Commercial` já aplica.

## Context

A landing promete um número exacto e uma data: «Faça parte dos primeiros 250» e
«Disponível até 31 de dezembro de 2026 ou até serem atingidos os primeiros 250
Membros Fundadores, consoante o que ocorrer primeiro». O checkout mostra
«Restam N lugares».

**Nada contava.** `FounderAvailability::taken()` contava as subscrições com
`commercial_condition = founder` — uma população que **nenhum fluxo escrevia**.
O checkout marcava a condição no *pagamento*; a subscrição só passava a
fundadora se, dias depois, um operador o dissesse à mão em
`SetCommercialCondition`. O contador lia, portanto, uma tabela que ninguém
enchia:

- «Restam 250 lugares» ficaria em 250 para sempre;
- o 251.º comprador veria 29,90 € sem forma de saber que era o 251.º;
- não havia ordinal, e por isso não havia resposta para «quem foi o 250.º».

A promessa era pública, precisa e verificável, e o sistema não a sabia
verificar.

## Decision

### 1. Um lugar é uma linha em `founder_seats`

Tabela nova, aditiva, migração `2026_09_12_000100`. Não toca em nenhuma coluna
existente.

| Coluna | Papel |
|---|---|
| `seat_number` `UNIQUE` | O ordinal público, 1..250. É ele que responde «quem foi o 250.º» e é o `UNIQUE` que torna o 251.º **impossível na base de dados**. |
| `organization_id` `UNIQUE` | Um lugar por organização, alguma vez. |
| `price_cents` + `currency` | O preço acordado, **congelado no momento da adesão**. |
| `claimed_at` | Quando foi tomado. É esta data — e não `id` — que ordena a promessa. |
| `reserved_until` | Até quando a reserva se aguenta sem pagamento confirmado. NULL depois de confirmada. |
| `confirmed_at` | Preenchida quando o dinheiro entrou. |
| `subscription_payment_id`, `organization_subscription_id` | O pedido que o reservou e o contrato que dele resultou. Informativos. |
| `claimed_by` | Quem o atribuiu, quando não foi o próprio checkout. |

### 2. Porque não o esquema que já existia

Tentou-se primeiro, e nenhuma das duas hipóteses serve:

**`organization_subscriptions.commercial_condition`** — a subscrição só existe
dias depois da compra, o que deixa a janela inteira da transferência por cobrir;
e a coluna é mutável por `SetCommercialCondition`, de modo que limpar uma
condição libertaria um lugar sem rasto e sem ordinal.

**`subscription_payments`** — o `ConfirmBankTransferRequest` **anula** a linha
pendente e cria outra, pelo que o mesmo comprador aparece ora duas vezes ora
nenhuma, conforme se conte `pending` ou `paid`. E não há ordinal: só `id`, que
não representa contratação.

### 3. Quando o lugar é reservado — no checkout, não no pagamento

> **Um lugar é atribuído no instante em que uma organização conclui o checkout
> do plano Pro e recebe a sua referência de transferência.**

Porque é o único momento **transacional e auditável** que este produto tem: não
há gateway, e tudo o que vem a seguir é uma pessoa a olhar para um extrato.

A alternativa — contar **pagamentos confirmados** — foi rejeitada por ser
comercialmente falsa no momento em que importa: a confirmação é manual e chega
dias depois, pelo que 250 pessoas poderiam ver «restam 250 lugares», transferir
29,90 € no mesmo dia, e quase todas descobririam mais tarde que não eram
fundadoras. A promessa tem de fechar quando é feita a quem a lê.

### 4. Prazo da reserva — a mesma janela que o comprador vê

`reserved_until = claimed_at + billing.bank_transfer.window_days` (14 dias).

Reservar **sem** prazo entregaria a promessa a carrinhos abandonados: 250
cliques bastariam para esgotar publicamente uma condição que ninguém pagou.

Não há job nem scheduler. As reservas vencidas são varridas dentro da mesma
transação que atribui a seguinte, e `FounderAvailability::taken()` já as ignora
pela data — quem lê o número não pode depender de uma limpeza ter corrido
primeiro.

### 5. Quando fica definitivo — quando o dinheiro entra

`ConfirmBankTransferRequest` chama `FounderSeats::confirm()`: `confirmed_at`
passa a ter data e `reserved_until` passa a NULL. Não é uma data que se
prolonga; é uma condição que deixa de se aplicar. Um lugar pago não expira.

Confirmar duas vezes não reescreve a data da primeira.

### 6. Quando é libertado

- **Automaticamente**, quando a reserva vence sem pagamento confirmado.
- **Explicitamente**, por `FounderSeats::release()`, com motivo obrigatório.

Em ambos os casos a **linha é apagada**. A tabela significa «os Membros
Fundadores», e uma linha libertada descreve alguém que não é um; deixá-la com
uma marca de anulada obrigaria todas as contagens futuras a lembrar-se de a
excluir — que é exactamente o género de detalhe que uma delas há-de esquecer. O
que aconteceu não se perde: fica em `audit_events`, com o número do lugar, o
motivo e quem o deu.

### 7. Ordinais densos, e o libertado volta ao bolo

O próximo lugar é **o menor número livre**, não `max + 1`. A diferença conta
assim que um lugar é libertado: se o n.º 100 volta ao bolo enquanto o 250 está
ocupado, `max + 1` daria 251 — «esgotado» — com 249 lugares realmente tomados.

Reatribuir o ordinal de quem nunca chegou a ser fundador está certo: os
ordinais descrevem a sequência dos fundadores **efectivos**, e mantê-los densos
é o que faz de «restam N» uma subtracção em vez de uma consulta com buracos.

### 8. Concorrência — quatro camadas, e nenhuma delas rebenta o checkout

1. **`UNIQUE(seat_number)`.** Duas transações que calculem o mesmo número não
   podem ambas gravar. É a única garantia que não depende de o código estar
   certo.
2. **`lockForUpdate()` sobre uma linha que existe SEMPRE** — a do plano `pro`,
   escolhida por a condição Fundador ser uma condição *sobre* o Pro e por essa
   linha ser semeada com a instalação e nunca apagada.
   *Esta camada foi corrigida em revisão.* A primeira versão bloqueava «a
   última linha de `founder_seats`», que numa tabela **vazia** não é linha
   nenhuma: as duas primeiras compras simultâneas calculavam ambas o n.º 1, o
   `UNIQUE` recusava a segunda, e a segunda compradora levava com um erro de
   base de dados a meio do checkout.
3. **Um ciclo de tentativas que converge.** Apanhada uma colisão, relê-se a
   tabela e pede-se o menor número que continue livre — nunca se insiste no
   mesmo. Limite de 10, sobre uma tabela cujo máximo é 250 linhas.
4. **O teto verificado em PHP antes de inserir**, que é o que devolve
   «esgotado» em vez de uma excepção.

**E em nenhum destes caminhos sai um erro para quem compra.** Esgotadas as
tentativas, devolve-se «sem lugar» e regista-se a ocorrência: o checkout segue
ao preço de tabela. Vender a 44,90 € quando havia lugar é um erro pequeno,
visível no trilho e corrigível por um operador; rebentar um checkout com um erro
de chave duplicada não é nenhuma dessas coisas.

O SQLite dos testes ignora `lockForUpdate()`, por isso a camada 2 é provada em
MySQL — `FounderSeatsMysqlGuaranteesTest`, opt-in — com duas ligações reais e a
tabela vazia, medindo o `Lock wait timeout` da segunda. As camadas 1, 3 e 4 são
provadas na suíte normal.

### 9. O preço congela no lugar

`config/billing.php` diz o que se pede **hoje**; `founder_seats.price_cents` diz
o que **esta pessoa** contratou. Depois de o lugar existir, mexer na
configuração não altera contrato nenhum.

### 10. Relação com o pagamento

O pedido de transferência é criado com o preço e a condição **do lugar**, e não
da configuração. Sem lugar, o checkout emite um pedido normal a preço de tabela
em vez de prometer o que já não existe.

**E a condição tem de continuar a valer até ao momento de pagar.** Um pedido
pendente caduca em duas dimensões — a janela de transferência que o comprador
viu anunciada, e o lugar que sustenta o preço. Quem voltasse ao checkout no
20.º dia recebia de volta a mesma referência a 29,90 € sem lugar por trás,
transferia, e só na confirmação é que alguém descobriria. `revalidate()` decide
antes: reafirma o lugar se ainda o houver, e anula o pedido — com motivo
registado — se não houver, emitindo outro com a condição em vigor. **O contrato
fica determinado antes de o dinheiro sair**; «quem confirma decide» não é
resposta quando o comprador já transferiu.

### 11. Relação com a subscrição

Nenhuma, do lado do acesso. **Nada em `App\Support\Entitlements` lê esta
tabela.** Um Membro Fundador é `plan = pro` com
`commercial_condition = founder`, com exactamente os mesmos módulos de um Pro
normal — a regra que o `CommercialCondition` foi escrito para proteger, e que
uma tabela de lugares não é sítio para quebrar.

Quando o operador activa o plano depois de confirmar o dinheiro, o lugar é
ligado à subscrição que dele resultou (`organization_subscription_id`), o que
permite saltar de um para o outro no backoffice sem SQL. É informativo e não
conta para os 250.

### 12. Auditabilidade

Três eventos, no padrão de `AuditLog` que a área comercial já usa:

- `commercial.founder_seat_claimed` — n.º, preço congelado, moeda, prazo da
  reserva e a **origem do benefício** (`checkout` ou `admin`);
- `commercial.founder_seat_confirmed`;
- `commercial.founder_seat_released` — n.º, motivo e se já estava confirmado.

Os três aparecem no trilho da ficha da conta.

### 13. Contas de teste, demo e internas — não inferidas

O esquema **não tem** essa marca: não há `is_internal`, `is_demo`, nem tipo de
organização que o diga. **Não se infere** por domínio de email, por nome, por
organização nem por id — inventar a classificação a partir de um desses seria
criar, dentro do mecanismo dos 250, uma regra de negócio que o produto não tem
em lado nenhum.

O mecanismo desta fase é `release()`: explícito, com motivo obrigatório e
auditado. Antes do lançamento comercial tem de ser confirmado que não ficam
lugares de teste indevidamente ocupados — é para isso que existe
`lapis:commercial-preflight`, que lista os lugares e o seu estado sem escrever
nada.

**Requisito transversal futuro:** um conceito explícito de conta
test/demo/internal na plataforma. Não pertence a esta decisão e não deve ser
introduzido por causa dela.

### 14. Sem contador público, por decisão actual

A landing não mostra «restam N». O número real existe agora, mas pô-lo na página
é uma decisão comercial e não uma correcção de facto: escassez anunciada é um
artifício de marketing, e esta página tem-se recusado a inventar figuras.

O que a página passou a fazer é o mínimo factual: **deixa de oferecer a condição
quando ela fecha** — por lotação ou por prazo. `HomeController` envia um
booleano, e a banda, o distintivo do cartão Pro e a frase do FAQ desaparecem com
ele. O Pro continua ao preço de tabela: fecha a condição, não o produto.

O checkout, esse, mostra «Restam N lugares» — e aí o número é verdadeiro e é
relevante para quem está prestes a contratar.

## Consequences

Uma tabela nova, com no máximo 250 linhas, lida em cada visita ao checkout e à
landing. É uma contagem indexada sobre um conjunto minúsculo.

`FounderAvailability` deixa de escrever e passa a só ler; quem atribui é
`FounderSeats`, e é lá que a garantia contra o 251.º vive. A separação é
deliberada: o contador é consultado em páginas públicas, e uma classe que só lê
não pode alocar nada por engano.

O `down()` da migração **recusa** se houver lugares atribuídos, pela mesma razão
que a migração do snapshot comercial recusa depois de haver preços gravados: um
lugar ocupado é a prova de uma condição acordada com uma pessoa, e nada a
reconstrói depois de a tabela desaparecer.

O `HARD_CAP` de 1000 na CHECK constraint é deliberadamente maior do que os 250
da promessa. O teto comercial vive em `billing.founder.seats`, onde uma decisão
comercial o pode mover; este é um travão de sanidade contra um erro de
configuração, e mudá-lo exige uma migração — que é o peso certo para uma decisão
dessas.

## Fora de âmbito, deliberadamente

**Vouchers.** O enum tem o caso e não há backend nenhum por trás. Continua assim.

**Renovação, cobrança recorrente e o que acontece a um fundador no segundo ano.**
Um lugar prova a condição da adesão. O que acontece quando o termo comercial
chega ao fim é uma política que não existe, e `commercial_term_ends_at` continua
a não ser lido por nada no caminho do acesso.

**Um conceito de conta interna.** §13.

**Contador público na landing.** §14.
