<?php

namespace Tests\Unit\Assessment\Advanced;

use App\Services\Assessment\Advanced\TimelineCriteria;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TimelineCriteriaTest extends TestCase
{
    /** @return array{name: string, kind: string, levels: list<array<string, mixed>>} */
    private function scale(string $name = 'Escala 1 a 5'): array
    {
        return ['name' => $name, 'kind' => 'levels', 'levels' => [
            ['code' => '1', 'label' => 'Insuficiente', 'sequence' => 1, 'is_negative' => true],
            ['code' => '2', 'label' => 'Suficiente', 'sequence' => 2, 'is_negative' => false],
        ]];
    }

    #[Test]
    public function the_scale_signature_is_stable_and_ignores_only_the_name(): void
    {
        $this->assertSame(TimelineCriteria::levels($this->scale()), TimelineCriteria::levels($this->scale()));
        $this->assertSame(TimelineCriteria::levels($this->scale()), TimelineCriteria::levels($this->scale('Outro nome')));
    }

    #[Test]
    public function any_change_to_a_level_changes_the_signature(): void
    {
        $base = TimelineCriteria::levels($this->scale());

        foreach ([['label', 'Fraco'], ['code', '9'], ['sequence', 7], ['is_negative', false]] as [$key, $value]) {
            $changed = $this->scale();
            $changed['levels'][0][$key] = $value;

            $this->assertNotSame($base, TimelineCriteria::levels($changed), "«{$key}» must be part of the signature");
        }

        $dropped = $this->scale();
        array_pop($dropped['levels']);
        $this->assertNotSame($base, TimelineCriteria::levels($dropped));

        $other = $this->scale();
        $other['kind'] = 'values';
        $this->assertNotSame($base, TimelineCriteria::levels($other));
    }

    #[Test]
    public function a_scale_that_is_not_known_has_no_signature(): void
    {
        $this->assertNull(TimelineCriteria::levels(null));
        $this->assertNull(TimelineCriteria::levels(['name' => 'sem tipo', 'levels' => []]));
    }

    #[Test]
    public function the_percentage_signature_is_the_domains_and_their_weights_whatever_the_order_or_the_spelling(): void
    {
        $one = TimelineCriteria::percentages([
            ['domain_id' => 2, 'weight_percent' => '30.0000'],
            ['domain_id' => 1, 'weight_percent' => '70'],
        ]);
        $other = TimelineCriteria::percentages([
            ['domain_id' => 1, 'weight_percent' => '70.0000'],
            ['domain_id' => 2, 'weight_percent' => '30.00'],
        ]);

        $this->assertNotNull($one);
        $this->assertSame($one, $other);
    }

    #[Test]
    public function a_different_weight_or_a_different_domain_is_a_different_signature(): void
    {
        $base = TimelineCriteria::percentages([['domain_id' => 1, 'weight_percent' => '50'], ['domain_id' => 2, 'weight_percent' => '50']]);

        $this->assertNotSame($base, TimelineCriteria::percentages([['domain_id' => 1, 'weight_percent' => '60'], ['domain_id' => 2, 'weight_percent' => '40']]));
        $this->assertNotSame($base, TimelineCriteria::percentages([['domain_id' => 1, 'weight_percent' => '50'], ['domain_id' => 3, 'weight_percent' => '50']]));
        $this->assertNotSame($base, TimelineCriteria::percentages([['domain_id' => 1, 'weight_percent' => '100']]));
    }

    #[Test]
    public function criteria_that_cannot_be_read_have_no_signature(): void
    {
        $this->assertNull(TimelineCriteria::percentages([]));
        $this->assertNull(TimelineCriteria::percentages([['domain_id' => 1]]));
        $this->assertNull(TimelineCriteria::percentages([['domain_id' => 1, 'weight_percent' => null]]));
        $this->assertNull(TimelineCriteria::percentages([['weight_percent' => '50']]));
    }
}
