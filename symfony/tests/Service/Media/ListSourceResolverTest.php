<?php

namespace App\Tests\Service\Media;

use App\Service\Media\Discover\ListSourceResolver;
use PHPUnit\Framework\TestCase;

class ListSourceResolverTest extends TestCase
{
    public function testParsesAnMdblistListUrl(): void
    {
        $parsed = ListSourceResolver::parse('https://mdblist.com/lists/garycrawfordgc/top-horror-2025');

        $this->assertSame('mdblist', $parsed['source']);
        $this->assertSame('garycrawfordgc', $parsed['user']);
        $this->assertSame('top-horror-2025', $parsed['slug']);
    }

    public function testTrailingSlashesAndQueryStringsAreTolerated(): void
    {
        $parsed = ListSourceResolver::parse('https://mdblist.com/lists/bob/my-list/?sort=rank');

        $this->assertSame('bob', $parsed['user']);
        $this->assertSame('my-list', $parsed['slug']);
    }

    /**
     * Phase 1 supports MDBList only. Trakt arrives in phase 2 and Letterboxd
     * plus IMDb in phase 5, so those must be refused clearly rather than half
     * accepted.
     */
    public function testUnsupportedHostsReturnNull(): void
    {
        $this->assertNull(ListSourceResolver::parse('https://letterboxd.com/dave/list/best-of-2024/'));
        $this->assertNull(ListSourceResolver::parse('https://trakt.tv/users/dave/lists/best'));
        $this->assertNull(ListSourceResolver::parse('not a url at all'));
        $this->assertNull(ListSourceResolver::parse(''));
    }
}
