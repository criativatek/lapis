# Utilização e acompanhamento — decisões pendentes (0.163.0)

Este documento junta o que **ainda não está decidido** para o painel `/admin/usage`
e o que foi preparado para essas decisões. As regras em vigor estão em
[usage-insights.md](usage-insights.md).

> **Proposta técnica ≠ validação jurídica.** As secções «Proposta» descrevem o que
> o código faz ou pode fazer, e porquê. As secções «Para validação jurídica»
> listam perguntas que **não** ficam respondidas por este documento. Nada aqui
> declara conformidade com o RGPD. A pendência n.º 12 de [legal.md](legal.md)
> continua aberta.

**Estado por omissão desta versão:** a recolha está **desligada**. Não há chave
de configuração para a ligar: só `php artisan lapis:usage-collection start
--operator=<email>` abre um período de recolha, com auditoria, e a migração não
abre nenhum. Enquanto estiver desligada, não se grava nenhum evento, a agregação
não lê dados de pessoas, o painel diz que não há recolha e não gera sinais, e a
Política de Privacidade não muda.

## 1. Classificação das organizações para métricas

### O que a investigação encontrou (só leitura)

- `organizations.is_test_account` foi criado na 0.91.0 para o **pré-voo
  comercial** (`lapis:commercial-preflight`): bloqueava o deploy por haver
  subscrições sem condição comercial registada numa população em que ninguém
  tinha contrato. O significado escrito na migração, na ação `SetTestAccount` e
  no próprio ecrã de admin é **«ninguém prometeu nada a esta conta»** — e o ecrã
  diz que a marca «serve apenas para o pré-voo comercial saber de quem não tem de
  perguntar».
- **Efeitos atuais:** o pré-voo comercial conta estas contas à parte e não
  bloqueia por elas; a ficha da conta mostra o selo «conta de teste». Não mexe em
  plano, módulos, limites, versão, acesso, retenção nem IA (a migração e a ação
  dizem-no e o código confirma: não há outros leitores).
- **Como foi atribuída em produção:** em bloco, a 2026-08-30, com
  `lapis:mark-test-accounts --created-before=2026-08-30` — todas as organizações
  criadas antes dessa data. Foi uma decisão comercial («não há clientes
  pagantes»), não uma avaliação, conta a conta, de dados fictícios. Entre elas
  estão as de professores parceiros convidados a experimentar, que podem estar a
  usar o produto no seu trabalho, com alunos reais.
- **Conclusão técnica:** o campo **não distingue** dados fictícios de utilização
  real e tem outra finalidade. Usá-lo para segmentar métricas faria desaparecer
  da «utilização real» precisamente os professores parceiros, e daria à marca
  comercial um segundo significado que ninguém lhe atribuiu.

### Proposta (implementada nesta versão, sem classificar ninguém)

- Classificação **própria** para métricas: `organizations.usage_classification`
  com quatro valores — **Por classificar** (omissão), **Utilização real**,
  **Teste e demonstração**, **Interna** — e `usage_classified_at`.
- **Nada é inferido**: nem do `is_test_account`, nem do nome, do email, do plano
  ou da atividade. Todas as organizações existentes e novas nascem «Por
  classificar». Só uma pessoa com a autorização do painel classifica (ficha da
  conta ou `lapis:usage-classify`), com rasto na auditoria **da plataforma** (não
  na da organização, que o responsável institucional lê).
- O painel tem quatro segmentos: **Utilização real** (inicial), **Teste e
  demonstração**, **Interna**, **Por classificar**. Pessoal interno
  (`is_platform_admin`/`is_support_technician`) fica sempre em «Interna».
- `is_test_account` continua intacto e com a sua finalidade comercial.

**Efeitos de cada mudança de classificação:** só a segmentação do painel (e por
isso as contagens de cada segmento, o ranking e a lista filtrada). Nada na conta
da organização: acesso, plano, recolha, conservação e Política não mudam.

### Decidido

- **Contas novas nascem «Por classificar»** (decisão fechada). Nada é presumido
  da origem do registo nem da atividade.

### Decisão que precisa da tua resposta

1. **Classificar as organizações de produção.** A lista atual foi obtida só em
   leitura (consulta `SELECT` numa transação `READ ONLY`, compatível com a
   versão instalada, sem publicar código nem migrar) e entregue **fora do
   repositório**: este documento, a PR e os testes não têm nomes de
   organizações reais. Para cada uma: «real», «teste» ou «interna». Até lá
   ficam «Por classificar». Depois do deploy desta versão, a classificação faz-se
   na ficha da conta ou com `lapis:usage-classify {ulid} {classificação}
   --operator=<email> --note="…"`.

## 2. Conservação

### Que dados existem e para que servem

| Dados | Granularidade | Para que é preciso | Até quando é preciso |
|---|---|---|---|
| `usage_events` | um evento por registo e dia (consultas: um por funcionalidade e dia); chave HMAC | deduplicação no próprio dia; agregação (hoje e ontem, ou mais dias depois de uma paragem do agendador); datas de primeira observação | poucos dias depois de agregado; margem para diagnóstico e recuperação |
| `usage_daily_rollups` | por pessoa, organização, funcionalidade e dia: contagens | todas as páginas do painel: períodos de 7/30/90 dias, semanas, coortes, sinais (até ~6 semanas), ranking | o período mais longo que o painel deve mostrar por pessoa |
| `usage_user_states` | por pessoa: 3 primeiros passos (data + estado «atingido») e a turma disponível desde (configuração, recalculada) | primeiros passos, sinais de conta/turma sem registo | data: 180 dias; estado: enquanto a conta existir (§2.1) |
| `usage_collection_periods` | início e fim de cada período de recolha (sem pessoas) | distinguir «sem recolha» de «sem atividade» | enquanto houver dados de utilização |
| `usage_coverage_gaps` | dias sem dados (só o dia e o motivo; sem pessoas) | não mostrar como zero um dia cujos eventos expiraram por agregar | o mesmo prazo dos agregados |

### Proposta (valores configuráveis; os indicados são os por omissão desta versão)

- **Eventos detalhados: 35 dias** (`LAPIS_USAGE_EVENTS_RETENTION_DAYS`), a contar
  do instante de cada evento, **agregados ou não**. A deduplicação só precisa do
  próprio dia; a agregação só recalcula dias recentes; 35 dias cobrem a
  recuperação de uma paragem do agendador e o diagnóstico de um pico de falhas.
- **Agregados diários por pessoa: 180 dias** (`LAPIS_USAGE_ROLLUPS_RETENTION_DAYS`).
  Cobrem o período mais longo pré-definido (90 dias) com as suas coortes e
  semanas completas, as 6 semanas dos sinais, e um semestre letivo de
  comparação. O intervalo personalizado fica limitado a este prazo.
- **Datas de primeiros passos:** 180 dias, depois só «atingido» (§2.1,
  implementado).
- **Tendências de longo prazo** (comparar anos letivos): **não** com dados por
  pessoa. Se forem precisas, a proposta é uma tabela de totais **sem pessoas**
  (por semana, funcionalidade e segmento) com prazo próprio — não implementada,
  depende da tua decisão.

### Garantias técnicas (implementadas e testadas)

- **O prazo dos eventos é um limite efetivo.** `usage:prune` corre de hora a
  hora e apaga os eventos mais antigos do que o prazo **independentemente da
  agregação**. Nenhum evento vive mais do que 35 dias e uma hora; antes, com uma
  passagem diária e a espera pela agregação, podia viver indefinidamente se a
  agregação parasse.
- **Lacunas em vez de zeros.** Se a agregação esteve parada mais tempo do que o
  prazo, os dias cujos eventos expiram por agregar ficam em `usage_coverage_gaps`
  (só o dia e o motivo) e os agregados parciais desses dias saem. O painel e os
  sinais tratam-nos como «sem recolha»: nunca zero, nunca inatividade. Não há
  reconstituição — a agregação nunca recalcula um dia de lacuna.
- **A limpeza não depende da recolha estar ligada** nem da agregação estar em
  dia.
- O prazo dos eventos nunca é maior do que o dos agregados (se a configuração o
  disser, aplica-se o menor), e a agregação nunca recalcula um dia anterior ao
  limite dos agregados.
- **Oposição e encerramento:** a eliminação apaga eventos, agregados e estado da
  pessoa no mesmo passo, com a linha da pessoa bloqueada; a escrita de um evento
  confirma, no mesmo comando SQL, que a pessoa não se opôs nem pediu o
  encerramento; a agregação filtra essas pessoas ao ler os eventos e apaga, em
  cada passagem, qualquer agregado ou estado que lhes reste.

### 2.1 Datas dos primeiros passos (decidido e implementado)

Para cada primeiro passo (primeira turma, primeiros alunos, primeiro registo
útil), `usage_user_states` guarda:

| Campo | O quê | Até quando |
|---|---|---|
| `first_*_on` | o **dia** (sem hora) da primeira observação | **180 dias** — o prazo dos agregados |
| `first_*_reached` | o estado «atingido», sem data | enquanto a conta existir |
| `class_available_since` | quando a primeira turma ficou disponível — **configuração**, recalculada a partir de `class_teachers`; não é um primeiro passo | — |

- Passados os 180 dias, `usage:prune` (de hora a hora, também com a recolha
  desligada) apaga a data e o passo fica «atingido». O detalhe mostra «Atingido —
  data já não conservada», nunca uma data aproximada.
- Nada volta a datar um passo atingido: nem eventos posteriores, nem dados
  pedagógicos.
- O sinal «Turma sem primeiro registo útil» lê o estado, por isso a data ter
  saído nunca o faz disparar. Os cálculos que precisam de datas (atividade num
  intervalo, semanas, coortes, ranking) leem os agregados e não usam «atingido».
- Oposição, pedido de encerramento e anonimização apagam data e estado; a retoma
  começa sem nenhum.

### Precisão dos prazos (para a Política)

- Eventos: limpeza de hora a hora, corte ao segundo → no máximo **35 dias e uma
  hora**.
- Agregados, lacunas e datas dos primeiros passos do dia D: saem na primeira
  passagem do dia D+181 → 180 dias completos depois de D e, no máximo, mais uma
  hora.
- Se o agendador parar, o atraso é o tempo da paragem (as falhas são
  reportadas).

## 3. Oposição e encerramento da conta

### Implementado (decidido no pedido de 2026-10-12)

- **Oposição em autosserviço:** nas definições da conta, «Não registar a minha
  utilização». Pára a recolha, elimina de imediato eventos, agregados e
  primeiros passos, impede que pedidos em curso ou agregações posteriores os
  reconstituam, e não mexe em dados pedagógicos nem no acesso. Quem se opôs sai
  de todos os números do painel (nunca aparece inativo nem «por avaliar»). Ao
  voltar a aceitar, a recolha começa nesse instante, sem histórico e com
  cobertura própria. Continua a ser possível executá-la por pedido escrito
  (`lapis:usage-opt-out`).
- **Encerramento:** o pedido de encerramento pára a recolha e elimina as
  métricas **no pedido**, não no dia 60. Cancelar o pedido não as recupera; a
  recolha recomeça no cancelamento (salvo oposição anterior), com cobertura
  própria.
- **Âmbito:** o encerramento de uma organização **institucional** não foi
  alterado — a recolha nessa organização segue as regras da conta de cada
  membro, e os dados da organização saem com ela. Todas as organizações de
  produção são pessoais.

### Texto do aviso (proposta de comunicação, não validação jurídica)

> «Pode opor-se à recolha de métricas de utilização nas definições da sua conta.
> Ao fazê-lo, a recolha é interrompida e as métricas associadas à sua conta são
> eliminadas, sem afetar o acesso às funcionalidades do Lapispro.»

Aparece na Política (secção «Métricas de utilização do serviço»), no aviso não
bloqueante que reaparece no instante da ativação, e (adaptado) nas definições.

## 4. Informação aos utilizadores

### Proposta (implementada)

- A secção «Métricas de utilização do serviço» da Política de Privacidade e as
  menções nas secções «Dados técnicos», «Para que usamos os dados», «Com que
  fundamento…», «Durante quanto tempo» e «Os seus direitos» **só aparecem a
  partir da primeira ativação**, e a **data em vigor da Política passa a ser o
  instante dessa ativação** (o mesmo registo que marca o início da cobertura).
  Assim a informação está disponível **no início** da recolha, e o aviso não
  bloqueante reaparece a toda a gente nesse momento, com o aviso de oposição.
- Desligar a recolha **não** retira a secção: as métricas já recolhidas
  continuam conservadas pelos prazos descritos.

## 5. Quem liga e desliga (decidido e implementado)

Uma autorização **própria**, separada de consultar o painel:
`can_control_usage_collection`, só com o estatuto de administrador, não concedida
por omissão, verificada no servidor em todos os pontos de entrada, auditada na
concessão, na revogação e em cada `start`/`stop`, e retirada com o estatuto de
administrador. Quem só consulta o painel não se pode atribuir. Procedimento da
primeira concessão em
[usage-insights.md](usage-insights.md#primeira-concessão).

## 6. Ativação — o que falta decidir

A ativação **não** foi executada em produção, e nenhuma autorização foi
concedida. Antes de um `start`:

1. A validação jurídica (abaixo) — **não concluída**.
2. Classificar as organizações de produção (§1).
3. Confirmar os prazos de 35/180 dias e o texto do aviso de oposição (§2, §3),
   depois da validação jurídica.
4. A primeira concessão da autorização de controlo: o **email exato** da conta
   administrativa que a recebe (há nomes repetidos em produção, por isso nunca
   pelo nome).
5. A data e a hora do `start`, e quem o executa — o procedimento está em
   [usage-insights.md](usage-insights.md#procedimento-operacional).

## Para validação jurídica (não respondido aqui)

Perguntas para quem fizer a validação, com as fontes oficiais consultadas a
2026-10-12:

1. **Fundamento.** O interesse legítimo (art. 6.º, n.º 1, alínea f)) está
   ponderado em [usage-insights.md](usage-insights.md#fundamento-e-ponderação-interesse-legítimo)
   segundo os três passos que o EDPB descreve (interesse, necessidade,
   ponderação). As orientações do EDPB estão ainda na versão 1.0 de consulta
   pública.
2. **Definição de perfis.** Os sinais e o ranking avaliam aspetos do
   comportamento de uma pessoa na aplicação. Saber se isso é «definição de
   perfis» (art. 4.º, ponto 4) e que consequências tem para a informação e para a
   oposição (arts. 13.º e 21.º). Não há decisões automatizadas com efeitos
   jurídicos ou significativos (art. 22.º) — nada no painel produz efeitos na
   conta —, mas a qualificação é jurídica.
3. **Proporcionalidade dos prazos** (art. 5.º, n.º 1, alíneas c) e e)): 35 e 180
   dias, e as datas de primeiros passos (§2.1). O princípio vem do considerando
   39 do RGPD; a CNPD retoma-o em pareceres recentes («o prazo de conservação dos
   dados deve ser limitado ao mínimo […] o responsável pelo tratamento deverá
   fixar os prazos para o apagamento ou a revisão periódica», Parecer 2026/4).
   Esse parecer é sobre outro tratamento e não se pronuncia sobre este.
4. **Informação** (art. 13.º, n.º 1, alíneas c) e d), e n.º 2, alíneas a) e b))
   e **apresentação do direito de oposição** (art. 21.º, n.º 4): o texto do aviso
   proposto, e se a informação no instante da ativação (e não antes) basta.
5. **Oposição.** Se a oposição em autosserviço, com eliminação imediata e sem
   pedir fundamentação, é a resposta adequada ao art. 21.º, n.º 1, neste
   tratamento.
6. **Contexto laboral.** Num professor que trabalhe sob uma escola, estes dados
   não são mostrados à escola. Confirmar que a Política o diz de forma
   suficiente.
7. **Avaliação de impacto** (art. 35.º): confirmar se é necessária.

Fontes oficiais:

- Regulamento (UE) 2016/679, texto do Jornal Oficial — <https://eur-lex.europa.eu/eli/reg/2016/679/oj/por>
- EDPB, *Guidelines 1/2024 on processing of personal data based on Article 6(1)(f) GDPR*, versão 1.0 (consulta pública, 8/10/2024) — <https://www.edpb.europa.eu/our-work-tools/documents/public-consultations/2024/guidelines-12024-processing-personal-data-based_en>
- Grupo de Trabalho do Artigo 29.º, *Guidelines on Automated individual decision-making and Profiling* (WP251rev.01), endossadas pelo EDPB — <https://www.edpb.europa.eu/endorsed-wp29-guidelines_en>
- CNPD, Parecer 2026/4 (PAR/2025/83), sobre um projeto de portaria das comunicações eletrónicas entre a GNR/PSP e os tribunais — citado só pelo princípio geral de conservação mínima — <https://www.cnpd.pt/umbraco/surface/cnpdDecision/download/123275>
