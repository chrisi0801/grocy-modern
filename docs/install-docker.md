# Grocy Modern mit Docker Compose betreiben

Dieses Repository bringt alles mit, was für ein eigenes Container-Image nötig ist:

| Datei | Zweck |
|---|---|
| `Dockerfile` | Mehrstufiger Build: Composer-Stage, Yarn-Stage, schlankes Runtime-Image |
| `.dockerignore` | Hält lokale Daten und Build-Artefakte aus dem Image |
| `docker/apache-grocy.conf` | Apache-vHost im Container (DocumentRoot auf `public/`) |
| `docker/entrypoint.sh` | Datenverzeichnis, Zeitzone und Dateirechte beim Start vorbereiten |
| `docker-compose.yml` | Fertiges Compose-Setup |
| `.env.example` | Vorlage für Port, Zeitzone, Sprache, Währung |
| `.github/workflows/docker-image.yml` | Baut das Image in GitHub Actions und legt es in der GitHub Container Registry ab |

Das Image basiert auf `php:8.5-apache-trixie` und bringt PHP 8.5 sowie SQLite in einer ausreichend neuen Version schon mit. Die Klimmzüge aus der LXC-Anleitung (Sury-Repo, PHP-Version im Apache umschalten) entfallen damit komplett.

---

## Voraussetzung: Docker im Proxmox-LXC

Wenn Docker in einem **LXC-Container** statt in einer VM laufen soll, braucht der Container zwei Features. Ohne sie startet der Docker-Daemon nicht oder verhält sich merkwürdig:

```bash
# Auf dem Proxmox-Host, Container vorher stoppen
pct set 110 --features nesting=1,keyctl=1
pct start 110
```

Docker selbst installieren:

```bash
curl -fsSL https://get.docker.com | sh
docker run --rm hello-world
```

> Alternativ läuft Docker in einer kleinen VM natürlich ebenfalls — dort brauchst du keine Sonderoptionen. Auf ZFS als Container-Storage ist eine VM sogar die ruhigere Variante.

---

## Variante A — direkt auf dem Zielhost bauen (am einfachsten)

Kein Registry-Konto, keine Zugangsdaten, ein Befehl.

```bash
cd /opt
git clone https://github.com/chrisi0801/grocy-modern.git grocy
cd grocy
git checkout claude/grocy-modern-design-redesign-pyvd3g

cp .env.example .env
nano .env            # Port, Zeitzone, Sprache, Währung

docker compose up -d --build
```

Der erste Build dauert einige Minuten (Composer- und Yarn-Abhängigkeiten werden geladen und die PHP-Erweiterungen `gd` und `intl` kompiliert). Danach:

```bash
docker compose ps          # sollte "healthy" zeigen
docker compose logs -f     # Apache-Log
```

Grocy ist erreichbar unter `http://<host>:9283` — Login `admin` / `admin`, **Passwort sofort ändern**.

---

## Variante B — Image über GitHub Actions bereitstellen

Sinnvoll, wenn mehrere Maschinen dasselbe Image ziehen sollen oder der Zielhost nichts bauen soll.

Der Workflow `.github/workflows/docker-image.yml` läuft bei jedem Push und legt das Image unter `ghcr.io/chrisi0801/grocy-modern` ab. Es ist nichts einzurichten — `GITHUB_TOKEN` reicht, ein eigenes Secret brauchst du nicht.

**Weil dein Repository privat ist, ist auch das Package privat.** Zum Ziehen brauchst du auf dem Zielhost einen Personal Access Token (classic) mit dem Scope `read:packages`:

```bash
echo '<DEIN_TOKEN>' | docker login ghcr.io -u chrisi0801 --password-stdin
```

Dann in der `.env`:

```dotenv
GROCY_IMAGE=ghcr.io/chrisi0801/grocy-modern:latest
```

und in `docker-compose.yml` den `build:`-Block auskommentieren. Danach:

```bash
docker compose pull
docker compose up -d
```

> Wenn du das Package in den GitHub-Einstellungen auf *public* stellst, entfällt der `docker login` — das Repository selbst bleibt dabei privat.

### Als Portainer-Stack

Portainer kann aus dem Web-Editor heraus nicht bauen — es braucht ein fertiges Image, also Variante B. Vorher einmalig die Registry hinterlegen: *Registries* → *Add registry* → *Custom registry*, URL `ghcr.io`, Benutzername dein GitHub-Name, Passwort ein PAT mit `read:packages`.

Dann *Stacks* → *Add stack* → *Web editor*, als Namen `grocy` vergeben und einfügen:

```yaml
services:
  grocy:
    image: ghcr.io/chrisi0801/grocy-modern:latest
    container_name: grocy
    restart: unless-stopped
    ports:
      - "9283:80"
    volumes:
      - grocy-data:/var/www/html/data
    environment:
      TZ: Europe/Vienna
      GROCY_DEFAULT_LOCALE: de
      GROCY_CURRENCY: EUR
      GROCY_CALENDAR_FIRST_DAY_OF_WEEK: "1"

volumes:
  grocy-data:
```

Zwei Unterschiede zur `docker-compose.yml` im Repository: kein `build:`-Block (Portainer kann nicht bauen) und kein `name:` — Portainer setzt den Projektnamen selbst auf den Stack-Namen. Das Volume heißt dadurch `<stackname>_grocy-data`, bei Stack-Name `grocy` also wie gehabt `grocy_grocy-data`.

Updates laufen danach über *Stacks* → *grocy* → **Update the stack** mit angehaktem *Re-pull image*. Baut der GitHub-Actions-Workflow ein neues `:latest`, holt Portainer es damit und startet den Container neu — das Volume bleibt unangetastet.

---

## Variante C — Image woanders bauen und übertragen

Ohne Registry, wenn der Zielhost keinen Internetzugang hat:

```bash
# Auf der Build-Maschine
docker build -t grocy-modern:local .
docker save grocy-modern:local | gzip > grocy-modern.tar.gz

# Auf den Zielhost kopieren und dort
gunzip -c grocy-modern.tar.gz | docker load
docker compose up -d      # build: in der compose-Datei auskommentieren
```

---

## Konfiguration

Es gibt zwei Wege, und sie lassen sich mischen:

**1. Umgebungsvariablen (empfohlen für Docker).** Jede Option aus `config-dist.php` kann mit dem Präfix `GROCY_` als Umgebungsvariable gesetzt werden und hat Vorrang vor `config.php`:

```yaml
environment:
  TZ: Europe/Vienna
  GROCY_DEFAULT_LOCALE: de
  GROCY_CURRENCY: EUR
  GROCY_CALENDAR_FIRST_DAY_OF_WEEK: "1"
  GROCY_FEATURE_FLAG_BATTERIES: "false"
```

**2. `config.php` im Datenverzeichnis.** Beim ersten Start legt der Entrypoint dort automatisch eine Kopie von `config-dist.php` an. Bearbeiten:

```bash
docker compose exec grocy nano /var/www/html/data/config.php   # ggf. vi
docker compose restart grocy
```

### Zeitzone

`TZ` unbedingt setzen. Grocy hat keine eigene Zeitzonen-Einstellung, sondern nutzt die von PHP — ohne `TZ` läuft der Container auf UTC und „fällig heute" verschiebt sich um ein paar Stunden. Der Entrypoint erzeugt daraus automatisch die passende `date.timezone`.

---

## Daten und Backup

Alles Veränderliche liegt im Volume `grocy-data` unter `/var/www/html/data`: die SQLite-Datenbank, hochgeladene Bilder und `config.php`.

```bash
# Backup
docker run --rm -v grocy_grocy-data:/data -v "$PWD":/backup alpine \
  tar czf /backup/grocy-$(date +%F).tgz -C /data .

# Restore
docker compose down
docker run --rm -v grocy_grocy-data:/data -v "$PWD":/backup alpine \
  sh -c 'rm -rf /data/* && tar xzf /backup/grocy-2026-08-16.tgz -C /data'
docker compose up -d
```

> Der Volume-Name ist `grocy_grocy-data`, weil in `docker-compose.yml` oben `name: grocy` gesetzt ist. Gegenprüfen mit `docker volume ls`.

Wer lieber direkt ins Dateisystem sichert, ersetzt das Volume durch einen Bind-Mount:

```yaml
volumes:
  - ./data:/var/www/html/data
environment:
  PUID: "1000"     # Ausgabe von `id -u`
  PGID: "1000"     # Ausgabe von `id -g`
```

`PUID`/`PGID` sind bei Bind-Mounts wichtig, sonst gehören die Dateien der Container-`www-data` (uid 33) und du kommst auf dem Host nur als root heran.

---

## Umzug von der bestehenden LXC-Installation

Deine laufende Installation lässt sich einfach übernehmen — es ist nur ein Verzeichnis:

```bash
# 1. Alte Installation stoppen
systemctl stop apache2

# 2. Datenverzeichnis sichern
tar czf /root/grocy-data.tgz -C /opt/grocy data/

# 3. Compose-Setup hochfahren, damit das Volume existiert
cd /opt/grocy && docker compose up -d && docker compose stop

# 4. Daten ins Volume kopieren
docker run --rm -v grocy_grocy-data:/data -v /root:/backup alpine \
  sh -c 'rm -rf /data/* && tar xzf /backup/grocy-data.tgz -C /data --strip-components=1'

# 5. Starten
docker compose up -d
```

Beim ersten Aufruf der Startseite laufen fällige Datenbank-Migrationen. Version und Schema sind identisch, es sollte also nichts zu tun sein.

Wenn danach alles läuft, kannst du auf dem Host Apache und PHP entfernen — oder den alten vHost einfach deaktivieren (`a2dissite grocy && systemctl reload apache2`), damit sich nichts um Port 80 streitet.

---

## Reverse Proxy und HTTPS

Der Container spricht nur HTTP auf Port 80 — TLS gehört davor. Beispiel für einen Apache auf demselben Host:

```apache
<VirtualHost *:443>
    ServerName grocy.deine-domain.de

    ProxyPreserveHost On
    ProxyPass        / http://127.0.0.1:9283/
    ProxyPassReverse / http://127.0.0.1:9283/

    SSLEngine on
    SSLCertificateFile    /etc/letsencrypt/live/grocy.deine-domain.de/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/grocy.deine-domain.de/privkey.pem
</VirtualHost>
```

Dafür `a2enmod proxy proxy_http ssl`. Mit Nginx Proxy Manager, Traefik oder Caddy ist es entsprechend ein normaler HTTP-Backend-Eintrag auf Port 9283.

HTTPS lohnt sich nicht nur aus Prinzip: Der Kamera-Barcodescanner und die Installation als App aufs Handy funktionieren in Browsern nur in einem „secure context".

### ⚠️ „Keine sichere Verbindung" hinter dem Reverse Proxy

Das ist die mit Abstand häufigste Stolperfalle — und der Grund, warum eine ganz normale Webseite hinter demselben Proxy problemlos läuft, Grocy aber nicht.

**Warum:** Grocy erzeugt **absolute** URLs. Steht `BASE_URL` auf dem Standardwert `/`, baut sich Grocy die Basis-URL bei jedem Request selbst zusammen — und das Schema (`http` oder `https`) leitet es aus `$_SERVER['HTTPS']` bzw. dem Header `X-Forwarded-Proto` ab (siehe `helpers/UrlManager.php`). Terminiert der Proxy TLS und spricht dann per **HTTP** mit dem Container, sieht Grocy nur eine unverschlüsselte Anfrage. Ohne den Header schreibt es folglich in jeden Link und jeden `<script>`/`<link>`-Tag `http://…`:

```
Seite:      https://grocy.deine-domain.de/stockoverview
darin aber: http://grocy.deine-domain.de/css/grocy_theme.css
            http://grocy.deine-domain.de/packages/jquery/dist/jquery.min.js
```

Der Browser blockt das als *Mixed Content*: das Schloss verschwindet, das Design fehlt, nichts funktioniert. Und ein Klick auf die Startseite wirft dich per `Location: http://…` komplett aus HTTPS heraus. Eine statische Apache-Seite mit relativen Links kann das gar nicht passieren.

**Prüfen** (von irgendeinem Rechner aus):

```bash
curl -sI https://grocy.deine-domain.de/ | grep -i location
```

Kommt dort `Location: http://…` zurück, ist genau das der Fehler. Ebenso eindeutig: F12 → Konsole zeigt „Mixed Content"-Meldungen.

**Lösung 1 (empfohlen) — den Proxy den Header schicken lassen.** `BASE_URL` bleibt auf `/`, der Proxy sendet `X-Forwarded-Proto: https`. Im Nginx Proxy Manager beim Proxy Host unter *Advanced* → *Custom Nginx Configuration*:

```nginx
proxy_set_header X-Forwarded-Proto $scheme;
proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
proxy_set_header Host              $host;
```

Bei Apache als Proxy übernimmt `ProxyPass` das nicht automatisch:

```apache
RequestHeader set X-Forwarded-Proto "https"
```

(dafür `a2enmod headers`).

Vorteil: Grocy bestimmt das Schema weiterhin pro Request und bleibt parallel über `http://<ip>:9283` erreichbar.

**Lösung 2 — `BASE_URL` fest verdrahten.** Nur, wenn Lösung 1 nicht funktioniert:

```yaml
environment:
  GROCY_BASE_URL: "https://grocy.deine-domain.de"   # exakt deine echte Domain!
```

Damit rät Grocy nicht mehr, sondern nutzt exakt diese Basis — unabhängig von jedem Proxy-Header.

> ⚠️ **Danach ist Grocy nur noch über genau diese Domain erreichbar.** Der Aufruf über `http://<ip>:9283/` wird per Redirect auf die Domain geschickt, und alle Assets verlinken dorthin. Setz das nur, wenn die Domain auch aus deinem LAN auflöst (Split-DNS oder `hosts`-Eintrag) — und trag deine echte Domain ein, nicht den Beispielwert.

#### Wieder rauskommen, wenn `BASE_URL` falsch gesetzt wurde

Die App läuft weiter, sie schickt den Browser nur an eine Adresse, die du nicht erreichst:

```bash
# Was ist aktiv? (zeigt das Redirect-Ziel)
docker compose exec grocy php -r 'echo getenv("GROCY_BASE_URL") ?: "(nicht gesetzt)", PHP_EOL;'
curl -sI http://<host>:9283/ | grep -i location

# Zeile aus docker-compose.yml bzw. .env wieder entfernen, dann
docker compose up -d
```

Wurde `BASE_URL` stattdessen in der `config.php` im Volume gesetzt:

```bash
docker compose exec grocy sed -i "s|^Setting('BASE_URL'.*|Setting('BASE_URL', '/');|" /var/www/html/data/config.php
docker compose restart grocy
```

**Weitere Punkte, die im Nginx Proxy Manager gern schiefgehen:**

- Beim Proxy Host muss **Scheme = `http`** stehen (nicht `https`) — der Container spricht selbst kein TLS. Steht dort `https`, läuft der Proxy in einen Handshake-Fehler und zeigt „keine sichere Verbindung", ohne dass Grocy überhaupt beteiligt ist.
- *Force SSL* im Proxy Host einschalten, damit HTTP-Aufrufe auf HTTPS umgeleitet werden.
- *Websockets Support* wird von Grocy nicht gebraucht, schadet aber nicht.

Läuft Grocy nicht auf einer eigenen (Sub-)Domain, sondern in einem Unterpfad, zusätzlich `GROCY_BASE_URL=https://deine-domain.de/grocy` und `GROCY_BASE_PATH=/grocy` setzen.

---

## Updates

```bash
cd /opt/grocy

# Einzeln und mit Blick auf die Ausgabe - schlaegt der Pull fehl, darf
# nichts weiterlaufen (bei privaten Repos fragt er ggf. nach Zugangsdaten)
git pull
git log -1 --format='%h %s'           # steht hier der erwartete Commit?

docker compose up -d --build          # Variante A
# bzw.
docker compose pull && docker compose up -d   # Varianten B/C
```

Danach einmal die Startseite aufrufen — dort laufen fällige Datenbank-Migrationen. Das Datenvolume bleibt unberührt. Vorher ein Backup zu ziehen ist trotzdem eine gute Angewohnheit, weil dieser Fork auf dem Entwicklungsstand von Grocy aufsetzt und nicht auf einem Release.

Alte Images aufräumen:

```bash
docker image prune -f
```

---

## Wie das Image gebaut ist

Drei Stufen, damit weder Composer noch Node im fertigen Image landen:

1. **`vendor`** (`php:8.5-cli-trixie`) — `composer install`, Ergebnis liegt in `/app/packages`. `git` ist nötig, weil zwei Abhängigkeiten (`lessql`, `php-gettext`) aus GitHub-Forks kommen.
2. **`assets`** (`node:22-trixie-slim`) — `yarn install`, Ergebnis liegt in `/app/public/packages`. Es muss **Yarn 1** sein: die `.yarnrc` nutzt `--modules-folder`, das Yarn 2+ nicht mehr kennt.
3. **`runtime`** (`php:8.5-apache-trixie`) — Anwendungscode plus die beiden Ergebnisbäume. Von Grocys Pflicht-Erweiterungen fehlen im offiziellen PHP-Image nur `gd` und `intl`, alles andere ist einkompiliert.

Am Ende der Runtime-Stufe steht ein Prüfschritt, der Grocys eigene `PrerequisiteChecker`-Liste einliest und PHP-Version, SQLite-Version und alle Erweiterungen kontrolliert. Fehlt etwas, **schlägt schon der Build fehl** statt erst der erste Seitenaufruf.

Die `-dev`-Pakete werden bewusst nicht wieder deinstalliert: Das `apt-get purge --auto-remove` würde die Laufzeitbibliotheken mitnehmen, gegen die die frisch kompilierten Erweiterungen gelinkt sind. Das Image ist dadurch rund 150 MB größer, funktioniert aber zuverlässig.

---

## Fehlersuche

| Symptom | Ursache / Lösung |
|---|---|
| Build bricht mit `Missing PHP extension(s): …` ab | Der eingebaute Prüfschritt hat angeschlagen. Die genannte Erweiterung im `Dockerfile` bei `docker-php-ext-install` ergänzen. |
| Build bricht bei `yarn install` mit `exit code 128` ab | 128 ist Gits Fehlercode: `bootstrap-combobox` wird per Git von GitHub geholt. Im Image sind dafür `git` **und** `ca-certificates` nötig — Letzteres wird vom node-Image beim Aufräumen wieder entfernt und im Dockerfile deshalb explizit nachinstalliert. |
| Build bricht bei `composer install` mit `exit code 2` ab | Eine von der Lock-Datei geforderte PHP-Erweiterung fehlt in der Build-Stage. Welche, zeigt `composer check-platform-reqs`. |
| Container startet, aber `unhealthy` | `docker compose logs grocy`. Meist Rechte im Datenverzeichnis: bei Bind-Mount `PUID`/`PGID` setzen. |
| Startseite lädt, Unterseiten 404 | Sollte im Image nicht passieren (`mod_rewrite` und `AllowOverride All` sind gesetzt). Tritt es hinter einem Reverse Proxy auf, fehlt meist `GROCY_BASE_URL`. |
| Alle Zeiten sind um Stunden verschoben | `TZ` nicht gesetzt — Container läuft auf UTC. |
| `permission denied` beim Speichern | `docker compose exec grocy chown -R www-data:www-data /var/www/html/data` |
| Docker startet im LXC nicht | `nesting=1,keyctl=1` am Container fehlen (siehe oben). |
| `denied` beim `docker compose pull` | Kein `docker login ghcr.io`, oder der Token hat `read:packages` nicht. |

Logs live:

```bash
docker compose logs -f grocy
```

Shell im Container:

```bash
docker compose exec grocy bash
```
