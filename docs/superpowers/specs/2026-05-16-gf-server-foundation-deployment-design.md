# Design — Block ① Server-Fundament & Deployment

- **Datum:** 2026-05-16
- **Projekt:** gf_server (Grand Fantasia Privatserver)
- **Status:** Entwurf zur Abnahme

## Kontext & Projektzerlegung

Das Gesamtvorhaben (öffentlicher Grand-Fantasia-Privatserver mit Community-Portal,
gehärtet, auf einem Ubuntu-VPS deployt) ist zu groß für eine einzelne Spec. Es
wurde in drei Blöcke zerlegt, die jeweils eine eigene Spec → Plan → Umsetzung
durchlaufen:

- **① Server-Fundament & Deployment** — *diese Spec*
- **② Account-/Web-Backend** — abgesicherte Datenzugriffs- und Account-Schicht,
  ersetzt die SQL-injizierbaren PHP-Dateien (eigene Spec, später)
- **③ Community-Portal** — öffentliche Seite, Account-Self-Service, Admin-Panel
  (eigene Spec, später)

Forum und Echtgeld-Item-Shop sind bewusst aus dem Scope; Empfehlung: fertige
Forensoftware bzw. Discord statt Eigenbau, kein Echtgeld-Shop.

### Herkunft des Codes (wichtig)

Entscheidung des Auftraggebers: **eigene Bestandteile von Grund auf neu
schreiben** statt fremden Code kosmetisch zu tarnen. Die kompilierten
Server-Binaries (`LoginServer`, `ZoneServer` usw.) und die Spieldaten bleiben
fremdes, urheberrechtlich geschütztes Material von X-Legend und werden
**unverändert** verwendet — sie sind nicht Gegenstand des Neuschriebs. Neu
geschrieben werden ausschließlich die selbst erstellten Teile: Installations-
und Verwaltungsskripte (Block ①) sowie das Web-/Portal-Material (Block ②/③).

## Ziel von Block ①

Ein sauberes, abgesichertes Fundament, auf dem die Spielserver auf einem
einzelnen Ubuntu-24.04-VPS zuverlässig und ohne grobe Sicherheitslücken laufen.
Die bisherigen Skripte `install` und `server` werden vollständig durch eigenen
Code ersetzt.

### Nicht-Ziele (Block ①)

- Kein Apache/PHP-Setup, kein Web-Panel — bewusst, damit das alte, verwundbare
  PHP-Panel gar nicht erst erreichbar installiert wird (→ Block ②/③).
- Keine Änderung an den Spiel-Binaries außer dem notwendigen IP-Patch.
- Kein Spielinhalt/Balancing.

## Zielumgebung

- **OS:** Ubuntu Server 24.04 LTS (Sicherheitssupport bis 2029).
- **DB:** PostgreSQL 16.
- **Topologie:** ein einzelner VPS; alle Spielserver, die DB und (später) das
  Portal laufen auf demselben Host.

## Architektur

### Installationsort & Benutzer

- Installationsverzeichnis: **`/opt/gfserver`** (statt `/root/gf_server`).
- Eigener System-User **`gfserver`** ohne Login-Shell und ohne sudo.
- Alle Spielserver-Prozesse laufen als `gfserver`, **nicht als root**. Die
  Spiel-Ports liegen über 1024, Root-Rechte sind zum Binden nicht nötig.
- Dateirechte `750` (Verzeichnisse/Skripte) bzw. `640` (Konfig/Daten),
  Eigentümer `gfserver`. Kein `chmod 777` an irgendeiner Stelle.

### Prozessmodell (systemd)

- Eine Unit pro Komponente: `gf-ticket`, `gf-gateway`, `gf-login`,
  `gf-mission`, `gf-world`, `gf-zone`.
- Sammel-Target `gfserver.target`, das alle sechs Units zieht.
- Start-Reihenfolge per `After=`-Kette analog zum bisherigen `server`-Skript:
  Ticket → Gateway → Login → Mission → World → Zone. Alle Units zusätzlich
  `After=postgresql.service`.
- `Restart=on-failure` mit `RestartSec` → abgestürzte Server starten neu
  (fehlt im bisherigen Setup vollständig).
- `WorkingDirectory=` je Komponente, damit die Binaries ihre `setup.ini` finden.

### Datenbank

- PostgreSQL 16 lauscht ausschließlich auf `127.0.0.1` (`listen_addresses`).
- `pg_hba.conf`: nur lokale Verbindungen, Authentifizierung `scram-sha-256`.
- Datenbanken `gf_gs`, `gf_ls`, `gf_ms` wie gehabt.
- Eigene Rollen statt überall `postgres`-Superuser:
  - `gf_app` — Nicht-Superuser, DML-Rechte (SELECT/INSERT/UPDATE/DELETE) auf die
    drei Spiel-Datenbanken; von den Spielserver-Binaries genutzt.
  - Eine separate, stärker eingeschränkte Portal-Rolle wird erst in Block ②
    definiert.
- Schema-Import: Die SQL-Dumps enthalten `OWNER TO postgres` und
  C-Sprach-/`dblink`-Funktionen, die Superuser-Rechte erfordern. Daher wird der
  Schema-Import **einmalig als `postgres`** ausgeführt; danach erhalten die
  App-Rollen per `GRANT` nur die nötigen DML-Rechte.

### Binary-IP-Patching

- `WorldServer` und `ZoneServer` enthalten eine hartkodierte IP, die an den
  Offsets `0x3EA7A7` (World) bzw. `0x822D47` (Zone) per `dd` überschrieben wird.
  Dieser Mechanismus bleibt erhalten — er ist der einzige Weg, den geschlossenen
  Binaries ihre IP mitzuteilen.
- Robustheitsverbesserungen gegenüber dem Original:
  - `.bak`-Sicherung vor jedem Patch (idempotent: bei erneutem Lauf von der
    `.bak` ausgehen, nicht vom bereits gepatchten Binary).
  - Verifikation: nach dem Patch die Bytes am Offset zurücklesen und gegen den
    erwarteten Wert prüfen; bei Abweichung Abbruch mit Fehlermeldung.

## Sicherheitshärtung

### Firewall (`ufw`)

- Default `deny incoming`, `allow outgoing`.
- Erlaubt: SSH (mit `ufw limit` gegen Brute-Force) plus die von außen
  tatsächlich benötigten Spiel-Ports.
- **Offener Implementierungsschritt:** Der exakte Satz öffentlicher Spiel-Ports
  (Login `6543`, Gateway `5560`, Ticket `7777` sowie die Zone/World-Ports) ist
  teils in den DB-Tabellen `worlds`/`serverstatus` hinterlegt. Er wird bei der
  Umsetzung durch DB-Inspektion ermittelt und im Runbook dokumentiert — nicht
  geraten.

### Datenbank

- Bind nur `127.0.0.1`; `pg_hba.conf` lokal + `scram-sha-256`.
- Starkes Zufallspasswort, generiert beim Install (z. B. via `openssl rand`),
  niemals im Skript hartkodiert oder ins Terminal ausgegeben.
- App-Rollen ohne `SUPERUSER`/`CREATEDB`/`CREATEROLE`.

### Prozess-Isolation (systemd-Direktiven je Unit)

- `User=gfserver`, `NoNewPrivileges=yes`, `ProtectSystem=strict`,
  `ProtectHome=yes`, `PrivateTmp=yes`.
- Beschreibbar nur das Daten-/Log-Verzeichnis (`ReadWritePaths=`).
- Ziel: ein kompromittierter Spielserver-Prozess ist vom übrigen System
  abgeschottet.

### System

- `unattended-upgrades` für automatische Sicherheitspatches.
- `fail2ban` für SSH.
- SSH-Härtung (Key-only-Login, `PermitRootLogin no`) wird **nur im Runbook
  empfohlen**, nicht automatisch geändert — um ein versehentliches Aussperren zu
  vermeiden.

### Secrets & Repo-Hygiene

- `.gitignore` für: `setup.ini` mit echten Werten, `gfserver.env`, Backups,
  Logs, `*.bak`.
- Eine nicht versionierte `gfserver.env` enthält IP, DB-Passwort und Ports.
  Die `setup.ini`-Dateien der Komponenten werden beim Install daraus erzeugt.
- `gfserver.env.example` als versionierte Vorlage.

### Bewusst entfernt

- `chmod 777 /root -R`.
- Öffnung von PostgreSQL auf `0.0.0.0/0`.
- Der `clear`-Befehl des alten `server`-Skripts (löschte `/var/log/secure`,
  `wtmp`, `syslog` u. a. — auf einem öffentlichen Server inakzeptabel).

## Liefergegenstände

| Datei | Zweck |
|---|---|
| `deploy/install.sh` | Idempotenter Installer: User/Verzeichnisse, PG16, Rollen, Schema-Import, IP-Patch, systemd-Units, `ufw`, `fail2ban`, `unattended-upgrades` |
| `deploy/gfctl` | Verwaltungsskript: `start/stop/restart/status/backup/restore` über systemd; **kein** `clear` |
| `deploy/systemd/gf-ticket.service` u. a. (6 Units) | Eine gehärtete Unit pro Komponente |
| `deploy/systemd/gfserver.target` | Sammel-Target für alle Komponenten |
| `deploy/systemd/gf-backup.service` + `gf-backup.timer` | Täglicher automatischer DB-Dump, Aufbewahrung der letzten N |
| `deploy/gfserver.env.example` | Vorlage für IP, DB-Passwort, Ports |
| `.gitignore` | Secrets, Backups, Logs, `*.bak` |
| `docs/deployment-runbook.md` | Schritt-für-Schritt vom frischen VPS bis „Server online" |

Die bisherigen Dateien `install` und `server` werden entfernt bzw. ersetzt.

### Verhalten von `gfctl`

- `start` / `stop` / `restart` — über `systemctl … gfserver.target`.
- `status` — systemd-Status aller Units + kurze Ressourcenübersicht.
- `backup` — zeitgestempelter `pg_dump` aller drei Datenbanken nach
  `/opt/gfserver/backup/`, ohne `chmod`-Eskalation.
- `restore <ordner>` — Einspielen eines Backups (mit Bestätigungsabfrage).
- Bewusst **kein** `clear`.

## Tests / Verifikation

### Automatisch prüfbar

- `shellcheck` + `bash -n` für alle Skripte ohne Befunde.
- `systemd-analyze verify` für alle Unit-Dateien ohne Befunde.
- IP-Patch-Funktion isoliert: eine Binary-Kopie patchen, Bytes am Offset gegen
  den erwarteten Wert prüfen; Idempotenz (zweiter Lauf ändert nichts/keine
  Doppelpatchung).
- `install.sh` zweimal hintereinander ausführbar ohne Fehler (Idempotenz).

### In Wegwerf-VM (Ubuntu 24.04) prüfbar

- Vollständiger `install.sh`-Durchlauf ohne Fehler.
- PG-Rollen existieren mit den erwarteten (eingeschränkten) Rechten.
- systemd-Units starten; Prozesse laufen als `gfserver`.
- PostgreSQL ist von außen **nicht** erreichbar (Port-Scan von extern).
- `ufw`-Regelwerk entspricht der Vorgabe.

### Nicht hier automatisierbar

- Echter End-to-End-Test (Client meldet sich an, Spielwelt läuft) — Bestandteil
  der Runbook-Abnahme auf dem realen VPS, nicht in dieser Spec als „bestanden"
  behauptet.

## Offene Punkte / noch zu verifizieren

1. **Öffentliche Spiel-Ports:** exakter Satz via DB-Inspektion zu ermitteln.
2. **32-bit-Ausführung:** Die Binaries sind laut `file` „statically linked";
   voraussichtlich genügt der 32-bit-Kernel-Support (auf Ubuntu amd64 Standard)
   ohne i386-Multiarch-Pakete. Der Installer prüft die Ausführbarkeit und
   installiert `libc6:i386` nur als Fallback.
3. **`config.ini` (Top-Level, 0 Bytes):** Verwendung unklar — bei der Umsetzung
   prüfen, ob eine Komponente die Datei liest.
4. **PG-16-Kompatibilität der SQL-Dumps:** Die Dumps stammen aus einer älteren
   PostgreSQL-Version; Import in PG 16 ist zu verifizieren (insb. `dblink`).

## Abhängigkeiten zu späteren Blöcken

- Block ② setzt die hier erstellte `gf_app`-Rolle und die `gfserver.env`-Struktur
  voraus und ergänzt eine eingeschränkte Portal-DB-Rolle.
- Block ③ setzt das gehärtete Fundament und das Deployment-Runbook voraus.
