<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The school as it appears on a document.
 *
 * Scoped to the tenant like everything else, so one school can never read or
 * overwrite another's letterhead — and the global scope throws rather than
 * falling back to «all organizations» (ADR-0002).
 *
 * NOTHING HERE IS ACADEMIC. It is a name, an address and a logo; no screen
 * that computes a result ever reads it.
 *
 * @property int $id
 * @property int $organization_id
 * @property string|null $official_name
 * @property string|null $short_name
 * @property string|null $address
 * @property string|null $postal_code
 * @property string|null $locality
 * @property string|null $country
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $website
 * @property string|null $school_code
 * @property string|null $tax_number
 * @property string|null $department
 * @property string|null $footer_note
 * @property string|null $logo_path
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'official_name', 'short_name', 'address', 'postal_code', 'locality', 'country',
    'phone', 'email', 'website', 'school_code', 'tax_number', 'department',
    'footer_note', 'logo_path',
])]
class OrganizationIdentity extends Model
{
    use BelongsToOrganization;

    /** Whether there is anything at all to put on a document. */
    public function isEmpty(): bool
    {
        foreach ($this->getAttributes() as $key => $value) {
            if (in_array($key, ['id', 'organization_id', 'created_at', 'updated_at'], true)) {
                continue;
            }

            if ($value !== null && $value !== '') {
                return false;
            }
        }

        return true;
    }
}
