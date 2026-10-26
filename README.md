# omnilab/emsc

The earthquake catalogue of the European-Mediterranean Seismological Centre (EMSC-CSEM) for
[omnisense/omnilab](https://github.com/glitchr-omnisense/omnilab): an event by its SeismicPortal id -
its magnitude and type, its place and depth, its time, its Flinn-Engdahl region, the agency that
located it - and the earthquakes of a box or around a point over a period, as series (their count
per day or hour; each one's magnitude or depth, or the step's maximum, mean...) or as occurrences -
entities, each with its time and its place, a page at a time. Through the SeismicPortal's FDSN
event web service, no key.

```php
$emsc = (new EmscSourceFactory($http))->create(['mailto' => 'support@glitchr.io']);
$quake = $emsc->entity('20261004_0000156');
$quake->label;                      // M 5.0 - CENTRAL TURKEY
$quake->time;                       // 2026-10-04 11:43:23.39 UTC
$quake->place;                      // 37.7417,36.1008
$quake->property('depth');          // 7 km
$quake->value('magnitude_type');    // mb

$europe = new BBox(-25, 35, 45, 72);
$largest = $emsc->occurrences(new OccurrenceQuery($europe, new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-07'),
    [OccurrenceQuery::MIN_MAGNITUDE => 4], limit: 5, sort: 'magnitude'));
$largest->first()->label;           // M 5.0 - CENTRAL TURKEY
[$count] = $emsc->series(new SeriesQuery(['count'], $europe, new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-07'), SeriesQuery::DAILY));
$count->values();                   // every magnitude: 133 133 114 177 181 146 151
```

```yaml
omnilab:
    sources:
        emsc:
            factory: emsc
            options:
                mailto: 'support@glitchr.io'   # in the User-Agent
                radius_km: 100                 # the circle around a Point, when a query gives none
                min_magnitude: 4               # the smallest magnitude series() and occurrences() count, when a query says none; null: all
```

The USGS's catalogue is [omnilab/usgs](https://github.com/glitchr-omnisense/omnilab-usgs): the same
FDSN standard, another network's locations and magnitudes for the same earthquakes (the EMSC's
M 5.0 mb of 2026-10-04 is the USGS's M 5.0 mww us6000tzip), and no id of one in the other, so the
`Collector` does not join them.

See [docs/](docs/index.md) for what is read, how it maps, and what the catalogue does not give here.

## Terms of use

The SeismicPortal's datasets are under [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/)
([terms](https://www.seismicportal.eu/terms.html)); credit them, for example "Credit: EMSC-CSEM
SeismicPortal, https://www.seismicportal.eu", or "EMSC-CSEM (2026): FDSN Earthquake Parameters.
Seismicportal.eu. (Dataset). https://www.seismicportal.eu/fdsn-wsevent.html. Accessed <date>". The
website, the services and the databases as such stay the EMSC's: bulk-copying the catalogue needs
its written permission. The service publishes no rate, answers at most 20,000 events a query (a
413 beyond); the source makes one call per entity, per series query and per page of occurrences.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by the European-Mediterranean Seismological Centre (EMSC-CSEM). It reaches their service on behalf of whoever runs it, and it does not
redistribute their data beyond the short excerpts recorded as test fixtures. Its MIT licence
covers this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with the terms above, for
obtaining whatever they require (an account, a key, a licence or subscription, an attribution or
a citation, a rate limit to respect), and for any use made of what it returns. The authors
publish it free of charge, make no commercial use of it, and accept no liability for how it is
used (see [LICENSE](LICENSE)).

License: MIT.
