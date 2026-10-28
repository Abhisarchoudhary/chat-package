<?php

namespace Revun\Chat\Tests;

use PHPUnit\Framework\TestCase;
use Revun\Chat\StreamUsers;

/**
 * Who is in the directory everybody browses, and who is only reachable.
 *
 * An institution's login has to be in Stream — its account manager writes to
 * it and it writes back — and being in Stream put somebody from outside the
 * company in the one list four portals browse, found by anybody in any of
 * them who happened to scroll.
 *
 * The portal that holds a person is the only one that knows which sort they
 * are, so it says so on the record itself (`ChatParticipant::chatListed`) and
 * every portal reads the same answer.
 */
class DirectoryListingTest extends TestCase
{
    public function test_somebody_no_portal_lists_is_not_in_the_directory(): void
    {
        $this->assertFalse(StreamUsers::listed([
            'id' => 'u_1',
            'organisations' => ['otr' => true],
            'listed' => ['otr' => false],
        ]));
    }

    public function test_a_colleague_is_in_the_directory(): void
    {
        $this->assertTrue(StreamUsers::listed([
            'id' => 'u_2',
            'organisations' => ['otr' => true],
            'listed' => ['otr' => true],
        ]));
    }

    public function test_one_portal_listing_them_is_enough(): void
    {
        /*
         * Somebody who recruits for us and rents from us is a colleague, and
         * a colleague is in the directory. It takes every portal holding them
         * to say no.
         */
        $this->assertTrue(StreamUsers::listed([
            'id' => 'u_3',
            'listed' => ['otr' => false, 'crp' => true],
        ]));
    }

    public function test_every_portal_has_to_say_no(): void
    {
        $this->assertFalse(StreamUsers::listed([
            'id' => 'u_4',
            'listed' => ['otr' => false, 'rypm' => false],
        ]));
    }

    public function test_somebody_written_before_the_field_existed_is_listed(): void
    {
        /*
         * The whole directory predates this field. Reading a missing answer
         * as "hide them" would empty four directories on the day it shipped,
         * and go on emptying them for every portal a version behind.
         */
        $this->assertTrue(StreamUsers::listed(['id' => 'u_5', 'organisations' => ['rypm' => true]]));
    }

    public function test_a_field_that_is_not_a_map_is_not_an_answer(): void
    {
        // Whatever this is, it is not four portals having said no.
        $this->assertTrue(StreamUsers::listed(['id' => 'u_6', 'listed' => true]));
        $this->assertTrue(StreamUsers::listed(['id' => 'u_7', 'listed' => null]));
    }

    public function test_an_empty_map_is_nobody_listing_them(): void
    {
        $this->assertFalse(StreamUsers::listed(['id' => 'u_8', 'listed' => []]));
    }
}
