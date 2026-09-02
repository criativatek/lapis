<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Imagens num reporte, e o aceite de quem as enviou.
 *
 * PORQUE É QUE O ACEITE É UMA COLUNA E NÃO UM PRESSUPOSTO. Uma captura de ecrã
 * do Lapispro é, por construção, uma imagem dos dados sobre que o defeito é —
 * uma tabela de nomes de crianças contra classificações. Quem a envia tem de a
 * ter visto e de o dizer, e «o utilizador confirmou» só é um facto se ficar
 * escrito com a data, a versão do texto que lhe foi mostrado, e o que aquele
 * aceite cobria. Sem os três é uma afirmação.
 *
 * `consent_scope` GUARDA O ÂMBITO, e é a metade que se esquece. Saber que
 * alguém aceitou não diz o que aceitou: um aceite dado quando não havia imagem
 * não cobre a imagem que apareceu depois. Aqui fica quantas imagens seguiram, se
 * a captura de ecrã foi certificada, e que aviso concreto lhe foi mostrado.
 *
 * O ACEITE SOBREVIVE À ANONIMIZAÇÃO, ao contrário do contexto técnico. Não é
 * conteúdo sobre a pessoa — é o registo de um acto dela, da mesma natureza que
 * `resolved_by`, que também sobrevive. Um aceite que se apaga deixa de servir
 * para aquilo que existe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_requests', function (Blueprint $table): void {
            $table->timestamp('consent_accepted_at')->nullable()->after('client_context');
            $table->string('consent_terms_version', 40)->nullable()->after('consent_accepted_at');
            $table->json('consent_scope')->nullable()->after('consent_terms_version');
        });

        Schema::create('support_attachments', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->foreignId('support_request_id')->constrained()->cascadeOnDelete();

            // `screenshot` é a que a aplicação tirou; `user_upload` é a que a
            // pessoa escolheu. São coisas diferentes para quem lê o pedido, e a
            // certificação da primeira é uma decisão à parte.
            $table->string('kind', 20);

            // Caminho relativo ao disco privado. Nunca uma URL: quem serve isto
            // é um controlador atrás da política, não o servidor de ficheiros.
            $table->string('disk_path', 255);
            $table->string('mime', 60);
            $table->unsignedInteger('bytes');

            $table->timestamps();

            $table->index('support_request_id');
        });

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE support_attachments ADD CONSTRAINT support_attachments_kind_check
                 CHECK (kind IN ('screenshot', 'user_upload'))"
            );

            // O par do aceite: ou estão os dois, ou não está nenhum. Mesma forma
            // do par de suspensão de retenção, pela mesma razão — metade de um
            // aceite não é um aceite.
            DB::statement(
                'ALTER TABLE support_requests ADD CONSTRAINT support_requests_consent_check
                 CHECK ((consent_accepted_at IS NULL) = (consent_terms_version IS NULL))'
            );
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE support_requests DROP CONSTRAINT support_requests_consent_check');
        }

        Schema::dropIfExists('support_attachments');

        Schema::table('support_requests', function (Blueprint $table): void {
            $table->dropColumn(['consent_accepted_at', 'consent_terms_version', 'consent_scope']);
        });
    }
};
