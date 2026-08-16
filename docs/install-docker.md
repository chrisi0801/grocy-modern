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

> Wenn du das Repository geforkt hast: In den Befehlen unten `chrisi0801` durch deinen eigenen GitHub-Account ersetzen.

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

Die Sichtbarkeit des Packages ist unabhängig von der des Repositories und lässt sich unter *GitHub → dein Profil → Packages → grocy-modern → Package settings* umstellen.

- **Package öffentlich** (Standard bei öffentlichem Repository): nichts weiter zu tun, jeder Host kann ziehen.
- **Package privat**: auf jedem ziehenden Host einmalig anmelden, mit einem Personal Access Token (classic) mit Scope `read:packages`:

```bash
echo '<DEIN_TOKEN>' | docker login ghcr.io -u chrisi0801 --password-stdin
```

Prüfen, welche Tags es gibt und ob anonym gezogen werden kann:

```bash
docker pull ghcr.io/chrisi0801/grocy-modern:latest
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

### Als Portainer-Stack

Portainer kann aus dem Web-Editor heraus nicht bauen — es braucht ein fertiges Image, also Variante B.

> Nur falls das Package **privat** ist, vorher einmalig die Registry hinterlegen: *Registries* → *Add registry* → *Custom registry*, URL `ghcr.io`, Benutzername dein GitHub-Name, Passwort ein PAT mit `read:packages`. Bei öffentlichem Package entfällt das.

*Stacks* → *Add stack* → *Web editor*, als Namen `grocy` vergeben und einfügen:

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

### Hinweis: HTTPS-Schema muss beim Proxy ankommen

Grocy erzeugt **absolute** URLs und leitet `http` vs. `https` aus `X-Forwarded-Proto` ab (siehe `helpers/UrlManager.php`). Nginx Proxy Manager, Traefik und Caddy senden diesen Header standardmäßig — dort ist **nichts zu konfigurieren**.

Nur wenn ein Proxy den Header nicht mitschickt, verlinkt Grocy alle Assets mit `http://`, der Browser blockt das als Mixed Content und das Schloss verschwindet. Test:

```bash
curl -sI https://grocy.deine-domain.de/ | grep -i location
```

Steht dort `http://`, fehlt der Header. Bei Apache als Proxy nachrüsten mit `a2enmod headers` und `RequestHeader set X-Forwarded-Proto "https"`.

**`GROCY_BASE_URL` dabei nicht setzen.** Mit einer festen Domain darin wird jeder Zugriff über `http://<ip>:9283` auf diese Domain umgeleitet — der Container wirkt dann von innen unerreichbar. Einfach die Zeile wieder aus dem Stack entfernen.

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
| Startseite lädt, Unterseiten 404 | Sollte im Image nicht passieren, `mod_rewrite` und `AllowOverride All` sind gesetzt. |
| Über die Domain nicht erreichbar, Timeout | Zeigt der DNS-Eintrag auf den **Reverse Proxy** oder versehentlich direkt auf den Container-Host? Nur der Proxy nimmt Port 443 an. |
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
