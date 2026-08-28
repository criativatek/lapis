<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Os dados de faturação de uma organização.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $name
 * @property string|null $tax_number
 * @property string $address_line1
 * @property string|null $address_line2
 * @property string $postal_code
 * @property string $city
 * @property string $country
 * @property string $email
 */
#[Fillable([
    'organization_id', 'name', 'tax_number', 'address_line1', 'address_line2',
    'postal_code', 'city', 'country', 'email',
])]
class BillingProfile extends Model
{
    use BelongsToOrganization;

    /**
     * A morada como se escreve num envelope — para os ecrãs, e mais tarde para
     * o documento de venda.
     *
     * @return list<string>
     */
    public function addressLines(): array
    {
        return array_values(array_filter([
            $this->name,
            $this->tax_number === null ? null : 'NIF '.$this->tax_number,
            $this->address_line1,
            $this->address_line2,
            trim($this->postal_code.' '.$this->city),
        ]));
    }
}
