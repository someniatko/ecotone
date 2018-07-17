<?php
declare(strict_types=1);

namespace Test\SimplyCodedSoftware\IntegrationMessaging\Handler\Processor;

use PHPUnit\Framework\TestCase;
use SimplyCodedSoftware\IntegrationMessaging\Handler\InMemoryReferenceSearchService;
use SimplyCodedSoftware\IntegrationMessaging\Handler\Processor\MethodInvoker\ValueBuilder;
use SimplyCodedSoftware\IntegrationMessaging\Support\MessageBuilder;

/**
 * Class StaticBuilderTest
 * @package Test\SimplyCodedSoftware\IntegrationMessaging\Handler\Processor
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 */
class StaticBuilderTest extends TestCase
{
    /**
     * @throws \SimplyCodedSoftware\IntegrationMessaging\InvalidMessageHeaderException
     * @throws \SimplyCodedSoftware\IntegrationMessaging\MessagingException
     */
    public function test_creating_static_value()
    {
        $value = new \stdClass();
        $converter = ValueBuilder::create("some", $value);
        $converter = $converter->build(InMemoryReferenceSearchService::createEmpty());

        $this->assertEquals(
            $value,
            $converter->getArgumentFrom(MessageBuilder::withPayload("a")->build())
        );
    }
}