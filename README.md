-----

<div align="center">
<img alt="Logo" height="50" src="public/img/logo.svg" />
<h2>Grocy Modern</h2>
<h3>Grocy mit einem modernen, mobile-first Design</h3>
<em><h4>Persönlicher Fork von <a href="https://github.com/grocy/grocy">grocy</a> — dem Haushalts-ERP von <a href="https://berrnd.de">Bernd Bestel</a></h4></em>
</div>

-----

## Worum geht es hier?

[Grocy](https://grocy.info) ist ein hervorragendes selbst gehostetes Haushalts-ERP — Bestände, Einkaufszettel, Rezepte, Speiseplan, Hausarbeiten. Nur die Oberfläche war mir zu altbacken und am Handy zu unhandlich, vor allem die breiten Tabellen.

Dieser Fork ändert **ausschließlich das Frontend**. Funktionsumfang, Datenbank und REST-API sind unverändert — es ist dasselbe Grocy, nur mit neuer Oberfläche.

|  |  |
|---|---|
| <img src="docs/img/mobile-stock.png" alt="Bestandsübersicht am Handy" width="260"> | <img src="docs/img/mobile-stock-dark.png" alt="Dark Mode" width="260"> |
| Tabellen werden am Handy zu Karten | Dark Mode über dieselben Design-Tokens |
| <img src="docs/img/mobile-drawer.png" alt="Navigations-Drawer" width="260"> | <img src="docs/img/desktop-stock.png" alt="Desktop" width="260"> |
| Off-Canvas-Navigation statt aufklappendem Menü | Desktop mit einklappbarer Sidebar |

## Was ist anders?

**Design-System statt Bootstrap-Standard.** Alle Farben, Radien, Schatten und Typografie sind CSS-Variablen in [`public/css/grocy_theme.css`](public/css/grocy_theme.css). Umfärben heißt: ein paar Variablen überschreiben — am besten in `data/custom_css.html`, dann überlebt es Updates:

```html
<style>
  :root {
    --g-brand: #7c3aed;
    --g-brand-hover: #6d28d9;
    --g-brand-soft: #f0e9fe;
    --g-brand-soft-text: #5b21b6;
  }
</style>
```

**Tabellen werden am Handy zu Karten.** Unter 768 px wird jede DataTable automatisch umgebaut: Produktname als Überschrift, jede Zelle mit ihrem Spaltenkopf beschriftet, Aktionen als Fußzeile, leere Zellen entfallen, Status wird zu einem farbigen Balken. Weil dabei der Tabellenkopf verschwindet, gibt es darüber eine Leiste mit **Sortieren** und **Tabellenoptionen** — beides war am Handy vorher praktisch nicht erreichbar. Das geschieht zentral in [`grocy_datatables.js`](public/js/grocy_datatables.js), keine der 70+ Views musste dafür angefasst werden.

**Neue App-Hülle.** Sticky Topbar, ruhigere Sidebar mit Markierung der aktiven Seite, einklappbar auf eine Icon-Leiste. Am Handy ein echter Off-Canvas-Drawer mit Abdunklung, Escape und Wisch-Geste statt eines Menüs, das den Inhalt nach unten schiebt. Dazu eine Bottom-Tab-Leiste mit den wichtigsten Ansichten und einem `+`-Button für Einkauf, Verbrauch, Umlagern und Inventur.

**Dialoge als Bottom-Sheets.** Am Handy fahren Dialoge von unten ein und lassen sich mit einem Wisch nach unten schließen — auch die Formular-Dialoge, die intern ein iframe sind.

**Dark Mode auf Token-Basis**, dadurch lückenlos über alle Komponenten hinweg.

## Installation

Zwei dokumentierte Wege:

| | |
|---|---|
| **[Docker / Docker Compose](docs/install-docker.md)** | Empfohlen. Bringt PHP 8.5 und SQLite mit, kein Build-Werkzeug auf dem Host. Inklusive Portainer-Stack und GitHub-Actions-Workflow, der das Image nach ghcr.io schiebt. |
| **[Proxmox LXC mit Apache2](docs/install-proxmox-lxc.md)** | Klassische Installation direkt auf dem Host. Braucht PHP 8.5 aus dem Sury-Repo sowie Composer und Yarn. |

Standard-Login nach der Installation: `admin` / `admin` — bitte sofort ändern.

> **Wichtig:** `update.sh` aus dem originalen Grocy **nicht** benutzen. Das Skript lädt das offizielle Release und überschreibt damit diesen Fork. Wie Updates stattdessen laufen, steht in beiden Anleitungen.

## Änderungen aus dem originalen Grocy übernehmen

```bash
git remote add upstream https://github.com/grocy/grocy.git
git fetch upstream
git merge upstream/master
```

Das Redesign liegt weitgehend in eigenen Dateien (`grocy_theme.css`, `grocy_mobile.css`, `grocy_sheet.js`, `views/layout/bottomnav.blade.php`), die Konfliktfläche bleibt dadurch klein. Erwartbare Konflikte: `views/layout/default.blade.php` und `public/css/grocy.css`.

> Dieser Fork setzt auf dem `master`-Branch von Grocy auf, also der Entwicklungsversion. Datenbank-Migrationen garantiert Grocy offiziell nur zwischen Releases — vor einem Merge also ein Backup von `data/` ziehen.

## Geänderte Dateien

| Datei | Rolle |
|---|---|
| `public/css/grocy_theme.css` | Design-Tokens und alle Komponenten-Overrides |
| `public/css/grocy_mobile.css` | Karten-Darstellung der Tabellen, Touch-Ergonomie |
| `public/css/grocy_menu_layout.css` | Topbar, Sidebar, Drawer, Bottom-Navigation |
| `public/css/grocy_night_mode.css` | Dark Mode (nur Token-Overrides) |
| `public/js/grocy_menu_layout.js` | Drawer, Sidebar-Rail |
| `public/js/grocy_datatables.js` | Karten-Modus, Sortier- und Optionsleiste |
| `public/js/grocy_sheet.js` | Wisch-zum-Schließen für Bottom-Sheets |
| `views/layout/default.blade.php` | Neue App-Hülle |
| `views/layout/bottomnav.blade.php` | Bottom-Navigation |
| `Dockerfile`, `docker/`, `docker-compose.yml` | Container-Image |

---

## Alles zu Grocy selbst

Funktionsumfang, REST-API, Barcode-Scanner, Eingabe-Kürzel, Plugins und alles Weitere sind unverändert. Die maßgebliche Dokumentation dazu ist die des originalen Projekts:

- Website und Feature-Übersicht &rarr; <https://grocy.info>
- Original-Repository und README &rarr; <https://github.com/grocy/grocy>
- Öffentliche Demo &rarr; <https://demo.grocy.info>
- REST-API-Browser &rarr; die integrierte Swagger-UI unter `/api`
- Hilfe und Fragen zu Grocy &rarr; [r/grocy auf Reddit](https://www.reddit.com/r/grocy)

**Bugs und Feature-Wünsche, die das Design betreffen, gehören in dieses Repository. Alles andere gehört ins [Original](https://github.com/grocy/grocy/issues) — und bitte dort nicht mit diesem Fork verwechseln.**

Wenn dir Grocy gefällt: <https://grocy.info/#say-thanks>

## Lizenz

MIT — wie das originale Grocy. Copyright (c) 2017-2026 Bernd Bestel (<https://berrnd.de>), siehe [LICENSE](LICENSE).

Das Redesign in diesem Fork steht unter derselben Lizenz.
