<?php

namespace Omnilab\Emsc;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Exception\ProviderException;
use Omnilab\Model\BBox;
use Omnilab\Model\Domain;
use Omnilab\Model\Entity;
use Omnilab\Model\Identifier;
use Omnilab\Model\Point;
use Omnilab\Model\Property;
use Omnilab\Model\Quantity;
use Omnilab\Model\Scheme;
use Omnilab\Model\Series;
use Omnilab\Source\Capability;
use Omnilab\Source\HttpSource;
use Omnilab\Source\OccurrenceQuery;
use Omnilab\Source\Page;
use Omnilab\Source\SeriesQuery;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The earthquake catalogue of the European-Mediterranean Seismological
 * Centre (EMSC-CSEM), through the SeismicPortal's FDSN event web service
 * (www.seismicportal.eu/fdsnws/event/1): an event by its SeismicPortal id
 * (20261004_0000156) - its magnitude and type, place, depth, time, the
 * Flinn-Engdahl region, the contributing agency - and, over a box or around
 * a point for a period, the earthquakes as series (their count per step;
 * each one's magnitude or depth, or the step's maximum, mean...) or as
 * occurrences, each an entity with its time and its place, one page at a
 * time.
 *
 * No key. One call per entity, per series query (every variable asked at
 * once), per page of occurrences. The service answers at most 20,000
 * events a query (a 413 beyond, when no limit is given).
 */
final class EmscSource extends HttpSource
{
    public const BASE_URI = 'https://www.seismicportal.eu/fdsnws/event/1/';

    /** The most events one query answers: more is a 413 from the service, a larger limit is cut to it. */
    public const MAX_EVENTS = 20000;

    /** Before the catalogue's first event (1998): an open start. */
    private const FIRST = '1900-01-01T00:00:00';

    /** Kilometres in a degree of a great circle: the FDSN standard's radius is in degrees. */
    private const KM_PER_DEGREE = 111.195;

    /** The variables series() serves, with their unit. */
    public const VARIABLES = ['count' => null, 'magnitude' => null, 'depth' => 'km'];

    /** How occurrences() sorts: the query's sort, the service's orderby. */
    public const SORTS = [OccurrenceQuery::NEWEST => 'time', OccurrenceQuery::OLDEST => 'time-asc', 'magnitude' => 'magnitude', 'magnitude-asc' => 'magnitude-asc'];

    /**
     * @param float      $radius       the radius around a Point, in kilometres, for series() and for occurrences() when the query gives none
     * @param float|null $minMagnitude the smallest magnitude series() and occurrences() count when the query says none (null: every event the catalogue has)
     */
    public function __construct(
        HttpClientInterface $http,
        private readonly float $radius = 100.0,
        private readonly ?float $minMagnitude = null,
        string $baseUri = self::BASE_URI,
        array $headers = [],
        float $throttle = 0.0,
    ) {
        parent::__construct($http, $baseUri, $headers, $throttle);
    }

    public function getName(): string
    {
        return 'emsc';
    }

    public function capabilities(): array
    {
        return [Capability::ENTITY, Capability::SERIES, Capability::OCCURRENCES, Capability::HISTORICAL, Capability::AREA];
    }

    /** An event by its SeismicPortal id (20261004_0000156), emsc.event:..., or its SeismicPortal page. */
    public function entity(Identifier|string $id): ?Entity
    {
        $identifier = self::identify($id, Scheme::EMSC_EVENT) ?? throw NotSupportedException::identifier('emsc', (string) $id);
        $feature = $this->getJson('query', ['format' => 'json', 'eventid' => $identifier->value]);

        return null === $feature || 'Feature' !== ($feature['type'] ?? null) ? null : $this->toEntity($feature);
    }

    /**
     * The earthquakes over the place for the period, as series, one per
     * variable asked:
     *
     * - "count": how many events each step (SeriesQuery::DAILY, HOURLY or
     *   another ISO 8601 duration), from the start of the period;
     * - "magnitude", "depth": each event's value at its time (no aggregation:
     *   an irregular series), or the step's MAX, MIN, MEAN or SUM.
     *
     * A BBox is the box; a Point, the circle of the configured radius around
     * it. The period defaults to the last 30 days; a date without a time is
     * the whole day, in the query's time zone (UTC by default).
     */
    public function series(SeriesQuery $query): array
    {
        // A place's earthquakes, not a station's records.
        $this->station($query);
        foreach ($query->variables as $variable) {
            if (!\array_key_exists($variable, self::VARIABLES)) {
                throw NotSupportedException::operation('emsc', \sprintf('serve "%s" (it serves %s)', $variable, implode(', ', array_keys(self::VARIABLES))));
            }
        }
        if (null !== $query->aggregation && !\in_array($query->aggregation, [Series::MAX, Series::MIN, Series::MEAN, Series::SUM], true)) {
            throw NotSupportedException::operation('emsc', 'aggregate by '.$query->aggregation);
        }
        $timezone = new \DateTimeZone($query->timezone ?? 'UTC');
        [$from, $to] = self::period($query->from, $query->to, $timezone);
        $data = $this->getJson('query', ['format' => 'json', 'orderby' => 'time-asc', 'starttime' => self::utc($from), 'endtime' => self::utc($to)] + $this->where($query->place)) ?? [];
        $events = [];
        foreach ($data['features'] ?? [] as $feature) {
            $p = \is_array($feature) ? ($feature['properties'] ?? []) : [];
            if (null !== ($time = self::time($p['time'] ?? null))) {
                $events[] = ['time' => $time, 'mag' => is_numeric($p['mag'] ?? null) ? (float) $p['mag'] : null, 'depth' => is_numeric($p['depth'] ?? null) ? (float) $p['depth'] : null];
            }
        }
        usort($events, static fn (array $a, array $b) => $a['time'] <=> $b['time']);
        $step = new \DateInterval($query->step);

        $series = [];
        foreach ($query->variables as $variable) {
            if ('count' === $variable) {
                $points = [];
                foreach (self::bins($from, $to, $step) as [$start, $end]) {
                    $points[] = [$start, \count(array_filter($events, static fn (array $e) => $e['time'] >= $start && $e['time'] < $end))];
                }
                $series[] = new Series('count', null, $query->place, $points, $query->step, Series::SUM, 'Earthquakes', false, 'emsc');
                continue;
            }
            $column = 'magnitude' === $variable ? 'mag' : 'depth';
            $label = 'magnitude' === $variable ? 'Magnitude' : 'Depth';
            if (null === $query->aggregation) {
                $points = array_map(static fn (array $e) => [$e['time'], $e[$column]], $events);
                $series[] = new Series($variable, self::VARIABLES[$variable], $query->place, $points, null, null, $label.' of each earthquake', false, 'emsc');
                continue;
            }
            $points = [];
            foreach (self::bins($from, $to, $step) as [$start, $end]) {
                $values = array_values(array_filter(array_map(static fn (array $e) => $e['time'] >= $start && $e['time'] < $end ? $e[$column] : null, $events), static fn ($v) => null !== $v));
                $points[] = [$start, $values ? match ($query->aggregation) {
                    Series::MAX => max($values),
                    Series::MIN => min($values),
                    Series::MEAN => round(array_sum($values) / \count($values), 3),
                    Series::SUM => array_sum($values),
                } : null];
            }
            $series[] = new Series($variable.'_'.$query->aggregation, self::VARIABLES[$variable], $query->place, $points, $query->step, $query->aggregation, $label.' ('.$query->aggregation.')', false, 'emsc');
        }

        return $series;
    }

    /**
     * The seismic events over the place for the period, each an entity with
     * its time and its place, one page at a time - what the list gives is
     * what entity() gives.
     *
     * A BBox is the box (across the antimeridian too); a Point, the circle
     * of the query's radius around it (the configured one by default); no
     * place, the world. A period's end left out is open: from the
     * catalogue's first event (1998), up to now; a date without a time is
     * its whole day, in UTC. Filters: OccurrenceQuery::MIN_MAGNITUDE (the
     * configured one by default), MAX_MAGNITUDE, and "magnitude_type" (the
     * type the bounds apply to: ml, mb, mw...). Sorts: newest (the service's
     * default), oldest, magnitude (the largest first), magnitude-asc. Up to
     * 20,000 a page; no total.
     */
    public function occurrences(OccurrenceQuery $query): Page
    {
        $filters = $this->filters($query, OccurrenceQuery::MIN_MAGNITUDE, OccurrenceQuery::MAX_MAGNITUDE, 'magnitude_type');
        $orderBy = null === $query->sort ? null : (self::SORTS[$query->sort] ?? throw NotSupportedException::operation('emsc', \sprintf('sort occurrences by "%s" (it sorts by %s)', $query->sort, implode(', ', array_keys(self::SORTS)))));
        foreach ([OccurrenceQuery::MIN_MAGNITUDE, OccurrenceQuery::MAX_MAGNITUDE] as $key) {
            if (isset($filters[$key]) && !is_numeric($filters[$key])) {
                throw new \InvalidArgumentException(\sprintf('The "%s" filter is a number, not "%s".', $key, get_debug_type($filters[$key])));
            }
        }
        $utc = new \DateTimeZone('UTC');
        $start = null === $query->from ? null : self::local($query->from, $utc);
        $end = null === $query->to ? null : self::local($query->to, $utc);
        if (null !== $end && '00:00:00' === $end->format('H:i:s')) {
            $end = $end->modify('+1 day');
        }
        $limit = max(1, min(self::MAX_EVENTS, $query->limit));
        $offset = max(1, (int) $query->cursor);
        $minimum = isset($filters[OccurrenceQuery::MIN_MAGNITUDE]) ? (float) $filters[OccurrenceQuery::MIN_MAGNITUDE] : null;
        $data = $this->getJson('query', [
            'format' => 'json',
            'orderby' => $orderBy,
            'starttime' => null === $start ? self::FIRST : self::utc($start),
            'endtime' => null === $end ? null : self::utc($end),
            'limit' => min(self::MAX_EVENTS, $limit + 1),
            'offset' => $offset > 1 ? $offset : null,
            'maxmagnitude' => $filters[OccurrenceQuery::MAX_MAGNITUDE] ?? null,
            'magnitudetype' => $filters['magnitude_type'] ?? null,
        ] + $this->where($query->place, $minimum, $query->radius)) ?? [];
        $features = array_values(array_filter($data['features'] ?? [], 'is_array'));
        $more = \count($features) > $limit || (self::MAX_EVENTS === $limit && \count($features) === $limit);

        return new Page(array_map($this->toEntity(...), \array_slice($features, 0, $limit)), $more ? (string) ($offset + $limit) : null);
    }

    /** @param array<string, mixed> $feature a GeoJSON feature of the service */
    private function toEntity(array $feature): Entity
    {
        $p = $feature['properties'] ?? [];
        $properties = [];
        $add = static function (string $key, string $label, mixed $value) use (&$properties): void {
            if (null !== $value && '' !== $value) {
                $properties[] = new Property($key, $label, $value, 'emsc');
            }
        };
        $add('magnitude', 'Magnitude', is_numeric($p['mag'] ?? null) ? (float) $p['mag'] : null);
        $add('magnitude_type', 'Magnitude type', self::text($p['magtype'] ?? null));
        // The depth as its property says: the geometry's third coordinate is negative for recent events, positive for old ones.
        $add('depth', 'Depth', is_numeric($p['depth'] ?? null) ? new Quantity(0 + $p['depth'], 'km') : null);
        $region = self::text($p['flynn_region'] ?? null);
        $add('region', 'Flinn-Engdahl region', $region);
        $add('event_type', 'Event type (EMSC code)', self::text($p['evtype'] ?? null));
        $add('author', 'Location author', self::text($p['auth'] ?? null));
        $add('catalog', 'Catalogue', self::text($p['source_catalog'] ?? null));
        $add('emsc_id', 'EMSC website id', self::text($p['source_id'] ?? null));
        $add('updated', 'Last updated', null === ($u = self::time($p['lastupdate'] ?? null)) ? null : $u->format(\DATE_ATOM));

        $value = (string) ($feature['id'] ?? $p['unid'] ?? '');
        $id = Identifier::tryOf(Scheme::EMSC_EVENT, $value) ?? throw new ProviderException($this->getName(), \sprintf('The SeismicPortal answered an event without an id it reads ("%s").', $value));
        $place = is_numeric($p['lat'] ?? null) && is_numeric($p['lon'] ?? null) ? new Point((float) $p['lat'], (float) $p['lon']) : null;
        $magnitude = is_numeric($p['mag'] ?? null) ? \sprintf('M %.1f', (float) $p['mag']) : null;
        $time = self::time($p['time'] ?? null);

        return new Entity(
            id: $id,
            label: null !== $magnitude && null !== $region ? $magnitude.' - '.$region : ($region ?? $id->value),
            domain: Domain::EARTH,
            kind: self::kind($p['evtype'] ?? null),
            properties: $properties,
            url: $id->url(),
            time: $time?->setTimezone(new \DateTimeZone('UTC')),
            place: $place,
            source: 'emsc',
        );
    }

    /**
     * What an event is, from EMSC's code: "ke" a known earthquake, "se" a
     * suspected one; any other code (ue, kh... seen in September 2026) is
     * given as it is, its meaning not published with the service.
     */
    private static function kind(mixed $code): ?string
    {
        $code = self::text(\is_string($code) ? $code : null);

        return match ($code) {
            null => null,
            'ke' => 'earthquake',
            'se' => 'suspected earthquake',
            default => $code,
        };
    }

    /**
     * The FDSN parameters of a place: a box (west past east across the
     * antimeridian, which the service reads), or a circle around a point
     * (its radius in degrees, the standard's unit); none for the world.
     *
     * @return array<string, mixed>
     */
    private function where(Point|BBox|null $place, ?float $minMagnitude = null, ?float $radius = null): array
    {
        $where = match (true) {
            $place instanceof BBox => ['minlatitude' => $place->south, 'maxlatitude' => $place->north, 'minlongitude' => $place->west, 'maxlongitude' => $place->east],
            $place instanceof Point => ['latitude' => $place->latitude, 'longitude' => $place->longitude, 'maxradius' => round(($radius ?? $this->radius) / self::KM_PER_DEGREE, 4)],
            default => [],
        };

        return $where + ['minmagnitude' => $minMagnitude ?? $this->minMagnitude];
    }

    /**
     * The steps from the start of the period to its end.
     *
     * @return list<array{\DateTimeImmutable, \DateTimeImmutable}>
     */
    private static function bins(\DateTimeImmutable $from, \DateTimeImmutable $to, \DateInterval $step): array
    {
        $bins = [];
        for ($start = $from; $start < $to && \count($bins) < 100_000; $start = $end) {
            $end = $start->add($step);
            $bins[] = [$start, $end];
        }

        return $bins;
    }

    /**
     * The period as two instants: a date without a time stands for the whole
     * day (from its midnight to the next); no start is 30 days before the end,
     * no end is now.
     *
     * @return array{\DateTimeImmutable, \DateTimeImmutable}
     */
    private static function period(?\DateTimeInterface $from, ?\DateTimeInterface $to, \DateTimeZone $timezone): array
    {
        $end = null === $to ? new \DateTimeImmutable('now', $timezone) : self::local($to, $timezone);
        if (null !== $to && '00:00:00' === $end->format('H:i:s')) {
            $end = $end->modify('+1 day');
        }
        $start = null === $from ? $end->modify('-30 days') : self::local($from, $timezone);

        return [$start, $end];
    }

    /** A time as written, read in a zone: a date and its time of day, the zone it was given in left aside. */
    private static function local(\DateTimeInterface $time, \DateTimeZone $timezone): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time->format('Y-m-d H:i:s.u'), $timezone);
    }

    /** Always with a time: the service reads a date alone as its whole day for an end. */
    private static function utc(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s');
    }
}
