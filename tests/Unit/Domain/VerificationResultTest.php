<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use Semitexa\Webhooks\Domain\Model\VerificationResult;

final class VerificationResultTest extends TestCase
{
    public function testSuccessFactory(): void
    {
        $result = VerificationResult::success();

        self::assertTrue($result->verified);
        self::assertSame('Signature verified', $result->reason);
    }

    public function testFailureFactory(): void
    {
        $result = VerificationResult::failure('Bad signature');

        self::assertFalse($result->verified);
        self::assertSame('Bad signature', $result->reason);
    }

    public function testNotRequiredFactory(): void
    {
        $result = VerificationResult::notRequired();

        self::assertTrue($result->verified);
        self::assertSame('Verification not required', $result->reason);
    }
}
