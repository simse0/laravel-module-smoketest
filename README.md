# trafficdesign/laravel-smoke-test

Automatisierter Smoke-Test für Laravel-Anwendungen. Das Package iteriert dynamisch über alle parameterfreien GET-Routen und prüft, dass keine Fehler zurückgegeben werden.

**Neue Routen werden automatisch mitgetestet** – ohne manuelle Erweiterung.

**Jede Route ist ein eigener Testfall** – bei einem Fehler siehst du sofort welche Route betroffen ist:

```
✓ /admin/users
✓ /admin/settings
✕ /admin/reports    ← sieht man sofort, ohne Suche
✓ /admin/roles
```

---

## Wie es funktioniert

Das Package stellt eine abstrakte Basisklasse `SmokeTestCase` bereit. Per `#[DataProvider]` werden alle parameterfreien GET-Routen automatisch als einzelne Testfälle registriert. Für jede Route wird ein User eingeloggt und die Route aufgerufen.

**Erlaubte Statuscodes:** `200 OK`, `302 Redirect` (und alles unter 400).  
**Nicht erlaubt:** `401`, `403`, `404`, `422`, `500` – alles ab 400.

---

## Voraussetzungen

- PHP 8.2+
- Laravel 11 oder 12
- PHPUnit 11+
- SQLite als Test-Datenbank (Laravel-Standard)

---

## Installation

### Option A – Via GitHub (ohne Packagist)

**Schritt 1:** Repository in `composer.json` des Projekts eintragen:

```json
"repositories": [
    {
        "type": "vcs",
        "url": "https://github.com/simse0/laravel-module-smoketest"
    }
]
```

**Schritt 2:** Package als Dev-Dependency installieren:

```bash
composer require simse0/laravel-module-smoketest:dev-master --dev
```

### Option B – Lokaler Pfad (Monorepo)

```json
"repositories": [
    {
        "type": "path",
        "url": "/var/www/packages/trafficdesign/laravel-smoke-test"
    }
]
```

```bash
composer require simse0/laravel-module-smoketest:@dev --dev
```

---

## Einrichtung

### Schritt 1 – Test-Datei generieren

```bash
php artisan vendor:publish --tag=smoke-test
```

Das erstellt `tests/Feature/SmokeTest.php` auf Basis des mitgelieferten Stubs.

### Schritt 2 – Ersten Lauf durchführen (kein Setup nötig)

```bash
php artisan test tests/Feature/SmokeTest.php
```

**Kein weiteres Setup nötig.** Das Package liest das User-Modell automatisch aus `config('auth.providers.users.model')` und erstellt einen Testuser per Factory.

Beim ersten Lauf können einzelne Routen fehlschlagen, die **keine echten Bugs** sind (z.B. destruktive Aktionen, MySQL-spezifische Queries unter SQLite). Diese in `$except` eintragen.

---

## Konfiguration

Alle Optionen werden in der eigenen `SmokeTest`-Klasse überschrieben:

### `$except` – Routen ausschließen

```php
class SmokeTest extends SmokeTestCase
{
    /**
     * Routen, die grundsätzlich übersprungen werden (exakter URI-Match).
     *
     * Typische Kandidaten:
     *   - Logout / Session-beendende Routen (würden den Test-User ausloggen)
     *   - Destruktive Aktionen (Account löschen, Daten zurücksetzen)
     *   - Debug-Endpoints (Sentry, Telescope, Horizon)
     *   - Routen mit MySQL-spezifischen Queries, die unter SQLite fehlschlagen
     */
    protected static array $except = [
        'logout',
        'user/delete-account',
        'dashboard',               // MySQL: HAVING auf aggregate subquery
        'org-admin/organizations', // MySQL: FIELD() für Sortierung
    ];
}
```

### `createUser()` – Testuser anpassen (optional)

Standardmäßig wird das User-Modell aus `config('auth.providers.users.model')` gelesen und per `factory()->create()` instanziiert. **Das funktioniert für alle Standard-Laravel-Projekte ohne Anpassung.**

Wenn das Projekt spezifische Rollen, Organisationen oder andere Abhängigkeiten benötigt, `createUser()` überschreiben:

```php
// Einfaches Beispiel: Factory-State
protected function createUser(): Authenticatable
{
    return User::factory()->withRole('admin')->create();
}

// Komplexes Beispiel: mit Organisations-Kontext
protected function createUser(): Authenticatable
{
    $organization = Organization::factory()->create(['contract_status' => 'active']);

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
```

### Immer ausgeschlossen (intern)

Diese Routen werden immer übersprungen und müssen nicht in `$except` eingetragen werden:

| URI | Grund |
|---|---|
| `up` | Laravel Health-Check |
| `sanctum/csrf-cookie` | API-interner Endpoint |
| `_ignition/health-check` | Debug-Toolbar |

---

## Welche Routen werden getestet

| Kriterium | Eingeschlossen |
|---|---|
| HTTP-Methode | nur `GET` |
| URL-Parameter | keine (kein `{id}` o.ä.) |
| Ausnahmen | alles in `$except` + interne Framework-Routen |

Der eingeloggte User kommt aus `createUser()` – damit werden sowohl Auth-Routen als auch öffentliche Routen getestet.

---

## Vollständiges Beispiel

### Minimale Version (Standard-Laravel-Projekt)

```php
<?php

namespace Tests\Feature;

use Trafficdesign\SmokeTest\SmokeTestCase;

class SmokeTest extends SmokeTestCase
{
    protected static array $except = [
        'logout',
    ];

    // createUser() muss nicht implementiert werden –
    // Standard: User::factory()->create(['email_verified_at' => now()])
}
```

### Erweiterte Version (Projekt mit Rollen/Organisationen)

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
    protected static array $except = [
        'logout',
        'dashboard',               // MySQL: HAVING auf aggregate subquery
        'org-admin/organizations', // MySQL: FIELD() für Sortierung
    ];

    protected function createUser(): Authenticatable
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

## Troubleshooting

**Route schlägt mit 422 fehl**

Die Route erwartet bestimmte Request-Daten oder einen spezifischen Kontext (z.B. aktive Queue-Connection). In `$except` eintragen.

**Route schlägt mit 500 fehl, funktioniert aber in Production**

Wahrscheinlich eine MySQL-spezifische SQL-Funktion (`FIELD()`, `REGEXP`, komplexe `HAVING`-Klauseln) die SQLite nicht unterstützt. In `$except` eintragen.

**Alle Routen zeigen `!` (risky) statt `✓`**

Das sind PHP-Deprecation-Notices oder ähnliche Warnungen aus dem Anwendungscode selbst – kein Problem mit den Tests. Die Assertions laufen durch, PHPUnit markiert den Test trotzdem als "risky". Nützlicher Hinweis: diese Routen haben veraltete Code-Muster.

**`createUser()` – warum `firstOrCreate` für Rollen?**

Bei SQLite-Tests mit `RefreshDatabase` setzt sich das Auto-Increment nicht zurück. `Role::create` schlägt bei wiederholter gleicher `(organization_id, slug)`-Kombination mit `UniqueConstraintViolationException` fehl. `firstOrCreate` verhindert das.

**`class not found` nach Installation**

```bash
composer dump-autoload
```
