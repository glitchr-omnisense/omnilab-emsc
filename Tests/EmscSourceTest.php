<?php

namespace Omnilab\Emsc\Tests;

use Omnilab\Emsc\EmscSource;
use Omnilab\Emsc\EmscSourceFactory;
use Omnilab\Exception\NotSupportedException;
use Omnilab\Exception\ProviderException;
use Omnilab\Exception\UnavailableException;
use Omnilab\Model\BBox;
use Omnilab\Model\Domain;
use Omnilab\Model\Identifier;
use Omnilab\Model\Point;
use Omnilab\Model\Quantity;
use Omnilab\Model\Scheme;
use Omnilab\Model\Series;
use Omnilab\Source\Capability;
use Omnilab\Source\OccurrenceQuery;
use Omnilab\Source\SeriesQuery;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Fixtures recorded from www.seismicportal.eu/fdsnws/event/1 on 2026-10-15
 * (fdsnws-event 1.2.1): the M 5.0 of 2026-10-04 in central Turkey
 * (20261004_0000156); the M4+ earthquakes over Europe from 2026-10-01 to
 * 10-07, the six largest and all eleven in time order; the M4+ within 100
 * km of Tokyo the same week (two); the 413 of a query of more than 20,000
 * events. An unknown id answers 204.
 */
final class EmscSourceTest extends TestCase
{
    /** @var list<array<string, string>> */
    private array $calls = [];

    private function source(?MockResponse $response = null, array $options = []): EmscSource
    {
        $http = new MockHttpClient(function (string $method, string $url) use ($response): MockResponse {
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            $this->calls[] = $query;
            if ($response) {
                return $response;
            }
            $fixture = match (true) {
                isset($query['eventid']) => '20261004_0000156' === $query['eventid'] ? 'event-20261004_0000156.json' : null,
                isset($query['latitude']) => 'query-tokyo.json',
                'magnitude' === ($query['orderby'] ?? null) => 'query-europe-m4-magnitude.json',
                default => 'query-europe-m4-time.json',
            };

            return null === $fixture
                ? new MockResponse('', ['http_code' => 204])
                : new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.$fixture));
        });
        $source = (new EmscSourceFactory($http))->create($options);
        self::assertInstanceOf(EmscSource::class, $source);

        return $source;
    }

    public function testAnEventInOneCall(): void
    {
        $quake = $this->source()->entity('20261004_0000156');

        self::assertSame('emsc.event:20261004_0000156', $quake->id->key());
        self::assertSame('M 5.0 - CENTRAL TURKEY', $quake->label);
        self::assertSame(Domain::EARTH, $quake->domain);
        self::assertSame('earthquake', $quake->kind);
        self::assertSame('2026-10-04T11:43:23.390+00:00', $quake->time->format('Y-m-d\TH:i:s.vP'));
        self::assertEquals(new Point(37.7417, 36.1008), $quake->place);
        self::assertSame(5.0, $quake->value('magnitude'));
        self::assertSame('mb', $quake->value('magnitude_type'));
        self::assertEquals(new Quantity(7.0, 'km'), $quake->value('depth'));
        self::assertSame(['CENTRAL TURKEY', 'EMSC', 'EMSC-RTS', '2069351'], [$quake->value('region'), $quake->value('author'), $quake->value('catalog'), $quake->value('emsc_id')]);
        self::assertSame('https://www.seismicportal.eu/eventdetails.html?unid=20261004_0000156', $quake->url);
        self::assertSame('emsc', $quake->source);
        self::assertSame([['format' => 'json', 'eventid' => '20261004_0000156']], $this->calls);
    }

    public function testAnEventWhoseIdItCannotReadIsAProviderError(): void
    {
        $this->expectException(\Omnilab\Exception\ProviderException::class);
        $this->expectExceptionMessage('[emsc] The SeismicPortal answered an event without an id it reads ("not an id").');
        $this->source(new MockResponse('{"type":"Feature","id":"not an id","properties":{"unid":"not an id","mag":5.0}}'))->entity('20261004_0000156');
    }

    public function testAnEventByItsPageOrScheme(): void
    {
        $source = $this->source();

        self::assertSame('20261004_0000156', $source->entity('https://www.seismicportal.eu/eventdetails.html?unid=20261004_0000156')?->id->value);
        self::assertSame('20261004_0000156', $source->entity(Identifier::of(Scheme::EMSC_EVENT, '20261004_0000156'))?->id->value);
        self::assertNull($source->entity('20991004_0000156'));
    }

    public function testAnotherBaseIsRefusedBeforeAnyCall(): void
    {
        $this->expectException(NotSupportedException::class);
        try {
            $this->source()->entity('us7000pn9s');
        } finally {
            self::assertSame([], $this->calls);
        }
    }

    public function testCapabilities(): void
    {
        self::assertSame([Capability::ENTITY, Capability::SERIES, Capability::OCCURRENCES, Capability::HISTORICAL, Capability::AREA], $this->source()->capabilities());
        self::assertSame('emsc', $this->source()->getName());
    }

    public function testADailyCountAndEachMagnitudeInOneCall(): void
    {
        $europe = new BBox(-25, 35, 45, 72);
        [$count, $magnitude] = $this->source(null, ['min_magnitude' => 4])->series(new SeriesQuery(['count', 'magnitude'], $europe, new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-07'), SeriesQuery::DAILY));

        self::assertSame([0, 1, 0, 7, 1, 1, 1], $count->values());
        self::assertSame('2026-10-01T00:00:00+00:00', $count->times()[0]->format(\DATE_ATOM));
        self::assertSame([SeriesQuery::DAILY, Series::SUM, false, 'emsc'], [$count->step, $count->aggregation, $count->forecast, $count->source]);
        self::assertCount(11, $magnitude);
        self::assertNull($magnitude->step);
        self::assertSame(5.0, $magnitude->max());
        self::assertSame([['format' => 'json', 'orderby' => 'time-asc', 'starttime' => '2026-10-01T00:00:00', 'endtime' => '2026-10-08T00:00:00', 'minlatitude' => '35', 'maxlatitude' => '72', 'minlongitude' => '-25', 'maxlongitude' => '45', 'minmagnitude' => '4']], $this->calls);
    }

    public function testTheDailyMaximumAroundAPoint(): void
    {
        $tokyo = new Point(35.6812, 139.7671);
        [$max] = $this->source(null, ['min_magnitude' => 4])->series(new SeriesQuery(['magnitude'], $tokyo, new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-07'), SeriesQuery::DAILY, Series::MAX));

        self::assertSame('magnitude_max', $max->variable);
        self::assertSame([5.3, null, null, null, null, null, 4.9], $max->values());
        // 100 km in degrees, the FDSN standard's unit.
        self::assertSame('0.8993', $this->calls[0]['maxradius']);
    }

    public function testAVariableOrAStationIsRefusedBeforeAnyCall(): void
    {
        $source = $this->source();
        foreach ([new SeriesQuery(['energy'], new Point(0, 0)), new SeriesQuery(['count'], Identifier::of('wmo', '07190'))] as $query) {
            try {
                $source->series($query);
                self::fail('Refused.');
            } catch (NotSupportedException) {
            }
        }
        self::assertSame([], $this->calls);
    }

    public function testTheLargestAsOccurrencesThenTheNextPage(): void
    {
        $source = $this->source();
        $page = $source->occurrences(new OccurrenceQuery(new BBox(-25, 35, 45, 72), new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-07'), [OccurrenceQuery::MIN_MAGNITUDE => 4], limit: 5, sort: 'magnitude'));

        self::assertCount(5, $page);
        self::assertSame('M 5.0 - CENTRAL TURKEY', $page->first()->label);
        self::assertSame(['5.0', '4.7', '4.7', '4.6', '4.3'], array_map(static fn ($e) => \sprintf('%.1f', $e->value('magnitude')), $page->items));
        self::assertSame('6', $page->next);
        self::assertNull($page->total);
        self::assertSame(['format' => 'json', 'orderby' => 'magnitude', 'starttime' => '2026-10-01T00:00:00', 'endtime' => '2026-10-08T00:00:00', 'limit' => '6', 'minlatitude' => '35', 'maxlatitude' => '72', 'minlongitude' => '-25', 'maxlongitude' => '45', 'minmagnitude' => '4'], $this->calls[0]);

        $source->occurrences(new OccurrenceQuery(new BBox(-25, 35, 45, 72), new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-07'), [OccurrenceQuery::MIN_MAGNITUDE => 4], limit: 5, cursor: $page->next, sort: 'magnitude'));
        self::assertSame('6', $this->calls[1]['offset']);
    }

    public function testAllOfAWeekOnOnePageAndAnOpenStart(): void
    {
        $source = $this->source();
        $page = $source->occurrences(new OccurrenceQuery(new BBox(-25, 35, 45, 72), to: new \DateTimeImmutable('2026-10-07'), filters: [OccurrenceQuery::MIN_MAGNITUDE => 4, 'magnitude_type' => 'mb'], limit: 20, sort: OccurrenceQuery::OLDEST));

        self::assertCount(11, $page);
        self::assertNull($page->next);
        self::assertSame('1900-01-01T00:00:00', $this->calls[0]['starttime']);
        self::assertSame('mb', $this->calls[0]['magnitudetype']);
        self::assertSame('time-asc', $this->calls[0]['orderby']);
    }

    public function testAPointsRadiusAndABoxAcrossTheAntimeridian(): void
    {
        $source = $this->source();
        $source->occurrences(new OccurrenceQuery(new Point(35.6812, 139.7671), radius: 50));
        $source->occurrences(new OccurrenceQuery(new BBox(170, -25, -170, -10)));

        self::assertSame('0.4497', $this->calls[0]['maxradius']);
        self::assertSame(['170', '-170'], [$this->calls[1]['minlongitude'], $this->calls[1]['maxlongitude']]);
    }

    public function testAnUnknownFilterOrSortIsRefusedBeforeAnyCall(): void
    {
        $source = $this->source();
        foreach ([new OccurrenceQuery(filters: [OccurrenceQuery::TAXON => 'x']), new OccurrenceQuery(sort: 'depth'), new OccurrenceQuery(filters: [OccurrenceQuery::KIND => 'earthquake'])] as $query) {
            try {
                $source->occurrences($query);
                self::fail('Refused.');
            } catch (NotSupportedException) {
            }
        }
        self::assertSame([], $this->calls);
    }

    public function testMoreThanTwentyThousandEventsIsTheServicesRefusal(): void
    {
        $source = $this->source(new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/too-much.json'), ['http_code' => 413]));

        try {
            $source->series(new SeriesQuery(['count'], new BBox(-180, -90, 180, 90), new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-09-30'), SeriesQuery::DAILY));
            self::fail('A 413.');
        } catch (ProviderException $e) {
            self::assertStringContainsString('More than 20000 entries', $e->getMessage());
        }
    }

    public function testADownServiceIsUnavailable(): void
    {
        $this->expectException(UnavailableException::class);
        $this->source(new MockResponse('', ['http_code' => 503]))->entity('20261004_0000156');
    }
}
