<?php

namespace App\Models;

/**
 * De onde veio uma exceção letiva — e nada mais do que isso (§14 do enunciado
 * da Fase 5.4).
 *
 * DELIBERADAMENTE SIMPLES: uma coluna de texto e três valores. Não há aqui uma
 * chave estrangeira para um lote de importação, nem um rasto de auditoria, nem
 * um «quem» e um «quando» — a pergunta a que isto responde é «isto foi escrito
 * à mão ou não?», e essa pergunta responde-se com uma palavra. O mesmo formato,
 * e pela mesma razão, que InterventionDescriptionSource já usa.
 *
 * TUDO O QUE ESTA FASE ESCREVE É Manual. `Suggested` e `Imported` existem para
 * que, no dia em que a sugestão de feriados (§17) e a importação de PDF/Excel
 * (Fase 5.6) chegarem, as linhas escritas hoje já sejam distinguíveis delas —
 * um calendário não deve ser incapaz de dizer o que o professor escreveu e o
 * que lhe foi proposto. Nenhum caminho de código desta entrega produz qualquer
 * um dos outros dois, e o formulário nunca os aceita do cliente.
 */
enum AcademicCalendarExceptionSource: string
{
    case Manual = 'manual';
    case Suggested = 'suggested';
    case Imported = 'imported';

    public function label(): string
    {
        return match ($this) {
            self::Manual => __('Escrita pelo professor'),
            self::Suggested => __('Sugerida'),
            self::Imported => __('Importada'),
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $source): string => $source->value, self::cases());
    }
}
