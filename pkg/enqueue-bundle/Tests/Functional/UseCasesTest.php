<?php

namespace Enqueue\Bundle\Tests\Functional;

use Enqueue\Bundle\Tests\Functional\App\CustomAppKernel;
use Enqueue\Client\DriverInterface;
use Enqueue\Client\Producer;
use Enqueue\Client\ProducerInterface;
use Enqueue\Symfony\Client\ConsumeMessagesCommand;
use Enqueue\Symfony\Consumption\ContainerAwareConsumeMessagesCommand;
use Interop\Queue\PsrContext;
use Interop\Queue\PsrMessage;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\Kernel;

/**
 * @group functional
 */
class UseCasesTest extends WebTestCase
{
    public function setUp()
    {
        // do not call parent::setUp.
        // parent::setUp();
    }

    public function provideEnqueueConfigs()
    {
        $baseDir = realpath(__DIR__.'/../../../../');

        // guard
        $this->assertNotEmpty($baseDir);

        $certDir = $baseDir.'/var/rabbitmq_certificates';
        $this->assertDirectoryExists($certDir);

        yield 'amqp' => [[
            'transport' => [
                'default' => 'amqp',
                'amqp' => [
                    'driver' => 'ext',
                    'host' => getenv('RABBITMQ_HOST'),
                    'port' => getenv('RABBITMQ_AMQP__PORT'),
                    'user' => getenv('RABBITMQ_USER'),
                    'pass' => getenv('RABBITMQ_PASSWORD'),
                    'vhost' => getenv('RABBITMQ_VHOST'),
                    'lazy' => false,
                ],
            ],
        ]];

        yield 'amqp_dsn' => [[
            'transport' => [
                'default' => 'amqp',
                'amqp' => getenv('AMQP_DSN'),
            ],
        ]];

        yield 'amqps_dsn' => [[
            'transport' => [
                'default' => 'amqp',
                'amqp' => [
                    'dsn' => getenv('AMQPS_DSN'),
                    'ssl_verify' => false,
                    'ssl_cacert' => $certDir.'/cacert.pem',
                    'ssl_cert' => $certDir.'/cert.pem',
                    'ssl_key' => $certDir.'/key.pem',
                ],
            ],
        ]];

        yield 'default_amqp_as_dsn' => [[
            'transport' => [
                'default' => getenv('AMQP_DSN'),
            ],
        ]];

        // Symfony 2.x does not such env syntax
        if (version_compare(Kernel::VERSION, '3.2', '>=')) {
            yield 'default_dsn_as_env' => [[
                'transport' => [
                    'default' => '%env(AMQP_DSN)%',
                ],
            ]];
        }

        yield 'default_dbal_as_dsn' => [[
            'transport' => [
                'default' => getenv('DOCTINE_DSN'),
            ],
        ]];

        yield 'stomp' => [[
            'transport' => [
                'default' => 'stomp',
                'stomp' => [
                    'host' => getenv('RABBITMQ_HOST'),
                    'port' => getenv('﻿RABBITMQ_STOMP_PORT'),
                    'login' => getenv('RABBITMQ_USER'),
                    'password' => getenv('RABBITMQ_PASSWORD'),
                    'vhost' => getenv('RABBITMQ_VHOST'),
                    'lazy' => false,
                ],
            ],
        ]];

        yield 'predis' => [[
            'transport' => [
                'default' => 'redis',
                'redis' => [
                    'host' => getenv('REDIS_HOST'),
                    'port' => (int) getenv('REDIS_PORT'),
                    'vendor' => 'predis',
                    'lazy' => false,
                ],
            ],
        ]];

        yield 'phpredis' => [[
            'transport' => [
                'default' => 'redis',
                'redis' => [
                    'host' => getenv('REDIS_HOST'),
                    'port' => (int) getenv('REDIS_PORT'),
                    'vendor' => 'phpredis',
                    'lazy' => false,
                ],
            ],
        ]];

        yield 'fs' => [[
            'transport' => [
                'default' => 'fs',
                'fs' => [
                    'path' => sys_get_temp_dir(),
                ],
            ],
        ]];

        yield 'fs_dsn' => [[
            'transport' => [
                'default' => 'fs',
                'fs' => 'file://'.sys_get_temp_dir(),
            ],
        ]];

        yield 'default_fs_as_dsn' => [[
            'transport' => [
                'default' => 'file://'.sys_get_temp_dir(),
            ],
        ]];

        yield 'dbal' => [[
            'transport' => [
                'default' => 'dbal',
                'dbal' => [
                    'connection' => [
                        'dbname' => getenv('DOCTRINE_DB_NAME'),
                        'user' => getenv('DOCTRINE_USER'),
                        'password' => getenv('DOCTRINE_PASSWORD'),
                        'host' => getenv('DOCTRINE_HOST'),
                        'port' => getenv('DOCTRINE_PORT'),
                        'driver' => getenv('DOCTRINE_DRIVER'),
                    ],
                ],
            ],
        ]];

        yield 'dbal_dsn' => [[
            'transport' => [
                'default' => 'dbal',
                'dbal' => getenv('DOCTINE_DSN'),
            ],
        ]];

        yield 'sqs' => [[
            'transport' => [
                'default' => 'sqs',
                'sqs' => [
                    'key' => getenv('AWS_SQS_KEY'),
                    'secret' => getenv('AWS_SQS_SECRET'),
                    'region' => getenv('AWS_SQS_REGION'),
                ],
            ],
        ]];

        yield 'gps' => [[
            'transport' => [
                'default' => 'gps',
                'gps' => [],
            ],
        ]];
    }

    /**
     * @dataProvider provideEnqueueConfigs
     */
    public function testProducerSendsMessage(array $enqueueConfig)
    {
        $this->customSetUp($enqueueConfig);

        $this->getMessageProducer()->sendEvent(TestProcessor::TOPIC, 'test message body');

        $queue = $this->getPsrContext()->createQueue('enqueue.test');

        $consumer = $this->getPsrContext()->createConsumer($queue);

        $message = $consumer->receive(100);

        $this->assertInstanceOf(PsrMessage::class, $message);
        $this->assertSame('test message body', $message->getBody());
    }

    /**
     * @dataProvider provideEnqueueConfigs
     */
    public function testProducerSendsCommandMessage(array $enqueueConfig)
    {
        $this->customSetUp($enqueueConfig);

        $expectedBody = __METHOD__.time();

        $this->getMessageProducer()->sendCommand(TestCommandProcessor::COMMAND, $expectedBody);

        $queue = $this->getPsrContext()->createQueue('enqueue.test');

        $consumer = $this->getPsrContext()->createConsumer($queue);

        $message = $consumer->receive(100);
        $consumer->acknowledge($message);

        $this->assertInstanceOf(PsrMessage::class, $message);
        $this->assertSame($expectedBody, $message->getBody());
    }

    /**
     * @dataProvider provideEnqueueConfigs
     */
    public function testClientConsumeCommandMessagesFromExplicitlySetQueue(array $enqueueConfig)
    {
        $this->customSetUp($enqueueConfig);

        $command = $this->container->get(ConsumeMessagesCommand::class);
        $processor = $this->container->get('test.message.command_processor');

        $expectedBody = __METHOD__.time();

        $this->getMessageProducer()->sendCommand(TestCommandProcessor::COMMAND, $expectedBody);

        $tester = new CommandTester($command);
        $tester->execute([
            '--message-limit' => 2,
            '--time-limit' => 'now +10 seconds',
            'client-queue-names' => ['test'],
        ]);

        $this->assertInstanceOf(PsrMessage::class, $processor->message);
        $this->assertEquals($expectedBody, $processor->message->getBody());
    }

    /**
     * @dataProvider provideEnqueueConfigs
     */
    public function testClientConsumeMessagesFromExplicitlySetQueue(array $enqueueConfig)
    {
        $this->customSetUp($enqueueConfig);

        $command = $this->container->get(ConsumeMessagesCommand::class);
        $processor = $this->container->get('test.message.processor');

        $this->getMessageProducer()->sendEvent(TestProcessor::TOPIC, 'test message body');

        $tester = new CommandTester($command);
        $tester->execute([
            '--message-limit' => 2,
            '--time-limit' => 'now +10 seconds',
            'client-queue-names' => ['test'],
        ]);

        $this->assertInstanceOf(PsrMessage::class, $processor->message);
        $this->assertEquals('test message body', $processor->message->getBody());
    }

    /**
     * @dataProvider provideEnqueueConfigs
     */
    public function testTransportConsumeMessagesCommandShouldConsumeMessage(array $enqueueConfig)
    {
        $this->customSetUp($enqueueConfig);

        $command = $this->container->get(ContainerAwareConsumeMessagesCommand::class);
        $command->setContainer($this->container);
        $processor = $this->container->get('test.message.processor');

        $this->getMessageProducer()->sendEvent(TestProcessor::TOPIC, 'test message body');

        $tester = new CommandTester($command);
        $tester->execute([
            '--message-limit' => 1,
            '--time-limit' => '+10sec',
            '--queue' => ['enqueue.test'],
            'processor-service' => 'test.message.processor',
        ]);

        $this->assertInstanceOf(PsrMessage::class, $processor->message);
        $this->assertEquals('test message body', $processor->message->getBody());
    }

    /**
     * @return string
     */
    public static function getKernelClass()
    {
        include_once __DIR__.'/App/CustomAppKernel.php';

        return CustomAppKernel::class;
    }

    protected function customSetUp(array $enqueueConfig)
    {
        static::$class = null;

        $this->client = static::createClient(['enqueue_config' => $enqueueConfig]);
        $this->client->getKernel()->boot();
        static::$kernel = $this->client->getKernel();
        $this->container = static::$kernel->getContainer();

        /** @var DriverInterface $driver */
        $driver = $this->container->get('enqueue.client.driver');
        $context = $this->getPsrContext();

        $queue = $driver->createQueue('test');

        if (method_exists($context, 'purgeQueue')) {
            $context->purgeQueue($queue);
        }

        $driver->setupBroker();
    }

    /**
     * {@inheritdoc}
     */
    protected static function createKernel(array $options = [])
    {
        /** @var CustomAppKernel $kernel */
        $kernel = parent::createKernel($options);

        $kernel->setEnqueueConfig(isset($options['enqueue_config']) ? $options['enqueue_config'] : []);

        return $kernel;
    }

    /**
     * @return ProducerInterface|object
     */
    private function getMessageProducer()
    {
        return $this->container->get(Producer::class);
    }

    /**
     * @return PsrContext|object
     */
    private function getPsrContext()
    {
        return $this->container->get('enqueue.transport.context');
    }
}
