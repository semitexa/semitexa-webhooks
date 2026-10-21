<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Request;
use Semitexa\Webhooks\Auth\Attribute\AsWebhookReceiver;
use Semitexa\Webhooks\Auth\WebhookAuthHandler;
use Semitexa\Webhooks\Domain\Contract\WebhookSignatureVerifierInterface;
use Semitexa\Webhooks\Domain\Model\VerificationResult;
use Semitexa\Webhooks\Domain\Model\WebhookVerificationInput;

final class WebhookAuthHandlerEmptyModeTest extends TestCase
{
    #[Test]
    public function a_receiver_that_verifies_nothing_authenticates_nobody(): void
    {
        // verificationMode '' verified nothing and still answered with a
        // service principal: an unsigned request reached a service route.
        $handler = new WebhookAuthHandler();
        (new \ReflectionProperty(WebhookAuthHandler::class, 'verifier'))->setValue($handler, new class implements WebhookSignatureVerifierInterface {
            public function verify(WebhookVerificationInput $input): VerificationResult
            {
                return VerificationResult::notRequired();
            }
        });

        $this->expectException(\Throwable::class);
        $this->expectExceptionMessage('empty verificationMode');
        $handler->handle(new UnverifiedReceiverPayload(new Request('POST', '/hooks/open', [], [], [], [], [], '{}')));
    }
}

#[AsWebhookReceiver(secretRef: 'env:UNUSED', verificationMode: '')]
final class UnverifiedReceiverPayload
{
    public function __construct(private readonly Request $request) {}

    public function getHttpRequest(): Request
    {
        return $this->request;
    }
}
