# trafficdesign/laravel-smoke-test

Automatisierter Smoke-Test für Laravel-Anwendungen. Das Package iteriert dynamisch über alle parameterfreien GET-Routen der Anwendung und prüft, dass keine HTTP 500-Fehler zurückgegeben werden.

**Neue Routen werden automatisch mitgetestet** – es muss nichts manuell erweitert werden.

---

## Wie es funktioniert

Das Package stellt eine abstrakte Basisklasse `SmokeTestCase` bereit. Diese erkennt automatisch alle GET-Routen ohne URL-Parameter und gruppiert sie nach Zugriffstyp (Admin, Org-Admin, eingeloggt, öffentlich). Pro Gruppe wird ein Test-User eingeloggt und jede Route aufgerufen – schlägt eine Route mit einem 500-Fehler fehl, schlägt der Test an.

Erlaubte Status-Codes: `200`, `302` (Redirect), `403` (Forbidden), `404` (Not Found).  
Nicht erlaubt: `500` (Server Error).

---

## Voraussetzungen

- PHP 8.2+
- Laravel 11 oder 12
- PHPUnit 11+
- SQLite als Test-Datenbank (Standard bei Laravel)

---

## Installation

### Option A – Via GitHub (empfohlen, ohne Packagist)

**Schritt 1:** Repository in der `composer.json` des Projekts eintragen:

```json
"repositories": [
    {
        "type": "vcs",
        "url": "https://github.com/trafficdesign/laravel-smoke-test"
    }
]
```

**Schritt 2:** Package als Dev-Dependency installieren:

```bash
composer require trafficdesign/laravel-smoke-test:dev-master --dev
```

### Option B – Lokaler Pfad (Monorepo / lokale Entwicklung)

```json
"repositories": [
    {
        "type": "path",
        "url": "/var/www/packages/laravel-smoke-test"
    }
]
```

```bash
composer require trafficdesign/laravel-smoke-test:@dev --dev
```

---

## Einrichtung

### Schritt 1 – Test-Datei generieren

```bash
php artisan vendor:publish --tag=smoke-test
```

Das erstellt `tests/Feature/SmokeTest.php` mit einer vorkonfigurierten Klasse auf Basis des mitgelieferten Stubs.

### Schritt 2 – Test-User-Setup anpassen

Die generierte Datei enthält zwei Methoden, die an das Rollen- und User-System des Projekts angepasst werden müssen:

```php
protected function createAdminUser(): Authenticatable
{
    // Hier einen Admin-User mit Factories erstellen
    return User::factory()->create(['role' => 'admin']);
}

protected function createOrgAdminUser(): Authenticatable
{
    // Hier einen Org-Admin-User erstellen
    // Kann identisch mit createAdminUser() sein, wenn keine Trennung existiert
    return User::factory()->create(['role' => 'org-admin']);
}
```

Diese Methoden sind `abstract` und **müssen** implementiert werden. Sie werden nur einmal pro Test-Gruppe aufgerufen.

### Schritt 3 – Smoke-Tests ausführen

```bash
php artisan test --compact tests/Feature/SmokeTest.php
```

Beim ersten Lauf können vereinzelt 500-Fehler auftreten, die **keine echten Bugs** sind – z.B. Routen mit MySQL-spezifischen Queries (`FIELD()`, komplexe `HAVING`-Klauseln), die unter SQLite nicht funktionieren. Diese in `$sqliteIncompatibleRoutes` eintragen (siehe Konfiguration).

---

## Konfiguration

Alle folgenden Properties können in der eigenen `SmokeTest`-Klasse überschrieben werden:

```php
class SmokeTest extends SmokeTestCase
{
    /**
     * Routen mit MySQL-spezifischen Queries, die unter SQLite fehlschlagen.
     * Kein echter Bug – nur Test-Umgebungs-Inkompatibilität.
     */
    protected array $sqliteIncompatibleRoutes = [
        'dashboard',               // z.B. HAVING auf aggregate subquery
        'org-admin/organizations', // z.B. FIELD() für benutzerdefinierte Sortierung
    ];

    /**
     * URIs die grundsätzlich übersprungen werden.
     * Standard: Laravel- und Framework-interne Routen.
     */
    protected array $excludedUris = [
        'up',
        'sanctum/csrf-cookie',
        '_ignition/health-check',
    ];

    /**
     * Middleware-Namen – anpassen falls im Projekt abweichend benannt.
     */
    protected string $adminMiddleware = 'admin';
    protected string $orgAdminMiddleware = 'org-admin';
}
```

---

## Welche Tests werden ausgeführt

| Test-Methode | Eingeloggter User | Welche Routen |
|---|---|---|
| `test_admin_routes_return_no_500` | Admin-User | Routen mit `admin`-Middleware |
| `test_org_admin_routes_return_no_500` | Org-Admin-User | Routen mit `org-admin`-Middleware |
| `test_authenticated_routes_return_no_500` | Admin-User | Routen mit `auth` (ohne Admin-Middleware) |
| `test_public_routes_return_no_500` | Nicht eingeloggt | Routen ohne `auth`-Middleware |

Hat eine Gruppe keine Routen, wird der Test automatisch mit `markTestSkipped()` übersprungen.

---

## Vollständiges Beispiel

```php
<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Trafficdesign\SmokeTest\SmokeTestCase;

class SmokeTest extends SmokeTestCase
{
    /**
     * Diese Routen nutzen MySQL-spezifische SQL-Funktionen und laufen
     * in der SQLite-Testumgebung nicht – kein echter Bug.
     */
    protected array $sqliteIncompatibleRoutes = [
        'dashboard',
        'org-admin/organizations',
    ];

    protected function createAdminUser(): Authenticatable
    {
        $organization = Organization::factory()->create([
            'contract_status' => 'active',
        ]);

        $role = Role::firstOrCreate(
            ['organization_id' => $organization->id, 'slug' => 'administrator'],
            ['name' => 'Administrator', 'is_org_admin' => false]
        );

        return User::factory()->create([
            'organization_id' => $organization->id,
            'role_id' => $role->id,
            'email_verified_at' => now(),
        ]);
    }

    protected function createOrgAdminUser(): Authenticatable
    {
        $organization = Organization::factory()->create([
            'contract_status' => 'active',
        ]);

        $role = Role::firstOrCreate(
            ['organization_id' => $organization->id, 'slug' => 'organisations-administrator'],
            ['name' => 'Organisations-Administrator', 'is_org_admin' => true]
        );

        return User::factory()->create([
            'organization_id' => $organization->id,
            'role_id' => $role->id,
            'email_verified_at' => now(),
        ]);
    }
}
```

---

## Warum `firstOrCreate` statt `create` für Rollen?

Laravel's `RefreshDatabase` nutzt bei SQLite standardmäßig Transaktionen statt TRUNCATE. Das Auto-Increment setzt sich dabei nicht zurück. Wird `create` verwendet und der Test mehrfach ausgeführt, kann es zu `UniqueConstraintViolationException` kommen, wenn eine Rolle mit derselben Kombination aus `organization_id` und `slug` nochmals angelegt wird. `firstOrCreate` verhindert das.

---

## Troubleshooting

**500-Fehler beim ersten Lauf trotz funktionierender Route in Production**

Ursache: Die Route nutzt eine MySQL-spezifische SQL-Funktion (`FIELD()`, `REGEXP`, komplexe `HAVING`-Klauseln), die SQLite nicht unterstützt.  
Lösung: Route in `$sqliteIncompatibleRoutes` eintragen.

**Test schlägt fehl mit "class not found"**

Ursache: Autoload nach Installation nicht aktualisiert.  
Lösung: `composer dump-autoload` ausführen.

**Alle Tests werden als "skipped" markiert**

Ursache: Keine passenden Routen gefunden – die Middleware-Namen im Projekt weichen von den Defaults ab.  
Lösung: `$adminMiddleware` und `$orgAdminMiddleware` in der eigenen Klasse überschreiben.
