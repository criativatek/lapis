<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A quem se passa a fatura.
 *
 * SEPARADO DE `organizations` de propósito. O nome de uma organização é «Escola
 * de Alvalade»; o nome de faturação é a entidade que paga, com NIF e morada, e
 * as duas divergem quase sempre — um professor a título individual, um
 * agrupamento a pagar por uma escola. Na mesma tabela, cada ecrã teria de
 * explicar qual dos nomes é qual.
 *
 * UM POR ORGANIZAÇÃO, criado no primeiro checkout e reutilizado depois, para
 * ninguém reescrever a morada a cada renovação.
 *
 * SÃO DADOS PESSOAIS quando quem paga é um professor a título individual —
 * nome, NIF e morada. Ficam sob o mesmo isolamento por organização que tudo o
 * resto, e não saem daqui para lado nenhum enquanto não houver faturador
 * certificado ligado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();

            $table->string('name');

            // Nullable: um consumidor particular pode pedir fatura sem
            // contribuinte, e obrigar afastaria quem compra a título individual.
            $table->string('tax_number', 20)->nullable();

            $table->string('address_line1');
            $table->string('address_line2')->nullable();
            $table->string('postal_code', 20);
            $table->string('city');
            $table->char('country', 2)->default('PT');

            // Para onde vai a fatura, que pode não ser o email de quem comprou.
            $table->string('email');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_profiles');
    }
};
