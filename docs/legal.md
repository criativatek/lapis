# Páginas legais — Termos e Privacidade

> ⚠️ **TEXTO INICIAL TÉCNICO/FACTUAL — REQUER REVISÃO JURÍDICA ANTES DA
> DIVULGAÇÃO A UTILIZADORES REAIS.**
>
> Este aviso vive aqui e no topo de
> [`app/Support/Legal/LegalDocuments.php`](../app/Support/Legal/LegalDocuments.php).
> **Não** é apresentado como banner ao utilizador final: um aviso desses na
> própria página convida a não confiar nela, e o problema que resolve é
> interno.

## O que existe

| | |
|---|---|
| `/termos` | `LegalController::terms` → `legal/Document` |
| `/privacidade` | `LegalController::privacy` → `legal/Document` |
| Texto | [`App\Support\Legal\LegalDocuments`](../app/Support/Legal/LegalDocuments.php) |
| Identidade do responsável | `config('lapis.legal.*')`, tudo `null` por omissão |
| Testes | [`tests/Feature/LegalPagesTest.php`](../tests/Feature/LegalPagesTest.php) |

Públicas, sem `auth` e sem `organization`: quem precisa de as ler antes de
criar conta tem de as conseguir abrir sem ter conta. Indexáveis, cada uma
canonical a si própria, e listadas no `sitemap.xml` e no `robots.txt`.

**O texto está em PHP e não nos componentes Vue.** Com o SSR do Inertia
desligado, texto escrito dentro de um `.vue` não chega à resposta e nenhum
teste de servidor lhe pode tocar. Daqui viaja no payload do Inertia — que está
no HTML — e os testes conseguem afirmar que a secção de menores existe, que a
descrição da IA não promete o que o produto não faz, e que nenhum placeholder
é apresentado como facto.

## A auditoria que sustenta o texto

Cada afirmação foi verificada no código antes de ser escrita:

| Afirmação | Onde foi verificada |
|---|---|
| Identidade do aluno cifrada, resto por pseudónimo | `student_identities` (`display_name`/`school_number` binários, cast `encrypted`), `students` só com `pseudonym_code` |
| Índice cego para pesquisa exata | `display_name_index` (HMAC), `App\Support\Privacy\BlindIndex` |
| Sessões guardam IP e navegador | migração `sessions`: `ip_address`, `user_agent` |
| Registo de atividade **não** guarda IP nem navegador | migração `audit_events` — só causer, evento, subject, summary, properties |
| Só cookies necessários e funcionais | `config/session.php`, `HandleAppearance` (`appearance`), `HandleInertiaRequests` (`sidebar_state`) |
| Sem analytics, marketing ou terceiros no browser | procura por gtag/GA/GTM/Hotjar/Meta/Segment/PostHog/Sentry/Matomo/Plausible: **zero ocorrências** |
| Tipos de letra alojados no próprio domínio | HTML de produção: `https://lapispro.com/build/assets/instrument-sans-*.woff2` — nenhum pedido a terceiros |
| IA envia texto pseudonimizado e com números mascarados | `ReportWritingAssistant`, `PseudonymMap`, `ProtectedFacts`, `RewriteGuard` |
| IA pode estar desligada | `config('lapis.ai.driver')` a `null` — e está, em produção |
| Encerramento com janela e depois anonimização | `RequestPersonalAccountClosure`, `AnonymiseClosedAccount`, `retention:execute` |
| Exportação dos próprios dados em todos os planos | `DataExportController`, sem `module:` |
| Limpezas automáticas de ficheiros temporários | `routes/console.php`, cinco tarefas horárias |
| Cópias de segurança regulares | `scripts/backup-database.sh`, cron diário |

## Identidade do responsável — **em falta**

Nada no repositório dizia quem é a entidade responsável. Os quatro valores
estão em `config/lapis.php` e **todos a `null`**:

```
LAPIS_LEGAL_CONTROLLER_NAME=
LAPIS_LEGAL_CONTROLLER_VAT=
LAPIS_LEGAL_CONTROLLER_ADDRESS=
LAPIS_LEGAL_PRIVACY_EMAIL=
```

Enquanto estiverem vazios, a página mostra **«Por definir»** em cada linha e um
aviso de que serão acrescentados — nunca um nome plausível. Há um teste que
falha se alguém encher um destes com um exemplo para «ficar bem».

**Isto é um gate antes de qualquer divulgação pública.** Uma política de
privacidade sem responsável identificado não cumpre o RGPD.

## Pontos que exigem validação jurídica

Escritos de forma prudente, e todos por confirmar:

1. **Identidade do responsável** — nome, NIF, morada e endereço de privacidade. Em falta.
2. **Bases legais** — o texto diz «execução do contrato» para os dados do professor e remete a responsabilidade dos dados de aluno para o professor/instituição. A repartição exata de responsabilidades (responsável vs. subcontratante) não foi validada.
3. **Dados de menores** — o enquadramento aplicável, e o que o LÁPIS deve exigir da instituição, está por confirmar.
4. **Subprocessadores** — alojamento, rede e email transacional existem, mas a relação jurídica não está clara e **nenhum é nomeado**. A secção está criada e vazia de propósito.
5. **Retenção** — os prazos técnicos reais estão descritos em termos gerais. A diferenciação Base +2 / Pro +5 da Matriz **não está implementada** e por isso **não é afirmada**.
6. **Limitação de responsabilidade** — formulação prudente, sem cláusulas agressivas, mas não validada.
7. **Direitos dos titulares** — os que a aplicação suporta estão descritos; limitação e oposição remetem para contacto. Falta confirmar o conjunto exato aplicável.
8. **Lei aplicável e jurisdição** — **não estão declaradas**. Nenhum foro foi escolhido.
9. **Transferências internacionais** — **não são mencionadas**, por não haver informação verificada sobre a localização dos subprocessadores.
10. **Autoridade de controlo** — referida genericamente, sem nomear entidade.
11. **Cookies** — a auditoria técnica não encontrou cookies não essenciais, e por isso não há pedido de consentimento. A conclusão jurídica de que nenhum é necessário fica por confirmar.
12. **Fornecedor de IA** — nenhum está configurado. Antes de ativar IA real, tem de ser identificado nesta página como subprocessador.

## Quando o texto mudar

Atualizar a data em `config('lapis.legal.terms_effective_from')` /
`privacy_effective_from`. São escritas à mão de propósito: uma alteração ao
texto legal é um ato deliberado, não algo que deva mover-se sozinho a cada
deploy.
