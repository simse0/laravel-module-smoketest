<?php

namespace Trafficdesign\SmokeTest;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Route;

/**
 * Abstrakte Basisklasse für automatisierte Smoke-Tests.
 *
 * Funktioniert out-of-the-box für jedes Standard-Laravel-Projekt.
 * Alle Einstellungen können in der eigenen SmokeTest-Klasse überschrieben werden.
 *
 * Minimales Beispiel (kein Setup nötig):
 *
 *   class SmokeTest extends \Trafficdesign\SmokeTest\SmokeTestCase
 *   {
 *       protected static array $except = ['logout'];
 *   }
 *
 * Erweitertes Beispiel (mit Rollen/Organisationen):
 *
 *   class SmokeTest extends \Trafficdesign\SmokeTest\SmokeTestCase
 *   {
 *       protected function createUser(): Authenticatable
 *       {
 *           return User::factory()->withRole('admin')->create();
 *       }
 *   }
 */
abstract class SmokeTestCase extends TestCase
{
    use RefreshDatabase;

    /**
     * URIs die vom Test ausgeschlossen werden (exakter Match).
     *
     * Typische Kandidaten:
     *   - Logout / Session-beendende Routen
     *   - Destruktive Aktionen (Account löschen, Daten zurücksetzen)
     *   - Debug-Endpoints (Sentry, Telescope, Horizon)
     *   - Routen mit MySQL-spezifischen Queries die unter SQLite fehlschlagen
     *
     * @var array<int, string>
     */
    protected static array $except = [];

    /**
     * Nur Routen mit diesen URI-Präfixen testen.
     * Leer = alle Routen testen.
     *
     * Beispiel: ['admin', 'dashboard'] testet nur /admin/* und /dashboard/*
     *
     * @var array<int, string>
     */
    protected static array $onlyPrefixes = [];

    /**
     * Framework-interne Routen die immer ausgeschlossen werden.
     * Kann überschrieben werden um eigene interne Routen hinzuzufügen.
     *
     * @var array<int, string>
     */
    protected static array $frameworkExcluded = [
        'up',
        'sanctum/csrf-cookie',
        '_ignition/health-check',
    ];

    /**
     * Zusätzliches Setup vor jedem Test.
     *
     * Hier z.B. Permissions seeden, Konfiguration setzen etc.
     * Wird nach RefreshDatabase ausgeführt, also nach dem DB-Reset.
     *
     * Beispiel:
     *   protected function setUpSmokeTest(): void
     *   {
     *       $this->seed(PermissionSeeder::class);
     *   }
     */
    protected function setUpSmokeTest(): void
    {
        // Standard: nichts. Im Projekt überschreiben falls nötig.
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSmokeTest();
    }

    /**
     * Den User für alle Route-Tests erstellen.
     *
     * Standard: liest das User-Modell aus config('auth.providers.users.model')
     * und erstellt eine Instanz per Factory. Funktioniert für alle
     * Standard-Laravel-Projekte ohne Anpassung.
     *
     * Überschreiben wenn das Projekt spezifische Rollen, Organisationen
     * oder andere Abhängigkeiten benötigt.
     */
    protected function createUser(): Authenticatable
    {
        /** @var class-string<\Illuminate\Database\Eloquent\Model> $model */
        $model = config('auth.providers.users.model');

        return $model::factory()->create();
    }

    /**
     * Prüft dass die Route keinen 4xx oder 5xx Fehler zurückgibt.
     *
     * Status < 400 ist erlaubt: 200 OK, 302 Redirect.
     * Status >= 400 schlägt fehl: 401, 403, 404, 422, 500.
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
     * Liefert alle zu testenden GET-Routen als DataProvider.
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

        $except = array_merge(static::$frameworkExcluded, static::$except);

        return collect(Route::getRoutes()->getRoutes())
            ->filter(function ($route) use ($except) {
                if (! in_array('GET', $route->methods())) {
                    return false;
                }
                if (str_contains($route->uri(), '{')) {
                    return false;
                }
                if (in_array($route->uri(), $except)) {
                    return false;
                }
                if (! empty(static::$onlyPrefixes)) {
                    $matchesPrefix = false;
                    foreach (static::$onlyPrefixes as $prefix) {
                        if (str_starts_with($route->uri(), $prefix)) {
                            $matchesPrefix = true;
                            break;
                        }
                    }

                    return $matchesPrefix;
                }

                return true;
            })
            ->mapWithKeys(function ($route) {
                $url = '/'.ltrim($route->uri(), '/');

                return [$url => [$url]];
            })
            ->toArray();
    }
}
