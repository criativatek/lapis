<?php

namespace App\Support\Seo;

/**
 * Everything a search engine or a social scraper reads about the public
 * landing page, in one place.
 *
 * IT IS HERE AND NOT IN THE BLADE because the same strings are needed three
 * or four times each — `<title>`, `og:title`, `twitter:title`; description,
 * `og:description`, `twitter:description`, and again inside the JSON-LD. Four
 * literals that must agree is four chances for them to stop agreeing, and the
 * one that drifts is invisible: nothing renders wrong, the page just starts
 * telling Google something different from what it tells LinkedIn.
 *
 * IT IS ALSO WHY THIS IS TESTABLE. `LandingSeoTest` asserts the title fits in
 * a result page, the description fits in a snippet, and every price in the
 * structured data is one of the approved figures — none of which can be
 * checked against a string typed inside a template.
 *
 * INERTIA SSR IS OFF (config/inertia.php), so ANY tag written by the Vue
 * `<Head>` lands only after the bundle has run. Everything a robot must read
 * is rendered by the server, from here. That is also why the structured data
 * below carries a real `featureList`: it is the one substantial, machine-
 * readable description of the product that exists in the HTML source before a
 * single line of JavaScript executes.
 */
class LandingSeo
{
    /**
     * 60 characters, which is inside what a result page renders before it
     * truncates — brand first (this is a branded product), then the phrase the
     * page is actually competing for, then the three things it does.
     *
     * «IA» rather than «IA pedagógica» is a deliberate trade: the longer form
     * pushed the title to 70 characters and cost the visible «Turmas». The
     * full phrase is carried by the description, the Open Graph title, a
     * section heading and the body copy, where it has room.
     */
    public const TITLE = 'LÁPIS | Plataforma para Professores — Avaliação, Turmas e IA';

    /**
     * 157 characters. Says what the product IS in the first three words,
     * because a snippet that opens with a slogan wastes the only line most
     * people read, and closes on the position that separates this from a
     * content generator.
     */
    public const DESCRIPTION = 'Plataforma para professores: gestão de turmas, avaliação de alunos, acompanhamento pedagógico, aulas, sumários e relatórios. A IA sugere; o professor decide.';

    /** Shorter, because a social card truncates harder than a result page. */
    public const SOCIAL_TITLE = 'LÁPIS — Plataforma para Professores';

    public const SOCIAL_DESCRIPTION = 'Avaliação de alunos, gestão de turmas, acompanhamento pedagógico, aulas, sumários, relatórios e IA pedagógica numa única plataforma para professores.';

    /**
     * The canonical is built from APP_URL and never from the incoming request,
     * so `www.`, a trailing slash, an `http://` hit or any query string all
     * resolve to the same one address. Nothing here can fix a wrong APP_URL —
     * it must be `https://lapispro.com` in production.
     */
    public static function canonical(): string
    {
        return url('/');
    }

    /**
     * What the application actually does, in the words a teacher would search
     * for. Every line maps to a capability that exists in the module
     * catalogue (`EntitlementsSeeder`) — this list is read by machines, and a
     * feature named here that the product does not have is the same lie as
     * one written on the page.
     *
     * It deliberately spans all three plans: it describes the APPLICATION.
     * Which plan carries what is the comparison table's job, on the page.
     *
     * @return list<string>
     */
    public static function featureList(): array
    {
        return [
            'Gestão de turmas e de alunos',
            'Perfis e critérios de avaliação com ponderações',
            'Instrumentos de avaliação e classificações',
            'Cálculo de classificações explicável',
            'Acompanhamento do progresso dos alunos',
            'Autoavaliação de alunos',
            'Estratégias e medidas pedagógicas',
            'Relatórios de avaliação',
            'Horário do professor',
            'Aulas e sumários',
            'Planeamento e sequências de aulas',
            'Agenda do ano letivo',
            'Análises avançadas e tendências',
            'IA pedagógica de apoio à decisão do professor',
            'Gestão pedagógica para escolas e agrupamentos',
        ];
    }

    /**
     * The two offers that carry a figure.
     *
     * INSTITUCIONAL IS ABSENT ON PURPOSE — its price is «sob consulta», and an
     * `Offer` with no price is not an offer. So is the Fundador condition:
     * 29,90 € is a launch price that ends on a date or on a seat count,
     * whichever comes first, and a promotional figure left in a search index
     * outlives the promotion. Both are on the page, where the conditions are
     * next to them.
     *
     * `priceValidUntil` on the free plan is the end of the school year it is
     * free for — «gratuito no ano letivo 2026/27» is a statement with an
     * expiry, and encoding it without one would be encoding a different claim.
     *
     * @return list<array<string, mixed>>
     */
    public static function offers(): array
    {
        return [
            [
                '@type' => 'Offer',
                'name' => 'LÁPIS Base',
                'price' => '0',
                'priceCurrency' => 'EUR',
                'priceValidUntil' => '2027-08-31',
                'description' => 'Gratuito no ano letivo 2026/27.',
                'url' => url('/').'#planos',
            ],
            [
                '@type' => 'Offer',
                'name' => 'LÁPIS Pro',
                'price' => '44.90',
                'priceCurrency' => 'EUR',
                'description' => 'Subscrição anual. Não existe pagamento mensal.',
                'url' => url('/').'#planos',
            ],
        ];
    }

    /**
     * NO RATING, NO REVIEW COUNT, NO USER COUNT. Nobody has collected one, and
     * `aggregateRating` is the single easiest property to invent and the one
     * that gets a site a manual action when it is invented.
     *
     * @return array<string, mixed>
     */
    public static function structuredData(): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'SoftwareApplication',
            'name' => 'LÁPIS',
            'alternateName' => 'Laboratório de Apoio ao Professor, Informação e Simplificação',
            'applicationCategory' => 'EducationalApplication',
            'applicationSubCategory' => 'Software de avaliação para professores',
            'operatingSystem' => 'Web',
            'softwareVersion' => (string) config('app.version'),
            'inLanguage' => 'pt-PT',
            'url' => self::canonical(),
            'description' => self::DESCRIPTION,
            'featureList' => self::featureList(),
            'audience' => [
                '@type' => 'EducationalAudience',
                'educationalRole' => 'teacher',
            ],
            'offers' => self::offers(),
        ];
    }
}
