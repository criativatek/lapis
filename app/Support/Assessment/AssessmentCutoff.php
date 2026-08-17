<?php

namespace App\Support\Assessment;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * «Dados até 15/11/2026» — the one place that decides what «until» means.
 *
 * A teacher looking at a class in the middle of a semester is asking a real
 * question: how were we doing back then? Answering it means leaving out
 * everything that had not happened yet — and doing that in ONE place, because
 * the alternative is `if ($date <= …)` scattered through half a dozen services,
 * each with its own idea of which date counts.
 *
 * WHAT COUNTS IS THE ACADEMIC DATE, NEVER THE BOOKKEEPING ONE. An instrument
 * belongs to the day it was applied (`applied_on`); a self-assessment to the day
 * it was submitted; a decision to the day it was confirmed. `created_at` and
 * `updated_at` describe when a row was typed into a computer, which is a fact
 * about the software and not about the class — a test sat in October and
 * imported in December belongs to October.
 *
 * An open cutoff (`none()`) changes nothing at all: every query it touches comes
 * back exactly as it was. That is what keeps a school that never asks this
 * question from paying for it (§43).
 */
final readonly class AssessmentCutoff
{
    private function __construct(public ?CarbonImmutable $date) {}

    /** No cutoff: everything counts, exactly as before. */
    public static function none(): self
    {
        return new self(null);
    }

    /**
     * Everything up to AND INCLUDING this day.
     *
     * Inclusive on purpose: a teacher who says «até 15 de novembro» means the
     * test they gave that morning is in.
     */
    public static function on(DateTimeInterface|string|null $date): self
    {
        if ($date === null || $date === '') {
            return self::none();
        }

        return new self(CarbonImmutable::parse($date)->startOfDay());
    }

    public function isOpen(): bool
    {
        return $this->date === null;
    }

    /** The last instant that still counts — 23:59:59.999999 of the cutoff day. */
    public function endOfDay(): ?CarbonImmutable
    {
        return $this->date?->endOfDay();
    }

    /**
     * Narrows a query on a date column, IN PLACE. An open cutoff leaves it
     * completely untouched.
     *
     * Mutating rather than returning: an Eloquent builder and a relation are
     * both mutable and neither guarantees `where()` hands back its own type, so
     * a fluent signature here could only be honest by lying about generics. The
     * caller keeps the query it already had.
     *
     * @template TModel of Model
     * @template TDeclaring of Model
     *
     * @param  EloquentBuilder<TModel>|Relation<TModel, TDeclaring, mixed>  $query
     */
    public function applyTo(EloquentBuilder|Relation $query, string $column): void
    {
        if ($this->isOpen()) {
            return;
        }

        $query->where($column, '<=', $this->endOfDay());
    }

    /** Whether a single date falls inside the cutoff. */
    public function covers(DateTimeInterface|string|null $date): bool
    {
        if ($this->isOpen()) {
            return true;
        }

        if ($date === null) {
            return false;
        }

        return CarbonImmutable::parse($date)->lessThanOrEqualTo($this->endOfDay());
    }

    /** «15/11/2026», for a screen. Null when there is nothing to say. */
    public function label(): ?string
    {
        return $this->date?->format('d/m/Y');
    }

    /** «2026-11-15», for a URL or a stored payload. */
    public function toIso(): ?string
    {
        return $this->date?->toDateString();
    }
}
