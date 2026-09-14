# Book Courts

Platzbuchung für Sportvereine: Mitglieder buchen Tennis- oder Badmintonplätze im 30-Minuten-Raster, Admins verwalten Plätze, Nutzer und wiederkehrende Serien. Doppelbuchungen verhindert die Datenbank selbst.

Reines PHP und PostgreSQL, ohne Framework, ohne Composer, ohne Build-Schritt. Läuft auf gewöhnlichem Webhosting mit Apache.

## Funktionen

**Mitglieder** (`index.php`)
- Wochen- und Tagesansicht aller Plätze
- Einzelbuchungen im 30-Minuten-Raster bis zur konfigurierten Höchstdauer (Standard 60 Minuten), nicht in der Vergangenheit
- Eigene Buchungen stornieren
- Der Kalender ist ohne Login einsehbar, Namen erscheinen dann nur als Initialen

**Admins** (`admin.php`, zusätzlich alles aus `index.php`)
- Buchungen bis 12 Stunden, auch rückwirkend
- Wiederkehrende Serien (z. B. Mannschaftstraining jeden Mittwoch)
- Plätze und Nutzer anlegen, bearbeiten, deaktivieren
- Beliebige Buchungen und Serien stornieren

## Voraussetzungen

- PHP 8.0+ mit `pdo_pgsql`
- PostgreSQL 13+
- Apache mit `mod_rewrite` und `mod_headers` (für Produktion), HTTPS

## Lokale Entwicklung

```bash
# 1. Datenbank anlegen und Schema einspielen
createdb book_courts_test
psql -d book_courts_test -f pg.sql

# 2. Konfiguration
cp .env.example .env
chmod 600 .env
# .env ausfüllen, z. B.:
#   DB_HOST=127.0.0.1  DB_NAME=book_courts_test  DB_USER=<dein-user>
#   DB_PASS=local      DB_SCHEMA=book_courts

# 3. Server starten
php -S localhost:8000 -t .
```

Dann http://localhost:8000/admin.php öffnen. Solange die Datenbank keinen Nutzer enthält, erscheint dort das Formular **Erst-Admin anlegen**. Danach mindestens einen Platz anlegen, sonst lässt sich nichts buchen.

Der PHP-Entwicklungsserver ignoriert `.htaccess`, lokal gibt es daher weder HTTPS-Umleitung noch Dateischutz.

## Konfiguration

Alle Werte stehen in `.env` (nicht im Repository, siehe `.env.example`):

| Schlüssel | Bedeutung |
|---|---|
| `DB_HOST`, `DB_PORT`, `DB_NAME` | PostgreSQL-Verbindung |
| `DB_USER`, `DB_PASS` | Zugangsdaten |
| `DB_SCHEMA` | Schema, in dem die Tabellen liegen (Standard: `book_courts`) |
| `MAX_BOOKING_MINUTES_USER` | Optional. Längste Buchung für Mitglieder in Minuten (Standard: `60`). Buchungen liegen im 30-Minuten-Raster: Werte daneben werden auf die längste enthaltene Rasterdauer abgerundet (`100` → 90, `45` → 30). Mindestens 30, höchstens 720. Keine ganze Zahl → Fehler beim Seitenaufruf |

Werte werden wörtlich übernommen, alles nach dem ersten `=` bis zum Zeilenende. Sonderzeichen wie `;`, `#` oder `"` brauchen keine Maskierung. Anführungszeichen sind nur nötig, wenn ein Wert mit einem Leerzeichen beginnt oder endet.

## Datenbankschema

`pg.sql` ist idempotent und löscht nichts. Es kann gefahrlos mehrfach laufen, auch gegen eine Datenbank, in der andere Anwendungen liegen, und legt ausschließlich Objekte im Ziel-Schema an. Neue Tabellen aus späteren Versionen kommen durch einen erneuten Lauf hinzu.

```bash
# Standard-Schema book_courts
psql -h <host> -U <user> -d <datenbank> -f pg.sql

# Abweichendes Schema - dann DB_SCHEMA in .env gleich setzen
psql -h <host> -U <user> -d <datenbank> -v schema_name=mein_schema -f pg.sql
```

Eine Entwicklungsdatenbank setzt man von Hand zurück: `DROP SCHEMA book_courts CASCADE;` – niemals in einer Datenbank mit weiteren Anwendungen.

## Deployment

`deploy.sh` spiegelt per FTPS (`lftp`) genau die Dateien, die der Server braucht: `index.php`, `admin.php`, `config.php`, `.htaccess`, `.env` und `assets/`.

```bash
cp .env.deploy.example .env.deploy   # FTP-Zugangsdaten eintragen
./deploy.sh --dry-run                # zeigt, was übertragen würde
./deploy.sh
```

Reihenfolge bei einem Update:

1. `.env` muss die **Produktions**-Zugangsdaten enthalten. `deploy.sh` lädt die lokale `.env` mit hoch – mit Entwicklungswerten zeigt die Live-App danach auf `127.0.0.1`.
2. `pg.sql` gegen die Produktionsdatenbank laufen lassen, falls sich das Schema geändert hat.
3. `./deploy.sh`

## Sicherheit

- Überschneidungen verhindert die Datenbank: Advisory Lock pro Platz, Konfliktprüfung in `create_booking()` und ein Trigger als zweite Absicherung
- CSRF-Token auf allen Formularen, durchgängig Prepared Statements, HTML-Escaping aller Ausgaben
- Session-Cookie mit `HttpOnly`, `SameSite=Lax` und `Secure` (bei HTTPS), neue Session-ID nach dem Login, Abmeldung nach 8 Stunden Inaktivität
- Login-Sperre nach 5 Fehlversuchen pro Konto bzw. 20 pro IP innerhalb von 15 Minuten; einheitliche Fehlermeldung, damit sich registrierte Adressen nicht ermitteln lassen
- `.htaccess`: Umleitung auf HTTPS, HSTS, Zugriffssperre für `.env`, `pg.sql`, `deploy.sh` und Markdown-Dateien
- Tailwind und Lucide werden lokal ausgeliefert, es gibt keine Anfragen an fremde Server

## Datenaufbewahrung

Buchungen und abgelaufene Serien werden 14 Tage nach ihrem Ende gelöscht. Die Bereinigung läuft höchstens einmal täglich und wird durch die nächste Buchung angestoßen; einen Cronjob braucht es nicht.

## Aufbau

| Datei | Inhalt |
|---|---|
| `index.php` | Kalender, Buchen, Stornieren, Serien, AJAX-Endpunkte `free_courts` und `series_preview` |
| `admin.php` | Admin-Oberfläche: Übersicht, Nutzer, Plätze |
| `config.php` | `.env`-Parser, Datenbankverbindung, Session-Handling, Login-Sperre, Bereinigung |
| `pg.sql` | Schema, Trigger und Stored Functions |
| `assets/` | Tailwind 3.4.16 und Lucide 0.469.0, Version im Dateinamen |
| `.htaccess` | HTTPS, Sicherheits-Header, Dateischutz, Caching |
| `deploy.sh` | FTPS-Deployment |
