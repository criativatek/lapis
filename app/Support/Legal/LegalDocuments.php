<?php

namespace App\Support\Legal;

/**
 * ⚠️ TEXTO INICIAL TÉCNICO/FACTUAL — REQUER REVISÃO JURÍDICA ANTES DA
 * DIVULGAÇÃO A UTILIZADORES REAIS.
 *
 * Cada frase aqui foi escrita a partir do que o código faz, verificado no
 * código, e não a partir de um modelo de termos genérico. Onde o produto não
 * permite concluir com segurança — a base legal de um tratamento, os
 * subprocessadores, a lei aplicável — o texto diz que está por definir em vez
 * de afirmar. Ver `docs/legal.md` para a lista dos pontos que dependem de
 * validação jurídica.
 *
 * A identidade do responsável esteve nessa lista até 2026-08-27 e já não está:
 * está confirmada em `config('lapis.legal.*')` e é apresentada por extenso.
 *
 * ESTÁ EM PHP E NÃO NOS COMPONENTES VUE por uma razão prática: o SSR do
 * Inertia está desligado, pelo que texto escrito dentro de um `.vue` não
 * chega à resposta e nenhum teste de servidor lhe pode tocar. Daqui, o
 * conteúdo viaja no payload do Inertia — que está no HTML — e
 * `LegalDocumentsTest` consegue afirmar que a secção de menores existe, que
 * nenhum placeholder é apresentado como facto, e que a descrição da IA não
 * promete o que o produto não faz.
 *
 * O QUE NUNCA DEVE ENTRAR AQUI: um fornecedor que não esteja verificado, uma
 * base legal que ninguém validou, um prazo legal específico, uma certificação,
 * um DPO, ou uma promessa de segurança absoluta.
 */
class LegalDocuments
{
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

    /**
     * @return array{title: string, effective_from: string, intro: string, sections: list<array{heading: string, body: list<string>}>}
     */
    public static function terms(): array
    {
        return [
            'title' => 'Termos de Utilização',
            'effective_from' => (string) config('lapis.legal.terms_effective_from'),
            'intro' => 'Estes Termos regulam a utilização do LÁPIS. Ao criar uma conta, está a aceitar o que aqui se descreve. Foram escritos para serem lidos: onde uma regra tem uma consequência prática, ela está dita por extenso.',
            'sections' => [
                [
                    'heading' => 'O que é o LÁPIS',
                    'body' => [
                        'O LÁPIS é um serviço disponibilizado pela '.(self::setting('controller_name') ?? 'entidade responsável indicada no fim desta página').'.',
                        'O LÁPIS — Laboratório de Apoio ao Professor, Informação e Simplificação — é uma plataforma para professores que reúne a gestão de turmas e alunos, os critérios e instrumentos de avaliação, o cálculo e a decisão de classificações, o acompanhamento pedagógico, as aulas e sumários, e os relatórios.',
                        'Destina-se a professores e a instituições de ensino. Não é uma plataforma para alunos nem para encarregados de educação: não existe registo, sessão ou acesso próprio para estes.',
                    ],
                ],
                [
                    'heading' => 'A sua conta',
                    'body' => [
                        'A conta é pessoal. Ao criá-la, é gerada a sua organização pessoal — o espaço onde o seu trabalho fica isolado do de qualquer outra pessoa.',
                        'Pode também pertencer a organizações institucionais, criadas por uma escola ou agrupamento. Nesse caso os dados aí registados pertencem à instituição, não à sua conta, e continuam a existir independentemente dela.',
                        'É responsável por manter as suas credenciais em segurança e por tudo o que for feito através da sua conta. O LÁPIS disponibiliza autenticação em dois passos e chaves de acesso (passkeys); recomendamos que use pelo menos uma delas.',
                        'Os dados que indica ao criar a conta devem ser verdadeiros e atuais.',
                        'Se detetar uma utilização indevida da sua conta, ou precisar de ajuda com o acesso, contacte '.self::contact('accounts_email').'.',
                    ],
                ],
                [
                    'heading' => 'Utilização aceitável',
                    'body' => [
                        'Compromete-se a utilizar o LÁPIS apenas para fins legítimos relacionados com o seu trabalho pedagógico.',
                        'Não deve tentar aceder a dados de outra organização, contornar os controlos de acesso, sobrecarregar deliberadamente o serviço, nem introduzir dados pessoais de terceiros sem fundamento legítimo para o fazer.',
                    ],
                ],
                [
                    'heading' => 'Dados pedagógicos e decisões',
                    'body' => [
                        'O LÁPIS calcula, organiza e propõe. Não decide.',
                        'A classificação final é sempre uma decisão do professor. O sistema apresenta uma proposta a partir do perfil de avaliação e do que está registado; a confirmação é sua, e se decidir de forma diferente da proposta, a diferença e a razão ficam registadas.',
                        'Quando as funcionalidades de inteligência artificial estão disponíveis, sugerem — nunca decidem, nunca atribuem ou alteram uma classificação, e nunca aplicam sozinhas uma estratégia. Cada sugestão é aceite, adaptada ou descartada por si.',
                        'A responsabilidade pedagógica pelo que regista e pelo que decide continua a ser sua e, quando aplicável, da sua instituição.',
                    ],
                ],
                [
                    'heading' => 'Disponibilidade e evolução',
                    'body' => [
                        'O LÁPIS é um serviço em evolução. Funcionalidades podem ser acrescentadas, alteradas ou substituídas, e o serviço pode ser interrompido para manutenção.',
                        'Procuramos manter o serviço disponível e avisar de interrupções planeadas, mas não garantimos disponibilidade ininterrupta.',
                        'Pode a qualquer momento exportar os seus dados a partir da aplicação.',
                    ],
                ],
                [
                    'heading' => 'Planos e condições comerciais',
                    'body' => [
                        'Existem três planos: LÁPIS Base, LÁPIS Pro e LÁPIS Institucional. O que os distingue são as funcionalidades disponíveis e, no caso do Base, alguns limites quantitativos.',
                        'Criar conta dá acesso ao plano Base. Pode estar disponível um período experimental do plano Pro, por tempo limitado e sem obrigação de pagamento.',
                        'Podem ser atribuídos códigos que dão acesso a condições especiais. As condições comerciais em vigor, incluindo preços e eventuais campanhas de lançamento, são as apresentadas na página pública no momento da adesão.',
                        'Terminar um plano superior não elimina dados: as funcionalidades correspondentes deixam de estar disponíveis e a informação já registada continua a existir, nos termos da Política de Privacidade.',
                    ],
                ],
                [
                    'heading' => 'Encerramento da conta',
                    'body' => [
                        'Pode pedir o encerramento da sua conta a partir das definições.',
                        'O pedido inicia um período de recuperação, durante o qual pode cancelá-lo e a conta volta ao normal. Durante esse período, a conta fica limitada a leitura e à exportação dos seus dados.',
                        'Terminado esse período, o encerramento é executado: os dados que o identificam a si são removidos, as credenciais deixam de permitir autenticação, e os dados identificativos dos alunos da sua organização pessoal são eliminados.',
                        'Não prometemos eliminação instantânea. Alguma informação é conservada quando é necessária à integridade dos registos ou ao registo de atividade — nesses casos deixa de estar associada a uma pessoa identificável. A Política de Privacidade descreve isto em detalhe.',
                        'O encerramento da sua conta não elimina dados de uma organização institucional de que seja apenas membro: esses pertencem à instituição.',
                    ],
                ],
                [
                    'heading' => 'Propriedade intelectual',
                    'body' => [
                        'O software, a marca e a apresentação do LÁPIS pertencem ao seu titular. Estes Termos não transferem qualquer direito sobre eles.',
                        'O conteúdo que introduz — critérios, registos, textos, relatórios — continua a ser seu ou da sua instituição. O LÁPIS trata-o apenas para lhe prestar o serviço.',
                    ],
                ],
                [
                    'heading' => 'Limitação de responsabilidade',
                    'body' => [
                        'O LÁPIS é uma ferramenta de apoio. Não substitui o julgamento profissional do professor nem as obrigações da instituição de ensino.',
                        'Na medida permitida pela lei aplicável, não respondemos por decisões pedagógicas tomadas com apoio da ferramenta, nem por perdas resultantes de utilização contrária a estes Termos.',
                        'Nada nestes Termos exclui responsabilidades que a lei não permita excluir.',
                    ],
                ],
                [
                    'heading' => 'Alterações a estes Termos',
                    'body' => [
                        'Estes Termos podem ser atualizados. A data de entrada em vigor da versão atual está indicada no início desta página.',
                        'Quando a alteração for material, procuraremos avisar através da aplicação ou por email antes de produzir efeitos.',
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array{title: string, effective_from: string, intro: string, sections: list<array{heading: string, body: list<string>}>}
     */
    public static function privacy(): array
    {
        return [
            'title' => 'Política de Privacidade',
            'effective_from' => (string) config('lapis.legal.privacy_effective_from'),
            'intro' => 'O LÁPIS trata dados pessoais de professores e de alunos — muitos deles menores. Esta página descreve que dados são tratados, para quê, durante quanto tempo, e o que pode exigir a qualquer momento.',
            'sections' => [
                [
                    'heading' => 'Responsável pelo tratamento',
                    'body' => [
                        'O LÁPIS é um serviço disponibilizado pela '.(self::setting('controller_name') ?? 'entidade indicada no fim desta página').'.',
                        'É essa entidade que responde pelos tratamentos descritos nesta página relativos à prestação do serviço. Quanto aos dados dos alunos, a repartição de responsabilidades entre o LÁPIS e a escola ou o professor que os introduz está sujeita a validação jurídica, e será precisada nesta página.',
                        'Os elementos completos de identificação — NIF e morada — estão no fim desta página.',
                        'Para exercer os seus direitos ou colocar qualquer questão sobre privacidade, escreva para '.self::contact('privacy_email').'.',
                    ],
                ],
                [
                    'heading' => 'Dados do professor',
                    'body' => [
                        'Nome e endereço de email, indicados por si ao criar a conta.',
                        'Dados de autenticação. A palavra-passe nunca é guardada, nem em texto legível nem cifrada: o que fica é uma representação criptográfica irreversível (hash), a partir da qual a palavra-passe não pode ser reconstituída. Se as ativar, ficam também os segredos de autenticação em dois passos e as suas chaves de acesso (passkeys).',
                        'A organização a que pertence, as suas configurações de avaliação e as suas preferências de interface.',
                        'O estado da sua conta e do seu plano, incluindo pedidos de encerramento e períodos experimentais.',
                    ],
                ],
                [
                    'heading' => 'Dados dos alunos',
                    'body' => [
                        'Os dados de identificação do aluno — nome e, quando indicado, número de processo — são guardados numa tabela separada e cifrados. O resto da aplicação trabalha com um pseudónimo, não com o nome.',
                        'Isto é pseudonimização, não anonimização: o professor continua a poder ver quem é cada aluno, porque precisa disso para trabalhar. O que se reduz é a exposição da identidade em tudo o resto. A anonimização — a remoção efetiva da relação com a pessoa — acontece no encerramento da conta, descrito mais abaixo.',
                        'Não é necessário anonimizar os alunos antes de os introduzir: a proteção é aplicada pelo LÁPIS.',
                        'Podem ainda existir data de nascimento e fotografia, quando o professor ou a instituição as introduzem.',
                        'Dados pedagógicos: turmas e inscrições, classificações e resultados por domínio, instrumentos de avaliação, registos de acompanhamento, autoavaliações, estratégias e medidas, e relatórios.',
                        'O aluno não tem conta, sessão nem acesso próprio ao LÁPIS. Os seus dados são introduzidos e geridos pelo professor ou pela instituição.',
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
                        'Ficheiros que carrega — pautas, fotografias, grelhas de correção, cópias de segurança para importação — e ficheiros que o LÁPIS gera, como relatórios e exportações dos seus dados.',
                        'Os ficheiros temporários de importações que não chegam a ser confirmadas são eliminados automaticamente, sem intervenção sua.',
                    ],
                ],
                [
                    'heading' => 'Para que usamos os dados',
                    'body' => [
                        'Prestar o serviço: autenticar a sua conta, organizar turmas e alunos, calcular e propor classificações, acompanhar a evolução dos alunos, produzir relatórios e organizar o trabalho letivo.',
                        'Segurança e auditoria: proteger as contas, detetar utilização indevida e manter um registo de quem fez o quê.',
                        'Cópias de segurança, para permitir a recuperação em caso de incidente.',
                        'Suporte, quando nos contacta através de '.self::contact('support_email').'.',
                        'Responder a pedidos de exportação ou de eliminação de dados.',
                        'Não usamos os dados para publicidade, para criar perfis comerciais, nem para os vender ou ceder a terceiros.',
                    ],
                ],
                [
                    'heading' => 'Fundamento do tratamento',
                    'body' => [
                        'O tratamento dos dados do professor assenta na execução do contrato de prestação do serviço e no cumprimento de obrigações legais aplicáveis.',
                        'Quanto aos dados dos alunos, o professor ou a instituição de ensino determinam a finalidade e devem dispor de fundamento legítimo para o seu tratamento no exercício da atividade educativa. O LÁPIS trata esses dados por conta de quem os introduz.',
                        'O enquadramento exato de cada tratamento, incluindo a repartição de responsabilidades entre o LÁPIS e a instituição, está sujeito a validação jurídica.',
                    ],
                ],
                [
                    'heading' => 'Inteligência artificial',
                    'body' => [
                        'A IA do LÁPIS sugere. O professor decide. Nenhuma classificação é atribuída, alterada ou decidida por um modelo, e nenhuma estratégia é aplicada automaticamente.',
                        'Quando disponível, é usada para ajudar a interpretar resultados que o LÁPIS já calculou, propor estratégias pedagógicas e aperfeiçoar a redação de um relatório já composto.',
                        'Antes de qualquer texto sair da aplicação, os nomes que o LÁPIS conhece são substituídos por designações genéricas e todos os números e datas por marcadores. Não são enviados o resto do relatório, a turma, a pauta, os resultados, as classificações, as autoavaliações, os registos, a identidade da escola nem o nome do professor. O que volta é verificado antes de ser apresentado, e é recusado se vier com um valor alterado.',
                        'As funcionalidades de IA podem estar desativadas. Quando não existe um motor configurado, a aplicação funciona na mesma e as opções de IA não ficam disponíveis.',
                        'Não está atualmente identificado um fornecedor de IA. Quando existir, será identificado nesta página como subprocessador antes de qualquer tratamento real.',
                    ],
                ],
                [
                    'heading' => 'Durante quanto tempo',
                    'body' => [
                        'Os dados são conservados pelo período necessário às finalidades do serviço e de acordo com as políticas aplicáveis a cada tipo de informação.',
                        'Pedido de encerramento de conta: existe um período de recuperação, durante o qual pode cancelar o pedido. Terminado esse período, os dados que o identificam a si são removidos, e com eles os dados identificativos dos alunos da sua organização pessoal — é aqui, e só aqui, que há anonimização no sentido próprio: a relação com a pessoa deixa de existir e não pode ser reposta. Os dados de uma organização institucional de que seja apenas membro não são afetados: pertencem à instituição.',
                        'Exportações dos seus dados: ficam disponíveis por um período curto e são depois eliminadas automaticamente.',
                        'Ficheiros temporários de importações não confirmadas: eliminados automaticamente pouco depois.',
                        'Registo de atividade: conservado por um período alargado, por ser o registo de segurança de quem fez o quê.',
                        'Cópias de segurança: conservadas em rotação por um período limitado e depois substituídas.',
                        'Os prazos concretos aplicáveis a cada categoria estão sujeitos a validação jurídica.',
                    ],
                ],
                [
                    'heading' => 'Os seus direitos',
                    'body' => [
                        'Acesso e retificação: pode consultar e corrigir os seus dados e os dados que introduziu diretamente na aplicação.',
                        'Portabilidade: pode exportar os seus dados a partir da aplicação, em qualquer plano, num ficheiro que pode guardar ou reimportar.',
                        'Eliminação: pode pedir o encerramento da conta a partir das definições, com o efeito descrito acima.',
                        'Limitação, oposição e quaisquer outros direitos aplicáveis: não existe um mecanismo automático na aplicação; exercem-se escrevendo para '.self::contact('privacy_email').'.',
                        'Tem também o direito de apresentar reclamação junto da autoridade de controlo competente.',
                    ],
                ],
                [
                    'heading' => 'Dados de menores',
                    'body' => [
                        'A maioria dos alunos cujos dados são tratados no LÁPIS são menores de idade, e o tratamento é feito com esse pressuposto.',
                        'Os alunos não criam contas nem acedem à aplicação. Os seus dados são introduzidos e geridos exclusivamente por professores e instituições autorizados.',
                        'Cabe ao professor e à instituição assegurar que existe fundamento legítimo para tratar esses dados e que apenas são introduzidos os dados necessários.',
                        'Do lado do LÁPIS, aplicamos minimização — a aplicação trabalha com um pseudónimo e não com o nome —, cifragem da identidade, isolamento entre organizações e controlo de acesso verificado no servidor.',
                        'O enquadramento aplicável ao tratamento de dados de menores está sujeito a validação jurídica.',
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
                        'Existem cópias de segurança regulares da base de dados e um registo de atividade imutável.',
                        'Nenhum sistema é totalmente seguro, e não prometemos segurança absoluta. Procuramos reduzir o risco e responder com rapidez a qualquer incidente.',
                    ],
                ],
                [
                    'heading' => 'Cookies e armazenamento no navegador',
                    'body' => [
                        'O LÁPIS usa apenas cookies estritamente necessários e de funcionamento. Não usa cookies de publicidade, de marketing ou de análise de tráfego, nem carrega serviços de terceiros no seu navegador — os tipos de letra são servidos do nosso próprio domínio.',
                        'Cookie de sessão: mantém a sua autenticação entre páginas.',
                        'Cookie de proteção contra falsificação de pedidos (CSRF): protege os formulários.',
                        'Preferências de interface: o tema claro/escuro e o estado do menu lateral.',
                        'Além de cookies, o LÁPIS guarda no armazenamento local do seu navegador a preferência de tema e rascunhos de grelhas de correção ainda por submeter. Os rascunhos ficam apenas no seu equipamento, nunca são enviados por si só, e são removidos quando a grelha é guardada.',
                        'Não identificámos cookies de finalidade não essencial, pelo que não é apresentado um pedido de consentimento. Esta avaliação está sujeita a validação jurídica.',
                    ],
                ],
                [
                    'heading' => 'Subprocessadores e terceiros',
                    'body' => [
                        'O LÁPIS recorre a serviços de terceiros para alojamento, entrega de rede e envio de email transacional. Estes serviços tratam dados por nossa conta e apenas para essas finalidades.',
                        'A lista identificada de subprocessadores, com as respetivas finalidades e localizações, está por publicar e será acrescentada a esta página. Enquanto isso não acontecer, não indicamos nomes que não possamos confirmar, nem afirmamos onde os dados são processados.',
                        'Não vendemos nem cedemos dados pessoais a terceiros.',
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
}
