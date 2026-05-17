# Design — Block ③b Forum-Integration

- **Datum:** 2026-05-17
- **Projekt:** gf_server (Grand Fantasia Privatserver)
- **Status:** Entwurf zur Abnahme

## Kontext & Projektzerlegung

Block ③ (Community-Portal) wurde in ③a (Kern-Portal) und ③b (Forum) geteilt.
Block ③a ist abgeschlossen (4 Stufen, PRs #3–#6). Diese Spec beschreibt **③b**:
die Einbindung eines fertigen Forums mit gemeinsamem Login.

Vorausgesetzt: Block ① (Server, PostgreSQL, Apache + PHP-FPM-Pool `gfserver`),
Block ② (`web/lib`-Bibliothek mit `AccountService`) und Block ③a (das Portal).

### Entscheidungen aus dem Brainstorming

- **Forensoftware: phpBB 3.3** — läuft direkt unter Apache + PHP, unterstützt
  PostgreSQL offiziell, passt zum bestehenden Stack ohne zweite DB-Engine.
- **Account-Kopplung: gemeinsamer Login (SSO)** über einen eigenen phpBB-
  Auth-Provider — ein Account für Spiel, Portal und Forum.
- **Platzierung: eigene Subdomain** `forum.<domain>` mit eigenem Apache-vHost.
- **Ansatz A** — eigene phpBB-Auth-Provider-Extension (kein fragiler Sync).

## Ziel von Block ③b

phpBB 3.3 als Community-Forum unter `forum.<domain>` bereitstellen, mit
gemeinsamem Login: Spieler melden sich im Forum mit ihrem bestehenden
Spiel-Account an.

### Nicht-Ziele

- Kein selbstgebautes Forum — phpBB wird eingebunden, nicht entwickelt.
- Keine Änderung an den Spiel-Binaries oder am `tb_user`-Schema.
- Kein Einchecken des phpBB-Frameworks ins Repo (nur unsere Extension).

## Architektur

### Komponenten und Ablageorte

- **phpBB 3.3** — installiert unter `/opt/gfserver/forum/`, getrennt von
  `web/public` (dem Portal). Wird beim Deployment heruntergeladen, **nicht**
  ins Repo eingecheckt.
- **SSO-Extension** — *unser* Code, versioniert im Repo unter
  `forum/ext/gfserver/sso/`. `install.sh` kopiert sie in das phpBB-`ext/`-
  Verzeichnis.
- **Datenbank `gf_forum`** — eigene PostgreSQL-Datenbank auf derselben
  Instanz, **im Besitz der Rolle `gf_forum`** (phpBB legt seine ~60 Tabellen
  selbst an und ändert sie bei Updates — bewusst anders als die rechtearmen
  Spiel-Rollen).
- **vHost `forum.<domain>`** — eigener Apache-vHost mit DocumentRoot auf der
  phpBB-Wurzel, nutzt denselben PHP-FPM-Pool `gfserver` wie das Portal.

### Deployment (`install.sh`-Erweiterung `setup_forum`)

- phpBB-3.3-Archiv (gepinnte Version) herunterladen, nach `/opt/gfserver/forum/`
  entpacken.
- Zusätzliche PHP-Extensions für phpBB nachinstallieren (`php-gd`, `php-xml`,
  `php-zip`).
- DB `gf_forum` + Rolle `gf_forum` anlegen; die DB gehört der Rolle.
- phpBB **headless per CLI installieren** — phpBB 3.3 unterstützt
  `phpbbcli.php install <config.yml>`. `install.sh` erzeugt die YAML aus
  `gfserver.env` (DB-Zugang, Board-Name, erster phpBB-Admin) und führt den
  Installer aus.
- Die SSO-Extension nach `forum/ext/gfserver/sso/` (im phpBB-Baum) kopieren
  und per `phpbbcli.php extension:enable gfserver/sso` aktivieren.
- Die Authentifizierungs-Methode per `phpbbcli.php config:set` auf `gfserver`
  setzen.
- Den `forum.<domain>`-vHost schreiben.
- HTTPS für die Subdomain via `certbot` — Runbook-Schritt (wie beim Portal).

### Konfiguration via `gfserver.env`

Neue Variablen: `FORUM_DOMAIN`, `FORUM_DB_PASSWORD` (Rolle `gf_forum`),
erster phpBB-Admin (Name/Passwort/E-Mail).

## Die SSO-Auth-Provider-Extension

Die Extension `forum/ext/gfserver/sso/` ist eine reguläre phpBB-Extension:

| Datei | Zweck |
|---|---|
| `composer.json` | Extension-Metadaten (`gfserver/sso`, phpBB-Versionsbereich) |
| `ext.php` | Extension-Lifecycle-Klasse |
| `config/services.yml` | registriert den Auth-Provider als Dienst mit Tag `auth.provider` |
| `auth/provider/gfserver.php` | der Custom-Auth-Provider |
| `language/en/...` | Sprachstrings für Fehlermeldungen |

### Keine Duplikation der Auth-Logik

Der Auth-Provider prüft Zugangsdaten **nicht** selbst neu. Er lädt den
Autoloader des Portals (`/opt/gfserver/web/vendor/autoload.php`) und ruft
Block ②s bereits getestetes `GfServer\AccountService::authenticate($username,
$password)` auf — dieselbe bcrypt-Prüfung wie beim Portal- und Spiel-Login.
Eine einzige Quelle der Wahrheit.

### `login()`-Ablauf

1. `AccountService::authenticate()` → `accounts.id` oder `null`.
2. `null` → phpBB-Login-Fehler (falsche Zugangsdaten).
3. Erfolg → `phpbb_users` nach dem Username durchsuchen.
4. Fehlt der phpBB-Nutzer → automatisch anlegen via phpBBs `user_add()`:
   Username = Spiel-Username; E-Mail aus `AccountService::webAccount()` (oder
   ein Platzhalter, falls keine hinterlegt); Gruppe „Registered"; ein
   unbrauchbares `user_password` (der phpBB-interne Hash wird nie geprüft —
   die Authentifizierung läuft immer über diesen Provider).
5. Den `phpbb_users`-Datensatz an phpBB zurückgeben → eingeloggt.

### Datenbank-Zugriff der Extension

Die Extension läuft im PHP-FPM-Pool `gfserver` und liest die `GF_DB_*`-
Umgebungsvariablen; sie verbindet als Rolle `gf_web`, die bereits `SELECT` auf
`tb_user`, `accounts` und `web_account` hat. Kein neuer DB-Zugang nötig.

## phpBB-Konfiguration & Integration

- **Authentifizierung:** Methode `gfserver` — jeder Forum-Login über die
  SSO-Extension.
- **phpBB-eigene Account-Funktionen abschalten** (Account-/Passwort-Verwaltung
  gehört dem Portal):
  - Registrierung — phpBB-Nutzer entstehen nur per SSO-Auto-Provisionierung;
    die phpBB-Registrierungsseite wird abgeschaltet.
  - Passwort ändern / „Passwort vergessen" — würden nur den ungenutzten
    phpBB-internen Hash betreffen; sie werden deaktiviert.
  - Der genaue Mechanismus (ACP-Einstellung bzw. Eingriff der Extension) wird
    beim Implementierungsplan anhand der phpBB-3.3-Konfiguration festgelegt;
    diese Spec hält die Absicht fest.
- **E-Mail:** phpBBs SMTP wird auf denselben Relay-Dienst wie das Portal
  konfiguriert (aus `gfserver.env`).
- **Portal → Forum verlinken:** Die Portal-Navigation bekommt einen
  „Forum"-Link auf `https://forum.<domain>`. Umsetzung: `FORUM_DOMAIN` in
  `gfserver.env` → vom Front-Controller per `View::share()` als `forumUrl`
  bereitgestellt → zusätzlicher Nav-Eintrag in `layout.php`. Das ist der
  einzige Eingriff ins Portal in ③b.

## Tests & CI

### Was prüfbar ist

- Die SSO-Extension ist dünner phpBB-Glue um das bereits getestete
  `AccountService::authenticate()` — keine neue Auth-Logik zum isolierten
  Testen.
- CI-Syntax-Checks: `php -l` für die Extension-PHP-Dateien (prüft Syntax ohne
  die phpBB-Klassen), `bash -n` für die `install.sh`-Erweiterung.

### Nicht durch CI abgedeckt (ehrlich)

③b ist überwiegend Deployment. In der verschobenen Deployment-Phase manuell
abzunehmen:

- Der phpBB-CLI-Install, der `forum.<domain>`-vHost und HTTPS.
- Der echte SSO-Durchlauf: ein Spiel-Account loggt sich im Forum ein, der
  phpBB-Nutzer wird auto-provisioniert.
- phpBBs E-Mail-Versand.

③b hat damit den größten deployment-seitigen Abnahmeanteil aller Blöcke.

## Offene Punkte

1. **phpBB-Version pinnen** — eine konkrete 3.3.x-Version festlegen, damit der
   Install reproduzierbar ist.
2. **Genauer phpBB-Konfig-Mechanismus** zum Abschalten von Registrierung und
   Passwort-Funktionen — beim Implementierungsplan festgelegt.
3. **phpBB-Updates** — das Framework liegt nicht im Repo; phpBB-Sicherheits-
   updates sind eine Betriebsaufgabe (Runbook-Hinweis).

## Abhängigkeiten zu anderen Blöcken

- Setzt ① (Server, PostgreSQL, Apache/PHP-FPM-Pool, `install.sh`,
  Migrations-Runner), ② (`web/lib`/`AccountService`) und ③a (Portal,
  `View::share`, `layout.php`) voraus.
- Mit ③b ist das volle Community-Portal komplett.
