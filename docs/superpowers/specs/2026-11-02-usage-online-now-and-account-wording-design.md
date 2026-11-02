# Painel de utilização: «Ligados agora» e linguagem de contas — desenho

Data: 2026-11-02. Base: `main` @ 7a9ed8c6 (0.188.1). Branch `feat/usage-online-now`.

## Contexto e decisões do utilizador

- Em produção não há instituições: há só contas pessoais de professores, e esse é o
  público-alvo. O painel `/admin/usage` fala de «organizações», o que confunde quando
  cada organização é uma pessoa.
- Decisão (2026-11-02): **(a)** acrescentar um cartão nominal «Ligados agora»;
  **(b)** falar de «contas» em vez de «organizações» na apresentação do painel.
  **Não** muda a regra por omissão (conta nova continua «Por classificar»), **não**
  se classifica a partir da lista de acompanhamento. Só apresentação; nada muda no
  esquema nem na semântica dos segmentos.

## 1. Cartão «Ligados agora»

### Fonte

Tabela `sessions` (driver `database`): `user_id`, `last_activity` (unix), `payload`
(base64 de `serialize(array)`). Sessão viva = `last_activity >= now − config('session.lifetime') min`
(120 em produção). Não há recolha nova, migração nem evento.

### Serviço

`App\Services\Usage\Insights\OnlineNow` (final), `build(CarbonImmutable $now): array`:

- junta `sessions` a `users` por `user_id` (sessões sem utilizador ignoradas);
- uma entrada por pessoa (máximo de `last_activity` entre as suas sessões);
- exclui, como o resto do painel, `anonymized_at`, `usage_opted_out_at` e
  `closure_requested_at` não nulos;
- **acesso técnico não conta**: uma sessão cujo payload contém `impersonator_id` é do
  suporte a trabalhar na conta de outra pessoa; essa sessão fica fora (a pessoa
  impersonada não está «ligada»). A sessão própria do técnico conta como dele. Descodificar
  com `unserialize(base64_decode($payload), ['allowed_classes' => false])` dentro de
  try/catch; se não descodificar, tratar como sessão normal;
- ordena por atividade mais recente;
- devolve:

```php
[
  'as_of' => 'HH:mm',            // hora de Lisboa do cálculo
  'window_minutes' => 15,        // «ativo agora»
  'session_minutes' => 120,      // config('session.lifetime')
  'active_count' => int,         // pessoas com atividade nos últimos 15 min
  'people' => [                  // todas as sessões vivas (≤ session_minutes)
    ['ulid' => ?string,          // usage_user_states.ulid quando existe (liga à ficha), senão null
     'name' => string, 'email' => string,
     'internal' => bool,         // is_platform_admin || is_support_technician
     'minutes_ago' => int, 'active' => bool], …
  ],
]
```

`UsageOverview::build()` acrescenta `'online' => $this->onlineNow->build($now)`.

### Apresentação (`Overview.vue`)

Secção «Ligados agora» logo a seguir ao `UsageHeader`, **antes** dos filtros, com a frase
«Independente dos filtros abaixo: uma sessão não pertence a nenhuma conta nem segmento.»
Dentro de um cartão (`rounded-xl border border-border bg-card p-4`):

- número grande: `active_count`, rótulo «ativos nos últimos 15 min»; ao lado «N com
  sessão nas últimas 2 h» (usar `session_minutes`), e «às HH:mm»;
- lista: nome (ligação à ficha `/admin/usage/users/{ulid}` quando `ulid`), email em
  `text-muted-foreground`, etiqueta «Interno» quando `internal`, «há N min» (0 → «agora»);
  pessoas `active` a cheio, as restantes em `text-muted-foreground`;
- vazio: «Ninguém com sessão iniciada neste momento.»;
- botão «Atualizar» → `router.reload({ only: ['online'] })` (visita parcial Inertia).

Tipos em `resources/js/types/usage.ts` (`UsageOnline`, campo `online` em
`UsageOverviewProps`); fixture em `fixtures.ts`.

### Política de Privacidade

Em `LegalDocuments::privacy()`, secção «Métricas de utilização do serviço», a seguir à
frase «Acedem a estes indicadores apenas as pessoas…», acrescentar:

> Para operar o serviço, essas mesmas pessoas podem ver, no momento, que contas têm uma
> sessão iniciada e há quanto tempo foi a última atividade de cada uma. Esta vista lê o
> registo de sessão já descrito em «Dados técnicos», não cria nenhum registo novo e não
> guarda histórico: deixa de mostrar a sessão quando ela expira.

Sem alterar `PrivacyVersion` (a validação jurídica das métricas continua pendente —
docs/legal.md pendência 12; quem decide a versão é o utilizador).

### Testes

- `tests/Feature/Usage/Insights/OnlineNowTest.php` (extends `InsightsTestCase`), inserindo
  linhas em `sessions` (`id`, `user_id`, `ip_address`, `user_agent`, `payload`,
  `last_activity`): ativo (≤15 min), vivo (≤120), expirado (>120, fora), oposição /
  encerramento / anonimizado (fora), impersonação (fora), `internal`, várias sessões da
  mesma pessoa = uma entrada com a mais recente, ordenação, sessão sem `user_id` ignorada,
  `ulid` nulo sem estado agregado.
- `UsageMetricsLegalTest`: a frase nova aparece só depois da ativação.
- `Overview.test.ts`: cartão com contagem, lista, etiqueta «Interno», vazio, botão chama
  `router.reload` com `only: ['online']`.

## 2. «Conta» em vez de «organização»

Só texto e a lista de opções. `organizations.usage_classification`, `UsageSegment`,
`InsightsScope` e as rotas ficam iguais.

- `InsightsChrome::options()['organizations']`: para organizações `personal`, `name` = nome
  do `owner` (fallback: nome da organização); acrescentar `type` (`personal|institutional`).
  Ordenar pelo nome apresentado.
- `UsageFilters.vue`: rótulo «Conta»; opção vazia «Todas».
- `InsightsChrome` aviso: «1 conta está por classificar e não entra na utilização real.» /
  «N contas estão por classificar e não entram na utilização real.»
- `UsageSegment::description()`: «Contas classificadas como utilização real, sem o pessoal
  interno.» / «Contas classificadas como teste e demonstração: dados fictícios, QA,
  ensaios.» / «Contas internas e toda a atividade do pessoal interno, onde quer que seja.» /
  «Contas que ninguém classificou ainda; não entram na utilização real.»
- `UsageClassification::description()` Internal: «Contas da própria equipa, usadas para
  operação.»; rever as outras pela mesma regra.
- Definição «Segmentos» em `InsightsChrome::definitions()`: «Cada conta é classificada à
  mão …».
- `User.vue`: `<dt>` «Organizações» → «Contas»; tile «Última atividade observada (todas as
  contas)».
- `AccountShow.vue`: «uma conta por classificar não entra na utilização real».
- `docs/usage-insights.md`: na secção «Classificação das organizações e segmentos» explicar
  que no painel «conta» = organização pessoal de um professor ou instituição; atualizar as
  frases citadas; secção nova «Ligados agora» (fonte, exclusões, impersonação, sem histórico).
- Atualizar os testes PHP/Vitest que afirmam os textos antigos (grep «organizações estão
  por classificar», «Organização», descrições dos segmentos).

## 3. Versão

0.189.0: `config/app.php` e entrada no `CHANGELOG.md` no formato das anteriores.

## Fora de âmbito

Regra por omissão das contas novas; classificação na lista de acompanhamento; visitantes
sem sessão autenticada; auto-refresh; histórico de presenças.
