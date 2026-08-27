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
 * @property string|null $contact_email
 */
#[Fillable([
    'mail_mailer', 'mail_host', 'mail_port', 'mail_username', 'mail_password',
    'mail_encryption', 'mail_from_address', 'mail_from_name', 'contact_email',
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

    /**
     * The address the public site invites people to write to, or null.
     *
     * NEVER FALLS BACK TO `mail_from_address`: that one is the system sender,
     * usually a no-reply nobody reads. An unanswered mailbox is worse than no
     * call to action, so the landing page is told the truth — nothing — and
     * renders accordingly.
     */
    public function publicContactEmail(): ?string
    {
        return filled($this->contact_email) ? $this->contact_email : null;
    }
}
