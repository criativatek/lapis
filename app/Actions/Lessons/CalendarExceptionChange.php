<?php

namespace App\Actions\Lessons;

use App\Models\AcademicCalendarException;
use App\Models\AcademicCalendarExceptionSource;
use App\Models\AcademicYear;
use Illuminate\Validation\ValidationException;

/**
 * A INTENÇÃO de criar, editar ou eliminar uma exceção do calendário, aplicada
 * DENTRO da transação da reconciliação (modo «hipotético»).
 *
 * Um objeto e não uma closure porque tem de ser IMPRESSO no `plan_token`: a
 * pré-visualização e a execução têm de provar que simularam o mesmo pedido.
 * Na pré-visualização a transação reverte e a exceção não chega a existir; na
 * execução só se confirma se o token coincidir.
 */
final class CalendarExceptionChange
{
    public const CREATE = 'create';

    public const UPDATE = 'update';

    public const DELETE = 'delete';

    /**
     * @param  array{type?: string, title?: string, starts_on?: string, ends_on?: string, note?: string|null}  $attributes
     */
    private function __construct(
        public readonly string $operation,
        public readonly ?int $exceptionId,
        public readonly array $attributes,
    ) {}

    /**
     * @param  array{type: string, title: string, starts_on: string, ends_on: string, note?: string|null}  $attributes
     */
    public static function create(array $attributes): self
    {
        return new self(self::CREATE, null, $attributes);
    }

    /**
     * @param  array{type?: string, title?: string, starts_on?: string, ends_on?: string, note?: string|null}  $attributes
     */
    public static function update(AcademicCalendarException|int $exception, array $attributes): self
    {
        return new self(self::UPDATE, self::idOf($exception), $attributes);
    }

    public static function delete(AcademicCalendarException|int $exception): self
    {
        return new self(self::DELETE, self::idOf($exception), []);
    }

    /**
     * Aplica a alteração às exceções do ano. SEMPRE DENTRO DE UMA TRANSAÇÃO.
     *
     * @return AcademicCalendarException|null a exceção criada ou alterada, já gravada; NULL ao eliminar
     *
     * @throws ValidationException quando a exceção a editar/eliminar não é deste ano
     */
    public function apply(AcademicYear $year): ?AcademicCalendarException
    {
        if ($this->operation === self::CREATE) {
            /** @var AcademicCalendarException $created */
            $created = $year->exceptions()->create($this->attributes + ['source' => AcademicCalendarExceptionSource::Manual]);

            return $created;
        }

        /** @var AcademicCalendarException|null $exception */
        $exception = $year->exceptions()->whereKey($this->exceptionId)->lockForUpdate()->first();

        if ($exception === null) {
            throw ValidationException::withMessages([
                'exception' => __('Esta exceção do calendário já não existe.'),
            ]);
        }

        if ($this->operation === self::DELETE) {
            $exception->delete();

            return null;
        }

        $exception->fill($this->attributes)->save();

        return $exception;
    }

    /**
     * @return array{0: string, 1: int|null, 2: array<string, mixed>} o pedido, para o token
     */
    public function fingerprint(): array
    {
        $attributes = $this->attributes;
        ksort($attributes);

        return [$this->operation, $this->exceptionId, $attributes];
    }

    private static function idOf(AcademicCalendarException|int $exception): int
    {
        return $exception instanceof AcademicCalendarException ? (int) $exception->getKey() : $exception;
    }
}
