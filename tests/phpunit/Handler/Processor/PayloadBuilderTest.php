<?php
declare(strict_types=1);

namespace Test\SimplyCodedSoftware\IntegrationMessaging\Handler\Processor;

use SimplyCodedSoftware\IntegrationMessaging\Handler\ExpressionEvaluationService;
use SimplyCodedSoftware\IntegrationMessaging\Handler\InMemoryReferenceSearchService;
use SimplyCodedSoftware\IntegrationMessaging\Handler\Processor\MethodInvoker\ConverterBuilder;
use SimplyCodedSoftware\IntegrationMessaging\Handler\Processor\MethodInvoker\HeaderBuilder;
use SimplyCodedSoftware\IntegrationMessaging\Handler\Processor\MethodInvoker\PayloadBuilder;
use SimplyCodedSoftware\IntegrationMessaging\Handler\Processor\MethodInvoker\ReferenceBuilder;
use SimplyCodedSoftware\IntegrationMessaging\Handler\Processor\MethodInvoker\ValueBuilder;
use SimplyCodedSoftware\IntegrationMessaging\Handler\SymfonyExpressionEvaluationAdapter;
use SimplyCodedSoftware\IntegrationMessaging\Support\MessageBuilder;
use Test\SimplyCodedSoftware\IntegrationMessaging\MessagingTest;

/**
 * Class PayloadBuilder
 * @package Fixture\Handler\Processor
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 */
class PayloadBuilderTest extends MessagingTest
{
    /**
     * @throws \SimplyCodedSoftware\IntegrationMessaging\MessagingException
     */
    public function test_creating_payload_converter()
    {
        $converter = PayloadBuilder::create("some");
        $converter = $converter->build(InMemoryReferenceSearchService::createEmpty());

        $payload = "rabbit";
        $this->assertEquals(
              $payload,
              $converter->getArgumentFrom(MessageBuilder::withPayload($payload)->build())
        );
    }
}