# Utilização e acompanhamento — decisões pendentes (0.163.0)

Este documento junta o que **ainda não está decidido** para o painel `/admin/usage`
e o que foi preparado para essas decisões. As regras em vigor estão em
[usage-insights.md](usage-insights.md).

> **Proposta técnica ≠ validação jurídica.** As secções «Proposta» descrevem o que
> o código faz ou pode fazer, e porquê. As secções «Para validação jurídica»
> listam perguntas que **não** ficam respondidas por este documento. Nada aqui
> declara conformidade com o RGPD. A pendência n.º 12 de [legal.md](legal.md)
> continua aberta.

**Estado por omissão desta versão:** a recolha está **desligada**
(`LAPIS_USAGE_ENABLED=false`). Enquanto estiver desligada, não se grava nenhum
evento, a agregação não lê dados de pessoas, o painel diz que não há recolha e
não gera sinais, e a Política de Privacidade não muda. A cobertura começa no
instante em que a recolha fica efetivamente ativa (ver «Ativação»).

## 1. Classificação das organizações para métricas

### O que a investigação encontrou (só leitura, no repositório)

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
  criadas antes dessa data (13). Foi uma decisão comercial («não há clientes
  pagantes»), não uma avaliação, conta a conta, de dados fictícios. Entre elas
  estão as de **dois professores parceiros convidados a experimentar** — que
  podem estar a usar o produto no seu trabalho, com alunos reais.
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
- O painel passa a ter quatro segmentos: **Utilização real** (inicial),
  **Teste e demonstração**, **Interna**, **Por classificar**. Pessoal interno
  (`is_platform_admin`/`is_support_technician`) fica sempre em «Interna».
- `is_test_account` continua intacto e com a sua finalidade comercial.

**Efeitos de cada mudança de classificação:** só a segmentação do painel (e por
isso as contagens de cada segmento, o ranking e a lista filtrada). Nada na conta
da organização: acesso, plano, recolha, conservação e Política não mudam.

### Decisões que precisam da tua resposta

1. **Classificar as 13 organizações de produção.** Correr, só leitura,
   `php artisan lapis:usage-classify --list` em produção (mostra tipo, data de
   criação, plano, `is_test_account`, membros) e dizer, para cada uma, «real»,
   «teste» ou «interna». Evidência disponível sem aceder a produção:
   - os **dois professores parceiros** → provavelmente «Utilização real», mas só tu
     sabes se usam dados reais — **confirmar**;
   - a conta **«Ana Martins QA»** (u18/o18) e **«Teste Deploy»** (u1) → «Teste e
     demonstração» ou «Interna» — **confirmar qual**;
   - as restantes → **sem evidência suficiente**; ficam «Por classificar» até
     indicação tua.
2. **Contas novas depois da ativação:** (a) nascem «Por classificar» (proposta
   implementada; nada é presumido, mas cada registo novo precisa de uma decisão
   para entrar na utilização real) ou (b) um registo público feito pelo
   formulário de inscrição nasce «Utilização real» e só as provisionadas no
   backoffice ficam por classificar. A (b) é uma presunção sobre a origem do
   registo, não sobre a atividade — mas é uma presunção, e por isso não foi
   implementada sem a tua decisão.

## 2. Conservação

### Que dados existem e para que servem

| Dados | Granularidade | Para que é preciso | Até quando é preciso |
|---|---|---|---|
| `usage_events` | um evento por registo e dia (consultas: um por funcionalidade e dia); chave HMAC | deduplicação no próprio dia; agregação (hoje e ontem, ou mais dias depois de uma paragem do agendador); datas de primeira observação | poucos dias depois de agregado; margem para diagnóstico e recuperação |
| `usage_daily_rollups` | por pessoa, organização, funcionalidade e dia: contagens | todas as páginas do painel: períodos de 7/30/90 dias, semanas, coortes, sinais (até ~6 semanas), ranking | o período mais longo que o painel deve mostrar por pessoa |
| `usage_user_states` | por pessoa: turma disponível desde (recalculada a cada passagem a partir do estado atual), 3 datas de primeira observação | primeiros passos, sinais de conta/turma sem registo | enquanto o acompanhamento inicial fizer sentido |
| `usage_collection_periods` | início e fim de cada período de recolha (sem pessoas) | distinguir «sem recolha» de «sem atividade» | enquanto houver dados de utilização |

### Proposta (valores configuráveis; os indicados são os por omissão desta versão)

- **Eventos detalhados: 35 dias** (`LAPIS_USAGE_EVENTS_RETENTION_DAYS`). A
  deduplicação só precisa do próprio dia (a chave inclui o dia); a agregação só
  recalcula dias recentes; 35 dias cobrem a recuperação depois de uma paragem do
  agendador e o diagnóstico de um pico de falhas, com folga.
- **Agregados diários por pessoa: 180 dias** (`LAPIS_USAGE_ROLLUPS_RETENTION_DAYS`).
  Cobrem o período mais longo pré-definido (90 dias) com as suas coortes e
  semanas completas, as 6 semanas dos sinais, e um semestre letivo de
  comparação. O intervalo personalizado fica limitado a este prazo.
- **Datas de primeiros passos:** enquanto a conta existir (são três datas), e
  eliminadas no encerramento e na oposição. Alternativa: limpá-las 180 dias
  depois da criação da conta.
- **Tendências de longo prazo** (comparar anos letivos): **não** com dados por
  pessoa. Se forem precisas, a proposta é uma tabela de totais **sem pessoas**
  (por semana, funcionalidade e segmento) com prazo próprio — não implementada,
  depende da tua decisão.
- **Antes:** 365 dias para eventos e agregados. A separação reduz o que fica
  associado a pessoas sem perder nenhum cálculo do painel.

### Garantias técnicas (implementadas e testadas)

- O prazo dos eventos nunca é maior do que o dos agregados (se a configuração o
  disser, aplica-se o menor): um agregado apagado não pode ser reconstituído a
  partir de eventos ainda guardados.
- A agregação nunca recalcula um dia anterior ao limite dos **agregados**: um
  dia mais antigo já não pode ter agregados, e recalculá-lo reconstituí-los-ia.
- Com a recolha desligada, a agregação continua a correr e a avançar o
  marcador dos dias agregados (sem se declarar «em dia» para os sinais): o prazo
  dos eventos vale também enquanto a recolha está desligada.
- A eliminação dos eventos só apaga dias **já agregados** (anteriores a
  `aggregated_through − 1`, o primeiro dia que a agregação ainda volta a ler):
  o intervalo recalculado nunca inclui um dia sem os seus eventos, e uma
  agregação parada mais do que 35 dias ainda recupera esses dias quando volta
  (os eventos ficam à espera dela, e o registo `usage.prune_waiting_for_aggregation`
  di-lo).
- **Oposição e encerramento:** a eliminação apaga eventos, agregados e estado da
  pessoa no mesmo passo; a agregação filtra essas pessoas ao ler os eventos e
  apaga, em cada passagem, qualquer agregado ou estado que lhes reste — fecha a
  janela em que uma agregação a decorrer em paralelo pudesse voltar a escrevê-los.
- A partir do pedido de encerramento, nada mais é registado sobre a pessoa.

## 3. Oposição e encerramento da conta

### Proposta (implementada)

- **Oposição:** pedido para `privacidade@lapispro.com`; executa-se com
  `php artisan lapis:usage-opt-out <email>` — a recolha pára para essa pessoa e
  tudo o que existia é eliminado de imediato. `--undo` volta a ligar a recolha a
  partir desse momento.
- **Encerramento:** a recolha pára no pedido de encerramento; os dados de
  utilização são eliminados no fim da janela de recuperação (dia 60), com a
  anonimização da conta.

### Decisões que precisam da tua resposta

3. **Encerramento:** eliminar os dados de utilização **no pedido** (proposta de
   minimização: não são precisos para recuperar a conta) ou **no dia 60** (como
   está, junto com o resto da conta)?
4. **Oposição em autosserviço:** acrescentar, nas definições do professor, um
   interruptor «Não registar a minha utilização»? O artigo 21.º não exige
   autosserviço, mas torna a oposição imediata e verificável.

## 4. Informação aos utilizadores

### Proposta (implementada)

- A secção «Métricas de utilização do serviço» da Política de Privacidade e as
  menções nas secções «Dados técnicos», «Para que usamos os dados», «Com que
  fundamento…» e «Durante quanto tempo» **só aparecem quando a recolha tiver sido
  ativada**, e a **data em vigor da Política passa a ser o instante da ativação**
  (o mesmo registo que marca o início da cobertura). Assim o aviso não
  bloqueante volta a aparecer a toda a gente no momento em que a recolha começa
  — nem antes, nem depois.
- A secção descreve: o que é e não é registado; os sinais; o **ranking interno**
  (ordenação por dias com atividade, ações concluídas, semanas com atividade e
  funcionalidades usadas, que não avalia o trabalho pedagógico); quem vê; o
  fundamento e o direito de oposição; os prazos (lidos da configuração, como os
  outros prazos da Política).

### Decisão que precisa da tua resposta

5. **Aviso específico de oposição:** o artigo 21.º, n.º 4, pede que o direito de
   oposição seja dado a conhecer de forma explícita e separada, o mais tardar na
   primeira comunicação. Proposta: no momento da ativação, o aviso não bloqueante
   ganha uma frase própria («Passámos a registar a utilização das
   funcionalidades, sem conteúdos; pode opor-se…»). O texto do aviso é teu.

## 5. Ativação

- `LAPIS_USAGE_ENABLED=false` por omissão. Para ativar: decidir os pontos acima,
  pôr a variável a `true` e recarregar a configuração.
- **A cobertura começa na ativação efetiva**: o primeiro registo possível (ou a
  primeira passagem da agregação) abre o período de recolha; nada é registado
  antes de existir um período aberto. Desligar fecha o período na passagem
  seguinte da agregação (até 15 minutos depois); os dias sem recolha aparecem
  como «sem recolha», nunca como zeros, e não geram sinais.

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
   dias, e as datas de primeiros passos enquanto a conta existir. O princípio
   vem do considerando 39 do RGPD; a CNPD retoma-o em pareceres recentes («o
   prazo de conservação dos dados deve ser limitado ao mínimo […] o responsável
   pelo tratamento deverá fixar os prazos para o apagamento ou a revisão
   periódica», Parecer 2026/4). Esse parecer é sobre outro tratamento e não se
   pronuncia sobre este.
4. **Informação** (art. 13.º, n.º 1, alíneas c) e d), e n.º 2, alíneas a) e b))
   e **apresentação do direito de oposição** (art. 21.º, n.º 4).
5. **Contexto laboral.** Num professor que trabalhe sob uma escola, estes dados
   não são mostrados à escola. Confirmar que a Política o diz de forma
   suficiente.
6. **Avaliação de impacto** (art. 35.º): confirmar se é necessária.

Fontes oficiais:

- Regulamento (UE) 2016/679, texto do Jornal Oficial — <https://eur-lex.europa.eu/eli/reg/2016/679/oj/por>
- EDPB, *Guidelines 1/2024 on processing of personal data based on Article 6(1)(f) GDPR*, versão 1.0 (consulta pública, 8/10/2024) — <https://www.edpb.europa.eu/our-work-tools/documents/public-consultations/2024/guidelines-12024-processing-personal-data-based_en>
- Grupo de Trabalho do Artigo 29.º, *Guidelines on Automated individual decision-making and Profiling* (WP251rev.01), endossadas pelo EDPB — <https://www.edpb.europa.eu/endorsed-wp29-guidelines_en>
- CNPD, Parecer 2026/4 (PAR/2025/83), sobre um projeto de portaria das comunicações eletrónicas entre a GNR/PSP e os tribunais — citado só pelo princípio geral de conservação mínima — <https://www.cnpd.pt/umbraco/surface/cnpdDecision/download/123275>
