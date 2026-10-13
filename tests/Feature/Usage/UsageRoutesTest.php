<?php

namespace Tests\Feature\Usage;

use App\Support\Usage\UsageFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A aplicação de `usage:<feature>` às rotas reais (routes/web.php).
 *
 * A funcionalidade efetiva de uma rota é o parâmetro do ÚLTIMO `usage:` da sua
 * lista de middleware: o grupo escreve primeiro, a rota (mais interior) por cima.
 */
class UsageRoutesTest extends TestCase
{
    use RefreshDatabase;

    private function effectiveFeature(LaravelRoute $route): ?string
    {
        $feature = null;

        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'usage:')) {
                $feature = substr($middleware, 6);
            }
        }

        return $feature;
    }

    private function routeNamed(string $name): LaravelRoute
    {
        $route = Route::getRoutes()->getByName($name);
        $this->assertNotNull($route, "A rota {$name} não existe.");

        return $route;
    }

    #[Test]
    public function every_usage_parameter_on_a_real_route_is_a_usage_feature(): void
    {
        $checked = 0;

        foreach (Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, 'usage:')) {
                    $checked++;
                    $this->assertNotNull(
                        UsageFeature::tryFrom(substr($middleware, 6)),
                        "{$route->uri()} usa um parâmetro de utilização desconhecido: {$middleware}",
                    );
                }
            }
        }

        $this->assertGreaterThan(100, $checked);
    }

    #[Test]
    public function key_routes_resolve_to_the_documented_feature(): void
    {
        $expected = [
            'lessons.index' => UsageFeature::Lessons,
            'lessons.summary.update' => UsageFeature::Lessons,
            'classes.show' => UsageFeature::Classes,
            'classes.store' => UsageFeature::Classes,
            'students.index' => UsageFeature::Classes,
            'classes.notebook.index' => UsageFeature::ClassNotebook,
            'classes.notebook.store' => UsageFeature::ClassNotebook,
            'classes.characterisation.show' => UsageFeature::Characterisation,
            'classes.characterisation-imports.store' => UsageFeature::Characterisation,
            'classes.roster-imports.confirm' => UsageFeature::ImportExport,
            'classes.photos.store' => UsageFeature::ImportExport,
            'reports.index' => UsageFeature::Reports,
            'reports.export.pdf' => UsageFeature::Reports,
            'instruments.index' => UsageFeature::Assessment,
            'results.index' => UsageFeature::Assessment,
            'classifications.decide' => UsageFeature::Assessment,
            'evaluation-sheets.store' => UsageFeature::Assessment,
            'exports.inovar.generate' => UsageFeature::ImportExport,
            'evaluation-sheets.inovar.confirm' => UsageFeature::ImportExport,
            'data-imports.confirm' => UsageFeature::ImportExport,
            'data-exports.store' => UsageFeature::ImportExport,
            'timetable-imports.confirm' => UsageFeature::ImportExport,
            'academic-calendar-imports.confirm' => UsageFeature::ImportExport,
            'calendar.index' => UsageFeature::Calendar,
            'calendar.events.store' => UsageFeature::Calendar,
        ];

        foreach ($expected as $name => $feature) {
            $this->assertSame($feature->value, $this->effectiveFeature($this->routeNamed($name)), "Funcionalidade efetiva de {$name}");
        }
    }

    #[Test]
    public function whole_route_families_resolve_to_one_feature(): void
    {
        $families = [
            'interventions.' => UsageFeature::Interventions,
            'records.' => UsageFeature::Records,
            'data-exports.' => UsageFeature::ImportExport,
            'data-imports.' => UsageFeature::ImportExport,
            'timetable-imports.' => UsageFeature::ImportExport,
            'academic-calendar-imports.' => UsageFeature::ImportExport,
            'classes.roster-imports.' => UsageFeature::ImportExport,
            'classes.notebook.' => UsageFeature::ClassNotebook,
            'classes.characterisation.' => UsageFeature::Characterisation,
            'classes.characterisation-imports.' => UsageFeature::Characterisation,
            'reports.' => UsageFeature::Reports,
        ];

        foreach ($families as $prefix => $feature) {
            $seen = 0;

            foreach (Route::getRoutes() as $route) {
                $name = $route->getName();

                if ($name === null || ! str_starts_with($name, $prefix)) {
                    continue;
                }

                $seen++;
                $this->assertSame($feature->value, $this->effectiveFeature($route), "Funcionalidade efetiva de {$name}");
            }

            $this->assertGreaterThan(0, $seen, "Nenhuma rota com o prefixo {$prefix}");
        }
    }

    #[Test]
    public function the_dashboard_settings_support_help_activity_and_admin_are_not_measured(): void
    {
        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();

            if ($name === 'dashboard' || preg_match('/^(settings|support|help|activity|admin)\./', $name) === 1) {
                $this->assertNull($this->effectiveFeature($route), "{$name} não devia ter funcionalidade de utilização");
            }
        }
    }
}
