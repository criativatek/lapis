<?php

namespace App\Support\Entitlements;

use App\Models\CapabilityVoucher;
use Illuminate\Support\Str;

final class CapabilityVoucherCode
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public static function normalize(string $code): string
    {
        return Str::upper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
    }

    public static function isWellFormed(string $code): bool
    {
        $length = strlen(self::normalize($code));

        return $length >= 4 && $length <= 32;
    }

    public static function generate(): string
    {
        do {
            $raw = '';
            for ($i = 0; $i < 16; $i++) {
                $raw .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $code = implode('-', str_split($raw, 4));
        } while (CapabilityVoucher::query()->code($code)->exists());

        return $code;
    }
}
