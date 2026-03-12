<?php

namespace Trafficdesign\SmokeTest;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Route;

/**
 * Abstrakte Basisklasse für automatisierte Smoke-Tests.
 *
 * Iteriert dynamisch über alle parameterfreien GET-Routen und prüft,
 * dass keine 500-Fehler zurückgegeben werden. Neue Routen werden automatisch
 * mitgetestet – keine manuelle Erweiterung nötig.
 *
 * Verwendung im eigenen Projekt:
 *
 *   class SmokeTest extends \Trafficdesign\SmokeTest\SmokeTestCase
 *   {
 *       protected array $sqliteIncompatibleRoutes = ['dashboard'];
 *
 *       protected function createAdminUser(): Authenticatable { ... }
 *       protected function createOrgAdminUser(): Authenticatable { ... }
 *   }
 */
abstract class SmokeTestCase extends TestCase
{
    use RefreshDatabase;

    /**
     * Routen mit MySQL-spezifischen Queries (FIELD(), HAVING auf Subqueries etc.),
     * die unter SQLite (Test-Umgebung) nicht funktionieren.
     *
     * Im eigenen Projekt überschreiben:
     *   protected array $sqliteIncompatibleRoutes = ['dashboard', 'org-admin/organizations'];
     *
     * @var array<int, string>
     */
    protected array $sqliteIncompatibleRoutes = [];

    /**
     * URIs die grundsätzlich vom Test ausgeschlossen werden (Framework-intern).
     *
     * @var array<int, string>
     */
    protected array $excludedUris = [
        'up',
        'sanctum/csrf-cookie',
        '_ignition/health-check',
    ];

    /**
     * Middleware-Name für Admin-Routen.
     * Im eigenen Projekt überschreiben falls abweichend.
     */
    protected string $adminMiddleware = 'admin';

    /**
     * Middleware-Name für Org-Admin-Routen.
     * Im eigenen Projekt überschreiben falls abweichend.
     */
    protected string $orgAdminMiddleware = 'org-admin';

    /**
     * Admin-User für Tests erstellen.
     * Muss im eigenen Projekt implementiert werden.
     */
    abstract protected function createAdminUser(): Authenticatable;

    /**
     * Org-Admin-User für Tests erstellen.
     * Muss im eigenen Projekt implementiert werden.
     * Kann identisch mit createAdminUser() sein wenn keine Trennung existiert.
     */
    abstract protected function createOrgAdminUser(): Authenticatable;

    // ─────────────────────────────────────────────────────────────────────────
    // Test-Methoden (werden automatisch von PHPUnit erkannt)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Admin-Routen dürfen kein 500 zurückgeben.
     */
    public function test_admin_routes_return_no_500(): void
    {
        $routes = $this->getParameterlessGetRoutes('admin');

        if (empty($routes)) {
            $this->markTestSkipped('Keine Admin-Routen gefunden.');
        }

        $user = $this->createAdminUser();

        foreach ($routes as $uri) {
            $response = $this->actingAs($user)->get('/'.$uri);

            $this->assertNotEquals(
                500,
                $response->status(),
                $this->buildErrorMessage($uri, $response)
            );
        }
    }

    /**
     * Org-Admin-Routen dürfen kein 500 zurückgeben.
     */
    public function test_org_admin_routes_return_no_500(): void
    {
        $routes = $this->getParameterlessGetRoutes('org-admin');

        if (empty($routes)) {
            $this->markTestSkipped('Keine Org-Admin-Routen gefunden.');
        }

        $user = $this->createOrgAdminUser();

        foreach ($routes as $uri) {
            $response = $this->actingAs($user)->get('/'.$uri);

            $this->assertNotEquals(
                500,
                $response->status(),
                $this->buildErrorMessage($uri, $response)
            );
        }
    }

    /**
     * Eingeloggte Routen (ohne Admin-Anforderung) dürfen kein 500 zurückgeben.
     */
    public function test_authenticated_routes_return_no_500(): void
    {
        $routes = $this->getParameterlessGetRoutes('user');

        if (empty($routes)) {
            $this->markTestSkipped('Keine Auth-Routen gefunden.');
        }

        $user = $this->createAdminUser();

        foreach ($routes as $uri) {
            $response = $this->actingAs($user)->get('/'.$uri);

            $this->assertNotEquals(
                500,
                $response->status(),
                $this->buildErrorMessage($uri, $response)
            );
        }
    }

    /**
     * Öffentliche Routen (kein Login) dürfen kein 500 zurückgeben.
     */
    public function test_public_routes_return_no_500(): void
    {
        $routes = $this->getParameterlessGetRoutes('public');

        if (empty($routes)) {
            $this->markTestSkipped('Keine öffentlichen Routen gefunden.');
        }

        foreach ($routes as $uri) {
            $response = $this->get('/'.$uri);

            $this->assertNotEquals(
                500,
                $response->status(),
                $this->buildErrorMessage($uri, $response)
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Hilfsmethoden
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Alle parameterfreien GET-Routen nach Zugriffstyp filtern.
     *
     * @return array<int, string>
     */
    protected function getParameterlessGetRoutes(string $type): array
    {
        $excluded = array_merge($this->excludedUris, $this->sqliteIncompatibleRoutes);

        return collect(Route::getRoutes()->getRoutes())
            ->filter(function ($route) use ($type, $excluded) {
                if (! in_array('GET', $route->methods())) {
                    return false;
                }
                if (str_contains($route->uri(), '{')) {
                    return false;
                }
                if (in_array($route->uri(), $excluded)) {
                    return false;
                }

                $middlewares = collect($route->gatherMiddleware());

                return match ($type) {
                    'admin' => $middlewares->contains($this->adminMiddleware)
                        && ! $middlewares->contains($this->orgAdminMiddleware),
                    'org-admin' => $middlewares->contains($this->orgAdminMiddleware),
                    'user' => $middlewares->contains('auth')
                        && ! $middlewares->contains($this->adminMiddleware)
                        && ! $middlewares->contains($this->orgAdminMiddleware),
                    'public' => ! $middlewares->contains('auth'),
                    default => false,
                };
            })
            ->map(fn ($route) => $route->uri())
            ->values()
            ->toArray();
    }

    /**
     * Aussagekräftige Fehlermeldung mit Exception-Details bauen.
     */
    protected function buildErrorMessage(string $uri, mixed $response): string
    {
        $exception = $response->exception?->getMessage() ?? 'keine Exception-Details verfügbar';

        return "Route /{$uri} hat einen 500-Fehler zurückgegeben.\nException: {$exception}";
    }
}
