<?php

namespace Tests\Feature\Ai;

use App\Support\Privacy\AiPayloadSanitizer;
use App\Support\Privacy\Pseudonyms;
use App\Support\Privacy\SanitisedPayload;
use Error;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

/**
 * WHERE A `SanitisedPayload` IS ALLOWED TO COME FROM.
 *
 * PHP has no friend classes and no package-private visibility, so «only
 * `AiPayloadSanitizer` may build one» cannot be a type. This file is the rest of
 * that guarantee, and it is deliberately made of two different kinds of check:
 *
 *   REFLECTION   the constructor is private, the classes are final. These catch
 *                somebody reopening the hole in the class itself.
 *   SOURCE SCAN  `producedBy(` has exactly one caller in `app/`. This catches
 *                somebody using the remaining door from a new service.
 *
 * The plain-text scan over concatenated source is the same technique
 * `CatalogCoherenceTest` already uses for capability keys — not real static
 * analysis, and not trying to be: good enough to notice «somebody started
 * building payloads over here», which is the whole question.
 */
class PayloadProvenanceTest extends TestCase
{
    // ---------------------------------------------------------------- reflection

    /** `new SanitisedPayload(...)` must not be expressible anywhere. */
    #[Test]
    public function the_payload_constructor_is_private(): void
    {
        $constructor = (new ReflectionClass(SanitisedPayload::class))->getConstructor();

        $this->assertNotNull($constructor);
        $this->assertTrue(
            $constructor->isPrivate(),
            'SanitisedPayload::__construct must stay private — a public one is the accidental bypass this whole design removes.',
        );
    }

    /** A subclass with a widened constructor would reopen exactly that hole. */
    #[Test]
    public function the_privacy_classes_are_final(): void
    {
        foreach ([SanitisedPayload::class, Pseudonyms::class, AiPayloadSanitizer::class] as $class) {
            $this->assertTrue(
                (new ReflectionClass($class))->isFinal(),
                "{$class} must be final: a subclass could widen the constructor or neuter the guard.",
            );
        }
    }

    /** The roster is built by rule or declared absent — never handed in raw. */
    #[Test]
    public function the_pseudonym_map_constructor_is_private_too(): void
    {
        $constructor = (new ReflectionClass(Pseudonyms::class))->getConstructor();

        $this->assertNotNull($constructor);
        $this->assertTrue($constructor->isPrivate());
    }

    #[Test]
    public function constructing_a_payload_directly_is_an_error(): void
    {
        $this->expectException(Error::class);

        // Deliberately illegal: PHP raises Error at runtime for a call to a
        // private constructor from another scope. Left as real code rather than
        // asserted through reflection so that reopening the constructor makes
        // THIS test fail too, not just the one above.
        new SanitisedPayload('texto por sanitizar', [], Pseudonyms::none());
    }

    // ---------------------------------------------------------------- source scan

    /**
     * The one remaining door, and it has one user.
     *
     * If this fails, a new service has started building payloads for itself.
     * That is not necessarily wrong — but it is exactly the decision that has to
     * be looked at rather than merged, because it is the decision to send an
     * engine something the sanitiser never saw.
     */
    #[Test]
    public function the_internal_factory_has_exactly_one_caller_in_the_application(): void
    {
        $callers = $this->filesInAppContaining('SanitisedPayload::producedBy(');

        $this->assertSame(
            ['app/Support/Privacy/AiPayloadSanitizer.php'],
            $callers,
            'SanitisedPayload::producedBy() is @internal to AiPayloadSanitizer. New caller(s): '.implode(', ', $callers),
        );
    }

    /**
     * Belt and braces on the reflection check above: even a `new` that somehow
     * became legal must not appear.
     *
     * `SanitisedPayload.php` itself is excluded, for two different reasons that
     * both apply: a private constructor is legitimately reachable from inside
     * its own class, and the file's docblock quotes the forbidden expression in
     * prose in order to explain that it is forbidden. A plain-text scan cannot
     * tell documentation from code, and making the scan cleverer would make it
     * less trustworthy rather than more.
     */
    #[Test]
    public function nothing_in_the_application_constructs_a_payload_directly(): void
    {
        $callers = array_values(array_diff(
            $this->filesInAppContaining('new SanitisedPayload('),
            ['app/Support/Privacy/SanitisedPayload.php'],
        ));

        $this->assertSame([], $callers);
    }

    /**
     * The gateway does not trust the type either. Two barriers only count as two
     * if the second one is still there.
     */
    #[Test]
    public function the_gateway_still_verifies_the_payload_before_the_wire(): void
    {
        $this->assertNotSame(
            [],
            $this->filesInAppContaining('assertClean('),
            'Nothing calls assertClean() any more — the last-moment check before the wire is gone.',
        );

        $this->assertContains(
            'app/Services/Ai/Gateway/AiGateway.php',
            $this->filesInAppContaining('assertClean('),
        );
    }

    /**
     * Repo-relative paths, sorted, of every PHP file under app/ whose source
     * contains $needle.
     *
     * @return list<string>
     */
    private function filesInAppContaining(string $needle): array
    {
        // Both sides normalised to forward slashes BEFORE the prefix is stripped:
        // on Windows `base_path()` comes back with backslashes, so stripping it
        // from an already-normalised path silently matches nothing and every
        // assertion in this file compares absolute paths to relative ones.
        $root = str_replace('\\', '/', base_path()).'/';
        $matches = [];

        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            if (str_contains(File::get($file->getPathname()), $needle)) {
                $matches[] = str_replace($root, '', str_replace('\\', '/', $file->getPathname()));
            }
        }

        sort($matches);

        return $matches;
    }
}
