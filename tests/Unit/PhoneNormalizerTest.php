<?php

namespace Tests\Unit;

use App\Support\PhoneNormalizer;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PhoneNormalizerTest extends TestCase
{
    public function test_normalizes_local_format(): void
    {
        $this->assertSame('6281298765432', PhoneNormalizer::normalize('0812-9876-5432'));
        $this->assertSame('6281298765432', PhoneNormalizer::normalize('081298765432'));
    }

    public function test_normalizes_leading_8_without_zero(): void
    {
        $this->assertSame('6281298765432', PhoneNormalizer::normalize('81298765432'));
    }

    public function test_normalizes_62_prefix(): void
    {
        $this->assertSame('6281298765432', PhoneNormalizer::normalize('6281298765432'));
        $this->assertSame('6281298765432', PhoneNormalizer::normalize('+62 812-9876-5432'));
    }

    public function test_rejects_unknown_format(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PhoneNormalizer::normalize('123');
    }

    public function test_rejects_empty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PhoneNormalizer::normalize('');
    }

    public function test_rejects_too_short(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PhoneNormalizer::normalize('081234');
    }
}
