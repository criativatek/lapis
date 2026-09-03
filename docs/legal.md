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

### Atualização de 2026-09-03 — acesso técnico

Os Termos, a Política de Privacidade e o Acordo de Tratamento de Dados passam a
descrever expressamente o acesso técnico às contas: pessoal especificamente
autorizado, finalidades limitadas de assistência, diagnóstico, manutenção,
segurança, investigação de incidentes e verificação do funcionamento, sem
depender de pedido prévio, e sempre identificado como acesso técnico no registo
de atividade.

O acesso já existia como impersonation; esta alteração acrescenta autorização
específica através da capability `is_support_technician`, motivo obrigatório e
preservação do ator real através de `AuditLog::record()` no registo de atividade.
Os três documentos recebem a versão `2026-09-03`. Não há nova aceitação forçada:
os Termos e o Acordo mantêm o fluxo existente, e a Política volta a apresentar o
aviso não bloqueante já implementado, porque `User::shouldSeePrivacyNotice()`
compara `privacy_notice_dismissed_at` com
`config('lapis.legal.privacy_effective_from')`. O desenho do mecanismo em si —
porquê reforçar a impersonação existente em vez de construir outra, e a
correção do ator real no registo de atividade — está em
[ADR-0014](adr/0014-technical-support-access.md).

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
| Acordo de Tratamento de Dados | Os dados dos **alunos** | **subcontratante do responsável pelo tratamento** |

**A repartição é a decisão estrutural desta fatia — e não pode pressupor quem é
o responsável.** Um professor pode exercer a atividade a título próprio e
determinar ele mesmo as finalidades: é então o responsável. Outro atua sob a
autoridade de uma escola ou agrupamento, e nesse caso a responsável é a
instituição. **Os textos não escolhem por ele.** O princípio publicado é:

> «Quem introduz dados de alunos no Lapispro deve fazê-lo enquanto responsável
> pelo tratamento ou devidamente autorizado pelo responsável pelo tratamento
> competente. Quando a HORIZONLEVEL trata esses dados apenas para prestar o
> serviço, atua como subcontratante.»

**Não há gate, declaração nem caixa de seleção** a exigir que alguém diga em que
qualidade age. A formulação comporta as duas situações e não obriga o professor
a fazer, no ecrã de registo, uma qualificação jurídica que pode não saber fazer.

O que é constante é o outro lado, e é isso que a Política declara em vez de uma
afirmação sobre quem é o responsável:

> «Relativamente aos dados pedagógicos dos alunos tratados por conta do
> responsável pelo tratamento, a HORIZONLEVEL não determina as respetivas
> finalidades pedagógicas nem define a base jurídica aplicável.»

Há um teste (`the_controller_processor_split_is_stated_in_both_directions`) que
falha se algum documento voltar a afirmar genericamente que o professor é o
responsável, ou se a HORIZONLEVEL passar a reclamar finalidades próprias.

**O Acordo é deliberadamente curto.** Não é um contrato negociado com um cliente
empresarial: é o enquadramento do acesso feito através de uma conta individual.
Vinte páginas com cláusulas de auditoria presencial não seriam mais protetoras —
seriam apenas menos lidas.

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
por conta do responsável pelo tratamento.

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
- São **seis** funcionalidades desde a 0.86.0, e a distinção entre elas é
  relevante para esta análise. Uma não vê dados pedagógicos de todo (o
  assistente do Centro de Ajuda). Três **interpretam** resultados que o motor
  determinístico já produziu e devolvem texto (estatística da turma, resultados
  do período, síntese de acompanhamento). Uma **propõe** estratégias que só o
  professor pode criar. Uma **reescreve** texto que o Lapispro já compôs, e aí
  o `RewriteGuard` recusa o retorno se um valor tiver sido alterado.
- Nenhuma delas escreve. `AiNonWriteTest` tira um checksum de todas as tabelas
  pedagógicas antes e depois de cada uma e falha se um byte mudar.
- Não há decisões automatizadas com efeitos jurídicos ou significativos: nada do
  que um modelo devolve produz por si só um efeito na avaliação de um aluno.
- O anexo III do Regulamento cobre sistemas usados para **avaliar resultados de
  aprendizagem** e para **determinar acesso ou admissão**. Que o produto caia ou
  não nessa alínea depende de uma leitura que não é nossa — mas os três factos
  acima são o que a análise vai precisar, e estão verificados no código.
- Antes de ativar um fornecedor real, esta análise tem de estar feita.

### Corrigido na fatia AI-complete — descrição factual, **redação por validar**

A fatia `feat/ai-complete-suite` (0.86.0) alargou o módulo de IA de duas
funcionalidades para seis, e duas passagens do texto público ficaram incompletas
ou falsas por causa disso. **Foram corrigidas, por instrução expressa do
responsável do produto na revisão pré-commit**, e a correção limitou-se à
descrição factual do que a aplicação faz. Nenhuma cláusula, base legal,
repartição de responsabilidades, prazo de conservação ou direito foi tocado.

**A redação continua por validar por jurista** — ver a lista «Pontos que
continuam a exigir validação jurídica» — mas já não descreve um comportamento
diferente do real, que era o problema.

**1. Política de Privacidade → «Inteligência artificial» → 2.º parágrafo**

*Era:* «Quando disponível, é usada para ajudar a interpretar resultados que o
Lapispro já calculou, propor estratégias pedagógicas e aperfeiçoar a redação de
um relatório já composto.»

*Porque estava errado:* incompleto. Continuava verdadeiro, mas eram três
funcionalidades de seis.

*Agora:* enumera as seis, e diz explicitamente que o assistente do Centro de
Ajuda não recebe dados pedagógicos de espécie alguma.

**2. Política de Privacidade → «Inteligência artificial» → 3.º parágrafo**

*Era:* «Antes de qualquer texto sair da aplicação, os nomes que o Lapispro
conhece são substituídos por designações genéricas e **todos os números e datas
por marcadores**. Não são enviados o resto do relatório, a turma, a pauta, **os
resultados, as classificações, as autoavaliações**, os registos, a identidade da
escola nem o nome do professor.»

*Porque estava errado:* **era falso**, e não apenas incompleto. Descrevia com
rigor «Aperfeiçoar redação» — onde continua verdadeiro, e onde o `RewriteGuard`
o garante — mas enunciava-o como regra do módulo inteiro. A análise da
avaliação, a análise da estatística e a síntese de acompanhamento enviam
precisamente resultados, classificações e autoavaliações: é esse o seu objeto, e
sem eles não teriam nenhum. A formulação absoluta «todos os números… por
marcadores» era, para essas três, o contrário do que acontece.

*Agora:* está separado em camadas. Um parágrafo diz que informação pedagógica
**pode** ser enviada e enumera o quê; outro enumera o que nunca é enviado
(identificadores diretos, contactos, identidade da escola e do professor); outro
diz que o texto livre sobre um aluno não é enviado nesta versão, e porquê; outro
descreve o pipeline real — allowlist campo a campo, pseudonimização no momento
da entrada, sanitização sobre o texto montado; e a garantia mais restritiva dos
relatórios fica como o que sempre foi, uma propriedade **daquela** funcionalidade.

*Vocabulário:* «pseudonimização» e «minimização», nunca «anonimização». O texto
diz explicitamente que o professor, com a pauta à frente, reconhece cada linha.

**3. Acordo de Tratamento de Dados → «Objeto, duração e natureza do tratamento»**

*Porque estava errado:* a enumeração da natureza do tratamento — «recolher,
guardar, organizar, calcular, consultar e devolver» — não previa transmitir
nada a um subcontratante de IA. Não era falso enquanto não existe fornecedor
configurado, mas descrevia uma natureza de tratamento mais estreita do que a que
a aplicação passa a ter quando as funcionalidades forem ativadas.

*Agora:* acrescenta a transmissão de um subconjunto pseudonimizado e minimizado,
apenas no momento em que o professor faz o pedido, com remissão para a secção de
IA da Política — e diz que nesta data nenhum fornecedor está configurado.

**4. Acordo de Tratamento de Dados → «Outros subcontratantes»**

*Agora:* diz que um fornecedor de IA seria um subcontratante da mesma natureza e
sujeito às mesmas regras, que nenhum existe nesta data, e que a identificação na
lista precede qualquer tratamento real. Coerente com a Política, e não nomeia
fornecedor nenhum.

**O que foi verificado e continua verdadeiro** (nada a alterar):

- «A IA do Lapispro sugere. O professor decide.» — agora com um teste que faz um
  retrato completo da base de dados antes e depois de cada funcionalidade de IA
  e falha se um único byte pedagógico mudar (`AiNonWriteTest`).
- «Não existem decisões automatizadas com efeitos jurídicos ou significativos.»
- «Os dados não são usados para treinar modelos.»
- «As funcionalidades de IA podem estar desativadas.» — reforçado: cada ecrã
  funciona na íntegra sem IA, e há testes por funcionalidade que o afirmam.
- Termos → «Quando as funcionalidades de inteligência artificial estão
  disponíveis, sugerem — nunca decidem…»
- Subcontratantes → «Não está configurado qualquer fornecedor de inteligência
  artificial, pelo que nenhum dado é enviado para um.» — verdadeiro nesta data
  em produção. **Deixa de o ser no instante em que um fornecedor for
  configurado**, e essa é a mesma revisão que a análise do Regulamento da IA
  acima: nenhuma das duas pode ficar para depois da ativação.

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
- [ ] **Acordo de subcontratação institucional** — celebrado com a instituição,
      para a utilização com vários professores sob administração comum.
      Documento próprio; o de `/tratamento-de-dados` enquadra o acesso por conta
      individual e diz-lo expressamente.
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
  lei». **Nunca um foro exclusivo** — uma cláusula de foro exclusivo é do género
  que um tribunal desconsidera quando contraposta a normas imperativas, e não há
  vantagem em arriscá-la. Há um teste que falha se «foro exclusivo» ou «comarca
  de Leiria» entrarem no texto.
- **Consumidor: nunca assumido.** Um professor pode contratar no exercício de
  uma atividade profissional e não ser consumidor. Os Termos não o qualificam:
  usam uma ressalva condicional — «quando lhe seja aplicável a legislação de
  proteção dos consumidores, mantém integralmente os direitos que dela
  resultem» — que é verdadeira nos dois casos. Há um teste a garanti-lo.
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
11. **Descrição pública da IA depois da fatia AI-complete** — a secção
    «Inteligência artificial» da Política e duas passagens do Acordo foram
    **reescritas na 0.86.0** para descreverem o que a aplicação faz: que
    informação pedagógica pode chegar ao fornecedor, que dados nunca chegam, e
    qual é o pipeline real. A correção é factual e está detalhada em «Corrigido
    na fatia AI-complete» acima; `LegalPagesTest` impede que volte a afirmar o
    contrário. **O que fica por validar é a redação**, como em todo o resto
    desta lista — já não é uma afirmação que se saiba falsa.

## Quando o texto mudar

Atualizar a data em `config('lapis.legal.terms_effective_from')`,
`privacy_effective_from` ou `processing_effective_from`. São escritas à mão de
propósito: uma alteração ao texto legal é um ato deliberado, não algo que deva
mover-se sozinho a cada deploy.

**Mudar `terms_effective_from` muda a versão que é registada nas contas novas.**
As contas já existentes continuam com a versão que aceitaram — o que é o
comportamento correto, e é também o sinal de que é preciso pedir nova aceitação.
