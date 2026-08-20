<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An invitation into an institutional organization (Fatia 3).
 *
 * `ulid` identifies this row for the OWNER — the id `DELETE /team/invitations/{id}`
 * uses to cancel it. It grants nothing on its own. Acceptance is a completely
 * separate identifier: the raw, high-entropy token mailed to the invited
 * address, matched here by `token_hash`. Neither identifier can be used in
 * place of the other, on purpose — leaking the ulid (it appears in an
 * authenticated owner's own browser) must never be enough to accept.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property string $email
 * @property string $token_hash
 * @property int $invited_by
 * @property int|null $accepted_by
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $cancelled_at
 */
#[Fillable(['email', 'token_hash', 'invited_by', 'expires_at'])]
class OrganizationInvitation extends Model
{
    use BelongsToOrganization, HasUlids;

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->cancelled_at === null && $this->expires_at->isFuture();
    }

    public function isExpired(): bool
    {
        return $this->accepted_at === null && $this->cancelled_at === null && $this->expires_at->isPast();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }
}
