<?php

namespace App\Models;

enum CapabilityGrantSource: string
{
    case Voucher = 'voucher';
    case Direct = 'direct';
}
