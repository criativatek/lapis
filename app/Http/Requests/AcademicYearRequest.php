<?php

namespace App\Http\Requests;

use App\Models\AcademicCalendarException;
use App\Models\AcademicCalendarExceptionType;
use App\Models\AcademicPeriod;
use App\Models\AcademicPeriodKind;
use App\Models\AcademicYear;
use App\Models\AcademicYearStatus;
use App\Rules\BelongsToCurrentOrganization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates an academic year and its periods together — a year is created with
 * its periods in one step (§9), so they are validated in one request.
 *
 * E, DESDE A FASE 5.4, TAMBÉM AS SUAS EXCEÇÕES LETIVAS — feriados, interrupções
 * e dias não letivos. Pela mesma razão e no mesmo pedido: são estrutura DESTE
 * ano, editadas no mesmo sítio e por quem edita os períodos, e um formulário que
 * as validasse noutro lado seria um segundo sítio onde as regras podiam divergir.
 *
 * All validation is server-side (§22.2). The uniqueness rule is scoped to the
 * resolved organization, not a bare unique:, so it cannot collide with another
 * organization's label.
 */
class AcademicYearRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organizationId = app(CurrentOrganization::class)->id();
        $year = $this->route('academic_year');
        $yearId = $year instanceof AcademicYear ? $year->id : null;

        return [
            'label' => [
                'required', 'string', 'max:32',
                Rule::unique('academic_years', 'label')
                    ->where('organization_id', $organizationId)
                    ->ignore($yearId),
            ],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after:starts_on'],
            'status' => ['required', Rule::enum(AcademicYearStatus::class)],
            'country_code' => ['required', 'string', 'size:2'],
            'region_code' => ['nullable', 'string', 'max:8'],

            'periods' => ['required', 'array', 'min:1'],
            // Absent/null is a brand-new period. When present, it must at
            // least belong to the current organization — whether it belongs
            // to THIS year is checked below, in after(), since that needs
            // the resolved $academicYear rather than a bare column rule.
            'periods.*.ulid' => ['nullable', 'string', new BelongsToCurrentOrganization(AcademicPeriod::class, 'ulid')],
            'periods.*.label' => ['required', 'string', 'max:64'],
            'periods.*.kind' => ['required', Rule::enum(AcademicPeriodKind::class)],
            'periods.*.sequence' => ['required', 'integer', 'min:1', 'max:255'],
            'periods.*.starts_on' => ['required', 'date'],
            'periods.*.ends_on' => ['required', 'date', 'after:periods.*.starts_on'],

            // AS EXCEÇÕES LETIVAS (Fase 5.4) — feriados, interrupções e dias
            // não letivos, submetidas no MESMO formulário e no mesmo pedido que
            // os períodos, porque são a mesma coisa: a estrutura deste ano.
            //
            // `sometimes` E NÃO `required`: ao contrário dos períodos, um ano
            // letivo sem exceção nenhuma é perfeitamente legítimo — e um pedido
            // que não fale de exceções de todo (uma integração antiga, um teste
            // que só quer mexer nos períodos) não deve por isso apagar as que já
            // existem. É AcademicYearService quem distingue os dois casos: a
            // chave ausente deixa-as como estão, um array vazio remove-as todas.
            'exceptions' => ['sometimes', 'array'],
            // Absent/null is a brand-new exception — a mesma convenção dos
            // `periods.*.ulid` acima, e o mesmo par de verificações em after():
            // pertencer à organização é o que esta regra vê, pertencer a ESTE
            // ano é o que ela não consegue ver.
            'exceptions.*.ulid' => ['nullable', 'string', new BelongsToCurrentOrganization(AcademicCalendarException::class, 'ulid')],
            'exceptions.*.type' => ['required', Rule::enum(AcademicCalendarExceptionType::class)],
            'exceptions.*.title' => ['required', 'string', 'max:200'],
            'exceptions.*.starts_on' => ['required', 'date'],
            // `after_or_equal`, E NÃO `after` — a diferença real face aos
            // períodos logo acima: um período tem de durar mais do que um dia,
            // mas uma exceção de um dia só é o caso MAIS comum que existe (um
            // feriado). É a mesma comparação que a CHECK da tabela faz.
            'exceptions.*.ends_on' => ['required', 'date', 'after_or_equal:exceptions.*.starts_on'],
            'exceptions.*.note' => ['nullable', 'string', 'max:2000'],
            // `source` NÃO SE VALIDA PORQUE NÃO SE ACEITA: a proveniência é
            // escrita pelo servidor (sempre «manual» nesta fase) e nunca vem do
            // cliente — senão um formulário podia declarar-se «importado» e a
            // coluna deixava de ser uma resposta honesta à pergunta que existe
            // para responder.
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $periods = $this->input('periods', []);

            // Period sequences must be unique within the year — the same rule the
            // UNIQUE(academic_year_id, sequence) index enforces, surfaced as a
            // clear message instead of a database error.
            $sequences = array_column($periods, 'sequence');
            if (count($sequences) !== count(array_unique($sequences))) {
                $validator->errors()->add('periods', __('Cada período deve ter uma ordem distinta.'));
            }

            // Every period must fall inside the academic year.
            $yearStart = $this->input('starts_on');
            $yearEnd = $this->input('ends_on');
            foreach ($periods as $index => $period) {
                if (isset($period['starts_on'], $period['ends_on'])
                    && ($period['starts_on'] < $yearStart || $period['ends_on'] > $yearEnd)) {
                    $validator->errors()->add(
                        "periods.{$index}.starts_on",
                        __('O período tem de estar dentro do ano letivo.'),
                    );
                }
            }

            // A period ulid that belongs to the current organization is still
            // not necessarily THIS year's own — without this, editing one
            // year could smuggle in (and later silently adopt, or even
            // remove) another year's period. Mirrors LessonSequenceRequest's
            // identical check for its own items.
            $year = $this->route('academic_year');
            if ($year instanceof AcademicYear) {
                $ownUlids = $year->periods()->pluck('ulid');
                foreach ($periods as $index => $period) {
                    $ulid = is_array($period) ? ($period['ulid'] ?? null) : null;
                    if ($ulid !== null && ! $ownUlids->contains($ulid)) {
                        $validator->errors()->add(
                            "periods.{$index}.ulid",
                            __('O período selecionado não pertence a este ano letivo.'),
                        );
                    }
                }
            }

            // AS MESMAS DUAS VERIFICAÇÕES, PARA AS EXCEÇÕES. São exatamente as
            // dos períodos — estar dentro do ano, e o ulid ser mesmo deste ano —
            // porque os dois riscos são os mesmos: uma interrupção fora do ano
            // não é estrutura de ano nenhum, e um ulid de outro ano deixaria um
            // formulário adotar (ou remover) a exceção de um ano vizinho.
            $exceptions = $this->input('exceptions', []);

            if (! is_array($exceptions)) {
                return;
            }

            foreach ($exceptions as $index => $exception) {
                if (! is_array($exception)) {
                    continue;
                }

                if (isset($exception['starts_on'], $exception['ends_on'])
                    && ($exception['starts_on'] < $yearStart || $exception['ends_on'] > $yearEnd)) {
                    $validator->errors()->add(
                        "exceptions.{$index}.starts_on",
                        __('A exceção tem de estar dentro do ano letivo.'),
                    );
                }
            }

            if ($year instanceof AcademicYear) {
                $ownExceptionUlids = $year->exceptions()->pluck('ulid');
                foreach ($exceptions as $index => $exception) {
                    $ulid = is_array($exception) ? ($exception['ulid'] ?? null) : null;
                    if ($ulid !== null && ! $ownExceptionUlids->contains($ulid)) {
                        $validator->errors()->add(
                            "exceptions.{$index}.ulid",
                            __('A exceção selecionada não pertence a este ano letivo.'),
                        );
                    }
                }
            }
        });
    }
}
