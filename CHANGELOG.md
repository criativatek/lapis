# Changelog — LÁPIS

Formato: [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/). Versão semântica pré-1.0 enquanto as fases são construídas.

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
