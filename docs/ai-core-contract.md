# Contrato do AI Core

> Para quem constrói experiências de IA (Assistente do Centro de Ajuda, Assistente
> Pedagógico) sobre a infraestrutura da branch `feat/ai-core`.
>
> Decisões e porquês: [ADR-0007](adr/0007-ai-core-one-gateway-one-policy.md).
> A regra que continua a valer acima de tudo: [ADR-0006](adr/0006-ai-rewrites-text-it-is-never-the-source.md)
> — **a IA reescreve e comenta texto; nunca é fonte de facto.**

## 1. A única porta

Existe **um** ponto de saída da aplicação para um motor de IA:

```php
App\Services\Ai\Gateway\AiGateway::ask(AiAsk $ask, User $user): AiAnswer
```

Nenhuma funcionalidade nova deve resolver um provider, ler uma credencial,
verificar um plano, contar nada, aplicar rate limiting ou escrever auditoria de
utilização. O gateway faz tudo isso, por esta ordem:

| # | Passo | Falha com |
|---|---|---|
| 1 | Entitlement da capability | `AiUnavailable` (`reason() === 'plan'`) |
| 2 | Existe motor configurado | `AiUnavailable` (`reason() === 'provider'`) |
| 3 | Rate limit por utilizador e por organização (por minuto) | `AiQuotaExceeded` |
| 4 | Quota por utilizador/dia e organização/mês | `AiQuotaExceeded` |
| 5 | Verificação de privacidade do payload | `RuntimeException` (bug, não estado) |
| 6 | Chamada ao provider | `AiRequestFailed` |
| 7 | Registo em `ai_usage_events` | — |

Todas as recusas ficam registadas antes de a exceção subir.

## 2. Request DTO — `AiAsk`

```php
new AiAsk(
    useCase: AiUseCase::HelpAnswer,          // obrigatório; determina a capability
    instruction: HelpAssistantPrompt::text(), // TEXTO DA APLICAÇÃO. Nunca texto do utilizador.
    content: $sanitizer->sanitise($question), // SanitisedPayload, não string
    promptVersion: HelpAssistantPrompt::VERSION,
    subjectHash: hash('sha256', $articleId),  // opcional
    temperature: 0.2,                          // opcional
);
```

Três coisas que o tipo obriga:

- **`content` não aceita `string`.** Só aceita `App\Support\Privacy\SanitisedPayload`,
  cujo **construtor é privado** e cuja classe é **final** — `new SanitisedPayload(...)`
  é erro fatal em qualquer sítio. A única fábrica, `producedBy()`, está marcada
  `@internal`, exige um `AiPayloadSanitizer` como argumento, e
  `PayloadProvenanceTest` falha se aparecer um segundo chamador em `app/`. Não é
  possível esquecer de sanitizar; é possível fazê-lo de propósito, e isso partirá
  um teste.
- **`instruction` e `content` nunca são concatenados**, em lado nenhum, até ao
  fio. Texto guardado que pareça uma ordem chega como conteúdo.
- **`promptVersion` é obrigatório.** Qualquer alteração ao texto da instrução
  exige uma versão nova, para que uma linha de auditoria antiga continue
  interpretável. Convenção estabelecida por `WritingPrompt::VERSION`.

### Construir o conteúdo — a ordem é obrigatória

```
allowlist  →  pseudonimizar  →  serializar  →  sanitizar
```

**Para contexto pedagógico (ou qualquer coisa com estrutura), usa `AiContext`.**

```php
$payload = AiContext::about(Pseudonyms::of($roster))   // ou ::withoutPeople()
    ->add('Domínio', $domain->name)
    ->add('Finalidade', $purpose->label())
    ->add('Padrão factual', $pattern)
    ->addList('Estratégia anterior', $strategyNames)
    ->add('Objetivo do professor', $objective)          // texto livre, delimitado
    ->toPayload($sanitizer);
```

Porque é que isto não é opcional:

- **`add()` recusa tudo o que não seja `string|int|float|null`.** Um modelo
  Eloquent, um array, um booleano — todos rebentam com `InvalidArgumentException`.
  Isto é uma guarda em runtime e **não** a união de tipos do parâmetro, porque
  `Eloquent\Model::__toString()` devolve `toJson()`: um parâmetro tipado
  `string` **aceita** um registo inteiro por coerção, e `declare(strict_types=1)`
  não salva (o modo estrito é decidido pelo ficheiro *chamador*). Não existe
  forma de despejar uma linha da base de dados num prompt.
- **Cada valor é pseudonimizado no `add()`**, enquanto ainda é um escalar isolado
  com significado conhecido — não depois de virar parágrafo.
- **Só depois é que se serializa**, e o sanitizador corre por cima como
  **segunda** barreira.

Para prosa que genuinamente não tem estrutura (uma pergunta que um professor
escreveu, uma secção que um compositor gerou):

```php
$payload = $sanitizer->sanitise($text, names: ['Maria Silva Costa', 'João Pereira']);
```

`sanitiseFields()` continua a existir como atalho e **delega no `AiContext`**, por
isso ganha a mesma ordem.

### O que o sanitizador tira, e o que não consegue ver

Sai por omissão: nomes da pauta (→ `Aluno A`, `Aluno B`), emails, telefones,
códigos postais, URLs, `n.º NN`, ULIDs/UUIDs e qualquer corrida de 6+ algarismos.
Fica: percentagens, classificações, contagens, períodos — a substância
pedagógica, sem a qual não há pergunta que valha a pena fazer.

> **O sanitizador é defesa adicional, não a única barreira.** São expressões
> regulares à procura de formas que conhecem, e são cegas a tudo o resto: um nome
> de rua, um irmão, «o pai da Rita». Um pipeline cuja única barreira é
> pattern matching sobre prosa deixa passar exatamente os dados pessoais para os
> quais ninguém escreveu um padrão. A minimização tem de acontecer onde ainda se
> vê o que um valor **é** — ou seja, no `AiContext`.

**É pseudonimização, não anonimização.** `AiAnswer` já vem com os nomes repostos;
o que o sanitizador removeu não volta, e não deve voltar.

## 3. Response DTO — `AiAnswer`

```php
$answer->text;                  // já re-hidratado (nomes repostos). NÃO VALIDADO.
$answer->provider;              // 'gemini' | 'chat-completions' | 'fake'
$answer->model;
$answer->inputTokens;           // ?int — ausente fica ausente
$answer->outputTokens;          // ?int
$answer->totalTokens();         // ?int — null se qualquer um dos dois faltar
$answer->durationMilliseconds;  // ?int
```

> **`text` é conteúdo não confiável.** O gateway não valida a resposta e não
> tenta: o que é uma boa resposta só a funcionalidade que perguntou sabe.
> Relatórios tem `RewriteGuard` para a sua versão desta pergunta. **A branch de
> experiências tem de construir a sua** — não há guarda genérica escondida no
> gateway.

## 4. Erros e estados

| Exceção | Significado para quem lê o ecrã | Métodos |
|---|---|---|
| `AiUnavailable` | «isto não está ligado» — clicar nunca vai ajudar | `publicMessage()`, `reason()`: `plan` \| `provider` |
| `AiQuotaExceeded` | «gastou o que tinha» — amanhã, ou no próximo mês | `publicMessage()`, `scope()`: `user_daily` \| `organization_monthly` |
| `AiRequestFailed` | «o motor não respondeu» — tentar outra vez | `publicMessage()`, `category()`, `getMessage()` (só para log) |

`AiRequestFailed::category()`: `timeout`, `unauthorized`, `rate_limited`,
`refused`, `provider_error`, `unusable_answer`, `unreachable`.

**Regras de apresentação, não negociáveis:**

- Mostrar `publicMessage()`. **Nunca** `getMessage()`, nunca `category()`, nunca
  um código de estado. A categoria só aparece na Administração da plataforma.
- Chamar `report($exception)` para `AiRequestFailed` — a razão técnica é útil no
  log e não carrega endpoint nem chave.
- Distinguir `plan` de `provider` no ecrã. São problemas de pessoas diferentes.

### Antes de desenhar o botão

```php
$gateway->isAvailable(AiCapability::HelpAssistant);        // bool
$gateway->unavailableReason(AiCapability::HelpAssistant);  // ?string
```

Slugs possíveis: `plan`, `off`, `credential_missing`, `model_missing`,
`endpoint_missing`, `unknown_driver`, `fake_in_production`, ou `null` quando está
disponível.

Isto decide o que é **desenhado**. O `ask()` volta a fazer todas as perguntas no
servidor — esconder um controlo é apresentação, não controlo de acesso
(CLAUDE.md §8.2).

## 5. Capabilities

**Uma capability por ÁREA DE PRODUTO, não uma por botão.** Uma escola compra
«IA na avaliação» ou não compra; não compra o botão de análise em Resultados
separadamente do que vier a existir ao lado dele no ano seguinte. A
granularidade fina — qual das funcionalidades de uma área foi uma chamada — é o
`AiUseCase`, que não custa nada comercialmente e é o que torna o contador
legível.

| `AiCapability` | chave | onde vive | Base | Pro | Inst. |
|---|---|---|---|---|---|
| `HelpAssistant` | `help_assistant` | Centro de Ajuda | ✅ | ✅ | ✅ |
| `PedagogicalAnalysis` | `ai_pedagogical_analysis` | Resultados › Estatística da turma | — | ✅ | ✅ |
| `Assessment` | `ai_assessment` | Resultados › Resultados do período | — | ✅ | ✅ |
| `Followup` | `ai_followup` | Evolução do Aluno | — | ✅ | ✅ |
| `Strategies` | `ai_strategies` | Evolução do Aluno › Estratégias | — | ✅ | ✅ |
| `Reports` | `ai_reports` | Relatórios › Secções | — | ✅ | ✅ |
| `Governance` | `ai_governance` | Administração institucional › IA | — | — | ✅ |
| `InstitutionalPool` | `ai_institutional_pool` | plafond da organização | — | — | ✅ |

A composição vem da **Matriz Mestre** e vive em `EntitlementsSeeder`, não aqui.
`AiEntitlementMatrixTest` afirma-a célula a célula, escrita à mão de propósito:
um teste que lesse o seeder passaria a dizer o que quer que o seeder dissesse.

**As duas últimas não chegam a um motor** (`AiCapability::isMetered()` devolve
`false`). São entitlements sobre ADMINISTRAR IA: a primeira abre um ecrã de
consumo, a segunda decide se um plafond organizacional se aplica por cima dos
tetos por capability. Nenhuma tem quota, balde de rate limit nem linha em
`ai_usage_events`, e nenhum `AiUseCase` aponta para elas.

### `ai_assistance` — a chave histórica

Continua no catálogo e continua em Pro e Institucional. Controlava «Aperfeiçoar
redação» e o sugeridor de estratégias antes de qualquer um deles passar pelo
gateway; agora esses verificam `ai_reports` e `ai_strategies`, e
`AiCapability::legacyModuleKeys()` faz com que a chave antiga continue a
conceder as duas.

**Só alarga, nunca retira.** O gateway pergunta se ALGUMA das chaves de
`moduleKeys()` é permitida, por isso uma organização que tenha a chave nova não
é afetada pelo que a antiga diga. Uma organização que tenha só a antiga —
através de um override, um piloto, uma exceção negociada — continua a funcionar
exatamente como funcionava, que é a propriedade de segurança de toda a migração.
`AiGatewayTest::the_legacy_ai_assistance_key_grants_exactly_what_it_used_to_gate`
prova isso, e prova também que a chave antiga **não** alargou para as
capabilities que nunca controlou.

### Use case → capability

`AiUseCase` determina a capability — não se passam as duas:

| `AiUseCase` | capability |
|---|---|
| `HelpAnswer` | `help_assistant` |
| `HelpArticleSuggestion` | `help_assistant` |
| `PedagogicalAnalysis` | `ai_pedagogical_analysis` |
| `AssessmentAnalysis` | `ai_assessment` |
| `FollowupSynthesis` | `ai_followup` |
| `PedagogicalStrategySuggestion` | `ai_strategies` |
| `ReportSectionRewrite` | `ai_reports` |
| `AdminConnectionTest` | nenhuma (só `platform-admin`) |

Falta um caso de uso? Acrescentar uma `case` ao enum e mapeá-la. **Não** passar
uma string.

## 5b. Quotas e plafond

Quatro tetos, e um pedido tem de passar por todos os que se apliquem:

| teto | janela | âmbito | onde se configura |
|---|---|---|---|
| rate limit | minuto | utilizador **e** organização | `lapis.ai.per_minute`, `…organization_per_minute` |
| quota do utilizador | dia | por capability | `plans.limits['ai_quota'][cap]['user_daily']` → `lapis.ai.quotas` |
| quota da organização | mês | por capability | `…['organization_monthly']` → `lapis.ai.quotas` |
| **plafond** | mês | **todas as capabilities juntas** | `plans.limits['ai_pool']` → `lapis.ai.pool` |

**Nenhum número em `config/` é uma regra comercial.** São tetos técnicos de
custo, com uma variável de ambiente à frente de cada um, e qualquer plano que
nomeie a chave ganha precedência automaticamente. Quanto é que um plano INCLUI é
uma decisão que vive em `plans.limits`, onde muda sem deploy.

**`null` é «sem teto», e `0` é «fechado».** Os dois são instruções reais e
diferentes — `0` é como se desliga uma funcionalidade sem mexer nos planos.

**O plafond só se aplica a quem tem `ai_institutional_pool`**, e está **inerte
por omissão**: ambos os valores em `config('lapis.ai.pool')` são `null`, porque
um plafond é uma figura contratual e um valor por omissão inventaria um para
todos os clientes institucionais de uma vez. «Preparado para» é o requisito;
«imposto» não é.

O backoffice guarda o plafond em `platform_settings.ai_quotas` sob a chave
reservada `ai_pool`, que o `AppServiceProvider` encaminha para `lapis.ai.pool`
em vez de `lapis.ai.quotas`. Nenhum módulo se chama `ai_pool` — a capability do
plafond é `ai_institutional_pool`, uma string diferente — e
`AiCapabilityCatalogTest` afirma que nunca se chamará.

Um pedido recusado **não consome quota** (`AiUsageEvent::scopeBillable`): nunca
chegou a um motor e não custou nada, e contá-lo faria com que atingir um teto o
tornasse mais difícil de contornar. Um pedido FALHADO conta: os tokens foram
gastos.

### Os números em vigor, e o que são

Estes são os **defaults técnicos** desta instalação. Nenhum é uma regra
comercial, nenhum está escrito num controlador ou num serviço, e todos podem
ser alterados sem deploy.

| capability | utilizador/dia | organização/mês | variáveis de ambiente |
|---|---:|---:|---|
| `help_assistant` | 60 | 3000 | `LAPIS_AI_HELP_USER_DAILY`, `LAPIS_AI_HELP_ORGANIZATION_MONTHLY` |
| `ai_pedagogical_analysis` | 40 | 1500 | `LAPIS_AI_PEDAGOGICAL_USER_DAILY`, `…_ORGANIZATION_MONTHLY` |
| `ai_assessment` | 40 | 1500 | `LAPIS_AI_ASSESSMENT_USER_DAILY`, `…_ORGANIZATION_MONTHLY` |
| `ai_followup` | 40 | 1500 | `LAPIS_AI_FOLLOWUP_USER_DAILY`, `…_ORGANIZATION_MONTHLY` |
| `ai_strategies` | 40 | 1500 | `LAPIS_AI_STRATEGIES_USER_DAILY`, `…_ORGANIZATION_MONTHLY` |
| `ai_reports` | 60 | 2000 | `LAPIS_AI_REPORTS_USER_DAILY`, `…_ORGANIZATION_MONTHLY` |
| **plafond** (todas juntas) | — | *sem teto* | `LAPIS_AI_POOL_ORGANIZATION_MONTHLY`, `LAPIS_AI_POOL_USER_MONTHLY` |

**Porque estes valores e não outros:** relação entre custo e repetição. O
assistente é barato e perguntado muitas vezes ao dia; uma análise é mais cara e
pedida uma vez por turma e por período; uma reescrita é por parágrafo, e um
professor reescreve vários seguidos. Não há aqui nenhuma leitura comercial —
são ordens de grandeza para travar um clique repetido e um cliente em ciclo.

**Três formas de os mudar, por ordem de precedência:**

1. **O plano** — `plans.limits['ai_quota'][capability][window]` e
   `plans.limits['ai_pool'][window]`. É aqui que uma decisão comercial vive.
   Ganha sempre. Nenhum plano semeado define qualquer uma destas chaves hoje, e
   `AiPoolQuotaTest` afirma-o.
2. **A Administração → Inteligência Artificial** — escreve em
   `platform_settings.ai_quotas`, que o `AppServiceProvider` lê sobre a config
   no arranque. **Sem deploy.** É o caminho normal para um operador.
3. **O `.env`** — o valor por omissão desta instalação, usado quando as duas
   camadas acima se calam.

**O que NÃO é possível:** um número comercial espalhado por controladores ou
serviços. `AiArchitectureTest::nothing_in_the_ai_surface_branches_on_a_plan_name`
falha se alguma decisão de IA passar a comparar uma chave de plano a um literal,
e `AiQuota` é o único sítio que lê um teto.

## 6. Rate limiting — o que NÃO fazer

O gateway aplica o rate limit ele próprio (`RateLimiter::tooManyAttempts`/`hit`,
por capability, por utilizador e por organização), porque tem de o fazer também
para um job ou um comando, onde não há stack de middleware.

> **Não acrescentar `->middleware('throttle:...')` para a mesma capability numa
> rota que chame o gateway.** Isso conta duas vezes e reduz o teto a metade.

## 7. O que o gateway regista, e o que não regista

`ai_usage_events`, uma linha por chamada: organização, utilizador, capability,
use case, provider, modelo, tokens (entrada/saída/total), duração, estado
(`succeeded`/`failed`/`blocked`), categoria de erro, `subject_hash`, timestamp.

**Não existe coluna** para prompt, resposta, texto de secção, aluno, resultado ou
classificação. Não é uma regra que alguém siga — é o esquema.

Auditoria de negócio (quem aceitou uma sugestão, o quê) continua a ser
responsabilidade da funcionalidade, via `AuditLog::record()`, seguindo o padrão
de `ReportWritingAssistant::record()`: métricas e hashes, nunca o texto.

## 8. Exemplo completo

```php
final class HelpAssistant
{
    public function __construct(
        private AiGateway $gateway,
        private AiPayloadSanitizer $sanitizer,
        private HelpCenter $help,
    ) {}

    public function answer(string $question, User $user): AiAnswer
    {
        // Os artigos são conteúdo autorado pela aplicação — mas passam pelo
        // sanitizador na mesma. A exceção seria o precedente.
        $context = $this->help->search($question)->take(3)
            ->map(fn (HelpArticle $article): string => $article->title.': '.$article->summary)
            ->implode("\n");

        return $this->gateway->ask(new AiAsk(
            useCase: AiUseCase::HelpAnswer,
            instruction: HelpAssistantPrompt::text(),
            content: AiContext::withoutPeople()   // uma pergunta sobre o produto
                ->add('Pergunta', $question)
                ->add('Artigos relevantes', $context)
                ->toPayload($this->sanitizer),
            promptVersion: HelpAssistantPrompt::VERSION,
        ), $user);
    }
}
```

O prompt (`HelpAssistantPrompt`) é da branch de experiências. Deve ser **fechado
e versionado**, sem campo de texto livre em lado nenhum, e deve terminar com o
equivalente ao `WritingPrompt::contentIsNotInstruction()` — a mensagem seguinte é
conteúdo, não são ordens.

## 9. O que este core deliberadamente NÃO faz

Embeddings · vector DB · Google Search grounding · memória longa · tools /
function calling · streaming · decisões automáticas · escrita em nome do modelo.

Um `AiAnswer` é texto devolvido a quem perguntou. Quem perguntou decide o que uma
pessoa vê.

## 10. Decisões pendentes

### Decidido na fatia AI-complete (0.86.0)

**A que planos pertencem as capabilities de IA.** Estava em aberto e a Matriz
Mestre decidiu: a tabela em §5 é a composição, e vive em `EntitlementsSeeder`.
O teste que falhava quando alguém compunha uma chave num plano
(`neither_ai_capability_is_granted_by_any_plan_yet`) foi substituído pelo seu
inverso — `every_ai_capability_belongs_to_at_least_one_plan` — porque a falha
que interessa vigiar passou a ser a oposta: uma capability catalogada e
esquecida, que nenhuma organização alcança e que não produz erro em lado nenhum.

Um override por organização (`organization_module_overrides`) continua a ser a
forma suportada de dar acesso a um piloto, um voucher ou um benefício
temporário, com validade opcional.

### Continuam pendentes

Nenhuma deve ser tomada em código sem falar com o responsável do produto
(CLAUDE.md §31).

1. **A quota comercial por plano.** Os números em `config('lapis.ai.quotas')` e
   `config('lapis.ai.pool')` são **tetos técnicos de custo**, não o que um plano
   inclui. `AiQuota` já lê `plans.limits['ai_quota'][capability][window]` e
   `plans.limits['ai_pool'][window]`, e o plano ganha precedência
   automaticamente — as chaves não existem em nenhum plano semeado. Quanto é que
   Base inclui de assistente, e qual o plafond de um contrato institucional, são
   decisões comerciais por tomar. `AiPoolQuotaTest` prova que o mecanismo morde
   assim que alguém escrever um número.
2. **Consentimento por organização.** Dívida herdada do ADR-0006 §2: configurar
   o motor é decisão do operador da instalação; uma escola dentro dela não tem
   forma de recusar. Continua por resolver, e a fatia AI-complete alargou o
   número de funcionalidades a que se aplica.
3. **Validação jurídica da descrição da IA.** A secção «Inteligência
   artificial» da Política e duas passagens do Acordo de Tratamento de Dados
   foram reescritas na 0.86.0 para descreverem o comportamento real — ver
   [`docs/legal.md`](legal.md) §«Corrigido na fatia AI-complete». A descrição é
   factual e está fixada por testes; a **redação** continua por validar por
   jurista, como todo o resto do texto legal.
4. **Texto livre em contextos sensíveis.** A síntese de acompanhamento não
   envia a descrição de um registo nem o objetivo de uma intervenção, porque é
   aí que vivem saúde, diagnóstico e contexto familiar. Isso limita a
   funcionalidade de forma real e conhecida — ver `FollowupContext`, que o
   documenta ao lado do código que o faz. Se alguma vez se quiser mudar, é uma
   decisão de produto e de proteção de dados, não uma otimização.

## 11. Credencial e segredos

- A chave vive em `LAPIS_AI_KEY` **ou** em `platform_settings.ai_api_key`
  (cifrada, cast `encrypted`). O que está guardado sobrepõe-se; o que não está cai
  para o `.env`.
- **Em produção prefira um gestor de segredos externo** com `LAPIS_AI_KEY`
  injetada no deploy e a coluna vazia. Uma coluna cifrada só é tão forte quanto a
  `APP_KEY` que a decifra, e a `APP_KEY` vive no mesmo ficheiro, na mesma máquina.
  A coluna existe porque esta instalação não tem gestor de segredos, não porque a
  base de dados seja o sítio certo para uma credencial.
- Único caminho de leitura do valor: `PlatformSetting::aiCredential()`, chamado
  só pelo `AppServiceProvider`. Não existe rota que a devolva.
- Não aparece em nenhum payload Inertia, log, exceção ou linha de auditoria. A
  Administração mostra «configurada», a data, e os últimos 4 caracteres quando a
  chave tem 20 ou mais.
