# Changelog — LÁPIS

Formato: [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/). Versão semântica pré-1.0 enquanto as fases são construídas.

## [0.64.1] — 2026-08-24

### Fixed

- **Aplicar uma sequência de aulas deixa de apagar sumário, recursos, TPC ou notas que o professor já tivesse escrito à mão.** Dois problemas na Fatia 4: primeiro, desligar explicitamente a opção "Sumário" não impedia o conteúdo de ser copiado sempre que a aula de destino ainda não tinha nenhum sumário — a opção era ignorada exatamente no caso em que mais importava respeitá-la. Segundo, mesmo com uma opção ligada, o valor da sequência substituía sempre o que já estava escrito na aula, em qualquer um dos quatro campos — Sumário, Recursos, TPC, Notas do professor — em vez de só preencher o que estava em branco. Agora uma sequência só escreve num campo quando a opção respetiva está ligada, o item da sequência tem texto nesse campo, e a aula de destino está em branco nesse mesmo campo; falhando qualquer uma destas três condições, o campo fica completamente intocado. Uma aula sem sumário ainda pode passar a existir com conteúdo vazio (nunca com o texto da sequência, se a opção Sumário estava desligada) quando pelo menos outro campo tinha algo genuíno para escrever; se nada havia mesmo para aplicar, a aula é simplesmente ignorada, sem criar um registo vazio. A mensagem apresentada depois de aplicar passa a distinguir aulas efetivamente preenchidas, aulas que já tinham conteúdo próprio e foram preservadas, e itens sem aula disponível — nunca mais uma única contagem de "aplicadas" a esconder o facto de metade da sequência não ter mudado nada.

## [0.64.0] — 2026-08-24

### Added

- **Módulo Aulas e Sumários — Fatia 4: sequências de aulas reutilizáveis.** Um professor com várias turmas da mesma disciplina e ano (ex.: 7ºA, 7ºB, 7ºC) pode agora definir uma vez um plano ordenado de conteúdo de aulas — `LessonSequence` e os seus `LessonSequenceItem` — e aplicá-lo a cada turma sem reescrever o mesmo sumário três vezes. Uma sequência pertence a uma disciplina e a um ano letivo, é opcionalmente limitada a um ano de escolaridade (em branco aplica-se a qualquer turma dessa disciplina, o que a torna utilizável no ensino superior, onde ano de escolaridade não faz sentido), e é pessoal de quem a criou — nunca partilhada com o resto da organização nesta fatia. Deliberadamente secundária: `/lessons/sequences` é uma página própria, com uma ligação discreta a partir da vista semanal, que continua a ser o centro operacional do módulo.

  **Cópia no momento da aplicação, nunca uma ligação viva.** Aplicar uma sequência a uma turma (`ApplyLessonSequence`) escreve o texto de cada item na próxima aula elegível dessa turma — por ordem, a partir de agora, nunca uma aula já lecionada — através do mesmo `SaveLessonSummary` que já deriva Preparado e nunca mexe em Lecionado. A partir desse momento cada sumário é independente: editar a sequência de origem depois de já ter sido aplicada nunca altera as aulas que já a usaram, exatamente a mesma disciplina que já protegia os modelos de relatório desta aplicação.

  **Quatro campos à escolha, notas do professor desligadas por omissão.** Sumário, Recursos, TPC e Notas do professor podem ser copiados independentemente; as Notas do professor começam sempre desligadas e só são copiadas se o professor as pedir explicitamente nessa aplicação em concreto — uma nota escrita a pensar no ritmo de uma turma raramente serve tal e qual noutra. Numa aula que já tem sumário, um campo não selecionado fica completamente intocado; o Sumário em si é sempre escrito quando a aula ainda não tem nenhum, porque a coluna não permite vazio — nunca por acaso, sempre pela mesma regra. Se houver menos aulas elegíveis do que itens na sequência, aplica-se às que existem e o resultado diz exatamente quantas aulas foram preenchidas e quantos itens ficaram sem aula disponível, em vez de falhar.

  **"Basear no sumário anterior", uma conveniência à parte, deliberadamente pequena.** Na página de uma aula sem sumário ainda escrito, um botão oferece copiar o sumário, notas, recursos e TPC da aula anterior mais recente da mesma turma que já tenha sumário — para dentro do formulário atual, ainda por guardar e totalmente editável. Não é uma segunda sequência nem uma ligação entre aulas: é só texto, escrito uma vez, que o professor pode alterar ou substituir como qualquer coisa que tivesse escrito de raiz; nada fica gravado enquanto não for o próprio a carregar em Guardar.

## [0.63.0] — 2026-08-24

### Added

- **Módulo Aulas e Sumários — Fatia 3: um único centro de registo por aula.** O Sumário passa a ser o centro sempre visível da página e a única gravação de conteúdo. A burocracia de planeamento da Fatia 2 — formulário, botões e transições manuais de preparação — foi retirada da experiência; preencher o Sumário deriva automaticamente o estado Preparado, sem ação manual e sem qualquer reabertura artificial.

  Três campos opcionais e recolhíveis completam o registo: **Notas do professor**, privadas e nunca mostradas aos alunos; **Recursos**, como referência ou URL em texto simples nesta primeira versão; e **TPC**, como nota do trabalho atribuído à aula. Estes campos pertencem ao mesmo `LessonSummary` e são guardados em conjunto, sem criar armazenamento paralelo. Deliberadamente não reutilizam `EvidenceRecord` nem o módulo Registos: esse sistema acompanha cumprimento por aluno, enquanto o TPC desta fatia descreve o que foi atribuído numa aula, sem dimensão de aluno — são preocupações diferentes.

  **“Marcar como lecionada” continua explícito e separado.** A transição para Lecionado tem uma ação própria, idempotente e auditada; nunca é inferida ao guardar o Sumário, pela presença de notas, recursos ou TPC, nem pela passagem do tempo. Rever qualquer texto depois de a aula estar lecionada mantém a rastreabilidade sem copiar conteúdo pedagógico ou privado para a auditoria.

## [0.62.1] — 2026-08-24

### Fixed

- **"Registo de Avaliações" passa a "Grelhas de correção" na navegação, no menu da landing e na mock da sidebar.** O nome anterior lia-se como um passo burocrático de arquivo; o novo nome diz o que o professor de facto abre — a grelha onde a correção acontece. Só nomenclatura: a rota, o controlador e a capability continuam `assessments`.

  **Auditado em paralelo, sem alteração de código**: o relato de que "Criação simples" ficava inacessível depois de passar por "Criação avançada" não se reproduz na HEAD atual — o ciclo simples → avançada → simples já preserva os campos comuns e já bloqueia corretamente (sem perder dados) uma estrutura avançada incompatível. Adicionada cobertura de regressão (`InstrumentForm.test.ts`) para os dois casos, sem alterar comportamento.

## [0.62.0] — 2026-08-24

### Added

- **Proteção contra perda de classificações na grelha de correção.** Um professor que edita a mesma grelha em dois separadores, dois browsers ou dois dispositivos deixa de arriscar perder trabalho silenciosamente.

  **Rascunho local imediato.** Cada célula alterada mas ainda não guardada é escrita no `localStorage` do browser (com um pequeno atraso técnico, nunca a cada tecla), isolada por organização, utilizador e elemento de avaliação — um rascunho de outra conta, outro professor ou outro elemento nunca é lido por engano. Ao reabrir a grelha (reload, fecho acidental, falha de rede), um aviso explícito oferece "Recuperar alterações" ou "Ignorar rascunho" — nunca aplicado silenciosamente. Um rascunho cuja estrutura mudou entretanto (questões, cotações ou domínios diferentes) é assinalado como incompatível e nunca aplicado célula a célula por posição ou nome. O rascunho só é apagado depois de o servidor confirmar a gravação; uma gravação falhada ou uma ligação perdida preservam-no.

  **Proteção real no servidor.** Auditado o incidente que motivou esta fatia — duas abas do mesmo professor a guardar alterações diferentes na mesma grelha — confirmou-se por código que o servidor aceitava escritas cegas, sem qualquer verificação de concorrência. Cada célula passa a transportar a sua `lock_version` (coluna já existente na tabela, nunca antes usada); o servidor bloqueia e compara a linha antes de escrever, incrementa a versão quando aceita, e rejeita apenas as células que outro pedido já alterou — as restantes do mesmo lote continuam a gravar normalmente. A grelha substitui as células rejeitadas pelo valor real do servidor e avisa o professor, sem tocar nas restantes edições locais. Cada rejeição fica registada em auditoria (`scores.stale_write_rejected`, só com identificadores técnicos). Autosave automático para o servidor foi deliberadamente **não implementado**: sem uma forma segura e provada de evitar sobrescrever uma edição feita noutro separador, ficou decidido manter o "Guardar" explícito como o único caminho até ao servidor.

  **Indicadores discretos, sem se misturarem com a avaliação em si.** Estado offline ("Sem ligação — N alterações protegidas neste dispositivo") e aviso de edição concorrente noutro separador (via `BroadcastChannel`, só informativo) aparecem em blocos visuais distintos do painel amarelo de resultados por resolver — nunca confundidos com regras pedagógicas.

  **Semântica da avaliação inalterada.** Vazio continua diferente de zero, ausência continua uma decisão explícita nunca inferida, "Guardar" continua a nunca concluir a correção, e nenhuma fórmula ou agregação foi tocada.

## [0.61.2] — 2026-08-23

### Fixed

- **Copy da secção de privacidade e do CTA final da landing, transversal a todos os níveis de ensino.** Duas afinações à correção anterior: "Cada organização acede apenas ao que lhe pertence" passa a "Cada conta acede apenas ao contexto autorizado" (a conta, não a organização, é a unidade que faz sentido tanto no Pro individual como no Institucional); e o parágrafo de RGPD deixa de dizer "o que o LÁPIS garante" — uma afirmação absoluta demasiado forte para uma secção legal — passando a "a conformidade não depende apenas da tecnologia", que distingue corretamente medidas técnicas de responsabilidades do utilizador/organização. O CTA final ("O próximo período pode correr melhor do que o último... Monte um perfil, traga uma turma... Meia hora chega") presumia calendário escolar por períodos e um tom informal — passa a "A próxima etapa pode correr melhor do que a última... Crie o seu perfil de avaliação, importe uma turma..." sem promessa temporal arbitrária. "Não é pedido cartão" clarificado para "Não é necessário cartão de crédito" (ambíguo antes). Só copy — sem alterações de layout, lógica, planos/preços ou segurança real.

## [0.61.1] — 2026-08-23

### Fixed

- **Copy da secção de segurança/privacidade da landing deixa de posicionar o LÁPIS como produto só para menores.** "Isto são dados de menores. A proteção é estrutural, não um acabamento." e "quando uma escola o liga" presumiam sempre uma escola como única decisora e alunos sempre menores — o que não representa o Pro individual (autonomia do titular da conta) nem o ensino superior. A referência a menores mantém-se como caso especialmente sensível, já não como definição universal do produto; o exemplo lateral passa de "turma 9.º B / n.º de processo" para "contexto académico: Turma / Unidade curricular" e "identificador institucional", transversal a básico, secundário e superior. Só copy — sem alterações de layout, lógica ou segurança real.

## [0.61.0] — 2026-08-23

### Added

- **Módulo Aulas e Sumários — Fatia 2 de 6: planeamento antecipado e centro semanal.** `/lessons` deixa de ser o placeholder "em construção" e passa a ser a página operacional real do módulo. O professor pode agora escrever um planeamento (`LessonPlan.planned_summary`) antes da aula acontecer, distinto e nunca copiado silenciosamente para o sumário oficial, e transitar o estado da aula entre Por preparar/Preparado/Lecionado — Preparado exige planeamento ou sumário já preenchido; voltar de Lecionado a um estado anterior é recusado. A vista semanal mostra as aulas de todas as turmas do professor no ano letivo selecionado, com navegação Semana anterior/atual/seguinte, e um botão "Preparar aulas desta semana" que materializa ocorrências em todas as turmas com horário configurado, reutilizando a mesma `MaterializeLessonsForRange` da Fatia 1. O horário recorrente de cada turma (`RecurringLessonSlot`) passa a ser configurável a partir da própria página da turma.

  **Âmbito reduzido face ao plano faseado original**, por pedido explícito: sem notas privadas, recursos, TPC, cumprimento, sequências, continuidade ou faltas/atrasos — ficam para fatias futuras. O ContextBar ainda não tem seletor funcional de turma/disciplina/período (só o ano letivo é real), por isso a vista semanal soma todas as turmas do professor em vez de filtrar por uma só.

## [0.60.0] — 2026-08-23

### Added

- **Módulo Aulas e Sumários — Fatia 1 de 6: ocorrência concreta e sumário simples.** Primeira fatia vertical de um módulo novo (desenho completo em `docs/superpowers/specs/2026-08-23-lessons-summaries-planning-sequences-design.md`, plano faseado em `docs/superpowers/plans/2026-08-23-lessons-summaries-planning-sequences.md`). O professor configura o horário recorrente de uma turma (`RecurringLessonSlot`), materializa ocorrências concretas (`Lesson`) num intervalo dentro do ano letivo — de forma idempotente e nunca como efeito lateral de uma leitura — e escreve/revê o sumário oficial da aula (`LessonSummary`). A primeira gravação nunca marca a aula como lecionada; editar o sumário depois de já lecionada atualiza `reviewed_at`/`reviewed_by` e regista um evento de auditoria sem copiar o texto do sumário.

  **Ainda não incluído nesta fatia** — planeamento antecipado, notas privadas, recursos, TPC, cumprimento, sequências reutilizáveis, continuidade de conteúdo pendente, faltas/atrasos e a vista semanal (chegam nas Fatias 2–6). Todos os modelos são tenant-owned (`BelongsToOrganization`), autorizados por `LessonPolicy`/`RecurringLessonSlotPolicy` através da relação professor↔turma já existente, recusam mutações durante impersonation e usam ULID nas rotas.

## [0.59.0] — 2026-08-23

### Added

- **A grelha de correção passa a poder ser guardada "Em preparação" e retomada mais tarde.** A validação manual da Fatia H mostrou uma confusão conceptual: o formulário de criação não constrói o Elemento de Avaliação (o teste, a ficha, o trabalho) — constrói a respetiva grelha de correção (domínios, questões, cotações, alocações). Duas ações distintas na criação e na edição de uma grelha ainda em preparação — "Guardar e continuar depois" e "Preparar grelha de correção" — tornam isto explícito: guardar persiste a estrutura tal como está, mesmo incompleta (zero questões, cotações por preencher, domínios por definir, soma diferente de 100); preparar aplica todas as validações que já existiam e transita a grelha para "Preparado", o estado que sempre existiu para poder lançar resultados.

  **Reutiliza o estado técnico `draft` que já existia** (`InstrumentStatus::Draft`, agora rotulado "Em preparação" em vez de "Rascunho") — não foi criado nenhum estado novo. O cliente deixa de poder escolher o estado diretamente: envia apenas a intenção (`submission_intent: save|prepare`), e é o servidor que deriva o estado daí.

  **Uma grelha "Em preparação" fica isolada do lançamento de resultados.** Auditados e fechados os pontos que hoje assumiam "existe um Instrument = está pronto": a página de lançamento redireciona para a edição em vez de mostrar uma grelha de alunos incompleta; guardar uma pontuação passa a exigir "Preparado" ou "Em correção" (antes só recusava "Concluído"); a importação de resultados e o descarregamento da grelha Excel deixam de aceitar um elemento em preparação. Nas listagens (Elementos de Avaliação, página da turma, Registo de Avaliações), "Em preparação" aparece com a ação "Continuar preparação", nunca "Abrir"/"Ver resultados".

  **Uma migration reversível** torna `instrument_items.points_possible` opcional — para representar "questão ainda sem cotação" sem usar 0 (que seria indistinguível de uma cotação real de zero). Elementos já existentes em Preparado, Em correção, Concluído ou Anulado não são afetados nem reclassificados. Funciona nos dois modos de criação (simples e avançada), sem sacrificar nenhuma capacidade existente da criação avançada. `ClassResultsCalculator` e `BuildResultsProgression` não foram alterados.

## [0.58.1] — 2026-08-23

### Fixed

- **Precisão e uniformidade da distribuição automática de cotações, na Criação simples.** O smoke manual da v0.58.0 mostrou 100 pontos divididos por 14 questões a gerar 7,1428 — um valor que o próprio input de cotação (`step="0.25"`) rejeita — e o resumo a mostrar "99.99999999999999 / 100". A distribuição automática passa a trabalhar em unidades inteiras de 0,25 (não em pontos brutos arredondados a 4 casas decimais arbitrárias), o que elimina o problema de raiz. O resto de uma divisão não exata deixa de ser todo despejado numa única questão (100/14 já não dá 13×7,00 + 1×9,00) — passa a ser espalhado um passo de cada vez pelas últimas questões, tão uniformemente quanto a grelha de 0,25 permite (100/14 → 6×7,00 + 8×7,25). Os totais apresentados (por questão e por domínio) passam a ser arredondados no próprio valor, não só na apresentação, o que evita o artefacto de vírgula flutuante. A cotação total e a de cada questão passam também a exigir `multiple_of:0.25` no servidor — usando aritmética decimal exata, não comparação de floats — como proteção real, não só uma dica visual do formulário.

## [0.58.0] — 2026-08-23

### Added

- **Vários domínios e várias questões na Criação simples de Elementos de Avaliação.** A validação manual da Fatia H mostrou o modelo anterior demasiado restritivo — 1 domínio, 1 questão. "Criação simples" passa a significar "forma simples de construir a estrutura", não "elemento necessariamente simples": o professor escolhe os domínios avaliados (um ou vários), define quantas questões tem cada domínio (1 por omissão, visível e editável), e ajusta a cotação de cada questão — o peso de cada domínio é sempre **derivado** da soma dessas cotações, nunca pedido diretamente. Cada domínio nasce com uma questão; reduzir a última remove o domínio explicitamente. Excecionalmente, "Editar domínios" numa questão permite dividi-la por mais do que um domínio (ex.: 70%/30%), reutilizando o mesmo `InstrumentDomainAllocations.vue` do modo avançado — agora com um segundo modo de edição em percentagem, fechado por omissão, ao lado do modo em pontos que a Criação avançada já usava. `canUseQuickMode` foi generalizado para múltiplos domínios/questões deixarem de obrigar à Criação avançada; grupos explícitos, bónus e outras estruturas especiais continuam a fazê-lo.

  **Sem tabela nova, sem modelo paralelo.** Cada questão continua a ser um `InstrumentItem` real, com códigos globais Q1…Qn (não reiniciados por domínio) no único grupo implícito de sempre. Nenhum `StudentItemScore` é criado por omissão; guardar continua a nunca concluir o elemento.

  **Grelha Excel redesenhada para refletir a estrutura real.** Cada cabeçalho de coluna passa a mostrar domínio, código, título e cotação máxima, agrupado visualmente por domínio; uma questão multi-domínio continua numa única coluna, assinalada como tal, nunca duplicada. A identidade inequívoca de cada coluna — pensada para uma futura importação — reutiliza o mecanismo de nomes definidos (`LAPIS_ITEM_<coluna>` → ULID) que a grelha de correção já tinha; não foi criada nenhuma folha ou contrato novo. Cabeçalho na linha 1 e alunos a partir da linha 2, como sempre.

## [0.57.1] — 2026-08-23

### Changed

- **Nomenclatura e copy dos modos de criação de Elementos de Avaliação.** "Criação rápida"/"Criação detalhada" passam a "Criação simples"/"Criação avançada" — depois de validação visual, "detalhada" sugeria um fluxo lento, e o modo simples já produz um elemento perfeitamente completo. Novo subtítulo no modo simples ("Crie o elemento com os dados essenciais. O LÁPIS prepara automaticamente a estrutura base.") e descrição do modo avançado reescrita para explicar o motivo de o escolher. A ligação entre modos passa a uma pergunta seguida de uma ação claramente clicável, com indicador visual. Só linguagem — lógica, payloads, validações e testes de domínio inalterados.

## [0.57.0] — 2026-08-23

### Added

- **Criação rápida de Elementos de Avaliação (Fatia H).** A criação passa a abrir num modo curto — turma, designação, tipo, data, período e domínio principal — em vez do formulário completo logo à partida, pensado sobretudo para tablet em contexto de sala de aula (sem modais, sem scroll longo, sem campos avançados visíveis). Escolher um único domínio aloca-lhe automaticamente 100%, reutilizando o mesmo mecanismo do modo detalhado (`InstrumentBuilder`); escolher mais do que um passa para a configuração detalhada de pesos. É possível alternar [Criação rápida]/[Criação detalhada] sem perder o que já estiver preenchido, sempre que a mudança for segura — a alternância bloqueia-se assim que existir estrutura detalhada que ficaria escondida. Um elemento criado no modo rápido é editado depois pelo fluxo detalhado normal, sem distinção.

  **Sem tabela nova, sem modelo paralelo, sem endpoint próprio.** O modo rápido produz exatamente a mesma entidade canónica que o modo detalhado sempre produziu — um `Instrument`, um grupo implícito sem nome, um `InstrumentItem` `Q1` de 100 pontos, uma alocação de 100% — validada no servidor (`quick=true` não pode transportar múltiplos grupos/itens, bónus, estado concluído ou totais diferentes de 100). Nenhuma pontuação ou resultado é criado por omissão; guardar nunca conclui o elemento, exatamente como no modo detalhado. Reforçada a mesma validação de tenancy do fluxo detalhado (período pertence ao ano letivo da turma, domínio pertence ao perfil ativo da turma) para ambos os modos.

## [0.56.0] — 2026-08-23

Release consolidada: reúne tudo o que foi concluído e testado desde a v0.45.9 — a versão confirmada ao vivo em produção (`74e8ff2`, 2026-08-22) — até este commit. Dez releases formais (v0.46.0 a v0.51.0) e cinco bumps informais desde então (v0.52.0 a v0.55.3), já individualmente documentados abaixo, mais as duas correções desta entrada. Zero migrations, dependências, variáveis de ambiente ou alterações de scheduler novas em todo o intervalo.

**Não incluído nesta release** — trabalho auditado, especificado ou iniciado na mesma janela mas deliberadamente deixado de fora: criação rápida de elementos de avaliação em modo simples/detalhado (Fatia H, só auditada), importação Excel "Grelha LÁPIS" para elementos de avaliação (Fatia I, por especificar), perfis de avaliação persistentes entre anos letivos (Fatia C, adiada por decisão de design), refinamento do checklist do painel (Fatia B, nunca retomada) e os itens menores de auditoria da Fatia J — incluindo a origem por explicar do ano letivo "9.º A Teste" no seletor.

### Fixed

- **Último resíduo de inglês visível.** O título por omissão de `AlertError` ("Something went wrong.") só aparecia nos dois ecrãs de 2FA que não o substituem por um próprio; passa a "Ocorreu um erro.". Auditados ambos os fluxos por inteiro — não fica nenhum outro texto em inglês.
- **`docs/deployment.md` apontava para a versão errada em produção.** Dizia 0.37.0/2026-08-20; uma verificação ao vivo por SSH (só leitura) confirmou produção em 0.45.9 @ `74e8ff2`, construída 2026-08-22. Documento corrigido; processo de deploy inalterado.

## [0.55.3] — 2026-08-22

### Changed

- **Copy da secção "O modelo da sua escola" na landing.** Reescrita para deixar claro que domínios, ponderações, escala e organização do ano letivo são estruturas que a escola/instituição define, não decisões individuais do professor. Removida a linguagem técnica "versionado". Layout, ícones e responsividade inalterados.

## [0.55.2] — 2026-08-22

### Changed

- **Ajuste à secção "Porquê LÁPIS?".** Corrigido o acento ("Porque" → "Porquê"). A secção passa a ter fundo próprio — o tom quente de papel já usado no cabeçalho/rodapé (`CHROME_SURFACE`, novo prop `warm` em `LandingSection`), criando uma pausa visual entre as secções brancas envolventes. Restante copy, destaque do acrónimo e layout inalterados.

## [0.55.1] — 2026-08-22

### Added

- **Secção "Porque LÁPIS?" na landing.** Nova nota editorial de marca entre "O Problema" e "Como funciona", explicando o acrónimo (Laboratório de Apoio ao Professor, Informação e Simplificação) com as iniciais destacadas por cor de destaque existente e peso tipográfico — nunca só cor. Compacta, sem cards nem ícones, reutilizando o `LandingSection` e o `RevealOnScroll` já usados no resto da página.

## [0.55.0] — 2026-08-22

### Changed

- **Nomenclatura visível "Instrumento" → "Elemento de Avaliação".** A revisão manual ainda encontrava "Novo instrumento", "Editar instrumento", "Anular instrumento" e mensagens equivalentes espalhadas pela UI e por mensagens de validação/erro do backend — a maior parte já dizia "Elementos de Avaliação" (navegação, listagens), mas não em todo o lado. Corrigidos 17 pontos no frontend (botões, títulos, breadcrumbs, tooltips, diálogos de confirmação) e 14 no backend (exceções de validação, do fluxo de correção, de importação, mensagens do controlador, dois enums de adaptações de avaliação). Só linguagem visível — `Instrument`, `InstrumentController`, rotas técnicas, tabelas e testes mantêm o nome técnico. Deliberadamente não tocado: o texto gerado das secções de relatórios (`app/Services/Reporting/`), porque esse texto passa por um guarda de reescrita (`RewriteGuard`/`WritingGlossary`) que protege a precisão da prosa de documentos oficiais e cuja sincronização exige uma revisão própria, cuidadosa — registado para decisão futura.

## [0.54.3] — 2026-08-22

### Changed

- **Copy da secção "O Problema" na landing.** Eyebrow, título, texto introdutório, os 4 blocos e a frase final reescritos, sem travessões. Layout, grelha, tipografia e numeração 01-04 inalterados.

## [0.54.2] — 2026-08-22

### Changed

- **Dois parágrafos do hero da landing.** Texto sobre avaliação/acompanhamento e sobre turmas/critérios/elementos de avaliação reescritos. Só estes dois parágrafos.

## [0.54.1] — 2026-08-22

### Changed

- **Faixa de níveis de ensino na landing.** "Superior" passa a "Universitário" na hero. Só esta palavra.

## [0.54.0] — 2026-08-22

### Added

- **Editar turma.** Uma turma criada com a designação errada obrigava a apagar e recriar — não havia forma de a corrigir. Ação "Editar turma" nova, descoberta a partir do cartão em Turmas (ícone com aria-label) e da própria página da turma (botão). O formulário só permite alterar a Designação, seguindo a convenção "Ex.: 7.º A" da Fatia E; ano letivo, disciplina e ano de escolaridade ficam bloqueados e explicados como tal — alteram-nos e desalinhariam elementos de avaliação e relatórios já associados. O `FormRequest` já previa este caso (a verificação de unicidade já sabia excluir-se a si próprio); reutilizado tal e qual, sem endpoint paralelo. Mesmo que um pedido tente enviar outros campos, o controlador só lê e grava a designação — o bloqueio é aplicado no servidor, não só escondido na UI.

## [0.53.2] — 2026-08-22

### Changed

- **Bloco de marca no cabeçalho da landing.** Tagline atualizada para "Mais simples. Mais tempo para o que realmente importa." e o conjunto "LÁPIS" + tagline ligeiramente maior (15px→17px / 11px→12px), mantendo "LÁPIS" visualmente dominante. Só este bloco — ícone, cores, resto do cabeçalho e restantes secções inalterados.

## [0.53.1] — 2026-08-22

### Changed

- **Copy do bloco "Três regras" na landing.** Título e os três cartões junto de `#como-funciona` reescritos para descrever vazio/zero, "não aplicável" e entrada tardia com mais precisão. Só texto — layout, tipografia e responsividade inalterados.

## [0.53.0] — 2026-08-22

### Added

- **Convenção transversal de exemplos nos campos.** Vários formulários mostravam um exemplo ("7.º A", "Q1", "Compreensão do texto"...) que parecia um valor já introduzido. Todo o texto de exemplo passa a seguir o padrão "Ex.: …" — 24 campos corrigidos em 17 páginas (turmas, disciplinas, perfis de avaliação, elementos de avaliação, ano letivo, equipa, admin, autenticação, relatórios, importação). Uma regra `::placeholder` global e centralizada (`resources/css/app.css`, reutilizando o token `--muted-foreground` já existente) garante que todo o placeholder da aplicação — incluindo campos nativos fora do componente `Input` — usa a mesma cor ténue e acessível, sem depender de repetir a classe campo a campo. Valores por omissão genuinamente funcionais (cotação total = 100, código de questão auto-numerado "Q1"/"Q2"...) foram auditados e mantidos como valores reais, não convertidos em exemplos. Campos instrutivos ("Nome completo", "Palavra-passe", "Procurar por nome ou email…") mantidos como estão — não são exemplos, são instruções.

## [0.52.0] — 2026-08-22

### Added

- **Ano letivo passa a ser um seletor real na barra de contexto.** "Ano letivo" era uma ligação de navegação para a página de gestão; passa a ser um menu suspenso com o ano atual e até três anos anteriores, cada um selecionável — a escolha grava-se na sessão (mesmo padrão já usado para trocar de organização) e passa a determinar o contexto de toda a app. A lista mostra sempre os anos mais recentes da organização, incluindo um ano ainda em preparação (rascunho) que ainda não seja o "atual" resolvido pela heurística existente — para que o professor consiga entrar nele e continuar a prepará-lo. Selecionar um ano mais antigo não esconde os mais recentes da lista. O acesso à gestão de anos letivos mantém-se, agora como opção separada dentro do menu. Corrigida também a mensagem da Disciplina na barra de contexto, que dizia sempre "Sem disciplinas configuradas" mesmo quando já existiam disciplinas só por não haver nenhuma selecionada — agora distingue os dois casos. Responsividade da barra de contexto ajustada para telemóvel (os seletores ainda não interativos ficam ocultos abaixo do desktop; Ano letivo e Disciplina mantêm-se sempre visíveis).

## [0.51.0] — 2026-08-22

### Added

- **Ligação e autoria em Estratégias e Medidas.** Depois de auditar o módulo, a maior parte do que se pedia já estava construída — o acompanhamento como ação de primeira classe (histórico preservado, nunca sobrescrito), o enquadramento pedagógico/legal, a biblioteca partilhada com Relatórios. Ficavam por fazer duas ligações pequenas: o nome de um aluno numa intervenção individual passa a ligar à sua própria página de Evolução (a ligação inversa já existia); e cada intervenção passa a indicar quem a registou, o que só importa quando uma turma tem mais do que um professor.

## [0.50.0] — 2026-08-22

### Added

- **Revisão de estratégia pendente, na Evolução do Aluno.** `Intervention::needsReview()` já existia — a data que o professor escolheu, já passada, numa estratégia ainda em curso — mas não aparecia em lado nenhum. A secção Estratégias e Medidas do aluno passa a mostrar "Revisão pendente" em cada linha nessa situação, e uma contagem no cabeçalho da secção. Composto inteiramente a partir do método já existente — sem regra nova, sem inferência, sem linguagem de "aluno em risco".

### Fixed

- **`docs/status.md` estava desatualizado.** Continuava a listar "Análise da Turma" como o último placeholder da Fase 3 e como o próximo candidato — já está feito desde a 0.35.0, com o nome de "Estatística".

## [0.49.0] — 2026-08-22

### Added

- **Preparação do ano letivo, no Painel do Professor.** Enquanto a organização não estiver totalmente pronta para o ano, o painel mostra uma checklist de cinco passos — ano letivo ativo, disciplinas configuradas, um perfil de avaliação para este ano, uma turma criada, um perfil associado a essa turma — em vez do antigo ecrã "ainda não tem turmas", que passa a ser só um desses passos. Só o primeiro passo por concluir recebe um botão de ação; os seguintes ficam visíveis mas sem ação até chegar a sua vez. Assim que os cinco estiverem concluídos, a checklist desaparece e o painel volta ao normal (turmas e pendências). Nenhuma rota, página ou ação nova — reutiliza inteiramente a criação de anos letivos, disciplinas, perfis (incluindo a reutilização entre anos já existente) e turmas; a heurística de "ano atual" é a mesma já usada pela barra de contexto. Zero dados de alunos lidos ou escritos.

## [0.48.4] — 2026-08-22

### Changed

- **Bloco de polimento UX: reutilização de perfis, TPC e landing.** A ação de reutilizar um perfil de avaliação passa a mostrar "Reutilizar" por extenso na listagem (não só um ícone), e a própria página lidera com a instrução ("Crie uma cópia deste perfil para outro ano letivo.") em vez do contexto do perfil. Na grelha rápida de trabalho de casa, os três estados ganham símbolos distintos (✓ ◐ ✕) — no desktop mostravam todos o mesmo "✓", distinguidos só pela posição e pela cor — e os contadores passam a badges compactas com símbolo, rótulo curto e número. A landing ganha a copy final da hero ("Mais simples. Mais tempo para o que realmente importa.") e o CTA para visitantes não autenticados passa de "Criar conta" para "Experimentar LÁPIS" em todos os botões de ação (mantido "Criar conta" onde é apenas uma ligação de navegação no rodapé, e a secção de preços ficou fora de âmbito). Sem alterações de domínio em nenhum dos três.

## [0.48.3] — 2026-08-22

### Changed

- **Polimento visual de "Importar configuração" e "Pré-visualizar importação".** Sem alterações ao domínio: mesmo parser, schema, business keys, plano de importação, idempotência, conflitos, escritas, tenancy, autorização, entitlement, limite de 20 MB. O seletor nativo de ficheiro dá lugar a uma zona de arrastar-e-largar (clicável, navegável por teclado, sem esconder o input nativo), com confirmação imediata do ficheiro escolhido — nome, tamanho, "Alterar ficheiro"/"Remover" — sem analisar automaticamente. O aviso de privacidade fica igual em ambas as páginas e na de exportação. A pré-visualização mostra agora a data e a versão em que o ficheiro foi criado, cada elemento da lista com o seu tipo (Ano letivo/Disciplina/Perfil de avaliação), nome e contexto separados, e um resumo do que falta importar. O botão "Importar novos elementos" continua desativado quando não há nada novo.

## [0.48.2] — 2026-08-22

### Changed

- **Polimento visual da página "Partilhar configuração".** Sem alterações ao domínio: mesma whitelist, mesmo preview, mesma idempotência, mesma tenancy. A ação "Importar configuração" passa a estar junto ao título, em vez de solta por baixo; as opções de exportação passam de checkboxes soltas a linhas clicáveis com contexto; um grupo sem opções (por exemplo "Escala" quando a organização não tem escalas próprias) mostra sempre uma frase discreta em vez de ficar vazio; o botão "Gerar e descarregar" começa desativado e só ativa com pelo menos um elemento selecionado, com um resumo em tempo real ("1 elemento selecionado" / "N elementos selecionados"); o aviso de privacidade passa a incluir "registos pedagógicos" na lista do que nunca é exportado.

## [0.48.1] — 2026-08-22

### Fixed

- **A exportação de configuração não descarregava num browser real.** `Export.vue` lia o token CSRF de uma meta tag que o layout nunca renderizava; corrigido a adicionar `<meta name="csrf-token">` a `app.blade.php`. Só afetava o download direto por `fetch()` — todos os outros pedidos da aplicação passam pelo router do Inertia, que já resolve o CSRF sozinho.

## [0.48.0] — 2026-08-22

### Added

- **Reutilização de perfis de avaliação entre anos letivos.** O owner pode escolher um ano letivo já existente, pré-visualizar o mesmo plano New/Existente/Conflito/Inválido da partilha seletiva e criar uma cópia estrutural independente do perfil nesse ano. A cópia recebe uma versão própria em rascunho, nunca é ativada automaticamente e nunca substitui um perfil equivalente; a origem fica intacta. O fluxo reutiliza integralmente as actions de exportação, planeamento e escrita já existentes, corre sob `module:assessment_profiles`, reconfirma o pacote devolvido pelo browser contra a origem e o destino atuais e audita a operação sem dados de alunos.

## [0.47.0] — 2026-08-22

### Added

- **Partilha seletiva de configurações entre professores.** Em Configuração → "Partilhar configuração", um professor escolhe o que quer entregar a um colega — identidade da escola, anos letivos, disciplinas, escalas próprias, perfis de avaliação — e recebe um ficheiro JSON. Nunca inclui alunos, matrículas, classificações, autoavaliações, registos de evidência, intervenções, relatórios, utilizadores, associações, palavras-passe, segredos ou qualquer dado derivado: a lista de campos exportáveis é uma whitelist fechada, e cada perfil de avaliação arrasta consigo apenas o seu ano letivo, disciplina, escala (referenciada por nome quando é uma escala de sistema, incluída quando é própria) e domínios — nunca os resultados de nenhum aluno. "Importar configuração" faz sempre uma pré-visualização primeiro (New/Existente/Conflito/Inválido, por chave de negócio — ano letivo por rótulo, disciplina por código, escala por nome, perfil por ano+disciplina+ano de escolaridade+nome), nunca escreve nada na fase de análise, nunca sobrepõe uma linha existente ou em conflito, e é idempotente (reimportar o mesmo ficheiro não duplica nada). Um perfil importado entra sempre como rascunho — a versão nunca fica ativa sozinha; o professor decide quando a publicar. Atrás do módulo `template_sharing` já existente nos planos Pro e Institucional (Base fica de fora); a sessão de pré-visualização fica amarrada ao organization_id de quem a criou, por isso não pode ser confirmada contra outra organização.

  **Sem tabela nova, sem coluna nova, sem alteração ao modelo de avaliação.** Reutiliza inteiramente o modelo de domínio já existente (`AcademicYear`, `Subject`, `Scale`, `AssessmentProfile`/`AssessmentProfileVersion`/`Domain`) através de duas ações novas e isoladas em `App\Actions\ConfigSharing` — deliberadamente não ramificadas do importador de cópia de segurança (Fatia 6), para a whitelist ficar estruturalmente incapaz de incluir dados pedagógicos, nunca dependente de alguém se lembrar de a manter assim.

## [0.46.0] — 2026-08-22

### Added

- **Registo rápido de Trabalho de casa, em grelha.** Em Ação Pedagógica → Registos, escolher o tipo "Trabalho de casa" numa turma e data já não abre o formulário genérico, aluno a aluno — mostra a turma inteira (só matrículas ativas) numa grelha com três estados por aluno (Realizado / Parcialmente realizado / Não realizado, mutuamente exclusivos, nenhum por defeito — em branco significa sempre "sem informação", nunca "não realizado") e uma "Observação" opcional e recolhida por omissão. Ações em massa ("Marcar todos como…", "Limpar") afetam só os alunos apresentados e continuam editáveis individualmente depois. Uma "Descrição comum" opcional preenche a Observação de cada aluno como ponto de partida, sem apagar o que já tiver sido personalizado numa linha. Contagens ao vivo, um único pedido para a turma inteira, uma só transação (com bloqueio da turma para serializar duas primeiras gravações em simultâneo). Reabrir a mesma turma/data recarrega exatamente o que foi gravado; limpar o estado de um aluno remove (soft-delete) o registo desse aluno para essa data, nunca fica por defeito como "não realizado". Uma eliminação explícita e confirmada apaga o conjunto todo de uma turma/data.

  **Sem tabela nova, sem módulo novo, sem migration.** Reutiliza inteiramente `EvidenceRecord`/`EvidenceKind::Homework`/`HomeworkStatus` (já existentes desde o módulo Registos original, com os três estados já corretos). A identidade de "o mesmo Trabalho de Casa" é deliberadamente simples — turma + tipo + data — e está documentada como tal: nunca existiu unicidade nessa combinação nesta tabela, e o formulário lento já não a impunha; duas tarefas diferentes escritas na mesma turma no mesmo dia através desta grelha continuam, hoje, a ser o mesmo conjunto de registos por aluno. Outros tipos de registo e a edição individual de um registo já existente mantêm-se exatamente como estavam. Nunca entra em `ClassResultsCalculator`/`BuildResultsProgression`/propostas/classificações — é só acompanhamento pedagógico, como qualquer outro registo.

## [0.45.9] — 2026-08-22

### Added

- **Reposição segura de palavra-passe no backoffice.** O detalhe de uma conta em `/admin` ganha "Repor palavra-passe" — envia o link de redefinição normal do Laravel (`Password::sendResetLink`, mesmo broker, mesmo token de utilização única, 60 minutos) para o email da conta, com diálogo de confirmação. Nunca mostra a palavra-passe atual, nunca a altera diretamente, nunca reativa uma conta desativada (essa continua bloqueada no login pela guarda já existente, mesmo depois de repor). Uma segunda ação, "Gerar palavra-passe temporária", reutiliza o mesmo padrão já usado na criação de contas (`Str::password(14)`, nunca persistida em claro) e mostra o valor uma única vez, com botão "Copiar" — nunca recuperável depois de sair da página. Disponível só para platform admin (herda a autorização do grupo de rotas já existente); bloqueado durante impersonação; ambas as ações auditadas sem nunca gravar a palavra-passe ou o token.

### Fixed

- **Uma falha de entrega SMTP ao repor a palavra-passe a partir do backoffice também escapava sem tratamento.** `Password::sendResetLink()` chama o envio da notificação diretamente, sem guarda própria (`Illuminate\Auth\Passwords\PasswordBroker`) — o mesmo padrão de falha corrigido em 0.45.8 para o registo. Corrigido a apanhar a exceção no controlador (o operador está a olhar diretamente para a página da conta, por isso é aí, e não no modelo, que faz sentido avisar de imediato) e a mostrar o mesmo erro já usado para outros estados falhados do broker, em vez de um 500.

## [0.45.8] — 2026-08-22

### Fixed

- **Uma falha de entrega SMTP no email de verificação derrubava o registo inteiro com um 500.** Diagnosticado em produção: `smoke2.local@lapis.test` (domínio local, sem entrega real possível) foi rejeitado pelo relé com um `550`, e a exceção do Symfony Mailer propagava-se por todo o pedido HTTP sem nada a apanhar — apesar de a conta, a organização pessoal, a membership e a subscrição inicial já terem sido criadas com sucesso antes desse ponto (confirmado em produção: zero dados órfãos). O mesmo caminho síncrono (`Registered` → `SendEmailVerificationNotification` → `MustVerifyEmail::sendEmailVerificationNotification()`) é partilhado pelo envio automático do registo e pelo botão "Enviar novamente" — corrigido uma única vez, sobrepondo o método em `App\Models\User` (nunca em código vendor) para nunca deixar uma falha de transporte escapar: `report()` regista o erro para alguém tratar, a conta nunca é marcada como verificada, e a pessoa continua o fluxo normal, vendo "A conta foi criada, mas não foi possível enviar o email de verificação neste momento. Pode tentar reenviá-lo dentro de instantes." em vez de um ecrã de erro. Sem filas nem workers novos — a decisão de não os introduzir nesta correção está registada, dado que produção não tem atualmente nenhum queue worker a correr.

## [0.45.7] — 2026-08-22

### Fixed

- **O campo de email do registo público ficava impossível de editar.** `Register.vue` vinculava `:value="prefillEmail ?? undefined"` ao componente `Input` partilhado — um prop que este não declara (só `defaultValue`/`modelValue`), por isso o Vue reencaminhava-o como atributo bruto direto para o `<input>` nativo, entrando em conflito com o `v-model` interno do componente (`useVModel` do `@vueuse/core`). O resultado: a cada nova renderização do formulário — o que acontece a cada tecla — o valor do campo era reposto a vazio, tornando impossível escrever, colar, editar parte do endereço, Ctrl+A, ou usar Backspace/Delete. Reproduzido em browser real (escrever, colar, editar a meio, substituir tudo, apagar a meio): todas as interações resultavam em campo vazio. Corrigido trocando para `:default-value`, o prop que o componente já declara e que só semeia o valor uma vez — o mesmo padrão já usado corretamente noutros campos da aplicação (ex. Nome em Configurações → Perfil). Auditado todo o `resources/js` por outros usos de `:value` num `Input` editável: nenhum outro encontrado — os restantes são `<option>`, campos `readonly`, ou um componente de apresentação distinto, por isso a correção fica isolada a este campo.

## [0.45.6] — 2026-08-21

### Added

- **Fusão do ramo remoto (`origin/main`) com os 41 commits locais acumulados desde a v0.42.6.** Dois ramos tinham avançado em paralelo sem se cruzar durante quase duas semanas: `origin/main` recebeu diretamente a nova landing page comercial (bullets abaixo); o local recebeu as Fatias 5 (encerramento recuperável de conta e de organização, `0.43.0` mais abaixo), 6 e 6.1 (backup/restauro pedagógico completo, incluindo clonagem entre organizações), a UI de Administração Institucional (`0.45.4`) e a consistência pt-PT (`0.45.5`). Os dois ramos usaram, cada um e de forma independente, o número `0.43.0` para conteúdo diferente; como o CHANGELOG não permite versões repetidas (`ReleaseVersionTest`), a entrega da landing page — que nunca chegou a ser publicada com esse número — entra diretamente aqui em vez de manter uma entrada `0.43.x` própria.
- **Nova página pública em `/`.** A homepage deixa de ser o cartão de visita de quatro pilares e passa a ser a página comercial do produto: problema, como funciona, personalização da avaliação, funcionalidades, o produto em ecrã, o que se ganha, confiança e proteção de dados, planos, perguntas frequentes e chamada final. Cabeçalho público fixo com âncoras e menu para telemóvel, e rodapé com produto e conta.
- **Os cartões de planos são lidos das tabelas de entitlements, não escritos no componente.** `HomeController` devolve cada plano com os módulos que carrega e, do segundo em diante, só o que ACRESCENTA ao anterior — por isso «tudo do LÁPIS Base, mais…» nunca pode divergir de `module_plan`. A tabela «Comparar os três planos» é gerada da mesma fonte. Mover um módulo entre planos no `EntitlementsSeeder` muda a página sem tocar em código de frontend.
- **Sete maquetas fiéis de ecrãs reais** — a proposta de classificação, o perfil de avaliação versionado, a pauta importada, a grelha de correção com os estados por célula, a análise da turma, as estratégias e medidas, e o relatório por secções — construídas com os componentes e os tokens da própria aplicação, e não com imagens. A análise da turma reutiliza o `OutcomeDonut` do dashboard de Estatística: uma página que redesenha um gráfico que o produto já tem está a mostrar algo que o produto não faz. Os dados são representativos; todos os estados e colunas mostrados existem.
- **As funcionalidades são apresentadas como as cinco etapas do ano** — organizar, avaliar, acompanhar, intervir, documentar — em separadores com um ecrã real por etapa, na mesma ordem por que `config/navigation.php` constrói o menu lateral. Uma grelha de quinze cartões iguais era a maneira óbvia de mostrar isto e a errada: quinze cartões dizem «aqui está uma lista», e ninguém lê uma lista. O `tablist` responde às setas do teclado.
- **Uma banda curta com as três regras de cálculo** que uma folha de cálculo não tem — vazio não é zero, «não aplicável» sai da conta, chegar tarde não custa zeros (§13.3, §11.4). Um argumento vale mais numa linha do que explicado.
- **SEO servido pelo servidor.** `description`, `canonical`, Open Graph, Twitter card e JSON-LD `SoftwareApplication` são escritos em `resources/views/app.blade.php` para a componente `Welcome`. Não podem viver no `<Head>` do Inertia: o SSR está desligado, e um robô lê a resposta antes de o bundle correr. Há um teste que fixa isto.

### Changed

- **A página responde ao ponteiro e ao scroll.** As maquetas levantam-se e os três pontos do cabeçalho ganham cor; os cartões de planos levantam-se com a sombra a acompanhar; as linhas das perguntas aquecem e o «+» roda; os separadores das etapas, os cartões do problema, as ligações do menu e do rodapé têm todos estado. Ao entrar em ecrã, cada secção revela-se — as maquetas em escala, o texto a subir — e o traço de acento do sobretítulo, a linha que liga os quatro passos e o traço de cada regra desenham-se da esquerda para a direita. Tudo isto é `motion-safe`: com `prefers-reduced-motion` o conteúdo aparece já colocado e nada se move.
- **Cada maqueta abre «O LÁPIS por dentro».** Clicar numa das janelas abre um diálogo com a estrutura da aplicação — o menu lateral pela ordem real, os seletores de contexto no cabeçalho, as ações da turma e a tabela de proposta e decisão — com cinco explicações que acendem a zona a que dizem respeito. Funciona nos dois sentidos: apontar para uma zona da imagem acende a explicação, e percorrer a lista acende a zona. A lista é o caminho acessível, feita de botões reais — e é por isso que a imagem não tem interatividade própria: um ponto sensível sobre a tabela teria de ser um botão a envolver uma tabela, o que não é válido nem utilizável.
- **O cabeçalho e o rodapé passam a ter tom próprio** — papel frio em claro, carvão azulado em escuro — em vez de serem o fundo da página com um contorno. Num ecrã escuro isso eram três pretos empilhados, e a moldura desaparecia dentro do conteúdo.
- **Barra de progresso de leitura** no limite inferior do cabeçalho, a partir do momento em que a página desce. Decorativa — o número que codifica é o da barra de scroll, que a tecnologia de apoio já expõe.
- **Os cartões de planos mostram no máximo seis módulos** e contam o resto. O Base carrega catorze, o que fazia um cartão mais alto do que um ecrã de portátil — e uma secção de preços que obriga a percorrer duas vezes é uma secção de preços que se salta. A tabela completa continua a um clique.
- **As Perguntas passam a duas colunas** — título e chamada para ação à esquerda, perguntas a ocupar a largura que antes ficava vazia.
- **Copy encurtada** em quase todas as secções. O texto anterior era exato e demasiado longo para uma página que se lê a percorrer.
- A rota `/` passa de `Route::inertia` para `HomeController`, por precisar de ler os planos. Continua a chamar-se `home` e continua a servir a mesma componente `Welcome`.
- Quem visita `/` já autenticado continua a ver a página — o cabeçalho e as duas chamadas para ação trocam para «Ir para o painel» em vez de redirecionar, que é o que se espera de quem chega por um link partilhado e a única forma que não pode entrar em ciclo com o painel.
- **O tour «O LÁPIS por dentro» deixa de existir abaixo de `lg`.** É uma vista de duas colunas cujo objetivo é apontar para uma zona e acender a explicação; num telemóvel colapsa num scroll longo sem nada que o conduza, que é pior do que a maqueta que o visitante já estava a ver. `display: none` tira também o botão da ordem de tabulação, para não ficar nada invisível alcançável. (Os efeitos de hover já não disparavam ao toque — o Tailwind v4 envolve todos os `hover:` em `@media (hover:hover)`.)
- **O tom do cabeçalho e do rodapé passa de azul para o âmbar da marca**, e mais saturado do que a primeira tentativa: `hsl(36 75% 93%)` em claro e `hsl(28 55% 13%)` em escuro, contra os `hsl(38 88% 65%)` do lápis do logótipo. Mesma família, um à luminosidade de papel e outro à de tinta.
- **O tom do texto teve de acompanhar o fundo.** O `text-muted-foreground` é um cinzento escolhido contra branco; sobre o creme novo mede 4,21:1, abaixo dos 4,5:1 que texto normal exige. Saturar o fundo sem mexer no texto teria tornado o rodapé discretamente ilegível. As duas cores de texto do chrome são quentes e medidas — 4,90:1 em claro, 6,77:1 em escuro. O botão «Entrar» ganhou hover próprio pela mesma razão: o token `accent` partilhado é um âmbar pálido escolhido contra branco e, neste fundo, o hover lia-se como não ter acontecido nada.

### Fixed

- **As âncoras do menu pareciam não funcionar em telemóvel, e a culpa era do scroll suave.** A página tem cerca de 13000px num ecrã pequeno; com `scroll-behavior: smooth`, um salto do hero para «Perguntas» animava durante mais de três segundos. Medido com toque emulado, aos 2,5s a página ainda ia a meio caminho (7430 de 8326) — o que quem toca no menu lê como «está partido», não como elegância. Tocar outra vez cancelava a animação a meio, o que explica aterrar «no meio das secções». O scroll suave foi removido: um salto instantâneo é o que uma âncora deve fazer, é igual em todos os browsers incluindo o Safari do iOS, e são menos vinte linhas de código.
- **A gaveta do menu fecha antes de a página rolar.** Enquanto está aberta, o reka-ui põe `overflow: hidden` no body e um scroll pedido nesse momento é simplesmente ignorado. Esperar um número fixo de milissegundos era adivinhação — a animação de fecho não dura o mesmo em todo o lado. Passa a esperar que o painel saia mesmo do DOM, com desistência ao fim de ~1,5s para nenhuma ligação ficar morta.
- **Ao saltar para uma secção, o título ficava a 190px do topo** de um ecrã de 844 — quase um quarto vazio antes de se ler alguma coisa. Ritmo vertical mais apertado no telemóvel e margem de scroll colada ao cabeçalho: passa a 150px, com o topo da secção a 72px.

### Não incluído

- **Preços.** Não existe nenhum preço no produto — nem em configuração, nem em base de dados — e a composição comercial dos planos está na lista do §31. Os cartões mostram o que cada plano CARREGA e dizem «Preço por anunciar» no Pro e no Institucional. O Base diz «Incluído ao criar conta», que é literalmente o que acontece hoje: o registo cria a organização já subscrita ao Base e nada pede cartão.
- Páginas de Termos, Privacidade, Cookies, Sobre e Contacto — não existem, por isso o rodapé não as inventa como links mortos.
- Imagem Open Graph (`og:image`) — sem asset, o cartão grande do Twitter/X renderiza uma caixa vazia, por isso ficou `summary` e não `summary_large_image`.

## [0.45.5] — 2026-08-21

### Fixed

- **Inglês residual na interface, sobretudo em Configurações.** Auditoria global ao `resources/js` confirmou que `lang/pt_PT/{auth,passwords,validation}.php` já estavam completos e ativos (`.env` já define `APP_LOCALE=pt_PT`) — o inglês encontrado era sempre copy fixa nos componentes Vue, nunca mensagens do Fortify/Laravel. Corrigidos Configurações → Perfil, Segurança, Aparência; gestão de 2FA e chaves de acesso (passkeys); páginas de autenticação (login, confirmação de password, etc.); menu do utilizador; exportação de dados; ligações de autoavaliação; e rótulos de acessibilidade (`sr-only`/`aria-label`) em diálogos, painéis laterais, breadcrumb e indicador de carregamento reutilizados em toda a aplicação. Inclui dois toasts que citavam `__('Password updated.')`/"Password temporária" sem qualquer chave `pt_PT` correspondente — ficavam literalmente em inglês em produção. Sem alterar nomes técnicos, enums, rotas, chaves de API ou colunas de base de dados; os dois erros devolvidos pelo pacote externo de passkeys (`@laravel/passkeys/vue`) continuam em inglês por não haver, no projeto, uma forma segura e já usada de lhes definir o idioma.
- **`routes/web.php` com um import fora de ordem** (`InstitutionAdminController` depois de `InstrumentController`, introduzido em 0.45.4), apanhado só agora por `pint --test`.

## [0.45.4] — 2026-08-21

### Fixed

- **O encerramento da organização institucional (Fatia 5) não tinha caminho de UI próprio.** A ação, a janela de recuperação de 90 dias, a reativação e toda a proteção de autorização já existiam e estavam testadas desde a Fatia 5 — mas a única forma de lhes aceder era a secção "Encerrar organização" no fundo de `team/Index.vue` (Equipa), enquanto o item de menu "Administração Institucional" mostrava um placeholder genérico da Fase 7, sem qualquer ligação ao encerramento. Sem alterar a ação, as rotas, a política ou a janela de retenção, a secção passou para uma página própria e mínima em Instituição → Administração Institucional (`institution.index`, `InstitutionAdminController`, componente reutilizável `ClosureCard.vue`), com uma frase explícita a distinguir esta ação — que afeta só a organização institucional — do encerramento da conta pessoal do próprio responsável (Configurações, 60 dias). "Equipa" mantém-se dedicada à gestão de membros e convites.

## [0.45.3] — 2026-08-21

### Changed

- **Clarificado, por auditoria, o comportamento de autoria na clonagem entre organizações (não é um bug).** Registos pedagógicos, estratégias/medidas e relatórios têm autor obrigatório na base de dados — não podem, por regra já existente, ser atribuídos a ninguém que não seja quem os escreveu. Quando o mesmo professor restaura a própria conta para uma organização nova ou vazia, a autoria mapeia-se corretamente, sem qualquer alteração de código — já funcionava. Quando o destino é uma conta genuinamente diferente, esses três domínios ficam corretamente `invalid`; a estrutura e a avaliação sem exigência de autoria pessoal continuam a clonar-se normalmente. A mensagem mostrada nesses casos passa a explicar isto diretamente — "a autoria de X é obrigatória e só pode ser confirmada com a conta que a criou" — em vez de um genérico "não pode ser confirmada com segurança". Ver `docs/backup-schema.md`.

## [0.45.2] — 2026-08-21

### Added

- **Anos letivos e disciplinas restauráveis (`schema_version = 5`).** O backup passa a transportar os dados reais completos dos anos letivos e das disciplinas referenciados pelo grafo pedagógico. Um restauro autorizado para uma organização vazia pode agora criá-los antes das turmas, períodos, domínios, perfis e relatórios que os referenciam, sem inventar datas, estados, país ou código e sem reutilizar um `ulid` pertencente à organização de origem.

### Fixed

- **A clonagem entre organizações (0.45.1) ficava parcial sempre que a organização de destino estivesse genuinamente vazia.** Turmas, inscrições, períodos letivos e domínios continuavam classificados como inválidos porque dependiam do ano letivo e da disciplina resolverem primeiro — e esses dois, até agora, nunca eram criados, só correspondidos por nome. A pré-visualização mostrava "nada novo para importar" mesmo com a clonagem em si a funcionar corretamente para os domínios que não dependiam deles. Resolvido pela mesma correção acima: turmas, inscrições, períodos e domínios já não precisaram de nenhuma alteração própria — resolvem-se corretamente assim que o ano letivo e a disciplina resolvem.

## [0.45.1] — 2026-08-21

### Added

- **Restauro pedagógico para outra organização, com a origem intacta.** Até agora, restaurar um backup para uma organização diferente da que o gerou classificava tudo como não restaurável assim que a organização de origem ainda existisse — mesmo sendo esse exatamente o cenário de copiar dados pedagógicos autorizados para outra conta. Passa a funcionar: quando o `ulid` de uma linha pertence a outra organização, o plano procura agora, só dentro do destino, uma correspondência pela identidade real dessa linha (a mesma chave que a base de dados já usa para a distinguir — ano+sequência de um período, nome de uma escala, rótulo de uma turma, código de um aluno, e por aí em diante; para o punhado de domínios sem essa chave — elementos de avaliação, registos, estratégias — usa-se a combinação de conteúdo que os identifica de forma equivalente, incluindo o hash já guardado num relatório finalizado ou numa avaliação intercalar). Havendo correspondência, é `existente` ou `conflito`, exatamente como uma linha do mesmo destino; não havendo, é `nova`, restaurada com uma identidade própria gerada de raiz — nunca a da origem. A organização de origem nunca é escrita, nunca é lida senão para esta comparação, e nunca perde nenhum dado. Reimportar o mesmo backup para o mesmo destino continua sem criar duplicados, pela mesma correspondência.

### Fixed

- **A verificação de "este registo já existe noutra organização" não via escalas nem tipos de elemento de outras organizações.** `Scale` e `InstrumentType` protegem a visibilidade dos seus registos de sistema com um âmbito de consulta próprio (`scaleVisibility`, `typeVisibility`), não o âmbito genérico `organization` que a verificação removia explicitamente. O resultado: uma escala ou tipo de elemento próprio de outra organização parecia não existir em lado nenhum, caía no caminho de "genuinamente novo" e tentava gravar-se com o `ulid` da origem — falhando com um erro de base de dados em vez de ser tratado como uma clonagem. Corrigido para remover todos os âmbitos da consulta, não só um nome fixo.
- **Um domínio sem correspondência dentro da mesma organização rebentava a classificação.** A verificação da chave de negócio de um domínio (`BuildAssessmentStructurePlan::classifyDomains()`) referenciava a variável que a construía sem a capturar no closure — um erro de PHP a meio da pré-visualização sempre que havia pelo menos um domínio nessas condições.



### Added

- **Restauro completo do grafo pedagógico (schema v2, `schema_version = 4`).** O backup gerado pelo LÁPIS deixa de se limitar a turmas, alunos e inscrições — passa a transportar também períodos letivos, escalas (e os seus níveis), tipos de elemento, domínios, perfis de avaliação e as suas versões (com os pesos de domínio e de período), elementos de avaliação e itens (com as alocações por domínio), pontuações registadas, o registo completo de classificações, modelos e respostas de autoavaliação, avaliações intercalares, registos pedagógicos, estratégias e medidas (com as suas revisões), e relatórios finalizados. Um professor que perca os dados de uma turma pode agora restaurá-la por inteiro a partir de um backup próprio e continuar a trabalhar exatamente onde ficou — o motor de cálculo (`BuildResultsProgression`, `ClassResultsCalculator`) recalcula os resultados a partir dos factos restaurados, nunca de um valor guardado no backup. Ver `docs/backup-schema.md`.
- **O backup transporta factos, nunca resultados.** Nenhuma média, evolução ou estatística de turma é alguma vez exportada ou importada — só o que um professor ou o sistema já escreveu como facto (uma pontuação, uma decisão de classificação já confirmada, uma resposta de autoavaliação). Isto é um princípio estrutural desta fatia, não um detalhe de implementação: duplicar a lógica de cálculo no formato de backup criaria um segundo sítio para essa lógica divergir do primeiro.
- **Escalas e tipos de elemento de sistema, restaurados só por correspondência.** Cada instalação do LÁPIS semeia a sua própria cópia das escalas e tipos de elemento de sistema, cada uma com o seu próprio `ulid` gerado independentemente — por isso estas nunca são criadas por um restauro, só correspondidas por nome/código à instalação de destino. Escalas e tipos de elemento próprios de uma organização continuam a restaurar-se por `ulid`, como qualquer outro registo.
- **Autoria mapeada só quando inequívoca, nunca inventada.** Cada campo de autoria do backup (quem avaliou, quem confirmou, quem criou um registo) é um email — na importação, só é atribuído ao utilizador que está a confirmar essa mesma importação, e só se o email corresponder. Em qualquer outro caso, o campo fica vazio quando a coluna o permite, ou bloqueia a linha inteira quando não permite — nunca é atribuído a outra pessoa da organização de destino.
- **Pré-visualização de importação, agrupada por Estrutura/Avaliação/Acompanhamento/Documentos.** A página de pré-visualização de um restauro passa a mostrar as novas dezenas de domínios organizadas em quatro grupos pedagogicamente reconhecíveis, com contagens de novos/existentes/conflitos/inválidos/não suportados — em vez de uma lista técnica plana. Pontos a rever (conflitos, dados inválidos, linhas não suportadas) continuam sempre visíveis, nunca escondidos, mesmo quando o domínio em causa não tem linha própria na tabela resumo.

### Fixed

- **Nenhum perfil de avaliação estava a ser restaurado.** `AssessmentProfile` não tinha conversão booleana declarada para `is_institutional_template`; o valor viajava pelo JSON do backup como um inteiro (`0`/`1`) em vez de um booleano, e a validação — que exige estritamente um booleano — rejeitava a linha inteira em silêncio. Como todas as versões de perfil dependem do respetivo perfil já ter sido restaurado, o efeito prático era que nenhuma turma recuperava o seu perfil de avaliação, e por isso nenhum resultado se recalculava depois de um restauro. Corrigido com a conversão em falta.
- **A segunda confirmação de um mesmo backup falhava sempre que havia autoavaliações.** As perguntas de um modelo de autoavaliação já existente no destino perdiam a referência ao modelo antes de chegar à escrita, porque a classificação `existing` desse domínio não a transportava — só a `new` o fazia. Corrigido para a incluir também nas linhas já existentes, que é o caminho que uma segunda importação do mesmo backup sempre segue.
- **Tipos de elemento de sistema rebentavam a escrita.** A construção da chave de correspondência de um tipo de elemento de sistema (`instrument_types`) omitia o código nas linhas devolvidas pelo plano, mas a escrita precisa desse código para reconstruir a mesma chave — todo tipo de elemento de sistema (o caso comum, usado por omissão em qualquer elemento de avaliação) fazia a importação falhar.
- **`applied_on` de um elemento de avaliação nunca era restaurado.** Esta coluna é obrigatória na base de dados e é a data comparada com a inscrição de cada aluno na regra de inscrição tardia (§11.4) — sem ela, nenhum elemento de avaliação com pontuações associadas conseguia sequer ser escrito. Adicionada a toda a cadeia: exportação, validação, plano e escrita.



### Added

- **Restauro e importação segura de backup.** Um professor ou responsável pode carregar um backup gerado pelo próprio LÁPIS (o ZIP completo de uma exportação, ou apenas `backup-lapis.json`) em Configurações → Importar dados, rever uma pré-visualização de tudo o que seria criado, existente, em conflito ou não suportado, e só então confirmar — nada é escrito antes da confirmação explícita. Restaura turmas, alunos e inscrições com a identidade original (`ulid`) preservada, o que torna importar o mesmo backup duas vezes seguro (a segunda vez não cria duplicados). Nunca sobrescreve um registo existente que tenha divergido localmente — fica marcado como conflito, nunca em merge silencioso. Anos letivos e disciplinas em falta no destino bloqueiam apenas a turma que os referencia, com uma mensagem clara em vez de inventar dados; elementos de avaliação e classificações são sempre marcados como não suportados nesta versão, por o formato de backup ainda não transportar os dados necessários para os recriar em segurança. Ver `docs/data-import.md`.
- **Segurança de ficheiro dedicada ao restauro.** O ZIP nunca é extraído para disco — só a entrada `backup-lapis.json` é lida diretamente, depois de validar todas as entradas uma a uma (limite de 500 entradas, 20 MB por entrada, 50 MB no total, e rejeição de qualquer nome de entrada com travessia de diretório ou caminho absoluto). Um leitor separado varre o conteúdo à procura de qualquer chave com forma de segredo (password, token, 2FA, passkey, SMTP, etc.) a qualquer profundidade e rejeita o ficheiro inteiro se encontrar alguma — antes mesmo de qualquer campo ser lido para a base de dados.
- **Limitação documentada: identidade global de `ulid`.** `classes.ulid`, `students.ulid` e `enrollments.ulid` são únicos em toda a base de dados, não por organização — restaurar para uma organização diferente da de origem só cria registos novos se a origem já não os tiver. O plano de importação deteta isto na pré-visualização (classificando a linha como inválida, com uma mensagem explícita) em vez de deixar a escrita falhar com um erro SQL bruto.
- `php artisan data-imports:prune`, agendado de hora a hora, fecha restauros por confirmar passado o prazo de expiração e limpa o ficheiro carregado e quaisquer ficheiros órfãos — nunca os dados já restaurados.
- `GenerateDataExport` passa a `schema_version = 3`: cada linha de `enrollments` no backup transporta agora `enrolled_on`, `left_on` e `class_number`, o mínimo necessário para recriar uma inscrição sem inventar uma data. Backups `schema_version = 2` já emitidos continuam legíveis; linhas que precisariam de `enrolled_on` para serem criadas ficam marcadas como não suportadas em vez de inventarem a data em falta.
## [0.43.0] — 2026-08-21

### Added

- **Encerramento recuperável de conta pessoal (60 dias).** Um utilizador pode pedir o encerramento da própria conta em Configurações — a conta fica disponível para recuperação e exportação durante 60 dias; pode reativá-la a qualquer momento dentro desse prazo. Ao fim do prazo, fica apenas **elegível** para eliminação — nada é apagado automaticamente. Bloqueado se a pessoa for responsável por alguma organização institucional ativa, até transferir a responsabilidade primeiro. Ver `docs/account-closure.md`.
- **Encerramento recuperável de organização institucional (90 dias).** O responsável (owner) pode pedir o encerramento da organização — membros, responsável e todos os dados pedagógicos permanecem intactos; só fica bloqueada nova atividade de escrita durante a janela. Qualquer membro vê um aviso, mesmo sem acesso à página de Equipa; só o responsável pode pedir ou cancelar.
- **Bloqueio de atividade normal durante o encerramento.** Novo middleware que permite sempre leitura, e durante uma janela de encerramento ativa restringe escrita a um conjunto reduzido de ações seguras (cancelar o encerramento, exportar dados, terminar sessão, mudar de organização, gerir password/2FA/passkeys). Nunca afeta outras organizações da mesma pessoa nem a organização pessoal.
- **Pré-visualização de elegibilidade para retenção, só de leitura.** `php artisan retention:status` (com `--json`) lista contas e organizações em encerramento com dias restantes, exportações expiradas por limpar, anos letivos fora da janela pedagógica por organização, e a contagem de eventos de auditoria fora da janela de segurança — nunca apaga nada. Visibilidade equivalente, também só de leitura, na ficha de conta do backoffice.
- **Estrutura do Ano Letivo, centralizada em Configuração.** Nova entrada na barra lateral que reúne a gestão de Anos letivos e Disciplinas (já existente, sem alteração de rotas, controllers ou policies) sob um único separador. O seletor de contexto no topo continua a permitir escolher ano letivo/disciplina, mas deixa de ser o único caminho até à sua gestão — e mostra "Sem ano letivo configurado" / "Sem disciplinas configuradas" em vez de um traço vazio quando não existe nenhum. Ver `docs/academic-structure.md`.

### Fixed

- **`data-exports:prune` falhava sempre a partir do scheduler.** Agendado de hora a hora desde a Fatia 4, este comando lançava `TenantNotResolvedException` sempre que corria fora de um pedido HTTP — ou seja, sempre que o scheduler o disparava — porque a sua query de limpeza estava scoped à organização, mas nenhuma organização é resolvida num contexto de consola. O teste existente não apanhou isto por correr logo a seguir a um pedido HTTP simulado, que deixava um tenant "preso" na mesma execução. Corrigido para operar explicitamente entre organizações (`withoutGlobalScope('organization')`), com um novo teste que reproduz a condição real do scheduler.
- **Eliminar a própria conta ressuscitava-a.** `ProfileController::destroy()` invalidava a sessão antes de terminar; ao mover essa ordem para acomodar a nova guarda de encerramento institucional, `Auth::logout()` — chamado sobre a mesma instância que acabara de ser apagada — voltava a inserir a linha ao ciclar o remember token num modelo já marcado como não existente. Corrigido operando sobre uma cópia distinta.
- **Apagar a própria conta sendo responsável por uma instituição só falhava com um erro SQL cru.** `DeleteUserAccount` deixa agora uma mensagem explícita — "transfira a responsabilidade primeiro" — em vez de deixar a restrição de chave estrangeira ser a primeira UX.

### Changed

- O prop partilhado `scope.academicYear` (seletor de contexto no topo) deixou de estar permanentemente vazio — reflete agora o ano letivo atual da organização, pela mesma heurística já testada que os relatórios de retenção usam.

## [0.42.6] — 2026-08-20

### Fixed

- **Criação de turma pouco descoberta na validação manual.** A página Turmas já tinha "Nova turma" como botão principal no topo — mas o estado vazio ("ainda sem turmas") mostrava só texto, sem ação clicável. Passa a ter também um botão "Criar a primeira turma", reutilizando exatamente a mesma rota/formulário/validação/action que "Nova turma" já usa — nenhum fluxo novo. Auditado o seletor de contexto no topo (Ano letivo/Disciplina/Ano/Turma/Período): não tem nenhuma ação de criação — os seletores "Ano", "Turma" e "Período" estão simplesmente desativados (fase futura), nada a remover aí.

## [0.42.5] — 2026-08-20

### Changed

- **A exportação de dados passa a produzir um workbook Excel legível, em vez de CSVs técnicos.** Validação manual encontrou os CSV difíceis de interpretar no Excel — colunas com ids técnicos, sem formatação. `Exportacao-LAPIS.xlsx` substitui os CSV: uma folha "Resumo" (sempre presente) mais uma folha por domínio com dados (Turmas, Alunos, Elementos de Avaliação, Avaliações, Classificações, Autoavaliações, Estratégias e Medidas, Registos, Relatórios) — só as que fizerem sentido para os dados existentes. Cabeçalhos em PT-PT, primeira linha fixa, filtro automático, larguras ajustadas, datas como datas Excel, números como números.
- **Nomes em vez de identificadores técnicos.** Nenhuma folha mostra `organization_id`, `class_id`, `student_id` ou equivalente como coluna principal — os dados são resolvidos para Organização, Turma, Aluno, Disciplina, Ano letivo, etc.
- **Terminologia igual à do resto do LÁPIS.** "Elementos de Avaliação", "Estratégias e Medidas", "Média Ponderada", "Proposta", "Classificação atribuída" — os mesmos termos já usados nas páginas de Classificações e Estratégias, nunca "Instrumentos", "Intervenções" ou "Resultado" genérico. A Média Ponderada e a Proposta são lidas dos mesmos serviços que a página de Classificações já usa (`BuildResultsProgression`, `ScaleProposalResolver`), chamados uma vez por turma — nunca recalculadas nem uma fórmula paralela.
- **`backup-lapis.json` substitui `manifest.json`** como o backup técnico estruturado (ids estáveis, relações, pronto para uma futura importação) — não se destina a leitura direta; o XLSX é o documento para isso.

### Security

- Sem alteração de scope: o XLSX só contém o que o utilizador já podia exportar — as próprias turmas (`class_teachers`), nunca o trabalho pedagógico de colegas, mesmo para o responsável institucional.

## [0.42.4] — 2026-08-20

### Fixed

- **Ações da página Equipa eram só ícones, sem explicação.** Na validação manual foi preciso adivinhar o que "Transferir responsabilidade" e "Remover da organização" faziam. Os dois botões passam a mostrar o texto completo junto do ícone (com `aria-label` e `title` PT-PT), em vez de depender só do ícone — não fica dependente de hover, funciona em ecrãs táteis, e não obriga a adivinhar.
- Copy do diálogo de confirmação de transferência ajustada ao texto sugerido: "Vai transferir a responsabilidade da organização para {Nome}. Deixará de ser responsável e continuará como membro da organização."

## [0.42.3] — 2026-08-20

### Fixed

- **"Transferir responsabilidade" na página Equipa não tinha confirmação forte.** Substituído o `confirm()` genérico do browser pelo componente `Dialog` já usado em "Sair da organização" e "Delete account" — indica o nome de quem vai passar a responsável, que o utilizador atual deixa de o ser e continua como membro. Clicar no ícone só abre o diálogo; só o botão "Transferir responsabilidade" dentro dele envia o pedido. Proteção contra duplo-clique preservada.

## [0.42.2] — 2026-08-20

### Fixed

- **Transferência de responsabilidade redirecionava o antigo responsável para uma página que já não podia ver.** A transferência em si sempre funcionou corretamente (confirmado pelo próprio registo de auditoria de produção) — o bug estava no destino do redirecionamento: `TeamController::transferOwnership` enviava sempre para `/team`, uma página só para o responsável (`OrganizationInvitationPolicy::viewAny`). Quem acabou de transferir a responsabilidade deixa de o ser nesse preciso instante, por isso a página seguinte devolvia sempre 403 — uma transferência bem-sucedida parecia ter falhado. Corrigido para redirecionar para `dashboard`, o mesmo destino já usado por `OrganizationController::switch()` e `OrganizationMembershipController::leave()` exatamente por esta razão.
- Botões "Transferir responsabilidade" e "Remover da organização" na página Equipa ganham proteção contra duplo clique.

## [0.42.1] — 2026-08-20

### Added

- **Fatia 4 — UI de governação de membros e exportação.** Página Equipa ganha "Remover da organização" e "Transferir responsabilidade" por membro, com confirmação forte; página de definições de perfil ganha "Sair da organização" (ou a instrução para transferir primeiro, se for o responsável) e a ligação para "Exportar os meus dados"; nova página "Turmas a Reatribuir" no grupo Instituição, com seleção de um membro atual por turma órfã.

## [0.42.0] — 2026-08-20

### Added

- **Fatia 4 — saída e governação de membros.** Um membro pode sair de uma organização institucional, o responsável pode remover outro membro e pode transferir a responsabilidade para um membro atual. As três mutações de governação recusam sessões de suporte impersonadas e deixam rasto de auditoria.
- **Reatribuição de turmas sem professor.** Turmas em preparação ou ativas sem qualquer linha em `class_teachers` são detetadas como estado derivado, sem coluna nem migração, e podem ser atribuídas pelo responsável a um membro atual da mesma organização.
- **Exportar os meus dados.** Qualquer conta, em qualquer plano, pode pedir uma cópia ZIP dos dados a que tem acesso — as próprias turmas e o que lhes está associado, nunca o trabalho pedagógico de colegas. Um responsável institucional recebe adicionalmente a lista da equipa e o registo de auditoria a que já tinha acesso, nunca dados pedagógicos nominais extra. Gerado de forma síncrona para `storage/app/private`, nunca público; fica disponível 24 horas e é depois removido por `data-exports:prune` (agendado a cada hora). Inclui `manifest.json` e um `README.txt` que enumera o que nunca é incluído (password, 2FA, passkeys, tokens de sessão/convite, segredos de configuração).
- **Arquitetura de política de conservação de dados.** `config/retention.php` fixa os alvos de retenção (dados pedagógicos: ano letivo atual + 3 anteriores; conta pessoal encerrada: 60 dias; organização institucional encerrada: 90 dias; logs técnicos: 90 dias; auditoria: 3 anos; backups técnicos: 30–60 dias; exportações: 24 horas), lidos por `App\Support\Retention\RetentionPolicy`. `AcademicYearRetentionClassifier` conta sempre em anos letivos (ordenação por `starts_on`), nunca por `created_at`, e nunca marca nada como "elegível para eliminação" — só `within_retention`. `ClosureRetention` implementa a fronteira de recuperabilidade (estritamente "menor que"), pronta a ligar a um futuro fluxo real de encerramento — nenhuma coluna nova foi criada para isso.
- Cobertura feature explícita para isolamento entre organizações, manutenção de contas e memberships não relacionadas, transferência imediata de autoridade, reatribuição com e sem co-docente, preservação de alunos/inscrições/evidências/autoria histórica, segurança da exportação (ficheiro privado, sem segredos, negado a quem não pediu) e fronteiras exatas da política de retenção.

### Security

- Saída e remoção retiram apenas as atribuições atuais em `class_teachers` da organização afetada. Nenhum aluno, inscrição ou registo pedagógico é apagado e nenhum campo histórico `*_by`/`causer_id` é reescrito.
- `DataExportPolicy` nega o download a qualquer pessoa que não seja quem pediu a exportação, mesmo dentro da mesma organização — o responsável institucional não pode descarregar a exportação de outro membro.

### Não incluído nesta fatia

- Nenhum job de purga, anonimização ou eliminação de dados reais além da limpeza do próprio ZIP de exportação expirado. Nenhuma coluna de encerramento de conta/organização. Nenhuma role intermédia, resolução de duplicados de alunos, ou alteração a fórmulas/resultados/planos.

## [0.41.1] — 2026-08-20

### Investigado

- **Suposta regressão no fluxo público de convites (Fatia 3).** Reportado um convite válido a ser recusado num `GET /invitations/{token}` sem sessão. Auditados a rota, o controller, `AcceptOrganizationInvitation::findByToken()` e o scope de tenancy em `OrganizationInvitation` — sem defeito encontrado: o lookup por `token_hash` já ignora corretamente o scope de organização antes de qualquer tenant estar resolvido, e um `GET` simples nunca aceita o convite (confirmado ao vivo: o token efetivamente enviado resolveu corretamente e o registo ficou por aceitar). A causa foi um erro de transcrição do token de 64 carateres no relato (`0`↔`O`, `M`↔`m`), não um bug de código.

### Added

- Teste de regressão explícito (`a_bare_get_with_no_session_never_accepts_the_invitation`) que fixa em código o comportamento verificado: um `GET` sem sessão a um convite válido nunca marca `accepted_at`/`cancelled_at` nem cria conta ou membership.

## [0.41.0] — 2026-08-20

### Added

- **Fatia 3 — convites e equipa institucional.** O responsável de uma organização institucional passa a convidar pessoas por email para a organização, em vez de precisar do backoffice do platform admin para cada membro.
- **Página Equipa**, só para o responsável — lista os membros atuais e os convites pendentes, e tem o formulário para convidar. Convidar o mesmo email outra vez renova o convite existente em vez de duplicar; o link anterior deixa de funcionar. Cancelar um convite pendente marca-o, não o apaga — fica no histórico.
- **O link do convite funciona com conta existente ou nova, sem duplicar o registo.** Alguém já com conta é levado a entrar; alguém sem conta é levado a criá-la — reutilizando o registo do Fortify tal como está, com a password sempre escolhida pela própria pessoa e nunca enviada por email. Nos dois casos, ao terminar, a pessoa junta-se automaticamente à organização e é levada ao Painel do Professor já nesse contexto.
- **A prova de acesso ao email não se pede duas vezes.** Quem chega pelo link do convite já demonstrou controlar essa caixa de correio; a verificação de email do Fortify é dada como cumprida nesse preciso caso, em vez de pedida de novo.
- Um utilizador autenticado com um email diferente do convidado nunca aceita em silêncio — vê uma página clara a explicar a situação, com a opção de terminar sessão para abrir o link com a conta certa.

### Changed

- A organização institucional passa a ter membros para além do responsável através deste caminho — o backoffice da Fatia 2 mantém-se disponível para o platform admin, mas deixa de ser o único.

### Não incluído nesta fatia

Remover membro, sair da organização, transferir propriedade, coordenador/direção ou qualquer papel intermédio — ficam para a Fatia 4.

## [0.40.0] — 2026-08-20

### Added

- **Fatia 2 — organização institucional e multiutilizador base.** Infraestrutura mínima para uma organização ter mais do que um utilizador, sem ainda construir convites, página Equipa ou papéis intermédios.
- **O backoffice cria organizações institucionais.** A mesma página «Nova conta» ganha a escolha entre Pessoal e Institucional. O responsável pode ser um utilizador novo (que fica também com a sua própria organização pessoal, como qualquer conta nova) ou um utilizador já existente (a sua organização pessoal mantém-se intocada). Criação atómica: organização, dono no `owner_id` **e** no pivot de membership, e subscrição — nunca um dono sem membership, que é o que impedia a Fatia 1 de ter algo real para gerir.
- **Gestão mínima de membros no backoffice.** A ficha de uma organização institucional ganha um bloco «Membros» — nome, email, Responsável/Membro, estado — e um formulário para o platform admin adicionar um utilizador já existente por email. Não é um convite: sem token, sem aceitação. Pertencer à organização pessoal e a uma institucional ao mesmo tempo é o suporte para isto.
- **Seletor de organização.** Quem pertence a mais do que uma organização vê, no menu do utilizador, a lista de organizações com um indicador da atual; quem só tem uma continua sem ver nada de novo. Trocar (`POST /organizations/switch`) exige pertencer à organização alvo — verificado no servidor, nunca só pela UI — e leva sempre ao Painel do Professor, nunca a uma página com um id da organização anterior.

### Changed

- **Módulos e plano passam a acompanhar a organização selecionada, não a sessão de login.** Ao trocar de organização, os `modules` e o menu lateral recalculam-se a partir da subscrição da organização atual — confirmado sem logout entre uma organização Base e uma Pro/Institucional.
- O predicado de subscrição inicial (`CreatePersonalOrganization`) foi extraído para `SubscribeOrganization`, partilhado agora com a criação institucional — a mesma regra escrita uma só vez, não duplicada.

### Não incluído nesta fatia

Convites por email, página Equipa, remoção ou saída de membro, transferência de propriedade, papéis coordenador/direção — ficam para as Fatias 3 e 4.

## [0.39.0] — 2026-08-20

### Added

- **Fatia 1 — segurança multiutilizador.** Prepara o que acontece no dia em que uma organização tiver mais do que um membro, sem ainda construir convites, equipa ou seletor de organização — só as duas regras de fronteira que têm de existir antes de qualquer uma dessas coisas.
- **Registo de atividade: um membro vê só os seus próprios eventos; o responsável da organização vê o registo inteiro.** Até aqui `/activity` mostrava os últimos 100 eventos da organização a qualquer pessoa autenticada, sem filtro. Passa a aplicar `causer_id = utilizador` para um membro normal, e sem filtro para quem é dono (`organizations.owner_id`) — porque o registo de atividade é uma função de segurança institucional, não um diário pessoal. Numa organização pessoal o resultado não muda: há sempre uma só pessoa, por isso as duas regras devolvem os mesmos eventos.
- **Configuração partilhada passa a ter dono.** Anos letivos, disciplinas, perfis de avaliação e escalas não têm variante "pessoal" — pertencem sempre à organização inteira. Um membro continua a lê-los e a usá-los (uma turma precisa de um perfil de avaliação para ser avaliada); só o responsável da organização os cria, edita ou apaga. Numa organização pessoal, o dono é a única pessoa que lá está — nada muda para quem usa o LÁPIS sozinho hoje.
- Nas páginas afetadas, os controlos de criar/editar/apagar deixam de aparecer a um membro sem essa permissão, em vez de aparecerem e falharem com 403 ao serem usados. A autorização em si continua só no servidor — a UI só evita o percurso morto.

### Changed

- `OrganizationPolicy`, `ReportPolicy` e `ReportTemplatePolicy` já implementavam exatamente esta regra (ler é de todos, escrever é do dono); o predicado que cada uma duplicava à sua maneira foi extraído para `User::owns()`/`ownsCurrentOrganization()`, para as quatro policies novas apontarem ao mesmo sítio.

## [0.38.4] — 2026-08-20

### Changed

- **A secção «Apagar definitivamente» deixa de falar em desativar "a conta".** Depois de separar utilizador, subscrição e eliminação no resto da ficha (0.38.3), esta frase continuava a misturar os três sob um único "conta". Passa a nomear as duas peças certas: desativar um **utilizador** bloqueia a pessoa; a eliminação definitiva é a exceção, só possível sem dados associados. Sem alteração à regra de bloqueio, ao endpoint `DELETE` ou ao que conta como dependência.

## [0.38.3] — 2026-08-20

### Changed

- **A ficha da conta no backoffice separa visualmente o que é da pessoa, o que é administrativo e o que é da organização.** No bloco «Dono», os campos de nome/email passam a ter o seu próprio subtítulo («Dados do utilizador»), e as ações de suporte — verificar email, promover a admin, aceder como utilizador, desativar — ficam num bloco «Ações administrativas» separado, em vez de tudo misturado sob um único título. Sem alteração a rotas, validação, `email_verified_at`, `deactivated_at`, guardas ou lógica de subscrição — só layout e texto.
- **Nomes de botão mais precisos sobre o que cada ação afeta.** «Guardar dados» → «Guardar alterações»; «Impersonar (suporte)» → «Aceder como utilizador» (com explicação em tooltip); «Desativar conta» / «Reativar conta» → «Desativar utilizador» / «Reativar utilizador»; «Suspender» / «Reativar» da subscrição → «Suspender subscrição» / «Reativar subscrição». A distinção que já existia — desativar tira o acesso a uma pessoa, suspender tira o produto à organização — fica explícita nos próprios rótulos, não só no texto de apoio por baixo.

## [0.38.2] — 2026-08-20

### Fixed

- **O formulário de editar utilizador ganha um rótulo próprio e feedback ao guardar.** O código já existia desde a Fatia 0, mas os assets de produção em `public/build` tinham sido compilados antes desse commit — quem validasse em `http://lapis.test` via build estático via a versão anterior, sem nenhuma das alterações da Fatia 0. Corrigido reconstruindo os assets. Aproveitado para tornar o controlo explícito («Editar utilizador», acima dos campos, em vez de inputs soltos) e para o guardar deixar de ser mudo: passa a mostrar a mesma notificação de sucesso que criar ou apagar uma conta já mostram.

## [0.38.1] — 2026-08-20

### Fixed

- **A lista de contas não tinha botão para criar uma conta.** A rota `/admin/accounts/create` sempre existiu, mas só se lá chegava a escrever o URL à mão — a página nunca teve o link. `/admin` ganha "Nova conta".

## [0.38.0] — 2026-08-20

### Added

- **O backoffice passa a gerir contas, não só a criá-las.** Até aqui o Superadmin provisionava uma conta e ficava sem forma de a editar. `/admin/accounts/{organização}` ganha um formulário para corrigir nome e email — mudar o email repõe a verificação a zero, e a conta segue o fluxo normal a partir daí. Fica registado em auditoria (`admin.user_updated`).
- **Desativar uma conta é diferente de suspender uma subscrição, e agora ambos existem.** Suspender (já existia) tira o plano à organização inteira; desativar (novo) tira a uma pessoa a capacidade de entrar, sem tocar no plano — a distinção que passa a fazer sentido no dia em que uma organização tiver mais do que um membro. Nova coluna `users.deactivated_at`. O bloqueio corre por middleware (`EnsureUserIsActive`) e por `Fortify::authenticateUsing`, para apanhar as três portas de entrada: o formulário de login, uma sessão já aberta no momento da desativação, e passkeys/"lembrar-me". Um administrador não se consegue desativar a si próprio nem ao último admin da plataforma ainda ativo.
- **Remoção definitiva, excecional e protegida.** `DELETE /admin/accounts/{organização}` só avança numa organização pessoal, sem dados pedagógicos associados (turmas, alunos, inscrições, elementos de avaliação, registos, intervenções, relatórios, perfis de avaliação) e sem rasto de auditoria — o que, na prática, só acontece numa conta que nunca chegou a ser usada. Havendo qualquer coisa, recusa com a lista do que bloqueia; nunca apaga histórico para o delete passar. Uma organização institucional nunca é apagada por aqui.
- A lista de contas (`/admin`) e a ficha de cada uma passam a mostrar o estado de acesso da pessoa — separado do estado da subscrição, que já lá estava.

## [0.37.1] — 2026-08-20

### Fixed

- **As mensagens de erro das palavras-passe deixam de ser códigos.** Quem definia uma palavra-passe via «validation.password.mixed» ou «validation.password.uncompromised» — o identificador interno da mensagem, não a mensagem. Faltavam ao ficheiro de português **47 mensagens de validação**, entre elas todas as das regras de palavra-passe. Agora dizem o que corrigir: «A palavra-passe tem de conter pelo menos uma letra maiúscula e uma minúscula», ou «Esta palavra-passe já apareceu numa fuga de dados pública e não pode ser usada. Escolha outra — de preferência várias palavras sem relação entre si.»
- **Os requisitos aparecem antes de falhar, não depois.** As páginas de criar conta e de definir nova palavra-passe passam a listar o que é exigido — comprimento, maiúsculas e minúsculas, algarismo, símbolo — em vez de os revelar uma recusa de cada vez. A lista é gerada a partir das regras reais do servidor, por isso não pode divergir delas.
- **As sete páginas de autenticação passam a estar em português.** Entrar, criar conta, recuperar e definir palavra-passe, confirmar palavra-passe, autenticação em dois passos e verificação de e-mail estavam em inglês, como vieram do template original.

## [0.37.0] — 2026-08-20

Três módulos novos — Relatórios, Acompanhamento do Aluno e a área de ação
pedagógica reescrita — mais a reorganização da navegação que os passou a
arrumar. Nenhuma fórmula de avaliação mudou.

### Added

#### Relatórios

- **Um relatório passa a ser um objeto da aplicação, não um download.** É criado, editado, finalizado, exportado, listado e reutilizado. Um relatório derivado de outro herda o que já foi escrito e mantém a referência ao original.
- **Relatório de turma**, com as secções que descrevem os dados: quem é a turma, a leitura principal, a distribuição das classificações atribuídas, os domínios, a evolução, o diário de bordo, o que o professor declarou sobre a planificação, as intervenções registadas e um fecho. Cada frase nomeia a leitura de que fala — uma percentagem sem a sua base não é um facto.
- **Relatório individual**, construído a partir da mesma leitura da turma. O número do aluno e o da turma saem do mesmo cálculo, por isso «72,1%, acima da média da turma (66,4%)» são dois números do mesmo momento e não podem discordar entre si.
- **Relatório por registo de diário**, com a unidade nunca convertida. «10 verificações de trabalho de casa, realizadas em 8 registos» é uma afirmação sobre verificações; transformá-la numa percentagem de alunos seria dizer outra coisa. Cada contagem leva o seu denominador em texto.
- **Relatório institucional** (plano Institucional), que se recusa a juntar escalas incompatíveis. «1 a 5» e «0 a 20» não são um eixo comum: as classificações são agrupadas por escala, cada grupo tem o seu parágrafo e a sua taxa, e não há total nenhum na página. As «Notas de comparabilidade» dizem sempre o que não pôde ser comparado.
- **Camada pedagógica — perguntada, nunca inferida.** O LÁPIS guarda ocorrências disciplinares e registos de mérito; não guarda comportamento nem atitude. As secções que caracterizam comportamento, nomeiam dificuldades ou propõem medidas escrevem a partir das respostas do professor, e de nenhum dado.
- **Finalizar congela o documento.** Ao finalizar, texto, estrutura, números, timbre, âmbito temporal e as respostas do professor são copiados para dentro do relatório e selados. Uma nota corrigida em maio, um logótipo trocado ou o nome oficial do agrupamento reescrito não alteram o relatório assinado em fevereiro — reimprimi-lo não corre uma única consulta.
- **Exportação em PDF e Word**, as duas a partir da mesma estrutura final. Um rascunho sai carimbado RASCUNHO em ambos os formatos.
- **Modelos de relatório.** Um modelo guarda a organização de um documento — que secções entram, por que ordem, com que tom — e nunca o seu conteúdo. Há modelos do sistema, da escola e pessoais; um relatório pode começar a partir de um, e um rascunho pode ser guardado como modelo.
- **Reordenar as secções de um rascunho**, com o rato ou pelo teclado.
- **Biblioteca pedagógica** de dificuldades, estratégias e objetivos, que sugere sem preencher: o que o professor escolhe é copiado no momento da escolha, por isso reescrever uma entrada em setembro não muda o que um relatório de fevereiro diz.
- **Análise Pedagógica nos Relatórios** é uma capacidade nova dos planos Pro e Institucional. As secções descritivas continuam no plano Base — o que distingue os planos é o relatório poder *interpretar*.

#### Acompanhamento do Aluno

- **O ano de um aluno contado como uma história, não como um painel.** Onde está → como evoluiu → em quê → o que aconteceu, que é a ordem das perguntas que um professor faz. Quatro indicadores no topo e nem mais um, cada um a dizer a que leitura pertence.
- **Nada é recalculado.** A página lê os números canónicos que já existem, junta o percurso por período e as autoavaliações, e escolhe — não há motor de cálculo novo, logo não há forma de discordar do resto da aplicação.
- **A ficha do aluno mostra as suas intervenções** e permite abrir uma nova sobre o aluno que está a ser lido, sem procurar a turma.

#### Estratégias e Medidas

- **Uma intervenção passa a registar o raciocínio, não só a ação:** porquê, o quê, para quê, e o que se observou depois. Nada disto é obrigatório — registar algo pequeno continua tão rápido como era, e uma intervenção antiga mostra «sem objetivo registado» em vez de um objetivo inventado.
- **Acompanhar uma intervenção ao longo do tempo.** «+ Acompanhamento» acrescenta ao histórico sem editar a intervenção: cada entrada é datada e lida para a frente — iniciada, acompanhada, avaliada, concluída. Funciona no telemóvel.
- **«Rever em»**, a data do próprio professor, e a única coisa que torna uma intervenção pendente. Nenhuma regra a inventa a partir do tempo decorrido.
- **Dois estados novos:** «suspensa» (pausada, pode retomar) é diferente de «cancelada» (abandonada), e uma apreciação pode dizer «necessita de reformulação».

#### Apoio à redação (opcional, por configurar)

- **Um botão que oferece dizer melhor uma secção, e não escreve nada de novo.** Diz «Aperfeiçoar redação» e não «Gerar com IA», porque as frases foram escritas a partir de dados verificados antes de alguém clicar. Uma secção de cada vez, a proposta aparece ao lado do parágrafo que substituiria, e «Manter atual» é um botão a sério.
- **Sem fornecedor escolhido, deliberadamente.** O LÁPIS é instalado com esta camada desligada e sem nenhuma empresa nomeada: não há motor, endereço nem modelo por omissão. Enquanto não for configurada por quem administra a instalação, a funcionalidade anuncia-se como indisponível — e os Relatórios funcionam inteiramente sem ela. Os factos nunca vêm daqui: só a redação.

### Changed

- **O menu passou a estar ordenado pelo trabalho do professor** — organizar, avaliar, acompanhar, intervir, documentar — e não pela ordem em que foi construído. As áreas transversais ficam em baixo.
- **«Instrumentos» é agora «Elementos de Avaliação»** e **«Intervenções» é agora «Estratégias e Medidas»**: a segunda área é mais larga do que as medidas formais — abrange diferenciação, apoio ao estudo, apoio à interpretação de enunciados e estratégias de autorregulação. Os endereços não mudaram.
- **Cada entrada do menu diz para que serve**, em tooltip e no nome lido por um leitor de ecrã, antes de o professor clicar.
- **«Resultados» deixou de ser um sítio para onde se navega.** A pergunta é «como está esta turma?»: o seletor abre agora a leitura da turma, e a grelha operacional onde se decide uma classificação está a um clique dela. Nenhuma rota foi removida.
- **Os títulos das secções da barra lateral usam o amarelo da marca**, discreto e mais legível do que o cinzento que substituem.

### Fixed

- **Intervenções importadas antes de o módulo ter tipos deixam de imprimir o que a importação deixou para trás.** Um título «Legado sem dominio» e uma descrição «x» estão genuinamente guardados, mas numa lista leem-se como uma categoria e uma observação pedagógicas, que não são.
- **Um relatório só explica a diferença entre registos e alunos quando ela existe.** Dizer «8 registos sobre 8 alunos» é ruído; dizer «10 verificações em 8 registos» é informação.
- **A pré-visualização da identidade da escola deixa de ser espremida** para uma coluna estreita nas Definições.
- Os relatórios deixaram de fazer perguntas cujas respostas nunca chegariam a ser impressas.

## [0.36.0] — 2026-08-19

### Definições → Identidade da escola

- **A escola passa a poder identificar-se uma vez.** Nome oficial, nome curto, morada, código postal, localidade, telefone, email e website, mais os dados administrativos opcionais — código da escola, NIF, departamento, país e um texto de rodapé.
- **Logótipo com carregamento, substituição e remoção.** PNG, JPG ou WebP até 2 MB; o ficheiro fica em armazenamento privado e só é servido a quem pertence à organização. Substituir apaga o anterior.
- **Nada é obrigatório.** Uma escola que hoje só sabe o nome guarda o nome; um campo deixado em branco fica por preencher e não como texto vazio. O que é escrito é guardado tal como foi escrito.
- **Pré-visualização do cabeçalho.** Mostra exatamente o que um documento vai imprimir, sem linhas vazias quando faltam contactos, e diz quando ainda não há nada configurado.
- **Quem administra a organização altera; quem lá trabalha vê.** Uma escola nunca lê nem sobrepõe a identidade de outra.
- **Preparação para os documentos.** A identidade fica disponível num ponto único para que os relatórios e exportações futuros a recebam já pronta, em vez de cada um voltar a pedir estes dados. Esta versão não gera ainda nenhum documento.

## [0.35.0] — 2026-08-18

### Resultados → Estatística: uma visão geral de desempenho

- **A turma lida como um todo.** Uma página nova que agrega o que Resultados e o Quadro Síntese já mostram — médias, distribuição, domínios, evolução — sem recalcular nada: os números são os mesmos, apenas contados e agrupados.
- **Quatro indicadores no topo**: o resultado da turma, a taxa de sucesso, quantos alunos têm resultado e como evoluiu. Cada um diz sobre quantos alunos foi calculado, porque uma percentagem sem a sua base é um número que ninguém pode conferir.
- **Gráficos com linguagem própria.** A forma segue os dados — um momento é um número, dois são duas pontas, três ou mais ganham série — e o Chart.js só é carregado quando é mesmo preciso.
- **Onde a turma se espalha, e o mapa da turma.** O valor está sempre escrito; a cor só o reforça. Escolher um domínio ou uma classificação realça esses alunos em toda a página, sempre com forma de limpar a seleção.

### Avaliação contínua: qual é o resultado do momento

- **A leitura principal deixa de ser sempre a média isolada do período.** No primeiro momento do ano, a Média Ponderada do período é o resultado; a partir daí é a Média Ponderada Acumulada, que é o que traduz a avaliação contínua e aquilo que a classificação acompanha.
- **A regra vem do perfil, não do calendário.** É lida da configuração de continuidade de cada período, por isso funciona com semestres, trimestres ou períodos, e uma escola cujo segundo período não acumule vê a leitura ajustar-se sozinha.
- **A média isolada continua disponível** como leitura suplementar, com um comutador «Avaliação contínua / Só neste período» que muda os resultados apresentados — e nunca as classificações nem a taxa de sucesso.
- **Duas evoluções, com nomes diferentes.** «Evolução do desempenho» compara o trabalho de cada período; «Evolução na avaliação contínua» compara o resultado que respondia em cada momento. Um aluno pode cair vinte pontos como período e cinco como ano: as duas frases são verdadeiras e não se substituem uma à outra.

### A classificação atribuída é a fonte oficial

- **Taxa de sucesso, distribuição e mudanças de nível contam as classificações que o professor atribuiu** — não as menções em que as médias calhem cair. Uma média em «Bom» com nível 2 atribuído conta como negativa.
- **Uma proposta não é uma decisão.** Só uma classificação confirmada ou publicada conta; quem ainda não foi classificado fica à parte e fora dos denominadores, nunca como insucesso.
- **A distribuição mostra o valor da classificação.** «2», «3», «4» em destaque, com a menção da escala por baixo como referência. Numa escala numérica agrupa pelos valores efetivamente atribuídos, sem inventar intervalos.
- **A distribuição estatística das médias mantém-se**, agora recolhida e claramente identificada como leitura secundária.

### Quem progrediu, e quem mudou de nível

- **Duas leituras separadas.** Quantos alunos progrediram, mantiveram-se ou regrediram nos resultados calculados; e quantos passaram para um nível igual ou superior ao limiar da escala, ou abaixo dele.
- **A linguagem vem da escala.** «Passaram para nível igual ou superior a 3» em vez de «passaram a positivo» — e o número vem da configuração, não do código: uma escala 0–20 com o limiar em 10 diz 10.
- **Progredir e ser positivo são coisas diferentes**, e passam a ter visuais distintos: um aluno pode descer e continuar positivo, ou subir e continuar negativo.

### Avaliações intercalares

- **«Dados até»**: ver a turma como estava numa data, sem gravar nada.
- **Guardar esse momento como Avaliação intercalar** — uma fotografia imutável, com nome próprio, listada e reabrível.
- **Comparar a intercalar com o final do período**, aluno a aluno e domínio a domínio, na leitura que o período usa: acumulado contra acumulado quando há continuidade, com o desempenho isolado por baixo.
- **Exportar para INOVAR a partir da própria fotografia**, com os códigos que a escala tinha nessa data.
- **O passado não se reescreve.** Uma fotografia antiga que não registou determinada leitura diz «—» em vez de a ir buscar aos dados de hoje.

### Importação de turmas: a coluna SIT

- **Os cinco códigos passam a ser reconhecidos**: X (matriculado), TR (transferência), MT (mudou de turma), AM (anulou matrícula) e EF (excluído por faltas). Antes só TR era interpretado e os restantes entravam como matriculados.
- **A reimportação corrige o estado.** Um aluno que passa a MT deixa de constar da turma corrente; se voltar a X, regressa — sem duplicar matrícula nem aluno.
- **Um código desconhecido ou vazio não altera nada** e é assinalado na pré-visualização, que passa a mostrar «MT — Mudou de turma» e a indicar a mudança de estado.
- **Nada é apagado.** O aluno, a identidade, a matrícula e todo o histórico mantêm-se; a exclusão por faltas é um estado administrativo e não gera classificação, zero nem falta.

### Alunos da turma e alunos do histórico

- **Os ecrãs correntes mostram apenas quem está na turma hoje**; a página da turma passa a listar à parte, recolhidos, os alunos que já não a integram, com o motivo em palavras.
- **O histórico mantém-se intacto.** Resultados, classificações e avaliações intercalares dos períodos em que estiveram inscritos continuam a incluí-los.
- **Um registo novo de intervenção ou de evidência só nomeia alunos da turma atual**; editar um registo antigo continua a preservar os participantes que ele já tinha.

### Acessibilidade e linguagem

- **Nenhuma leitura depende da cor**: todos os ícones são decorativos e acompanhados de texto, os valores estão sempre escritos e os controlos anunciam o estado.
- **Animações respeitam `prefers-reduced-motion`** e todas as superfícies têm variante para modo escuro.
- **Percentagens e plurais em pt-PT**, e cada figura diz de que base foi calculada.

## [0.34.0] — 2026-08-16

### O ano inteiro numa página — Quadro Síntese

- **Uma turma lê-se ao longo do ano, não um período de cada vez.** O Quadro Síntese põe os períodos lado a lado, com os domínios em linha, para se ver o percurso de cada aluno de uma vez em vez de abrir três ecrãs e comparar de cabeça.
- **A evolução entre períodos aparece assinalada.** Subiu, manteve-se, desceu — indicado junto ao valor, sem transformar a tabela num semáforo.
- **Média Ponderada e resultado acumulado com nome próprio.** Cada número diz o que é. Uma média do período e um acumulado do ano são coisas diferentes e deixam de aparecer como se fossem a mesma.
- **Cada domínio tem a sua menção qualitativa.** Ao lado do valor, a menção que lhe corresponde na escala do perfil — sem limiares fixos escritos no código: quem os define é a escala da turma.
- **Domínios visualmente separados.** Cabeçalho discreto, corpo neutro e um separador ténue, para a tabela se ler por blocos em vez de ser uma parede de números.

### Classificações — a decisão é do professor

- **O Nível ou a Classificação atribuída edita-se onde o aluno se vê.** Em Resultados, sem navegar para outro sítio e sem perder o contexto de quem se está a avaliar.
- **Já não é preciso uma proposta prévia para decidir.** Um professor pode atribuir a classificação mesmo quando o sistema não tinha proposto nada — o sistema propõe, nunca decide.
- **Uma classificação confirmada continua editável até ser publicada.** Confirmar é decidir, não fechar. Depois de publicada fica bloqueada, e o LÁPIS explica porquê em vez de falhar em silêncio.
- **A decisão é registada na escala em que foi tomada**, e não na percentagem que a originou.

### Autoavaliação — o aluno na primeira pessoa

- **O formulário está organizado em três blocos:** «O meu desempenho», «A minha reflexão» e «Sobre o trabalho realizado».
- **Tudo é escrito na primeira pessoa.** É o aluno que fala sobre o seu trabalho, e o texto passou a soar assim.
- **«Nível» ou «Classificação», conforme a escala da turma** — a palavra vem da escala do perfil e não de um pressuposto sobre o ano de escolaridade.
- **A pergunta sobre o que melhorar sabe em que momento do ano está.** Fala do próximo período, do próximo semestre ou do fim do ano letivo consoante o calendário real da turma, em vez de assumir trimestres.
- **O aluno deixou de ver o valor calculado enquanto se autoavalia.** A autoavaliação é a leitura dele, não uma confirmação da do sistema.

### Turmas — Relação de Turma e dados administrativos

- **A importação da Relação de Turma (EB058e) guarda também o N.º de processo e a data de nascimento.** Reimportar preenche o que falta sem apagar o que já lá estava.
- **Nova secção «Dados administrativos» na página da turma**, para corrigir ou preencher à mão o N.º de processo de um aluno.

### Exportar para INOVAR

- **A grelha que o INOVAR dá é a grelha que o LÁPIS devolve.** O ficheiro `.xls` da escola é carregado, preenchido e devolvido — nada é gerado de raiz e mais nada no ficheiro é alterado.
- **Os alunos são identificados pelo N.º de processo, nunca pelo nome.** Dois alunos podem partilhar um nome, e uma nota escrita na pessoa errada não é um erro que alguém apanhe a ler.
- **As menções são escritas como F, I, S, B e MB**, a partir da correspondência declarada em cada nível da escala de sistema.
- **Uma pré-validação antes de gerar seja o que for:** que alunos e domínios foram correspondidos, quantas menções ficam prontas, e o que está a impedir o resto.
- **Uma célula sem menção fica por preencher.** Nunca é preenchida com a menção mais baixa.
- **Os resultados calculados com informação parcial são explicados por nome:** que aluno, que domínio, que elemento de avaliação, em que data, e o que ficou registado nesse elemento. Um vazio continua a não ser uma falta.
- **Disponível nos planos Pro e Institucional.** A funcionalidade é opcional: quem não a usa não passa a precisar de N.º de processo em lado nenhum.
- **A grelha carregada é apagada do servidor assim que o ficheiro preenchido é entregue**, e uma limpeza periódica remove o que ficar para trás.

### Correções

- Botão «Gerar ficheiro INOVAR» passou a descarregar mesmo o ficheiro.
- O seletor de ficheiro da grelha deixou de ser praticamente invisível.
- Concordância de singular e plural nos avisos de cobertura parcial.
- O sombreado de evolução no Quadro Síntese ficou circunscrito à célula.
- Os seeders de dados de referência deixaram de poder apagar níveis de escala que não criaram — uma instalação em uso pode correr as migrações e o seeder sem perder nada, e as escalas criadas por cada escola ficam intactas.

## [0.33.0] — 2026-08-15

### Importar resultados de outra plataforma — Intuitivo

- **As exportações do Intuitivo passam a poder ser lidas pelo LÁPIS.** A mesma entrada de Avaliações — «Importar resultados de outra plataforma» — aceita agora o ficheiro `.xlsx` do Intuitivo além da exportação do Plickers, com o mesmo percurso de quatro passos e a mesma regra de fundo: o LÁPIS mostra o que encontrou e só escreve quando o professor confirma. Disponível nos planos Pro e Institucional, como já estava.
- **Um teste com vários grupos é um instrumento, não vários.** Um enunciado dividido em GRUPO I a GRUPO IV continua a ser um único teste, com uma data e uma cotação. Criar quatro instrumentos separados seria descrever mal aquilo que o aluno fez.
- **Cada grupo pode contar para um domínio diferente.** É o que torna esta importação útil: o mesmo teste pode avaliar Leitura, Educação Literária, Gramática e Escrita, e cada bloco vai para o domínio a que pertence, em vez de o teste inteiro ir para um só.
- **O grupo do ficheiro não é o domínio.** O LÁPIS não deduz o domínio do nome do grupo — «GRUPO II» não diz nada sobre o que foi avaliado, e adivinhar a partir de um número romano seria inventar pedagogia. Nenhum domínio vem pré-selecionado, e quando a avaliação conta para a classificação cada grupo participante precisa de um escolhido pelo professor.

### Três níveis de detalhe

- **Por grupos** é o caminho normal do Intuitivo, e o que vem escolhido de origem: cada grupo dá um resultado, com a cotação que o próprio ficheiro declara.
- **Resultado global** importa apenas a classificação do teste. Continua a ser o caminho normal do Plickers, que não mudou.
- **Detalhe por pergunta** conserva a correção questão a questão. «Item 1» pode repetir-se em cada grupo sem se confundir: uma pergunta é identificada pelo grupo a que pertence, e não pelo número que tem dentro dele.
- **O detalhe de origem fica guardado mesmo quando não é importado como avaliação.** Importar por grupos escreve os resultados dos grupos, mas as perguntas e as suas pontuações continuam registadas na importação — servem para conferir contas, diagnosticar e auditar, sem passarem a avaliações que ninguém pediu.

### Contas que têm de bater certo

- **O que o ficheiro soma e o que o LÁPIS calcula são comparados e mostrados.** A soma das perguntas confere com a cotação do grupo, a soma dos grupos com o total do teste, e a soma das pontuações de cada aluno com o total que o ficheiro lhe atribui.
- **O total do ficheiro é conferência, nunca substituição.** Havendo divergência, o LÁPIS avisa em vez de corrigir por sua conta. Diferenças de arredondamento próprias do formato são toleradas; um ficheiro estruturalmente incompatível é recusado.
- **Um grupo só tem resultado quando é inteiramente conhecido.** Faltando a pontuação de uma pergunta desse grupo, o grupo fica sem resultado — somar as restantes daria um número mais baixo com aspeto de nota.

### O que os vazios continuam a não ser

- **Uma célula vazia não é um zero.** Também não é falta, falta justificada, dispensa nem «não aplicável». O que um vazio significa no Intuitivo ainda não está demonstrado, e atribuir-lhe um significado por conveniência seria decidir sobre a avaliação de um aluno sem fundamento. Fica por avaliar, à espera do professor.

### Ficheiros de origem — segurança e privacidade

- **Só é aceite o formato que foi efetivamente verificado.** Um ficheiro que não seja inequivocamente compatível é recusado com uma mensagem clara, em vez de ser interpretado por aproximação. Formatos com macros não são suportados.
- **O ficheiro é validado antes de ser aberto.** Tamanho, número de componentes internos e dimensão depois de descomprimido são verificados primeiro, o que impede que um ficheiro pequeno e malicioso se expanda até esgotar o servidor.
- **Nada dentro do ficheiro é executado.** Fórmulas não são calculadas, ligações a outros documentos não são seguidas e macros não são lidas. O que é importado é o que está escrito nas células.
- **As propriedades pessoais do documento não entram no LÁPIS.** O nome de quem criou ou modificou o ficheiro, e os caminhos da máquina onde foi gravado, não são guardados nem registados; da folha conserva-se apenas o necessário à importação.

## [0.32.1] — 2026-08-15

### Subscrições — uma de cada vez

- **Suspender uma conta passa a retirar mesmo o acesso.** Era o efeito visível do problema: uma organização podia ter mais do que uma subscrição em vigor ao mesmo tempo, e suspender a mais recente deixava a anterior voltar silenciosamente a vigorar. Na prática, «suspensa» podia significar «despromovida para o plano Base», enquanto o ecrã dizia que estava suspensa. A suspensão passa a abranger todas as subscrições em vigor.
- **Uma organização passa a ter, no máximo, uma subscrição em vigor num dado instante.** A regra vive num único sítio, por onde passam todas as mudanças de plano: a subscrição anterior é encerrada no mesmo instante em que a nova começa, sem intervalo e sem sobreposição.
- **Uma conta criada com plano Pro ou Institucional nasce já nesse plano.** Antes nascia em Base e recebia o plano escolhido por cima, no mesmo pedido — que era a origem do problema. Deixa de ser escrita uma subscrição para ser encerrada um instante depois.
- **O histórico é preservado.** Cada mudança de plano deixa a subscrição anterior no registo, com o plano, as datas e o estado que teve. Nenhuma linha é eliminada: o que passa a ser impossível é a sobreposição, não a existência de histórico.
- **Reativar retoma a mesma subscrição** que a suspensão pôs em pausa, e nunca uma que já tinha terminado.
- **Pedir o plano que já está em vigor não faz nada**, em vez de acumular subscrições idênticas.

### Reparação de dados

- **Um comando de operação repara as sobreposições que já existiam**, sem apagar nada: encerra as subscrições que tinham sido substituídas sem nunca terem sido encerradas, e mantém aquela que já era a efetiva — por isso a reparação não altera o plano de ninguém. Corre primeiro em simulação, e recusa-se a agir sobre qualquer caso que não reconheça, deixando-o intacto para decisão humana.

## [0.32.0] — 2026-08-15

### Importar resultados de outra plataforma — Plickers

- **Os resultados de um teste aplicado noutra plataforma podem agora entrar no LÁPIS sem ser copiados à mão.** A partir de Avaliações, «Importar resultados de outra plataforma» lê a exportação, mostra o que encontrou e só escreve quando o professor confirma. Disponível nos planos Pro e Institucional.
- **O caminho normal importa a classificação que a plataforma já calculou.** O Plickers dá um resultado por aluno — 55%, 85% — e é esse que é importado. Reconstruir as cotações de vinte perguntas para obter um número que já existe era trabalho sem produto, e produzia um número *diferente* assim que uma pergunta valesse mais do que outra.
- **O detalhe pergunta a pergunta é opcional**, numa caixa que ninguém tem de tocar. Só quem quiser conservar a correção questão a questão no LÁPIS é que define cotações, chaves de resposta e domínios por questão.
- **O professor escolhe o domínio avaliado.** O LÁPIS não o infere pelo texto das perguntas. Quando a avaliação conta para a classificação, o domínio é obrigatório — um resultado que conta para domínio nenhum é um resultado que não conta para nada, em silêncio.
- **O período é determinado pela data de aplicação** e continua visível e alterável. Uma data que não caia inequivocamente num período não inventa nenhum: pede que o professor escolha.
- **A correspondência entre os alunos do ficheiro e os da turma é conservadora por decisão.** Nome exato e nome normalizado associam; tudo o resto fica «Por associar» à espera do professor. Não há aproximação silenciosa, o número do cartão do Plickers nunca é lido como número de aluno, e o mesmo aluno não pode receber duas linhas do ficheiro. Trinta alunos por associar é preferível a um resultado no aluno errado.
- **Uma importação pode ser cancelada em qualquer passo.** Descarta o ficheiro temporário e a análise, e não toca em nada já avaliado.
- **Cada análise é uma importação nova**, com identificador, resumo e conteúdo próprios. Dois ficheiros com o mesmo nome e conteúdos diferentes deixam de se confundir — a identidade de uma importação vem do que o ficheiro diz, nunca de como se chama.

### O que os vazios continuam a não ser

- **Um aluno sem resultado na plataforma fica por avaliar.** Não recebe zero, não recebe falta, não recebe falta justificada e não recebe dispensa. «Não participou nesta aplicação» é um facto sobre a plataforma de origem, e uma ausência é um acontecimento que só o professor regista.
- **Um zero obtido continua a ser um zero.** A distinção entre «não tem resultado» e «teve zero» é a razão de ser de todo este fluxo.
- **O resultado original fica guardado.** «Resultado na plataforma: 85%» continua a poder ser consultado depois de o ficheiro ser eliminado.

### Correção — dizer o que falta

- **A grelha passa a dizer quem falta resolver, pelo nome**, em vez de recusar a conclusão com um botão cinzento. «Falta resolver 1 resultado antes de concluir a correção. Por avaliar: Marta Tomás.»
- **Uma célula por decidir identifica-se como «Por avaliar»**, na própria linha e no seletor de estado, em vez de um travessão que se confunde com uma célula vazia qualquer.
- **Guardar passa a confirmar que guardou.** Era a única ação da página que gravava sem dizer nada, o que era indistinguível de um botão que não funciona. Sem alterações pendentes, a página di-lo em vez de deixar o botão mudo.
- **Concluir a correção continua a ser um ato explícito do professor.** Guardar persiste trabalho; concluir declara-o terminado, e nada o faz automaticamente.

### Proteção de dados

- **Uma falha de gravação deixa de poder levar a exportação para os registos de erro.** A mensagem de uma exceção de base de dados interpola os valores gravados, e o valor aqui é o ficheiro inteiro — nomes e respostas de trinta alunos. O conteúdo é retirado no ponto da falha; o código de erro é preservado.
- **O ficheiro carregado vive em armazenamento privado e por pouco tempo**: apagado ao importar, apagado ao cancelar, e as sessões abandonadas são encerradas pelo comando de limpeza.
- **A proveniência guardada é minimizada.** Depois da importação, os nomes da plataforma de origem são descartados — a correspondência já diz quem é quem.

### Operação

- **Os dados de referência passam a ser sincronizados em todos os deploys**, não apenas no primeiro. Uma nova capacidade é dado de referência: alterar o seeder sem o executar deixa os planos existentes sem ela, que foi exatamente o que aconteceu com esta funcionalidade.

## [0.31.0] — 2026-08-15

### Instrumentos — grupos e secções

- **Um instrumento pode agora ter secções.** Um teste organiza-se em «Grupo I», «Grupo II», «Parte A» — e a grelha passa a mostrá-lo assim, em vez de uma lista contínua de questões. A estrutura é independente dos domínios: uma secção pode alimentar vários domínios e um domínio pode ser avaliado em várias secções.
- **A numeração das questões passa a ser por secção.** Um mesmo instrumento pode ter «1, 2, 3» no Grupo I e «1, 2, 3» no Grupo II, como acontece num enunciado real. Até aqui o código tinha de ser único em todo o instrumento, o que obrigava a numerações artificiais.
- **Um instrumento simples continua sem estrutura nenhuma.** Quem não precisa de secções não vê nenhuma: o grupo implícito não aparece no ecrã nem obriga a decidir nada.
- **Corrigida a edição estrutural.** Reordenar secções ou renumerar questões deixa de poder colidir com a numeração antiga a meio da gravação, e a edição do cabeçalho (título, data, tipo, peso) passa a estar coberta por testes de regressão.

### Resultados — proposta na escala do perfil

- **A Proposta deixa de ser a percentagem repetida.** Passa a ser o resultado lido na escala que a versão do perfil congelou — um 4, um 16, um 80%, um «Bom». O Resultado continua a ser o valor normalizado em percentagem: são duas colunas com dois significados.
- **As três famílias de escala têm tratamento próprio.** Escalas por níveis usam as bandas configuradas; escalas numéricas usam o intervalo da própria escala (`min + (normalizado/100) × (max − min)`), arredondado pela regra do perfil — genérico para 0–20, 1–20, 0–10 ou qualquer outro; escalas percentuais só são arredondadas. Nenhuma escala está escrita no código.
- **Uma escala sem bandas aprovadas diz que está por configurar**, em vez de mostrar um vazio silencioso. O LÁPIS não infere limiares: o nível é atribuído pelo professor.

### Correção — concluir e reabrir

- **Uma correção pode agora ser dada por concluída.** Até aqui um instrumento entrava em «Em correção» na primeira nota e ficava lá para sempre: uma correção terminada era indistinguível de uma abandonada, e a grelha continuava editável por descuido.
- **«Concluir correção» é recusada enquanto faltar decidir alguma célula aplicável**, e a recusa diz quantas faltam. Contam como decididas a ausência, a dispensa, o «não aplicável» e a anulação — são decisões que o professor já tomou. Só «por avaliar» e «em revisão» mantêm a correção aberta.
- **A barra de progresso e o botão não podem discordar.** Quem vê 6/6 consegue sempre concluir; quem vê 4/5 é sempre recusado — a regra passou a viver num único sítio, usado pelos dois.
- **Uma correção concluída fica em modo de consulta** e a gravação é recusada no servidor, não apenas escondida no ecrã. Fica registado quem concluiu e quando.
- **«Reabrir correção» volta atrás** e devolve as células a editáveis. Nem concluir nem reabrir mexem numa única classificação: concluir é uma afirmação sobre o trabalho do professor, não sobre os resultados dos alunos.

### Resultados — cobertura parcial

- **O aviso ⚠ passa a explicar-se.** Ao passar o rato — ou o foco do teclado — indica o instrumento e o dia: «Teste de Compreensão Leitora · 15/10/2026 — Ausência: 3 questões sem classificação.»
- **«Cobertura insuficiente» passou a «Cobertura parcial».** Se a cobertura fosse mesmo insuficiente, o LÁPIS não devia produzir resultado nenhum. O que acontece é que há resultado e ele assenta em parte dos elementos aplicáveis.
- **Quando não há resultado, o aviso diz outra coisa: «Sem elementos avaliados».** São dois fenómenos diferentes e deixam de partilhar a mesma frase.
- **Uma entrada tardia nunca é descrita como falta.** O aluno que entrou depois de o instrumento ter sido aplicado é excluído do cálculo pela mesma regra, mas não faltou a nada — e o aviso não o pode dizer.
- **As ocorrências são agrupadas por instrumento**, não por célula: quem faltou a um teste de três questões faltou a um teste. Uma questão repartida por dois domínios conta uma só vez no resultado global.
- O cálculo não mudou. Nenhum valor, arredondamento ou classificação é afetado por esta alteração.

## [0.30.0] — 2026-08-14

### Alunos

- **Os dados de um aluno já inscrito passam a ser editáveis.** Nome, número e data de entrada corrigem-se no lugar, a partir da própria turma. Até aqui um simples erro de escrita obrigava a eliminar o aluno e voltar a criá-lo — com tudo o que isso arrastava atrás. A ação «Editar» aparece na coluna Ações, antes de «Eliminar».
- **Um aluno «(sem identidade)» passa a poder receber nome.** Ao guardá-lo, a identidade cifrada é criada e a listagem passa imediatamente a mostrar o nome real. O campo abre vazio, nunca pré-preenchido com o texto marcador.
- **Corrigir a data de entrada corrige também o «ingresso tardio»**, nos dois sentidos: adiar a entrada marca-o, antecipá-la limpa-o. Não fica um aviso desatualizado a dizer o contrário do que a data diz.
- **A correção é sempre uma edição, nunca uma recriação.** O pseudónimo, o `Student` e o `Enrollment` mantêm-se — e com eles ficam intactos instrumentos, resultados, avaliações, registos, intervenções, autoavaliações, relatórios e fotografias. É a garantia de que corrigir um nome não desliga o histórico pedagógico do aluno a quem pertence.

### Fotografias

- **Gestão individual da fotografia**: adicionar, substituir e remover, aluno a aluno, a partir do mesmo diálogo de edição — sem outro ícone na tabela. Até aqui a única via era a importação em lote do ficheiro do Inovar.
- **O aluno que entra a meio do ano deixa de ficar sem fotografia.** Inscreve-se à mão, edita-se, associa-se a fotografia — sem gerar nem importar de novo o ficheiro completo da turma.
- **Uma só implementação de armazenamento**, em `StudentPhotoService`: a importação em lote e a gestão individual passaram a partilhar o mesmo disco privado, o mesmo esquema de nomes e a mesma limpeza. A importação do Inovar continua a funcionar exatamente como antes.
- **Substituir uma fotografia deixa de acumular ficheiros órfãos**, e uma falha a meio deixa de os criar: se a nova referência não chegar a ser gravada, o ficheiro acabado de escrever é removido e a fotografia anterior fica intacta; se uma inscrição falhar durante a importação, a fotografia já escrita para essa linha é limpa. Ao remover, a referência é apagada primeiro — uma limpeza física falhada regista um aviso e nunca faz a aplicação voltar a apontar para um ficheiro que o professor mandou remover.
- **A fotografia continua opcional e continua privada.** Nada é bloqueado por não existir; o ficheiro nunca sai do armazenamento privado, é servido apenas pela rota autorizada, e a validação lê o conteúdo do ficheiro em vez de confiar na extensão.

### Miniaturas

- **Uma miniatura do aluno junto ao nome**, para o professor ligar depressa nome a rosto — sobretudo no início do ano ou numa turma nova: na lista da turma, na grelha de correção, nas classificações, nos resultados e no resumo de avaliação. É ajuda visual e nada mais: não entra em relatórios, registos, intervenções nem em qualquer conteúdo que atravesse a fronteira da IA.
- **Quem não tem fotografia mostra um marcador do mesmo tamanho**, para que a ausência nunca desalinhe as colunas — e uma imagem que falhe a carregar cai para esse mesmo marcador, em vez de deixar uma imagem partida.
- **A fotografia adapta-se à densidade da grelha, não o contrário**: 34 px na lista da turma, 24 px nas grelhas, sem aumentar o espaçamento vertical das linhas. Na grelha de correção, onde a coluna do aluno compete com as colunas de lançamento, a miniatura recolhe em ecrãs estreitos. O nome nunca é escondido em favor da fotografia.

## [0.29.1] — 2026-08-14

### Documentação

- **A solução registada para a armadilha 6 estava errada, e o deploy da 0.29.0 provou-o.** Criar um SSH user dedicado (`lapis-deploy`) não impede o CloudPanel de reescrever a `authorized_keys`: fê-lo na mesma, com as mesmas três chaves de terceiros, 24 segundos depois de a nossa chave ter funcionado. O nome partilhado nunca foi a causa. A solução que resulta é `~/.ssh/authorized_keys2`, que o `sshd` lê e o painel não gere. Fica também registado que continua por esclarecer porque é que chaves de terceiros são injetadas num utilizador deste site — pertencem ao grupo `lapis`, logo leem o `.env`.
- **Armadilha nova: o utilizador de deploy tem de ser dono do código.** Os diretórios pertenciam ao antigo user `deploy` a `750`, e o `lapis-deploy` — no grupo, mas não dono — não conseguiu escrever: 845 ficheiros recusados, `config/app.php` na versão anterior, e o script a dar a extração por concluída na mesma. Fica documentado o `chown` correto (código para quem faz deploy, `storage` e `bootstrap/cache` para o utilizador web com escrita de grupo) e a razão de nunca se usar `chmod 777` — o problema é de propriedade, e `777` mascara-o dando escrita a outras equipas do VPS.
- **Verificar a extração por checksum, não pela versão.** O `tar` falha ficheiro a ficheiro e o `|| true` engole o erro; comparar `config/app.php`, `composer.lock` e `manifest.json` entre local e servidor é o que distingue um deploy real de um que não escreveu nada. Acrescentado à checklist, com o teste de propriedade e escrita a correr **antes** de entrar em manutenção.
- **`.agents` e `.superpowers` acrescentados às exclusões do pacote.** Estão no `.gitignore`, mas o `tar` não o lê — foi assim que 6 MB de skills e fontes TTF foram parar a produção. Documentado também que o servidor tem Node 12, demasiado antigo para o Vite: os assets são sempre compilados localmente.

## [0.29.0] — 2026-08-14

### Intervenções

- **Módulo reconcebido.** Deixou de ser «uma medida de apoio, para um aluno, com duração» e passou a «uma ação pedagógica intencional», que pode ser pontual ou continuada. O registo rápido é o essencial: escolher quem, escolher o tipo, guardar — sem título obrigatório, sem descrição obrigatória (salvo no tipo «Outro»), sem estado nem duração a preencher.
- **Aluno, grupo ou turma.** Uma intervenção pode dirigir-se a um aluno, a um grupo de dois ou mais, ou à turma inteira. Uma intervenção de turma não nomeia alunos de propósito — a turma é o destinatário — mas continua a aparecer ao filtrar por qualquer aluno dela.
- **Catálogo de 29 tipos**, agrupados por contexto (aprendizagem, avaliação, métodos de estudo e autonomia, atenção e autorregulação, comportamento, integração). O contexto é sempre deduzido do tipo, nunca escolhido pelo professor.
- **Domínio disciplinar opcional**, com três leituras distintas: sem domínio específico, um domínio, ou todos os domínios. Só domínios reais da disciplina — «avaliação» ou «comportamento» são contextos, não domínios.
- **Enquadramento pedagógico/legal opcional**, recolhido por defeito. A maioria das intervenções é prática corrente e não precisa dele. Quando o tipo corresponde inequivocamente a uma medida formal, o enquadramento é preenchido e pode ser alterado ou removido; quando apenas se aproxima de uma, é **sugerido** e só fica registado se o professor confirmar — uma sugestão por confirmar não é guardada de todo.
- **Medidas de suporte e adaptações no processo de avaliação são coisas distintas.** Dar tempo suplementar a um aluno regista a adaptação e deixa o nível da medida por especificar: usar uma adaptação nunca significa, por si só, que o aluno está abrangido por medidas universais, seletivas ou adicionais. As duas podem coexistir quando o professor assim o decidir.
- **Monitorização preservada.** Estado, data de fim prevista, conclusão e as apreciações periódicas de eficácia continuam exatamente como estavam, como ações separadas do registo.
- **«Disponível para relatórios»**, ativo por defeito: marca elegibilidade para uso futuro pelo módulo de Relatórios, que não é alterado nesta versão — nada é copiado nem gerado automaticamente.
- **Listagem com filtros** por aluno, tipo, contexto, domínio, período, disponibilidade para relatórios e nível de medida.

### Preparação multijurisdição

- **A intervenção é pedagógica e global; o enquadramento legal é jurisdicional.** «Apoio à organização da escrita» significa o mesmo em qualquer país; o que varia é se alguma legislação o enquadra. O catálogo de tipos deixou de conter qualquer conhecimento jurídico.
- **Portugal passou a ser uma implementação isolada**, com toda a sua terminologia — níveis de medida, medidas e adaptações — fechada no seu próprio enquadramento jurídico.
- **Jurisdição por organização**, com um fallback de compatibilidade para Portugal enquanto uma organização não tiver a sua definida. Uma jurisdição indicada explicitamente para a qual não exista enquadramento **não** herda o português: fica simplesmente sem enquadramento legal.
- **Funciona plenamente sem enquadramento legal.** Uma escola numa jurisdição ainda não suportada regista intervenções normalmente, com tipos, destinatários, domínios, datas, estado, eficácia e filtros — apenas sem a camada jurídica, e sem lhe ser mostrada a taxonomia de outro país.
- **A língua da interface nunca decide qual a lei aplicável.** Uma escola portuguesa pode trabalhar em inglês, e uma escola estrangeira em português.
- **Alterações legislativas futuras não reclassificam o passado.** O enquadramento é resolvido pela data da própria intervenção, e o que ficou decidido é guardado no momento e nunca recalculado — abrir ou editar uma intervenção antiga não a reinterpreta à luz de legislação posterior.

## [0.28.1] — 2026-08-13

### Documentação

- **Duas armadilhas novas no deploy, apanhadas durante o deploy da 0.28.0.** O SSH user `deploy` não é exclusivo deste site — é um nome genérico partilhado com outros sites do VPS, e a sua `authorized_keys` é reescrita por fora, apagando a chave que o painel do LAPIS diz ter guardado. Fica documentado o sintoma enganador (permissões e `sshd -T` aparecem corretos), o sinal fiável (o tamanho do ficheiro: ~90 bytes por chave) e a solução (criar um SSH user com nome único em vez de reutilizar o `deploy`). A segunda: tentativas repetidas fazem o `fail2ban` banir o IP, e `Connection timed out` significa ban — não chave recusada.
- **Estado de produção atualizado.** A produção estava na 0.25.0, não na 0.27.0 — o deploy da 0.28.0 apanhou três versões de uma vez. Fica o aviso para confirmar sempre a versão real no servidor antes de assumir de onde parte um deploy, já que o servidor não tem `.git` e nada indica de fora que commit lá está.

## [0.28.0] — 2026-08-13

### Avaliações

- **Novo módulo Avaliações.** Vista orientada ao fluxo do professor sobre os instrumentos já criados — "o que está a acontecer", não "o que construí". Lista com filtros por Estado, Finalidade e Período, estado derivado por avaliação e progresso sempre contado por alunos, nunca por células (ex.: "12/20", nunca uma contagem de células do tipo "117/200").
- **Página operacional da avaliação.** Resumo com alunos aplicáveis, concluídos, por corrigir, faltas e em revisão; estado individual por aluno (Corrigida, Por corrigir, Faltou, Em revisão, Situação especial, entre outros). Alunos fora do período de matrícula ficam sempre destacados à parte, nunca misturados com os restantes nem a contar para o progresso.
- **Reutilização total da grelha de correção e do fluxo de Instrumentos.** Corrigir a partir de Avaliações abre a mesma grelha já existente — sem nova rota de notas, sem novo sistema de correção. Navegação Avaliações → correção → "Voltar a Avaliações" quando a origem é o novo módulo; o fluxo antigo mantém "Voltar à turma".
- **Criar avaliação reutiliza o fluxo existente de Instrumentos.** "Nova avaliação" pede a turma (não há turma ambiente numa lista que cruza turmas) e abre o formulário de Instrumentos já existente, sem segundo formulário. O período vem pré-preenchido quando o filtro ativo pertence ao ano letivo da turma escolhida; caso contrário, o professor escolhe normalmente.
- **Campo "Finalidade" no formulário de instrumento.** Diagnóstica / Formativa / Sumativa / Outra — antes só existia como valor interno, sem forma de o professor o escolher.
- **Avaliações diagnósticas com "Contabiliza para classificação" a Não por defeito.** Só quando o professor não decide explicitamente o contrário — uma escolha explícita, em qualquer sentido, é sempre respeitada e nunca sobreposta automaticamente, incluindo ao editar uma avaliação diagnóstica já existente.

### Grelha de correção

- **Apreciação qualitativa por domínio.** Junto ao total já existente, cada domínio do instrumento passa a mostrar pontos, percentagem e menção qualitativa própria — agregada a partir da contribuição de cada questão alocada ao domínio, nunca a pontuação isolada de uma única questão.
- **Badges cromáticos consistentes por banda qualitativa**, reutilizáveis fora da grelha — a cor deriva da posição estrutural da banda na escala, nunca do texto do rótulo, para não se perder com escalas personalizadas ou traduzidas.
- **Resultados parciais claramente distintos dos definitivos.** Um domínio com questões ainda por corrigir nunca mostra uma menção qualitativa como se fosse a palavra final.
- **Mesma escala e mesma lógica qualitativa em todo o lado.** Nenhum limiar novo — a apreciação por domínio usa exatamente as mesmas scale bands e a mesma função que já geravam a apreciação global.

## [0.27.0] — 2026-08-13

### Adicionado

- **Ligação de autoavaliação por aluno, sem login.** Em Autoavaliações → turma → período, "Ligações para os alunos" gera uma ligação assinada e temporária (7 dias) por aluno — sem conta nem password — para o aluno preencher a sua própria autoavaliação no próprio dispositivo. Fica registado como preenchido pelo aluno, distinto de quando o professor preenche em entrevista. Disponível apenas nos planos Pro e Institucional (módulo novo `self_assessment_links`), não no plano Base.

## [0.26.0] — 2026-08-12

### Adicionado

- **Módulo Registos concluído.** Os 10 tipos de registo (Trabalho de casa, Participação, Progresso, Dificuldade, Ocorrência disciplinar, Comportamento meritório, Apoio, Contacto, Atividade, Observação) passam a ter campos condicionais próprios — Situação, Participação observada, Domínio relacionado (opcional, só Progresso/Dificuldade), Gravidade, Avaliação global + Incluir no relatório — com dicas curtas e placeholders por tipo. O agrupamento em Aprendizagem/Comportamento e atitudes/Acompanhamento é sempre calculado a partir do tipo (`EvidenceKind::group()`), nunca escolhido pelo professor nem gravado em coluna própria. Editar e eliminar (com confirmação) ficam disponíveis na listagem, com filtros por aluno, tipo e período. Um aviso discreto lembra que os registos não alteram a classificação; uma síntese neutra por tipo resume o que está listado, sem juízos de valor.
- **Registar vários alunos de uma vez.** Ao criar um registo, o campo Aluno passa a alternar entre "Turma inteira" e uma grelha de checkboxes com "Selecionar todos"/"Limpar seleção" — sem ter de percorrer um dropdown aluno a aluno. Cada aluno selecionado fica com o seu próprio registo independente (editável e eliminável à parte), não um registo partilhado.
- **Preparação de dados para relatórios (interface por fazer).** Um registo de Atividade pode ser marcado "Incluir no relatório" — sinalização por registo, distinta de `classes.include_evidence_in_report`/`enrollments.include_evidence_in_report` (essas continuam a decidir se o livro de registos aparece no relatório). Ficam prontos os scopes reutilizáveis (`forClass`, `forEnrollmentOrWholeClass`, `inPeriod`, `inGroup`, `autoSelectableForReport`) que uma futura interface de Relatórios vai usar — esta entrega não altera `ReportsController` nem `resources/js/pages/reports/*`.

### Corrigido

- **Dois testes de Registos davam falso positivo.** Ficaram desatualizados quando `participation_level`/`activity_evaluation` passaram a obrigatórios nos respetivos tipos: o pedido falhava a validação e voltava para trás, e `assertRedirect()` não distinguia isso de um sucesso — o registo nunca chegava a ser criado.
- **Descrição opcional (Trabalho de casa/Participação/Ocorrência disciplinar) podia rebentar a inserção.** A coluna `description` é `NOT NULL` na base de dados; omitir o campo enviava `NULL` para a queda. Passa a gravar `''` quando não preenchida.
- **Erro de tipos no formulário de Registos.** Um tipo local `Record` (a forma de um registo na listagem) tapava o genérico nativo `Record<K, V>` do TypeScript, usado no dicionário de metadados por tipo — `vue-tsc` nunca tinha corrido sobre este ficheiro. Renomeado para `EvidenceRecordRow`.

## [0.25.0] — 2026-08-01

### Alterado

- **Cotação por domínio passa a ser em pontos, não em percentagem.** Ao criar/editar um instrumento, escolhem-se primeiro os domínios avaliados; ao criar cada questão, a cotação de cada domínio insere-se diretamente em pontos (uma questão pode tocar mais do que um domínio). A cotação total da questão é só de leitura, somada automaticamente. Um painel-resumo no final do formulário mostra o total por domínio, atribuindo a cada um só a parte da questão que lhe foi cotada — nunca a questão inteira duplicada nos vários domínios que toca. A base de dados e o motor de cálculo continuam em percentagem por dentro; a conversão é só de apresentação.

### Corrigido

- **O seletor de domínio de cada questão mostrava todos os domínios da turma**, não só os escolhidos no topo do formulário para aquele instrumento.

## [0.24.0] — 2026-08-01

### Adicionado

- **Grau de gravidade nas ocorrências disciplinares.** Ao escolher "Ocorrência disciplinar" nos Registos, aparece um campo obrigatório antes da Descrição com 5 graus: Advertência/Falta de material (G2), Perturbação ligeira da aula (G3), Indisciplina/Falta de respeito (G4), Infração grave (G5), Infração muito grave (G6).
- **"Incluir dados que constam nos Registos do professor" passa de Registos para Relatórios.** A checkbox por registo individual nunca teve efeito nenhum (a pauta nunca leu essa flag). Substituída por uma definição ao nível da turma (omissão) com exceção por aluno, ambas geridas na página do relatório da turma.

### Alterado

- **Três designações de tipo de registo:** "Comportamento positivo" → "Comportamento meritório (G1)"; "Nota" → "Observação"; "Ocorrência" → "Ocorrência disciplinar".

### Corrigido

- **Ineficiência no formulário de instrumento**: a lista de questões por domínio recalculava-se a cada tecla premida em qualquer campo, em vez de só quando a lista de questões muda.

## [0.23.0] — 2026-07-31

### Adicionado

- **Opção "Outro…" no tipo de instrumento, com designação livre.** Ao criar ou editar um instrumento, escolher "Outro…" no campo "Tipo" mostra um campo de texto para o professor escrever a sua própria designação. O `InstrumentType` já estava desenhado para isto (linhas próprias por organização, "o professor pode adicionar as suas") — só faltava a interface. Reutilizar a mesma designação depois não cria um tipo duplicado; volta a usar o mesmo.

## [0.22.6] — 2026-07-31

### Corrigido

- **O dropdown "Tipo" ao criar um instrumento aparecia sem nenhuma opção em produção.** `instrument_types` estava vazio — o deploy inicial (2026-07-27) só correu o `EntitlementsSeeder`, não os outros dois seeders de referência obrigatórios (`SystemScalesSeeder`, `InstrumentTypesSeeder`) que `DatabaseSeeder`/`ReferenceDataSeeder` sempre correm juntos. Corrigido a correr `InstrumentTypesSeeder` diretamente em produção (idempotente); `docs/deployment.md` passa a apontar para `ReferenceDataSeeder` (os três) em vez de só o `EntitlementsSeeder`, para não repetir a lacuna num futuro deploy de raiz.

## [0.22.5] — 2026-07-31

### Corrigido

- **"Associar fotos" dava erro 500 em produção (`UnableToCreateDirectory`).** Efeito colateral da limpeza dos ficheiros de teste poluídos (0.22.3): as pastas `storage/app/private/roster-imports` e `student-photos`, ao serem geridas pelo user `deploy` por SSH, ficaram sem permissão de escrita para o grupo (`750`), e o site corre como `lapis` — só partilha o grupo com `deploy`, não é dono. Corrigido diretamente no servidor (`chmod g+rwX`) e documentado em `docs/deployment.md` como uma nova armadilha a evitar da próxima vez que se mexer nestas pastas por SSH.

### Adicionado

- **Nota explicativa sobre os modelos Intuitivo a usar na ficha da turma.** Junto aos botões "Importar lista"/"Adicionar fotos" (e nas descrições dos respetivos diálogos, e no passo de fotos da pré-visualização da importação), indica-se agora qual o modelo esperado: EB058 (Excel) para a lista de alunos, EB019 (Word) para as fotos.

## [0.22.3] — 2026-07-31

### Corrigido

- **Ficheiros de fotos exportados num formato Word "normal" (imagens inline modernas + nomes em texto simples numa tabela) extraíam sempre 0 fotos, silenciosamente.** `PhotoFileParser` só reconhecia o formato específico do export "Intuitivo" (imagens VML + legendas em `w:altChunk`). Confirmado diretamente com um ficheiro real do utilizador: agora reconhece também o formato de tabela (linha de fotos + linha de nomes separada, imagens via `<w:drawing>`/`<a:blip>`), como alternativa quando o formato original não encontra nada.
- **A resolução do caminho das imagens dentro do ficheiro assumia sempre um caminho absoluto ("/media/imagem.jpg"), mas um documento Word "normal" usa caminhos relativos à pasta `word/` ("media/imagem.jpg").** Confirmado que isto fazia com que as imagens do segundo formato nunca fossem encontradas mesmo depois de reconhecidas — corrigido para resolver cada convenção corretamente (regra OOXML: `/` no início = raiz do ficheiro; sem `/` = relativo a `word/`).

## [0.22.2] — 2026-07-31

### Corrigido

- **Mensagens de sucesso como "N foto(s) associada(s)." ou "Instrumento anulado." nunca apareciam — eram guardadas com `->with('status', ...)`, mas o sistema de toasts só reage a `Inertia::flash('toast', [...])` (o padrão já usado por outros controllers, ex. `EvidenceController`, `InterventionController`).** Sem feedback visível, uma associação de fotos que resultasse em "0 associadas" parecia exatamente igual a uma que tivesse funcionado — impossível distinguir uma falha silenciosa de sucesso. `ClassPhotoImportController`, `RosterImportController` e `InstrumentController` (anular/reverter) passam a usar `Inertia::flash('toast', ...)`, tal como os restantes.

## [0.22.1] — 2026-07-31

### Corrigido

- **Na pré-visualização da importação de turma, era possível confirmar a importação sem as fotos escolhidas chegarem a ser associadas.** "Adicionar fotos" exige dois passos — escolher o ficheiro Word e depois clicar em "Adicionar fotos" — mas nada impedia avançar diretamente para "Confirmar importação" depois de só escolher o ficheiro, saltando o segundo clique sem qualquer aviso. Os logs do servidor confirmaram exatamente isto: nenhum pedido ao passo de anexar fotos chegou a ser feito. `Preview.vue` passa agora a mostrar um aviso e a bloquear "Confirmar importação" enquanto houver um ficheiro de fotos escolhido e ainda não submetido.
- **Um deploy anterior enviou por engano o `storage/app` local (fotos de teste e pastas temporárias de importação de dias anteriores) para produção**, poluindo `storage/app/private/student-photos` e `roster-imports` com ficheiros que nenhum aluno real referenciava. Ficheiros órfãos removidos do servidor (confirmado, zero referências na base de dados de produção); o comando de empacotamento em `docs/deployment.md` passa a excluir sempre `storage/app` — conteúdo carregado é específico de cada ambiente e nunca deve viajar num pacote de código.

## [0.22.0] — 2026-07-31

### Adicionado

- **Rodapé com a versão da aplicação e uma página "Novidades".** Todas as páginas da área do professor passam a mostrar, no rodapé, a versão atual (`v{versão}`) e um link "Novidades" que abre `/novidades` — uma página com o histórico completo de alterações, lido diretamente do `CHANGELOG.md` do repositório (versão, data, categoria e descrição de cada alteração). `App\Support\Changelog\ChangelogParser` faz o parsing do ficheiro; a página não depende de organização/tenant, porque o changelog é igual para todos os professores.

## [0.21.19] — 2026-07-31

### Corrigido

- **"Adicionar fotos" na ficha da turma parecia não fazer nada: a caixa de upload ficava aberta e as fotos não apareciam.** `submitPhotos()` nunca fechava o diálogo depois de submeter — ao contrário de `enroll()` (mesma página) e do fluxo de anulação de instrumentos, que já fecham o seu próprio diálogo em `onSuccess`. Como o redireccionamento do upload volta para a mesma página (`classes.show`), o Inertia reaproveita a instância do componente em vez de a recriar, por isso o estado local do diálogo nunca era reposto sozinho. `photoDialogOpen.value = false` no `onSuccess` resolve — as fotos já associadas corretamente ficavam sempre por trás do diálogo aberto.

## [0.21.18] — 2026-07-31

### Corrigido

- **O recurso ao pseudónimo como alternativa (0.21.17) mostrava-o também na coluna "Nome", indistinguível de um nome real.** Como o pseudónimo já tem a sua própria coluna dedicada onde faz sentido, repeti-lo na coluna do nome escondia o problema em vez de o sinalizar. Todos os mesmos pontos passam a mostrar `(sem identidade)` quando a identidade não existe, deixando claro que é um dado em falta e não um nome válido.

## [0.21.17] — 2026-07-31

### Corrigido

- **Um aluno sem registo de identidade (nome/foto) deixava de conseguir abrir a ficha da turma, relatórios, resultados, avaliação de intervenções, evidências, autoavaliação e a grelha do instrumento — erro 500 "Attempt to read property display_name on null".** Todos os pontos que liam `$student->identity->display_name`/`photo_path` assumiam que a identidade existe sempre; no ambiente local, a tabela `student_identities` acabou vazia (dados perdidos localmente, não em produção). Estes pontos passam agora a usar `optional($student->identity)->display_name`, com o pseudónimo do aluno como alternativa quando a identidade não existe — a app deixa de rebentar mesmo com dados incompletos, mostrando o pseudónimo até a identidade ser restaurada (ex.: reimportando a turma).

## [0.21.16] — 2026-07-30

### Alterado

- **Formulário de instrumento reorganizado por domínio (Tarefa 1).** `InstrumentForm.vue` (partilhado por `Create.vue` e `Edit.vue`) deixa de mostrar uma lista única e plana de questões. O professor escolhe primeiro, em "Domínios avaliados", quais os domínios que o instrumento cobre; cada domínio marcado ganha a sua própria secção com "+ Adicionar questão", e uma questão criada aí já nasce alocada a 100% a esse domínio. A secção "Sem domínio associado" mantém-se para perguntas sem alocação, sem alterações de comportamento. O editor de alocações por questão foi extraído para `InstrumentDomainAllocations.vue`, reutilizado nas duas secções, para que uma pergunta continue a poder repartir-se por vários domínios manualmente. Ao abrir um instrumento existente em `Edit.vue`, os domínios já usados pelas suas perguntas ficam pré-marcados, para que nenhuma pergunta desapareça de vista até o professor mexer nas caixas. Nenhuma alteração de backend, esquema, rotas ou validação.

## [0.21.15] — 2026-07-30

### Corrigido

- **Importar a estrutura de outro instrumento podia copiar alocações de domínio que a turma de destino não avalia.** A revisão final do plano de importação encontrou que a mesma disciplina (`subject_id`) não garante o mesmo conjunto de domínios: duas turmas podem partilhar a disciplina mas ter versões de perfil diferentes (ano/turma distintos), cada uma com os seus próprios domínios. `InstrumentController::importableInstrumentsFor()` passa agora a filtrar as alocações de domínio de cada pergunta pelos domínios que a turma de destino realmente avalia (`InstrumentBuilder::domainsFor()`) — uma alocação para um domínio que a turma de destino não segue é descartada em vez de copiada, evitando dados de domínio inconsistentes.

## [0.21.14] — 2026-07-30

### Adicionado

- **Importar a estrutura de outro instrumento ao criar um novo (Tarefa 2/2).** O formulário de "Novo instrumento" ganha um seletor "Importar de outro instrumento", com os candidatos que a Tarefa 1 já filtra (turmas do próprio professor, mesma disciplina). Ao escolher um e clicar em "Importar questões", copia-se o título, a cotação total, o indicador de bónus e todas as perguntas (com as suas alocações de domínio) para o formulário — tudo continua totalmente editável depois, e nada além da estrutura é copiado (nunca notas de alunos). Fecha o plano de importação de estrutura de instrumentos.

## [0.21.13] — 2026-07-30

### Adicionado

- **Backend para importar a estrutura de um instrumento como ponto de partida (Tarefa 1/2).** `InstrumentController::create()` passa a enviar uma nova prop Inertia `importableInstruments` — os instrumentos das próprias turmas do professor autenticado, filtrados à mesma disciplina da turma alvo (nunca a de um colega, nunca de outra disciplina, onde as alocações de domínio não fariam sentido). Só estrutura viaja: código, enunciado, pontuação possível, indicador de bónus e alocações de domínio por pergunta — nunca notas de alunos. A Tarefa 2 (frontend) consome esta prop em `instruments/Create.vue` para oferecer "importar de um instrumento existente".

## [0.21.12] — 2026-07-30

### Corrigido

- **`PUT /instruments/{instrument}` aceitava `status: 'cancelled'` fora do fluxo dedicado de anulação.** A validação de `InstrumentRequest` ainda listava `cancelled` como um valor de estado aceite pela edição genérica — encontrado na revisão final do plano de edição de instrumentos. Isto contornava por completo `cancel()` (Tarefa 4/6): nenhum motivo era exigido, e `cancelled_at`/`cancelled_by`/`status_before_cancellation` ficavam todos por preencher. Um instrumento assim "anulado" tornava-se ilegível para "Reverter anulação" — que tentaria repor `status_before_cancellation` (`null`) na coluna `status`, não anulável. Só `InstrumentController::cancel()`/`revertCancellation()` devem produzir ou consumir este valor; a lista de estados aceites pela edição genérica deixou de incluir `cancelled`.

## [0.21.11] — 2026-07-30

### Adicionado

- **Interface de edição/anulação de instrumentos em `Grid.vue` (Tarefa 6/6).** A grelha ganha o link "Editar instrumento" e o botão "Anular instrumento" (abre um diálogo com motivo obrigatório, `POST .../cancel`). Enquanto anulado, a grelha mostra uma faixa com o motivo e o botão "Reverter anulação" (`POST .../revert-cancellation`), e todas as caixas de nota e seletores de estado ficam desativados — o link de editar, o botão de anular e o botão Guardar desaparecem por completo, já que o backend recusa estas ações com 403 enquanto o instrumento está anulado (Tarefas 3/4). Fecha o plano de edição de instrumentos iniciado na Tarefa 1.

## [0.21.10] — 2026-07-30

### Adicionado

- **Formulário de edição de instrumentos (Tarefa 5/6).** A lógica de `instruments/Create.vue` foi extraída para um novo `InstrumentForm.vue` partilhado (mesmo padrão já usado em `assessment-profiles/ProfileForm.vue`), com `Create.vue` a passar a ser um invólucro fino sobre ele. O placeholder mínimo de `instruments/Edit.vue` (Tarefa 3) é substituído pelo formulário real, pré-preenchido com os dados atuais do instrumento. Uma questão que já tem notas lançadas (`has_scores`, enviado pelo backend desde a Tarefa 3) tem agora o botão de remover desativado, com um tooltip a explicar porquê — a face visível da proteção que `InstrumentBuilder::update()` já impõe no servidor desde a Tarefa 2.

## [0.21.9] — 2026-07-30

### Adicionado

- **Anulação reversível de instrumentos (Tarefa 4/6).** Novas rotas `POST instruments/{instrument}/cancel` (motivo obrigatório) e `POST instruments/{instrument}/revert-cancellation`, que repõe o estado exato anterior à anulação — o ciclo anular/reverter pode repetir-se sem limite. Enquanto anulado, o instrumento fica só-leitura: `edit()`, `update()` (Tarefa 3) e agora também `saveScores()` recusam o pedido com 403. A grelha (`show()`) passa a enviar `status` (valor bruto) e `cancellation_reason`, para a Tarefa 6 construir a interface de anular/reverter no `Grid.vue`.

## [0.21.8] — 2026-07-30

### Adicionado

- **Fundação para a edição de instrumentos (Tarefa 3/6).** Novas rotas `GET instruments/{instrument}/edit` e `PUT instruments/{instrument}` — ainda sem interface própria (`instruments/Edit.vue` é por agora um placeholder mínimo; a Tarefa 5 constrói o formulário real). `InstrumentRequest` ganha autorização própria: sem isto, um professor sem acesso à turma recebia um erro de validação (422) em vez de acesso negado (403) sempre que o pedido também tivesse dados inválidos, porque a validação do pedido corre antes de qualquer autorização no corpo do controlador. Um instrumento anulado (funcionalidade da Tarefa 4) já fica preparado para ficar só-leitura nestas duas rotas.

## [0.21.7] — 2026-07-30

### Corrigido

- **Trocar o código de duas perguntas existentes na mesma edição fazia `InstrumentBuilder::update()` rebentar com um erro 500.** `instrument_items` tem uma constraint `unique(instrument_id, code)`, e o `update()` introduzido na Tarefa 2/6 passou a atualizar cada pergunta mantida no lugar, uma de cada vez — se a pergunta A (código `Q1`) troca para `Q2` enquanto a pergunta B (`Q2`) troca para `Q1`, escrever a nova pergunta A primeiro colide com o código ainda em uso pela B (o MySQL não tem constraints únicas diferíveis). Isto nunca acontecia na implementação antiga de apagar-tudo-e-recriar, porque nada existia ainda quando as novas linhas eram criadas — uma regressão introduzida especificamente pela mudança para a atualização no lugar. `update()` passa agora por duas fases dentro da mesma transação: primeiro atribui a cada pergunta mantida/editada um código temporário garantidamente único (derivado do seu próprio `id`), só depois é que qualquer uma recebe o código final — nenhum estado intermédio pode colidir com a constraint. Sem alteração de comportamento visível para o caso comum (sem troca de códigos).

## [0.21.6] — 2026-07-30

### Adicionado

- **Fundação para a edição de instrumentos (Tarefa 2/6).** `InstrumentBuilder::update()` deixa de apagar todas as perguntas e recriá-las (o que falhava assim que qualquer nota já existisse) e passa a comparar o que já existe com o que foi submetido: uma pergunta mantida ou editada atualiza-se no lugar (mantém o `id` e as notas já lançadas), uma pergunta nova é criada, e uma pergunta removida só é aceite se não tiver nenhuma nota lançada — caso contrário a atualização inteira é rejeitada sem nada ser escrito. Baixar a cotação de uma pergunta abaixo da maior nota já lançada nela também é rejeitado. Sem nenhuma rota ou interface nova ainda — a lógica de negócio fica pronta para as próximas tarefas a ligarem.

## [0.21.5] — 2026-07-29

### Adicionado

- **Fundação para a edição de instrumentos (Tarefa 1/6).** Nova coluna `status_before_cancellation` em `instruments`, para uma futura anulação/reversão poder repor o estado exato anterior. `InstrumentItem` ganha `scores()`, para saber se uma questão já tem notas lançadas antes de a deixar remover ou baixar a cotação. Sem mudança de comportamento visível ainda — as próximas tarefas deste plano constroem a edição em cima disto.

## [0.21.4] — 2026-07-29

### Corrigido

- **A grelha de correção deixava lançar uma nota acima da cotação máxima de uma questão.** Nem o frontend nem o backend impediam isto — o `max` do campo numérico é só decorativo no HTML, e a validação do pedido em `InstrumentController::saveScores()` não tinha nenhum limite ligado à questão. `RecordScores::save()` passa agora a verificar, antes de abrir a transação, se cada nota lançada excede a cotação máxima da sua questão — se sim, nada é escrito e a exceção `ScoreExceedsMaximumException` chega ao professor como erro de validação. Aplica-se também a questões de bónus: bónus só isenta uma questão do denominador (§4.2), nunca sobe o teto da própria questão. Na grelha (`Grid.vue`), a célula com nota acima do máximo fica visualmente marcada (borda e texto a vermelho) e o botão "Guardar" fica desativado enquanto isso não for corrigido — feedback imediato em vez de só depois de tentar guardar.

### Alterado

- **Revisão da coluna "Apreciação Qualitativa" da grelha de classificação.** O comentário sobre `percentFor()`/`qualitativeLabelFor()` em `instruments/Grid.vue` afirmava "mirrors CalculationEngine" de forma demasiado ampla; passa a dizer exatamente o que é espelhado (a exclusão de itens de bónus do denominador e o casamento inclusivo de bandas) e o que não é (ponderação por domínio, elegibilidade/entrada tardia, `absence_mode` — regras que só o `CalculationEngine` aplica). O cabeçalho "Apreciação Qualitativa" ganha um tooltip a deixar claro que é um indicador só deste instrumento, não a classificação oficial da turma/período. `percentFor()` e `qualitativeLabelFor()` foram extraídas para um novo módulo puro `resources/js/lib/instrumentQualitativeRating.ts` — sem alteração de comportamento — para que a lógica fique isolada e trivialmente testável no dia em que este projeto adotar um test runner de frontend (ainda não existe nenhum).

## [0.21.2] — 2026-07-29

### Adicionado

- **Percentagem e apreciação qualitativa na grelha de classificação.** `instruments/Grid.vue` ganha uma coluna "Apreciação Qualitativa" e a célula do Total passa a mostrar também a percentagem. Ambas são calculadas no cliente, reativamente, à medida que o professor edita as classificações — sem precisar de recarregar a página. A percentagem usa como denominador só os itens já avaliados (nunca a cotação total fixa do instrumento), para que um item por avaliar nunca puxe a percentagem para baixo — a mesma regra "vazio não é zero" que já governa o total. A apreciação qualitativa lê as bandas da escala do perfil de avaliação da turma (`scaleBands`, enviado por `InstrumentController::show()`); mostra "—" sempre que nada está avaliado, ou quando a turma não tem perfil, ou o perfil usa uma escala sem bandas (ex.: "Escala 0 a 20") — nunca um rótulo adivinhado.

## [0.21.1] — 2026-07-29

### Corrigido

- **O emparelhamento de fotos por nome nunca batia certo com um ficheiro real do Intuitivo.** Verificado diretamente contra os ficheiros reais desta sessão: a folha de fotos em Word usa só primeiro+último nome nas legendas ("Afonso Mordomo"), enquanto a lista Excel traz o nome completo com nomes do meio ("Afonso Pito Mordomo") — a comparação por igualdade de string normalizada nunca via os dois como iguais, pelo que nenhuma das 30 fotos reais alguma vez associava. `RosterImportPreviewBuilder::findPhotoIndex()` passa a comparar por subsequência de palavras (as palavras de um nome aparecem, pela mesma ordem, dentro do outro) em vez de igualdade exata, testado nos dois sentidos para cobrir tanto a legenda abreviada como o nome completo. `normalize()` mantém-se inalterado para as outras utilizações (deteção de duplicados, verificação de já-inscrito) — só o emparelhamento de fotos muda.

## [0.21.0] — 2026-07-29

### Adicionado

- **Fotos para alunos já inscritos.** O detalhe da turma passa a aceitar um ficheiro Word de fotografias mesmo depois de a lista ter sido confirmada. O emparelhamento reutiliza a mesma normalização por nome da pré-visualização da importação, guarda cada correspondência em armazenamento permanente e mantém sem alteração os alunos sem fotografia correspondente. Ficheiros Word inválidos regressam ao formulário com erro de validação e a ação continua restrita aos professores da turma.
- **Ativação de turmas.** Uma turma em preparação apresenta agora a ação «Ativar turma» no cabeçalho. A ação atualiza o estado para ativa, o detalhe devolve simultaneamente o valor técnico e o rótulo traduzido, e o botão desaparece depois da ativação.

## [0.20.8] — 2026-07-29

### Alterado

- **A importação de lista de turma passa a ser duas fases sequenciais em vez de um único diálogo confuso.** O diálogo original perguntava tudo de uma vez — Excel, mais uma checkbox opcional "Queres associar fotos?" que revelava um segundo input de ficheiro Word — e os professores achavam pouco claro. `classes/Show.vue` agora só pede o Excel; `RosterImportController::store()` deixou de aceitar `photos` (a validação e o `PhotoFileParser` saíram deste método por completo) e a pré-visualização nasce sempre sem fotos. Um novo endpoint `POST classes/{class}/roster-imports/{token}/photos` (`RosterImportController::attachPhotos()`) anexa o Word DEPOIS, reaproveitando o mesmo `$token` — e faz o emparelhamento por nome contra os dados ATUAIS do cliente (`rows` no pedido), não contra uma cópia do Excel no servidor, para que correções de nome já feitas na pré-visualização não sejam ignoradas. `RosterImportPreviewBuilder` ganha `matchPhotosToRows()`, extraído para reutilizar a normalização de nome já existente em vez de duplicar a lógica de `build()`. `roster-imports/Preview.vue` ganha uma secção "Adicionar fotos" que envia o ficheiro Word mais `form.rows` via `router.post` (nunca axios) e volta a semear `form.rows` a partir das `props.rows` atualizadas no `onSuccess`, já que o `useForm` local não se re-deriva sozinho de props alteradas.

## [0.20.7] — 2026-07-29

### Corrigido

- **A confinação de `photo_temp_path` ao próprio token (0.20.4) ainda era contornável.** Uma revisão posterior mostrou que a verificação `str_starts_with($caminho, $pastaDoToken.'/')` podia ser enganada por um caminho como `roster-imports/{token}/../{outroToken}/0.jpg`: começa literalmente pelo prefixo exigido, mas o Flysystem resolve o `..` embutido dentro da própria raiz do disco (só rejeita travessia que escape da raiz), acabando por ler a foto de OUTRA importação. Em vez de tentar tornar a verificação de string mais esperta, `confirm()` deixa de aceitar qualquer caminho vindo do cliente: as linhas passam a indicar a foto só por `photo_index` (posição) e `photo_extension` (extensão, validada por allowlist alfanumérica — nunca pode conter `/` nem `.`), e é o próprio servidor que reconstrói o caminho a partir destes dois valores estreitos mais o `token` que já controla via rota. Sem `isWithinThisImportsTempFolder()` a decidir se confia ou não num caminho — deixou de existir caminho nenhum vindo de fora para confiar.

## [0.20.6] — 2026-07-29

### Adicionado

- **Correção manual de foto na pré-visualização da importação.** O desenho original exigia que o professor pudesse "trocar foto mal associada" ou associar uma foto a um aluno sem correspondência automática, mas nenhuma das 11 tarefas implementou isto — a pré-visualização só permitia incluir/excluir, editar nome e número. `RosterImportController::store()` passa agora também `photos` (todas as fotos extraídas do ficheiro Word, incluindo as que não bateram certo com nenhum nome). Em `roster-imports/Preview.vue`, cada linha ganha uma miniatura "sem foto" mais uma miniatura por cada foto ainda disponível para essa linha — uma foto já atribuída a OUTRA linha desaparece das opções até essa linha ser reatribuída, impedindo que duas linhas fiquem com a mesma foto.

## [0.20.5] — 2026-07-29

### Adicionado

- **Limpeza agendada das importações de turma abandonadas.** Um professor que carrega uma lista (com fotos) e nunca chega a confirmar deixava a pasta temporária (`storage/app/private/roster-imports/{token}/`), com fotografias reais de alunos, para sempre no disco — só `confirm()` alguma vez chamava a limpeza. Novo comando `roster-imports:prune` (agendado a correr de hora a hora em `routes/console.php`) remove pastas de importação com mais de 6 horas sem atividade. Cumpre a promessa já assumida no desenho («o ficheiro carregado não é retido indefinidamente») e no próprio docblock de `RosterImportTempStorage`.

## [0.20.4] — 2026-07-29

### Corrigido

- **Um erro de validação ao confirmar a importação destruía as fotos já carregadas.** `RosterImportController::confirm()` tinha `Gate::authorize()` e `$request->validate()` dentro do mesmo `try/finally` que apaga a pasta temporária do token — uma linha inválida (nome vazio, número de turma fora do intervalo) fazia a `finally` apagar as fotos antes da exceção de validação chegar ao professor. Ao corrigir e reenviar, todas as fotos falhavam silenciosamente. Autorização e validação passam agora a correr ANTES do `try/finally`; só o ciclo que processa as linhas (que precisa mesmo das fotos temporárias) fica protegido pela limpeza garantida.
- **`photo_temp_path` era um caminho controlado pelo cliente, usado sem confirmar que pertencia a este pedido.** Nada impedia (em teoria) que um pedido manipulado apontasse para a pasta temporária de OUTRO token e associasse a foto de outra importação a um aluno sem relação nenhuma. `confirm()` agora confirma que o caminho está confinado à pasta do próprio token (`roster-imports/{token}/...`) antes de o ler e copiar — um caminho fora daí é tratado como se não houvesse foto, nunca copiado.

## [0.20.3] — 2026-07-29

### Corrigido

- **`PhotoFileParser` emparelhava fotos e legendas errado no documento real do Intuitivo.** O algoritmo usava uma única variável "imagem pendente": ao processar um grupo de N imagens seguido de N legendas (a estrutura real da grelha de fotos do Intuitivo, ex. 6 fotos por linha), cada nova imagem sobrescrevia a pendente, descartando as anteriores — só a última imagem de cada grupo de 6 sobrevivia para ser emparelhada (incorretamente) com a primeira legenda seguinte. Confirmado diretamente contra o ficheiro real: 30 imagens e 30 legendas no documento, mas apenas 5 pares corretos produzidos. Substituída a variável única por uma fila FIFO: cada legenda reclama a imagem mais antiga ainda não emparelhada — comportamento idêntico ao anterior no caso alternado simples, correto também no caso agrupado. Nova fixture `DocxFixtureBuilder::buildGrouped()` reproduz a estrutura agrupada real para o teste de regressão.

## [0.20.2] — 2026-07-27

### Alterado

- **Removida a gama numérica redundante no dropdown de escalas.** O nome da escala já a inclui («Escala 0 a 20», «Escala 1 a 5») — o `(0 a 20)` acrescentado a seguir duplicava a informação. O `<option>` volta a mostrar só o nome.

## [0.20.1] — 2026-07-27

### Alterado

- **Rótulo da gama numérica no dropdown de escalas.** `(0.000 a 20.000)` passa a `(0 a 20)` — o cast `decimal:3` do backend mantém-se (precisão para cálculo), só a apresentação no `<option>` deixa de mostrar casas decimais desnecessárias.

## [0.20.0] — 2026-07-27

### Adicionado

- **Escalas no perfil de avaliação.** A escala de sistema «Escala 1 a 5» passa a ter as bandas normalizadas aprovadas e propõe os níveis Fraco, Insuficiente, Suficiente, Bom e Muito Bom sem retirar ao professor a confirmação final. O motor mantém-se puro e recebe as bandas como value objects.
- **Escalas personalizadas inline.** O formulário de perfil agrupa escalas de sistema e personalizadas, mostra a gama numérica e permite criar uma escala personalizada (nome, mínimo e máximo) num diálogo Inertia sem perder o rascunho já preenchido.

### Corrigido

- **`Scale` nunca aplicava isolamento de organização.** O método `bootScale()` seguia a convenção de arranque de *traits* (`boot{NomeDoTrait}`), mas «Scale» é o nome da própria classe, não de um trait usado por ela — o Eloquent nunca chamava este método. Na prática, o scope `scaleVisibility` nunca filtrava nada (todas as escalas de todas as organizações ficavam visíveis) e `organization_id` nunca era carimbado na criação. Exposto pelo primeiro teste real a criar uma escala personalizada pela aplicação. Corrigido com um `boot()` próprio (`parent::boot()` + registo do scope/hook), o padrão que o Eloquent efetivamente invoca.

## [0.19.6] — 2026-07-27

### Alterado

- **Hero da homepage** (`/`): título passa a "LAP-IS", com "Laboratório de Apoio ao Professor — Informação e Simplificação" e "O seu LÁPIS digital para avaliar, organizar e ensinar." como sub-mensagem. Cabeçalho da homepage mantém a tagline curta ("Mais tempo para ensinar"); o `AppLogo.vue` (páginas autenticadas) passa a mostrar a frase completa.

## [0.19.5] — 2026-07-27

### Corrigido

- **Boot da app crashava (500) se a password SMTP guardada não decifrasse.** `AppServiceProvider::applyPlatformMailSettings()` lia `mail_password` (cast `encrypted`) sem proteção — uma ciphertext que já não bate certo com a `APP_KEY` atual (chave rodada, ou a linha vir de outra base de dados) derrubava **todos** os pedidos, não só o envio de email. Agora decifra explicitamente com `Crypt::decryptString()` num `try/catch`: se falhar, regista o erro e usa o mail do `.env`, sem 500. O operador tem de voltar a guardar as definições de SMTP em `/admin` para o email voltar a funcionar.

## [0.19.4] — 2026-07-27

### Documentação

- **Guia do backoffice** ([docs/backoffice.md](docs/backoffice.md)): tornar-se admin (`lapis:make-admin`), gestão de contas, criar/provisionar, impersonar, e **configuração de SMTP**. Inclui o mapeamento Encriptação→porta→scheme e a config real de produção — `mail.criativatek.com` só escuta **587/STARTTLS** (465/SSL dá `Connection refused`; sondado do VPS). `status.md` e `README.md` atualizados.

## [0.19.3] — 2026-07-27

### Corrigido

- **Backoffice não mostrava toasts.** O `AdminLayout` não montava o `<Toaster />`, por isso o resultado do «Enviar email de teste» (e do «Guardar») disparava mas nunca aparecia. Adicionado — o teste de SMTP passa a reportar sucesso/erro visível.

## [0.19.2] — 2026-07-27

### Corrigido

- **SMTP SSL (porta 465) não enviava.** O override passava `ssl`/`tls` como `scheme`, mas o Symfony Mailer quer `smtps` (TLS implícito, 465) — caso contrário o envio falha. Mapeado: `ssl → smtps`; TLS/nenhuma usam STARTTLS (o default `null`).

## [0.19.1] — 2026-07-27

### Corrigido

- **SMTP: campo «Mailer» era um footgun.** Pedia-se um valor que devia ser sempre `smtp`, e pôr lá o host partia o envio. Removido — o mailer do sistema é sempre SMTP (o override fixa `mail.default=smtp`). O «Enviar email de teste» passa a aceitar um **destinatário** à escolha, para o teste chegar a um inbox real.

## [0.19.0] — 2026-07-27

### Adicionado

- **Backoffice — impersonação de suporte (Slice 5).** O admin da plataforma vê a app como um professor para dar apoio: botão «Impersonar» no detalhe da conta, **banner âmbar** persistente («A ver a app como X — Terminar») e regresso à sua sessão. **Nunca impersona outro admin.** Início e fim **auditados** na trilha da org-alvo — é a ação mais sensível.

## [0.18.0] — 2026-07-27

### Adicionado

- **Backoffice — definições de SMTP (Slice 4).** O operador configura o **email do sistema** (verificação, reset, convites) a partir do backoffice: tabela `platform_settings` (linha única, password **cifrada**) que **sobrepõe o `.env`** em runtime (no boot). Página com o formulário SMTP (password write-only — em branco mantém a guardada) e botão **«Enviar email de teste»**. Desbloqueia o registo self-service assim que houver um SMTP real.

## [0.17.0] — 2026-07-27

### Adicionado

- **Backoffice — criar/provisionar conta (Slice 3).** O operador cria um professor (ou escola) a partir do backoffice: nome, email, password (opcional — gera uma temporária se em branco) e plano. Reutiliza `CreatePersonalOrganization` (user + org + subscrição), aplica o plano escolhido e marca o email verificado (contas provisionadas saltam a verificação). Audita `admin.account_created`.

## [0.16.0] — 2026-07-27

### Adicionado

- **Backoffice — gestão de contas (Slice 2).** Página de detalhe de cada organização com ações do operador: **verificar email** do dono, **mudar plano** (Base/Pro/Institucional), **suspender/reativar** subscrição, e **conceder/revogar** admin da plataforma. Mostra dono, subscrição e módulos ativos resolvidos. Cada ação fica na **trilha de auditoria da org-alvo** (via `runFor`).

### Corrigido

- **Resolução de entitlements não-determinística:** duas subscrições com o mesmo `starts_at` (ex.: mudar de plano no mesmo segundo do registo) podiam resolver para a antiga. `Entitlements::resolve` (e as queries do backoffice) passam a desempatar por `id` — a subscrição mais recente ganha sempre.

## [0.15.0] — 2026-07-27

### Adicionado

- **Backoffice de plataforma — fundação + lista de contas (Slice 1).** Área `/admin` para o operador do SaaS, fora do scope de tenant: coluna `is_platform_admin` em `users` (nunca mass-assignable), middleware `platform-admin` (403 para professores), comando `php artisan lapis:make-admin <email> [--revoke]`, e `AdminLayout` próprio. Primeira página: lista de **todas** as organizações (cross-org) com dono, plano, estado da subscrição e verificação de email — paginada e pesquisável.

## [0.14.1] — 2026-07-23

### Documentação

- **Onboarding portável para um novo contribuidor:** `README.md` (porta de entrada), `docs/workflow.md` (fluxo assistido por IA — equipa de agentes, Codex ativo, loop por slice, armadilhas SQLite-local vs MySQL-CI), `docs/status.md` (feito / bloqueado / falta), `docs/deployment.md` (CloudPanel · lapis.criativatek.com).
- **Orquestrador no repo:** os 4 agentes (`explorer`/`architect`/`implementer`/`reviewer`) passam a viver em `.claude/agents/` — `reviewer` usa `sonnet` (**sem Fable neste projeto**). `AGENTS.md` na raiz para o Codex. Secção «Orquestração & fluxo de IA» no `CLAUDE.md`.

## [0.14.0] — 2026-07-23

### Adicionado

- **Intervenções (§14).** Medidas de apoio por aluno com ciclo de vida (nova → em curso → concluída/cancelada) e apreciações periódicas de eficácia (sem efeito / parcialmente eficaz / eficaz / inconclusivo). Ligadas à inscrição e, opcionalmente, a um domínio; podem marcar-se para o relatório. **Nunca entram no cálculo** (§14.3). Concluir carimba a data de conclusão. Item «Intervenções» do menu passa a construído.

## [0.13.0] — 2026-07-23

### Adicionado

- **Autoavaliação (§15).** O aluno reflete por domínio, em entrevista com o professor: uma pergunta de escala 1–5 por domínio do perfil, e uma reflexão. **Comparada com a avaliação, nunca somada a ela** (§14/§15) — o resultado calculado de cada domínio aparece ao lado da autoavaliação, mas não há FK para os resultados. Template derivado automaticamente dos domínios da turma. Item «Autoavaliações» do menu passa a construído.

## [0.12.0] — 2026-07-23

### Adicionado

- **Registos / Evidências (§14).** O diário de bordo do professor: entradas qualitativas por turma (10 tipos — participação, ocorrência, comportamento, contacto…), opcionalmente sobre um aluno e um domínio, com data e a opção «incluir no relatório». **Nunca entra no cálculo** (§14.3): não tem peso nem FK para resultados. Soft delete. Um registo dirigido tem de apontar para um aluno da própria turma e um domínio da própria disciplina. Item «Registos» do menu passa a construído.

## [0.11.0] — 2026-07-23

### Adicionado

- **Trilha de auditoria (§22.4).** Tabela `audit_events` imutável e tenant-scoped: os eventos que tocam o registo de um aluno deixam uma linha com o autor, o momento e o contexto (valores, motivo). Emitidos pelos serviços que detêm cada ação — confirmação e alteração de classificação (com o motivo), publicação (evento único em lote), migração de perfil, ativação de versão, exportação de pauta (§22.5). Página «Registo de atividade» só-leitura.
- Decisão: tabela própria em vez de `spatie/laravel-activitylog` — o requisito é isolamento por organização + eventos de domínio (não diff de modelos), e uma tabela focada encaixa exato sem dependência nova.

## [0.10.0] — 2026-07-23

### Adicionado

- **Relatórios — pauta de classificações.** Por turma, a pauta dos alunos × períodos com as classificações **decididas** (confirmadas ou publicadas); uma proposta nunca aparece como nota. Imprimível (só a pauta, sem o shell) e exportável em CSV (com BOM UTF-8 para os acentos abrirem bem no Excel). Item «Relatórios» do menu passa a construído.

## [0.9.0] — 2026-07-22

### Adicionado

- **Painel do Professor.** Substitui o placeholder do starter kit por um painel real: saudação, resumo (turmas, classificações por confirmar, por publicar) e cartões por turma com as pendências e atalhos para Classificações/Resultados. Só mostra trabalho já criado — não decide nada. Agregação numa única query (join a `enrollments`), scoped à turma e à organização.

## [0.8.1] — 2026-07-22

### Corrigido (revisão independente da publicação + migração)

- **Bypass de autorização na publicação (P0):** `PublishClassifications` filtrava por período+âmbito mas não por turma; como os períodos pertencem ao ano letivo, publicar uma turma publicava as classificações confirmadas de outra turma do mesmo ano (mesmo de outro professor). Passa a filtrar por `enrollment_id` da turma. Teste com duas turmas no mesmo período.
- **Pré-visualização da migração mentia (P0):** mostrava «antes→depois, alterado» para classificações confirmadas/publicadas que a migração deixa intactas. Agora cada célula tem estado — `mantida` (decisão congelada), `sem proposta`, ou `recalculada` (antes/depois) — e só as recalculáveis contam para os totais.
- **Migração criava propostas não mostradas:** o re-cálculo passa a `refreshOnly` — só atualiza propostas em aberto existentes, nunca cria novas que o professor não viu na pré-visualização.
- **Concorrência:** confirmação de migração e publicação correm agora sob `lockForUpdate` com re-verificação do estado dentro da transação (evita registos de auditoria contraditórios e escritas cegas).
- Contagens da pré-visualização, do registo e do toast agora coincidem (vêm todas do mesmo documento). Guard `under_review` restrito aos instrumentos que contam.

## [0.8.0] — 2026-07-22

### Adicionado

- **Migração de perfil auditável (§10.2, A4).** Uma turma com resultados só muda de versão de perfil através de uma migração registada: pré-visualização por aluno do valor antes/depois em cada período, confirmação com motivo obrigatório, e um registo `class_profile_migrations` imutável.
  - Ativar uma nova versão **não** migra as turmas automaticamente — elas continuam na versão que produziu os seus resultados até uma migração explícita.
  - Ao migrar, as propostas em aberto recalculam sob a nova versão; as classificações confirmadas ou publicadas mantêm-se na versão antiga (o histórico não é recalculado).
  - `ClassController::updateProfile` passa a encaminhar para a migração quando a turma já tem decisões, em vez de trocar a versão em silêncio.

### Corrigido

- `MigrateClassProfile`: a relação `profileVersion` em cache era usada após a troca da FK, recalculando sob a versão antiga. Passa a apontar para a nova versão antes de re-propor.

## [0.7.0] — 2026-07-22

### Adicionado

- **Publicação da classificação (§13.2).** Botão «Publicar confirmadas»: as classificações confirmadas passam a `published` (comunicadas). Publicar **não recalcula nada** — só muda o estado e `published_at`.
  - Um elemento `under_review` (reclamação pendente) **bloqueia** a publicação da classificação desse período (§5); a contagem de retidas é reportada ao professor.

## [0.6.0] — 2026-07-22

### Adicionado

- **Resultado acumulado (§6.3, Q4).** Toggle «Por período / Acumulado» nas classificações. Com `accumulated_mode = all_valid_year_elements`, o motor reprocessa os elementos brutos de todos os períodos contribuintes até ao período em causa — **não** a média das médias dos períodos (que sobreponderaria os primeiros elementos).
  - Período e acumulado são decisões distintas para o mesmo (aluno, período): coexistem na tabela por âmbito.
  - O ingresso tardio atravessa o acumulado corretamente: o aluno é avaliado só pelos elementos que o alcançam (Filipe: só o 2.º período; nunca um zero).
  - `ClassResultsCalculator::forScope/forAccumulated`; proposta e confirmação passam a ser scope-aware.
  - DemoDataSeeder ganha um instrumento no 2.º período para o acumulado ter conteúdo.

## [0.5.0] — 2026-07-22

### Adicionado

- **Decisão de classificação (§7).** O motor propõe, o professor confirma. Tabelas `classifications` e `calculation_snapshots`, serviços `ProposeClassifications` e `ConfirmClassification`, e a página por turma/período.
  - A proposta determinística nunca é sobrescrita; o valor final do professor vive ao lado dela.
  - Alterar a proposta exige um motivo — garantido por CHECK em MySQL (`<=>` null-safe) **e** ao nível do serviço (cenário A10). Verificado com CHECK real em MySQL.
  - A confirmação congela um `calculation_snapshot`: cópias literais dos inputs, versão das regras, explicação estruturada e `payload_hash` SHA-256. Nunca atualizado.
  - Alunos sem resultado calculável (ausência total, ingresso tardio) **não** recebem proposta — nunca um zero.
  - Uma só classificação viva por (aluno, período, âmbito) via coluna gerada `status_active_flag`.
- **Página inicial LÁPIS.** Substitui o ecrã do starter kit Laravel; marca navy + âmbar, tema claro/escuro.

### Corrigido

- CHECK do A10 no `domain-model.md` estava incompleto: rejeitava o próprio estado `proposed` (`final_value` nulo vs proposta preenchida). Corrigido para admitir "sem decisão final" com ambas as colunas `final_*` nulas.
