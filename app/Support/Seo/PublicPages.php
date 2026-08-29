<?php

namespace App\Support\Seo;

/**
 * Every public, indexable page of the marketing site, in one list.
 *
 * The blade head, the sitemap, robots.txt, the theme lock and the routes all
 * used to know about «the landing and the three legal pages» separately, and
 * each new page would have had to be added in five places. Now a page exists
 * when it is here: its path, the Inertia component that renders it, and the
 * two strings a search result shows.
 *
 * TITLES ARE ≤ 60 CHARACTERS AND DESCRIPTIONS ≤ 160, asserted by
 * MarketingPagesTest — the limits a result page renders before truncating.
 * The landing page's own strings stay in LandingSeo (they carry the JSON-LD
 * and older tests); this table points at them rather than repeating them.
 *
 * @phpstan-type PublicPage array{path: string, component: string, title: string, description: string, changefreq: string, priority: string, slug?: string}
 */
class PublicPages
{
    public const FEATURE_SLUGS = ['avaliacao', 'turmas', 'acompanhamento', 'aulas-e-sumarios', 'relatorios'];

    /**
     * @return list<PublicPage>
     */
    public static function all(): array
    {
        return [
            self::page('/', 'Welcome', LandingSeo::TITLE, LandingSeo::DESCRIPTION, 'weekly', '1.0'),

            self::feature('avaliacao', 'Avaliação de alunos com critérios próprios | Lapispro',
                'Domínios, ponderações, instrumentos e escala da sua escola. O Lapispro calcula a média ponderada e propõe a classificação; o professor decide sempre.'),
            self::feature('turmas', 'Gestão de turmas e alunos para professores | Lapispro',
                'Importe a pauta que a escola já lhe deu, com números, nomes e fotografias. Turmas, alunos e anos letivos organizados num único lugar.'),
            self::feature('acompanhamento', 'Acompanhamento pedagógico e evolução do aluno | Lapispro',
                'Quadro síntese, estatística da turma e evolução de cada aluno por domínio e por período. Estratégias e registos fora do cálculo.'),
            self::feature('aulas-e-sumarios', 'Aulas, sumários e horário do professor | Lapispro',
                'Horário semanal, aulas com sumário, sequências reutilizáveis e a agenda do ano letivo — ao lado das turmas e das classificações.'),
            self::feature('relatorios', 'Relatórios de avaliação e pautas | Lapispro',
                'Relatórios cujas secções partem do que já registou, pautas por período e quadro síntese exportável. Finalizar fixa o documento.'),

            self::page('/planos', 'marketing/Plans', 'Planos e preços para professores | Lapispro',
                'Base gratuito, Pro por 44,90 € por ano e Institucional para escolas. Sem cartão no Base; condição Membro Fundador para os primeiros 250.', 'weekly', '0.9'),
            self::page('/seguranca', 'marketing/Security', 'Segurança e proteção de dados de alunos | Lapispro',
                'Pseudonimização, isolamento por organização, 2FA e passkeys, registo de operações e IA sem dados identificáveis. RGPD desde a arquitetura.', 'monthly', '0.7'),
            self::page('/sobre', 'marketing/About', 'Sobre o Lapispro e contacto | Lapispro',
                'Quem faz o Lapispro, a entidade responsável pelos dados e como falar connosco. Software para professores feito em Portugal.', 'monthly', '0.5'),

            self::page('/termos', 'legal/Document', 'Termos de Utilização — Lapispro', 'Os termos que regem a utilização do Lapispro.', 'yearly', '0.3'),
            self::page('/privacidade', 'legal/Document', 'Política de Privacidade — Lapispro', 'Como o Lapispro trata os dados pessoais de professores e alunos.', 'yearly', '0.3'),
            self::page('/tratamento-de-dados', 'legal/Document', 'Acordo de Tratamento de Dados — Lapispro', 'O acordo de tratamento de dados entre a escola e o Lapispro.', 'yearly', '0.3'),
        ];
    }

    /**
     * The page for the request being rendered, or null when the page is not
     * public (and therefore noindex — see app.blade.php).
     *
     * Matched by component AND path, because the three legal pages share one
     * component and the five feature pages share another.
     *
     * @return PublicPage|null
     */
    public static function current(string $component, string $requestPath): ?array
    {
        $path = '/'.ltrim($requestPath, '/');

        foreach (self::all() as $page) {
            if ($page['component'] === $component && $page['path'] === $path) {
                return $page;
            }
        }

        return null;
    }

    public static function isPublicComponent(string $component): bool
    {
        foreach (self::all() as $page) {
            if ($page['component'] === $component) {
                return true;
            }
        }

        return false;
    }

    /** Absolute URL of a page, rooted at the declared public address. */
    public static function url(string $path): string
    {
        return LandingSeo::canonical().($path === '/' ? '' : $path);
    }

    /**
     * @return PublicPage
     */
    private static function feature(string $slug, string $title, string $description): array
    {
        return [...self::page('/funcionalidades/'.$slug, 'marketing/Feature', $title, $description, 'monthly', '0.8'), 'slug' => $slug];
    }

    /**
     * @return PublicPage
     */
    private static function page(string $path, string $component, string $title, string $description, string $changefreq, string $priority): array
    {
        return compact('path', 'component', 'title', 'description', 'changefreq', 'priority');
    }
}
