<?php

namespace Trafficdesign\SmokeTest;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Route;

/**
 * Abstrakte Basisklasse für automatisierte Smoke-Tests.
 *
 * Jede Route wird als eigenständiger Testfall ausgeführt – bei einem Fehler
 * sieht man sofort welche Route betroffen ist, ohne dass der Test beim ersten
 * Fehler stoppt.
 *
 * Verwendung im eigenen Projekt:
 *
 *   class SmokeTest extends \Trafficdesign\SmokeTest\SmokeTestCase
 *   {
 *       protected static array $except = [
 *           'logout',          // würde den User ausloggen
 *           'delete-account',  // destruktive Aktion
 *       ];
 *
 *       protected function createUser(): Authenticatable
 *       {
 *           return User::factory()->create(['role' => 'admin']);
 *       }
 *   }
 */
abstract class SmokeTestCase extends TestCase
{
    use RefreshDatabase;

    /**
     * URIs die vom Test ausgeschlossen werden (exakter Match).
     *
     * Im eigenen Projekt als static property überschreiben:
     *
     *   protected static array $except = [
     *       'logout',
     *       'delete-account',
     *       'confirm-password',
     *       'debug-sentry',
     *   ];
     *
     * @var array<int, string>
     */
    protected static array $except = [];

    /**
     * Framework-interne Routen die immer ausgeschlossen werden.
     *
     * @var array<int, string>
     */
    private static array $frameworkExcluded = [
        'up',
        'sanctum/csrf-cookie',
        '_ignition/health-check',
    ];

    /**
     * Den User für alle Route-Tests erstellen.
     *
     * Den höchst-privilegierten User verwenden, um maximale Routen-Abdeckung
     * zu erreichen. Wird einmal pro Testfall aufgerufen.
     */
    abstract protected function createUser(): Authenticatable;

    /**
     * Prüft dass die Route keinen 4xx oder 5xx Fehler zurückgibt.
     *
     * Status < 400 ist erlaubt: 200 OK, 302 Redirect.
     * Status >= 400 schlägt fehl: 401, 403, 404, 500.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('routeProvider')]
    public function test_route_is_accessible(string $url): void
    {
        $user = $this->createUser();
        $response = $this->actingAs($user)->get($url);

        $this->assertTrue(
            $response->status() < 400,
            sprintf(
                "Route %s schlug fehl mit Status %d.\nException: %s",
                $url,
                $response->status(),
                $response->exception?->getMessage() ?? 'keine Exception-Details verfügbar'
            )
        );
    }

    /**
     * Liefert alle parameterfreien GET-Routen als DataProvider.
     *
     * DataProvider-Methoden laufen vor setUp() – daher wird die App hier
     * manuell gebootet, falls sie noch nicht initialisiert ist.
     *
     * @return array<string, array{string}>
     */
    public static function routeProvider(): array
    {
        // DataProvider läuft vor setUp() – App manuell booten falls nötig
        $app = require getcwd().'/bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        $except = array_merge(self::$frameworkExcluded, static::$except);

        return collect(Route::getRoutes()->getRoutes())
            ->filter(function ($route) use ($except) {
                return in_array('GET', $route->methods())
                    && ! str_contains($route->uri(), '{')
                    && ! in_array($route->uri(), $except);
            })
            ->mapWithKeys(function ($route) {
                $url = '/'.ltrim($route->uri(), '/');

                return [$url => [$url]];
            })
            ->toArray();
    }
}
