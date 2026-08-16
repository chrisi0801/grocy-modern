# Grocy Modern auf einem Proxmox-LXC installieren (Apache2)

Anleitung für diesen Fork auf einem LXC-Container, auf dem Apache2 und PHP bereits laufen.

Der Fork wird aus Git installiert, nicht aus einem Release-ZIP. Das heißt: Composer- und Yarn-Abhängigkeiten müssen einmalig selbst gebaut werden, weil sie nicht im Repository liegen.

---

## 0. Kurzüberblick

| | |
|---|---|
| Zielverzeichnis | `/opt/grocy` |
| Webroot (DocumentRoot) | `/opt/grocy/public` |
| Daten (DB, Uploads, Config) | `/opt/grocy/data` |
| Basis | Grocy 4.6.0 + Redesign-Branch |
| Standard-Login | `admin` / `admin` — **sofort ändern** |

**Wichtigste Stolperfalle vorweg:** Grocy 4.6 verlangt **PHP 8.5** und **SQLite 3.40+**. Kein aktuelles Debian/Ubuntu liefert PHP 8.5 aus den Standard-Repos, das muss aus dem Sury-Repo kommen (Schritt 2). Der Container prüft das beim ersten Aufruf und bricht sonst mit einer klaren Fehlermeldung ab.

---

## 1. Container vorbereiten

Auf dem Proxmox-Host, falls der Container noch nicht existiert — sonst überspringen:

```bash
# Auf dem Proxmox-Host: verfügbare Templates ansehen und ggf. herunterladen
pveam available | grep debian
pveam download local debian-13-standard_13.0-1_amd64.tar.zst   # Dateiname aus der Liste oben übernehmen

pct create 110 local:vztmpl/debian-13-standard_13.0-1_amd64.tar.zst \
  --hostname grocy \
  --cores 2 --memory 1024 --swap 512 \
  --rootfs local-lvm:8 \
  --net0 name=eth0,bridge=vmbr0,ip=dhcp \
  --unprivileged 1 --features nesting=1 \
  --onboot 1
pct start 110
pct enter 110
```

> **Distribution:** Nimm **Debian 12 (bookworm) oder 13 (trixie)**. Grund: Grocy verlangt SQLite ≥ 3.40 — Debian 12 liefert 3.40.1, Debian 13 ist neuer. Ubuntu 22.04 hat nur SQLite 3.37 und fällt damit durch die Prüfung; Ubuntu 24.04 (3.45) geht.

Ein unprivilegierter Container reicht völlig. `nesting=1` schadet nicht, ist hier aber nicht zwingend.

Im Container:

```bash
apt update && apt upgrade -y
apt install -y curl wget git unzip ca-certificates lsb-release apt-transport-https gnupg
```

### Prüfen, was schon da ist

```bash
php -v                    # welche PHP-Version läuft aktuell?
apache2 -v
php -r 'echo (new PDO("sqlite::memory:"))->query("select sqlite_version()")->fetch()[0], PHP_EOL;'
```

Die letzte Zeile muss **≥ 3.40.0** ausgeben. Ist sie kleiner, hilft nur eine neuere Distribution — SQLite kommt aus der Systembibliothek, gegen die PHP gelinkt ist.

---

## 2. PHP 8.5 installieren (Sury-Repo)

Debian liefert maximal PHP 8.4, Grocy braucht 8.5. Das Repo von Ondřej Surý ist die übliche Quelle:

```bash
install -d /etc/apt/keyrings
curl -fsSL https://packages.sury.org/php/apt.gpg -o /etc/apt/keyrings/sury-php.gpg
echo "deb [signed-by=/etc/apt/keyrings/sury-php.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" \
  > /etc/apt/sources.list.d/sury-php.list
apt update
```

> Auf Ubuntu stattdessen: `add-apt-repository ppa:ondrej/php && apt update`

Jetzt PHP 8.5 mit allen von Grocy geforderten Modulen:

```bash
apt install -y \
  php8.5 php8.5-cli libapache2-mod-php8.5 \
  php8.5-sqlite3 php8.5-gd php8.5-intl php8.5-mbstring \
  php8.5-curl php8.5-xml php8.5-zip
```

Was wofür gebraucht wird:

| Modul | Paket | Wofür |
|---|---|---|
| `pdo_sqlite` | `php8.5-sqlite3` | Datenbank |
| `gd` | `php8.5-gd` | Produkt-/Rezeptbilder skalieren |
| `intl` | `php8.5-intl` | Zahlen-/Datumsformate, Lokalisierung |
| `mbstring` | `php8.5-mbstring` | Umlaute & Co. |
| `fileinfo`, `ctype`, `filter`, `iconv`, `tokenizer`, `json`, `zlib` | in `php8.5-common` enthalten | diverse |
| `curl`, `xml`, `zip` | eigene Pakete | HTTP-Client, Composer |

### Alte PHP-Version im Apache ablösen

Wenn vorher z. B. PHP 8.4 als Apache-Modul lief, muss umgeschaltet werden — sonst liefert der Webserver weiterhin die alte Version aus, obwohl `php -v` auf der Kommandozeile schon 8.5 zeigt. Das ist die häufigste Fehlerquelle.

```bash
a2dismod php8.4 2>/dev/null    # ggf. an die tatsächlich vorhandene Version anpassen
a2enmod php8.5
update-alternatives --set php /usr/bin/php8.5
systemctl restart apache2
```

Kontrolle (der Wert aus dem Webserver zählt, nicht der aus der CLI):

```bash
mkdir -p /var/www/html && echo '<?php echo PHP_VERSION;' > /var/www/html/_v.php
curl -s localhost/_v.php; echo
rm /var/www/html/_v.php
```

Muss `8.5.x` ausgeben.

---

## 3. Build-Werkzeuge: Composer und Yarn

Beide werden **nur zum Installieren/Aktualisieren** gebraucht, nicht im laufenden Betrieb.

### Composer

```bash
curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
rm /tmp/composer-setup.php
composer --version
```

### Node + Yarn 1 (Classic)

Grocy nutzt die Datei `.yarnrc` mit Optionen (`--modules-folder`), die es **nur in Yarn 1.x** gibt. Yarn 2+/Berry funktioniert hier nicht.

```bash
curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
apt install -y nodejs
npm install --global yarn@1
yarn --version    # muss 1.22.x sein
```

---

## 4. Fork klonen und bauen

```bash
GH_USER=<dein-github-user>

cd /opt
git clone https://github.com/$GH_USER/grocy-modern.git grocy
cd /opt/grocy
git checkout claude/grocy-modern-design-redesign-pyvd3g
```

> Ist das Repository privat, brauchst du beim Klonen ein Personal Access Token:
> `git clone https://<DEIN_TOKEN>@github.com/$GH_USER/grocy-modern.git grocy`
> Alternativ per Deploy-Key über SSH.

Abhängigkeiten bauen (dauert ein paar Minuten, braucht Internetzugang):

```bash
cd /opt/grocy
composer install --no-dev --optimize-autoloader   # -> /opt/grocy/packages
yarn install                                       # -> /opt/grocy/public/packages
```

Kontrolle — beide Dateien/Ordner müssen existieren:

```bash
ls /opt/grocy/packages/autoload.php
ls -d /opt/grocy/public/packages/bootstrap
```

---

## 5. Konfiguration

```bash
cp /opt/grocy/config-dist.php /opt/grocy/data/config.php
nano /opt/grocy/data/config.php
```

Sinnvolle Anpassungen für einen deutschen Haushalt:

```php
Setting('MODE', 'production');        // unbedingt production - alles andere deaktiviert die Anmeldung!
Setting('DEFAULT_LOCALE', 'de');
Setting('CURRENCY', 'EUR');
Setting('CALENDAR_FIRST_DAY_OF_WEEK', '1');   // Montag
```

Alles, was du in `data/config.php` nicht setzt, wird aus `config-dist.php` als Standard übernommen. Bei Updates kannst du also einfach in `config-dist.php` nach neuen Optionen schauen.

Nicht benötigte Bereiche lassen sich hier auch komplett ausblenden, z. B.:

```php
Setting('FEATURE_FLAG_BATTERIES', false);
Setting('FEATURE_FLAG_CHORES', false);
```

### Rechte setzen

Nur `data/` muss vom Webserver beschreibbar sein:

```bash
chown -R root:www-data /opt/grocy
chmod -R 755 /opt/grocy
chown -R www-data:www-data /opt/grocy/data
chmod -R 775 /opt/grocy/data
```

---

## 6. Apache-vHost

```bash
nano /etc/apache2/sites-available/grocy.conf
```

```apache
<VirtualHost *:80>
    ServerName grocy.fritz.box

    DocumentRoot /opt/grocy/public

    <Directory /opt/grocy/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog  ${APACHE_LOG_DIR}/grocy_error.log
    CustomLog ${APACHE_LOG_DIR}/grocy_access.log combined
</VirtualHost>
```

Zwei Punkte, an denen es sonst klemmt:

- **`DocumentRoot` muss auf `public/` zeigen**, nicht auf `/opt/grocy`. Nur so liegen `data/` (Datenbank!) und `packages/` außerhalb des Web-Zugriffs.
- **`AllowOverride All`** ist nötig, damit `public/.htaccess` greift. Dort steht das URL-Rewriting, ohne das jede Unterseite eine 404 liefert.

Aktivieren:

```bash
a2enmod rewrite
a2ensite grocy
a2dissite 000-default        # optional, wenn Grocy allein auf dem Container läuft
apache2ctl configtest
systemctl reload apache2
```

### Ohne mod_rewrite

Wenn du `mod_rewrite` partout nicht willst, geht auch:

```php
// in data/config.php
Setting('DISABLE_URL_REWRITING', true);
```

Dann laufen alle URLs über `/index.php/...`. Mit mod_rewrite ist es aber schöner.

### Unterverzeichnis statt eigener Domain

Läuft Grocy unter `https://server/grocy/` statt auf einer eigenen (Sub-)Domain, zusätzlich setzen:

```php
Setting('BASE_URL', '/grocy/');
```

---

## 7. Erster Start

Im Browser die Adresse aufrufen, z. B. `http://grocy.fritz.box/`.

Beim ersten Aufruf der Startseite `/` legt Grocy die SQLite-Datenbank an und führt alle Schema-Migrationen aus. Das dauert einmalig einige Sekunden — nicht abbrechen.

- Anmeldung: **`admin` / `admin`**
- Passwort sofort ändern: Benutzermenü rechts oben → *Passwort ändern*

Danach lohnt ein Blick auf:

- Rechts oben *Ansichtseinstellungen* → **Nachtmodus** (an / aus / Systemeinstellung folgen)
- Am Handy: Seite über „Zum Startbildschirm hinzufügen" installieren — Grocy ist eine PWA und läuft dann wie eine App im Vollbild

---

## 8. Updates

**Benutze `update.sh` nicht.** Das Skript lädt das offizielle Grocy-Release herunter und überschreibt damit alles — dein Redesign wäre weg.

Für diesen Fork stattdessen:

```bash
# Backup: die Datenbank ist eine einzige Datei
cd /opt/grocy
tar czf /root/grocy-backup-$(date +%F).tgz data/

# Erst der Pull, ALLEIN und mit Blick auf die Ausgabe.
# Schlaegt er fehl (Zugangsdaten, lokale Aenderungen), darf der Rest nicht laufen.
git pull

# Dann der Rest, mit && verkettet, damit ein Fehler die Kette abbricht
systemctl stop apache2 \
  && composer install --no-dev --optimize-autoloader \
  && yarn install \
  && chown -R www-data:www-data data/ \
  && systemctl start apache2
```

> Das `git pull` bewusst einzeln: Bei einem privaten Repository kann es nach Zugangsdaten fragen. Fügst du den ganzen Block auf einmal ein, wird die nächste Zeile als Benutzername verschluckt, der Pull scheitert — und alles danach läuft trotzdem weiter, als wäre nichts gewesen. Nach dem Update also immer gegenprüfen:
>
> ```bash
> git log -1 --format='%h %s'
> ```

Danach einmal die Startseite `/` aufrufen — dort laufen fällige Datenbank-Migrationen.

### Änderungen aus dem originalen Grocy übernehmen

```bash
git remote add upstream https://github.com/grocy/grocy.git
git fetch upstream
git merge upstream/master        # oder: git rebase upstream/master
```

Konflikte sind vor allem in `views/layout/default.blade.php` und den CSS-Dateien zu erwarten. Weil das Redesign fast vollständig in eigenen Dateien liegt (`public/css/grocy_theme.css`, `grocy_mobile.css`, `views/layout/bottomnav.blade.php`), bleibt die Konfliktfläche aber klein.

> Beachte: Dieser Fork basiert auf dem `master`-Branch von Grocy, also der Entwicklungsversion. Grocy garantiert Datenbank-Migrationen offiziell nur zwischen Releases, nicht zwischen einzelnen Commits. Vor einem `git pull` also wirklich das Backup machen.

---

## 9. Sicherung

Alles Wichtige liegt in einem Verzeichnis:

```bash
tar czf /root/grocy-backup-$(date +%F).tgz -C /opt/grocy data/
```

Als nächtlicher Cronjob:

```bash
cat > /etc/cron.daily/grocy-backup <<'EOF'
#!/bin/bash
mkdir -p /root/backups
tar czf /root/backups/grocy-$(date +%F).tgz -C /opt/grocy data/
find /root/backups -name 'grocy-*.tgz' -mtime +30 -delete
EOF
chmod +x /etc/cron.daily/grocy-backup
```

Auf Proxmox-Ebene erledigt ein regulärer Container-Snapshot bzw. ein `vzdump`-Backup dasselbe komfortabler.

---

## 10. HTTPS

Für die PWA-Installation am Handy und für Kamera-Barcodescanning ist HTTPS praktisch Pflicht — Browser geben den Kamerazugriff auf reinem HTTP nur unter `localhost` frei.

Bei einer öffentlich erreichbaren Domain:

```bash
apt install -y certbot python3-certbot-apache
certbot --apache -d grocy.deine-domain.de
```

Im reinen Heimnetz bietet sich stattdessen ein vorgelagerter Reverse Proxy (Nginx Proxy Manager, Traefik, Caddy) mit einem eigenen oder DNS-validierten Let's-Encrypt-Zertifikat an.

### Hinweis: HTTPS-Schema muss beim Proxy ankommen

Grocy erzeugt **absolute** URLs und leitet `http` vs. `https` aus `X-Forwarded-Proto` ab (siehe `helpers/UrlManager.php`). Nginx Proxy Manager, Traefik und Caddy senden diesen Header standardmäßig — dort ist **nichts zu konfigurieren**.

Nur wenn ein Proxy den Header nicht mitschickt, verlinkt Grocy alle Assets mit `http://`, der Browser blockt das als Mixed Content und das Schloss verschwindet. Test:

```bash
curl -sI https://grocy.deine-domain.de/ | grep -i location
```

Steht dort `http://`, fehlt der Header. Bei Apache als Proxy nachrüsten mit `a2enmod headers` und `RequestHeader set X-Forwarded-Proto "https"`.

**`BASE_URL` dabei auf `/` lassen.** Trägt man dort eine feste Domain ein, wird jeder Zugriff über die interne IP auf diese Domain umgeleitet — die Installation wirkt dann von innen unerreichbar. Zurücksetzen: Zeile in `data/config.php` wieder auf `Setting('BASE_URL', '/');`.

Läuft Grocy in einem Unterpfad statt auf einer eigenen (Sub-)Domain, zusätzlich `Setting('BASE_PATH', '/grocy');` setzen.

---

## 11. Fehlersuche

| Symptom | Ursache / Lösung |
|---|---|
| `PHP 8.5.0 is required, however you are running 8.4.x` | Apache nutzt noch das alte PHP-Modul. `a2dismod php8.4 && a2enmod php8.5 && systemctl restart apache2`, dann mit der `_v.php`-Probe aus Schritt 2 gegenprüfen. |
| `SQLite 3.40.0 is required, however you are running 3.3x` | Distribution zu alt. Debian 12/13 oder Ubuntu 24.04 nutzen. |
| `/packages/autoload.php not found. Have you run Composer?` | `composer install` im Projektverzeichnis vergessen oder fehlgeschlagen. |
| `config.php in data directory not found` | `cp config-dist.php data/config.php` vergessen. |
| `PHP module 'intl' not installed` | Fehlendes Modul nachinstallieren: `apt install php8.5-intl && systemctl restart apache2`. |
| Startseite lädt, jede Unterseite ist 404 | `mod_rewrite` fehlt oder `AllowOverride All` steht nicht im vHost. Alternativ `DISABLE_URL_REWRITING` setzen. |
| Seite ohne jedes Styling, Icons fehlen | `yarn install` nicht gelaufen — `/opt/grocy/public/packages` ist leer. |
| Weiße Seite, keine Meldung | `tail -f /var/log/apache2/grocy_error.log` |
| Fehler beim Speichern, „database is locked" | Rechte auf `data/`: `chown -R www-data:www-data /opt/grocy/data` |
| Anmeldung wird übersprungen, alles ist offen | `MODE` steht nicht auf `production`. In `data/config.php` korrigieren. |

Log live mitlesen:

```bash
tail -f /var/log/apache2/grocy_error.log
```

---

## 12. Was am Redesign anders ist

Für den Betrieb ändert sich nichts — es sind reine Frontend-Dateien. Relevant nur, falls du selbst nachjustieren willst:

- `public/css/grocy_theme.css` — alle Farben, Radien, Schatten und Typo als CSS-Variablen. Farben ändern heißt hier: die Variablen im `:root`-Block anpassen.
- `public/css/grocy_mobile.css` — Karten-Darstellung der Tabellen am Handy und Touch-Ergonomie.
- `public/css/grocy_menu_layout.css` + `public/js/grocy_menu_layout.js` — Sidebar, mobiler Drawer, Bottom-Navigation.
- `public/css/grocy_night_mode.css` — Dark Mode, ebenfalls nur Variablen.
- `views/layout/bottomnav.blade.php` — die Einträge der unteren Navigationsleiste am Handy.

Eigene Anpassungen, die ein Update überleben sollen, gehören nicht in diese Dateien, sondern nach `data/custom_css.html` bzw. `data/custom_js.html` — die werden automatisch eingebunden und liegen im nicht versionierten `data`-Verzeichnis. Beispiel für eine andere Akzentfarbe:

```html
<!-- data/custom_css.html -->
<style>
  :root {
    --g-brand: #7c3aed;
    --g-brand-hover: #6d28d9;
    --g-brand-soft: #f0e9fe;
    --g-brand-soft-text: #5b21b6;
  }
</style>
```
