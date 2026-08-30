<?php

namespace App\Models;

/**
 * Por onde o pedido entrou.
 *
 * Não é o mesmo que «tem conta»: um pedido de guest fica para sempre sem
 * `user_id`, mesmo que a pessoa crie conta no dia seguinte com o mesmo email.
 * Ligá-los a posteriori seria dar acesso ao histórico a quem provou apenas ter
 * o endereço — que é a mesma porta que o portal de guest recusou (ADR-0011 §3).
 */
enum SupportSource: string
{
    case Guest = 'guest';
    case Authenticated = 'authenticated';

    public function label(): string
    {
        return match ($this) {
            self::Guest => __('Sem conta'),
            self::Authenticated => __('Com conta'),
        };
    }
}
