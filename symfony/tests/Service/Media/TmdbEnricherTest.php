<?php

namespace App\Tests\Service\Media;

use App\Service\Media\TmdbEnricher;
use PHPUnit\Framework\TestCase;

/**
 * The enricher is a deliberate copy of TmdbController::enrich(), which is
 * upstream-owned and private. These tests pin the behaviour the copy must
 * keep, so a future upstream change that alters the original is visible here
 * rather than silently diverging.
 */
class TmdbEnricherTest extends TestCase
{
    public function testItMapsAMovieAndMarksItInTheLibrary(): void
    {
        $enricher = new TmdbEnricher();

        $out = $enricher->enrich(
            [[
                'id'           => 603,
                'media_type'   => 'movie',
                'title'        => 'The Matrix',
                'release_date' => '1999-03-31',
                'vote_average' => 8.216,
            ]],
            ['movie' => [603 => ['id' => 42, 'status' => 'downloaded']], 'tv' => []],
        );

        $this->assertCount(1, $out);
        $this->assertSame(603, $out[0]['id']);
        $this->assertSame('movie', $out[0]['type']);
        $this->assertSame('The Matrix', $out[0]['title']);
        $this->assertSame(1999, $out[0]['year']);
        $this->assertSame(8.2, $out[0]['vote']);
        $this->assertTrue($out[0]['in_library']);
        $this->assertSame('downloaded', $out[0]['lib_status']);
        $this->assertSame(42, $out[0]['lib_id']);
    }

    public function testItReadsTvTitlesAndDatesFromTheirOwnKeys(): void
    {
        $enricher = new TmdbEnricher();

        $out = $enricher->enrich(
            [['id' => 1399, 'name' => 'Game of Thrones', 'first_air_date' => '2011-04-17']],
            ['movie' => [], 'tv' => ['tmdb_1399' => ['id' => 7, 'status' => 'partial']]],
            'tv',
        );

        $this->assertSame('Game of Thrones', $out[0]['title']);
        $this->assertSame(2011, $out[0]['year']);
        $this->assertSame('partial', $out[0]['lib_status']);
    }

    public function testItDropsItemsThatAreNeitherMovieNorTv(): void
    {
        $enricher = new TmdbEnricher();

        $out = $enricher->enrich(
            [['id' => 5, 'media_type' => 'person', 'name' => 'Keanu Reeves']],
            ['movie' => [], 'tv' => []],
        );

        $this->assertSame([], $out);
    }

    public function testItLeavesLibraryFieldsEmptyForAnUnknownTitle(): void
    {
        $enricher = new TmdbEnricher();

        $out = $enricher->enrich(
            [['id' => 999, 'media_type' => 'movie', 'title' => 'Nope']],
            ['movie' => [], 'tv' => []],
        );

        $this->assertFalse($out[0]['in_library']);
        $this->assertNull($out[0]['lib_status']);
        $this->assertNull($out[0]['lib_id']);
        $this->assertNull($out[0]['year']);
    }
}
