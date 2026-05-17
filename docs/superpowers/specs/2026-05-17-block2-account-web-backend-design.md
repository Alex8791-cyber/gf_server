# Design — Block ② Account-/Web-Backend

- **Datum:** 2026-05-17
- **Projekt:** gf_server (Grand Fantasia Privatserver)
- **Status:** Entwurf zur Abnahme

## Kontext & Projektzerlegung

Block ② ist der zweite von drei Blöcken (siehe
`2026-05-16-gf-server-foundation-deployment-design.md`):

- **① Server-Fundament & Deployment** — abgeschlossen, nach `main` gemerged (PR #1).
- **② Account-/Web-Backend** — *diese Spec*.
- **③ Community-Portal** — späterer Block (Web-UI, Seiten, Sessions, Admin-UI,
  Account-Self-Service).

Block ② liefert die abgesicherte Backend-Schicht: eine getestete PHP-Domänen-
Bibliothek plus die DB-Migration für Passwort-Hashing. Block ③ baut das Portal
darauf auf.

## Vorab-Recherche: Passwort-/Auth-Schema (Ergebnis)

Eine gezielte Untersuchung der geschlossenen Binaries hat das in Block ①
offengelassene Auth-Schema geklärt (Konfidenz: hoch):

- Die Login-Prüfung passiert **nicht** im geschlossenen Binary, sondern in einer
  **PL/pgSQL-Stored-Procedure `account_login()`** in `gf_ms.sql`. Sie vergleicht
  `tb_user.pwd` per direktem String-Vergleich (`pPwd <> pPassword`) gegen das
  vom Client gesendete Passwort.
- Die Funktionen `lapis::CalcMD5Sum` und `lapis::CAccountDBO::CheckAccountPassword`
  in den Binaries sind **toter Code** (von keiner Funktion aufgerufen).
- `accounts.password` (gf_ls) wird für die Authentifizierung **nicht gelesen**;
  das LoginServer-Binary schreibt dort selbst einen leeren String.
- Der Client sendet das Passwort über den ohnehin RC4-verschlüsselten Kanal
  (sehr wahrscheinlich als Klartext); das ist Transport, nicht Speicherung.

**Konsequenz:** Passwort-Hashing lässt sich nachrüsten, ohne Binary oder Client
zu ändern — die Vergleichslogik (`account_login()`) liegt im editierbaren
SQL-Dump.

## Ziel von Block ②

Die SQL-injizierbaren PHP-Dateien durch eine saubere, getestete PHP-Domänen-
Bibliothek ersetzen und Passwörter künftig mit bcrypt speichern.

### Nicht-Ziele (Block ②)

- Kein Web-UI, keine Seiten, kein Login-Frontend, kein Routing/Sessions — das
  ist Block ③.
- Keine Installation von Apache/PHP auf dem VPS — das macht Block ③.
- Keine Änderung an den geschlossenen Spiel-Binaries.

## Architektur

### Verzeichnis-Layout

```
web/
  lib/
    Config.php          # DB-Zugangsdaten aus Umgebungsvariablen
    Database.php        # PDO-Wrapper, nur parametrisierte Queries
    Validation.php      # Regeln für Username / Passwort / Namen
    AccountService.php  # registrieren, Passwort ändern
    AdminService.php    # GM-Rechte, Spieler-/Sprite-Namen ändern
  tests/                # PHPUnit-Integrationstests
  composer.json         # nur Dev-Abhängigkeit: phpunit
_utils/db/migrations/
  gf_ms/001_password_hashing.sql
deploy/
  migrate.sh            # Migrations-Runner (psql-basiert)
```

Die alten Dateien `_utils/web/*.php` werden entfernt.

### Einordnung in die Blöcke

- Block ① stellt DB + die `gf_app`-Rolle bereit. Block ② ergänzt eine eigene,
  enger berechtigte Web-Rolle `gf_web` (löst das in Block ① offengelassene
  Versprechen ein).
- Server-Fußabdruck von Block ②: nur die SQL-Migration (per `psql` angewendet).
  Die PHP-Bibliothek wird in ② per CI getestet, nicht auf dem VPS ausgeführt;
  PHP/Apache installiert erst Block ③.

## Datenbank-Migration

Erste Schema-Änderung am Spiel-DB, als nummerierte Migration
`_utils/db/migrations/gf_ms/001_password_hashing.sql`:

### Passwort-Hashing (bcrypt via pgcrypto)

- `CREATE EXTENSION IF NOT EXISTS pgcrypto;` in `gf_ms`.
- `tb_user.pwd` und `tb_user.password` von `varchar(32)` auf `text` erweitern
  (ein bcrypt-Hash ist 60 Zeichen lang).
- `account_login()` per `CREATE OR REPLACE` neu definieren: statt
  `pPwd <> pPassword` künftig `pPwd <> crypt(pPassword, pPwd)` (bcrypt-
  Verifikation); die lokale Variable `pPwd char(32)` wird `text`. Die neue
  Funktion wird 1:1 aus der bestehenden Definition in `gf_ms.sql` abgeleitet —
  es ändern sich nur die Vergleichszeile und der Variablentyp, sonst nichts.
- Bestandskonten umstellen: bereits vorhandene Klartext-Passwörter werden in
  bcrypt umgewandelt; Zeilen, die bereits einen bcrypt-Hash enthalten
  (Präfix `$2a$`/`$2b$`/`$2y$`), werden übersprungen — die Migration ist damit
  idempotent. Auf dem frischen Server sind die Tabellen leer.

### Web-DB-Rolle `gf_web`

Eigene PostgreSQL-Rolle mit minimalen Rechten — nur die Objekte, die das
Web-Backend wirklich braucht:

- DML auf `accounts`, `gm_tool_accounts` (gf_ls), `tb_user` (gf_ms),
  `player_characters`, `elf1` (gf_gs).
- `EXECUTE` auf `account_login`.
- Kein Zugriff auf den Rest der Datenbanken.

### Migrations-Runner

- Nummerierte `.sql`-Dateien je Datenbank unter `_utils/db/migrations/<db>/`.
- Eine Tabelle `schema_migrations` je Datenbank verfolgt angewendete
  Migrationen; erneutes Ausführen überspringt bereits angewandte.
- Angewendet per `psql` durch `deploy/migrate.sh`, zusätzlich als
  `gfctl migrate` verfügbar. `install.sh` (Block ①) ruft den Runner nach dem
  Schema-Import auf.

## PHP-Domänen-Bibliothek

Alle Klassen unter `web/lib/`, durchgängig parametrisierte Queries — kein
String-Zusammenbau von SQL.

### `Config`

Lädt DB-Host, Datenbanknamen und die `gf_web`-Zugangsdaten aus Umgebungs-
variablen (z. B. `GF_DB_HOST`, `GF_DB_USER`, `GF_DB_PASSWORD`). Keine
hartkodierten Werte. Tests setzen eigene Werte.

### `Database`

PDO-Wrapper, hält die drei Verbindungen (`gf_gs`, `gf_ls`, `gf_ms`). PDO mit
`ERRMODE_EXCEPTION` und `ATTR_EMULATE_PREPARES = false`. Bietet ausschließlich
parametrisierte Query-Helfer und `transaction()` (pro Datenbank).

### `Validation`

Zentrale Regeln: Username (Länge, Zeichensatz), Passwort (Mindestlänge),
Spieler-/Sprite-Namen. Ersetzt die verstreuten `preg_match`-Prüfungen und
behebt den Bug in `sprite.php` (Prüfung der undefinierten Variable `$newname`
statt `$spritename`).

### `AccountService`

- `register()`: validiert; errechnet `accounts.id` unter einem kurzen
  Table-Lock (statt der `COUNT(*)`-Race-Condition des alten Codes); hasht das
  Passwort mit bcrypt (`password_hash`, `PASSWORD_BCRYPT`, cost 12); schreibt
  `gf_ms.tb_user` (Hash in `pwd` **und** `password`) und `gf_ls.accounts`
  (`password` bleibt leer — wie es das LoginServer-Binary selbst tut).
- **Datenübergreifend, nicht atomar:** Eine Transaktion kann nicht zwei
  PostgreSQL-Datenbanken umspannen. Reihenfolge: zuerst `tb_user` (gf_ms),
  dann `accounts` (gf_ls). Schlägt der zweite Schritt fehl, wird der erste
  kompensierend zurückgenommen (Lösch-Statement) und ein Fehler gemeldet —
  keine verwaisten Konten.
- `changePassword()`: setzt den bcrypt-Hash neu.

### `AdminService`

- `setGmPrivilege()` — GM-Rechte: `player_characters.privilege` (gf_gs),
  `gm_tool_accounts` (gf_ls), `tb_user.byauthority` (gf_ms). Behebt den Bug aus
  `gm.php` (`UPDATE gm_tool_account` → korrekt `gm_tool_accounts`).
- `renamePlayer()` — `player_characters.given_name` (gf_gs).
- `renameSprite()` — `elf1.name` (gf_gs).

### Fehlerbehandlung

Methoden werfen typisierte Exceptions (`ValidationException`,
`ConflictException`, `DatabaseException`). Die Bibliothek gibt kein HTML aus und
`echo`t nicht — die Darstellung entscheidet die Aufrufschicht (Block ③).

## Tests & CI

### PHPUnit-Integrationstests (`web/tests/`)

Gegen eine echte PostgreSQL-Instanz mit geladenem Spielschema + Migrationen
(keine Mocks):

- `DatabaseTest` — Verbindungen stehen; parametrisierte Queries funktionieren.
- `AccountServiceTest` — Registrierung legt die Zeilen in `accounts` + `tb_user`
  an; `tb_user.pwd` enthält einen bcrypt-Hash (kein Klartext); doppelter
  Username wird abgelehnt; fehlschlagender zweiter Insert nimmt den ersten
  zurück (keine Waise); `changePassword` ersetzt den Hash.
- **Auth-Roundtrip** (zentraler Test) — nach `register()` muss die echte Stored
  Procedure `account_login()` das richtige Passwort akzeptieren und ein falsches
  ablehnen. Verifiziert, dass bcrypt-Speicherung und umgeschriebener
  `account_login` zusammenpassen.
- `AdminServiceTest` — GM-Rechte vergeben/entziehen, Umbenennen; deckt die
  behobenen Bugs ab.
- `ValidationTest` — Username-/Passwort-/Namensregeln.

### CI

Der bestehende GitHub-Actions-Workflow (`.github/workflows/ci.yml`) bekommt
einen zweiten Job `web-backend`:

- PostgreSQL als Service-Container.
- Schritte: Spielschema (`_utils/db/*.sql`) laden → Migrationen anwenden →
  `composer install` → `phpunit`.
- Damit läuft der Auth-Roundtrip in CI — echte Server-seitige Verifikation der
  Passwort-Hashing-Umstellung, ohne VPS.

## Offene Punkte / noch zu verifizieren

1. **Client-Login nach der Umstellung:** Der finale Beweis, dass sich der
   Spiel-Client nach der bcrypt-Umstellung noch einloggt, kommt erst beim
   Deployment-Test (bewusst verschoben, bis alle drei Blöcke fertig sind). CI
   verifiziert die Server-Seite vollständig (Backend ↔ `account_login`); die
   Client↔Server-Strecke ist die Abnahme in der Deployment-Phase.
2. **Vollständige `account_login()`-Definition:** Die Umsetzung leitet die neue
   Funktion aus der realen Definition in `gf_ms.sql` ab — diese ist beim
   Schreiben des Implementierungsplans wörtlich aus dem Dump zu übernehmen.
3. **`charpassword` (Charakter-Passwort):** Das sekundäre `use_charpassword`/
   `charpassword`-Feld in `accounts` bleibt vorerst unangetastet — außerhalb des
   Scopes von Block ②.

## Abhängigkeiten zu anderen Blöcken

- Setzt Block ① voraus (DB, Rollen, `gfctl`, CI-Workflow, Migrations-Aufruf in
  `install.sh`).
- Block ③ konsumiert `AccountService`/`AdminService` und baut das Web-Portal,
  Sessions und die Admin-UI darauf auf.
