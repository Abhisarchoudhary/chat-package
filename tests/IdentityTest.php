<?php

namespace Revun\Chat\Tests;

use PHPUnit\Framework\TestCase;
use Revun\Chat\Identity;

/**
 * The one rule the whole system stands on: three portals, one person, one id.
 */
class IdentityTest extends TestCase
{
    public function test_the_same_address_is_the_same_person_however_it_is_written(): void
    {
        $id = Identity::forEmail('Priya.Sharma@RoyalYorkPM.com');

        $this->assertSame($id, Identity::forEmail('priya.sharma@royalyorkpm.com'));
        $this->assertSame($id, Identity::forEmail('  Priya.Sharma@royalyorkpm.com  '));
    }

    public function test_two_people_are_two_ids(): void
    {
        $this->assertNotSame(
            Identity::forEmail('priya@royalyorkpm.com'),
            Identity::forEmail('priya@msr.com'),
        );
    }

    public function test_the_id_is_something_stream_will_accept(): void
    {
        $id = Identity::forEmail('a.person+tag@example.co.uk');

        // Stream allows a-z A-Z 0-9 @ _ - and at most 64 characters.
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9@_-]{1,64}$/', $id);
        $this->assertTrue(Identity::looksLikeOurs($id));
    }

    public function test_an_id_from_outside_is_recognised_before_it_is_trusted(): void
    {
        $this->assertFalse(Identity::looksLikeOurs('admin'));
        $this->assertFalse(Identity::looksLikeOurs('u_short'));
        $this->assertFalse(Identity::looksLikeOurs('u_'.str_repeat('z', 30)));
    }

    public function test_an_empty_address_is_refused_rather_than_hashed(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Identity::forEmail('   ');
    }
}
