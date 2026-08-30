<?php

namespace App\Models;

/**
 * O ASSUNTO, ESCOLHIDO POR QUEM ESCREVE.
 *
 * Sete opções, e o critério é o que a pessoa reconhece no produto — não a
 * arquitectura interna. A navegação tem mais de vinte entradas e o domínio tem
 * dezenas de excepções; oferecer isso a quem está com um problema seria pedir-
 * lhe que classificasse o nosso código. Quem classifica tecnicamente é um
 * operador, depois, em `SupportTechnicalCode`.
 *
 * O QUE FICOU DELIBERADAMENTE DE FORA: a IA (é do Pro — oferecê-la a quem está
 * no Base revela o plano e não ajuda), as aulas, as medidas de apoio e a
 * administração institucional. Sete opções que se leem são melhores do que
 * vinte que se escolhem mal, e o operador reclassifica sem custo. Não há
 * `privacidade`: os direitos do titular exercem-se no canal que a Política de
 * Privacidade nomeia, e um segundo sítio para a mesma obrigação legal seria
 * duas respostas para um prazo que a lei conta uma vez.
 */
enum SupportCategory: string
{
    /** Não consegue entrar, verificar o email, usar dois passos, aceitar convite. */
    case Access = 'access';

    /** Turmas, alunos, inscrições, importação de pautas. */
    case ClassesStudents = 'classes_students';

    /** Elementos, grelhas, perfis, classificações — o núcleo do produto. */
    case Assessment = 'assessment';

    /** Relatórios e exportações: a saída que vai para terceiros. */
    case Reports = 'reports';

    /** Ficheiros que entram: pautas, horários, calendários, correções. */
    case Imports = 'imports';

    /** Planos, pagamentos, faturas, vouchers. Sem gateway, passa por uma pessoa. */
    case Billing = 'billing';

    /** Um pedido que não cabe continua a ser um pedido. */
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Access => __('Acesso e conta'),
            self::ClassesStudents => __('Turmas e alunos'),
            self::Assessment => __('Avaliação e classificações'),
            self::Reports => __('Relatórios e exportações'),
            self::Imports => __('Importações'),
            self::Billing => __('Planos e pagamentos'),
            self::Other => __('Outro assunto'),
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $category): array => ['value' => $category->value, 'label' => $category->label()],
            self::cases(),
        );
    }
}
