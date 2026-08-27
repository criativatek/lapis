# Documentos legais — Termos, Privacidade e Tratamento de Dados

> ⚠️ **TEXTO TÉCNICO/FACTUAL — REQUER REVISÃO JURÍDICA ANTES DA DIVULGAÇÃO A
> UTILIZADORES REAIS.**
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
| `/tratamento-de-dados` | `LegalController::processing` → `legal/Document` |
| Texto | [`App\Support\Legal\LegalDocuments`](../app/Support/Legal/LegalDocuments.php) |
| Identidade do responsável | `config('lapis.legal.*')` — confirmada, ver abaixo |
| Testes | [`tests/Feature/LegalPagesTest.php`](../tests/Feature/LegalPagesTest.php), [`tests/Feature/Legal/TermsAcceptanceTest.php`](../tests/Feature/Legal/TermsAcceptanceTest.php) |

As três são públicas, sem `auth` e sem `organization`: quem precisa de as ler
antes de criar conta tem de as conseguir abrir sem ter conta. Indexáveis, cada
uma canonical a si própria, e listadas no `sitemap.xml` e no `robots.txt`. As
três remetem umas para as outras através de `related`.

**O texto está em PHP e não nos componentes Vue.** Com o SSR do Inertia
desligado, texto escrito dentro de um `.vue` não chega à resposta e nenhum
teste de servidor lhe pode tocar. Daqui viaja no payload do Inertia — que está
no HTML — e os testes conseguem afirmar que a secção de menores existe, que a
descrição da IA não promete o que o produto não faz, e que nenhum placeholder
é apresentado como facto.

## Porquê três documentos

Porque são três figuras jurídicas distintas, e juntá-las esconde a única que
importa perceber.

| Documento | Sobre o quê | A HORIZONLEVEL é |
|---|---|---|
| Termos de Utilização | A relação comercial e de utilização | contraparte |
| Política de Privacidade | Os dados da **conta do professor** | **responsável pelo tratamento** |
| Acordo de Tratamento de Dados | Os dados dos **alunos** | **subcontratante do professor** |

**A repartição é a decisão estrutural desta fatia.** O professor decide que
alunos existem, que dados sobre eles são registados e para que servem — logo é
ele quem determina as finalidades, e é ele o responsável. O Lapispro guarda,
calcula e devolve, seguindo o que ele determinou.

Reclamar uma base legal própria sobre dados pedagógicos seria afirmar um poder
de decisão sobre a avaliação de menores que o produto não tem e não quer. Há um
teste (`the_controller_processor_split_is_stated_in_both_directions`) que falha
no dia em que alguém escrever o contrário.

**O Acordo é deliberadamente curto.** Não é um contrato negociado com um cliente
empresarial: é o enquadramento de um professor que criou conta sozinho e vai lá
pôr os alunos da sua turma. Vinte páginas com cláusulas de auditoria presencial
não seriam mais protetoras — seriam apenas menos lidas.

## Bases legais — só para a conta

| Base | Finalidades |
|---|---|
| Execução do contrato | Criar e manter a conta, disponibilizar o plano, suporte, encerramento |
| Obrigação jurídica | Conservação imposta por lei, pedidos de autoridades, obrigações fiscais |
| Interesse legítimo | Segurança das contas, prevenção de abuso, registo de atividade, cópias de segurança |

O interesse legítimo vem **ponderado** no texto, não apenas invocado, e a
ponderação aponta para uma restrição concreta: o registo de atividade não guarda
IP nem navegador.

**Nenhuma destas bases é invocada para os dados dos alunos**, que são tratados
por conta do professor.

## Aceitação dos Termos

`users.terms_version` + `users.terms_accepted_at`, escritas por
`CreateNewUser` na mesma transação da conta.

- **A versão é a data de entrada em vigor**, e não um número à parte — ver
  `LegalDocuments::termsVersion()`. Dois valores que têm de concordar são duas
  oportunidades para deixarem de concordar.
- **A versão vem do servidor, nunca do pedido.** Um campo de formulário a dizer
  que versão foi aceite é um campo que o cliente altera.
- **Não se guarda IP nem navegador.** Seria recolher dados de tráfego, com base
  legal mais frágil, para uma finalidade que duas colunas já cumprem. Há um
  teste a garantir que a tabela não ganha onde os pôr.
- **Sem caixa de seleção.** Aceitar os Termos é condição do contrato, não uma
  escolha separada; uma checkbox obrigatória não acrescenta consentimento
  nenhum, só um passo. A página de registo diz o que se está a aceitar e liga
  para os três documentos.
- **Nulo é um estado legítimo**: contas anteriores à coluna, e contas
  provisionadas por um administrador no backoffice, ficam a `null`. Não se
  inventa uma aceitação que ninguém deu.

> **Por fechar:** as contas provisionadas em `/admin/accounts/create` nunca
> passam pelo formulário de registo e por isso nunca registam aceitação. São
> raras e criadas com acompanhamento; o mecanismo de recolha para esse caminho
> é uma fatia própria.

## A auditoria que sustenta o texto

Cada afirmação foi verificada no código antes de ser escrita:

| Afirmação | Onde foi verificada |
|---|---|
| Identidade do aluno cifrada, resto por pseudónimo | `student_identities` (`display_name`/`school_number` binários, cast `encrypted`), `students` só com `pseudonym_code` |
| Índice cego para pesquisa exata | `display_name_index` (HMAC), `App\Support\Privacy\BlindIndex` |
| Sessões guardam IP e navegador | migração `sessions`: `ip_address`, `user_agent` |
| Registo de atividade **não** guarda IP nem navegador | migração `audit_events` — só causer, evento, subject, summary, properties |
| **Sem campos de saúde, NEE ou categorias especiais** | `docs/domain-model.md` §11.3; `enrollments.import_note` documenta a exclusão deliberada |
| Medidas de suporte descrevem a ação, não o estatuto | `App\Models\SupportMeasureLevel` — docblock explícito |
| Só cookies necessários e funcionais | `config/session.php`, `HandleAppearance` (`appearance`), `HandleInertiaRequests` (`sidebar_state`) |
| Armazenamento local: tema e rascunhos de grelha | `useAppearance.ts`, `useGridDraft.ts` |
| Palavra-passe é hash irreversível, não cifra | `User`, cast `'password' => 'hashed'` |
| Sem analytics, marketing ou terceiros no browser | procura por gtag/GA/GTM/Hotjar/Meta/Segment/PostHog/Sentry/Matomo/Plausible: **zero ocorrências** |
| Tipos de letra alojados no próprio domínio | HTML de produção: `https://lapispro.com/build/assets/instrument-sans-*.woff2` |
| IA envia texto pseudonimizado e com números mascarados | `ReportWritingAssistant`, `PseudonymMap`, `ProtectedFacts`, `RewriteGuard` |
| IA pode estar desligada | `config('lapis.ai.driver')` a `null` — e está, em produção |
| Encerramento com janela e depois anonimização | `RequestPersonalAccountClosure`, `AnonymiseClosedAccount`, `retention:execute` |
| Exportação dos próprios dados em todos os planos | `DataExportController`, sem `module:` |
| Prazos publicados = prazos executados | `config/retention.php`, lido pelo texto e pelas rotinas |

## Prazos de conservação — o que se publica e o que não

**Regra: publica-se o prazo que uma rotina cumpre. Onde nenhuma rotina o cumpre,
a página diz que não há prazo em vez de inventar um.**

| Prazo | Valor | Quem o executa |
|---|---|---|
| Encerramento de conta pessoal | `retention.personal_account_closure_days` (60 dias) | `retention:execute`, diário às 03:40 |
| Disponibilidade de uma exportação | `retention.data_export_availability_hours` (24 h) | `data-exports:prune`, horário |
| Ficheiros temporários de importação/exportação | 6 h (pautas, INOVAR) / 24 h (grelhas) | quatro `*:prune` horários |
| Registos técnicos do servidor | 14 dias | `LOG_DAILY_DAYS`, rotação do Laravel |
| Cópias de segurança | 30 diárias + 12 mensais | `scripts/backup-database.sh` |

**Não publicados, por não existir rotina:**

- `retention.pedagogical_previous_years_retained` (3 anos letivos) — lido por
  `RetentionPolicy`, mas **nada apaga por antiguidade**. A diferenciação
  Base +2 / Pro +5 da Matriz continua por implementar.
- `retention.security_audit_years` (3 anos) — não há prune de `audit_events`.

Os dois primeiros valores são lidos pelo texto legal a partir do `config`, e não
escritos à mão. Um texto que diz «60 dias» enquanto o comando apaga aos 90 é
pior do que um texto que não diz prazo nenhum.

## Subcontratantes — a auditoria factual

**Publicam-se apenas os que se conseguem comprovar a partir do repositório.**

| Fornecedor | Papel | Prova | Constituição |
|---|---|---|---|
| Contabo GmbH | VPS onde correm aplicação, base de dados, ficheiros e cópias | `docs/deployment.md` §1 (`161.97.80.63`, CloudPanel) | Alemanha |
| Cloudflare, Inc. | DNS, entrega de rede, terminação TLS | `docs/deployment.md` §«Cloudflare / TLS» | EUA |

**Não publicados:**

- **Servidor de correio transacional.** O SMTP é configurado pelo operador em
  `/admin/settings` (`platform_settings`), não no `.env` nem no repositório — não
  é comprovável a partir do código. A página diz isso, em vez de nomear um
  fornecedor plausível. **Nomear assim que estiver verificado.**
- **Fornecedor de IA.** Não há nenhum configurado (`lapis.ai.driver` a `null`).
  Antes de ativar IA real, tem de ser identificado na Política.

Há um teste (`only_verifiable_subprocessors_are_named`) que falha se aparecer na
página um nome plausível mas não verificado.

### Transferências

- A infraestrutura de dados está num único servidor, alojado por uma sociedade
  **alemã**. A localização física do datacentro **não foi confirmada** e por isso
  não é afirmada.
- O tráfego passa pela rede da **Cloudflare**, sociedade **norte-americana** com
  pontos de presença em vários países. A página diz que não pode afirmar que
  todo o tratamento por essa via ocorre no EEE.
- **Não se invocam cláusulas contratuais-tipo nem qualquer outro mecanismo de
  transferência**, porque nenhum foi verificado. A avaliação está declarada como
  em curso.

## Nota interna — Regulamento da IA

**Fica aqui e não na página.** A qualificação de um sistema ao abrigo do
Regulamento (UE) 2024/1689 é uma análise jurídica, e uma página que a afirme
está a decidir uma questão que ninguém decidiu. Há um teste
(`the_ai_section_makes_no_regulatory_risk_classification`) que falha se as
palavras «risco elevado», «alto risco», «AI Act» ou equivalentes entrarem no
texto público.

O que é factual e relevante para essa análise futura:

- A IA **não** atribui, altera nem decide qualquer classificação. Todos os
  motores de cálculo são determinísticos (`ScaleProposalResolver` e
  companhia); o modelo nunca lhes toca.
- As duas funcionalidades são **sugerir estratégias** e **aperfeiçoar redação**
  de texto que o Lapispro já compôs. `RewriteGuard` recusa o retorno se um valor
  tiver sido alterado.
- Não há decisões automatizadas com efeitos jurídicos ou significativos: nada do
  que um modelo devolve produz por si só um efeito na avaliação de um aluno.
- O anexo III do Regulamento cobre sistemas usados para **avaliar resultados de
  aprendizagem** e para **determinar acesso ou admissão**. Que o produto caia ou
  não nessa alínea depende de uma leitura que não é nossa — mas os três factos
  acima são o que a análise vai precisar, e estão verificados no código.
- Antes de ativar um fornecedor real, esta análise tem de estar feita.

## Portão institucional

**O módulo Institucional não está disponível para adesão, e o lançamento é para
professores individuais.** A landing di-lo (`PLAN_COPY.institutional.priceNote`
e a FAQ), os Termos dizem-no, e o Acordo diz que o documento institucional
**ainda não existe**.

**O portão já é estrutural, não uma promessa:** não há caminho self-service para
uma organização institucional. `CreateInstitutionalOrganization` só é alcançável
por um administrador da plataforma, através de `AdminAccountController`. O
docblock dessa ação remete para esta secção.

Antes de criar a primeira organização institucional real, tem de estar fechado:

- [ ] **Contrato institucional** — a relação comercial com a escola ou
      agrupamento, distinta dos Termos individuais.
- [ ] **Acordo de subcontratação institucional** — com a instituição como
      responsável, e não o professor. Documento próprio; o de
      `/tratamento-de-dados` é para o caso individual e diz-lo expressamente.
- [ ] **Papéis internos** — administrador, coordenador, professor: quem vê o quê
      dentro da instituição, e com que fundamento.
- [ ] **Prazos de conservação acordados** com a instituição, incluindo o que
      acontece aos dados quando um professor sai.
- [ ] **Quem responde pelos dados de um aluno** quando dois professores da mesma
      instituição o registam.
- [ ] Revisão dos textos legais para o caso institucional, que hoje aparecem
      apenas como «ainda não disponível».

## Identidade do responsável — **confirmada (2026-08-27)**

| | |
|---|---|
| Entidade | HORIZONLEVEL, LDA |
| NIF | 513354166 |
| Morada | Rua do Verde Pinho, n.º 133, 2415-609 Leiria, Portugal |
| Privacidade / RGPD | `privacidade@lapispro.com` |

**São defaults em `config/lapis.php`, não apenas variáveis de ambiente.** São um
facto sobre o produto, idêntico em todas as instalações; deixá-los só no `.env`
significaria que produção mostraria «Por definir» até alguém definir quatro
variáveis. As variáveis `LAPIS_LEGAL_*` continuam a existir para quem precise de
as sobrepor.

O mecanismo de «Por definir» **mantém-se** para o caso de uma instalação as
limpar, e continua testado.

## Contactos oficiais, e onde cada um é usado

| Endereço | Usado em |
|---|---|
| `privacidade@lapispro.com` | Privacidade: «Quem responde pelo quê» e «Os seus direitos»; Acordo: «Informação e verificação» |
| `suporte@lapispro.com` | Privacidade: finalidade «Suporte» |
| `contas@lapispro.com` | Termos: «A sua conta» — uma única vez |

**A Criativatek não é a entidade jurídica e não aparece em nenhum dos
documentos** — há teste a garanti-lo.

> **Nota técnica, fora do âmbito desta fatia:** o remetente do email
> transacional em produção continua a ser `lapis@criativatek.com`. O
> alinhamento com `contas@lapispro.com` é uma fatia própria — e é a mesma que
> permitirá nomear o fornecedor de correio nos subcontratantes.

## Lei aplicável e foro

- **Lei portuguesa**, declarada.
- **Foro prudente:** «os tribunais territorialmente competentes nos termos da
  lei». **Nunca um foro exclusivo** — uma cláusula de foro exclusivo contra quem
  contrata como consumidor é precisamente do género que um tribunal
  desconsidera, e o professor individual contrata como consumidor. Há um teste
  que falha se «foro exclusivo» ou «comarca de Leiria» entrarem no texto.
- **Autoridade de controlo: CNPD**, nomeada.

## Pontos que continuam a exigir validação jurídica

Escritos de forma prudente, e por confirmar:

1. **Repartição responsável/subcontratante** — a leitura adotada (professor
   responsável, Lapispro subcontratante) é a que corresponde ao que o produto
   faz, mas não foi validada por jurista.
2. **Enquadramento dos dados de menores** — a posição adotada é que o
   tratamento não assenta em consentimento dado ao Lapispro. Por confirmar.
3. **Categorias especiais em texto livre** — o modelo de dados não as tem, mas
   um campo de observações aceita o que lá escreverem. A formulação adotada
   pede que não se escreva; a suficiência dessa formulação é por validar.
4. **Cookies** — a auditoria técnica não encontrou cookies não essenciais. A
   qualificação jurídica de cada elemento fica por confirmar, e o texto diz isso
   em vez de concluir.
5. **Transferências internacionais** — ver acima. Avaliação por fazer.
6. **Limitação de responsabilidade** — formulação prudente, não validada.
7. **Direitos dos titulares** — a distinção entre a exportação do produto e o
   direito de portabilidade está feita; o conjunto exato aplicável por
   confirmar.
8. **Regulamento da IA** — nota interna acima; nenhuma classificação pública.
9. **Livro de Reclamações / RAL de consumo** — os Termos referem os meios de
   resolução alternativa «legalmente previstos» sem nomear entidade nem
   declarar adesão. Se existir obrigação de disponibilizar livro de reclamações
   eletrónico, é aqui que entra.
10. **Contrato e acordo institucionais** — ver «Portão institucional».

## Quando o texto mudar

Atualizar a data em `config('lapis.legal.terms_effective_from')`,
`privacy_effective_from` ou `processing_effective_from`. São escritas à mão de
propósito: uma alteração ao texto legal é um ato deliberado, não algo que deva
mover-se sozinho a cada deploy.

**Mudar `terms_effective_from` muda a versão que é registada nas contas novas.**
As contas já existentes continuam com a versão que aceitaram — o que é o
comportamento correto, e é também o sinal de que é preciso pedir nova aceitação.
