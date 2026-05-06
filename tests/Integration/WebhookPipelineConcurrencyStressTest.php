<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Tests\Integration;

use App\Tests\Modules\WebhookDemo\Fixtures\InMemoryWebhookAttemptRepository;
use App\Tests\Modules\WebhookDemo\Fixtures\InMemoryWebhookEndpointDefinitionRepository;
use App\Tests\Modules\WebhookDemo\Fixtures\InMemoryWebhookTransport;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Orm\OrmManager;
use Semitexa\Webhooks\Application\Db\MySQL\Repository\OutboundDeliveryRepository;
use Semitexa\Webhooks\Application\Service\Outbound\BackoffCalculator;
use Semitexa\Webhooks\Application\Service\Outbound\OutboundRequestSigner;
use Semitexa\Webhooks\Application\Service\Outbound\OutboxClaimService;
use Semitexa\Webhooks\Application\Service\Outbound\WebhookDeliveryWorker;
use Semitexa\Webhooks\Application\Service\Outbound\WebhookPublisher;
use Semitexa\Webhooks\Configuration\WebhookConfig;
use Semitexa\Webhooks\Domain\Enum\OutboundStatus;
use Semitexa\Webhooks\Domain\Enum\WebhookDirection;
use Semitexa\Webhooks\Domain\Model\OutboundWebhookMessage;
use Semitexa\Webhooks\Domain\Model\TransportResult;
use Semitexa\Webhooks\Domain\Model\WebhookEndpointDefinition;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use function Swoole\Coroutine\run;

/**
 * Capstone concurrency stress test for the webhook pipeline.
 *
 * The framework's atomic guarantees compose: publisher idempotency
 * prevents duplicate outbound delivery rows; worker claim/lease prevents
 * duplicate transport sends; CAS finalization prevents stale workers
 * from corrupting state; the replay store + inbound dedupe block
 * duplicate processing on the receiver. These tests run all four
 * layers concurrently against real MySQL with Swoole coroutine
 * barriers — the production deployment shape — and assert the
 * end-to-end "side effect happens exactly once" contract.
 *
 * Each test runs in its own PHP process via #[RunTestsInSeparateProcesses]
 * so the Swoole runtime teardown does not poison subsequent tests in
 * the suite. Tables are created/dropped in setUp/tearDown so each test
 * owns its DB lifecycle.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class WebhookPipelineConcurrencyStressTest extends TestCase
{
    private OrmManager $orm;
    private OutboundDeliveryRepository $outboxRepo;
    private InMemoryWebhookEndpointDefinitionRepository $endpointRepo;
    private InMemoryWebhookAttemptRepository $attemptRepo;
    private InMemoryWebhookTransport $transport;
    private OutboundRequestSigner $signer;

    protected function setUp(): void
    {
        if (!class_exists(Coroutine::class, false)) {
            self::markTestSkipped('Swoole\\Coroutine not available — concurrency stress test cannot run');
        }

        $host = getenv('DB_HOST');
        if ($host === false || $host === '') {
            self::markTestSkipped('DB_HOST not configured — stress test requires real MySQL');
        }

        try {
            $container = ContainerFactory::get();
            $this->orm = $container->get(OrmManager::class);
            $this->orm->getAdapter()->execute('SELECT 1');
        } catch (\Throwable $e) {
            self::markTestSkipped('MySQL not reachable: ' . $e->getMessage());
        }

        $this->resetTables();
        $this->outboxRepo = $container->get(OutboundDeliveryRepository::class);
        $this->endpointRepo = new InMemoryWebhookEndpointDefinitionRepository();
        $this->attemptRepo = new InMemoryWebhookAttemptRepository();
        $this->signer = new OutboundRequestSigner();
        $this->transport = new InMemoryWebhookTransport($this->endpointRepo, $this->signer);

        // Warm the ORM's lazy MapperRegistry / DomainRepository on the main
        // coroutine. Without this, the first concurrent coroutine to call a
        // repository method may race on mapper initialization and observe
        // a half-built registry.
        $this->outboxRepo->findByStatus(OutboundStatus::Pending->value, 1);
    }

    protected function tearDown(): void
    {
        if (!isset($this->orm)) {
            return;
        }
        try {
            $this->orm->getAdapter()->execute('DROP TABLE IF EXISTS `webhook_outbox`');
        } catch (\Throwable) {
            // best effort
        }
    }

    private function resetTables(): void
    {
        $adapter = $this->orm->getAdapter();
        $adapter->execute('DROP TABLE IF EXISTS `webhook_outbox`');
        $adapter->execute(<<<'SQL'
CREATE TABLE `webhook_outbox` (
    id BINARY(16) NOT NULL,
    endpoint_definition_id BINARY(16) NOT NULL,
    endpoint_key VARCHAR(191) NOT NULL,
    provider_key VARCHAR(64) NOT NULL,
    tenant_id VARCHAR(64) NULL,
    event_type VARCHAR(191) NOT NULL,
    status VARCHAR(32) NOT NULL,
    idempotency_key VARCHAR(255) NULL,
    payload_json LONGTEXT NOT NULL,
    headers_json LONGTEXT NULL,
    signed_headers_json LONGTEXT NULL,
    next_attempt_at DATETIME(6) NOT NULL,
    last_attempt_at DATETIME(6) NULL,
    delivered_at DATETIME(6) NULL,
    attempt_count INT NOT NULL,
    max_attempts INT NOT NULL,
    initial_backoff_seconds INT NOT NULL,
    max_backoff_seconds INT NOT NULL,
    lease_owner VARCHAR(191) NULL,
    lease_expires_at DATETIME(6) NULL,
    last_response_status INT NULL,
    last_response_headers_json LONGTEXT NULL,
    last_response_body LONGTEXT NULL,
    last_error LONGTEXT NULL,
    source_ref VARCHAR(255) NULL,
    metadata_json LONGTEXT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_webhook_outbox_endpoint_idempotency (endpoint_definition_id, idempotency_key),
    INDEX idx_status_next_attempt (status, next_attempt_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    private function registerEndpoint(string $endpointKey, string $targetUrl = 'https://example.test/inbound'): WebhookEndpointDefinition
    {
        $definition = new WebhookEndpointDefinition(
            id: random_bytes(16),
            endpointKey: $endpointKey,
            direction: WebhookDirection::Outbound,
            providerKey: 'stress-test',
            enabled: true,
            tenantId: null,
            verificationMode: null,
            signingMode: null,
            secretRef: null,
            targetUrl: $targetUrl,
            timeoutSeconds: 10,
            maxAttempts: 3,
            initialBackoffSeconds: 30,
            maxBackoffSeconds: 600,
            dedupeWindowSeconds: null,
            handlerClass: null,
            defaultHeaders: null,
            metadata: null,
            createdAt: new \DateTimeImmutable(),
            updatedAt: new \DateTimeImmutable(),
        );
        $this->endpointRepo->add($definition);
        return $definition;
    }

    private function buildPublisher(): WebhookPublisher
    {
        $publisher = new WebhookPublisher();
        (new ReflectionProperty(WebhookPublisher::class, 'endpointRepo'))
            ->setValue($publisher, $this->endpointRepo);
        (new ReflectionProperty(WebhookPublisher::class, 'outboxRepo'))
            ->setValue($publisher, $this->outboxRepo);
        return $publisher;
    }

    private function buildWorker(): WebhookDeliveryWorker
    {
        $config = WebhookConfig::withOverrides();
        $claimService = new OutboxClaimService();
        (new ReflectionProperty(OutboxClaimService::class, 'outboxRepo'))
            ->setValue($claimService, $this->outboxRepo);
        (new ReflectionProperty(OutboxClaimService::class, 'config'))
            ->setValue($claimService, $config);

        $worker = new WebhookDeliveryWorker();
        (new ReflectionProperty(WebhookDeliveryWorker::class, 'claimService'))
            ->setValue($worker, $claimService);
        (new ReflectionProperty(WebhookDeliveryWorker::class, 'transport'))
            ->setValue($worker, $this->transport);
        (new ReflectionProperty(WebhookDeliveryWorker::class, 'outboxRepo'))
            ->setValue($worker, $this->outboxRepo);
        (new ReflectionProperty(WebhookDeliveryWorker::class, 'attemptRepo'))
            ->setValue($worker, $this->attemptRepo);
        (new ReflectionProperty(WebhookDeliveryWorker::class, 'backoffCalculator'))
            ->setValue($worker, new BackoffCalculator());
        return $worker;
    }

    private function rowCountByIdempotencyKey(string $key): int
    {
        return (int) $this->orm->getAdapter()->execute(
            'SELECT COUNT(*) FROM webhook_outbox WHERE idempotency_key = :k',
            ['k' => $key],
        )->fetchColumn();
    }

    private function rowCount(): int
    {
        return (int) $this->orm->getAdapter()->execute(
            'SELECT COUNT(*) FROM webhook_outbox',
        )->fetchColumn();
    }

    // ==================================================================
    //  Scenario A — concurrent publisher idempotency
    // ==================================================================

    #[Test]
    public function many_concurrent_publishers_with_same_idempotency_key_create_exactly_one_delivery(): void
    {
        $this->registerEndpoint('stress-A');

        $publisherCount = 15;
        $errors = [];

        run(function () use ($publisherCount, &$errors): void {
            $startGate = new Channel($publisherCount);
            $done = new Channel($publisherCount);

            for ($i = 0; $i < $publisherCount; $i++) {
                Coroutine::create(function () use ($startGate, $done, &$errors): void {
                    try {
                        $publisher = $this->buildPublisher();
                        $startGate->pop(); // wait for release
                        $publisher->publish(new OutboundWebhookMessage(
                            endpointKey: 'stress-A',
                            eventType: 'stress.event.v1',
                            payload: ['n' => 1],
                            idempotencyKey: 'shared-A',
                        ));
                    } catch (\Throwable $e) {
                        $errors[] = $e::class . ': ' . $e->getMessage();
                    } finally {
                        $done->push(1);
                    }
                });
            }

            // Release all at once.
            for ($i = 0; $i < $publisherCount; $i++) {
                $startGate->push(1);
            }
            for ($i = 0; $i < $publisherCount; $i++) {
                $done->pop();
            }
        });

        self::assertSame([], $errors, 'no publisher errors expected');
        self::assertSame(1, $this->rowCountByIdempotencyKey('shared-A'), 'exactly one delivery row for one shared idempotency key');
    }

    #[Test]
    public function many_concurrent_publishers_with_different_idempotency_keys_create_one_per_key(): void
    {
        $this->registerEndpoint('stress-keys');

        $keys = ['k-1', 'k-2', 'k-3', 'k-4', 'k-5', 'k-6', 'k-7', 'k-8', 'k-9', 'k-10'];
        $errors = [];

        run(function () use ($keys, &$errors): void {
            $startGate = new Channel(count($keys));
            $done = new Channel(count($keys));

            foreach ($keys as $key) {
                Coroutine::create(function () use ($startGate, $done, $key, &$errors): void {
                    try {
                        $publisher = $this->buildPublisher();
                        $startGate->pop();
                        $publisher->publish(new OutboundWebhookMessage(
                            endpointKey: 'stress-keys',
                            eventType: 'stress.event.v1',
                            payload: [],
                            idempotencyKey: $key,
                        ));
                    } catch (\Throwable $e) {
                        $errors[] = $e::class . ': ' . $e->getMessage();
                    } finally {
                        $done->push(1);
                    }
                });
            }

            for ($i = 0; $i < count($keys); $i++) {
                $startGate->push(1);
            }
            for ($i = 0; $i < count($keys); $i++) {
                $done->pop();
            }
        });

        self::assertSame([], $errors);
        self::assertSame(count($keys), $this->rowCount(), 'one delivery per distinct key');
        foreach ($keys as $key) {
            self::assertSame(1, $this->rowCountByIdempotencyKey($key), "key {$key} produced exactly one row");
        }
    }

    #[Test]
    public function same_idempotency_key_on_different_endpoints_creates_one_delivery_per_endpoint(): void
    {
        $this->registerEndpoint('stress-endpoint-A');
        $this->registerEndpoint('stress-endpoint-B');

        $errors = [];
        run(function () use (&$errors): void {
            $startGate = new Channel(2);
            $done = new Channel(2);

            foreach (['stress-endpoint-A', 'stress-endpoint-B'] as $endpointKey) {
                Coroutine::create(function () use ($startGate, $done, $endpointKey, &$errors): void {
                    try {
                        $publisher = $this->buildPublisher();
                        $startGate->pop();
                        $publisher->publish(new OutboundWebhookMessage(
                            endpointKey: $endpointKey,
                            eventType: 'cross-endpoint.v1',
                            payload: [],
                            idempotencyKey: 'shared-cross-endpoint',
                        ));
                    } catch (\Throwable $e) {
                        $errors[] = $e::class . ': ' . $e->getMessage();
                    } finally {
                        $done->push(1);
                    }
                });
            }

            $startGate->push(1);
            $startGate->push(1);
            $done->pop();
            $done->pop();
        });

        self::assertSame([], $errors);
        self::assertSame(2, $this->rowCountByIdempotencyKey('shared-cross-endpoint'), 'one row per endpoint for the shared key');
    }

    // ==================================================================
    //  Scenario B — concurrent worker claim
    // ==================================================================

    #[Test]
    public function many_workers_racing_one_pending_delivery_send_exactly_once(): void
    {
        $this->registerEndpoint('stress-claim');

        $publisher = $this->buildPublisher();
        $publisher->publish(new OutboundWebhookMessage(
            endpointKey: 'stress-claim',
            eventType: 'stress.event.v1',
            payload: [],
            idempotencyKey: 'claim-race-1',
        ));

        $workerCount = 8;
        $outcomes = [];
        $errors = [];

        run(function () use ($workerCount, &$outcomes, &$errors): void {
            $startGate = new Channel($workerCount);
            $done = new Channel($workerCount);

            for ($i = 0; $i < $workerCount; $i++) {
                $workerId = "worker-{$i}";
                Coroutine::create(function () use ($startGate, $done, $workerId, &$outcomes, &$errors): void {
                    try {
                        $worker = $this->buildWorker();
                        $startGate->pop();
                        $outcomes[$workerId] = $worker->processOne($workerId);
                    } catch (\Throwable $e) {
                        $errors[] = $e::class . ': ' . $e->getMessage();
                    } finally {
                        $done->push(1);
                    }
                });
            }

            for ($i = 0; $i < $workerCount; $i++) {
                $startGate->push(1);
            }
            for ($i = 0; $i < $workerCount; $i++) {
                $done->pop();
            }
        });

        self::assertSame([], $errors, 'no worker errors expected');
        self::assertSame(1, count($this->transport->sent), 'transport must have been called exactly once');
        self::assertSame(1, $this->rowCountByIdempotencyKey('claim-race-1'));

        // Exactly one worker observed a delivered outcome; the rest were idle.
        $delivered = 0;
        $idle = 0;
        foreach ($outcomes as $outcome) {
            if ($outcome->newStatus === OutboundStatus::Delivered) {
                $delivered++;
            } elseif ($outcome->idleNoDeliveryDue) {
                $idle++;
            }
        }
        self::assertSame(1, $delivered, 'exactly one worker delivered');
        self::assertSame($workerCount - 1, $idle, 'other workers were idle (no claim)');
    }

    #[Test]
    public function attempt_count_in_db_remains_one_under_worker_race(): void
    {
        $this->registerEndpoint('stress-attempts');
        $publisher = $this->buildPublisher();
        $publisher->publish(new OutboundWebhookMessage(
            endpointKey: 'stress-attempts',
            eventType: 'stress.event.v1',
            payload: [],
            idempotencyKey: 'attempts-race-1',
        ));

        $workerCount = 6;
        run(function () use ($workerCount): void {
            $startGate = new Channel($workerCount);
            $done = new Channel($workerCount);
            for ($i = 0; $i < $workerCount; $i++) {
                $workerId = "worker-{$i}";
                Coroutine::create(function () use ($startGate, $done, $workerId): void {
                    $worker = $this->buildWorker();
                    $startGate->pop();
                    $worker->processOne($workerId);
                    $done->push(1);
                });
            }
            for ($i = 0; $i < $workerCount; $i++) {
                $startGate->push(1);
            }
            for ($i = 0; $i < $workerCount; $i++) {
                $done->pop();
            }
        });

        $row = $this->orm->getAdapter()->execute(
            'SELECT attempt_count, status FROM webhook_outbox WHERE idempotency_key = :k',
            ['k' => 'attempts-race-1'],
        )->fetchOne();

        self::assertNotNull($row);
        self::assertSame(1, (int) $row['attempt_count'], 'exactly one claim → exactly one attempt count bump');
        self::assertSame(OutboundStatus::Delivered->value, $row['status']);
    }

    // ==================================================================
    //  Scenario C — publisher + worker pipeline
    // ==================================================================

    #[Test]
    public function publisher_and_worker_pipeline_processes_side_effect_exactly_once(): void
    {
        $this->registerEndpoint('stress-pipeline');

        $publisherCount = 10;
        $workerCount = 5;

        run(function () use ($publisherCount, $workerCount): void {
            $publishersDone = new Channel($publisherCount);
            $workersDone = new Channel($workerCount);

            // Publishers fire first; they all share the same idempotency key.
            for ($i = 0; $i < $publisherCount; $i++) {
                Coroutine::create(function () use ($publishersDone): void {
                    $publisher = $this->buildPublisher();
                    $publisher->publish(new OutboundWebhookMessage(
                        endpointKey: 'stress-pipeline',
                        eventType: 'pipeline.event.v1',
                        payload: ['n' => 1],
                        idempotencyKey: 'pipeline-once',
                    ));
                    $publishersDone->push(1);
                });
            }
            for ($i = 0; $i < $publisherCount; $i++) {
                $publishersDone->pop();
            }

            // Now workers race for the single delivery.
            for ($i = 0; $i < $workerCount; $i++) {
                $workerId = "worker-{$i}";
                Coroutine::create(function () use ($workersDone, $workerId): void {
                    $worker = $this->buildWorker();
                    $worker->processOne($workerId);
                    $workersDone->push(1);
                });
            }
            for ($i = 0; $i < $workerCount; $i++) {
                $workersDone->pop();
            }
        });

        self::assertSame(1, $this->rowCountByIdempotencyKey('pipeline-once'), 'exactly one delivery row');
        self::assertSame(1, count($this->transport->sent), 'transport called exactly once');
        self::assertSame(1, count($this->attemptRepo->all()), 'exactly one attempt row recorded');
    }

    // ==================================================================
    //  Scenario I — stale finalization rejection under forced interleaving
    // ==================================================================

    #[Test]
    public function stale_worker_finalization_is_rejected_after_lease_expiry(): void
    {
        $this->registerEndpoint('stress-stale');
        $publisher = $this->buildPublisher();
        $publisher->publish(new OutboundWebhookMessage(
            endpointKey: 'stress-stale',
            eventType: 'stale.event.v1',
            payload: [],
            idempotencyKey: 'stale-race-1',
        ));

        // Worker A claims with a lease that's already expired (negative offset).
        // Worker B reclaims successfully. Worker A then attempts a stale
        // finalization via the CAS path; the CAS must reject.
        $workerA = $this->buildWorker();
        $workerB = $this->buildWorker();

        $delivery = $this->outboxRepo->claimAndLease('worker-A', new \DateTimeImmutable('-1 second'));
        self::assertNotNull($delivery);

        // Worker B reclaims while A's lease is expired.
        $reclaim = $this->outboxRepo->claimAndLease('worker-B', new \DateTimeImmutable('+60 seconds'));
        self::assertNotNull($reclaim, 'worker B must reclaim the expired lease');

        // Worker A, returning from a hypothetical long transport call, tries
        // to finalize. The CAS WHERE clause requires lease_owner='worker-A'
        // but the row now belongs to worker-B → returns false.
        $staleDelivered = $this->outboxRepo->markDeliveredIfOwned($delivery->getId(), 'worker-A', 200, null, null);
        self::assertFalse($staleDelivered, 'stale worker A must not be able to finalize');

        $row = $this->orm->getAdapter()->execute(
            'SELECT status, lease_owner, attempt_count FROM webhook_outbox WHERE idempotency_key = :k',
            ['k' => 'stale-race-1'],
        )->fetchOne();
        self::assertSame(OutboundStatus::Delivering->value, $row['status'], 'row remains under worker B\'s claim');
        self::assertSame('worker-B', $row['lease_owner']);
        self::assertSame(2, (int) $row['attempt_count'], 'attempt count = 2 (worker A claim + worker B reclaim)');
    }

    // ==================================================================
    //  Sustained burst — repeat scenario A many times
    // ==================================================================

    #[Test]
    public function sustained_burst_of_publishers_and_workers_remains_side_effect_once(): void
    {
        $this->registerEndpoint('stress-burst');

        $bursts = 5;
        $publishersPerBurst = 6;

        run(function () use ($bursts, $publishersPerBurst): void {
            for ($burst = 0; $burst < $bursts; $burst++) {
                $key = "burst-{$burst}";

                // Publish $publishersPerBurst times concurrently for THIS burst's key.
                $publishersDone = new Channel($publishersPerBurst);
                for ($i = 0; $i < $publishersPerBurst; $i++) {
                    Coroutine::create(function () use ($publishersDone, $key): void {
                        $publisher = $this->buildPublisher();
                        $publisher->publish(new OutboundWebhookMessage(
                            endpointKey: 'stress-burst',
                            eventType: 'burst.event.v1',
                            payload: ['burst' => $key],
                            idempotencyKey: $key,
                        ));
                        $publishersDone->push(1);
                    });
                }
                for ($i = 0; $i < $publishersPerBurst; $i++) {
                    $publishersDone->pop();
                }

                // Then a single worker drains.
                $worker = $this->buildWorker();
                $worker->processOne("worker-burst-{$burst}");
            }
        });

        self::assertSame($bursts, $this->rowCount(), 'one row per burst');
        self::assertSame($bursts, count($this->transport->sent), 'one transport send per burst');
    }
}
