<?php

namespace App\Support\Legal;

/**
 * ⚠️ TEXTO TÉCNICO/FACTUAL — REQUER REVISÃO JURÍDICA ANTES DA DIVULGAÇÃO A
 * UTILIZADORES REAIS.
 *
 * Cada frase aqui foi escrita a partir do que o código faz, verificado no
 * código, e não a partir de um modelo de termos genérico. Ver `docs/legal.md`
 * para a auditoria que sustenta cada afirmação e para a lista dos pontos que
 * dependem de validação jurídica.
 *
 * TRÊS DOCUMENTOS, E A RAZÃO DE SEREM TRÊS:
 *
 * - `terms()` regula a relação comercial e de utilização.
 * - `privacy()` descreve os tratamentos em que a HORIZONLEVEL é RESPONSÁVEL:
 *   a conta do professor, a segurança, o suporte, a relação comercial.
 * - `processing()` é o acordo de tratamento para os dados dos ALUNOS, em que
 *   a HORIZONLEVEL é SUBCONTRATANTE do professor. É um documento distinto
 *   porque é uma figura jurídica distinta, e misturá-lo com a Política de
 *   Privacidade é o erro que faz uma plataforma parecer estar a decidir
 *   finalidades pedagógicas que não decide.
 *
 * A REPARTIÇÃO É A ESPINHA DE TUDO O QUE SE SEGUE, E NÃO PODE PRESSUPOR QUEM
 * É O RESPONSÁVEL. Um professor pode exercer a atividade a título próprio e
 * determinar ele mesmo as finalidades — e ser o responsável. Outro atua sob a
 * autoridade de uma escola ou agrupamento, e nesse caso o responsável é a
 * instituição. Os documentos não escolhem por ele: dizem que quem introduz
 * dados de alunos deve fazê-lo enquanto responsável ou devidamente autorizado
 * pelo responsável competente, e comportam as duas situações.
 *
 * O que é constante é o outro lado: quando a HORIZONLEVEL trata esses dados
 * apenas para prestar o serviço, atua como subcontratante. Não determina as
 * finalidades pedagógicas nem define a base jurídica aplicável — e é isso, e
 * não uma afirmação sobre quem é o responsável, que os textos declaram.
 *
 * ESTÁ EM PHP E NÃO NOS COMPONENTES VUE por uma razão prática: o SSR do
 * Inertia está desligado, pelo que texto escrito dentro de um `.vue` não
 * chega à resposta e nenhum teste de servidor lhe pode tocar. Daqui, o
 * conteúdo viaja no payload do Inertia — que está no HTML — e
 * `LegalPagesTest` consegue afirmar que a secção de menores existe, que
 * nenhum placeholder é apresentado como facto, e que a descrição da IA não
 * promete o que o produto não faz.
 *
 * O QUE NUNCA DEVE ENTRAR AQUI: um fornecedor que não esteja verificado, uma
 * base legal que ninguém validou, uma classificação de risco ao abrigo do
 * Regulamento da IA, um foro exclusivo, uma certificação, um DPO, ou uma
 * promessa de segurança absoluta.
 */
class LegalDocuments
{
    /**
     * A versão dos Termos, para efeitos de registo de aceitação.
     *
     * É A PRÓPRIA DATA DE ENTRADA EM VIGOR, e não um número à parte. Dois
     * valores que têm de concordar são duas oportunidades para deixarem de
     * concordar, e o que fica errado é invisível: nada rebenta, a conta só
     * passa a dizer que aceitou uma versão que nunca existiu.
     */
    public static function termsVersion(): string
    {
        return (string) config('lapis.legal.terms_effective_from');
    }

    /**
     * A identidade do responsável, e o que falta dela.
     *
     * @return array{name: ?string, vat: ?string, address: ?string, privacy_email: ?string, complete: bool}
     */
    public static function controller(): array
    {
        $values = [
            'name' => self::setting('controller_name'),
            'vat' => self::setting('controller_vat'),
            'address' => self::setting('controller_address'),
            'privacy_email' => self::setting('privacy_email'),
        ];

        return [...$values, 'complete' => ! in_array(null, $values, true)];
    }

    private static function setting(string $key): ?string
    {
        $value = config("lapis.legal.{$key}");

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * Um contacto oficial, ou uma formulação que não promete um endereço que
     * não existe.
     *
     * Os três estão configurados; este fallback existe para que uma instalação
     * que os limpe não passe a mostrar a palavra «null» a meio de uma frase.
     */
    private static function contact(string $key): string
    {
        return self::setting($key) ?? 'o contacto indicado nesta página';
    }

    /** A entidade, por extenso, ou uma perífrase que não inventa um nome. */
    private static function entity(): string
    {
        return self::setting('controller_name') ?? 'entidade responsável indicada no fim desta página';
    }

    /**
     * Os prazos que a aplicação cumpre mesmo, lidos de onde são cumpridos.
     *
     * NÃO SE ESCREVE UM PRAZO À MÃO NUM DOCUMENTO LEGAL quando o número vive
     * numa configuração que uma tarefa agendada lê todas as noites. Um texto
     * que diz «60 dias» enquanto o comando apaga aos 90 é pior do que um texto
     * que não diz prazo nenhum.
     */
    private static function closureDays(): int
    {
        return (int) config('retention.personal_account_closure_days');
    }

    private static function exportHours(): int
    {
        return (int) config('retention.data_export_availability_hours');
    }

    /*
     * Os prazos da Central de Suporte, lidos de `config/retention.php` como
     * todos os outros. O documento não pode afirmar um número que a rotina não
     * cumpra: mudar o prazo na configuração muda o texto, e nunca ao contrário.
     */

    private static function supportReminderDays(): int
    {
        return (int) config('retention.support_waiting_reminder_days');
    }

    private static function supportAutoResolveDays(): int
    {
        return (int) config('retention.support_waiting_auto_resolve_days');
    }

    private static function supportRetainedMonths(): int
    {
        return (int) config('retention.support_resolved_months_retained');
    }

    /**
     * @return array{title: string, effective_from: string, intro: string, sections: list<array{heading: string, body: list<string>}>, related: list<array{label: string, href: string}>}
     */
    public static function terms(): array
    {
        return [
            'title' => 'Termos de Utilização',
            'effective_from' => (string) config('lapis.legal.terms_effective_from'),
            'intro' => 'Estes Termos regulam a utilização do Lapispro. Ao criar uma conta, está a aceitar o que aqui se descreve. Foram escritos para serem lidos: onde uma regra tem uma consequência prática, ela está dita por extenso.',
            'related' => [
                ['label' => 'Política de Privacidade', 'href' => '/privacidade'],
                ['label' => 'Acordo de Tratamento de Dados', 'href' => '/tratamento-de-dados'],
            ],
            'sections' => [
                [
                    'heading' => 'O que é o Lapispro',
                    'body' => [
                        'O Lapispro é um serviço disponibilizado pela '.self::entity().'.',
                        'O Lapispro é uma plataforma para professores que reúne a gestão de turmas e alunos, os critérios e instrumentos de avaliação, o cálculo e a decisão de classificações, o acompanhamento pedagógico, as aulas e sumários, e os relatórios.',
                        'Destina-se a professores. Não é uma plataforma para alunos nem para encarregados de educação: não existe registo, sessão ou acesso próprio para estes.',
                        'A utilização por escolas e agrupamentos, com vários professores sob uma administração comum, está prevista mas ainda não se encontra disponível para adesão. Enquanto não estiver, estes Termos regulam a utilização por professores a título individual.',
                    ],
                ],
                [
                    'heading' => 'A sua conta',
                    'body' => [
                        'A conta é pessoal. Ao criá-la, é gerada a sua organização pessoal — o espaço onde o seu trabalho fica isolado do de qualquer outra pessoa.',
                        'É responsável por manter as suas credenciais em segurança e por tudo o que for feito através da sua conta. O Lapispro disponibiliza autenticação em dois passos e chaves de acesso (passkeys); recomendamos que use pelo menos uma delas.',
                        'Os dados que indica ao criar a conta devem ser verdadeiros e atuais.',
                        'Se detetar uma utilização indevida da sua conta, ou precisar de ajuda com o acesso, contacte '.self::contact('accounts_email').'.',
                    ],
                ],
                [
                    'heading' => 'Aceitação e versões destes Termos',
                    'body' => [
                        'Ao criar conta aceita a versão destes Termos em vigor nesse momento. Fica registado, na sua conta, qual a versão aceite e a data em que o foi — e nada mais: não guardamos o endereço IP nem o identificador do navegador com que o fez.',
                        'A versão de cada documento é a sua data de entrada em vigor, indicada no início da página. É essa data que fica registada na sua conta.',
                        'Se estes Termos forem materialmente alterados, procuraremos avisá-lo através da aplicação ou por email antes de a nova versão produzir efeitos, e voltar a registar a aceitação quando isso for necessário.',
                    ],
                ],
                [
                    'heading' => 'Utilização aceitável',
                    'body' => [
                        'Compromete-se a utilizar o Lapispro apenas para fins legítimos relacionados com o seu trabalho pedagógico.',
                        'Não deve tentar aceder a dados de outra organização, contornar os controlos de acesso, sobrecarregar deliberadamente o serviço, nem introduzir dados pessoais de terceiros sem fundamento legítimo para o fazer.',
                    ],
                ],
                [
                    'heading' => 'Dados pedagógicos e decisões',
                    'body' => [
                        'O Lapispro calcula, organiza e propõe. Não decide.',
                        'A classificação final é sempre uma decisão do professor. O sistema apresenta uma proposta a partir do perfil de avaliação e do que está registado; a confirmação é sua, e se decidir de forma diferente da proposta, a diferença e a razão ficam registadas.',
                        'Quando as funcionalidades de inteligência artificial estão disponíveis, sugerem — nunca decidem, nunca atribuem ou alteram uma classificação, e nunca aplicam sozinhas uma estratégia. Cada sugestão é aceite, adaptada ou descartada por si.',
                        'A responsabilidade pedagógica pelo que regista e pelo que decide continua a ser sua.',
                    ],
                ],
                [
                    'heading' => 'Proteção de dados: quem responde pelo quê',
                    'body' => [
                        'São duas relações diferentes, e vale a pena distingui-las porque as consequências são diferentes.',
                        'Quanto aos dados da sua conta — nome, email, autenticação, plano, segurança e suporte — a '.self::entity().' é a responsável pelo tratamento. Está descrito na Política de Privacidade.',
                        'Quanto aos dados dos alunos, quem os introduz no Lapispro deve fazê-lo enquanto responsável pelo tratamento ou devidamente autorizado pelo responsável pelo tratamento competente. Isso abrange tanto o professor que exerce a atividade a título próprio e determina ele mesmo as finalidades, como o professor que atua sob a autoridade de uma escola ou agrupamento — caso em que a responsável é a instituição.',
                        'Quando a HORIZONLEVEL, LDA trata esses dados apenas para prestar o serviço, atua como subcontratante. As condições dessa relação estão no Acordo de Tratamento de Dados, que faz parte integrante destes Termos.',
                    ],
                ],
                [
                    'heading' => 'Disponibilidade e evolução',
                    'body' => [
                        'O Lapispro é um serviço em evolução. Funcionalidades podem ser acrescentadas, alteradas ou substituídas, e o serviço pode ser interrompido para manutenção.',
                        'Procuramos manter o serviço disponível e avisar de interrupções planeadas, mas não garantimos disponibilidade ininterrupta.',
                        'Pode a qualquer momento exportar os seus dados a partir da aplicação.',
                    ],
                ],
                [
                    'heading' => 'Planos e condições comerciais',
                    'body' => [
                        'Existem os planos Base e Pro, disponíveis para professores a título individual. O que os distingue são as funcionalidades e, no caso do Base, alguns limites quantitativos.',
                        'Existe também um plano Institucional, destinado a escolas e agrupamentos, que ainda não está disponível para adesão.',
                        'Criar conta dá acesso ao plano Base. Pode estar disponível um período experimental do plano Pro, por tempo limitado e sem obrigação de pagamento.',
                        'Podem ser atribuídos códigos que dão acesso a condições especiais. As condições comerciais em vigor, incluindo preços e eventuais campanhas de lançamento, são as apresentadas na página pública no momento da adesão.',
                        'Terminar um plano superior não elimina dados: as funcionalidades correspondentes deixam de estar disponíveis e a informação já registada continua a existir, nos termos da Política de Privacidade.',
                    ],
                ],
                [
                    'heading' => 'Encerramento da conta',
                    'body' => [
                        'Pode pedir o encerramento da sua conta a partir das definições.',
                        'O pedido inicia um período de recuperação de '.self::closureDays().' dias, durante o qual pode cancelá-lo e a conta volta ao normal. Durante esse período, a conta fica limitada a leitura e à exportação dos seus dados.',
                        'Terminado esse período, o encerramento é executado: os dados que o identificam a si são removidos, as credenciais deixam de permitir autenticação, e os dados identificativos dos alunos da sua organização pessoal são eliminados.',
                        'Não prometemos eliminação instantânea. Alguma informação é conservada quando é necessária à integridade dos registos ou ao registo de atividade — nesses casos deixa de estar associada a uma pessoa identificável. A Política de Privacidade descreve isto em detalhe.',
                        'Se vier a pertencer a uma organização institucional, o encerramento da sua conta não elimina dados de uma organização institucional de que seja apenas membro: esses pertencem à instituição.',
                    ],
                ],
                [
                    'heading' => 'Propriedade intelectual',
                    'body' => [
                        'O software, a marca e a apresentação do Lapispro pertencem ao seu titular. Estes Termos não transferem qualquer direito sobre eles.',
                        'O conteúdo que introduz — critérios, registos, textos, relatórios — continua a ser seu. O Lapispro trata-o apenas para lhe prestar o serviço.',
                    ],
                ],
                [
                    'heading' => 'Limitação de responsabilidade',
                    'body' => [
                        'O Lapispro é uma ferramenta de apoio. Não substitui o julgamento profissional do professor nem as obrigações da instituição de ensino.',
                        'Na medida permitida pela lei aplicável, não respondemos por decisões pedagógicas tomadas com apoio da ferramenta, nem por perdas resultantes de utilização contrária a estes Termos.',
                        'Nada nestes Termos exclui ou limita responsabilidades que a lei não permita excluir ou limitar. Quando lhe seja aplicável a legislação de proteção dos consumidores, mantém integralmente os direitos que dela resultem.',
                    ],
                ],
                [
                    'heading' => 'Alterações a estes Termos',
                    'body' => [
                        'Estes Termos podem ser atualizados. A data de entrada em vigor da versão atual está indicada no início desta página, e é essa data que serve de versão.',
                        'Quando a alteração for material, procuraremos avisar através da aplicação ou por email antes de produzir efeitos.',
                    ],
                ],
                [
                    'heading' => 'Lei aplicável e resolução de litígios',
                    'body' => [
                        'A estes Termos aplica-se a lei portuguesa.',
                        'Para a resolução de qualquer litígio emergente destes Termos são competentes os tribunais territorialmente competentes nos termos da lei.',
                        'Quando lhe seja aplicável a legislação de proteção dos consumidores, mantém integralmente os direitos que dela resultem, incluindo o recurso aos meios de resolução alternativa de litígios de consumo legalmente previstos.',
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array{title: string, effective_from: string, intro: string, sections: list<array{heading: string, body: list<string>}>, related: list<array{label: string, href: string}>}
     */
    public static function privacy(): array
    {
        return [
            'title' => 'Política de Privacidade',
            'effective_from' => (string) config('lapis.legal.privacy_effective_from'),
            'intro' => 'Esta página descreve os tratamentos de dados pessoais pelos quais o Lapispro responde como responsável: a sua conta de professor, a segurança, o suporte e a relação comercial. Os dados dos seus alunos seguem um regime diferente, descrito no Acordo de Tratamento de Dados.',
            'related' => [
                ['label' => 'Termos de Utilização', 'href' => '/termos'],
                ['label' => 'Acordo de Tratamento de Dados', 'href' => '/tratamento-de-dados'],
            ],
            'sections' => [
                [
                    'heading' => 'Quem responde pelo quê',
                    'body' => [
                        'O Lapispro é um serviço disponibilizado pela '.self::entity().'. Os elementos completos de identificação — NIF e morada — estão no fim desta página.',
                        'Sobre os dados da sua conta, essa entidade é a responsável pelo tratamento: é ela que decide para que servem e como são tratados. É deste conjunto que esta página trata.',
                        'Sobre os dados dos alunos, não. Quem os introduz no Lapispro deve fazê-lo enquanto responsável pelo tratamento ou devidamente autorizado pelo responsável pelo tratamento competente — o que abrange tanto o professor que exerce a atividade a título próprio como aquele que atua sob a autoridade de uma escola ou agrupamento.',
                        'Quando a HORIZONLEVEL, LDA trata esses dados apenas para prestar o serviço, atua como subcontratante: guarda, calcula e devolve segundo as instruções recebidas, e não os usa para finalidades próprias. As condições dessa relação estão no Acordo de Tratamento de Dados.',
                        'Relativamente aos dados pedagógicos dos alunos tratados por conta do responsável pelo tratamento, a HORIZONLEVEL não determina as respetivas finalidades pedagógicas nem define a base jurídica aplicável.',
                        'Para exercer os seus direitos ou colocar qualquer questão sobre privacidade, escreva para '.self::contact('privacy_email').'.',
                    ],
                ],
                [
                    'heading' => 'Dados do professor',
                    'body' => [
                        'Nome e endereço de email, indicados por si ao criar a conta.',
                        'Dados de autenticação. A palavra-passe nunca é guardada, nem em texto legível nem cifrada: o que fica é uma representação criptográfica irreversível (hash), a partir da qual a palavra-passe não pode ser reconstituída. Se as ativar, ficam também os segredos de autenticação em dois passos e as suas chaves de acesso (passkeys).',
                        'A organização pessoal a que pertence, as suas configurações de avaliação e as suas preferências de interface.',
                        'O estado da sua conta e do seu plano, incluindo pedidos de encerramento e períodos experimentais.',
                        'A versão dos Termos que aceitou e a data em que o fez.',
                    ],
                ],
                [
                    'heading' => 'Dados dos alunos',
                    'body' => [
                        'Descrevem-se aqui por transparência — para que saiba o que a plataforma guarda —, mas o regime aplicável é o do Acordo de Tratamento de Dados, e as finalidades são determinadas pelo responsável pelo tratamento, não por nós.',
                        'Os dados de identificação do aluno — nome e, quando indicado, número de processo — são guardados numa tabela separada e cifrados. O resto da aplicação trabalha com um pseudónimo, não com o nome.',
                        'Isto é pseudonimização, não anonimização: o professor continua a poder ver quem é cada aluno, porque precisa disso para trabalhar. O que se reduz é a exposição da identidade em tudo o resto. A anonimização — a remoção efetiva da relação com a pessoa — acontece no encerramento da conta, descrito mais abaixo.',
                        'Não é necessário anonimizar os alunos antes de os introduzir: a proteção é aplicada pelo Lapispro.',
                        'Podem ainda existir data de nascimento e fotografia, quando são introduzidas.',
                        'Dados pedagógicos: turmas e inscrições, classificações e resultados por domínio, instrumentos de avaliação, registos de acompanhamento, autoavaliações, estratégias e medidas, e relatórios.',
                        'O aluno não tem conta, sessão nem acesso próprio ao Lapispro. Os seus dados são introduzidos e geridos através da conta do professor.',
                    ],
                ],
                [
                    'heading' => 'Dados técnicos',
                    'body' => [
                        'Sessões: para manter a sua autenticação, é guardado um registo de sessão que inclui o endereço IP e o identificador do navegador.',
                        'Registo de atividade (auditoria): que ação foi feita, por quem, sobre o quê e quando. Não inclui endereço IP nem identificador de navegador.',
                        'Registos técnicos do servidor, para diagnóstico de erros e segurança.',
                    ],
                ],
                [
                    'heading' => 'Ficheiros',
                    'body' => [
                        'Ficheiros que carrega — pautas, fotografias, grelhas de correção, cópias de segurança para importação — e ficheiros que o Lapispro gera, como relatórios e exportações dos seus dados.',
                        'Os ficheiros temporários de importações que não chegam a ser confirmadas são eliminados automaticamente, sem intervenção sua.',
                    ],
                ],
                [
                    'heading' => 'Para que usamos os dados',
                    'body' => [
                        'Prestar o serviço: autenticar a sua conta, disponibilizar as funcionalidades do seu plano, e manter a sua organização e as suas configurações.',
                        'Segurança e auditoria: proteger as contas, detetar utilização indevida e manter um registo de quem fez o quê.',
                        'Cópias de segurança, para permitir a recuperação em caso de incidente.',
                        'Suporte, quando nos contacta através de '.self::contact('support_email').' ou do formulário de contacto do nosso site. A secção «Contactos e suporte» descreve este tratamento em detalhe.',
                        'Gestão do plano e da relação comercial, incluindo faturação quando aplicável.',
                        'Responder a pedidos de exportação ou de eliminação de dados, e a pedidos de autoridades quando a lei o imponha.',
                        'Não usamos os dados para publicidade, para criar perfis comerciais, nem para os vender ou ceder a terceiros. Não usamos dados de alunos para desenvolver ou treinar modelos.',
                    ],
                ],
                [
                    'heading' => 'Com que fundamento tratamos os dados da sua conta',
                    'body' => [
                        'Execução do contrato: criar e manter a conta, disponibilizar as funcionalidades do plano, prestar suporte e gerir o encerramento. Sem estes tratamentos não há serviço.',
                        'Cumprimento de obrigações legais: conservar o que a lei obrigue a conservar, dar resposta a pedidos legítimos de autoridades, e cumprir as obrigações fiscais e contabilísticas associadas a um plano pago.',
                        'Interesse legítimo: manter a segurança da plataforma e das contas, prevenir e detetar utilização abusiva, manter o registo de atividade e as cópias de segurança, e assegurar a integridade e a continuidade do serviço. Ponderámos este interesse contra os seus direitos e restringimos os dados ao mínimo que estas finalidades exigem — o registo de atividade, por exemplo, não guarda endereço IP nem identificador de navegador.',
                        'Estes fundamentos aplicam-se aos dados da sua conta. Não são invocados para os dados pedagógicos dos alunos: esses são tratados por conta do responsável pelo tratamento, nos termos do Acordo de Tratamento de Dados. Os contactos de quem ainda não tem conta têm fundamento próprio, indicado na secção «Contactos e suporte».',
                        'Quando um tratamento assentar no seu consentimento, será pedido de forma separada e pode ser retirado a qualquer momento, sem afetar o que foi feito antes.',
                    ],
                ],
                [
                    /*
                     * A SECÇÃO QUE FALTAVA QUANDO O SUPORTE PASSOU A TER
                     * FORMULÁRIO. Até à Central de Suporte, o suporte era um
                     * endereço de email e a Política descrevia-o assim; o
                     * fundamento estava enunciado para «os dados da sua conta»,
                     * e quem escreve do formulário público pode não ter conta
                     * nenhuma — é precisamente o caso de «não consigo entrar».
                     *
                     * Cada frase aqui corresponde a um comportamento verificado
                     * no código: os campos são os do formulário, os prazos são
                     * os de `config/retention.php` que `support:retention`
                     * executa, e o que se diz não ser guardado não é mesmo
                     * escrito em lado nenhum.
                     */
                    'heading' => 'Contactos e suporte',
                    'body' => [
                        'Pode contactar-nos por email para '.self::contact('support_email').' ou pelo formulário de contacto do nosso site, com ou sem conta criada. Se já tiver conta e conseguir entrar, pode também abrir um pedido dentro do Lapispro e acompanhar as respostas.',
                        'Do formulário do site — o público, que pode usar sem conta — recolhemos o nome e o endereço de email que nos indicar, o assunto que escolher de uma lista, e o resumo e a descrição que escrever, e mais nada. Não guardamos o seu endereço IP nem informação sobre o seu navegador: o endereço é usado apenas no momento do envio, para limitar submissões automáticas, e não é registado. Esse formulário não aceita ficheiros anexos.',
                        'Quando abre um pedido de dentro do Lapispro, com a conta iniciada, recolhemos além disso informação técnica sobre o ecrã onde estava: o nome desse ecrã, o endereço da página com os identificadores substituídos, a família e a versão principal do seu navegador, o sistema e o tamanho da janela. Não guardamos a identificação completa do navegador nem o seu endereço IP.',
                        'Recolhemos também, desse mesmo ecrã, as mensagens técnicas que o navegador tenha registado, os pedidos ao servidor que tenham falhado e os erros que tenham ocorrido nos minutos anteriores. Servem para reproduzir o problema sem lho ter de perguntar. Nada disto sai do seu navegador até carregar em enviar, e o formulário mostra-lhe, antes disso, quantas mensagens e quantos pedidos vão seguir.',
                        'Pode juntar uma imagem do seu ecrã e outras imagens à sua escolha. Nenhuma imagem é recolhida sem a pedir, e nenhuma segue sem a rever: mostramo-la em tamanho real e só a enviamos depois de confirmar que a viu e que não mostra dados que não queira partilhar. Guardamos essa confirmação com a data e com o aviso que lhe foi apresentado. Se não a confirmar, o pedido segue à mesma, sem a imagem. As imagens ficam em armazenamento privado, só acessíveis a si e às pessoas da nossa equipa responsáveis por responder, e são eliminadas com o pedido nos prazos indicados em «Durante quanto tempo».',
                        'Chamamos-lhe a atenção para que uma imagem de um ecrã do Lapispro pode mostrar nomes de alunos e classificações. A aplicação não consegue reconhecer nomes dentro de uma imagem, e por isso a decisão de a enviar é sua, com a imagem à vista.',
                        'Pode ditar a descrição em vez de a escrever. Quem transcreve é o seu próprio navegador, e não o Lapispro: consoante o navegador e o sistema, a transcrição é feita no seu dispositivo ou o áudio é enviado pelo navegador ao serviço de reconhecimento de voz do fornecedor dele — hoje, no Chrome e no Edge, é este segundo caso, e o fornecedor é a Google. O formulário diz-lhe qual dos dois se aplica antes de começar a ouvir. Em nenhum dos casos o som passa por nós nem é gravado: o que chega ao Lapispro é apenas o texto, que fica no ecrã para corrigir antes de enviar. Pelo mesmo motivo por que lhe pedimos que não escreva nomes de alunos, pedimos-lhe que também não os dite.',
                        'Usamos estes dados para receber, analisar e responder ao seu pedido, e para manter o histórico da conversa enquanto ela for útil a ambos.',
                        'Fundamento, consoante o motivo do contacto: quando pede informações, uma demonstração ou uma proposta, ou quando o contacto se destina a preparar uma eventual contratação, o tratamento assenta em diligências pré-contratuais (artigo 6.º, n.º 1, alínea b) do Regulamento). Quando já é nosso utilizador — incluindo quando nos escreve por não conseguir entrar na conta —, assenta na execução do contrato (mesma alínea b)). Quando o contacto é de outra natureza, assenta no nosso interesse legítimo em receber, organizar e responder às comunicações que nos são dirigidas (alínea f)). Prevenir e detetar abuso, fraude e incidentes de segurança no canal de suporte assenta também em interesse legítimo (alínea f)); e conservamos o que a lei nos obrigue a conservar, quando exista obrigação concreta aplicável (alínea c)).',
                        'Não lhe pedimos consentimento para o simples facto de nos contactar, e contactar o suporte não nos autoriza a enviar-lhe comunicações comerciais.',
                        'Quando o fundamento for o interesse legítimo, pode opor-se ao tratamento a qualquer momento, nos termos descritos em «Os seus direitos».',
                        'Acedem ao seu pedido as pessoas da nossa equipa responsáveis por responder. Um pedido é da pessoa que o escreveu: não é visível para outros utilizadores, nem para colegas da mesma escola ou agrupamento, nem para quem administre a organização a que pertença.',
                        'Enviamos-lhe email para confirmar a receção e sempre que houver resposta. Esses emails identificam o pedido por uma referência e não reproduzem o que escreveu. Se responder a um desses emails, a sua mensagem chega à nossa caixa de suporte, mas não fica automaticamente guardada no histórico do pedido — para ficar, escreva-a no Lapispro.',
                        'A referência de um pedido serve para o identificarmos, e não é uma credencial: não dá acesso ao pedido nem substitui a autenticação.',
                        'Pedimos-lhe que não inclua no pedido nomes de alunos, dados de saúde ou outros dados pessoais desnecessários. Se os incluir, tratamo-los apenas para lhe responder, e eliminamo-los com o pedido nos prazos indicados em «Durante quanto tempo».',
                    ],
                ],
                [
                    'heading' => 'Categorias especiais de dados',
                    'body' => [
                        'O Lapispro não foi concebido para tratar categorias especiais de dados — saúde, origem racial ou étnica, convicções, e as restantes categorias que o Regulamento protege de forma reforçada.',
                        'Não existe na aplicação qualquer campo que peça esse tipo de informação. O modelo de dados do aluno não tem campos de saúde nem de necessidades educativas especiais, e isso é uma decisão deliberada, não uma omissão.',
                        'Os campos de texto livre — registos de acompanhamento, observações, secções de relatório — aceitam o que o professor lá escrever. Pedimos que não escreva neles informação de saúde ou de outra categoria especial sem ter fundamento para a tratar, e que registe apenas o que é necessário à finalidade pedagógica.',
                        'As medidas de suporte à aprendizagem que o Lapispro permite registar descrevem a ação do professor e o seu enquadramento pedagógico. Não afirmam nem inferem o estatuto formal do aluno: essa é uma determinação que compete à escola, e a aplicação não a faz por si.',
                    ],
                ],
                [
                    'heading' => 'Dados de menores',
                    'body' => [
                        'A maioria dos alunos cujos dados são tratados no Lapispro são menores de idade, e o tratamento é feito com esse pressuposto em todas as decisões técnicas do produto.',
                        'O Lapispro não é oferecido a menores nem a encarregados de educação. Os alunos não criam conta, não iniciam sessão e não acedem à aplicação; os seus dados são introduzidos e geridos através da conta do professor. Por isso não recolhemos nem verificamos consentimento parental: não é sobre um consentimento dado ao Lapispro que este tratamento assenta.',
                        'Cabe ao responsável pelo tratamento — o professor que exerça a atividade a título próprio, ou a escola ou agrupamento sob cuja autoridade ele atue — assegurar que existe fundamento legítimo para tratar os dados dos alunos no exercício da atividade educativa, e que apenas são introduzidos os dados necessários.',
                        'Do lado do Lapispro, aplicamos minimização — a aplicação trabalha com um pseudónimo e não com o nome —, cifragem da identidade, isolamento entre organizações e controlo de acesso verificado no servidor.',
                        'Pedidos relativos aos dados de um aluno devem ser dirigidos ao responsável pelo tratamento — o professor ou a escola, consoante o caso. Se nos chegar um pedido desses, encaminhamo-lo e prestamos a assistência que nos for pedida.',
                    ],
                ],
                [
                    'heading' => 'Inteligência artificial',
                    'body' => [
                        'A IA do Lapispro sugere. O professor decide. Nenhuma classificação é atribuída, alterada ou decidida por um modelo, nenhuma estratégia é aplicada automaticamente, nenhuma decisão pedagógica é gravada por uma sugestão, e o que a IA devolve é sempre revisto por quem o pediu antes de ter qualquer efeito.',
                        'Quando disponível, a IA é usada para apoiar seis coisas: responder a perguntas sobre a utilização da aplicação a partir dos artigos do Centro de Ajuda; interpretar em palavras a estatística de uma turma; interpretar os resultados de um período; organizar o percurso de um aluno numa síntese de acompanhamento; propor estratégias pedagógicas; e aperfeiçoar a redação de uma secção de relatório já composta.',
                        'HÁ DUAS SITUAÇÕES DIFERENTES, e o Lapispro pode prometer coisas diferentes sobre cada uma. A primeira é o contexto que a aplicação monta sozinha a partir dos seus dados. A segunda é o texto que o professor escreve à mão num campo destinado à IA. O que se segue distingue-as, porque o grau de garantia não é o mesmo.',
                        'A) NO CONTEXTO QUE O LAPISPRO MONTA, informação pedagógica pode ser enviada ao fornecedor de IA. Conforme a funcionalidade, isso pode incluir resultados já calculados pelo Lapispro, classificações e outros resultados estruturados quando são o objeto da análise, os nomes dos domínios e critérios de avaliação, indicadores de evolução e de tendência, a autoavaliação registada por um aluno em forma estruturada, e informação estruturada sobre estratégias e intervenções. Sem esses dados, estas funcionalidades não teriam objeto.',
                        'Nesse contexto NÃO SÃO INCLUÍDOS nomes reais de alunos, endereços de correio eletrónico, números de aluno e números de processo, contactos telefónicos, moradas, dados de encarregados de educação, nem identificadores internos da aplicação como ULID ou UUID. A identidade da escola e o nome do professor também não são incluídos quando não são necessários ao caso de uso — e não são necessários em nenhum deles.',
                        'TAMBÉM NÃO É INCLUÍDO, NESTA VERSÃO, o texto livre que o professor escreve sobre um aluno: a descrição de um registo de acompanhamento, o objetivo e a descrição de uma intervenção, e observações equivalentes. É nesses campos que, na prática, pode aparecer informação sensível — saúde, acompanhamento clínico, necessidades específicas, contexto familiar ou social — e por isso a síntese de acompanhamento recebe em vez disso categorias, estados e contagens. É uma limitação deliberada da funcionalidade.',
                        'COMO É PREPARADO ESSE CONTEXTO. Cada funcionalidade declara, campo a campo, o que envia: nada é incluído por ser o conteúdo de um registo, apenas por ter sido nomeado. Os nomes de alunos que o Lapispro conhece são substituídos por designações genéricas — «Aluno A», «Aluno B» — no momento em que cada valor entra, e essa correspondência não é estável entre pedidos. Sobre o texto já montado corre depois uma limpeza que retira endereços de correio eletrónico, telefones, códigos postais, endereços de internet, números escritos na forma «n.º 12», identificadores internos e séries longas de algarismos. Só então o pedido é feito.',
                        'Isto é pseudonimização e minimização, não anonimização: o professor, que tem a pauta à frente, reconhece a que aluno corresponde cada linha; quem recebe os dados do lado do fornecedor não o consegue.',
                        'B) NO TEXTO QUE O PROFESSOR ESCREVE À MÃO — a pergunta ao assistente, o objetivo de uma sugestão de estratégia, o corpo de uma secção de relatório — o Lapispro não pode garantir o mesmo, porque não controla o que lá é escrito. Nesses campos mostramos um aviso a pedir que não sejam introduzidos nomes, contactos nem outros dados pessoais dos alunos.',
                        'Antes de enviar, o Lapispro procura nesse texto padrões objetivamente identificáveis — endereços de correio eletrónico, contactos telefónicos, códigos postais, números escritos na forma «n.º 12», identificadores internos e séries longas de algarismos. Quando encontra algum, o pedido não segue: é apresentada uma confirmação, o texto não é alterado, e continuar passa a ser uma escolha explícita de quem escreveu. Essa verificação é feita no seu navegador e não recorre a nenhum serviço externo.',
                        'ESSA DETEÇÃO REDUZ O RISCO E NÃO O ELIMINA. Reconhece formatos, não pessoas. Um nome próprio escrito por extenso não tem um formato que o distinga de qualquer outra palavra, e por isso não é detetado — nem prometemos que seja. Nas páginas onde a aplicação já tem o nome do aluno à frente por outra razão, esse nome concreto é procurado também; fora dessas, e no Centro de Ajuda, não é procurado nome nenhum, porque ir buscar dados de alunos para verificar um texto seria pior do que o problema que resolve.',
                        'O aperfeiçoamento da redação de um relatório tem, além do descrito acima, uma proteção própria e mais restritiva, porque aí a IA não precisa de saber número nenhum: o que sai é o texto de uma única secção, com os números e as datas dessa secção substituídos por marcadores, e sem os restantes dados do relatório. O que volta é comparado com o que saiu e é recusado se algum valor tiver sido alterado. Esta proteção é dessa funcionalidade e não descreve as restantes.',
                        'O assistente do Centro de Ajuda não recebe dados pedagógicos de espécie alguma: o que sai é a pergunta escrita e os artigos de documentação relevantes.',
                        'Não existem decisões automatizadas com efeitos jurídicos ou significativos sobre uma pessoa: nenhum resultado de um modelo produz por si só um efeito na avaliação de um aluno.',
                        'O QUE O LAPISPRO GUARDA SOBRE UM PEDIDO DE IA: que houve um pedido, de que funcionalidade, que motor respondeu, quanto custou em tokens, quanto tempo demorou e como terminou. Não guarda o texto da pergunta nem o da resposta — não existe sequer coluna onde isso coubesse. Não há memória entre pedidos nem histórico de conversa, e nenhum resultado de IA é gravado automaticamente como decisão pedagógica.',
                        'O que um fornecedor de IA faça com o conteúdo do seu lado — se o conserva, por quanto tempo, e para quê — depende do fornecedor e do contrato, e não é algo que possamos afirmar antes de existir um. Será descrito nesta página quando um for escolhido, antes de qualquer tratamento real.',
                        'Os dados não são usados pelo Lapispro para treinar modelos.',
                        'As funcionalidades de IA podem estar desativadas. Quando não existe um motor configurado, a aplicação funciona na mesma e as opções de IA não ficam disponíveis.',
                        'Não está atualmente identificado um fornecedor de IA, e nesta data nenhum dado é enviado para um. Quando existir, será identificado nesta página como subcontratante antes de qualquer tratamento real, e a sua localização será indicada.',
                    ],
                ],
                [
                    'heading' => 'Durante quanto tempo',
                    'body' => [
                        'Indicam-se abaixo os prazos que a aplicação cumpre efetivamente, de forma automática. Onde ainda não existe um prazo aplicado por uma rotina, esta página não afirma um.',
                        'Conta ativa: os dados da conta são conservados enquanto a conta existir.',
                        'Pedido de encerramento de conta: existe um período de recuperação de '.self::closureDays().' dias, durante o qual pode cancelar o pedido. Terminado esse período, uma rotina diária remove os dados que o identificam a si, e com eles os dados identificativos dos alunos da sua organização pessoal — é aqui, e só aqui, que há anonimização no sentido próprio: a relação com a pessoa deixa de existir e não pode ser reposta. Os dados de uma organização institucional de que seja apenas membro não são afetados: pertencem à instituição.',
                        'Exportações dos seus dados: ficam disponíveis '.self::exportHours().' horas e são depois eliminadas automaticamente, por uma rotina que corre de hora a hora.',
                        'Ficheiros temporários de importações e exportações não confirmadas: eliminados automaticamente algumas horas depois do último toque, também de hora a hora.',
                        'Pedidos de suporte à espera de resposta sua: enviamos um lembrete ao fim de '.self::supportReminderDays().' dias e, se não houver resposta, o pedido é encerrado automaticamente ao fim de '.self::supportAutoResolveDays().' dias. Um pedido que esteja do nosso lado, à espera que lhe respondamos, não é encerrado por inatividade.',
                        'Pedidos de suporte encerrados: o conteúdo completo — o que escreveu, as respostas e o seu nome e endereço de email — é conservado durante '.self::supportRetainedMonths().' meses a contar do encerramento. Findo esse prazo, uma rotina diária elimina as mensagens e coloca a nulo o nome, o endereço de email e o conteúdo do pedido; permanece apenas informação que não permite identificá-lo — a referência, o assunto escolhido da lista, o estado e as datas —, para sabermos quantos pedidos de cada tipo existiram. Se responder a um pedido já encerrado, ele reabre e a contagem só recomeça quando voltar a ser encerrado.',
                        'Registos técnicos do servidor: rotação automática de 14 dias.',
                        'Cópias de segurança da base de dados: rotação de 30 cópias diárias e 12 mensais. Um dado eliminado da aplicação pode subsistir numa cópia de segurança até essa cópia ser substituída pela rotação.',
                        'Registo de atividade: conservado enquanto for necessário como registo de segurança de quem fez o quê. Não está definido um prazo automático de eliminação, e por isso não indicamos um.',
                        'Dados pedagógicos de anos letivos anteriores: não existe hoje eliminação automática por antiguidade. Quando existir, o prazo será indicado aqui antes de ser aplicado.',
                        'Suspensão excecional de um prazo: a eliminação de um pedido de suporte pode ser suspensa quando a conservação seja necessária ao cumprimento de uma obrigação legal, à investigação de fraude, abuso ou incidente de segurança, ou à declaração, ao exercício ou à defesa de direitos. A suspensão é decidida por um administrador da plataforma, o motivo é registado, e o acesso continua limitado a quem já o tinha. Cessado o fundamento, a suspensão é levantada e o pedido é eliminado ou anonimizado no ciclo seguinte, contado a partir do encerramento e não da data em que a suspensão terminou.',
                    ],
                ],
                [
                    'heading' => 'Os seus direitos',
                    'body' => [
                        'Sobre os dados da sua conta, os direitos exercem-se perante a '.self::entity().', escrevendo para '.self::contact('privacy_email').'.',
                        'Acesso e retificação: pode consultar e corrigir os seus dados diretamente na aplicação, e pedir-nos o que não conseguir fazer por si.',
                        'Apagamento: pode pedir o encerramento da conta a partir das definições, com o efeito e o prazo descritos acima.',
                        'Limitação, oposição e portabilidade: não existe um mecanismo automático na aplicação; exercem-se pelo mesmo endereço. Quando a portabilidade se aplique, entregamos os dados abrangidos em formato estruturado e de uso corrente.',
                        'A exportação disponível dentro da aplicação é uma funcionalidade do produto, pensada para lhe devolver o seu trabalho e permitir reimportá-lo. É útil para exercer estes direitos, mas não se confunde com eles nem esgota o direito de portabilidade, cujo âmbito é definido pela lei e não por nós.',
                        'Sobre os dados dos alunos, os direitos dos titulares exercem-se perante o responsável pelo tratamento — o professor ou a escola, consoante o caso. O Lapispro presta a assistência necessária para lhes dar resposta, nos termos do Acordo de Tratamento de Dados.',
                        'Tem o direito de apresentar reclamação junto da autoridade de controlo. Em Portugal, é a Comissão Nacional de Proteção de Dados (CNPD).',
                    ],
                ],
                [
                    'heading' => 'Segurança',
                    'body' => [
                        'Todo o acesso é feito por ligação cifrada (HTTPS).',
                        'Os dados de identificação dos alunos são cifrados na base de dados; o resto da aplicação usa um pseudónimo.',
                        'Cada organização só acede aos seus próprios dados, e essa separação é imposta no servidor, não apenas escondida na interface.',
                        'Todas as autorizações são verificadas no servidor. Esconder um botão nunca é o controlo de acesso.',
                        'As palavras-passe não são guardadas: fica apenas uma representação criptográfica irreversível. Estão disponíveis autenticação em dois passos e chaves de acesso.',
                        'Existem cópias de segurança regulares da base de dados e um registo de atividade.',
                        'Nenhum sistema é totalmente seguro, e não prometemos segurança absoluta. Procuramos reduzir o risco e responder com rapidez a qualquer incidente.',
                    ],
                ],
                [
                    'heading' => 'Cookies e armazenamento no navegador',
                    'body' => [
                        'A auditoria técnica ao produto encontrou apenas cookies estritamente necessários e de funcionamento. Não há cookies de publicidade, de marketing ou de análise de tráfego, e não é carregado qualquer serviço de terceiros no seu navegador — os tipos de letra são servidos do nosso próprio domínio.',
                        'Cookie de sessão: mantém a sua autenticação entre páginas.',
                        'Cookie de proteção contra falsificação de pedidos (CSRF): protege os formulários.',
                        'Preferências de interface: o tema claro/escuro e o estado do menu lateral.',
                        'Além de cookies, o Lapispro guarda no armazenamento local do seu navegador a preferência de tema e rascunhos de grelhas de correção ainda por submeter. Os rascunhos ficam apenas no seu equipamento, nunca são enviados por si só, e são removidos quando a grelha é guardada.',
                        'Não é apresentado pedido de consentimento porque não identificámos nenhuma finalidade que o exija. Esta é a descrição do que a auditoria encontrou; a qualificação jurídica de cada um destes elementos está sujeita a validação, e se dela resultar que algum exige consentimento, passará a ser pedido.',
                    ],
                ],
                [
                    'heading' => 'Subcontratantes',
                    'body' => [
                        'Recorremos a terceiros para prestar o serviço. Indicamos abaixo apenas aqueles cuja utilização podemos confirmar; nenhum outro é nomeado por suposição.',
                        'Contabo GmbH — alojamento do servidor onde a aplicação, a base de dados, os ficheiros e as cópias de segurança residem. É uma sociedade constituída na Alemanha.',
                        'Cloudflare, Inc. — sistema de nomes de domínio, entrega de rede e terminação de tráfego cifrado à frente do domínio. É uma sociedade constituída nos Estados Unidos da América e opera uma rede distribuída por vários países.',
                        'Envio de email transacional (verificação de conta, recuperação de palavra-passe): feito através de um servidor de correio configurado pelo operador da plataforma. Não nomeamos aqui o fornecedor por não o podermos confirmar nesta data; será indicado quando estiver verificado.',
                        'Não está configurado qualquer fornecedor de inteligência artificial, pelo que nenhum dado é enviado para um.',
                        'Não vendemos nem cedemos dados pessoais a terceiros.',
                    ],
                ],
                [
                    'heading' => 'Transferências internacionais',
                    'body' => [
                        'A aplicação, a base de dados, os ficheiros e as cópias de segurança residem num único servidor, alojado por uma sociedade constituída na Alemanha.',
                        'O tráfego passa pela rede da Cloudflare, uma sociedade constituída nos Estados Unidos da América, que opera pontos de presença em vários países. Não podemos, nesta data, afirmar que todo o tratamento por essa via ocorre exclusivamente no Espaço Económico Europeu.',
                        'A avaliação formal destas transferências, e a identificação dos mecanismos jurídicos que as enquadram, está em curso e será refletida nesta página. Enquanto não estiver concluída, preferimos dizê-lo a afirmar uma localização que não podemos comprovar.',
                    ],
                ],
                [
                    'heading' => 'Alterações a esta Política',
                    'body' => [
                        'Esta Política pode ser atualizada. A data de entrada em vigor da versão atual está indicada no início desta página.',
                        'Quando a alteração for material, procuraremos avisar através da aplicação ou por email antes de produzir efeitos.',
                    ],
                ],
            ],
        ];
    }

    /**
     * O acordo de tratamento — o professor como responsável, o Lapispro como
     * subcontratante dos dados dos alunos.
     *
     * DELIBERADAMENTE LEVE. Não é um contrato negociado com um cliente
     * empresarial: é o enquadramento de um professor que criou conta sozinho e
     * vai lá pôr os alunos da sua turma. Um documento de vinte páginas com
     * cláusulas de auditoria presencial não seria mais protetor — seria apenas
     * menos lido, e o que ninguém lê não protege ninguém.
     *
     * @return array{title: string, effective_from: string, intro: string, sections: list<array{heading: string, body: list<string>}>, related: list<array{label: string, href: string}>}
     */
    public static function processing(): array
    {
        return [
            'title' => 'Acordo de Tratamento de Dados',
            'effective_from' => (string) config('lapis.legal.processing_effective_from'),
            'intro' => 'Quando são registados alunos no Lapispro, as finalidades desses dados são determinadas pelo responsável pelo tratamento, e o Lapispro trata-os por conta dele. Este documento descreve as condições dessa relação. É deliberadamente curto, porque um acordo que ninguém lê não protege ninguém.',
            'related' => [
                ['label' => 'Termos de Utilização', 'href' => '/termos'],
                ['label' => 'Política de Privacidade', 'href' => '/privacidade'],
            ],
            'sections' => [
                [
                    'heading' => 'Para que serve este documento',
                    'body' => [
                        'O Regulamento Geral sobre a Proteção de Dados exige que, quando alguém trata dados pessoais por conta de outra pessoa, exista um acordo escrito a fixar o que pode e não pode fazer com eles. É isso que este documento é.',
                        'Faz parte integrante dos Termos de Utilização e aplica-se a partir do momento em que introduz no Lapispro dados relativos a um aluno.',
                        'Não precisa de assinar nem devolver nada: aceitá-lo faz parte de aceitar os Termos.',
                    ],
                ],
                [
                    'heading' => 'Quem é quem',
                    'body' => [
                        'Quem introduz dados de alunos no Lapispro deve fazê-lo enquanto responsável pelo tratamento ou devidamente autorizado pelo responsável pelo tratamento competente.',
                        'Isto abrange duas situações, e este acordo aplica-se às duas. O professor que exerce a atividade a título próprio e determina ele mesmo as finalidades do tratamento é o responsável. O professor que atua sob a autoridade de uma escola ou agrupamento trata os dados no âmbito dessa autoridade, e o responsável é a instituição.',
                        'A '.self::entity().' é a subcontratante em qualquer das situações: quando trata esses dados apenas para prestar o serviço, atua por conta do responsável pelo tratamento e não para finalidades próprias. Não determina as finalidades pedagógicas nem define a base jurídica aplicável.',
                        'A utilização do Lapispro por escolas e agrupamentos, com vários professores sob uma administração comum, ainda não está disponível para adesão, e será enquadrada em documento próprio quando estiver. Até lá, este acordo é o enquadramento do acesso feito através de uma conta individual.',
                    ],
                ],
                [
                    'heading' => 'Objeto, duração e natureza do tratamento',
                    'body' => [
                        'Objeto: a prestação do Lapispro enquanto plataforma de gestão de turmas, avaliação e acompanhamento pedagógico.',
                        'Natureza e finalidade: recolher, guardar, organizar, calcular, consultar e devolver os dados registados através da conta, exclusivamente para prestar o serviço contratado.',
                        'Quando as funcionalidades assistidas por inteligência artificial estiverem ativas e forem utilizadas, isso inclui ainda transmitir a um subcontratante de IA um subconjunto pseudonimizado e minimizado desses dados, apenas o necessário ao pedido feito e apenas no momento em que o professor o faz. O que pode e não pode integrar esse subconjunto está descrito na secção «Inteligência artificial» da Política de Privacidade, que faz parte deste enquadramento. Nesta data não está configurado qualquer fornecedor de IA e nenhum dado é transmitido por essa via.',
                        'Duração: enquanto a conta existir, e depois durante o período de recuperação previsto nos Termos.',
                        'Titulares dos dados: os alunos cujos dados sejam registados através da conta.',
                        'Categorias de dados: identificação do aluno (nome e, quando indicado, número de processo), eventualmente data de nascimento e fotografia, e dados pedagógicos — turmas e inscrições, classificações e resultados, instrumentos de avaliação, registos de acompanhamento, autoavaliações, estratégias e medidas, e relatórios.',
                    ],
                ],
                [
                    'heading' => 'As suas instruções',
                    'body' => [
                        'Os dados dos alunos são tratados apenas de acordo com as instruções recebidas através da conta, dadas em nome do responsável pelo tratamento. Essas instruções são, na prática, aquilo que é feito na aplicação e o que estes documentos preveem.',
                        'Não usamos os dados dos alunos para finalidades próprias, não os cruzamos com dados de outra organização, não os usamos para publicidade nem para criar perfis comerciais, e não os usamos para desenvolver ou treinar modelos de inteligência artificial.',
                        'Se alguma vez formos obrigados por lei a tratar esses dados de outra forma, informamo-lo antes de o fazer, salvo se a própria lei o proibir.',
                    ],
                ],
                [
                    'heading' => 'Confidencialidade',
                    'body' => [
                        'O acesso aos dados está limitado a quem precisa dele para operar e manter o serviço, e essas pessoas estão vinculadas a um dever de confidencialidade.',
                        'A aplicação permite que um operador da plataforma aceda a uma conta para prestar assistência. Esse acesso fica registado no registo de atividade, identificado como tal.',
                    ],
                ],
                [
                    'heading' => 'Segurança',
                    'body' => [
                        'Aplicamos as medidas descritas na secção «Segurança» da Política de Privacidade: ligação cifrada, cifragem da identidade do aluno na base de dados, pseudonimização no resto da aplicação, isolamento entre organizações imposto no servidor, autorizações verificadas no servidor, palavras-passe guardadas apenas como representação irreversível, autenticação em dois passos e chaves de acesso disponíveis, cópias de segurança regulares e registo de atividade.',
                        'Estas medidas podem evoluir. Qualquer alteração manterá um nível de proteção equivalente ou superior.',
                    ],
                ],
                [
                    'heading' => 'Outros subcontratantes',
                    'body' => [
                        'Recorremos a outros prestadores para alojamento, entrega de rede e envio de email. Estão identificados na secção «Subcontratantes» da Política de Privacidade, que é a lista em vigor. Um fornecedor de inteligência artificial seria um subcontratante da mesma natureza, sujeito às mesmas regras; nesta data não existe nenhum configurado, e a sua identificação nessa lista precederá qualquer tratamento real por essa via.',
                        'Ao aceitar este acordo autoriza-nos, de forma geral, a recorrer a esses prestadores e a substituí-los. Quando um for acrescentado ou substituído, a lista é atualizada nessa página e pode opor-se, cessando a utilização do serviço se a alteração não lhe convier.',
                        'Cada prestador fica vinculado a obrigações de proteção de dados não menos exigentes do que as deste acordo, e continuamos responsáveis perante si pelo que fizerem.',
                    ],
                ],
                [
                    'heading' => 'Apoio ao responsável pelo tratamento',
                    'body' => [
                        'Se um aluno, ou quem o representa, exercer um direito perante o responsável pelo tratamento — acesso, retificação, apagamento, limitação, oposição ou portabilidade — prestamos a assistência razoável para lhe dar resposta. Boa parte pode ser feita diretamente na aplicação: consultar, corrigir e exportar.',
                        'Se recebermos um pedido desses dirigido a nós, encaminhamo-lo para o titular da conta e não respondemos em lugar do responsável pelo tratamento.',
                        'Prestamos também a assistência razoável no que respeite à segurança do tratamento, à notificação de violações de dados e a eventuais avaliações de impacto, na medida da informação de que dispomos.',
                    ],
                ],
                [
                    'heading' => 'Violações de dados pessoais',
                    'body' => [
                        'Se tomarmos conhecimento de uma violação de dados pessoais que afete dados de alunos registados através da sua conta, informamo-lo sem demora injustificada, com a informação de que dispusermos: o que aconteceu, que dados foram afetados, que consequências prováveis identificámos e que medidas tomámos.',
                        'A notificação à autoridade de controlo e, quando for o caso, aos titulares, compete ao responsável pelo tratamento. Damos a informação necessária para o poder fazer.',
                    ],
                ],
                [
                    'heading' => 'Devolução e eliminação',
                    'body' => [
                        'Pode exportar os dados a partir da aplicação a qualquer momento, em qualquer plano.',
                        'Quando encerra a conta, decorrido o período de recuperação de '.self::closureDays().' dias previsto nos Termos, os dados identificativos dos alunos da sua organização pessoal são eliminados por uma rotina automática.',
                        'Um dado eliminado da aplicação pode subsistir numa cópia de segurança até essa cópia ser substituída pela rotação descrita na Política de Privacidade. Durante esse intervalo continua sujeito às mesmas medidas de segurança.',
                    ],
                ],
                [
                    'heading' => 'Informação e verificação',
                    'body' => [
                        'Disponibilizamos a informação necessária para demonstrar o cumprimento das obrigações deste acordo, mediante pedido razoável dirigido a '.self::contact('privacy_email').'.',
                        'Não estão previstas auditorias presenciais à infraestrutura por titulares de contas individuais: a infraestrutura é partilhada por todas as contas, e um acesso desses exporia os dados das outras.',
                    ],
                ],
                [
                    'heading' => 'Transferências',
                    'body' => [
                        'Aplica-se o descrito na secção «Transferências internacionais» da Política de Privacidade, incluindo o que aí se diz sobre o que ainda não podemos afirmar.',
                    ],
                ],
                [
                    'heading' => 'Alterações a este acordo',
                    'body' => [
                        'Este acordo pode ser atualizado. A data de entrada em vigor da versão atual está indicada no início desta página.',
                        'Quando a alteração for material, procuraremos avisar através da aplicação ou por email antes de produzir efeitos.',
                    ],
                ],
            ],
        ];
    }
}
