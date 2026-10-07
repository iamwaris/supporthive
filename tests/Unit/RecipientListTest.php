<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Mail\RecipientList;
use PHPUnit\Framework\TestCase;

final class RecipientListTest extends TestCase
{
    public function testAcceptsNewlinesCommasAndSemicolons(): void
    {
        $list = RecipientList::parse("a@example.com\r\nb@example.com, c@example.com;d@example.com");

        self::assertTrue($list->isValid());
        self::assertSame(['a@example.com', 'b@example.com', 'c@example.com', 'd@example.com'], $list->emails);
    }

    public function testDuplicatesAreDroppedCaseInsensitively(): void
    {
        $list = RecipientList::parse("Owner@Example.com\nowner@example.com\nOWNER@EXAMPLE.COM");

        self::assertTrue($list->isValid());
        self::assertSame(['Owner@Example.com'], $list->emails);
    }

    public function testEachInvalidAddressIsReported(): void
    {
        $list = RecipientList::parse("good@example.com\nnot-an-email\nalso@bad");

        self::assertFalse($list->isValid());
        self::assertCount(2, $list->errors);
        self::assertStringContainsString('not-an-email', $list->errors[0]);
        self::assertSame(['good@example.com'], $list->emails);
    }

    public function testTheCountIsCapped(): void
    {
        $addresses = [];
        for ($i = 1; $i <= RecipientList::MAX_RECIPIENTS + 1; $i++) {
            $addresses[] = 'person' . $i . '@example.com';
        }

        $list = RecipientList::parse(implode("\n", $addresses));

        self::assertFalse($list->isValid());
        self::assertStringContainsString('at most ' . RecipientList::MAX_RECIPIENTS, $list->errors[0]);
    }

    public function testExactlyTheCapIsAllowed(): void
    {
        $addresses = [];
        for ($i = 1; $i <= RecipientList::MAX_RECIPIENTS; $i++) {
            $addresses[] = 'person' . $i . '@example.com';
        }

        self::assertTrue(RecipientList::parse(implode(',', $addresses))->isValid());
    }

    public function testEmptyInputIsAValidEmptyList(): void
    {
        $list = RecipientList::parse("  \n ");

        self::assertTrue($list->isValid());
        self::assertSame([], $list->emails);
        self::assertSame('', $list->toSetting());
    }

    public function testStoredFormIsOnePerLine(): void
    {
        $list = RecipientList::parse('a@example.com, b@example.com');

        self::assertSame("a@example.com\nb@example.com", $list->toSetting());
    }
}
