<?php

namespace Revun\Chat\Tests;

use PHPUnit\Framework\TestCase;
use Revun\Chat\StreamUsers;

/**
 * The shape of a partial update, which is what keeps four portals out of each
 * other's way — and the one case in which the careful form is impossible.
 *
 * Stream refuses a `set` naming `titles.otr` when the person has no `titles`
 * at all: `400 no such field: titles`, and it refuses the **whole** request,
 * so the name and the photograph beside it are lost too. Every person written
 * before a field existed is in that state, so adding one field stopped a live
 * directory updating at all — while the sync reported success, because the
 * refusal was only ever a line in a log file.
 */
class PartialUpdateTest extends TestCase
{
    public function test_a_field_nobody_holds_yet_is_written_as_the_whole_parent(): void
    {
        $rooted = StreamUsers::rooted(
            ['titles.otr' => 'Leasing Manager'],
            ['id' => 'u_1', 'departments' => ['otr' => 'Leasing']],
        );

        $this->assertSame(['titles' => ['otr' => 'Leasing Manager']], $rooted);
    }

    public function test_a_field_that_is_already_there_stays_dotted(): void
    {
        // Written whole, this would replace Royal York's and Bridgepoint's
        // entries with ours — which is the whole thing the dotted form avoids.
        $rooted = StreamUsers::rooted(
            ['titles.otr' => 'Leasing Manager'],
            ['id' => 'u_1', 'titles' => ['rypm' => 'Property Manager']],
        );

        $this->assertSame(['titles.otr' => 'Leasing Manager'], $rooted);
    }

    public function test_the_plain_fields_are_left_alone(): void
    {
        $rooted = StreamUsers::rooted(
            ['name' => 'Amelia Naidoo', 'image' => 'https://example.test/p.jpg'],
            [],
        );

        $this->assertSame(['name' => 'Amelia Naidoo', 'image' => 'https://example.test/p.jpg'], $rooted);
    }

    public function test_several_keys_under_one_missing_parent_arrive_together(): void
    {
        // Two portals' worth in one write would otherwise be two writes, the
        // second of which replaces the first.
        $rooted = StreamUsers::rooted(
            ['titles.otr' => 'Leasing Manager', 'titles.rypm' => 'Property Manager'],
            [],
        );

        $this->assertSame(['titles' => ['otr' => 'Leasing Manager', 'rypm' => 'Property Manager']], $rooted);
    }

    public function test_a_parent_that_is_there_but_not_an_object_is_written_whole(): void
    {
        // Stream answers the same way for a field that is not a map, and a
        // dotted write into it cannot work either.
        $rooted = StreamUsers::rooted(['titles.otr' => 'Leasing Manager'], ['titles' => null]);

        $this->assertSame(['titles' => ['otr' => 'Leasing Manager']], $rooted);
    }

    public function test_a_whole_sync_set_is_rooted_only_where_it_has_to_be(): void
    {
        $rooted = StreamUsers::rooted([
            'name' => 'Amelia Naidoo',
            'image' => 'https://crm.example.com/chat/employees/18/photo',
            'email' => 'amelia@example.test',
            'organisations.otr' => true,
            'departments.otr' => null,
            'titles.otr' => 'Leasing Manager',
        ], [
            'id' => 'u_1',
            'organisations' => ['otr' => true],
            'departments' => ['otr' => 'Leasing'],
        ]);

        $this->assertSame([
            'name' => 'Amelia Naidoo',
            'image' => 'https://crm.example.com/chat/employees/18/photo',
            'email' => 'amelia@example.test',
            'organisations.otr' => true,
            'departments.otr' => null,
            'titles' => ['otr' => 'Leasing Manager'],
        ], $rooted);
    }
}
