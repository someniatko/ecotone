<?php
declare(strict_types=1);

namespace Test\SimplyCodedSoftware\Messaging\Unit\Handler\Processor;

use PHPUnit\Framework\TestCase;
use SimplyCodedSoftware\Messaging\Handler\ExpressionEvaluationService;
use SimplyCodedSoftware\Messaging\Handler\InMemoryReferenceSearchService;
use SimplyCodedSoftware\Messaging\Handler\InterfaceParameter;
use SimplyCodedSoftware\Messaging\Handler\InterfaceToCall;
use SimplyCodedSoftware\Messaging\Handler\Processor\MethodInvoker\Converter\HeaderExpressionBuilder;
use SimplyCodedSoftware\Messaging\Handler\SymfonyExpressionEvaluationAdapter;
use SimplyCodedSoftware\Messaging\Handler\TypeDescriptor;
use SimplyCodedSoftware\Messaging\Support\InvalidArgumentException;
use SimplyCodedSoftware\Messaging\Support\MessageBuilder;
use Test\SimplyCodedSoftware\Messaging\Fixture\Service\CalculatingService;
use Test\SimplyCodedSoftware\Messaging\Fixture\Service\CallableService;

/**
 * Class ExpressionBuilderTest
 * @package Test\SimplyCodedSoftware\Messaging\Unit\Handler\Processor
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 */
class HeaderExpressionBuilderTest extends TestCase
{
    /**
     * @throws \Doctrine\Common\Annotations\AnnotationException
     * @throws \ReflectionException
     * @throws \SimplyCodedSoftware\Messaging\Handler\TypeDefinitionException
     * @throws \SimplyCodedSoftware\Messaging\MessagingException
     * @throws \SimplyCodedSoftware\Messaging\Support\InvalidArgumentException
     */
    public function test_creating_payload_expression()
    {
        $converter = HeaderExpressionBuilder::create("x", "token", "value ~ 1", true);
        $converter = $converter->build(InMemoryReferenceSearchService::createWith([
            ExpressionEvaluationService::REFERENCE => SymfonyExpressionEvaluationAdapter::create()
        ]));

        $this->assertEquals(
            "1001",
            $converter->getArgumentFrom(
                InterfaceToCall::create(CallableService::class, "wasCalled"),
                InterfaceParameter::createNullable("x", TypeDescriptor::createWithDocBlock("string",  "")),
                MessageBuilder::withPayload("some")
                    ->setHeader("token", "100")
                    ->build(),
                []
            )
        );
    }

    /**
     * @throws \SimplyCodedSoftware\Messaging\Handler\TypeDefinitionException
     * @throws \SimplyCodedSoftware\Messaging\MessagingException
     */
    public function test_using_reference_service_in_expression()
    {
        $converter = HeaderExpressionBuilder::create("x", "number", "reference('calculatingService').sum(value)", true);

        $converter = $converter->build(InMemoryReferenceSearchService::createWith([
            ExpressionEvaluationService::REFERENCE => SymfonyExpressionEvaluationAdapter::create(),
            "calculatingService" => CalculatingService::create(1)
        ]));

        $this->assertEquals(
            101,
            $converter->getArgumentFrom(
                InterfaceToCall::create(CallableService::class, "wasCalled"),
                InterfaceParameter::createNullable("x", TypeDescriptor::create("string")),
                MessageBuilder::withPayload("x")
                    ->setHeader("number", 100)
                    ->build(),
                []
            )
        );
    }

    public function test_throwing_exception_if_header_does_not_exists()
    {
        $converter = HeaderExpressionBuilder::create("x", "token", "value ~ 1", true);
        $converter = $converter->build(InMemoryReferenceSearchService::createWith([
            ExpressionEvaluationService::REFERENCE => SymfonyExpressionEvaluationAdapter::create()
        ]));

        $this->expectException(InvalidArgumentException::class);

        $converter->getArgumentFrom(
            InterfaceToCall::create(CallableService::class, "wasCalled"),
            InterfaceParameter::createNullable("x", TypeDescriptor::createWithDocBlock("string",  "")),
            MessageBuilder::withPayload("some")->build(),
            []
        );
    }

    public function test_not_throwing_exception_if_header_does_not_exists_and_is_no_required()
    {
        $converter = HeaderExpressionBuilder::create("x", "token", "value ~ 1", false);
        $converter = $converter->build(InMemoryReferenceSearchService::createWith([
            ExpressionEvaluationService::REFERENCE => SymfonyExpressionEvaluationAdapter::create()
        ]));

        $this->assertEquals(
            "1",
            $converter->getArgumentFrom(
                InterfaceToCall::create(CallableService::class, "wasCalled"),
                InterfaceParameter::createNullable("x", TypeDescriptor::createWithDocBlock("string",  "")),
                MessageBuilder::withPayload("some")->build(),
                []
            )
        );
    }
}