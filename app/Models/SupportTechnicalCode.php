<?php

namespace App\Models;

/**
 * A CLASSIFICAÇÃO TÉCNICA, INTERNA, ATRIBUÍDA POR UM OPERADOR.
 *
 * Nasce NULL e nada a infere — nem do texto, nem da categoria, nem da rota.
 * Deduzir «isto é um erro de importação» a partir do que a pessoa escreveu é a
 * mesma família de erro que deduzir «isto é uma conta de teste» pelo domínio do
 * email, ou «isto é fundador» pelo valor pago: o pré-voo comercial e
 * `SetTestAccount` recusam-no, e aqui recusa-se pela mesma razão. NULL significa
 * «ninguém olhou»; `Unclassified` significa «alguém olhou e não encaixa», e as
 * duas coisas não podem colapsar numa só.
 *
 * CADA CASO SAI DE UMA FAMÍLIA DE EXCEPÇÕES QUE JÁ EXISTE no código — não de
 * uma taxonomia inventada. Sobrevive à anonimização precisamente por ser um
 * vocabulário fechado: não há aqui onde um nome de aluno se esconder.
 *
 * DOIS CÓDIGOS QUE NÃO EXISTEM, E DE PROPÓSITO:
 *
 *  - **desempenho/timeout** — não há instrumentação de latência nem uma única
 *    excepção que o represente. Fechá-lo seria criar uma gaveta para dados que
 *    ninguém recolhe.
 *  - **perda/corrupção de dados** — nenhum incidente destes está tipificado. É
 *    uma palavra pesada de mais para aparecer em estatística sem definição, e a
 *    etiqueta existir antes do primeiro caso convida a usá-la mal.
 *
 * Qualquer um deles entra por uma linha, no dia em que houver caso real.
 */
enum SupportTechnicalCode: string
{
    /** Autenticação, verificação de email, dois passos, convites, tenancy. */
    case AuthAccess = 'auth_access';

    /**
     * O plano fez o que promete.
     *
     * Separado de propósito: um Base que atinge as suas turmas activas não é
     * uma avaria, e contá-lo como defeito faria o produto parecer partido
     * exactamente quando está correcto. Ver `App\Support\Limits\LimitKey`.
     */
    case EntitlementLimit = 'entitlement_limit';

    /** Um ficheiro que a aplicação não conseguiu ler. A falha mais diagnosticável. */
    case ImportParse = 'import_parse';

    /**
     * Uma regra de avaliação recusou — e recusar é o que ela faz.
     *
     * É a família mais numerosa do domínio (vazio não é zero, nota acima do
     * máximo, versão de perfil congelada, migração de perfil, decisão de
     * classificação). Quase nunca é bug; é quase sempre uma explicação.
     */
    case AssessmentRule = 'assessment_rule';

    /** Falha ao gerar um documento que ia sair para terceiros. */
    case ReportGeneration = 'report_generation';

    /** Checkout, condição comercial, correcção de pagamento, voucher, período experimental. */
    case Commercial = 'commercial';

    /** O serviço de IA: indisponível, pedido falhado, quota esgotada. */
    case AiService = 'ai_service';

    /**
     * Uma dúvida, não uma avaria — a maioria do suporte real.
     *
     * Sem este caso, tudo o que chega parece incidente e a estatística diz que
     * o produto está partido quando o que falta é uma frase de ajuda.
     */
    case DataQuestion = 'data_question';

    /** Um operador olhou e nenhuma das famílias acima serve. */
    case Unclassified = 'unclassified';

    public function label(): string
    {
        return match ($this) {
            self::AuthAccess => __('Autenticação e acesso'),
            self::EntitlementLimit => __('Limite de plano atingido'),
            self::ImportParse => __('Ficheiro não interpretado'),
            self::AssessmentRule => __('Regra de avaliação recusou'),
            self::ReportGeneration => __('Falha ao gerar documento'),
            self::Commercial => __('Comercial e pagamentos'),
            self::AiService => __('Serviço de IA'),
            self::DataQuestion => __('Dúvida, não avaria'),
            self::Unclassified => __('Por classificar'),
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $code): array => ['value' => $code->value, 'label' => $code->label()],
            self::cases(),
        );
    }
}
