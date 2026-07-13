# uMap GeoJSON Spatial Filter Proxy

Ein einzelnes PHP-Skript, das zwei entfernte GeoJSON-Datensätze verarbeitet:

1. einen Quelldatensatz mit allen GeoJSON-Features und
2. einen Pufferdatensatz mit bereits vorberechneten `Polygon`- oder `MultiPolygon`-Geometrien.

Das Skript behält nur jene Features des Quelldatensatzes, die mindestens eine Pufferregion räumlich berühren oder überschneiden. Das fertig gefilterte Ergebnis wird auf dem Webspace in zwei Varianten zwischengespeichert: mit den vollständigen Originalgeometrien und als Punktdatensatz mit einem repräsentativen Schwerpunkt je Feature. Beide Varianten werden als unkomprimiertes GeoJSON an uMap oder einen anderen Client ausgeliefert.

Die räumliche Filterung läuft vollständig in PHP. Es werden weder eine Datenbank noch GEOS, GDAL oder andere externe GIS-Werkzeuge benötigt.

Die optimierte Version verwendet zwei räumliche Indizes:

- einen gleichmäßigen Rasterindex für die Kanten der Pufferpolygone und
- einen Y-Bucket-Index für Punkt-in-Polygon-Prüfungen.

Dadurch müssen Quellsegmente nicht mehr mit sämtlichen Puffersegmenten verglichen werden.

## Dateien

```text
geojson-proxy.php            PHP-Proxy, Attribut- und räumlicher Filter
test_php_proxy_v14.py         Integrationstest
README.md                     diese Dokumentation
```

Die PHP-Datei kann für den produktiven Einsatz auch in `geojson-proxy.php` umbenannt werden.

## Funktionsweise

```text
SOURCE_URL (GeoJSON oder gzip) ─┐
                                ├─ herunterladen und gegebenenfalls entpacken
BUFFER_URL (GeoJSON oder gzip) ─┘
                                            ↓
                              Puffergeometrien vorbereiten
                                            ↓
                         Raster- und Punktindizes aufbauen
                                            ↓
                         Quell-Features nach Transportmodus filtern
                                            ↓
                              verbleibende Features räumlich filtern
                                            ↓
                    ┌───────────────────────┴───────────────────────┐
                    ↓                                               ↓
          vollständige Geometrien                         Schwerpunkt-Punkte
          cache/data.geojson                             cache/points.geojson
                    └───────────────────────┬───────────────────────┘
                                            ↓
                                      cache/meta.json
                                            ↓
                                       uMap-Client
```

Die aufwendige Verarbeitung findet nur beim Neuaufbau des Caches statt. Dabei werden beide GeoJSON-Varianten gemeinsam erzeugt. Während der konfigurierten Cache-Laufzeit werden `data.geojson` oder `points.geojson` direkt ausgeliefert, ohne die Upstream-Daten erneut abzurufen oder die Punktgeometrien bei jedem Request neu zu berechnen.

## Voraussetzungen

Für den Betrieb:

- PHP 8.0 oder neuer
- PHP-zlib mit `gzdecode()` für gzip-Dateien
- entweder PHP-cURL oder aktiviertes `allow_url_fopen`
- Schreibrechte für das Cache-Verzeichnis
- ausgehender HTTP- oder HTTPS-Zugriff auf beide Quelldateien

Für die Tests zusätzlich:

- Python 3.10 oder neuer
- das PHP-Kommandozeilenprogramm einschließlich des eingebauten PHP-Webservers

Bei einem etwa 6 MiB großen **entpackten** Quelldatensatz sollte ein üblicher Webspace grundsätzlich ausreichen. Der tatsächliche Speicherverbrauch hängt stärker von der Anzahl und Komplexität der Features und Koordinaten als von der reinen Dateigröße ab, da PHP-Arrays deutlich mehr Speicher als der JSON-Text benötigen.

## Installation

1. `geojson-proxy-transport-filter.php` auf den Webspace kopieren.
2. Die Konstanten am Anfang der Datei anpassen.
3. Sicherstellen, dass PHP das konfigurierte Cache-Verzeichnis anlegen oder beschreiben darf.
4. Das Skript einmal im Browser oder mit `--warm-cache` aufrufen.
5. Die URL des PHP-Skripts in uMap als externen GeoJSON-Layer eintragen.

Eine mögliche Verzeichnisstruktur:

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

Alle veränderbaren Einstellungen befinden sich am Anfang der PHP-Datei.

### Quellen und Cache

```php
const VERSION = '1.3.0';

const SOURCE_URL = 'https://example.org/source.geojson.gz';
const BUFFER_URL = 'https://example.org/buffer.geojson.gz';

const TRANSPORT_MODE_PROPERTY = 'affected-transportmode-types';
const ALLOWED_TRANSPORT_MODE_TYPES = ['bus', 'tram', 'train'];

const CACHE_DIR = __DIR__ . '/cache';
const CACHE_TTL = '15m';
const STALE_TTL = '24h';

const MAX_BYTES = 32 * 1024 * 1024;
const HTTP_TIMEOUT = 60;
const USER_AGENT = 'umap-geojson-spatial-filter/' . VERSION;
```

| Konstante | Bedeutung |
|---|---|
| `VERSION` | Skriptversion und Bestandteil der Cache-Identität. |
| `SOURCE_URL` | URL der GeoJSON-`FeatureCollection`, aus der Features gefiltert werden. |
| `BUFFER_URL` | URL eines GeoJSON-Dokuments mit einem oder mehreren `Polygon`- beziehungsweise `MultiPolygon`-Puffern. |
| `TRANSPORT_MODE_PROPERTY` | Name der Feature-Property, welche die Liste der Verkehrsmitteltypen enthält. |
| `ALLOWED_TRANSPORT_MODE_TYPES` | Erlaubte Werte. Mindestens ein Wert muss im Feature vorkommen. Ein leeres Array deaktiviert den Attributfilter. Der Vergleich ist exakt und case-sensitive. |
| `CACHE_DIR` | Lokales, durch PHP beschreibbares Cache-Verzeichnis. |
| `CACHE_TTL` | Zeitraum, in dem das gefilterte Ergebnis als frisch gilt. |
| `STALE_TTL` | Zusätzlicher Zeitraum, in dem ein abgelaufener Cache bei Refresh-Fehlern ausgeliefert werden darf. |
| `MAX_BYTES` | Höchstgröße je heruntergeladenem Dokument, je entpacktem Dokument und des gefilterten Ergebnisses. |
| `HTTP_TIMEOUT` | Zeitlimit pro Upstream-Abruf in Sekunden. |
| `USER_AGENT` | HTTP-User-Agent für beide Gegenstellen. |
| `GEO_EPSILON` | Numerische Toleranz der Geometrieprüfungen. Normalerweise nicht ändern. |

Unterstützte Zeitangaben:

```text
30s
5m
1h
24h
1d
```

Eine reine Ganzzahl wird als Anzahl Sekunden interpretiert.

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
| `DEBUG_LOG_ENABLED` | Schreibt detaillierte JSON-Lines-Einträge nach `cache/proxy.log`. |
| `DEBUG_LOG_FILENAME` | Name der Logdatei im Cache-Verzeichnis. |
| `STATUS_FILENAME` | Name der Datei mit dem jeweils letzten Verarbeitungsstatus. |
| `STATUS_ENDPOINT_ENABLED` | Aktiviert den Diagnose-Endpunkt `?status=1`. |
| `LOG_PROGRESS_EVERY` | Fortschrittsmeldung nach jeweils so vielen Quell-Features; `0` deaktiviert Fortschrittsmeldungen. |

Für 1512 Features ist beispielsweise ein Wert von `100` sinnvoll, wenn während der Fehlersuche häufigere Statusmeldungen gewünscht sind:

```php
const LOG_PROGRESS_EVERY = 100;
```

Im stabilen Betrieb kann ein höherer Wert verwendet oder das Logging abgeschaltet werden.

### Räumliche Indexe

```php
const SPATIAL_INDEX_TARGET_SEGMENTS_PER_CELL = 12;
const SPATIAL_INDEX_MAX_TOTAL_CELLS = 65536;
const SPATIAL_INDEX_MAX_GRID_DIMENSION = 256;
const SPATIAL_INDEX_MAX_CELLS_PER_SEGMENT = 1024;

const POINT_INDEX_TARGET_EDGES_PER_BUCKET = 24;
const POINT_INDEX_MAX_BUCKETS = 512;
const POINT_INDEX_MAX_BUCKETS_PER_EDGE = 128;
```

Diese Werte sind Schutz- und Tuningparameter der beiden Indizes:

| Konstante | Bedeutung |
|---|---|
| `SPATIAL_INDEX_TARGET_SEGMENTS_PER_CELL` | Angestrebte durchschnittliche Anzahl Puffersegmente pro Rasterzelle. Kleinere Werte erzeugen mehr Zellen und weniger Kandidaten pro Suche. |
| `SPATIAL_INDEX_MAX_TOTAL_CELLS` | Obergrenze für die theoretische Gesamtzahl der Rasterzellen. |
| `SPATIAL_INDEX_MAX_GRID_DIMENSION` | Maximale Anzahl Spalten beziehungsweise Zeilen des Rasters. |
| `SPATIAL_INDEX_MAX_CELLS_PER_SEGMENT` | Sehr lange Puffersegmente werden nicht in unbegrenzt viele Zellen eingetragen. |
| `POINT_INDEX_TARGET_EDGES_PER_BUCKET` | Angestrebte Anzahl Polygonkanten pro Y-Bucket. |
| `POINT_INDEX_MAX_BUCKETS` | Maximale Zahl der Y-Buckets pro Polygonring. |
| `POINT_INDEX_MAX_BUCKETS_PER_EDGE` | Obergrenze für die Zahl der Buckets, in die eine einzelne Kante eingetragen wird. |

Die Standardwerte sollten zunächst unverändert bleiben. Eine Änderung ist nur sinnvoll, wenn reale Laufzeit- und Speicherwerte aus `proxy.log` vorliegen.

## Anforderungen an die GeoJSON-Daten

### Quelldatensatz

Der Quelldatensatz muss eine GeoJSON-`FeatureCollection` sein:

```json
{
  "type": "FeatureCollection",
  "features": [
    {
      "type": "Feature",
      "properties": {
        "name": "Beispiel"
      },
      "geometry": {
        "type": "Point",
        "coordinates": [14.2858, 48.3069]
      }
    }
  ]
}
```

Unterstützte Geometrietypen der Quell-Features:

- `Point`
- `MultiPoint`
- `LineString`
- `MultiLineString`
- `Polygon`
- `MultiPolygon`
- `GeometryCollection`

Features ohne gültiges Geometrieobjekt werden nicht übernommen. Properties, Feature-IDs und fremde Mitglieder der ursprünglichen `FeatureCollection` bleiben erhalten. Eine vorhandene globale `bbox` wird entfernt, weil sie nach dem Filtern nicht mehr stimmen muss.

### Zusätzlicher Transportmittel-Filter

Optional kann vor der räumlichen Prüfung nach der Feature-Property
`affected-transportmode-types` gefiltert werden:

```json
{
  "type": "Feature",
  "properties": {
    "affected-transportmode-types": ["bus", "tram"]
  },
  "geometry": {
    "type": "Point",
    "coordinates": [14.2858, 48.3069]
  }
}
```

Die erlaubten Werte werden am Anfang der PHP-Datei konfiguriert:

```php
const TRANSPORT_MODE_PROPERTY = 'affected-transportmode-types';
const ALLOWED_TRANSPORT_MODE_TYPES = ['bus', 'train'];
```

Die Semantik ist **ODER**: Das Feature wird für die anschließende räumliche
Prüfung zugelassen, sobald mindestens einer seiner Werte in der Allow-List
vorkommt. Im Beispiel treffen daher sowohl `['bus']` als auch
`['tram', 'train']` zu.

Der Vergleich ist exakt und unterscheidet Groß- und Kleinschreibung. `bus` und
`BUS` sind daher unterschiedliche Werte. Fehlt die Property, ist sie `null`
oder enthält sie keinen passenden String, wird das Feature verworfen.

Ein einzelner String wird aus Robustheitsgründen ebenfalls akzeptiert. Die
bevorzugte Datenform bleibt jedoch eine Liste von Strings.

Mit einer leeren Allow-List wird dieser Filter vollständig deaktiviert:

```php
const ALLOWED_TRANSPORT_MODE_TYPES = [];
```

Die Attributprüfung findet **vor** der Geometrieprüfung statt. Nicht passende
Features verursachen daher keine räumlichen Berechnungen.

### Pufferdatensatz

Der Pufferdatensatz kann eines der folgenden GeoJSON-Objekte sein:

- eine direkte `Polygon`- oder `MultiPolygon`-Geometrie
- ein `Feature`
- eine `FeatureCollection`
- eine `GeometryCollection`

`Polygon`- und `MultiPolygon`-Geometrien dürfen darin beliebig verschachtelt vorkommen. Andere Geometrietypen werden ignoriert. Enthält der Datensatz kein gültiges Polygon, schlägt der Cache-Aufbau fehl.

### Kompression

Beide Antworten dürfen entweder:

- plain GeoJSON oder
- echte gzip-Daten

enthalten. Gzip wird anhand der Magic Bytes im Response-Body erkannt. Die Dateiendung und der `Content-Type` sind dafür nicht entscheidend.

### Koordinatensystem

Beide Datensätze müssen dasselbe Koordinatensystem und dieselbe Achsenreihenfolge verwenden. Das Skript führt keine Transformation durch.

Bei üblichen GeoJSON-Dateien lautet die Koordinatenreihenfolge:

```text
[Längengrad, Breitengrad]
```

Beispiel:

```json
[14.2858, 48.3069]
```

Die Implementierung berechnet keine Distanz und keinen Puffer. Der Puffer muss bereits als Polygon vorliegen.

## Räumliche Filterung

Ein Quell-Feature wird übernommen, sobald irgendein Teil seiner Geometrie mindestens einen Puffer schneidet, berührt oder innerhalb davon liegt.

Berücksichtigt werden unter anderem:

- Punkte innerhalb eines Polygons
- Punkte auf dessen Außenrand
- Linien, die einen Polygonrand kreuzen
- Linien, die vollständig innerhalb eines Polygons liegen
- Polygone, die einen Puffer überlappen
- Polygone, die vollständig innerhalb eines Puffers liegen
- Polygone, die einen Puffer vollständig umschließen
- Innenringe beziehungsweise Löcher von Polygonen
- einzelne treffende Bestandteile von Multi-Geometrien und `GeometryCollection`

### Punktdarstellung für Symbole

Ohne Parameter liefert der Proxy weiterhin die vollständig gefilterten Geometrien. Mit folgendem Parameter wird dieselbe FeatureCollection als Punktdatensatz ausgeliefert:

```text
?geometry=point
```

`?geometry=centroid` ist als gleichbedeutender Alias verfügbar. `?geometry=full` erzwingt ausdrücklich die Standarddarstellung.

Bei jedem Feature werden `id`, `properties` und sonstige fremde Feature-Mitglieder beibehalten; nur `geometry` wird durch eine `Point`-Geometrie ersetzt. Eine eventuell vorhandene Feature-`bbox` wird entfernt.

Die Punktkoordinate wird abhängig vom Geometrietyp berechnet:

- `Point`: unveränderte Koordinate
- `MultiPoint`: arithmetischer Mittelwert der Punkte
- `LineString` und `MultiLineString`: nach Segmentlänge gewichteter Linienschwerpunkt
- `Polygon` und `MultiPolygon`: nach Fläche gewichteter Flächenschwerpunkt; Innenringe werden abgezogen
- `GeometryCollection`: Schwerpunkt der enthaltenen Geometrien mit der höchsten vorhandenen Dimension

Bei degenerierten Flächen wird, soweit möglich, auf Linien- beziehungsweise Punktanteile zurückgefallen. Features, für die überhaupt keine gültige Punktkoordinate ermittelt werden kann, werden nur aus der Punktdarstellung ausgelassen; die vollständige Darstellung bleibt davon unberührt.

Die Berechnung erfolgt direkt im Koordinatenraum der Eingabedaten. Bei gewöhnlichem GeoJSON mit Längen- und Breitengraden ist dies somit ein planarer Schwerpunkt in Grad und kein geodätischer Schwerpunkt auf dem Erdellipsoid. Für einen regional begrenzten Datensatz wie Österreich ist das für die Symbolpositionierung in der Regel ausreichend.

Ein geometrischer Schwerpunkt muss bei stark konkaven Polygonen oder Polygonen mit Löchern nicht zwingend innerhalb der sichtbaren Fläche liegen. Für eine garantiert innenliegende Beschriftungsposition wäre ein eigener „point on surface“- beziehungsweise Polylabel-Algorithmus erforderlich.

### Algorithmische Optimierung

Die ursprüngliche naive Prüfung hätte im ungünstigen Fall jedes Quellsegment mit jedem Puffersegment verglichen. Bei komplexen Polygonen führt das zu annähernd quadratischem Aufwand.

Die optimierte Version arbeitet in mehreren Stufen:

1. **Bounding-Box-Prüfung:** Features werden zuerst gegen die Bounding Boxes der Puffer geprüft.
2. **Rasterindex für Puffergrenzen:** Puffersegmente werden einmalig räumlichen Rasterzellen zugeordnet.
3. **Lokale Kandidatensuche:** Ein Quellsegment wird nur mit Puffersegmenten aus den berührten Rasterzellen verglichen.
4. **Y-Bucket-Index:** Punkt-in-Polygon-Prüfungen berücksichtigen nur Kanten, deren Y-Ausdehnung zur Höhe des Prüfpunkts passt.
5. **Reduzierte Linienprüfung:** Bei einer `LineString` wird nicht jeder Punkt vollständig gegen das Polygon geprüft. Ein Eintritt von außen muss eine indizierte Pufferkante schneiden.

Der Index wird bei jedem Cache-Refresh einmal aus den Pufferpolygonen aufgebaut und anschließend für alle Quell-Features wiederverwendet.

## Cache-Verhalten

Der Cache besteht aus:

```text
cache/data.geojson   fertig gefiltertes Ergebnis mit vollständigen Geometrien
cache/points.geojson Punktdarstellung desselben Ergebnisses
cache/meta.json      beide ETags, Cache-Identität und Zeitangaben
cache/refresh.lock   Sperrdatei für parallele Aktualisierungen
cache/proxy.log      fortlaufendes Diagnoseprotokoll
cache/status.json    zuletzt gemeldeter Bearbeitungsstatus
```

### Frischer Cache

Solange `CACHE_TTL` nicht abgelaufen ist, werden je nach Request direkt `data.geojson` oder `points.geojson` gelesen. Quell- und Puffer-URL werden nicht erneut aufgerufen.

```text
X-Cache: HIT
```

### Erfolgreicher Neuaufbau

Nach Ablauf der TTL lädt das Skript beide Dateien, entpackt sie gegebenenfalls, baut die räumlichen Indizes auf, filtert den Quelldatensatz, erzeugt zusätzlich die Punktdarstellung und ersetzt beide Cache-Dateien gemeinsam.

```text
X-Cache: MISS
```

### Fehler beim Neuaufbau

Ist ein alter Cache vorhanden und befindet er sich noch innerhalb von `STALE_TTL`, wird weiterhin das zuletzt erfolgreich erzeugte Ergebnis ausgeliefert.

```text
X-Cache: STALE
Warning: 110 - "Response is stale because source refresh failed"
Cache-Control: public, max-age=0, must-revalidate
```

Ohne verwendbaren alten Cache antwortet das Skript mit HTTP 502 und einem JSON-Fehlerobjekt.

### Parallele Aufrufe

`refresh.lock` und `flock()` verhindern, dass mehrere gleichzeitige uMap-Anfragen denselben Cache parallel neu berechnen. Ein wartender Request prüft den Cache nach Erhalt des Locks erneut.

### Cache-Identität

Die Cache-Metadaten berücksichtigen:

- die Skriptversion
- `SOURCE_URL`
- `BUFFER_URL`
- `TRANSPORT_MODE_PROPERTY`
- die normalisierte Liste `ALLOWED_TRANSPORT_MODE_TYPES`

Werden eine URL, die Allow-List oder `VERSION` geändert, wird ein alter Cache nicht als passender Cache akzeptiert.

## Verwendung im Browser und in uMap

Vollständige gefilterte Geometrien:

```text
https://your-domain.example/geojson-proxy.php
```

Punktdarstellung für einen separaten Symbol-Layer:

```text
https://your-domain.example/geojson-proxy.php?geometry=point
```

In uMap können damit zwei externe Layer auf denselben Proxy zeigen:

```text
Geometrie-Layer:
URL:    https://your-domain.example/geojson-proxy.php
Format: GeoJSON

Symbol-Layer:
URL:    https://your-domain.example/geojson-proxy.php?geometry=point
Format: GeoJSON
```

Beide Layer enthalten dieselben Feature-IDs und Properties. Dadurch können in uMap für die Flächen beziehungsweise Linien und für die Symbole dieselben datenabhängigen Darstellungsregeln verwendet werden.

Das Ergebnis wird mit folgendem Content-Type ausgeliefert:

```text
application/geo+json; charset=utf-8
```

CORS ist für alle Ursprünge aktiviert:

```text
Access-Control-Allow-Origin: *
```

Unterstützte HTTP-Methoden:

- `GET`
- `HEAD`
- `OPTIONS`

Andere Methoden erhalten HTTP 405. Ein unbekannter Wert für `geometry` erhält HTTP 400, damit Tippfehler nicht unbemerkt die falsche Darstellung liefern.

## Status und Logging

### Status-Endpunkt

Der normale Endpunkt gibt erst nach Abschluss des Cache-Aufbaus GeoJSON aus. Der Fortschritt kann parallel über folgenden Endpunkt abgefragt werden:

```text
https://your-domain.example/geojson-proxy.php?status=1
```

Beispiel:

```json
{
  "status_available": true,
  "current": {
    "stage": "filtering",
    "updated_at": "2026-07-12T18:45:20+00:00",
    "elapsed_seconds": 1.42,
    "memory_mib": 38,
    "peak_memory_mib": 44,
    "processed_features": 1000,
    "total_features": 1512,
    "retained_features": 246,
    "buffer_polygons": 6,
    "buffer_segments": 18342,
    "percent": 66.1
  }
}
```

Mögliche Verarbeitungsstufen sind unter anderem:

```text
request-start
waiting-for-refresh-lock
refresh-lock-acquired
refresh-started
downloading-source
source-decoded
downloading-buffer
buffer-decoded
preparing-buffer-polygons
filtering
spatial-filter-complete
encoding-result
writing-cache
refresh-complete
refresh-failed
fatal-error
```

### Logdatei

`cache/proxy.log` verwendet das JSON-Lines-Format: Jede Zeile ist ein vollständiges JSON-Objekt.

Live beobachten:

```bash
tail -f cache/proxy.log
```

Ein relevanter Eintrag nach dem Indexaufbau sieht beispielsweise so aus:

```json
{
  "level": "INFO",
  "message": "buffer polygons prepared with spatial indexes",
  "buffer_polygons": 6,
  "buffer_segments": 18342,
  "boundary_grid_cells": 1520
}
```

Diese Werte helfen bei der Beurteilung, ob ungewöhnlich viele oder besonders komplexe Puffersegmente verarbeitet werden.

## HTTP-Caching

Das Skript erzeugt für die vollständige und die punktförmige Darstellung jeweils einen eigenen SHA-256-basierten `ETag`. Clients können den zur angefragten URL gehörenden Wert über `If-None-Match` zurücksenden. Ist diese Darstellung unverändert, antwortet der Proxy mit HTTP 304 und ohne Body.

Zusätzliche Header:

```text
ETag: "..."
X-Cache: HIT | MISS | STALE
X-Geometry-Mode: full | point
X-Cache-Fetched-At: 2026-07-12T12:00:00+00:00
Age: 42
```

## Kommandozeilenverwendung

Hilfe anzeigen:

```bash
php geojson-proxy.php --help
```

Version anzeigen:

```bash
php geojson-proxy.php --version
```

Cache unabhängig von seiner aktuellen Gültigkeit neu aufbauen:

```bash
php geojson-proxy.php --warm-cache
```

Beispielausgabe:

```text
cache refreshed
etag: "..."
bytes: 123456
point_etag: "..."
point_bytes: 45678
expires_at: 2026-07-12T12:15:00+00:00
stale_until: 2026-07-13T12:15:00+00:00
peak_memory_mib: 38.00
```

Der Warm-Cache-Aufruf eignet sich auch für einen Cronjob, damit die Aktualisierung nicht durch den ersten uMap-Aufruf nach Ablauf der TTL ausgelöst werden muss.

```cron
*/15 * * * * /usr/bin/php /pfad/zu/geojson-proxy.php --warm-cache >/dev/null 2>&1
```

## Tests

`test_php_proxy_v14.py` ist ein Integrationstest. Er startet:

1. einen lokalen Python-HTTP-Server als simulierte Quell- und Puffergegenstelle,
2. den eingebauten PHP-Webserver mit einer temporären Kopie des Skripts und
3. echte HTTP-Anfragen gegen den Proxy.

Die originale PHP-Datei wird nicht verändert. Der Test ersetzt nur in einer temporären Kopie die Konfigurationskonstanten und verwendet ein temporäres Cache-Verzeichnis.

Die Tests benötigen keinen Internetzugriff.

### Test ausführen

```bash
python3 test_php_proxy_v14.py ./geojson-proxy.php
```

Alternativ:

```bash
chmod +x test_php_proxy_v14.py
./test_php_proxy_v14.py ./geojson-proxy.php
```

Ein anderes PHP-Binary verwenden:

```bash
python3 test_php_proxy_v14.py ./geojson-proxy.php \
  --php-bin /usr/local/bin/php
```

PHP-Serverlog nach dem Test anzeigen:

```bash
python3 test_php_proxy_v14.py ./geojson-proxy.php \
  --show-php-log
```

### Abgedeckte Testfälle

Der Test führt die Gruppen `Test 0` bis `Test 12` aus:

0. Syntaxprüfung der temporär konfigurierten PHP-Datei mit `php -l`
1. Abruf und Dekomprimierung beider gzip-Dateien sowie räumliche Filterung aller unterstützten Geometrietypen
1b. Punktdarstellung mit identischen Feature-IDs und Properties sowie typgerechten Schwerpunktberechnungen
2. erneuter Aufruf beider Darstellungen aus dem fertigen Cache ohne weitere Upstream-Anfragen
3. getrennte ETags, `If-None-Match` und HTTP 304 für vollständige und punktförmige Darstellung
4. `HEAD` mit darstellungsspezifischen Headern und ohne Body
5. `OPTIONS`, CORS, HTTP 405 sowie HTTP 400 bei ungültigem `geometry`-Parameter
6. geänderter Puffer wird erst nach Ablauf der TTL berücksichtigt
7. Ausfall der Quell-URL führt zur Auslieferung des alten Caches als `STALE`
8. Ausfall der Puffer-URL führt ebenfalls zur Auslieferung des alten Caches
9. erfolgreiche Erholung sowie erzwungener Neuaufbau mit `--warm-cache`
10. ungültiger Pufferdatensatz führt ohne alten Cache zu HTTP 502
11. erster Request direkt im Punktmodus sowie ODER-Filterung nach `affected-transportmode-types`, einschließlich fehlender Property und case-sensitive Matching
12. Änderung der Allow-List invalidiert einen ansonsten frischen Cache

Die Geometrietests enthalten unter anderem:

- Treffer und Nicht-Treffer für alle unterstützten Quellgeometrien
- mehrere Pufferpolygone
- `MultiPolygon` innerhalb einer `GeometryCollection`
- Punkte auf dem Außenrand
- Punkte auf dem Rand eines Polygonlochs
- Punkte und Geometrien vollständig in einem Loch
- kreuzende Linien
- überlappende und umschließende Polygone
- Erhalt ursprünglicher Properties und fremder Collection-Mitglieder
- Entfernung einer nach dem Filtern ungültigen globalen `bbox`
- Kontrolle, dass im Cache sowohl die vollständige als auch die punktförmige Darstellung liegt
- Flächenmittelpunkt mit Innenring, längengewichteter Linienmittelpunkt und dimensionsgerechte `GeometryCollection`

Erfolgreicher Abschluss:

```text
All tests passed.
```

Die Tests prüfen die fachliche Korrektheit der räumlichen Filterung und des Cache-Verhaltens. Sie sind kein repräsentativer Benchmark für die Laufzeit realer, hochkomplexer Geometrien.

## Diagnose und Fehlerbehebung

### Nur `refresh.lock` wird angelegt

`refresh.lock` wird vor Download, Entpacken, Indexaufbau und Filterung erzeugt. Prüfe anschließend:

```text
cache/status.json
cache/proxy.log
```

Oder rufe parallel auf:

```text
https://your-domain.example/geojson-proxy.php?status=1
```

Damit ist erkennbar, ob der Prozess beim Lock, Download, JSON-Dekodieren, Indexaufbau oder Filtern arbeitet.

### `waiting-for-refresh-lock`

Ein anderer PHP-Prozess hält den Lock und baut vermutlich gerade den Cache auf. Prüfe `proxy.log` und den Status-Endpunkt. Eine alte leere Lockdatei ist allein kein Problem; entscheidend ist die aktive `flock()`-Sperre.

### `cache directory is not writable`

Das konfigurierte Verzeichnis ist für den PHP-Prozess nicht beschreibbar. Rechte oder Eigentümer anpassen.

### `PHP zlib support is required for gzip data`

Die PHP-zlib-Erweiterung ist nicht verfügbar. Auf einem verwalteten Webspace muss sie durch den Anbieter aktiviert sein.

### `neither curl nor allow_url_fopen is available`

PHP kann keine entfernten URLs abrufen. Entweder cURL aktivieren oder `allow_url_fopen` freischalten lassen.

### `response exceeds MAX_BYTES`

Die heruntergeladene Datei, die entpackte Datei oder das Ergebnis überschreitet `MAX_BYTES`.

Für einen 6 MiB großen entpackten Quelldatensatz ist der Standardwert von 32 MiB ein sinnvoller Ausgangspunkt:

```php
const MAX_BYTES = 32 * 1024 * 1024;
```

### `Allowed memory size ... exhausted`

Das PHP-`memory_limit` reicht nicht für JSON-Text, dekodierte PHP-Arrays, vorbereitete Pufferindizes und Ergebnis aus. Das Speicherlimit erhöhen oder die Datensätze vereinfachen.

Den Spitzenverbrauch zeigt der CLI-Aufruf:

```bash
php geojson-proxy.php --warm-cache
```

### Die Verarbeitung bleibt bei `preparing-buffer-polygons`

Der Aufbau der räumlichen Indizes benötigt ungewöhnlich lange. Typische Ursachen:

- extrem viele Puffersegmente
- sehr lange Segmente, die große Bereiche überdecken
- ungültige oder unnötig hoch aufgelöste Puffergeometrien

Prüfe die Segmentzahl im Log. Eine Vereinfachung der Pufferpolygone vor der Bereitstellung kann Laufzeit und Speicherbedarf stark reduzieren.

### Die Verarbeitung bleibt bei `filtering`

Prüfe im Status:

- `processed_features`
- `total_features`
- `retained_features`
- `buffer_segments`
- `percent`

Falls sich `processed_features` nur langsam erhöht, sind vermutlich einzelne Quell-Features sehr komplex. Dann lohnt es sich, deren Koordinatenzahl oder Geometrietyp gezielt zu untersuchen.

### HTTP 502

Mindestens einer dieser Schritte ist fehlgeschlagen:

- Quell- oder Pufferdatei herunterladen
- gzip-Datei entpacken
- JSON dekodieren
- GeoJSON-Grundstruktur prüfen
- mindestens ein Pufferpolygon finden
- räumliche Indizes aufbauen
- räumlich filtern
- Ergebnis kodieren oder speichern

Die JSON-Antwort enthält im Feld `details` die konkrete Fehlermeldung. Zusätzliche Informationen stehen in `cache/proxy.log` und `cache/status.json`.

### Leere FeatureCollection

Eine leere `features`-Liste ist kein technischer Fehler. Sie bedeutet, dass kein Quell-Feature eine Pufferregion überschneidet.

## Leistungsoptimierung

Die Standardindexwerte sind für allgemeine Daten gewählt. Vor Änderungen sollten folgende Werte aus dem Log betrachtet werden:

- `buffer_polygons`
- `buffer_segments`
- `boundary_grid_cells`
- Dauer der Stufen `preparing-buffer-polygons` und `filtering`
- maximaler Speicherverbrauch

Weitere wirksame Maßnahmen:

1. Puffergeometrien vorab sinnvoll vereinfachen.
2. Unnötig dichte Quelllinien oder Polygone vereinfachen.
3. Den Cache über einen Cronjob mit `--warm-cache` erzeugen.
4. `CACHE_TTL` so wählen, dass die Neuberechnung nicht unnötig häufig erfolgt.
5. Logging nach erfolgreicher Inbetriebnahme reduzieren.

Ein kleinerer Wert für `SPATIAL_INDEX_TARGET_SEGMENTS_PER_CELL` kann die Kandidatenzahl pro Rasterzelle reduzieren, erzeugt aber mehr Indexzellen und höheren Speicherbedarf. Ein größerer Wert spart Speicher, führt aber zu mehr Segmentvergleichen. Änderungen sollten deshalb anhand realer Messwerte erfolgen.

## Grenzen

Die räumlichen Operationen sind bewusst als Pure-PHP-Lösung implementiert und ersetzen keine vollständige GIS-Engine.

Zu beachten:

- Es erfolgt keine Koordinatentransformation.
- Ungültige oder selbstüberschneidende Polygone werden nicht repariert.
- Sonderfälle am 180. Längengrad beziehungsweise Antimeridian werden nicht eigens behandelt.
- Sehr große oder extrem komplexe Geometrien können weiterhin hohe Laufzeit und hohen Speicherbedarf verursachen.
- Die Implementierung ist für topologische Überschneidungsprüfungen gedacht, nicht für exakte Distanz-, Flächen- oder Pufferberechnungen.
- Die numerische Toleranz kann robuste GEOS-Arithmetik nicht vollständig ersetzen.
- Die Punktdarstellung berechnet planare Schwerpunkte und garantiert bei konkaven Polygonen oder Löchern keinen Punkt innerhalb der Fläche.
- Der Rasterindex beschleunigt Kandidatensuchen, ändert aber nicht die geometrische Genauigkeit der anschließenden Segmenttests.

Für den beschriebenen Einsatzzweck – einen ungefähr 6 MiB großen Datensatz periodisch gegen sechs vorberechnete Pufferpolygone zu filtern und das Ergebnis aus dem Cache an uMap auszuliefern – ist die indexierte Version wesentlich besser geeignet als eine vollständige paarweise Segmentprüfung.

## Sicherheitshinweise

- Die beiden URLs sind fest in der PHP-Datei konfiguriert. Das Skript ist kein offener Proxy und nimmt keine Ziel-URL aus Benutzerparametern entgegen.
- `MAX_BYTES`, HTTP-Timeouts und eine Begrenzung der Weiterleitungen schützen vor unbegrenzt großen oder hängenden Downloads.
- Das Cache-Verzeichnis sollte möglichst nicht direkt öffentlich abrufbar sein. Der Client soll ausschließlich das PHP-Skript verwenden.
- Enthalten die URLs geheime Tokens, muss der Zugriff auf PHP-Quelldateien und Backups zuverlässig verhindert sein.
- `proxy.log` und `status.json` können Quell-URLs, Größen und Fehlermeldungen enthalten. Bei vertraulichen Daten sollte der direkte Webzugriff auf das Cache-Verzeichnis gesperrt werden.
- Der Status-Endpunkt ist für Diagnosezwecke gedacht. Falls dessen Informationen nicht öffentlich sichtbar sein sollen, `STATUS_ENDPOINT_ENABLED` nach der Inbetriebnahme auf `false` setzen.
- Da CORS mit `*` aktiviert ist, kann das gefilterte Ergebnis von beliebigen Webseiten abgerufen werden. Das ist für einen öffentlichen uMap-Layer üblich, für vertrauliche Daten aber ungeeignet.
