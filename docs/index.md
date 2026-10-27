# omnilab/emsc

The EMSC's earthquake catalogue, through the SeismicPortal's FDSN event service
(`https://www.seismicportal.eu/fdsnws/event/1/`), for [omnisense/omnilab](https://github.com/glitchr-omnisense/omnilab):
an earthquake by its SeismicPortal id - time, place, magnitude, depth, Flinn-Engdahl region - as an
entity; the earthquakes of a box or around a point as series or as occurrences. No key.

## Installation

```sh
composer require omnilab/emsc
```

```php
use Omnilab\Emsc\EmscSourceFactory;

$emsc = (new EmscSourceFactory($httpClient))->create([
    'mailto' => 'support@glitchr.io',  // optional
    'radius_km' => 100,                // optional: the circle around a Point, when a query gives none
    'min_magnitude' => 4,              // optional: the smallest magnitude counted, when a query says none; null: every event
]);
```

With the Symfony bundle, the factory is registered as `emsc` when the bundle knows it; otherwise
register `EmscSourceFactory` as a service (it is autoconfigured as a `SourceFactoryInterface`).

## Credentials

None: the SeismicPortal's FDSN event service takes no key and no account.

## Options

The factory's options, with the defaults it applies (`EmscSourceFactory`):

| Option | Type | Default | Required | Effect |
|---|---|---|---|---|
| `radius_km` | `float` | `100` | no | the circle around a `Point`, in kilometres, for `series()`, and for `occurrences()` when the query gives no radius (sent in degrees) |
| `min_magnitude` | `?float` | null | no | the smallest magnitude counted when a query says none; null: every event the catalogue has |
| `mailto` | `?string` | null | no | a contact address, sent in the User-Agent |
| `user_agent` | `?string` | null | no | the whole User-Agent, in place of the one made from `mailto` |
| `throttle` | `float` | `0` | no | seconds left between two calls: the EMSC publishes no figure |
| `base_uri` | `string` | `https://www.seismicportal.eu/fdsnws/event/1/` | no | the API the source calls |

Per call, `occurrences()` reads the filters `OccurrenceQuery::MIN_MAGNITUDE`, `MAX_MAGNITUDE` and
`magnitude_type`; any other key is refused before any call ([Calls](#calls)).

## Capabilities

`capabilities()` answers `entity`, `series`, `occurrences`, `historical`, `area`; an operation it does not answer throws `NotSupportedException` before any call.

| Capability | Supported | |
|---|---|---|
| `entity` | yes | a SeismicPortal id (`20261004_0000156`, `emsc.event:`, `emsc:`, its page); another scheme refused before any call; one call |
| `search` | no | the catalogue has no text search |
| `series` | yes | `count`, `magnitude`, `depth` over a box or around a point, by any step; one call for every variable; 20,000 events at most (the service's 413 beyond) |
| `products` | no |  |
| `occurrences` | yes | the earthquakes of a box, a circle or the world, sorted newest, oldest, by magnitude; 20,000 a page at most, paged by offset; no total |
| `datasets` | no |  |
| `jobs` | no |  |
| `open` | no |  |
| `structure` | no |  |
| `cross_references` | no | none: the SeismicPortal gives no other base's id |
| `forecast` | no |  |
| `historical` | yes | the catalogue from 1998 |
| `area` | yes | a `BBox` (across the antimeridian too) |
| `station` | no |  |
| `cloud_cover` | no |  |

## Which API

The SeismicPortal's FDSN event web service (`https://www.seismicportal.eu/fdsnws/event/1/`,
fdsnws-event 1.2.1, service 2.2), the EMSC's principal service for its catalogue (EMSC-RTS since
2012; EMSC-EMB before): `query?format=json&eventid=` for one event, `query?format=json` for the
series and the occurrences. Its JSON is GeoJSON with the EMSC's own properties (`unid`, `mag`,
`magtype`, `depth`, `flynn_region`, `evtype`, `auth`, `source_id`...). Times are UTC.

**EarthScope (IRIS) is not offered**: its FDSN event service
(`service.iris.edu/fdsnws/event/1`, which EarthScope's `service.earthscope.org` mirrors) answers
"This service has been retired" with a 404 to every call, its version included, checked on
2026-10-15.

## Its shapes

As [omnilab/usgs](https://github.com/glitchr-omnisense/omnilab-usgs): an earthquake is an
**entity** with a `time` and a `place`; the earthquakes of a place over a period are a **series**
when a variable over time is asked (how many a day, the largest of each day, each one's
magnitude: an irregular series, `step` null), and **occurrences** (the core's fourth shape) when
each event's own place and identity matter. The catalogue has no text search: no `search()`.

## Calls

| Method | Reads | SeismicPortal |
|---|---|---|
| `entity()` | a SeismicPortal id (`20261004_0000156`), `emsc.event:...`, `emsc:...`, its page (`eventdetails.html?unid=`) | `query?format=json&eventid=` |
| `series()` | `count`, `magnitude`, `depth`; a `BBox` or a `Point`; the period (30 days back by default; a date alone is the whole day, in the query's time zone, UTC by default); a step (`SeriesQuery::DAILY`, `HOURLY`, any ISO 8601 duration); an aggregation (`Series::MAX`, `MIN`, `MEAN`, `SUM`) | one `query?format=json&orderby=time-asc&starttime=&endtime=` with the place and `minmagnitude`, for every variable |
| `occurrences()` | an `OccurrenceQuery`: a `BBox`, a `Point` and its radius (`radius_km` by default), or none (the world); the period (a date alone is its whole day, UTC; an open start is the catalogue's first event, an open end now); the filters below; a sort (`newest`, the service's default, `oldest`, `magnitude`, `magnitude-asc`); a page of up to 20,000, the next by offset | `query?format=json&orderby=&starttime=&endtime=&limit=&offset=` with the place, `minmagnitude`, `maxmagnitude`, `magnitudetype` |

A `Point` is the circle of `radius_km` around it, sent as the standard's `maxradius` in degrees
(100 km: 0.8993°); a box across the antimeridian is sent as it is, west past east, which the
service reads (both sides answered). Times are always sent with their time of day: the service
reads an end given as a date alone as its whole day. `count` is per step (aggregation `sum`);
`magnitude` and `depth` are each event's value without an aggregation, the step's aggregate with
one; a step without an event is 0 for `count`, null for the others. A variable it does not serve,
another aggregation, a station in place of a place, an unknown filter or sort are refused before
any call. An unknown id is `null` (the service answers 204). More than 20,000 events in a series
is the service's 413, a `ProviderException` that says so: narrow the place, the period or raise
`min_magnitude`; occurrences page through them.

| Filter | FDSN | Value |
|---|---|---|
| `OccurrenceQuery::MIN_MAGNITUDE` | `minmagnitude` | a number; the `min_magnitude` option when absent |
| `OccurrenceQuery::MAX_MAGNITUDE` | `maxmagnitude` | a number |
| `magnitude_type` | `magnitudetype` | the magnitude type the bounds apply to: `ml`, `mb`, `mw`... |

The service has no event-type filter (`eventtype` is a 400): `OccurrenceQuery::KIND` is refused.
A page asks one event more than it holds, to know whether another follows (`next`, the offset of
the following page); it has no `total`.

## What an entity holds

`id` (`emsc.event:20261004_0000156`), `label` (`M 5.0 - CENTRAL TURKEY`: the magnitude and the
Flinn-Engdahl region, the catalogue having no title), `domain` earth, `kind` (`earthquake` for
the code `ke`, `suspected earthquake` for `se`, any other code as it is: `ue` and `kh` met in
September 2026, their meaning not published with the service), `time` (UTC, to the hundredth of a
second), `place` (the epicentre), `url` (its SeismicPortal page), and these properties:

| Key | SeismicPortal | Value |
|---|---|---|
| `magnitude`, `magnitude_type` | mag, magtype | number, text: `mb`, `ml`, `mw`, `m`... |
| `depth` | depth | Quantity, km (read from the property: the geometry's third coordinate is negative for recent events, positive for the old ones) |
| `region` | flynn_region | text |
| `event_type` | evtype | the EMSC's code |
| `author`, `catalog` | auth, source_catalog | the agency that located it (EMSC, AFAD, NDI...), `EMSC-RTS` or `EMSC-EMB` |
| `emsc_id` | source_id | the event's id on www.emsc-csem.org |
| `updated` | lastupdate | ISO 8601 |

An occurrence holds what `entity()` gives: the list and the event answer the same properties.

## The Collector

No path: the SeismicPortal gives no id of another base, and Wikidata has no property for the
EMSC's ids (none found on 2026-10-15). An EMSC event and the USGS's of the same earthquake are
two entities; joining them by time and place would be a guess, which the `Merger` does not make.

## What the catalogue does not give here

The felt reports, the moment tensors, the rupture models and the real-time WebSocket feed (other
SeismicPortal services), the QuakeML's other origins and magnitudes (`includeallorigins`,
`includeallmagnitudes`), the contributor filter and the EMSC website id lookup (`evid`) are not
read.

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnilab\Emsc\EmscSourceFactory;
use Omnilab\Model\BBox;
use Omnilab\Model\Series;
use Omnilab\Source\OccurrenceQuery;
use Omnilab\Source\SeriesQuery;
use Symfony\Component\HttpClient\HttpClient;

$emsc = (new EmscSourceFactory(HttpClient::create()))->create(['mailto' => 'you@example.org']);
$europe = new BBox(-25, 35, 45, 72);

$quakes = $emsc->occurrences(new OccurrenceQuery($europe, new DateTimeImmutable('2026-10-19'), new DateTimeImmutable('2026-10-25'), [OccurrenceQuery::MIN_MAGNITUDE => 4], limit: 3, sort: 'magnitude'));
foreach ($quakes as $quake) {
    echo $quake->time->format('Y-m-d H:i:s'), ' UTC  ', $quake->label, '  ', $quake->property('depth'), '  ', $quake->id, "\n";
}

[$count, $max] = $emsc->series(new SeriesQuery(['count', 'magnitude'], $europe, new DateTimeImmutable('2026-10-19'), new DateTimeImmutable('2026-10-25'), SeriesQuery::DAILY, Series::MAX));
echo 'events a day: ', implode(', ', $count->values()), "\nlargest a day: ", implode(', ', $max->values()), "\n";
```

```
2026-10-23 10:19:16 UTC  M 5.7 - SICILY, ITALY  3.9 km  emsc.event:20261023_0000151
2026-10-19 14:44:23 UTC  M 5.3 - ICELAND  1.1 km  emsc.event:20261019_0000208
2026-10-23 13:55:36 UTC  M 5.2 - SICILY, ITALY  10 km  emsc.event:20261023_0000226
events a day: 125, 133, 152, 164, 210, 192, 126
largest a day: 5.3, 3.8, 3.9, 3.9, 5.7, 3.6, 4.9
```

(as answered on 2026-10-27: two calls; `count` is always the step's sum)

```yaml
# config/packages/omnilab.yaml
omnilab:
    sources:
        emsc: { factory: emsc, options: { mailto: '%env(OMNILAB_MAILTO)%', radius_km: 100 } }
```

## Errors

Every call goes through `HttpSource`: a 404 or a 410 (and a 204) is null - an empty page for a list -; a 429 is a `RateLimitedException` (`retryAfter` from `Retry-After`); a 5xx, a time-out or a host that does not answer an `UnavailableException`; any other 4xx a `ProviderException` with its `status` and what the service said; an answer that is not JSON a `ProviderException`, a web page answered for JSON an `UnavailableException`.

The SeismicPortal's own: an unknown id is its 204, null; more than 20,000 events in a series its
413 ("More than 20000 entries"), a `ProviderException`. Before any call (`NotSupportedException`):
a variable not served, an aggregation it does not make, a station, a filter or a sort not read;
a magnitude filter that is not a number (`\InvalidArgumentException`).

> [!WARNING]
> Known defect: an event whose id the source cannot read (`EmscSource::toEntity()`) throws
> `\UnexpectedValueException`, where a `ProviderException` (an `OmnilabException`) is expected: a
> `catch (OmnilabException)` misses it.

## Verified, and not

Against the real service on 2026-10-15: the M 5.0 of 2026-10-04 in central Turkey by its id and
its page (11:43:23.39 UTC, mb, 7 km); an unknown id (204, null); the M4+ earthquakes over Europe
from 2026-10-01 to 10-07, the five largest as occurrences (the M 5.0 first, the next page from
the sixth) and all eleven, oldest first, in three pages of four; the same week's daily count over
Europe at every magnitude (133, 133, 114, 177, 181, 146, 151) and its daily maxima; the two M4+
within 100 km of Tokyo; 24 M5+ in a box across the antimeridian from 2026-09-01 to 10-14, both
sides; the catalogue's oldest event by an open start (1998-01-01, western Turkey); a world query
over nine months (the 413 "More than 20000 entries"). The fixtures in `Tests/Fixtures/` are its
answers.

Again on 2026-10-27: the three largest M4+ earthquakes over Europe from 10-19 to 10-25 (Sicily,
Iceland), the week's daily count and maxima (the example above).

Not verified: the service's answer to a client that calls too fast (no 429 met, no rate
published). Not offered: EarthScope's (IRIS) event service, retired.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by the EMSC-CSEM. The SeismicPortal's datasets are under
[CC BY 4.0](https://creativecommons.org/licenses/by/4.0/) ([terms](https://www.seismicportal.eu/terms.html)),
to be credited ("Credit: EMSC-CSEM SeismicPortal, https://www.seismicportal.eu"); bulk-copying the
catalogue needs the EMSC's written permission, as the README's
[Terms of use](https://github.com/glitchr-omnisense/omnilab-emsc#terms-of-use) says. Whoever runs
the package is responsible for complying with them and for any use made of what it returns: see
the README's [Responsibility](https://github.com/glitchr-omnisense/omnilab-emsc#responsibility)
section.
