<?php

declare(strict_types=1);

namespace Queuey\Symfony\Tests\Integration;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use Queuey\Backend;
use Queuey\RabbitMq\AmqpUrl;
use Queuey\RabbitMq\Topology;
use Queuey\Symfony\Tests\Fixtures\AppQueues;
use Queuey\Symfony\Tests\TestCase;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * The broker-backed case: the same fixture kernel, booted in the `integration`
 * environment, which swaps the memory backend for RabbitMQ on `%env(AMQP_URL)%`.
 *
 * Without `AMQP_URL` every test here skips, exactly as core's own
 * `RabbitMqTestCase` does. The fixture queues are deleted before *and* after
 * each test — including their `.dead` and `.deferred.<ttl>` companions — so a
 * run never inherits a message, or a topology, from the one before it.
 */
abstract class IntegrationTestCase extends TestCase
{
    /**
     * Hold-queue TTLs the fixtures can produce: `Reports` retries on a fixed 2s
     * and the `Images` override on a fixed 1s, both rounded up to the 1s
     * granularity. Listing them is cheaper than asking the management API which
     * queues exist, and a name that is not there deletes harmlessly.
     */
    private const HOLD_TTLS_MS = [1000, 2000, 3000, 5000];

    private string $url = '';

    /**
     * Boot the fixture kernel on RabbitMQ rather than on the memory backend.
     *
     * @param array<string, mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return parent::createKernel($options + ['environment' => 'integration']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $url = getenv('AMQP_URL');
        if (!\is_string($url) || $url === '') {
            self::markTestSkipped('AMQP_URL is not set; skipping the broker-backed suite.');
        }

        $this->url = $url;
        // Belt and braces for `%env(AMQP_URL)%`: CLI PHP fills `$_ENV` only when
        // `variables_order` says `E`, and the container looks there first.
        $_ENV['AMQP_URL'] = $url;
        $_SERVER['AMQP_URL'] = $url;

        $this->deleteFixtureQueues();
    }

    protected function tearDown(): void
    {
        if ($this->url !== '') {
            // The backend outlives the worker by design, so close it here —
            // before the queues go, so nothing is consuming while they do.
            $this->closeBackend();
            $this->deleteFixtureQueues();
        }

        parent::tearDown();
    }

    /** Every queue the fixture set can create on the broker. */
    public function deleteFixtureQueues(): void
    {
        $names = [];
        foreach (AppQueues::all() as $case) {
            $queue = $case->queueName();
            $names[] = $queue;
            $names[] = Topology::deadQueueName($queue);
            foreach (self::HOLD_TTLS_MS as $ttl) {
                $names[] = Topology::deferredQueueName($queue, Topology::DEFAULT_DEFERRED_SUFFIX, $ttl);
            }
        }

        $connection = $this->rawConnection();

        try {
            foreach ($names as $name) {
                // A channel of its own: a refused method closes the channel it
                // ran on, and "queue does not exist" is exactly that.
                $channel = $connection->channel();

                try {
                    $channel->queue_delete($name);
                    $channel->close();
                } catch (\Throwable) {
                    // Already gone, which is the state we wanted.
                }
            }
        } finally {
            try {
                $connection->close();
            } catch (\Throwable) {
                // Nothing left to clean up.
            }
        }
    }

    /** A control connection of our own, never the bundle's. */
    private function rawConnection(): AMQPStreamConnection
    {
        $url = AmqpUrl::parse($this->url);

        return new AMQPStreamConnection($url->host, $url->port, $url->user, $url->password, $url->vhost);
    }

    private function closeBackend(): void
    {
        if (!static::$kernel instanceof KernelInterface) {
            return;
        }

        try {
            $this->service(Backend::class)->close();
        } catch (\Throwable) {
            // Never connected, or the test closed it already.
        }
    }
}
