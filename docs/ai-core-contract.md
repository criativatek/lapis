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

```php
AiCapability::HelpAssistant        // 'help_assistant'
AiCapability::PedagogicalAnalysis  // 'ai_pedagogical_analysis'
```

São **duas chaves separadas** e não devem ser fundidas: uma escola pode
razoavelmente querer o assistente de ajuda e não a análise pedagógica, e a
segunda é a que vê material sobre crianças.

Nenhuma delas é `ai_assistance`, que continua exatamente onde estava (Pro e
Institucional) a controlar «Aperfeiçoar redação» nos Relatórios e o sugeridor de
estratégias nas Intervenções.

`AiUseCase` determina a capability — não se passam as duas:

| `AiUseCase` | capability |
|---|---|
| `HelpAnswer` | `help_assistant` |
| `HelpArticleSuggestion` | `help_assistant` |
| `PedagogicalAnalysis` | `ai_pedagogical_analysis` |
| `PedagogicalStrategySuggestion` | `ai_pedagogical_analysis` |
| `AdminConnectionTest` | nenhuma (só `platform-admin`) |

Falta um caso de uso? Acrescentar uma `case` ao enum e mapeá-la. **Não** passar
uma string.

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

Três, e nenhuma deve ser tomada em código sem falar com o responsável do produto
(CLAUDE.md §31).

1. **A que planos pertencem `help_assistant` e `ai_pedagogical_analysis`.**
   Estão catalogadas em `EntitlementsSeeder::MODULES` e **em nenhum plano**.
   Consequência hoje: nenhuma organização as tem, todos os ecrãs mostram o estado
   `plan`, e nada chega ao motor. Para decidir: uma linha em `BASE_MODULES` /
   `PRO_MODULES` / `INSTITUTIONAL_MODULES`.
   `AiGatewayTest::neither_ai_capability_is_granted_by_any_plan_yet` falha quando
   isso acontecer, de propósito — para que quem o fizer leia primeiro esta secção.
   Entretanto, um override por organização (`organization_module_overrides`) é a
   forma suportada de dar acesso a um piloto.
2. **A quota comercial por plano.** Os números em `config('lapis.ai.quotas')` são
   **tetos técnicos de custo**, não o que um plano inclui. `AiQuota::planLimit()`
   já lê `plans.limits['ai_quota'][capability][window]` — a chave não existe em
   nenhum plano. Quando existir, o plano ganha precedência automaticamente.
3. **Consentimento por organização.** Dívida herdada do ADR-0006 §2: configurar o
   motor é decisão do operador da instalação; uma escola dentro dela não tem forma
   de recusar. Continua por resolver.

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
