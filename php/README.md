# uMap GeoJSON Spatial Filter Proxy

Ein einzelnes PHP-Skript für Webspaces ohne PostGIS, GEOS, GDAL oder andere installierbare GIS-Werkzeuge.

Das Skript lädt zwei entfernte GeoJSON-Datensätze:

1. einen Quelldatensatz mit allen Features und
2. einen Pufferdatensatz mit vorberechneten `Polygon`- oder `MultiPolygon`-Geometrien.

Aus dem Quelldatensatz bleiben nur jene Features erhalten, die

- optional zu mindestens einem erlaubten Verkehrsmitteltyp passen und
- mindestens eine Pufferregion berühren oder überschneiden.

Aus demselben gefilterten Ergebnis werden zwei GeoJSON-Ausgaben erzeugt:

- die vollständigen Originalgeometrien und
- repräsentative Punkte für Marker oder Symbole auf einer Karte.

Beide Ausgaben entstehen gemeinsam bei einem einzigen Cache-Refresh. Der Punkt-Layer löst daher keine zweite räumliche Filterung aus.

## Dateien

```text
geojson-proxy.php       PHP-Proxy und Filter
test_geojson_proxy.py  Integrationstest
README.md               diese Dokumentation
```

## Datenfluss

```text
SOURCE_URL ─┐
            ├─ herunterladen und gegebenenfalls gzip entpacken
BUFFER_URL ─┘
                         ↓
             GeoJSON dekodieren und prüfen
                         ↓
             Pufferpolygone vorbereiten
                         ↓
       Rasterindex + Y-Bucket-Index aufbauen
                         ↓
       optional nach Transportmodus filtern
                         ↓
              räumlich überschneiden
                         ↓
              gefilterte Features
                  ↙             ↘
     Originalgeometrien     repräsentative Punkte
      data.geojson           points.geojson
                  ↘             ↙
                     meta.json
```

## Voraussetzungen

Für den Betrieb:

- PHP 8.0 oder neuer
- PHP-zlib mit `gzdecode()`
- PHP-cURL oder aktiviertes `allow_url_fopen`
- Schreibrechte für das Cache-Verzeichnis
- ausgehender HTTP- oder HTTPS-Zugriff auf beide URLs

Für die Tests zusätzlich:

- Python 3.10 oder neuer
- PHP als Kommandozeilenprogramm
- der eingebaute PHP-Webserver, der bei üblichen PHP-Installationen enthalten ist

Bei einem etwa 6 MiB großen entpackten Quelldatensatz ist der Ansatz normalerweise gut handhabbar. Der tatsächliche Speicherbedarf hängt vor allem von der Anzahl und Komplexität der Koordinaten ab, weil PHP-Arrays deutlich mehr Speicher als der JSON-Text benötigen.

## Installation

1. `geojson-proxy.php` auf den Webspace kopieren.
2. Die Konstanten am Anfang der Datei anpassen.
3. Sicherstellen, dass PHP `CACHE_DIR` anlegen oder beschreiben darf.
4. Das Skript einmal im Browser oder per `--warm-cache` aufrufen.
5. Die gewünschte Ausgabe-URL in uMap als externen GeoJSON-Layer eintragen.

Beispielstruktur:

```text
public_html/
├── geojson-proxy.php
└── cache/
    ├── data.geojson
    ├── points.geojson
    ├── meta.json
    ├── proxy.log
    ├── refresh.lock
    └── status.json
```

Das Cache-Verzeichnis wird automatisch erzeugt, sofern das übergeordnete Verzeichnis beschreibbar ist.

## Konfiguration

Alle Einstellungen befinden sich am Anfang von `geojson-proxy.php`.

### Quellen und Attributfilter

```php
const VERSION = '1.4.0';

const SOURCE_URL = 'https://example.org/source.geojson.gz';
const BUFFER_URL = 'https://example.org/buffer.geojson.gz';

const TRANSPORT_MODE_PROPERTY = 'affected-transportmode-types';
const ALLOWED_TRANSPORT_MODE_TYPES = ['bus', 'tram', 'train'];
```

| Konstante | Bedeutung |
|---|---|
| `VERSION` | Skriptversion und Bestandteil der Cache-Identität. |
| `SOURCE_URL` | GeoJSON-`FeatureCollection`, aus der Features gefiltert werden. |
| `BUFFER_URL` | GeoJSON-Dokument mit einem oder mehreren `Polygon`- oder `MultiPolygon`-Puffern. |
| `TRANSPORT_MODE_PROPERTY` | Name der Property unter `feature.properties`. |
| `ALLOWED_TRANSPORT_MODE_TYPES` | Erlaubte Werte. Mindestens ein Wert muss vorkommen. Ein leeres Array deaktiviert den Attributfilter. |

Der Vergleich der Verkehrsmitteltypen ist exakt und case-sensitive. `bus` und `BUS` sind daher unterschiedliche Werte.

Erwartete Struktur:

```json
{
  "type": "Feature",
  "properties": {
    "affected-transportmode-types": ["bus", "tram"]
  },
  "geometry": {
    "type": "LineString",
    "coordinates": [[14.28, 48.30], [14.29, 48.31]]
  }
}
```

Die Semantik ist **ODER**. Bei der Konfiguration `['bus', 'train']` genügt ein einzelner Treffer.

Die Property wird ausdrücklich hier gelesen:

```php
$feature['properties'][TRANSPORT_MODE_PROPERTY]
```

Fehlt die Property oder enthält sie bei aktiviertem Filter keinen passenden String, wird das Feature verworfen. Neben einer Liste wird aus Robustheitsgründen auch ein einzelner String akzeptiert.

### Cache und HTTP

```php
const CACHE_DIR = __DIR__ . '/cache';
const CACHE_TTL = '15m';
const STALE_TTL = '24h';

const MAX_BYTES = 32 * 1024 * 1024;
const HTTP_TIMEOUT = 60;
const USER_AGENT = 'umap-geojson-spatial-filter/' . VERSION;
```

| Konstante | Bedeutung |
|---|---|
| `CACHE_DIR` | Beschreibbares lokales Cache-Verzeichnis. |
| `CACHE_TTL` | Zeitraum, in dem beide Ausgaben als frisch gelten. |
| `STALE_TTL` | Zusätzlicher Zeitraum, in dem ein alter Cache bei Refresh-Fehlern ausgeliefert werden darf. |
| `MAX_BYTES` | Höchstgröße jedes Downloads, jedes entpackten Dokuments und jeder einzelnen Ausgabe. |
| `HTTP_TIMEOUT` | Zeitlimit je Upstream-Abruf in Sekunden. |
| `USER_AGENT` | User-Agent für beide Gegenstellen. |

Unterstützte Zeitangaben:

```text
30s
5m
1h
24h
1d
```

Eine reine Ganzzahl wird als Sekundenanzahl interpretiert.

### Punktausgabe

```php
const OUTPUT_QUERY_PARAMETER = 'output';
const POINT_OUTPUT_MODE = 'points';
const POINT_OUTPUT_ORIGINAL_GEOMETRY_PROPERTY = '_original_geometry_type';
```

| Konstante | Bedeutung |
|---|---|
| `OUTPUT_QUERY_PARAMETER` | HTTP-Parameter zur Auswahl der Darstellung. |
| `POINT_OUTPUT_MODE` | Parameterwert für die Punktausgabe. |
| `POINT_OUTPUT_ORIGINAL_GEOMETRY_PROPERTY` | Optionale zusätzliche Property mit dem ursprünglichen Geometrietyp. Ein leerer String deaktiviert sie. |

Existiert die konfigurierte Property bereits im Feature, wird ihr vorhandener Wert nicht überschrieben.

### Logging und Status

```php
const DEBUG_LOG_ENABLED = true;
const DEBUG_LOG_FILENAME = 'proxy.log';
const STATUS_FILENAME = 'status.json';
const STATUS_ENDPOINT_ENABLED = true;
const LOG_PROGRESS_EVERY = 1000;
```

| Konstante | Bedeutung |
|---|---|
| `DEBUG_LOG_ENABLED` | Aktiviert das JSON-Lines-Log unter `cache/proxy.log`. |
| `DEBUG_LOG_FILENAME` | Name der Logdatei. |
| `STATUS_FILENAME` | Datei mit dem zuletzt bekannten Verarbeitungsstatus. |
| `STATUS_ENDPOINT_ENABLED` | Aktiviert den HTTP-Statusendpunkt. |
| `LOG_PROGRESS_EVERY` | Fortschrittsmeldung nach so vielen Quellfeatures; `0` deaktiviert sie. |

### Räumliche Indizes

```php
const SPATIAL_INDEX_TARGET_SEGMENTS_PER_CELL = 12;
const SPATIAL_INDEX_MAX_TOTAL_CELLS = 65536;
const SPATIAL_INDEX_MAX_GRID_DIMENSION = 256;
const SPATIAL_INDEX_MAX_CELLS_PER_SEGMENT = 1024;

const POINT_INDEX_TARGET_EDGES_PER_BUCKET = 24;
const POINT_INDEX_MAX_BUCKETS = 512;
const POINT_INDEX_MAX_BUCKETS_PER_EDGE = 128;
```

Der Rasterindex ordnet Puffersegmente räumlichen Zellen zu. Ein Quellsegment wird dadurch nur mit Segmenten aus überlappten Zellen verglichen.

Der Y-Bucket-Index beschleunigt Punkt-in-Polygon-Prüfungen. Für einen horizontalen Teststrahl werden nur jene Polygonkanten betrachtet, deren Y-Ausdehnung zur Y-Koordinate des Punktes passt.

Die Standardwerte sollten zunächst unverändert bleiben. Änderungen sind nur anhand realer Laufzeit- und Speicherwerte sinnvoll.

`GEO_EPSILON` ist lediglich eine sehr kleine numerische Toleranz für Float-Vergleiche und kein zusätzlicher räumlicher Puffer.

## HTTP-Ausgaben

### Vollständige Geometrien

Ohne Parameter:

```text
https://example.org/geojson-proxy.php
```

oder explizit:

```text
https://example.org/geojson-proxy.php?output=full
```

Diese Ausgabe enthält die gefilterten Features mit ihren ursprünglichen Geometrien.

### Repräsentative Punkte

```text
https://example.org/geojson-proxy.php?output=points
```

Diese Ausgabe enthält dieselben gefilterten Feature-IDs und Properties, aber jede Geometrie ist ein GeoJSON-`Point`.

Ein unbekannter Wert liefert HTTP 400:

```text
https://example.org/geojson-proxy.php?output=centroids
```

### Response-Header

Beide Ausgaben liefern unter anderem:

```text
Content-Type: application/geo+json; charset=utf-8
ETag: "..."
X-Cache: HIT | MISS | STALE
X-Output-Mode: full | points
X-Cache-Fetched-At: ...
Age: ...
```

Die vollständige und die punktförmige Ausgabe besitzen getrennte ETags. Ein ETag der vollständigen Ausgabe validiert daher nicht die Punktausgabe und umgekehrt.

`GET`, `HEAD` und `OPTIONS` werden unterstützt. Andere HTTP-Methoden liefern HTTP 405.

## Regeln für repräsentative Punkte

Die Punktausgabe verwendet nicht für alle Geometrietypen blind den mathematischen Schwerpunkt. Das wäre bei Linien, konkaven Polygonen oder Polygonen mit Löchern oft ungeeignet.

| ursprüngliche Geometrie | erzeugter Punkt |
|---|---|
| `Point` | unveränderte Koordinate |
| `MultiPoint` | arithmetisches Mittel aller gültigen Punkte |
| `LineString` | Mittelpunkt bei 50 % der Linienlänge |
| `MultiLineString` | Längenmittelpunkt der längsten Teillinie |
| `Polygon` | Flächenschwerpunkt, sofern er im Polygon liegt; andernfalls ein innerer Scanline-Punkt |
| `MultiPolygon` | repräsentativer Punkt des flächenmäßig größten Teilpolygons |
| `GeometryCollection` | repräsentativer Punkt des Elements mit der höchsten Dimension; bei Gleichstand des größten Elements |

Die Linienlängen werden mit einer equirektangularen Meter-Näherung bestimmt. Das ist für übliche lokale Kartendaten deutlich geeigneter als eine reine Distanzberechnung in Grad.

Bei Polygonen werden Innenringe von der Fläche abgezogen. Liegt der Schwerpunkt außerhalb eines konkaven Polygons oder in einem Loch, sucht das Skript auf horizontalen Scanlines einen Punkt, der tatsächlich im Polygon liegt.

Beispiel einer Punktausgabe:

```json
{
  "type": "Feature",
  "id": "123",
  "properties": {
    "name": "Baustelle",
    "affected-transportmode-types": ["bus", "tram"],
    "_original_geometry_type": "LineString"
  },
  "geometry": {
    "type": "Point",
    "coordinates": [14.285, 48.305]
  }
}
```

Features, für die aus einer ungültigen oder leeren Geometrie kein Punkt abgeleitet werden kann, werden nur aus `points.geojson` ausgelassen. Die vollständige Ausgabe bleibt davon unberührt. Bei gültigen unterstützten GeoJSON-Geometrien sollte dies normalerweise nicht vorkommen.

## Verwendung in uMap

Die zwei URLs können als zwei getrennte Layer verwendet werden.

### Layer mit vollständigen Geometrien

```text
URL:    https://example.org/geojson-proxy.php
Format: GeoJSON
```

### Layer mit Symbolen oder Markern

```text
URL:    https://example.org/geojson-proxy.php?output=points
Format: GeoJSON
```

Beide Layer basieren auf demselben Filterdurchlauf und demselben Aktualisierungszeitpunkt.

Je nach gewünschter Darstellung kann in uMap etwa

- der Geometrie-Layer für Linien und Flächen und
- der Punkt-Layer für Icons, Labels oder Popups

verwendet werden.

## Cache-Verhalten

Beim ersten Aufruf oder nach Ablauf von `CACHE_TTL`:

1. Lockdatei öffnen und exklusiven Lock erwerben.
2. Quelldatensatz herunterladen und entpacken.
3. Pufferdatensatz herunterladen und entpacken.
4. Attribut- und Geometriefilter ausführen.
5. vollständige Ausgabe kodieren.
6. Punktausgabe erzeugen und kodieren.
7. beide Dateien und gemeinsame Metadaten speichern.

Während der TTL werden nur lokale Cachedateien gelesen.

```text
cache/data.geojson    vollständige Geometrien
cache/points.geojson  repräsentative Punkte
cache/meta.json       gemeinsame Zeitstempel, ETags und Dateigrößen
```

`meta.json` enthält sinngemäß:

```json
{
  "version": "1.4.0",
  "cache_key": "...",
  "fetched_at": 1783950000,
  "expires_at": 1783950900,
  "stale_until": 1784037300,
  "outputs": {
    "full": {
      "filename": "data.geojson",
      "etag": "\"...\"",
      "bytes": 5234123
    },
    "points": {
      "filename": "points.geojson",
      "etag": "\"...\"",
      "bytes": 743210
    }
  }
}
```

Der Cache-Schlüssel berücksichtigt:

- Skriptversion
- Quell-URL
- Puffer-URL
- Propertyname des Transportmodus
- normalisierte Allow-List
- konfigurierte Property für den ursprünglichen Geometrietyp

Änderungen dieser Werte erzwingen damit automatisch einen Neuaufbau.

Bei einem Refresh-Fehler wird, sofern vorhanden, die jeweils angeforderte alte Darstellung bis `STALE_TTL` ausgeliefert. Die Antwort enthält dann:

```text
X-Cache: STALE
Warning: 110 - "Response is stale because source refresh failed"
```

## CLI

Hilfe:

```bash
php geojson-proxy.php --help
```

Version:

```bash
php geojson-proxy.php --version
```

Beide Cacheausgaben manuell neu aufbauen:

```bash
php geojson-proxy.php --warm-cache
```

Beispielausgabe:

```text
cache refreshed
full_etag: "..."
full_bytes: 5234123
points_etag: "..."
points_bytes: 743210
expires_at: ...
stale_until: ...
peak_memory_mib: ...
```

Für produktive Installationen kann `--warm-cache` regelmäßig über einen Cronjob ausgeführt werden. Die Webanfragen müssen dann normalerweise nur die fertigen Dateien ausliefern.

## Logging und Diagnose

Fortlaufendes Log:

```text
cache/proxy.log
```

Live ansehen:

```bash
tail -f cache/proxy.log
```

Aktueller Verarbeitungsstand:

```text
cache/status.json
```

Status im Browser:

```text
https://example.org/geojson-proxy.php?status=1
```

Der Statusendpunkt berichtet unter anderem:

- aktuelle Verarbeitungsstufe
- Zahl verarbeiteter und beibehaltener Features
- Speicherverbrauch
- Vorhandensein beider Cachedateien
- Metadaten und ETags beider Ausgaben

Typische Stufen:

```text
request-start
waiting-for-refresh-lock
refresh-lock-acquired
downloading-source
source-decoded
downloading-buffer
buffer-decoded
preparing-buffer-polygons
filtering
spatial-filter-complete
creating-point-representation
encoding-results
writing-cache
refresh-complete
```

Da Status und Log interne URLs, Pfade oder Fehlermeldungen enthalten können, sollten sie bei öffentlich erreichbaren Installationen geschützt oder nach der Inbetriebnahme deaktiviert werden.

## Tests

Ausführen:

```bash
python3 test_geojson_proxy.py ./geojson-proxy.php
```

Optional mit Ausgabe des PHP-Entwicklungsserver-Logs:

```bash
python3 test_geojson_proxy.py ./geojson-proxy.php --show-php-log
```

Der Test erzeugt eine temporäre Kopie der PHP-Datei und ersetzt darin nur die Konfigurationskonstanten. Die Originaldatei wird nicht verändert.

Die Tests prüfen unter anderem:

1. PHP-Syntax
2. gzip-Abruf beider Quellen
3. räumliche Filterung aller unterstützten Geometrietypen
4. Polygonlöcher und Randberührungen
5. Attributfilter mit ODER-Semantik
6. gleichzeitige Erzeugung von `data.geojson` und `points.geojson`
7. Punktrepräsentationen von `Point`, `MultiPoint`, `LineString`, `MultiLineString`, `Polygon`, `MultiPolygon` und `GeometryCollection`
8. innere Punkte für konkave Polygone und Polygone mit Schwerpunkt im Loch
9. getrennte ETags und `304 Not Modified`
10. `HEAD`, `OPTIONS`, HTTP 400 und HTTP 405
11. `MISS`, `HIT` und `STALE`
12. Cache-Refresh nach TTL-Ablauf
13. Stale-Cache bei Ausfall jeder der beiden Quellen
14. `--warm-cache`
15. Statusendpunkt
16. Cache-Invalidierung bei geänderter Transportmodus-Allow-List

Ein erfolgreicher Lauf endet mit:

```text
All tests passed.
```

## Anforderungen und Grenzen

- Der Quelldatensatz muss eine GeoJSON-`FeatureCollection` sein.
- Der Pufferdatensatz darf eine Geometrie, ein Feature, eine `FeatureCollection` oder eine `GeometryCollection` enthalten, muss aber mindestens ein gültiges `Polygon` oder `MultiPolygon` enthalten.
- Beide Datensätze müssen dasselbe Koordinatenreferenzsystem verwenden.
- Bei normalem GeoJSON ist die Koordinatenreihenfolge `[Longitude, Latitude]`.
- Datumsgrenze und Polregionen werden nicht speziell behandelt.
- Ungültige oder selbstüberschneidende Polygone werden nicht repariert.
- Die reine PHP-Geometrie ist nicht so robust wie GEOS oder JTS.
- Die Polygon-Schwerpunktberechnung erfolgt planar im Koordinatensystem des GeoJSON und nicht als geodätischer Flächenschwerpunkt auf dem Ellipsoid.
- `MultiPoint`-Mittelwerte und Repräsentationspunkte von Multi-Geometrien können außerhalb jener Teilgeometrie liegen, die den räumlichen Filter ausgelöst hat. Sie repräsentieren das gesamte Feature.
- Die sichtbare Linienbreite in uMap ist keine geometrische Breite. Eine `LineString` wird nur dann räumlich behalten, wenn ihre eigentliche Linie den Puffer berührt oder schneidet.

Für rechtlich relevante Geometrieoperationen, sehr große Datensätze oder topologisch problematische Daten sollte eine etablierte GIS-Engine wie GEOS, JTS oder PostGIS verwendet werden.

## Sicherheit

Die beiden URLs sind fest im Skript konfiguriert. Dadurch ist der Dienst kein frei verwendbarer Open Proxy.

Zusätzlich sinnvoll:

- Cache-Verzeichnis nicht direkt öffentlich ausliefern
- Statusendpunkt nach der Diagnose deaktivieren oder schützen
- Logdatei nicht öffentlich zugänglich machen
- Schreibrechte auf das notwendige Verzeichnis beschränken
- `MAX_BYTES` und `HTTP_TIMEOUT` nicht unnötig hoch setzen
- nur vertrauenswürdige Quell-URLs konfigurieren
