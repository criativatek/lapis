<?php

namespace App\Support\Commercial;

use App\Models\Voucher;

/**
 * A resposta de `Vouchers::resolve()`: o veredicto e, quando existe, o voucher.
 *
 * O voucher só vem quando foi ENCONTRADO — mesmo que o veredicto seja uma
 * recusa: o checkout precisa dele para explicar «expirou» com a janela real, e
 * o resgate precisa dele para re-verificar sob lock sem procurar duas vezes.
 * `Malformed` e `NotFound` viajam sem voucher, porque não há nenhum.
 */
final class VoucherResolution
{
    public function __construct(
        public readonly VoucherOutcome $outcome,
        public readonly ?Voucher $voucher = null,
    ) {}

    public function isValid(): bool
    {
        return $this->outcome->isValid();
    }
}
