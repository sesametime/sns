<?php

declare(strict_types=1);

namespace Enqueue\Sns;

use Aws\Sns\SnsClient as AwsSnsClient;
use Interop\Queue\Consumer;
use Interop\Queue\Context;
use Interop\Queue\Destination;
use Interop\Queue\Exception\PurgeQueueNotSupportedException;
use Interop\Queue\Exception\SubscriptionConsumerNotSupportedException;
use Interop\Queue\Exception\TemporaryQueueNotSupportedException;
use Interop\Queue\Message;
use Interop\Queue\Producer;
use Interop\Queue\Queue;
use Interop\Queue\SubscriptionConsumer;
use Interop\Queue\Topic;

class SnsContext implements Context
{
    /**
     * @var SnsClient
     */
    private $client;

    /**
     * @var array
     */
    private $config;

    private $topicArns;

    /**
     * @var array<string, array> Cached subscriptions per topic ARN, for the lifetime of this context.
     */
    private $subscriptionsCache = [];

    /**
     * @var string[] Topic names already declared (CreateTopic called) during this context's lifetime.
     */
    private $declaredTopics = [];

    public function __construct(SnsClient $client, array $config)
    {
        $this->client = $client;
        $this->config = $config;
        $this->topicArns = $config['topic_arns'] ?? [];
    }

    /**
     * @return SnsMessage
     */
    public function createMessage(string $body = '', array $properties = [], array $headers = []): Message
    {
        return new SnsMessage($body, $properties, $headers);
    }

    /**
     * @return SnsDestination
     */
    public function createTopic(string $topicName): Topic
    {
        return new SnsDestination($topicName);
    }

    /**
     * @return SnsDestination
     */
    public function createQueue(string $queueName): Queue
    {
        return new SnsDestination($queueName);
    }

    public function declareTopic(SnsDestination $destination): void
    {
        if (in_array($destination->getTopicName(), $this->declaredTopics, true)) {
            return;
        }

        $result = $this->client->createTopic([
            'Attributes' => $destination->getAttributes(),
            'Name' => $destination->getQueueName(),
        ]);

        if (false == $result->hasKey('TopicArn')) {
            throw new \RuntimeException(sprintf('Cannot create topic. topicName: "%s"', $destination->getTopicName()));
        }

        $this->topicArns[$destination->getTopicName()] = (string) $result->get('TopicArn');
        $this->declaredTopics[] = $destination->getTopicName();
    }

    public function setTopicArn(SnsDestination $destination, string $arn): void
    {
        $this->topicArns[$destination->getTopicName()] = $arn;
    }

    public function deleteTopic(SnsDestination $destination): void
    {
        $topicArn = $this->getTopicArn($destination);

        $this->client->deleteTopic($topicArn);

        unset($this->topicArns[$destination->getTopicName()], $this->subscriptionsCache[$topicArn]);
        $this->declaredTopics = array_values(array_filter(
            $this->declaredTopics,
            static fn (string $name): bool => $name !== $destination->getTopicName(),
        ));
    }

    public function subscribe(SnsSubscribe $subscribe): void
    {
        foreach ($this->getSubscriptions($subscribe->getTopic()) as $subscription) {
            if ($subscription['Protocol'] === $subscribe->getProtocol()
                && $subscription['Endpoint'] === $subscribe->getEndpoint()) {
                return;
            }
        }

        $this->client->subscribe([
            'Attributes' => $subscribe->getAttributes(),
            'Endpoint' => $subscribe->getEndpoint(),
            'Protocol' => $subscribe->getProtocol(),
            'ReturnSubscriptionArn' => $subscribe->isReturnSubscriptionArn(),
            'TopicArn' => $this->getTopicArn($subscribe->getTopic()),
        ]);

        $this->invalidateSubscriptionsCache($subscribe->getTopic());
    }

    public function unsubscibe(SnsUnsubscribe $unsubscribe): void
    {
        foreach ($this->getSubscriptions($unsubscribe->getTopic()) as $subscription) {
            if ($subscription['Protocol'] != $unsubscribe->getProtocol()) {
                continue;
            }

            if ($subscription['Endpoint'] != $unsubscribe->getEndpoint()) {
                continue;
            }

            $this->client->unsubscribe([
                'SubscriptionArn' => $subscription['SubscriptionArn'],
            ]);

            $this->invalidateSubscriptionsCache($unsubscribe->getTopic());
        }
    }

    public function getSubscriptions(SnsDestination $destination): array
    {
        $topicArn = $this->getTopicArn($destination);

        if (array_key_exists($topicArn, $this->subscriptionsCache)) {
            return $this->subscriptionsCache[$topicArn];
        }

        $args = [
            'TopicArn' => $topicArn,
        ];

        $subscriptions = [];
        while (true) {
            $result = $this->client->listSubscriptionsByTopic($args);

            $subscriptions = array_merge($subscriptions, $result->get('Subscriptions'));

            if (false == $result->hasKey('NextToken')) {
                break;
            }

            $args['NextToken'] = $result->get('NextToken');
        }

        return $this->subscriptionsCache[$topicArn] = $subscriptions;
    }

    private function invalidateSubscriptionsCache(SnsDestination $destination): void
    {
        unset($this->subscriptionsCache[$this->getTopicArn($destination)]);
    }

    public function setSubscriptionAttributes(SnsSubscribe $subscribe): void
    {
        foreach ($this->getSubscriptions($subscribe->getTopic()) as $subscription) {
            if ($subscription['Protocol'] !== $subscribe->getProtocol()) {
                continue;
            }

            if ($subscription['Endpoint'] !== $subscribe->getEndpoint()) {
                continue;
            }

            $this->client->setSubscriptionAttributes(array_merge(
                $subscribe->getAttributes(),
                ['SubscriptionArn' => $subscription['SubscriptionArn']],
            ));
        }
    }

    public function getTopicArn(SnsDestination $destination): string
    {
        if (false == array_key_exists($destination->getTopicName(), $this->topicArns)) {
            $this->declareTopic($destination);
        }

        return $this->topicArns[$destination->getTopicName()];
    }

    public function createTemporaryQueue(): Queue
    {
        throw TemporaryQueueNotSupportedException::providerDoestNotSupportIt();
    }

    /**
     * @return SnsProducer
     */
    public function createProducer(): Producer
    {
        return new SnsProducer($this);
    }

    /**
     * @param SnsDestination $destination
     */
    public function createConsumer(Destination $destination): Consumer
    {
        throw new \LogicException('SNS transport does not support consumption. You should consider using SQS instead.');
    }

    public function close(): void
    {
    }

    /**
     * @param SnsDestination $queue
     */
    public function purgeQueue(Queue $queue): void
    {
        PurgeQueueNotSupportedException::providerDoestNotSupportIt();
    }

    public function createSubscriptionConsumer(): SubscriptionConsumer
    {
        throw SubscriptionConsumerNotSupportedException::providerDoestNotSupportIt();
    }

    public function getAwsSnsClient(): AwsSnsClient
    {
        return $this->client->getAWSClient();
    }

    public function getSnsClient(): SnsClient
    {
        return $this->client;
    }
}
