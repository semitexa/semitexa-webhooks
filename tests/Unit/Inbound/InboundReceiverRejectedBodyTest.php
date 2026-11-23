<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Tests\Unit\Inbound;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Webhooks\Application\Service\Inbound\HmacSha256SignatureVerifier;
use Semitexa\Webhooks\Application\Service\Inbound\InboundDedupeKeyFactory;
use Semitexa\Webhooks\Application\Service\Inbound\InboundWebhookReceiver;
use Semitexa\Webhooks\Application\Service\Inbound\InboundWebhookService;
use Semitexa\Webhooks\Domain\Contract\WebhookAttemptRepositoryInterface;
use Semitexa\Webhooks\Domain\Contract\WebhookEndpointDefinitionRepositoryInterface;
use Semitexa\Webhooks\Domain\Enum\InboundStatus;
use Semitexa\Webhooks\Domain\Enum\WebhookDirection;
use Semitexa\Webhooks\Domain\Model\InboundWebhookEnvelope;
use Semitexa\Webhooks\Domain\Model\WebhookEndpointDefinition;
use Semitexa\Webhooks\Tests\Unit\Inbound\Fixtures\KeepingInbox;

/**
 * The receiver decoded the whole JSON body of every request to name its event
 * type, a rejected one too, before the signature result counted. A 2 MB body
 * of small objects took 106 MB and killed the worker under its 128 MB limit:
 * one unsigned request from anyone (tk-webhooks-unsigned-body-decoded-before-signature).
 * Only a verified body is decoded now; a header still names a type.
 */
final class InboundReceiverRejectedBodyTest extends TestCase
{
    private const SECRET = 'partner-secret';

    #[Test]
    public function a_rejected_body_is_not_decoded_for_its_type(): void
    {
        $rejected = $this->receiver()->receive($this->envelope('{"type":"invoice.paid"}', signature: 'forged'));

        self::assertSame(InboundStatus::RejectedSignature, $rejected->getStatus());
        self::assertNull($rejected->getParsedEventType());
    }

    #[Test]
    public function a_verified_body_still_names_its_type_and_a_header_names_it_for_either(): void
    {
        self::assertSame('invoice.paid', $this->receiver()->receive($this->envelope('{"type":"invoice.paid"}'))->getParsedEventType());

        $rejected = $this->receiver()->receive($this->envelope('{}', signature: 'forged', type: 'refund.created'));
        self::assertSame('refund.created', $rejected->getParsedEventType(), 'a header costs what it weighs');
    }

    #[Test]
    public function an_unsigned_body_of_many_small_objects_costs_about_its_bytes(): void
    {
        // About 480 KB; decoded, PHP's arrays would take some 25 MB.
        $body = '[' . implode(',', array_fill(0, 60000, '{"a":1}')) . ']';
        $envelope = $this->envelope($body, signature: 'forged');
        $receiver = $this->receiver();

        memory_reset_peak_usage();
        $before = memory_get_usage();
        $delivery = $receiver->receive($envelope);
        $grew = memory_get_peak_usage() - $before;

        self::assertSame(InboundStatus::RejectedSignature, $delivery->getStatus());
        self::assertLessThan(8 * 1024 * 1024, $grew, sprintf('receiving a rejected %d-byte body took %d bytes', strlen($body), $grew));
    }

    private function envelope(string $body, ?string $signature = null, ?string $type = null): InboundWebhookEnvelope
    {
        $ts = (string) time();

        return new InboundWebhookEnvelope(
            endpointKey: 'partner',
            httpMethod: 'POST',
            requestUri: '/webhooks/partner',
            headers: [
                'X-Webhook-Event-Id' => 'evt-1',
                'X-Webhook-Timestamp' => $ts,
                'X-Webhook-Signature' => 'sha256=' . ($signature ?? hash_hmac('sha256', $ts . '.' . $body, self::SECRET)),
                ...($type !== null ? ['X-Webhook-Event-Type' => $type] : []),
            ],
            rawBody: $body,
            contentType: 'application/json',
        );
    }

    private function receiver(): InboundWebhookReceiver
    {
        $endpoints = $this->createStub(WebhookEndpointDefinitionRepositoryInterface::class);
        $endpoints->method('findByEndpointKey')->willReturn(new WebhookEndpointDefinition(
            id: 'ep-in', endpointKey: 'partner', direction: WebhookDirection::Inbound, providerKey: 'partner',
            enabled: true, tenantId: null, verificationMode: 'hmac-sha256', signingMode: null, secretRef: self::SECRET,
            targetUrl: null, timeoutSeconds: 5, maxAttempts: 5, initialBackoffSeconds: 30, maxBackoffSeconds: 3600,
            dedupeWindowSeconds: null, handlerClass: null, defaultHeaders: null, metadata: null,
            createdAt: new \DateTimeImmutable(), updatedAt: new \DateTimeImmutable(),
        ));
        $inbox = new KeepingInbox();
        $service = new InboundWebhookService();
        (new \ReflectionProperty(InboundWebhookService::class, 'inboundRepo'))->setValue($service, $inbox);
        (new \ReflectionProperty(InboundWebhookService::class, 'attemptRepo'))->setValue($service, $this->createStub(WebhookAttemptRepositoryInterface::class));

        $receiver = new InboundWebhookReceiver();
        foreach ([
            'endpointRepo' => $endpoints,
            'verifier' => new HmacSha256SignatureVerifier(),
            'inboxRepo' => $inbox,
            'inboundService' => $service,
            'dedupeKeyFactory' => new InboundDedupeKeyFactory(),
        ] as $property => $value) {
            (new \ReflectionProperty(InboundWebhookReceiver::class, $property))->setValue($receiver, $value);
        }

        return $receiver;
    }
}
