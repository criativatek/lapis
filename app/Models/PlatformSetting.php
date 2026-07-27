<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * The single platform settings row. Not tenant-scoped — it is the SaaS itself.
 *
 * @property int $id
 * @property string $mail_mailer
 * @property string|null $mail_host
 * @property int $mail_port
 * @property string|null $mail_username
 * @property string|null $mail_password
 * @property string|null $mail_encryption
 * @property string|null $mail_from_address
 * @property string|null $mail_from_name
 */
#[Fillable([
    'mail_mailer', 'mail_host', 'mail_port', 'mail_username', 'mail_password',
    'mail_encryption', 'mail_from_address', 'mail_from_name',
])]
class PlatformSetting extends Model
{
    protected function casts(): array
    {
        return [
            'mail_password' => 'encrypted',
            'mail_port' => 'integer',
        ];
    }

    /** The one-and-only settings row, created (with DB defaults) on first read. */
    public static function current(): self
    {
        return static::query()->first() ?? static::query()->create([]);
    }

    public function mailConfigured(): bool
    {
        return filled($this->mail_host);
    }
}
