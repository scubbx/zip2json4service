# uMap GeoJSON Spatial Filter Proxy

Ein PHP-basierter Proxy, der GeoJSON-Daten herunterlädt, Features räumlich gegen Puffer-Polygone filtert und die Ergebnisse mit Caching ausliefert. Ideal für den Einsatz mit [uMap](https://umap.openstreetmap.fr/) zur Darstellung gefilterter Geodaten.

---

## Inhaltsverzeichnis

- [Funktionsweise](#funktionsweise)
- [Voraussetzungen](#voraussetzungen)
- [Installation auf dem eigenen Webspace](#installation-auf-dem-eigenen-webspace)
- [Konfiguration](#konfiguration)
- [Verwendung](#verwendung)
- [Entwicklung und Tests](#entwicklung-und-tests)
- [uMap-Integration](#umap-integration)
- [Betrieb und Wartung](#betrieb-und-wartung)
- [Fehlerbehebung](#fehlerbehebung)
- [Struktur](#struktur)
- [Features](#features)
- [Hinweise](#hinweise)

---

## Funktionsweise

Der Proxy funktioniert in folgenden Schritten:

1. **Datenabruf**: Lädt die Quell-GeoJSON und die Puffer-GeoJSON (beide können gzip-komprimiert sein)
2. **Attributfilter**: Optional Filterung nach Verkehrsmitteltypen (z.B. `affected-transportmode-types`)
3. **Räumliche Filterung**: Behält nur Features, die mindestens ein Puffer-Polygon schneiden, berühren oder innerhalb liegen
4. **Punktdarstellung**: Berechnet parallel eine Punktdarstellung (Schwerpunkte) für Symbol-Layer
5. **Caching**: Speichert die gefilterten Ergebnisse zwischenspeichert für schnelle Auslieferung
6. **Auslieferung**: Liefert die Ergebnisse als GeoJSON mit CORS-Unterstützung aus

**Wichtig**: Beide GeoJSON-Datensätze müssen dasselbe Koordinatensystem verwenden (typischerweise WGS84 mit [Längengrad, Breitengrad]).

---

## Voraussetzungen

### Server-Anforderungen

| Anforderung | Version | Notizen |
|-------------|---------|--------|
| **PHP** | 8.0 oder neuer | `php -v` zum Prüfen |
| **PHP-cURL** | Empfohlen | Für HTTP-Anfragen |
| **PHP-zlib** | Erforderlich | Für gzip-Dekomprimierung (`gzdecode()`) |
| **Webserver** | Apache/Nginx/etc. | Mit PHP-Unterstützung |
| **Speicherplatz** | ~100 MB | Für Cache und temporäre Dateien |
| **Speicherlimit** | 256 MB+ | Abhängig von Datengröße |

### Prüfen der Voraussetzungen

Erstellen Sie eine Testdatei `phpinfo.php`:

```php
<?php
phpinfo();
```

Rufen Sie sie im Browser auf und prüfen Sie:
- PHP Version >= 8.0
- `curl` Extension ist aktiviert
- `zlib` Extension ist aktiviert

**Alternative CLI-Prüfung**:

```bash
php -r "echo 'PHP: ' . PHP_VERSION . '\n'; \
echo 'cURL: ' . (function_exists('curl_init') ? 'ja' : 'nein') . '\n'; \
echo 'zlib: ' . (function_exists('gzdecode') ? 'ja' : 'nein') . '\n';"
```

---

## Installation auf dem eigenen Webspace

### Schritt 1: Dateien herunterladen

**Option A: Git-Clone (empfohlen für Entwickler)**

```bash
# Auf Ihrem lokalen Rechner
git clone https://github.com/scubbx/zip2json4service.git
cd zip2json4service

# Wechseln zum modularen Branch (aktuelle Entwicklung)
git checkout refactor/modular-structure

# Dateien per FTP/SFTP auf den Webspace hochladen
# - Das gesamte Verzeichnis `zip2json4service/` 
# - Inklusive Unterverzeichnisse: config/, public/, src/
```

**Option B: ZIP-Download (einfacher für Nutzer)**

1. Laden Sie den Repository als ZIP herunter:
   - Klicken Sie auf **Code** → **Download ZIP** auf GitHub
2. Entpacken Sie die Datei auf Ihrem lokalen Rechner
3. Wechseln Sie in den Branch `refactor/modular-structure` (wenn nicht bereits enthalten)
4. Laden Sie **alle Dateien und Verzeichnisse** per FTP/SFTP auf Ihren Webspace hoch

**Wichtig**: Bewahren Sie die Verzeichnisstruktur bei! Der Proxy erwartet:
```
Ihr-Webspace/
├── config/
│   ├── config.php
│   └── dependencies.php
├── public/
│   └── index.php
└── src/
    └── GeoJsonProxy/
        ├── Application.php
        ├── Config.php
        └── ... (weitere Dateien)
```

### Schritt 2: Verzeichnisstruktur auf dem Server

Empfohlene Installation:

```
/var/www/html/
├── geojson-proxy/          # Hauptverzeichnis des Proxys
│   ├── config/
│   │   ├── config.php      # ⚠️ WICHTIG: Anpassen!
│   │   └── dependencies.php
│   ├── public/
│   │   └── index.php       # Öffentlicher Einstiegspunkt
│   ├── src/
│   │   └── GeoJsonProxy/
│   │       └── ...          # Anwendungslogik
│   └── cache/               # Wird automatisch angelegt
│       ├── data.geojson
│       ├── points.geojson
│       ├── meta.json
│       ├── proxy.log
│       └── status.json
└── index.html              # Ihre bestehende Website
```

**Alternative (flacher)**:

```
/var/www/html/
├── config/
├── public/
│   └── index.php           # Aufrufbar unter: https://ihre-domain.de/public/index.php
└── src/
```

### Schritt 3: Konfiguration anpassen

**Öffnen Sie `config/config.php` in einem Texteditor** und passen Sie die folgenden Einstellungen an:

```php
<?php
// === WICHTIG: Diese Werte müssen Sie anpassen ===

// URL der Quell-GeoJSON (kann gzip-komprimiert sein)
// Beispiel: Ihre Daten mit allen Features
const SOURCE_URL = 'https://ihre-datenquelle.de/daten.geojson.gz';

// URL der Puffer-GeoJSON mit Polygon/MultiPolygon-Geometrien
// Beispiel: Ihre Pufferregionen für die Filterung
const BUFFER_URL = 'https://ihre-datenquelle.de/puffer.geojson.gz';

// === Optional: Verkehrsmittel-Filter ===

// Name der Property, die die Verkehrsmitteltypen enthält
const TRANSPORT_MODE_PROPERTY = 'affected-transportmode-types';

// Erlaubte Werte (leeres Array = Filter deaktiviert)
// Beispiel: Nur Features mit "bus" oder "tram" behalten
const ALLOWED_TRANSPORT_MODE_TYPES = ['bus', 'tram'];
// Oder deaktivieren:
// const ALLOWED_TRANSPORT_MODE_TYPES = [];

// === Cache-Einstellungen ===

// Cache-Verzeichnis (relativ zum Skript oder absoluter Pfad)
const CACHE_DIR = __DIR__ . '/../cache';

// Lebensdauer des frischen Caches
const CACHE_TTL = '15m';  // 15 Minuten

// Lebensdauer des veralteten Caches (wird bei Fehlern ausgeliefert)
const STALE_TTL = '24h';  // 24 Stunden

// === Limits ===

// Maximale Größe für Downloads und Ergebnisse (in Bytes)
const MAX_BYTES = 32 * 1024 * 1024;  // 32 MB

// HTTP-Timeout in Sekunden
const HTTP_TIMEOUT = 60;

// === Logging (für Debugging) ===

// Detailliertes Logging aktivieren
const DEBUG_LOG_ENABLED = true;

// Logdatei-Name
const DEBUG_LOG_FILENAME = 'proxy.log';

// Status-Endpunkt aktivieren (für Diagnose)
const STATUS_ENDPOINT_ENABLED = true;
```

**Hinweise zur Konfiguration**:

- **SOURCE_URL**: Muss eine gültige GeoJSON FeatureCollection sein
- **BUFFER_URL**: Muss mindestens ein Polygon oder MultiPolygon enthalten
- **CACHE_DIR**: Muss beschreibbar für den PHP-Prozess sein
- **MAX_BYTES**: Erhöhen Sie dies, wenn Ihre Daten größer als 32 MB sind
- **DEBUG_LOG_ENABLED**: Auf `false` setzen, wenn alles funktioniert

### Schritt 4: Cache-Verzeichnis erstellen

Erstellen Sie das Cache-Verzeichnis manuell und setzen Sie die Berechtigungen:

```bash
# Per SSH auf dem Server
mkdir -p /var/www/html/geojson-proxy/cache
chown -R www-data:www-data /var/www/html/geojson-proxy/cache
chmod -R 755 /var/www/html/geojson-proxy/cache
```

**Berechtigungen prüfen**:

```bash
# Testen, ob PHP in das Verzeichnis schreiben kann
php -r "\$test = @fopen('/var/www/html/geojson-proxy/cache/test.txt', 'w'); \
echo (\$test ? 'OK: Schreibrechte vorhanden' : 'FEHLER: Keine Schreibrechte'); \
if (\$test) fclose(\$test);"
```

Falls keine SSH-Zugang: Erstellen Sie das Verzeichnis per FTP und setzen Sie die Berechtigungen auf **755** (rwxr-xr-x).

### Schritt 5: Erste Inbetriebnahme

#### Methode A: Über den Browser

1. Rufen Sie die Proxy-URL im Browser auf:
   ```
   https://ihre-domain.de/geojson-proxy/public/index.php
   ```
2. Der Proxy lädt automatisch die Daten und baut den Cache auf
3. Dies kann einige Sekunden dauern (abhängig von der Datengröße)

#### Methode B: Über die Kommandozeile (CLI)

```bash
# Auf dem Server per SSH
cd /var/www/html/geojson-proxy
php public/index.php --warm-cache
```

Dies erzwingt den Cache-Aufbau ohne Browser. Sie sehen die Ausgabe:
```
cache refreshed
etag: "abc123..."
bytes: 123456
point_etag: "def456..."
point_bytes: 78901
...
```

### Schritt 6: Testen

**Prüfen, ob der Proxy funktioniert**:

```bash
# Vollständige Geometrien
curl -I https://ihre-domain.de/geojson-proxy/public/index.php

# Punktdarstellung
curl -I https://ihre-domain.de/geojson-proxy/public/index.php?geometry=point

# Status-Endpunkt
curl https://ihre-domain.de/geojson-proxy/public/index.php?status=1
```

Erwartete Antwort:
- HTTP 200 für erfolgreiche Anfragen
- `Content-Type: application/geo+json; charset=utf-8`
- `X-Cache: HIT` (wenn Cache frisch ist) oder `X-Cache: MISS` (beim ersten Aufruf)

**Prüfen der Logdatei**:

```bash
# Per SSH
tail -f /var/www/html/geojson-proxy/cache/proxy.log
```

Oder laden Sie `cache/proxy.log` per FTP herunter.

---

## Konfiguration

### Alle Konfigurationsoptionen

| Konstante | Typ | Standard | Beschreibung |
|-----------|-----|----------|--------------|
| `VERSION` | string | '1.5.1' | Skriptversion |
| `SOURCE_URL` | string | - | **Pflicht**: URL der Quell-GeoJSON |
| `BUFFER_URL` | string | - | **Pflicht**: URL der Puffer-GeoJSON |
| `TRANSPORT_MODE_PROPERTY` | string | 'affected-transportmode-types' | Property-Name für Verkehrsmittel |
| `ALLOWED_TRANSPORT_MODE_TYPES` | array | [] | Erlaubte Verkehrsmitteltypen |
| `CACHE_DIR` | string | `config/../cache` | Cache-Verzeichnis |
| `CACHE_TTL` | string | '15m' | Frische Cache-Lebensdauer |
| `STALE_TTL` | string | '24h' | Veralteter Cache-Lebensdauer |
| `MAX_BYTES` | int | 32MB | Maximale Dateigröße |
| `HTTP_TIMEOUT` | int | 60 | HTTP-Timeout in Sekunden |
| `USER_AGENT` | string | 'umap-...' | HTTP User-Agent |
| `GEO_EPSILON` | float | 1e-12 | Numerische Toleranz |
| `DEBUG_LOG_ENABLED` | bool | true | Detailliertes Logging |
| `DEBUG_LOG_FILENAME` | string | 'proxy.log' | Logdatei-Name |
| `STATUS_FILENAME` | string | 'status.json' | Statusdatei-Name |
| `STATUS_ENDPOINT_ENABLED` | bool | true | Status-Endpunkt aktiv |
| `LOG_PROGRESS_EVERY` | int | 1000 | Log-Fortschritt alle N Features |

### Zeitangaben-Format

Unterstützte Formate für `CACHE_TTL` und `STALE_TTL`:

| Format | Beispiel | Bedeutung |
|--------|----------|-----------|
| Sekunden | `300` | 300 Sekunden |
| Minuten | `15m` | 15 Minuten |
| Stunden | `1h` | 1 Stunde |
| Tage | `24h` | 24 Stunden |
| Tage | `1d` | 1 Tag |

### Beispiel-Konfigurationen

**Minimale Konfiguration (nur Pflichtfelder)**:

```php
const SOURCE_URL = 'https://example.org/data.geojson';
const BUFFER_URL = 'https://example.org/buffer.geojson';
const CACHE_DIR = __DIR__ . '/../cache';
```

**Vollständige Konfiguration für Produktionsumgebung**:

```php
const SOURCE_URL = 'https://data.your-city.de/gtfs-rt/stop-times.geojson.gz';
const BUFFER_URL = 'https://data.your-city.de/boundaries/city-limits.geojson.gz';

const TRANSPORT_MODE_PROPERTY = 'affected-transportmode-types';
const ALLOWED_TRANSPORT_MODE_TYPES = ['bus', 'tram', 'train'];

const CACHE_DIR = __DIR__ . '/../cache';
const CACHE_TTL = '30m';    // 30 Minuten frischer Cache
const STALE_TTL = '48h';   // 48 Stunden veralteter Cache

const MAX_BYTES = 50 * 1024 * 1024;  // 50 MB
const HTTP_TIMEOUT = 120;             // 2 Minuten

const DEBUG_LOG_ENABLED = false;     // Im Produktion deaktivieren
const STATUS_ENDPOINT_ENABLED = true;
```

---

## Verwendung

### Web-API

| Endpunkt | Beschreibung | Beispiel |
|----------|--------------|----------|
| Standard | Vollständige gefilterte Geometrien | `https://ihre-domain.de/geojson-proxy/public/index.php` |
| Punktdarstellung | Schwerpunkte für Symbol-Layer | `https://ihre-domain.de/geojson-proxy/public/index.php?geometry=point` |
| Status | Diagnose-Informationen | `https://ihre-domain.de/geojson-proxy/public/index.php?status=1` |

**HTTP-Methoden**:
- `GET` - Daten abrufen
- `HEAD` - Nur Header abrufen
- `OPTIONS` - CORS-Vorabprüfung

### CLI-Befehle

```bash
# Hilfe anzeigen
php public/index.php --help

# Version anzeigen
php public/index.php --version

# Cache manuell aufbauen (ohne Browser)
php public/index.php --warm-cache
```

**Cronjob-Empfehlung** (automatischer Cache-Aufbau):

```bash
# Alle 15 Minuten Cache aktualisieren (wenn CACHE_TTL = 15m)
*/15 * * * * /usr/bin/php /pfad/zu/geojson-proxy/public/index.php --warm-cache >/dev/null 2>&1
```

### HTTP-Header

Der Proxy sendet folgende Header:

```
Access-Control-Allow-Origin: *
Content-Type: application/geo+json; charset=utf-8
ETag: "sha256-hash"
X-Cache: HIT | MISS | STALE
X-Geometry-Mode: full | point
X-Cache-Fetched-At: 2024-01-15T10:30:00+00:00
Age: 42
Cache-Control: public, max-age=900
```

---

## Entwicklung und Tests

Der Integrationstest benötigt Python 3, PHP CLI mit zlib-Unterstützung und die Möglichkeit, lokale Server auf `127.0.0.1` zu starten. Er erstellt eine temporäre Kopie des Projekts mit eigener Konfiguration und eigenen Cache-Verzeichnissen. Die Dateien und die Konfiguration im Arbeitsverzeichnis werden dabei nicht verändert.

Führen Sie den Test aus dem Projektverzeichnis aus:

```bash
# Vollständige Integrationssuite
python3 test_php_proxy.py .

# Zusätzlich die Ausgaben der gestarteten PHP-Entwicklungsserver anzeigen
python3 test_php_proxy.py . --show-php-log

# Bestimmte PHP-Installation verwenden
python3 test_php_proxy.py . --php-bin /pfad/zu/php
```

Der Test prüft unter anderem:

- die Syntax aller PHP-Dateien in der temporär konfigurierten Projektkopie;
- gzip-komprimierte und unkomprimierte Upstream-Daten, Redirects, Größenlimits und fehlerhafte Payloads;
- alle unterstützten GeoJSON-Geometrietypen sowie Löcher, Grenzberührungen und degenerierte Randfälle;
- vollständige und punktförmige Repräsentationen einschließlich Transportmodusfilter;
- Cache `MISS`, `HIT` und `STALE`, Cache-Key-Invalidierung, beschädigte Cache-Dateien und den Ablauf von `stale_until`;
- ETags, `304 Not Modified`, `HEAD`, `OPTIONS`, CORS, Status-Endpunkt und CLI-Befehle;
- konkurrierende Cold-Cache-Anfragen und die Verriegelung des gemeinsamen Refreshs.

Bei Erfolg endet die Ausgabe mit `All tests passed.`. Der Test verwendet ausschließlich temporäre Verzeichnisse und lokale Mock-Upstreams; ein Internetzugang ist nicht erforderlich.

---

## uMap-Integration

### Schritt 1: Layer in uMap erstellen

1. Melden Sie sich bei [uMap](https://umap.openstreetmap.fr/) an
2. Öffnen Sie Ihre Karte
3. Klicken Sie auf **"Datenlayer hinzufügen"**
4. Wählen Sie **"Externe Daten"**

### Schritt 2: Vollständige Geometrien einbinden

| Feld | Wert |
|------|-------|
| **Name** | z.B. "Baustellen - Vollständig" |
| **URL** | `https://ihre-domain.de/geojson-proxy/public/index.php` |
| **Format** | GeoJSON |
| **Farbe** | Wählen Sie eine Farbe |
| **Symbol** | Optional |

### Schritt 3: Punkt-Layer für Symbole einbinden

| Feld | Wert |
|------|-------|
| **Name** | z.B. "Baustellen - Symbole" |
| **URL** | `https://ihre-domain.de/geojson-proxy/public/index.php?geometry=point` |
| **Format** | GeoJSON |
| **Symbol** | Wählen Sie ein Symbol |

**Wichtig**: Beide Layer verwenden dieselben Feature-IDs und Properties, sodass Sie:
- Identische datenabhängige Stile verwenden können
- Bei Klick auf ein Symbol das entsprechende Feature in beiden Layern hervorheben können

### Schritt 4: Stile konfigurieren

Da beide Layer dieselben Feature-IDs und Properties haben, können Sie:

1. **Einheitliche Farben**: Beide Layer mit derselben Farbe darstellen
2. **Datenabhängige Stile**: In beiden Layern dieselben Regeln basierend auf Properties verwenden
3. **Popups**: In beiden Layern dieselben Popup-Inhalte anzeigen

**Beispiel für datenabhängigen Stil**:

```
Farbe basierend auf Property "status":
- status = "geplant" → Gelb
- status = "aktiv" → Rot
- status = "abgeschlossen" → Grün
```

Dies funktioniert identisch in beiden Layern!

---

## Betrieb und Wartung

### Cache-Verwaltung

**Cache manuell löschen**:

```bash
# Alle Cache-Dateien löschen
rm -f /var/www/html/geojson-proxy/cache/*
```

Der Cache wird automatisch neu aufgebaut beim nächsten Aufruf.

**Cache-Informationen prüfen**:

```bash
# Meta-Daten anzeigen
cat /var/www/html/geojson-proxy/cache/meta.json
```

Beispielausgabe:
```json
{
  "version": "1.5.1",
  "cache_key": "abc123...",
  "etag": "def456...",
  "point_etag": "ghi789...",
  "fetched_at": 1700000000,
  "expires_at": 1700009000,
  "stale_until": 1700944000,
  "bytes": 123456,
  "point_bytes": 78901
}
```

### Logging

**Logdatei anzeigen**:

```bash
# Letzte Einträge anzeigen
tail -50 /var/www/html/geojson-proxy/cache/proxy.log

# Live-Überwachung
tail -f /var/www/html/geojson-proxy/cache/proxy.log
```

**Logdatei-Format**: JSON-Lines (jede Zeile ist ein JSON-Objekt)

Beispiel:
```json
{"timestamp":"2024-01-15T10:30:00+00:00","level":"INFO","request_id":"20240115-12345-abc123","mode":"web","elapsed_seconds":1.234,"memory_mib":25.5,"peak_memory_mib":30.1,"message":"stage: refresh-complete","response_bytes":123456,"etag":"def456..."}
```

**Status-Endpunkt**:

```bash
curl https://ihre-domain.de/geojson-proxy/public/index.php?status=1 | jq .
```

Beispielausgabe:
```json
{
  "status_available": true,
  "current": {
    "stage": "serving-fresh-cache",
    "updated_at": "2024-01-15T10:30:00+00:00",
    "elapsed_seconds": 0.001,
    "memory_mib": 25.5,
    "peak_memory_mib": 30.1
  },
  "cache": {
    "data_exists": true,
    "meta_exists": true,
    "metadata": {
      "fetched_at": "2024-01-15T10:25:00+00:00",
      "expires_at": "2024-01-15T10:40:00+00:00",
      "stale_until": "2024-01-16T10:40:00+00:00",
      "bytes": 123456,
      "etag": "def456..."
    }
  },
  "log_file": "proxy.log"
}
```

### Performance-Optimierung

1. **Cache-TTL anpassen**:
   - Längere TTL = weniger häufige Neuberechnung
   - Kürzere TTL = aktuellere Daten
   - Empfehlung: `CACHE_TTL = '15m'` bis `CACHE_TTL = '1h'`

2. **Cronjob einrichten**:
   ```bash
   # Cache alle 15 Minuten aktualisieren (vor Ablauf)
   */14 * * * * /usr/bin/php /pfad/zu/public/index.php --warm-cache >/dev/null 2>&1
   ```

3. **Daten vereinfachen**:
   - Quell-GeoJSON vorab vereinfachen (z.B. mit `ogr2ogr` oder QGIS)
   - Unnötige Properties entfernen
   - Puffer-Polygone vereinfachen

4. **Speicherlimit erhöhen** (falls nötig):
   - In `.htaccess`: `php_value memory_limit 512M`
   - Oder in `php.ini`: `memory_limit = 512M`

### Sicherheitshinweise

1. **Cache-Verzeichnis schützen**:
   - Das Cache-Verzeichnis sollte **nicht** öffentlich zugänglich sein
   - Erstellen Sie eine `.htaccess`-Datei im Cache-Verzeichnis:
     ```apache
     Deny from all
     ```

2. **URLs schützen**:
   - Falls Ihre GeoJSON-URLs Authentifizierung erfordern (z.B. API-Tokens):
   - Speichern Sie die URLs in der Konfiguration (nicht in Git!)
   - Beschränken Sie den Zugriff auf die PHP-Dateien

3. **CORS**:
   - Der Proxy erlaubt CORS von allen Ursprüngen (`Access-Control-Allow-Origin: *`)
   - Dies ist für uMap notwendig, kann aber bei sensiblen Daten angepasst werden

4. **Logging**:
   - Die Logdatei kann URLs und Fehlermeldungen enthalten
   - Schützen Sie das Cache-Verzeichnis vor öffentlichem Zugriff

---

## Fehlerbehebung

### Häufige Probleme und Lösungen

| Problem | Ursache | Lösung |
|---------|---------|--------|
| **HTTP 500** | PHP-Fehler | `cache/proxy.log` prüfen |
| **HTTP 502** | Datenfehler | SOURCE_URL oder BUFFER_URL prüfen |
| **HTTP 404** | Falsche URL | Pfad zum Skript prüfen |
| **Leere Antwort** | Keine Treffer | Filter-Konfiguration prüfen |
| **Cache wird nicht aktualisiert** | Berechtigungen | Cache-Verzeichnis Berechtigungen prüfen |

### Detaillierte Fehleranalyse

**1. "cache directory is not writable"**

```bash
# Berechtigungen prüfen
ls -la /var/www/html/geojson-proxy/cache/

# Berechtigungen setzen
chown -R www-data:www-data /var/www/html/geojson-proxy/cache
chmod -R 755 /var/www/html/geojson-proxy/cache
```

**2. "PHP zlib support is required for gzip data"**

Die PHP-zlib-Erweiterung fehlt:

```bash
# Bei Debian/Ubuntu
sudo apt-get install php-zlib
sudo systemctl restart apache2

# Bei CentOS/RHEL
sudo yum install php-zlib
sudo systemctl restart httpd
```

**3. "neither curl nor allow_url_fopen is available"**

PHP kann keine entfernten URLs abrufen:

```bash
# cURL installieren
sudo apt-get install php-curl
sudo systemctl restart apache2

# Oder allow_url_fopen aktivieren
# In php.ini:
allow_url_fopen = On
```

**4. "response exceeds MAX_BYTES"**

Die Daten sind zu groß:

```php
// In config/config.php
const MAX_BYTES = 64 * 1024 * 1024;  // 64 MB
```

**5. "Allowed memory size ... exhausted"**

Speicherlimit erhöht:

```bash
# In .htaccess (wenn erlaubt)
php_value memory_limit 512M

# Oder in php.ini
memory_limit = 512M
```

**6. "buffer GeoJSON contains no Polygon or MultiPolygon geometry"**

Die Puffer-Datei enthält keine gültigen Polygone:
- Prüfen Sie die BUFFER_URL
- Prüfen Sie, dass die Datei Polygon- oder MultiPolygon-Geometrien enthält
- Testen Sie die URL im Browser

**7. Leere FeatureCollection**

Keine Features passieren den Filter:
- Prüfen Sie SOURCE_URL und BUFFER_URL
- Prüfen Sie die räumliche Abdeckung (overlappen die Daten?)
- Prüfen Sie den Attributfilter (ALLOWED_TRANSPORT_MODE_TYPES)

### Debugging mit dem Status-Endpunkt

```bash
# Status abrufen
curl https://ihre-domain.de/geojson-proxy/public/index.php?status=1
```

Mögliche Stufen:
- `request-start` - Anfrage begonnen
- `downloading-source` - Quelldaten werden geladen
- `source-decoded` - Quelldaten dekodiert
- `filtering-transport-modes` - Attributfilter läuft
- `downloading-buffer` - Pufferdaten werden geladen
- `spatial-filter-start` - Räumliche Filterung beginnt
- `spatial-filter-complete` - Räumliche Filterung abgeschlossen
- `encoding-full-result` - Ergebnis wird kodiert
- `writing-cache` - Cache wird geschrieben
- `serving-fresh-cache` - Frischer Cache wird ausgeliefert
- `waiting-for-refresh-lock` - Wartet auf Cache-Aktualisierung

---

## Struktur

```
.
├── config/
│   ├── config.php          # Konfigurationskonstanten
│   └── dependencies.php    # Autoloading-Konfiguration
├── public/
│   └── index.php           # Öffentlicher Einstiegspunkt
├── src/GeoJsonProxy/
│   ├── Application.php      # Hauptanwendung
│   ├── Config.php           # Konfiguration
│   ├── Cache/
│   │   └── Manager.php      # Cache-Verwaltung
│   ├── Diagnostics/
│   │   └── Logger.php       # Logging
│   ├── GeoJson/
│   │   ├── BoundingBox.php   # Bounding-Box-Utilities
│   │   ├── Centroid.php     # Schwerpunktberechnung
│   │   ├── FeatureFilter.php # Attributfilter
│   │   ├── GeometryExtractor.php # Geometrie-Extraktion
│   │   ├── Parser.php        # JSON-Parsing
│   │   ├── Point.php         # Punkt-Utilities
│   │   ├── PreparedPolygon.php # Kompakter Pufferpolygon-Index
│   │   ├── Segment.php       # Segment-Schnittprüfung
│   │   └── SpatialFilter.php # Räumliche Filterung
│   ├── Http/
│   │   ├── Fetcher.php      # HTTP-Client
│   │   └── GzipDetector.php # Gzip-Erkennung
│   └── SpatialIndex/
│       ├── GridIndex.php     # Raster-Index
│       └── PointIndex.php    # Punkt-Index
└── test_php_proxy.py         # Integrationssuite
```

---

## Features

- ✅ **Gzip-Unterstützung**: Automatische Erkennung und Dekomprimierung
- ✅ **Attributfilter**: Filterung nach Verkehrsmitteltypen (optional)
- ✅ **Räumliche Filterung**: Präzise geometrische Schnittprüfungen
- ✅ **Caching**: Intelligentes Caching mit TTL und Stale-Cache
- ✅ **Punktdarstellung**: Automatische Schwerpunktberechnung
- ✅ **CORS**: Vollständige CORS-Unterstützung für uMap
- ✅ **ETag-Caching**: HTTP-Caching mit ETags
- ✅ **Status-Endpunkt**: Diagnose-Informationen in Echtzeit
- ✅ **Detailliertes Logging**: JSON-Lines-Format für einfache Analyse
- ✅ **CLI-Unterstützung**: Cache-Warm-up per Kommandozeile

---

## Hinweise

### Unterstützte GeoJSON-Typen

**Quelldatensatz** (SOURCE_URL):
- ✅ FeatureCollection (erforderlich)
- ✅ Point, MultiPoint
- ✅ LineString, MultiLineString
- ✅ Polygon, MultiPolygon
- ✅ GeometryCollection

**Pufferdatensatz** (BUFFER_URL):
- ✅ Polygon
- ✅ MultiPolygon
- ✅ Feature mit Polygon/MultiPolygon
- ✅ FeatureCollection mit Polygon/MultiPolygon
- ✅ GeometryCollection mit Polygon/MultiPolygon

### Koordinatensystem

- Beide Datensätze müssen dasselbe Koordinatensystem verwenden
- Typischerweise WGS84 mit [Längengrad, Breitengrad]
- Keine automatische Transformation

### Punktdarstellung

Die Punktkoordinate wird berechnet als:
- **Point**: Unveränderte Koordinate
- **MultiPoint**: Arithmetischer Mittelwert
- **LineString/MultiLineString**: Längengewichteter Linienschwerpunkt
- **Polygon/MultiPolygon**: Flächenschwerpunkt (Innenringe werden abgezogen)
- **GeometryCollection**: Schwerpunkt der höchsten Dimension

**Hinweis**: Bei stark konkaven Polygonen oder Polygonen mit Löchern liegt der Schwerpunkt nicht zwingend innerhalb der sichtbaren Fläche.

---

## Support

Bei Fragen oder Problemen:

1. **Logdatei prüfen**: `cache/proxy.log`
2. **Status prüfen**: `?status=1` Endpunkt
3. **Konfiguration prüfen**: `config/config.php`
4. **PHP-Voraussetzungen prüfen**: `phpinfo()`

---

*Version: 1.5.1* | *Branch: refactor/modular-structure* | *Lizenz: MIT*
