<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A CENTRAL DE SUPORTE PASSA A TER CASA. Ver ADR-0011.
 *
 * Três tabelas, todas novas, nenhuma coluna existente alterada. Nada aqui
 * converte histórico: os emails que já foram trocados em caixas de correio
 * pessoais ficam onde estão — inventar-lhes um pedido seria fabricar um
 * registo que ninguém escreveu.
 *
 * FORA DA TENANCY, como `vouchers` e `founder_seats`. Um pedido de suporte é da
 * PESSOA e da plataforma, não do inquilino: `organization_id` existe para o
 * operador saber de onde veio, e **nunca** para decidir quem o pode ver. É a
 * única coluna deste domínio que se parece com tenancy e não é — ver a policy.
 *
 * AS COLUNAS IDENTIFICANTES NASCEM NULLABLE, E ISSO É O DESENHO. Passados 24
 * meses sobre a resolução, `AnonymiseSupportRequest` põe-nas a NULL de verdade
 * e apaga as mensagens e as entregas. Sem marcas de substituição: um
 * `anonimizado-…@invalido.local` é um valor, ocupa espaço numa listagem, e
 * alguém acaba por o ler como se fosse um facto (ADR-0011 §8). A
 * obrigatoriedade destes campos vive na fronteira — nas FormRequests —, que é
 * onde ela é verdadeira; no esquema, seria uma promessa que a retenção teria de
 * quebrar.
 */
return new class extends Migration
{
    /** Os quatro estados do ADR-0011 §2. Não existe `closed`. */
    protected const STATUSES = ['open', 'in_progress', 'waiting_for_user', 'resolved'];

    /** O que a pessoa escolhe no formulário. Pequeno de propósito. */
    protected const CATEGORIES = [
        'access', 'classes_students', 'assessment', 'reports', 'imports', 'billing', 'other',
    ];

    /** Classificação técnica interna, atribuída por um operador. Nasce NULL. */
    protected const TECHNICAL_CODES = [
        'auth_access', 'entitlement_limit', 'import_parse', 'assessment_rule',
        'report_generation', 'commercial', 'ai_service', 'data_question', 'unclassified',
    ];

    /** Por que razão a eliminação foi suspensa. Vocabulário fechado (ADR-0011 §9). */
    protected const HOLD_REASONS = [
        'legal_dispute', 'fraud_investigation', 'statutory_obligation', 'formal_proceeding', 'other',
    ];

    protected const AUTHOR_ROLES = ['requester', 'operator', 'system'];

    protected const SOURCES = ['guest', 'authenticated'];

    protected const NOTIFICATION_TYPES = [
        'request_received', 'request_replied', 'waiting_reminder', 'team_new_request',
    ];

    /**
     * Como a entrega falhou, classificado a partir da CLASSE da excepção e do
     * código SMTP numérico — nunca da mensagem. Ver `SupportDeliveryFailureCode`.
     */
    protected const FAILURE_CODES = [
        'smtp_connection', 'smtp_auth', 'invalid_recipient', 'rejected_by_server', 'unknown',
    ];

    public function up(): void
    {
        Schema::create('support_requests', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();

            /**
             * O número de protocolo: `SUP-XXXXXX`, no alfabeto legível sem
             * O/0/I/1 que a referência bancária e o código de voucher já usam.
             *
             * NÃO É UMA CREDENCIAL. É para a pessoa dizer ao telefone e para o
             * operador procurar — legível de propósito e, por isso mesmo,
             * adivinhável. Nenhum ecrã o aceita como forma de aceder a um
             * pedido (ADR-0011 §3).
             */
            $table->string('reference', 20)->unique();

            /**
             * Quem escreveu. NULL depois da anonimização — a sério, não uma
             * marca.
             */
            $table->string('requester_name', 120)->nullable();
            $table->string('requester_email', 255)->nullable();

            /**
             * A conta e a organização, quando existem.
             *
             * `user_id` é a ÚNICA chave de autorização do lado do requerente.
             * `organization_id` é contexto para quem responde — um colega da
             * mesma organização não vê o pedido, e o administrador
             * institucional também não.
             */
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();

            /** Por onde entrou. Um pedido de guest nunca tem `user_id`. */
            $table->string('source', 20);

            /** O que a PESSOA escolheu. Sete opções que ela reconhece. */
            $table->string('category', 40);

            $table->string('subject', 200)->nullable();
            $table->text('description')->nullable();

            $table->string('status', 20)->default('open');

            /**
             * Contexto técnico, recolhido do servidor e nunca do que o cliente
             * diz ser. `technical_code` é a classificação INTERNA e nasce NULL:
             * nada a infere — quem classifica é um operador que olhou, a mesma
             * recusa que o pré-voo comercial e as contas de teste já fazem.
             */
            $table->string('technical_reference', 120)->nullable();
            $table->string('technical_route', 200)->nullable();
            $table->string('technical_code', 40)->nullable();
            $table->string('app_version', 20);

            /**
             * O relógio da espera. Só existe em `waiting_for_user`, e a CHECK
             * abaixo diz isso em SQL. O lembrete é enviado uma vez.
             */
            $table->timestamp('waiting_since')->nullable();
            $table->timestamp('waiting_reminder_sent_at')->nullable();

            /**
             * O relógio da retenção começa aqui — e PÁRA se o pedido for
             * reaberto, porque `resolved_at` volta a NULL. Um pedido activo
             * nunca é anonimizado (ADR-0011 §12).
             */
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();

            /** Resolvido pelo tempo, não por uma pessoa. Dito, não deduzido. */
            $table->boolean('auto_resolved')->default(false);

            /**
             * A EXCEPÇÃO À PROMESSA DE ELIMINAÇÃO, e por isso classificada em
             * vez de escrita à mão. A nota é interna: nunca sai para auditoria
             * nem para email, e é o único campo do hold que a anonimização
             * apaga — os restantes sobrevivem como prova de que a excepção
             * existiu.
             */
            $table->timestamp('retention_hold_at')->nullable();
            $table->foreignId('retention_hold_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('retention_hold_reason_code', 40)->nullable();
            $table->text('retention_hold_note')->nullable();
            $table->timestamp('retention_hold_released_at')->nullable();
            $table->foreignId('retention_hold_released_by')->nullable()->constrained('users')->nullOnDelete();

            /** Carimbo que torna a anonimização idempotente. */
            $table->timestamp('anonymized_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'waiting_since']);
            $table->index(['resolved_at', 'anonymized_at']);
            $table->index('organization_id');
            $table->index('user_id');
        });

        Schema::create('support_messages', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();

            /**
             * `cascadeOnDelete` e SEM guard de eliminação, ao contrário de
             * `VoucherRedemption`: aqui apagar É o mecanismo. A anonimização
             * dos 24 meses apaga estas linhas, e uma restrição que o impedisse
             * transformaria a promessa de eliminação numa impossibilidade.
             */
            $table->foreignId('support_request_id')->constrained()->cascadeOnDelete();

            $table->string('author_role', 20);
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();

            /** A conversa canónica. O email é o aviso; isto é o registo. */
            $table->text('body');

            $table->timestamps();

            $table->index(['support_request_id', 'id']);
        });

        Schema::create('support_notification_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->foreignId('support_request_id')->constrained()->cascadeOnDelete();

            $table->string('notification_type', 40);

            /**
             * O DESTINATÁRIO POR PAPEL, NUNCA POR ENDEREÇO. O da equipa deriva
             * de `lapis.support.inbox`; o do requerente deriva da relação com o
             * pedido. É essa ausência que faz a anonimização funcionar sem ter
             * de vir cá limpar nada — e que impede esta tabela técnica de se
             * tornar uma segunda cópia dos dados pessoais.
             */
            $table->string('recipient_role', 20);

            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('last_failed_at')->nullable();
            $table->timestamp('delivered_at')->nullable();

            /**
             * COMO falhou, de um vocabulário fechado, derivado da classe da
             * excepção e do código SMTP numérico.
             *
             * NUNCA a mensagem da excepção, nunca a resposta do servidor, nunca
             * um stack trace, nunca credenciais. Uma mensagem de erro de SMTP
             * cita com frequência o endereço de destino — guardá-la seria
             * reintroduzir aqui o dado pessoal que a coluna ausente acima
             * recusa.
             */
            $table->string('failure_code', 40)->nullable();

            $table->timestamps();

            /**
             * Uma linha por notificação e por destinatário. O reenvio
             * ACTUALIZA a linha em vez de acrescentar outra: o que interessa é
             * «esta notificação chegou?», não quantas vezes se tentou — e as
             * tentativas ficam no contador.
             */
            $table->unique(['support_request_id', 'notification_type', 'recipient_role'], 'support_deliveries_unique');
            // Nome explícito: o que o Laravel geraria a partir do nome desta
            // tabela passa os 64 caracteres que o MySQL aceita num
            // identificador, e a migração falharia só em produção.
            $table->index(['delivered_at', 'last_failed_at'], 'support_deliveries_pending_index');
        });

        $this->addChecks('support_requests', [
            'support_requests_status_check' => $this->inList('status', self::STATUSES),
            'support_requests_source_check' => $this->inList('source', self::SOURCES),
            'support_requests_category_check' => $this->inList('category', self::CATEGORIES),
            'support_requests_technical_code_check' => 'technical_code IS NULL OR '.$this->inList('technical_code', self::TECHNICAL_CODES),
            // O relógio da espera só existe no estado da espera.
            'support_requests_waiting_check' => "status <> 'waiting_for_user' OR waiting_since IS NOT NULL",
            // Um pedido resolvido sabe quando o foi. É daqui que a retenção conta.
            'support_requests_resolved_check' => "status <> 'resolved' OR resolved_at IS NOT NULL",
            // Não se lembra uma espera que não começou.
            'support_requests_reminder_check' => 'waiting_reminder_sent_at IS NULL OR waiting_since IS NOT NULL',
            // «Um pedido de guest não tem conta» NÃO ESTÁ AQUI, e não é
            // esquecimento: o MySQL recusa (erro 3823) uma CHECK sobre uma
            // coluna que participa numa chave estrangeira com acção
            // referencial — e `user_id` é `nullOnDelete()`, porque um pedido
            // tem de sobreviver ao apagamento da conta que o abriu. Das duas
            // garantias, a que se mantém é a que protege o histórico. A regra
            // vive em `SupportRequest::booted()`, que a impõe em toda a
            // escrita, e num teste que a afirma.
            //
            // Um hold é uma data COM um motivo classificado, ou não é nada.
            // Nada aqui liga a NOTA ao hold, de propósito: a anonimização
            // apaga-a e deixa a prova de pé.
            'support_requests_hold_check' => '(retention_hold_at IS NULL) = (retention_hold_reason_code IS NULL)',
            'support_requests_hold_reason_check' => 'retention_hold_reason_code IS NULL OR '.$this->inList('retention_hold_reason_code', self::HOLD_REASONS),
            // Não se liberta o que nunca foi aplicado.
            'support_requests_hold_release_check' => 'retention_hold_released_at IS NULL OR retention_hold_at IS NOT NULL',
        ]);

        $this->addChecks('support_messages', [
            'support_messages_role_check' => $this->inList('author_role', self::AUTHOR_ROLES),
            // «Uma resposta de operador tem sempre um operador» também não
            // cabe aqui, pela mesma razão do erro 3823: `author_user_id` é uma
            // chave estrangeira `nullOnDelete()`, porque uma mensagem tem de
            // sobreviver ao apagamento da conta de quem a escreveu — e, de
            // resto, a anonimização precisa de a poder anular. A regra vive em
            // `SupportMessage::booted()`.
        ]);

        $this->addChecks('support_notification_deliveries', [
            'support_deliveries_type_check' => $this->inList('notification_type', self::NOTIFICATION_TYPES),
            'support_deliveries_recipient_check' => $this->inList('recipient_role', ['requester', 'support_team']),
            'support_deliveries_failure_code_check' => 'failure_code IS NULL OR '.$this->inList('failure_code', self::FAILURE_CODES),
            // Entregue é entregue: não coexiste com um motivo de falha.
            'support_deliveries_delivered_check' => 'delivered_at IS NULL OR failure_code IS NULL',
            // Uma tentativa deixa data. Zero tentativas não deixam nenhuma.
            'support_deliveries_attempts_check' => 'attempts = 0 OR last_attempt_at IS NOT NULL',
            'support_deliveries_failure_date_check' => 'last_failed_at IS NULL OR failure_code IS NOT NULL',
        ]);
    }

    public function down(): void
    {
        $this->refuseIfRequestsWouldBeLost();

        Schema::dropIfExists('support_notification_deliveries');
        Schema::dropIfExists('support_messages');
        Schema::dropIfExists('support_requests');
    }

    /**
     * REVERSÍVEL SÓ ENQUANTO NINGUÉM TIVER PEDIDO AJUDA.
     *
     * A mesma regra dos lugares de fundador e dos vouchers, e pela mesma razão:
     * um pedido de suporte é uma conversa que alguém teve connosco, muitas
     * vezes o relato de um problema com os alunos de uma turma inteira. Nada o
     * reconstrói depois de a tabela desaparecer, e recuar em silêncio sobre ele
     * seria apagar a única prova de que a pessoa nos escreveu.
     *
     * @throws RuntimeException quando recuar destruiria pedidos gravados
     */
    protected function refuseIfRequestsWouldBeLost(): void
    {
        if (! Schema::hasTable('support_requests')) {
            return;
        }

        $count = DB::table('support_requests')->count();

        if ($count === 0) {
            return;
        }

        throw new RuntimeException(
            "Refusing to roll back: {$count} support request(s) are recorded. Each one is a conversation somebody "
            .'had with us, and dropping the tables destroys it with nothing able to reconstruct it. This migration '
            .'is reversible only while no support request exists.'
        );
    }

    /** @param list<string> $values */
    protected function inList(string $column, array $values): string
    {
        return $column." IN ('".implode("','", $values)."')";
    }

    /**
     * CHECK constraints apenas onde o motor as tem.
     *
     * O SQLite — o motor dos testes — não as adiciona a uma tabela existente;
     * lá, os enums nativos e a validação da fronteira sustentam as mesmas
     * regras. É a mesma divisão que as migrações comerciais documentam, e é por
     * isso que existe um teste de MySQL de raiz a provar a metade que o SQLite
     * não prova.
     *
     * @param  array<string, string>  $checks
     */
    protected function addChecks(string $table, array $checks): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach ($checks as $name => $expression) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
    }
};
