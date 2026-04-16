<?php

namespace Enqueue\Sns\Tests\Spec;

use Aws\Result;
use Enqueue\Sns\SnsClient;
use Enqueue\Sns\SnsContext;
use Enqueue\Sns\SnsDestination;
use Enqueue\Sns\SnsSubscribe;
use Interop\Queue\Spec\ContextSpec;

class SnsContextTest extends ContextSpec
{
    public function testShouldCreateConsumerOnCreateConsumerMethodCall(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('SNS transport does not support consumption. You should consider using SQS instead.');

        parent::testShouldCreateConsumerOnCreateConsumerMethodCall();
    }

    public function testSetsSubscriptionAttributesOnlyForMatchingSubscription(): void
    {
        $client = $this->createMock(SnsClient::class);
        $client->expects($this->once())
            ->method('listSubscriptionsByTopic')
            ->willReturn(new Result(['Subscriptions' => [
                ['SubscriptionArn' => 'arn1', 'Protocol' => 'sqs', 'Endpoint' => 'endpoint1'],
                ['SubscriptionArn' => 'arn2', 'Protocol' => 'sqs', 'Endpoint' => 'endpoint2'],
                ['SubscriptionArn' => 'arn3', 'Protocol' => 'http', 'Endpoint' => 'endpoint1'],
            ]]));
        $client->expects($this->once())
            ->method('setSubscriptionAttributes')
            ->with($this->equalTo(['attr1' => 'value1', 'SubscriptionArn' => 'arn1']));

        $context = new SnsContext($client, ['topic_arns' => ['topic1' => 'topicArn1']]);
        $context->setSubscriptionAttributes(new SnsSubscribe(
            new SnsDestination('topic1'),
            'endpoint1',
            'sqs',
            false,
            ['attr1' => 'value1'],
        ));
    }

    public function testSetSubscriptionAttributesSkipsWhenNoMatch(): void
    {
        $client = $this->createMock(SnsClient::class);
        $client->expects($this->once())
            ->method('listSubscriptionsByTopic')
            ->willReturn(new Result(['Subscriptions' => [
                ['SubscriptionArn' => 'arn1', 'Protocol' => 'sqs', 'Endpoint' => 'endpoint1'],
            ]]));
        $client->expects($this->never())
            ->method('setSubscriptionAttributes');

        $context = new SnsContext($client, ['topic_arns' => ['topic1' => 'topicArn1']]);
        $context->setSubscriptionAttributes(new SnsSubscribe(
            new SnsDestination('topic1'),
            'other-endpoint',
            'http',
            false,
            ['attr1' => 'value1'],
        ));
    }

    protected function createContext()
    {
        $client = $this->createMock(SnsClient::class);

        return new SnsContext($client, ['topic_arns' => []]);
    }
}
