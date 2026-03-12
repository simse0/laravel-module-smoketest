# trafficdesign/laravel-smoke-test

Automatisierter Smoke-Test für Laravel-Anwendungen. Iteriert dynamisch über alle parameterfreien GET-Routen und prüft, dass keine 500-Fehler zurückgegeben werden. **Neue Routen werden automatisch mitgetestet** – keine manuelle Erweiterung nötig.

## Installation

```bash
composer require trafficdesign/laravel-smoke-test --dev
```

Via Git-Repository (ohne Packagist):

```json
// composer.json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/trafficdesign/laravel-smoke-test"
        }
    ],
    "require-dev": {
        "trafficdesign/laravel-smoke-test": "^1.0"
    }
}
```

## Einrichtung

### 1. Stub publishen

```bash
php artisan vendor:publish --tag=smoke-test
```

Das erstellt `tests/Feature/SmokeTest.php` mit einer vorkonfigurierten Klasse.

### 2. Setup anpassen

Die generierte Datei enthält `createAdminUser()` und `createOrgAdminUser()`. Diese an das Rollen- und User-System des Projekts anpassen.

### 3. SQLite-Inkompatibilitäten markieren

Beim ersten Lauf können Routen mit MySQL-spezifischen Queries (z.B. `FIELD()`) unter SQLite fehlschlagen. Diese in die `$sqliteIncompatibleRoutes`-Liste eintragen:

```php
protected array $sqliteIncompatibleRoutes = [
    'dashboard',               // nutzt HAVING auf aggregate subquery
    'org-admin/organizations', // nutzt FIELD() für Sortierung
];
```

## Verwendung

```bash
# Smoke-Tests ausführen
php artisan test --compact tests/Feature/SmokeTest.php
```

## Was wird getestet

| Test | User | Middleware |
|---|---|---|
| `test_admin_routes_return_no_500` | Admin | `admin` |
| `test_org_admin_routes_return_no_500` | Org-Admin | `org-admin` |
| `test_authenticated_routes_return_no_500` | Admin | `auth` (ohne Admin) |
| `test_public_routes_return_no_500` | — | keine |

Alle Tests prüfen `assertNotEquals(500, $status)` – 403, 404 und 302 sind erlaubt.

## Konfiguration

In der eigenen `SmokeTest`-Klasse können folgende Properties überschrieben werden:

```php
class SmokeTest extends SmokeTestCase
{
    // Routen mit MySQL-spezifischen Queries
    protected array $sqliteIncompatibleRoutes = ['dashboard'];

    // Grundsätzlich ausgeschlossene URIs (Default: up, sanctum/csrf-cookie, _ignition/health-check)
    protected array $excludedUris = ['up', 'sanctum/csrf-cookie'];

    // Middleware-Namen (falls im Projekt abweichend)
    protected string $adminMiddleware = 'admin';
    protected string $orgAdminMiddleware = 'org-admin';
}
```

## Beispiel einer vollständigen SmokeTest-Klasse

```php
class SmokeTest extends SmokeTestCase
{
    protected array $sqliteIncompatibleRoutes = [
        'dashboard',
    ];

    protected function createAdminUser(): Authenticatable
    {
        $org = Organization::factory()->create(['contract_status' => 'active']);

        $role = Role::firstOrCreate(
            ['organization_id' => $org->id, 'slug' => 'administrator'],
            ['name' => 'Administrator', 'is_org_admin' => false]
        );

        return User::factory()->create([
            'organization_id' => $org->id,
            'role_id' => $role->id,
            'email_verified_at' => now(),
        ]);
    }

    protected function createOrgAdminUser(): Authenticatable
    {
        $org = Organization::factory()->create(['contract_status' => 'active']);

        $role = Role::firstOrCreate(
            ['organization_id' => $org->id, 'slug' => 'organisations-administrator'],
            ['name' => 'Organisations-Administrator', 'is_org_admin' => true]
        );

        return User::factory()->create([
            'organization_id' => $org->id,
            'role_id' => $role->id,
            'email_verified_at' => now(),
        ]);
    }
}
```
