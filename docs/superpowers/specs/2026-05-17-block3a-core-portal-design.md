# Design — Block ③a Kern-Portal

- **Datum:** 2026-05-17
- **Projekt:** gf_server (Grand Fantasia Privatserver)
- **Status:** Entwurf zur Abnahme

## Kontext & Projektzerlegung

Das Gesamtprojekt hat drei Blöcke; Block ③ (Community-Portal) wurde beim
Brainstorming weiter unterteilt:

- **① Server-Fundament & Deployment** — abgeschlossen, gemerged (PR #1).
- **② Account-/Web-Backend** — abgeschlossen, gemerged (PR #2). Liefert die
  `web/lib`-Domänen-Bibliothek (PDO `Database`, `AccountService`,
  `AdminService`, `Validation`, Exceptions) und bcrypt-Passwörter.
- **③a Kern-Portal** — *diese Spec*: die Plain-PHP-Webanwendung (öffentliche
  Seite, Account-Self-Service, Admin-UI).
- **③b Forum-Integration** — späterer Teilblock mit eigener Spec: fertige
  Forensoftware einbinden. Nicht Teil von ③a.

### Entscheidungen aus dem Brainstorming

- **E-Mail-Funktionen: ja.** Es ist ein öffentlicher Server — Self-Service-
  Passwort-Reset skaliert, und E-Mail-Bestätigung ist echter Anti-Abuse-Schutz.
  SMTP läuft über einen Relay-Dienst (Konfiguration in `gfserver.env`); der
  Mail-Versand liegt hinter einer `Mailer`-Schnittstelle, damit Tests ohne
  echten Relay laufen.
- **Admin-Zugang: eigene Portal-Admin-Tabelle** (`web_admin`), entkoppelt von
  In-Game-GM, mit Bootstrap des ersten Admins beim Install.
- **Technik: Ansatz A** — Plain-PHP Front-Controller + Mini-Router +
  PHP-Templates, kein Framework (konsistent mit Block ②).

## Ziel von Block ③a

Ein abgesichertes Kern-Portal als Plain-PHP-Webanwendung auf Basis von Block ②s
`web/lib`: öffentliche Seiten, Account-Self-Service mit E-Mail-Flows und ein
login-geschütztes Admin-UI.

### Nicht-Ziele (Block ③a)

- Kein Forum — eigener Teilblock ③b.
- Keine `gm_tool_accounts`-Provisionierung (Login fürs externe GM-Tool).
- Kein Item-Shop.
- Keine Browser-/UI-Automatisierungstests.

## Architektur

### Verzeichnis-Layout

Ergänzt das bestehende `web/`:

```
web/
  lib/            # Block ② — Domänen-Logik (minimal erweitert, s. u.)
  app/            # NEU — HTTP-/Anwendungsschicht
    Router.php          # Pfad -> Controller
    Controller/         # ein Handler pro Seite
    Session.php         # gehärtete Session-Verwaltung
    Csrf.php            # CSRF-Token
    Auth.php            # Portal-Login-Status, Admin-Prüfung
    Mailer.php          # Interface
    SmtpMailer.php      # SMTP-Implementierung
  public/         # NEU — Web-Root (Apache-DocumentRoot)
    index.php           # Front-Controller
    assets/style.css    # handgeschriebenes CSS
  templates/      # NEU — schlichte PHP-Views (Layout + Seiten-Partials)
  tests/          # erweitert
```

Nur `public/` ist vom Web aus erreichbar; `lib/`, `app/`, `templates/`,
`vendor/` liegen außerhalb des DocumentRoots.

### Anwendungsfluss

Apache leitet alle Anfragen auf `public/index.php` → `Router` ordnet den Pfad
einem `Controller` zu → der Controller nutzt `web/lib`-Services → rendert ein
Template in das gemeinsame Layout.

### Web-Server & Deployment

Block ① installiert bewusst kein Apache/PHP. Block ③a erweitert Block ①s
`install.sh`:

- Apache + PHP-FPM + benötigte PHP-Extensions installieren.
- Einen vHost mit DocumentRoot `…/web/public` einrichten.
- HTTPS via Let's Encrypt/certbot; HTTP→HTTPS-Redirect.
- `ufw`-Freigabe für 80/443.
- Erster Admin, SMTP-Zugang und Portal-Domain werden über `gfserver.env`
  konfiguriert.

### Einordnung

③a setzt Block ① (Server, DB, `gf_web`-Rolle, Migrations-Runner) und Block ②
(`web/lib`) voraus.

## Datenbank-Ergänzungen

Neue portal-eigene Tabellen in `gf_ls`, als nummerierte Migrationen nach
Block ②s `gf_ls/001`:

| Tabelle | Zweck |
|---|---|
| `web_account` | `account_id` (PK, FK→`accounts.id`), `email` (UNIQUE), `email_verified` bool, `created_at` |
| `web_admin` | `account_id` (PK, FK) — Mitgliedschaft = Portal-Admin |
| `web_news` | `id`, `title`, `body`, `author_account_id`, `published_at` (NULL = Entwurf), `created_at` |
| `web_token` | `token` (PK, **Hash** des Tokens), `account_id`, `purpose` (`password_reset`/`email_verify`), `expires_at`, `used_at` |
| `web_login_attempt` | `id`, `ip`, `attempted_at`, `success` — Grundlage fürs Rate-Limit |

Eine zusätzliche Grant-Migration gibt der `gf_web`-Rolle DML-Rechte auf genau
diese fünf Tabellen (plus Sequenz-Nutzung, wo nötig).

### E-Mail-Bestätigung als Login-Gate

Neu registrierte Konten werden **gesperrt** angelegt: `tb_user.byauthority =
255`. Die Stored Procedure `account_login()` prüft genau diesen Wert und
liefert „Account gesperrt" (nRet=5). Die E-Mail-Bestätigung setzt
`byauthority = 0` und schaltet das Konto frei. So blockiert eine unbestätigte
Registrierung den **Spiel-Login** wirklich — über den vorhandenen Sperr-
Mechanismus, ohne Eingriff ins Binary.

### Erweiterung von `web/lib` (Block ②)

Kleine, nötige Ergänzungen:

- `AccountService::authenticate(username, password)` — Zugangsdaten per
  `crypt()` prüfen; vom Portal-Login gebraucht (in Block ② nicht vorhanden).
- Account-Sperr-/Freischalt-Logik für den Bestätigungs-Flow: `register()` legt
  das Konto gesperrt an und schreibt zusätzlich die `web_account`-Zeile
  (E-Mail) mit; die Bestätigung schaltet frei.

### Bootstrap des ersten Admins

Ein Install-Schritt bzw. eine `gfctl`-Funktion trägt einen in `gfserver.env`
genannten Account in `web_admin` ein — so erhält der erste Admin Rechte, ohne
dass je ein ungeschütztes UI existiert.

## Seiten & Funktionen

### Öffentliche Seiten (kein Login)

- **Startseite** — Server-Vorstellung, Live-Status, Spielerzahl, Links.
- **News** — Liste veröffentlichter `web_news`-Einträge + Einzelansicht.
- **Download** — Client-Download-Links (konfiguriert).
- **Server-Status** — prüft die Spiel-Ports (Portcheck wie im alten
  `status.php`), zeigt Online/Offline je Komponente + Anzahl Konten/Charaktere.
- **Rangliste** — Leaderboard aus `player_characters` (Top nach Level u. ä.).

### Account-Self-Service

- **Registrierung** — Username + E-Mail + Passwort; legt das Konto gesperrt an,
  schreibt `web_account`, verschickt die Bestätigungs-Mail.
- **E-Mail-Bestätigung** — Token-Link → E-Mail verifiziert, Konto freigeschaltet.
- **Login / Logout** — Portal-Session via `AccountService::authenticate`.
- **Passwort ändern** — eingeloggt, mit aktuellem + neuem Passwort.
- **Passwort-Reset** — Anfrage (E-Mail → Token-Mail) + Zurücksetzen
  (Token-Link → neues Passwort).
- **Konto-Übersicht** — eingeloggte Ansicht der eigenen Kontodaten/Charaktere.

### Admin-UI (Login + `web_admin`-Mitgliedschaft)

- **Dashboard**.
- **News-Verwaltung** — `web_news` anlegen/bearbeiten/veröffentlichen/löschen.
- **Spieler-Verwaltung** — GM-Rechte vergeben/entziehen, Spieler-/Sprite-Namen
  ändern (`AdminService`).
- **Konto-Verwaltung** — Passwort eines Nutzers zurücksetzen, Konto
  sperren/entsperren, Bestätigungs-Mail erneut senden.

## Sicherheit

- **Sessions** — PHP-Sessions gehärtet: Cookies `HttpOnly`, `Secure`,
  `SameSite=Lax`; Session-ID-Regeneration beim Login; moderate Lebensdauer.
- **CSRF** — Pro-Session-Token, geprüft bei jedem zustandsändernden POST
  (Registrierung, Login, Passwort ändern, alle Admin-Aktionen).
- **Output-Escaping** — alle dynamischen Template-Ausgaben über einen
  `e()`-Helfer (`htmlspecialchars`). SQL ist über `Database` parametrisiert.
- **Rate-Limiting** (`web_login_attempt`) — fehlgeschlagene Logins pro
  IP/Username im Zeitfenster begrenzen; Registrierung und
  Passwort-Reset-Anfrage ebenfalls gedrosselt.
- **Token-Sicherheit** — Tokens kryptografisch zufällig (`random_bytes`); in
  `web_token` wird nur der Hash gespeichert, der Klartext geht per Mail raus;
  Einmal-Nutzung (`used_at`), kurze Gültigkeit (Reset ~1 h).
- **Account-Enumeration vermeiden** — die Passwort-Reset-Anfrage zeigt immer
  dieselbe Meldung, unabhängig davon, ob die E-Mail existiert.
- **Mailer** — `Mailer`-Interface + `SmtpMailer` (SMTP-Daten aus
  `gfserver.env`); Tests nutzen einen `FakeMailer`.
- **HTTP-Header & HTTPS** — Sicherheits-Header
  (`X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, schlanke
  CSP); HTTP→HTTPS-Redirect; `Secure`-Cookies.
- **Admin-Schutz** — alle Admin-Routen hinter Login und `web_admin`-Prüfung.

## Tests & CI

### PHPUnit (`web/tests/`, erweitert)

- **Domänen-Erweiterungen** — `AccountService::authenticate` (richtiges/falsches
  Passwort), Sperr-/Freischalt-Logik.
- **Bestätigungs-Gate** (zentraler Integrationstest) — Registrierung → Konto
  gesperrt, `account_login` lehnt ab → Token einlösen → freigeschaltet,
  `account_login` akzeptiert.
- **Token-Logik** — `web_token` anlegen/einlösen, Ablauf, Einmal-Nutzung,
  Hash-Speicherung.
- **Rate-Limiter** — N Fehlversuche → Drosselung greift.
- **CSRF** — Token-Erzeugung/-Prüfung; ungültiges Token wird abgewiesen.
- **Mailer-Flows** — mit `FakeMailer`: Reset- und Bestätigungs-Flow verschicken
  die richtige Mail mit gültigem Token.
- **Router** — Pfad → Controller-Zuordnung.

### CI

Der bestehende Job `web-backend` lädt das Spielschema, wendet **alle**
Migrationen an und führt `phpunit` über `web/tests/` aus — neue Migrationen und
Tests von ③a werden ohne Workflow-Änderung erfasst. Der `FakeMailer` macht
echten SMTP-Versand in CI überflüssig.

### Nicht durch CI abgedeckt (ehrlich)

- Echte Browser-/Klick-Durchläufe der Seiten.
- Echter SMTP-Versand und das deployte Apache/HTTPS-Setup.

Beides gehört in die verschobene Deployment-Phase (manuelle Abnahme). CI
verifiziert die PHP-Logik vollständig gegen echtes PostgreSQL.

## Offene Punkte

1. **`gm_tool_accounts`-Provisionierung** — Login fürs externe GM-Tool bleibt
   außerhalb von ③a; bei Bedarf später als kleines Zusatzthema.
2. **Deployment-Abnahme** — Browser-Durchläufe, echter SMTP-Relay und das
   Apache/HTTPS-Setup werden erst in der (verschobenen) Deployment-Phase
   verifiziert.
3. **Forum (③b)** — eigene Spec, baut auf dem hier erstellten Portal auf.

## Abhängigkeiten zu anderen Blöcken

- Setzt Block ① (Server, DB, `gf_web`-Rolle, Migrations-Runner, `install.sh`)
  und Block ② (`web/lib`) voraus.
- Block ③b (Forum) baut auf dem Portal aus ③a auf.
