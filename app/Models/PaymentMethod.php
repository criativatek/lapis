<?php

namespace App\Models;

/**
 * How the money arrived — as far as the operator who received it knows.
 *
 * Every case here is something a person does outside this application, because
 * there is no gateway. `Card` is not a case: nothing in this system takes a
 * card, and offering the option would suggest otherwise. No card data is ever
 * stored, whatever the method says.
 *
 * Nullable in the database: a payment whose method nobody recorded is still a
 * payment, and guessing would be worse than leaving it blank.
 */
enum PaymentMethod: string
{
    case BankTransfer = 'bank_transfer';
    case MbWay = 'mb_way';
    case Multibanco = 'multibanco';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::BankTransfer => __('Transferência bancária'),
            self::MbWay => __('MB WAY'),
            self::Multibanco => __('Referência Multibanco'),
            self::Other => __('Outro'),
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $method): array => ['value' => $method->value, 'label' => $method->label()],
            self::cases(),
        );
    }
}
