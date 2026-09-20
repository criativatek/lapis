<?php

namespace Tests\Unit\Characterisation;

use App\Support\Characterisation\AcronymDictionary;
use App\Support\Characterisation\SuggestAcronymCorrection;
use Tests\TestCase;

/**
 * §19 — offering a plausible correction for a token OCR may have misread.
 *
 * Every candidate has to come from AcronymDictionary — this class invents
 * nothing — and the edit distance stays small enough that a suggestion is
 * always explainable as "one character different", never a loose guess.
 */
class SuggestAcronymCorrectionTest extends TestCase
{
    private function suggester(): SuggestAcronymCorrection
    {
        return new SuggestAcronymCorrection(new AcronymDictionary);
    }

    public function test_a_single_character_near_miss_is_suggested(): void
    {
        $suggestion = $this->suggester()->suggest('ACN5');

        $this->assertNotNull($suggestion);
        $this->assertSame('ACNS', $suggestion->token);
        $this->assertSame('Adaptação curricular não significativa', $suggestion->expansion);
    }

    public function test_an_unconfirmed_dictionary_token_can_also_be_suggested(): void
    {
        // RTP is a KNOWN acronym with no confirmed expansion — still a
        // legitimate suggestion target: the dictionary is the single source,
        // confirmed or not (see the class's own docblock).
        $suggestion = $this->suggester()->suggest('RTQ');

        $this->assertNotNull($suggestion);
        $this->assertSame('RTP', $suggestion->token);
        $this->assertNull($suggestion->expansion);
    }

    public function test_a_token_with_no_plausible_candidate_gets_no_suggestion(): void
    {
        $this->assertNull($this->suggester()->suggest('XYZQW'));
    }

    public function test_a_token_already_spelled_correctly_gets_no_suggestion(): void
    {
        // PLNM is confirmed but not a measure — it still reaches this class
        // as "unresolved" (DecreeLaw54CodeResolver::extractUnknownAcronyms),
        // and typed correctly it must never suggest itself.
        $this->assertNull($this->suggester()->suggest('PLNM'));
    }

    public function test_a_non_acronym_shaped_string_gets_no_suggestion(): void
    {
        // A free-text sentence is never "close to" ACNS in any sense worth
        // acting on — the shape test rules it out before distance is even
        // computed.
        $this->assertNull($this->suggester()->suggest('Precisa de apoio no recreio'));
    }
}
