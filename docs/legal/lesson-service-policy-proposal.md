# Proposta — atualização da Política de Privacidade e do Acordo (serviço letivo)

**Estado:** PROPOSTA DE REDAÇÃO PARA REVISÃO. Não é texto aprovado, não altera `LegalDocuments`,
e **nenhum fundamento jurídico aqui é dado como validado**. Onde falta decisão de quem tem
competência, está marcado **[A VALIDAR]**. Nada disto bloqueia o desenvolvimento nem os testes:
as chaves `LAPIS_LESSON_NOT_HELD_ENABLED` e `LAPIS_STRIKE_ADHESION_RECORDING` ficam desligadas
em produção até esta validação.

Complementa `docs/legal/strike-adhesion-proposal.md` (comportamento da especificação da greve).

## 1. O que a funcionalidade faz (factos, para a redação)

1. O professor pode registar que uma aula prevista **não se realizou**, escolhendo uma **causa**
   entre opções fechadas: greve; escola sem condições de funcionamento; encerramento por mau tempo;
   problema nas instalações; razão organizacional da escola; outra causa (com descrição breve).
2. Se a causa for **greve**, o professor **pode** (facultativo) especificar «Aderi à greve» ou
   «Não aderi à greve». Registar só «Greve» é o normal e não implica nenhuma das duas. A
   aplicação **nunca infere** a especificação nem a atribui a outro professor.
3. A **causa** descreve o que aconteceu à turma e é lida pelos professores com acesso à turma
   (incluindo em relatórios agregados: «Greve: 2»). A **especificação**, a **descrição de «outra
   causa»** e a **categoria de ausência do professor** só são lidas por quem as registou.
4. Observadores da turma não registam nem alteram resultados.
5. A especificação não vai para a auditoria, para pesquisas, para painéis, para relatórios da
   turma, nem para os assistentes de IA.
6. A **exportação pessoal** (restaurável) contém por inteiro o que o utilizador registou; o que um
   colega reservou viaja no valor válido menos específico («Outro», sem especificação,
   «Reservado»), para o ficheiro continuar restaurável sem entregar o dado. Não é um backup
   integral: esse é a cópia diária da base de dados, só da operação.
7. O relatório pessoal «Serviço letivo» mostra ao professor as causas e a especificação que
   **ele** registou.
8. **Correções.** Quem registou um resultado pode corrigi-lo (a causa, a especificação facultativa,
   a descrição, o motivo da ausência; e trocar «Professor ausente» por «Aula não realizada» e
   vice-versa). Nunca de ou para «Lecionada» nem «Turma noutra atividade letiva» (alteraria a
   numeração e o planeamento). Cada correção guarda **quem**, **quando**, os **valores anteriores e
   novos** e um **motivo em texto livre curto** (3 a 200 carateres), pedido ao professor.
9. **Visibilidade do histórico.** O histórico (que inclui valores reservados e o motivo textual)
   só é lido por quem tem a leitura do dado original (o titular do registo). Não vai para a
   auditoria geral (esta leva só o resultado e a causa, que são da turma), nem para relatórios,
   pesquisas, painéis ou assistentes de IA.
10. **Correção administrativa excecional.** Se o titular deixou de pertencer à turma, a direção da
    instituição (a competência que já tem para as retificações) pode corrigir **apenas a causa**,
    com motivo. Não lê a especificação, a descrição nem o histórico, e não declara nada em nome de
    um professor. O único professor que ficou **não** recebe esta competência por o ser.
11. **Exportação pessoal vs. backup operacional.** A exportação que o professor descarrega
    (`scope: personal`) leva o que ele registou, incluindo o **histórico das suas correções**; o
    dado reservado de colegas viaja reduzido e o histórico dos colegas não viaja. Não é um backup
    integral: esse é a cópia diária da base de dados, só da operação, que guarda tudo.
12. **Restauro.** Restaura-se acrescentando (nunca sobrescreve), sem duplicar correções, sem
    reclamar a autoria de quem não é a conta que importa.

## 2. Redação proposta

### 2.1 Política de Privacidade — secção «Dados do professor» (substitui a última linha)

> Informação operacional relativa à atividade letiva que regista: o resultado de cada aula
> (dada; não dada por ausência do professor; turma em outra atividade letiva; ou prevista e não
> realizada) e, quando aplicável, uma categoria escolhida entre opções fechadas — a categoria de
> ausência do professor (formação, serviço oficial, outro) ou a causa de a aula não se ter
> realizado (por exemplo, greve, encerramento da escola, mau tempo). Numa aula não realizada por
> greve, o professor pode, se o desejar, indicar se aderiu ou não à greve; esta indicação é
> facultativa, é visível apenas para o próprio e nunca é presumida pela aplicação. Não é pedido
> nem guardado texto livre sobre o motivo pessoal de uma ausência; apenas, em «outra causa», uma
> descrição curta da causa da aula não se ter realizado. **[A VALIDAR: enquadramento da
> indicação de adesão (dado operacional, ou categoria especial), fundamento de licitude e
> prazo de conservação.]**

Quem acede: a causa, os professores com acesso à turma; o restante, só o próprio.

### 2.2 Acordo de Subcontratação — «Objeto, duração e natureza do tratamento»

> … o resultado da ocorrência de cada aula, a categoria operacional de ausência do professor, a
> causa operacional de uma aula prevista e não realizada e, a título facultativo e reservado ao
> próprio professor, a indicação da sua situação perante uma greve. **[A VALIDAR: se esta
> última indicação altera a classificação do tratamento (cf. «não constitui categoria especial»
> na redação atual).]**

### 2.2b Política de Privacidade — correções, histórico e exportações (acrescento proposto)

> O professor pode corrigir o resultado que registou de uma aula. Cada correção fica guardada com
> o nome de quem a fez, a data, os valores anteriores e novos e um motivo curto escrito pelo
> professor (que deve evitar dados pessoais). Este histórico é visível apenas para o professor que
> registou o resultado. A exportação de dados pessoais inclui o histórico das correções do próprio
> professor; os dados reservados de outros professores não são incluídos. Cópias de segurança
> operacionais da base de dados contêm todos os dados e são acessíveis apenas à operação do
> serviço, durante o prazo de conservação indicado. **[A VALIDAR: prazo de conservação do
> histórico e das cópias; fundamento de licitude do motivo textual; direito de retificação e de
> acesso quando o titular do registo já não está na turma; quem responde a um pedido de acesso.]**

### 2.3 Data de entrada em vigor e notificação

Nova data de entrada em vigor e o mesmo procedimento de notificação usado nas revisões
anteriores. **[A VALIDAR]**

## 3. Alterações de código que acompanham a publicação (na mesma alteração)

1. `LegalDocuments`: os dois textos e as duas datas.
2. `LessonOutcomeLegalTest`: passa a fixar os novos campos aceites pelo pedido
   (`cause`, `situation`, `cause_note`, `retroactive`) e a nova redação, em vez de exigir que o
   pedido só tenha `outcome`, `reason`, `note` — hoje esses campos só entram nas regras com
   `lapis.lesson_not_held_enabled` ligada, precisamente para este teste continuar a ser o guarda
   da publicação do texto.
3. Ligar `LAPIS_LESSON_NOT_HELD_ENABLED` e, só depois, `LAPIS_STRIKE_ADHESION_RECORDING`.

## 4. Perguntas em aberto para quem valida

1. A indicação «Aderi à greve» é dado pessoal de que natureza? Pode ser recolhida com
   consentimento do próprio, como dado facultativo?
2. A causa «Greve», visível a todos os professores da turma e em agregados, identifica indiretamente
   alguém? (Hoje não há especificação visível a outros; mas uma turma com um só professor…)
3. Prazo de conservação da especificação (ligado ao fim do ano letivo ou à vida da conta?).
4. A exportação pessoal entregue a um professor deve incluir a causa dos colegas? (Hoje inclui — é da turma.)
5. Direito de retificação: o professor corrige o que registou na página da aula (resultado, causa,
   especificação, descrição), com histórico. **[A VALIDAR]** se o histórico, por guardar os valores
   anteriores incluindo a especificação, deve ser apagável a pedido ou conservado como registo de
   auditoria, e por quanto tempo.
6. O **motivo textual** da correção (texto livre, 3–200 carateres) é um dado novo. **[A VALIDAR]**
   fundamento, minimização (a interface pede para evitar dados pessoais) e conservação.
7. **Correção administrativa**: a direção corrige a causa sem ler os detalhes. **[A VALIDAR]** se
   esta intervenção precisa de ser comunicada ao titular e se a competência da direção, reutilizada
   do modelo das retificações de classificações, é suficiente para um dado do professor.
8. **Exportação pessoal** inclui o histórico das correções do próprio. **[A VALIDAR]** se deve
   incluir também as correções administrativas feitas por terceiros sobre registos do próprio.
9. **Conservação e recuperação**: o dump diário guarda tudo (incluindo especificação e histórico)
   durante o prazo operacional da cópia; a exportação pessoal é gerada a pedido e expira em 24 h.
   **[A VALIDAR]** prazo da cópia diária e procedimento de apagamento após o prazo.
