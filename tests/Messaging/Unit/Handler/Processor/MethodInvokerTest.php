<?php
declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Handler\Processor;

use Ecotone\Messaging\Config\InMemoryChannelResolver;
use Ecotone\Messaging\Conversion\AutoCollectionConversionService;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Conversion\SerializedToObject\DeserializingConverter;
use Ecotone\Messaging\Conversion\StringToUuid\StringToUuidConverter;
use Ecotone\Messaging\Handler\InMemoryReferenceSearchService;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundInterceptorReference;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\HeaderBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\MessageConverterBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\PayloadBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocationException;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvoker;
use Ecotone\Messaging\Handler\Processor\WrapWithMessageBuildProcessor;
use Ecotone\Messaging\Handler\ReferenceNotFoundException;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\MessagingException;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Ecotone\Messaging\Support\MessageBuilder;
use Ramsey\Uuid\Uuid;
use ReflectionException;
use stdClass;
use Test\Ecotone\Messaging\Fixture\Annotation\Interceptor\CalculatingServiceInterceptorExample;
use Test\Ecotone\Messaging\Fixture\Behat\Ordering\Order;
use Test\Ecotone\Messaging\Fixture\Behat\Ordering\OrderConfirmation;
use Test\Ecotone\Messaging\Fixture\Behat\Ordering\OrderProcessor;
use Test\Ecotone\Messaging\Fixture\Handler\ExampleService;
use Test\Ecotone\Messaging\Fixture\Handler\Processor\AroundInterceptorObjectBuilderExample;
use Test\Ecotone\Messaging\Fixture\Handler\Processor\Interceptor\CallMultipleUnorderedArgumentsInvocationInterceptorExample;
use Test\Ecotone\Messaging\Fixture\Handler\Processor\Interceptor\CallWithAnnotationFromClassInterceptorExample;
use Test\Ecotone\Messaging\Fixture\Handler\Processor\Interceptor\CallWithAnnotationFromMethodInterceptorExample;
use Test\Ecotone\Messaging\Fixture\Handler\Processor\Interceptor\CallWithEndingChainAndReturningInterceptorExample;
use Test\Ecotone\Messaging\Fixture\Handler\Processor\Interceptor\CallWithEndingChainNoReturningInterceptorExample;
use Test\Ecotone\Messaging\Fixture\Handler\Processor\Interceptor\CallWithInterceptedObjectInterceptorExample;
use Test\Ecotone\Messaging\Fixture\Handler\Processor\Interceptor\CallWithNullableStdClassInterceptorExample;
use Test\Ecotone\Messaging\Fixture\Handler\Processor\Interceptor\CallWithPassThroughInterceptorExample;
use Test\Ecotone\Messaging\Fixture\Handler\Processor\Interceptor\CallWithProceedingAndReturningInterceptorExample;
use Test\Ecotone\Messaging\Fixture\Handler\Processor\Interceptor\CallWithProceedingInterceptorExample;
use Test\Ecotone\Messaging\Fixture\Handler\Processor\Interceptor\CallWithReferenceSearchServiceExample;
use Test\Ecotone\Messaging\Fixture\Handler\Processor\Interceptor\CallWithReplacingArgumentsInterceptorExample;
use Test\Ecotone\Messaging\Fixture\Handler\Processor\Interceptor\CallWithRequestMessageInterceptorExample;
use Test\Ecotone\Messaging\Fixture\Handler\Processor\Interceptor\CallWithStdClassInterceptorExample;
use Test\Ecotone\Messaging\Fixture\Handler\Processor\Interceptor\CallWithUnorderedClassInvocationInterceptorExample;
use Test\Ecotone\Messaging\Fixture\Handler\Processor\StubCallSavingService;
use Test\Ecotone\Messaging\Fixture\Service\CalculatingService;
use Test\Ecotone\Messaging\Fixture\Service\ServiceExpectingMessageAndReturningMessage;
use Test\Ecotone\Messaging\Fixture\Service\ServiceExpectingOneArgument;
use Test\Ecotone\Messaging\Fixture\Service\ServiceExpectingThreeArguments;
use Test\Ecotone\Messaging\Fixture\Service\ServiceExpectingTwoArguments;
use Test\Ecotone\Messaging\Fixture\Service\ServiceWithoutAnyMethods;
use Test\Ecotone\Messaging\Unit\MessagingTest;

/**
 * Class MethodInvocationTest
 * @package Ecotone\Messaging\Handler\ServiceActivator
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 */
class MethodInvokerTest extends MessagingTest
{
    public function test_throwing_exception_if_not_enough_arguments_provided()
    {
        $this->expectException(InvalidArgumentException::class);

        $service = ServiceExpectingTwoArguments::create();
        $interfaceToCall = InterfaceToCall::create($service, 'withoutReturnValue');

        MethodInvoker::createWith($interfaceToCall, $service, [], InMemoryReferenceSearchService::createEmpty());
    }

    public function test_invoking_service()
    {
        $serviceExpectingOneArgument = ServiceExpectingOneArgument::create();
        $interfaceToCall = InterfaceToCall::create($serviceExpectingOneArgument, 'withoutReturnValue');

        $methodInvocation = MethodInvoker::createWith($interfaceToCall, $serviceExpectingOneArgument, [
            PayloadBuilder::create('name')
        ], InMemoryReferenceSearchService::createEmpty());

        $methodInvocation->processMessage(MessageBuilder::withPayload('some')->build());

        $this->assertTrue($serviceExpectingOneArgument->wasCalled(), "Method was not called");
    }

    /**
     * @throws InvalidArgumentException
     * @throws ReferenceNotFoundException
     * @throws MessagingException
     */
    public function test_not_changing_content_type_of_message_if_message_is_return_type()
    {
        $serviceExpectingOneArgument = ServiceExpectingMessageAndReturningMessage::create("test");
        $interfaceToCall = InterfaceToCall::create($serviceExpectingOneArgument, 'send');

        $methodInvocation = MethodInvoker::createWith($interfaceToCall, $serviceExpectingOneArgument, [
            MessageConverterBuilder::create("message")
        ], InMemoryReferenceSearchService::createEmpty());

        $this->assertMessages(
            MessageBuilder::withPayload("test")
                ->build(),
            $methodInvocation->processMessage(MessageBuilder::withPayload('some')->build())
        );
    }

    /**
     * @throws InvalidArgumentException
     * @throws ReflectionException
     * @throws MessagingException
     */
    public function test_invoking_service_with_return_value_from_header()
    {
        $serviceExpectingOneArgument = ServiceExpectingOneArgument::create();
        $interfaceToCall = InterfaceToCall::create($serviceExpectingOneArgument, 'withReturnValue');
        $headerName = 'token';
        $headerValue = '123X';

        $methodInvocation = MethodInvoker::createWith($interfaceToCall, $serviceExpectingOneArgument, [
            HeaderBuilder::create('name', $headerName)
        ], InMemoryReferenceSearchService::createEmpty());

        $this->assertEquals($headerValue,
            $methodInvocation->processMessage(
                MessageBuilder::withPayload('some')
                    ->setHeader($headerName, $headerValue)
                    ->build()
            )
        );
    }

    /**
     * @throws InvalidArgumentException
     * @throws ReflectionException
     * @throws MessagingException
     */
    public function test_if_method_requires_one_argument_and_there_was_not_passed_any_then_use_payload_one_as_default()
    {
        $serviceExpectingOneArgument = ServiceExpectingOneArgument::create();
        $interfaceToCall = InterfaceToCall::create($serviceExpectingOneArgument, 'withReturnValue');

        $methodInvocation = MethodInvoker::createWith($interfaceToCall, $serviceExpectingOneArgument,  [], InMemoryReferenceSearchService::createEmpty());

        $payload = 'some';

        $this->assertEquals($payload,
            $methodInvocation->processMessage(
                MessageBuilder::withPayload($payload)
                    ->build()
            )
        );
    }

    /**
     * @throws InvalidArgumentException
     * @throws ReflectionException
     * @throws MessagingException
     */
    public function test_if_method_requires_two_argument_and_there_was_not_passed_any_then_use_payload_and_headers_if_possible_as_default()
    {
        $serviceExpectingOneArgument = ServiceExpectingTwoArguments::create();
        $interfaceToCall = InterfaceToCall::create($serviceExpectingOneArgument, 'payloadAndHeaders');

        $methodInvocation = MethodInvoker::createWith($interfaceToCall, $serviceExpectingOneArgument, [], InMemoryReferenceSearchService::createEmpty());

        $payload = 'some';

        $this->assertEquals(
            $payload,
            $methodInvocation->processMessage(
                MessageBuilder::withPayload($payload)
                    ->build()
            )
        );
    }

    /**
     * @throws InvalidArgumentException
     * @throws ReflectionException
     * @throws MessagingException
     */
    public function test_throwing_exception_if_passed_wrong_argument_names()
    {
        $serviceExpectingOneArgument = ServiceExpectingOneArgument::create();
        $interfaceToCall = InterfaceToCall::create($serviceExpectingOneArgument, 'withoutReturnValue');

        $this->expectException(InvalidArgumentException::class);

        MethodInvoker::createWith($interfaceToCall, $serviceExpectingOneArgument, [
            PayloadBuilder::create('wrongName')
        ], InMemoryReferenceSearchService::createEmpty());
    }

    /**
     * @throws InvalidArgumentException
     * @throws ReflectionException
     * @throws MessagingException
     */
    public function test_invoking_service_with_multiple_not_ordered_arguments()
    {
        $serviceExpectingThreeArgument = ServiceExpectingThreeArguments::create();
        $interfaceToCall = InterfaceToCall::create($serviceExpectingThreeArgument, 'withReturnValue');

        $methodInvocation = MethodInvoker::createWith($interfaceToCall, $serviceExpectingThreeArgument, [
            HeaderBuilder::create('surname', 'personSurname'),
            HeaderBuilder::create('age', 'personAge'),
            PayloadBuilder::create('name'),
        ], InMemoryReferenceSearchService::createEmpty());

        $this->assertEquals("johnybilbo13",
            $methodInvocation->processMessage(
                MessageBuilder::withPayload('johny')
                    ->setHeader('personSurname', 'bilbo')
                    ->setHeader('personAge', 13)
                    ->build()
            )
        );
    }

    /**
     * @throws InvalidArgumentException
     * @throws ReferenceNotFoundException
     * @throws MessagingException
     */
    public function test_invoking_with_payload_conversion()
    {
        $referenceSearchService = InMemoryReferenceSearchService::createWith([
            AutoCollectionConversionService::REFERENCE_NAME => AutoCollectionConversionService::createWith([
                new DeserializingConverter()
            ])
        ]);
        $interfaceToCall = InterfaceToCall::create(new OrderProcessor(), 'processOrder');

        $methodInvocation =
            WrapWithMessageBuildProcessor::createWith(
                $interfaceToCall,
                MethodInvoker::createWith($interfaceToCall, new OrderProcessor(), [
                    PayloadBuilder::create('order')
                ], $referenceSearchService),
                $referenceSearchService
            );

        $replyMessage = $methodInvocation->processMessage(
            MessageBuilder::withPayload(serialize(Order::create('1', "correct")))
                ->setContentType(MediaType::createApplicationXPHPSerialized())
                ->build()
        );

        $this->assertMessages(
            MessageBuilder::withPayload(OrderConfirmation::fromOrder(Order::create('1', "correct")))
                ->setContentType(MediaType::createApplicationXPHPWithTypeParameter(OrderConfirmation::class))
                ->build(),
            $replyMessage
        );
    }

    /**
     * @throws InvalidArgumentException
     * @throws ReferenceNotFoundException
     * @throws MessagingException
     */
    public function test_throwing_exception_if_cannot_convert_to_php_media_type()
    {
        $referenceSearchService = InMemoryReferenceSearchService::createWith([
            AutoCollectionConversionService::REFERENCE_NAME => AutoCollectionConversionService::createWith([])
        ]);
        $service   = new OrderProcessor();
        $interfaceToCall = InterfaceToCall::create($service, 'processOrder');

        $methodInvocation =
            WrapWithMessageBuildProcessor::createWith(
                $interfaceToCall,
                MethodInvoker::createWith(
                    $interfaceToCall, $service,  [
                    PayloadBuilder::create('order')
                ], $referenceSearchService),
                $referenceSearchService
            );

        $this->expectException(InvalidArgumentException::class);

        $methodInvocation->processMessage(
            MessageBuilder::withPayload(serialize(Order::create('1', "correct")))
                ->setContentType(MediaType::createApplicationXPHPSerialized())
                ->build()
        );
    }

    public function test_calling_if_media_type_is_incompatible_but_types_are()
    {
        $referenceSearchService = InMemoryReferenceSearchService::createWith([
            AutoCollectionConversionService::REFERENCE_NAME => AutoCollectionConversionService::createWith([])
        ]);
        $objectToInvoke         = new ExampleService();
        $interfaceToCall        = InterfaceToCall::create($objectToInvoke, 'receiveString');
        $methodInvocation       =
            WrapWithMessageBuildProcessor::createWith(
                $interfaceToCall,
                MethodInvoker::createWith(
                    $interfaceToCall,
                    $objectToInvoke,
                    [PayloadBuilder::create('id')],
                    $referenceSearchService
                ),
                $referenceSearchService
            );

        $result = $methodInvocation->processMessage(
            MessageBuilder::withPayload("some")
                ->setContentType(MediaType::createApplicationXPHPSerialized())
                ->build()
        );

        $this->assertEquals("some", $result->getPayload());
    }

    /**
     * @throws InvalidArgumentException
     * @throws ReferenceNotFoundException
     * @throws MessagingException
     */
    public function test_choosing_one_of_compatible_return_union_type_when_first_is_correct()
    {
        $referenceSearchService = InMemoryReferenceSearchService::createEmpty();
        $serviceToCall          = new ServiceExpectingOneArgument();
        $interfaceToCall        = InterfaceToCall::create($serviceToCall, 'withDifferentScalarOrObjectReturnType');
        $methodInvocation       =
            WrapWithMessageBuildProcessor::createWith(
                $interfaceToCall,
                MethodInvoker::createWith(
                    $interfaceToCall, $serviceToCall, [
                    PayloadBuilder::create('value')
                ], $referenceSearchService),
                $referenceSearchService
            );

        $replyMessage = $methodInvocation->processMessage(MessageBuilder::withPayload(new stdClass())->build());

        $this->assertMessages(
            MessageBuilder::withPayload(new stdClass())
                ->setContentType(MediaType::createApplicationXPHPWithTypeParameter(stdClass::class))
                ->build(),
            $replyMessage
        );
    }

    public function test_choosing_one_of_compatible_return_union_type_when_first_is_incorrect()
    {
        $referenceSearchService = InMemoryReferenceSearchService::createEmpty();

        $interfaceToCall = InterfaceToCall::create(new ServiceExpectingOneArgument(), 'withDifferentScalarOrObjectReturnType');
        $methodInvocation =
            WrapWithMessageBuildProcessor::createWith(
                $interfaceToCall,
                MethodInvoker::createWith($interfaceToCall, new ServiceExpectingOneArgument(), [
                    PayloadBuilder::create('value')
                ], $referenceSearchService),
                $referenceSearchService
            );

        $replyMessage = $methodInvocation->processMessage(MessageBuilder::withPayload("test")->build());

        $this->assertMessages(
            MessageBuilder::withPayload("test")
                ->setContentType(MediaType::createApplicationXPHPWithTypeParameter("string"))
                ->build(),
            $replyMessage
        );
    }

    public function test_if_can_not_decide_return_type_make_use_resolved_from_return_value()
    {
        $referenceSearchService = InMemoryReferenceSearchService::createEmpty();
        $interfaceToCall = InterfaceToCall::create(new ServiceExpectingOneArgument(), 'withCollectionAndArrayReturnType');
        $methodInvocation =
            WrapWithMessageBuildProcessor::createWith(
                $interfaceToCall,
                MethodInvoker::createWith($interfaceToCall, new ServiceExpectingOneArgument(),  [
                    PayloadBuilder::create('value')
                ], $referenceSearchService),
                $referenceSearchService
            );

        $replyMessage = $methodInvocation->processMessage(MessageBuilder::withPayload("test")->build());

        $this->assertMessages(
            MessageBuilder::withPayload("test")
                ->setContentType(MediaType::createApplicationXPHPWithTypeParameter("string"))
                ->build(),
            $replyMessage
        );
    }

    /**
     * @throws InvalidArgumentException
     * @throws ReferenceNotFoundException
     * @throws MessagingException
     */
    public function test_invoking_with_header_conversion()
    {
        $orderProcessor   = new OrderProcessor();
        $interfaceToCall = InterfaceToCall::create($orderProcessor, 'buyByName');

        $methodInvocation = MethodInvoker::createWith(
            $interfaceToCall, $orderProcessor, [
            HeaderBuilder::create("id", "uuid")
        ], InMemoryReferenceSearchService::createWith([
            AutoCollectionConversionService::REFERENCE_NAME => AutoCollectionConversionService::createWith([
                new StringToUuidConverter()
            ])
        ]));

        $uuid = "fd825894-907c-4c6c-88a9-ae1ecdf3d307";
        $replyMessage = $methodInvocation->processMessage(
            MessageBuilder::withPayload("some")
                ->setHeader("uuid", $uuid)
                ->setContentType(MediaType::createTextPlain())
                ->build()
        );

        $this->assertEquals(
            OrderConfirmation::createFromUuid(Uuid::fromString($uuid)),
            $replyMessage
        );
    }

    /**
     * @throws InvalidArgumentException
     * @throws ReferenceNotFoundException
     * @throws MessagingException
     */
    public function test_invoking_with_converter_for_collection_if_types_are_compatible()
    {
        $service   = new OrderProcessor();
        $interfaceToCall = InterfaceToCall::create($service, 'buyMultiple');

        $methodInvocation = MethodInvoker::createWith(
            $interfaceToCall, $service, [
            PayloadBuilder::create("ids")
        ], InMemoryReferenceSearchService::createWith([
            AutoCollectionConversionService::REFERENCE_NAME => AutoCollectionConversionService::createWith([
                new StringToUuidConverter()
            ])
        ]));

        $replyMessage = $methodInvocation->processMessage(
            MessageBuilder::withPayload(["fd825894-907c-4c6c-88a9-ae1ecdf3d307", "fd825894-907c-4c6c-88a9-ae1ecdf3d308"])
                ->setContentType(MediaType::createApplicationXPHPWithTypeParameter("array<string>"))
                ->build()
        );

        $this->assertEquals(
            [OrderConfirmation::createFromUuid(Uuid::fromString("fd825894-907c-4c6c-88a9-ae1ecdf3d307")), OrderConfirmation::createFromUuid(Uuid::fromString("fd825894-907c-4c6c-88a9-ae1ecdf3d308"))],
            $replyMessage
        );
    }

    public function test_calling_with_single_around_advice_proceeding_method_invocation()
    {
        $interceptingService1 = CallWithProceedingInterceptorExample::create();
        $interceptedService = StubCallSavingService::create();
        $methodInvocation = MethodInvoker::createWith(
            InterfaceToCall::create($interceptedService, 'callNoArgumentsAndReturnType'),
            $interceptedService, [],
            InMemoryReferenceSearchService::createWith([
            CallWithProceedingInterceptorExample::class => $interceptingService1
        ]), InMemoryChannelResolver::createEmpty(),
            [
                AroundInterceptorReference::createWithNoPointcut("someId", CallWithProceedingInterceptorExample::class, "callWithProceeding")
            ]
        );

        $methodInvocation->processMessage(MessageBuilder::withPayload("some")->build());
        $this->assertTrue($interceptedService->wasCalled());
        $this->assertTrue($interceptingService1->wasCalled());
    }

    public function test_calling_with_around_interceptor_from_object_builder()
    {
        $interceptingService1 = StubCallSavingService::create();
        $interceptedService = StubCallSavingService::create();
        $methodInvocation = MethodInvoker::createWith(
            InterfaceToCall::create($interceptedService, 'callNoArgumentsAndReturnType'),
            $interceptedService, [],
            InMemoryReferenceSearchService::createEmpty(), InMemoryChannelResolver::createEmpty(),
            [
                AroundInterceptorReference::createWithObjectBuilder("someId", AroundInterceptorObjectBuilderExample::create($interceptingService1), "callWithProceed", 0, "")
            ]
        );

        $methodInvocation->processMessage(MessageBuilder::withPayload("some")->build());
        $this->assertTrue($interceptedService->wasCalled());
        $this->assertTrue($interceptingService1->wasCalled());
    }

    public function test_calling_with_multiple_around_advice_proceeding_method_invocation()
    {
        $interceptingService1 = CallWithProceedingInterceptorExample::create();
        $interceptingService2 = CallWithProceedingInterceptorExample::create();
        $interceptingService3 = CallWithProceedingInterceptorExample::create();
        $interceptedService = StubCallSavingService::create();
        $methodInvocation = MethodInvoker::createWith(
            InterfaceToCall::create($interceptedService, 'callNoArgumentsAndReturnType'),
            $interceptedService, [], InMemoryReferenceSearchService::createWith([
            "interceptor1" => $interceptingService1,
            "interceptor2" => $interceptingService2,
            "interceptor3" => $interceptingService3
        ]), InMemoryChannelResolver::createEmpty(),
            [
                AroundInterceptorReference::createWithNoPointcut("someId", "interceptor1", "callWithProceeding"),
                AroundInterceptorReference::createWithNoPointcut("someId", "interceptor2", "callWithProceeding"),
                AroundInterceptorReference::createWithNoPointcut("someId", "interceptor3", "callWithProceeding")
            ]
        );

        $methodInvocation->processMessage(MessageBuilder::withPayload("some")->build());
        $this->assertTrue($interceptedService->wasCalled());
        $this->assertTrue($interceptingService1->wasCalled());
        $this->assertTrue($interceptingService2->wasCalled());
        $this->assertTrue($interceptingService3->wasCalled());
    }

    public function test_calling_with_around_method_interceptor_changing_return_value()
    {
        $interceptingService1 = CallWithEndingChainAndReturningInterceptorExample::createWithReturnType("changed");
        $interceptedService = StubCallSavingService::createWithReturnType("original");
        $methodInvocation = MethodInvoker::createWith(
            InterfaceToCall::create($interceptedService, 'callNoArgumentsAndReturnType'),
            $interceptedService, [], InMemoryReferenceSearchService::createWith([
            CallWithEndingChainAndReturningInterceptorExample::class => $interceptingService1
        ]), InMemoryChannelResolver::createEmpty(),
            [AroundInterceptorReference::createWithNoPointcut("someId", CallWithEndingChainAndReturningInterceptorExample::class, "callWithEndingChainAndReturning")]
        );

        $this->assertFalse($interceptedService->wasCalled());
        $this->assertEquals(
            "changed",
            $methodInvocation->processMessage(MessageBuilder::withPayload("some")->build())
        );
    }

    public function test_calling_with_method_interceptor_changing_return_value_at_second_call()
    {
        $interceptingService1 = CallWithProceedingAndReturningInterceptorExample::create();
        $interceptingService2 = CallWithEndingChainAndReturningInterceptorExample::createWithReturnType("changed");
        $interceptedService = StubCallSavingService::createWithReturnType("original");
        $methodInvocation = MethodInvoker::createWith(
            InterfaceToCall::create($interceptedService, 'callWithReturn'),
            $interceptedService, [], InMemoryReferenceSearchService::createWith([
            CallWithProceedingAndReturningInterceptorExample::class => $interceptingService1,
            CallWithEndingChainAndReturningInterceptorExample::class => $interceptingService2
        ]), InMemoryChannelResolver::createEmpty(),
            [
                AroundInterceptorReference::createWithNoPointcut("someId", CallWithProceedingAndReturningInterceptorExample::class, "callWithProceedingAndReturning"),
                AroundInterceptorReference::createWithNoPointcut("someId", CallWithEndingChainAndReturningInterceptorExample::class, "callWithEndingChainAndReturning")
            ]
        );

        $this->assertEquals(
            "changed",
            $methodInvocation->processMessage(MessageBuilder::withPayload("some")->build())
        );
    }

    public function test_calling_with_interceptor_ending_call_and_return_nothing()
    {
        $interceptingService1 = CallWithEndingChainNoReturningInterceptorExample::create();
        $interceptedService = StubCallSavingService::createWithReturnType("original");
        $methodInvocation = MethodInvoker::createWith(
            InterfaceToCall::create($interceptedService, 'callWithReturn'),
            $interceptedService, [], InMemoryReferenceSearchService::createWith([
            CallWithEndingChainNoReturningInterceptorExample::class => $interceptingService1
        ]), InMemoryChannelResolver::createEmpty(),
            [
                AroundInterceptorReference::createWithNoPointcut("someId", CallWithEndingChainNoReturningInterceptorExample::class, "callWithEndingChainNoReturning")
            ]
        );

        $this->assertNull($methodInvocation->processMessage(MessageBuilder::withPayload("some")->build()));
    }

    public function test_changing_calling_arguments_from_interceptor()
    {
        $interceptingService1 = CallWithReplacingArgumentsInterceptorExample::createWithArgumentsToReplace(["stdClass" => new stdClass()]);
        $interceptedService = StubCallSavingService::create();
        $methodInvocation = MethodInvoker::createWith(
            InterfaceToCall::create($interceptedService, 'callWithStdClassArgument'),
            $interceptedService, [], InMemoryReferenceSearchService::createWith([
            CallWithReplacingArgumentsInterceptorExample::class => $interceptingService1
        ]), InMemoryChannelResolver::createEmpty(),
            [AroundInterceptorReference::createWithNoPointcut("someId", CallWithReplacingArgumentsInterceptorExample::class, "callWithReplacingArguments")]
        );

        $this->assertNull($methodInvocation->processMessage(MessageBuilder::withPayload("some")->build()));
        $this->assertTrue($interceptedService->wasCalled());
    }

    public function test_calling_interceptor_with_unordered_arguments_from_intercepted_method()
    {
        $interceptingService1 = CallWithUnorderedClassInvocationInterceptorExample::create();
        $interceptedService = StubCallSavingService::create();
        $methodInvocation = MethodInvoker::createWith(
            InterfaceToCall::create($interceptedService, 'callWithStdClassAndIntArgument'),
            $interceptedService, [
            PayloadBuilder::create("some"),
            HeaderBuilder::create("number", "number")
        ], InMemoryReferenceSearchService::createWith([
            CallWithUnorderedClassInvocationInterceptorExample::class => $interceptingService1
        ]), InMemoryChannelResolver::createEmpty(),
            [
                AroundInterceptorReference::createWithNoPointcut("someId", CallWithUnorderedClassInvocationInterceptorExample::class, "callWithUnorderedClassInvocation")
            ]
        );

        $message = MessageBuilder::withPayload(new stdClass())
            ->setHeader("number", 5)
            ->build();
        $methodInvocation->processMessage($message);

        $this->assertTrue($interceptedService->wasCalled(), "Intercepted Service was not called");
    }

    public function test_calling_interceptor_with_multiple_unordered_arguments()
    {
        $interceptingService1 = CallMultipleUnorderedArgumentsInvocationInterceptorExample::create();
        $interceptedService = StubCallSavingService::create();
        $methodInvocation = MethodInvoker::createWith(
            InterfaceToCall::create($interceptedService, 'callWithMultipleArguments'),
            $interceptedService, [
            PayloadBuilder::create("some"),
            HeaderBuilder::create("numbers", "numbers"),
            HeaderBuilder::create("strings", "strings")
        ], InMemoryReferenceSearchService::createWith([
            CallMultipleUnorderedArgumentsInvocationInterceptorExample::class => $interceptingService1
        ]), InMemoryChannelResolver::createEmpty(),
            [
                AroundInterceptorReference::createWithNoPointcut("someId", CallMultipleUnorderedArgumentsInvocationInterceptorExample::class, "callMultipleUnorderedArgumentsInvocation")
            ]
        );

        $message = MessageBuilder::withPayload(new stdClass())
            ->setHeader("numbers", [5, 1])
            ->setHeader("strings", ["string1", "string2"])
            ->build();
        $methodInvocation->processMessage($message);

        $this->assertTrue($interceptedService->wasCalled(), "Intercepted Service was not called");
    }

    public function test_passing_through_message_when_calling_interceptor_without_method_invocation()
    {
        $interceptingService1 = CallWithPassThroughInterceptorExample::create();
        $interceptedService = StubCallSavingService::createWithReturnType("some");
        $interfaceToCall = InterfaceToCall::create($interceptedService, 'callWithReturn');

        $methodInvocation = MethodInvoker::createWith(
            $interfaceToCall, $interceptedService,  [], InMemoryReferenceSearchService::createWith([
            CallWithPassThroughInterceptorExample::class => $interceptingService1
        ]), InMemoryChannelResolver::createEmpty(),
            [AroundInterceptorReference::createWithNoPointcut("someId", CallWithPassThroughInterceptorExample::class, "callWithPassThrough")]
        );

        $this->assertEquals(
            "some",
            $methodInvocation->processMessage(MessageBuilder::withPayload(new stdClass())->build())
        );
    }

    public function test_calling_interceptor_with_intercepted_object_instance()
    {
        $interceptingService1 = CallWithInterceptedObjectInterceptorExample::create();
        $interceptedService = StubCallSavingService::createWithReturnType("some");
        $methodInvocation = MethodInvoker::createWith(
            InterfaceToCall::create($interceptedService, 'callWithReturn'), $interceptedService,  [], InMemoryReferenceSearchService::createWith([
            CallWithInterceptedObjectInterceptorExample::class => $interceptingService1
        ]), InMemoryChannelResolver::createEmpty(),
            [AroundInterceptorReference::createWithNoPointcut("someId", CallWithInterceptedObjectInterceptorExample::class, "callWithInterceptedObject")]
        );

        $this->assertEquals(
            "some",
            $methodInvocation->processMessage(MessageBuilder::withPayload(new stdClass())->build())
        );
    }

    public function test_calling_interceptor_with_request_message()
    {
        $interceptingService1 = CallWithRequestMessageInterceptorExample::create();
        $interceptedService = StubCallSavingService::createWithReturnType("some");
        $methodInvocation = MethodInvoker::createWith(
            InterfaceToCall::create($interceptedService, 'callWithReturn'),
            $interceptedService, [], InMemoryReferenceSearchService::createWith([
            CallWithRequestMessageInterceptorExample::class => $interceptingService1
        ]), InMemoryChannelResolver::createEmpty(),
            [AroundInterceptorReference::createWithNoPointcut("someId", CallWithRequestMessageInterceptorExample::class, "callWithRequestMessage")]
        );

        $requestMessage = MessageBuilder::withPayload(new stdClass())->build();
        $this->assertEquals(
            $requestMessage,
            $methodInvocation->processMessage($requestMessage)
        );
    }

    public function test_not_throwing_exception_when_can_not_resolve_argument_when_parameter_is_nullable()
    {
        $interceptingService1 = CallWithNullableStdClassInterceptorExample::create();
        $interceptedService = StubCallSavingService::createWithReturnType("some");
        $interfaceToCall = InterfaceToCall::create($interceptedService, 'callWithReturn');

        $methodInvocation = MethodInvoker::createWith(
            $interfaceToCall, $interceptedService, [], InMemoryReferenceSearchService::createWith([
            CallWithNullableStdClassInterceptorExample::class => $interceptingService1
        ]), InMemoryChannelResolver::createEmpty(),
            [AroundInterceptorReference::createWithNoPointcut("someId", CallWithNullableStdClassInterceptorExample::class, "callWithNullableStdClass")]
        );

        $requestMessage = MessageBuilder::withPayload("test")->build();
        $this->assertNull($methodInvocation->processMessage($requestMessage));
    }

    public function test_throwing_exception_if_cannot_resolve_arguments_for_interceptor()
    {
        $interceptingService1 = CallMultipleUnorderedArgumentsInvocationInterceptorExample::create();
        $interceptedService = StubCallSavingService::createWithReturnType("some");
        $interfaceToCall = InterfaceToCall::create($interceptedService, 'callWithReturn');

        $methodInvocation = MethodInvoker::createWith(
            $interfaceToCall, $interceptedService, [], InMemoryReferenceSearchService::createWith([
            CallMultipleUnorderedArgumentsInvocationInterceptorExample::class => $interceptingService1
        ]), InMemoryChannelResolver::createEmpty(),
            [AroundInterceptorReference::createWithNoPointcut("someId", CallMultipleUnorderedArgumentsInvocationInterceptorExample::class, "callMultipleUnorderedArgumentsInvocation")]
        );

        $this->expectException(MethodInvocationException::class);

        $this->assertEquals(
            "some",
            $methodInvocation->processMessage(MessageBuilder::withPayload(new stdClass())->build())
        );
    }

    public function test_calling_interceptor_with_method_annotation()
    {
        $interceptingService1 = CallWithAnnotationFromMethodInterceptorExample::create();
        $interceptedService = StubCallSavingService::createWithReturnType("some");
        $methodInvocation = MethodInvoker::createWith(
            InterfaceToCall::create($interceptedService, 'methodWithAnnotation'),
            $interceptedService, [], InMemoryReferenceSearchService::createWith([
            CallWithAnnotationFromMethodInterceptorExample::class => $interceptingService1
        ]), InMemoryChannelResolver::createEmpty(),
            [AroundInterceptorReference::createWithNoPointcut("someId", CallWithAnnotationFromMethodInterceptorExample::class, "callWithMethodAnnotation")]
        );

        $requestMessage = MessageBuilder::withPayload("test")->build();
        $this->assertNull($methodInvocation->processMessage($requestMessage));
    }

    public function test_calling_interceptor_with_endpoint_annotation()
    {
        $interceptedService = StubCallSavingService::createWithReturnType("some");
        $methodInvocation = MethodInvoker::createWith(
            InterfaceToCall::create($interceptedService, 'methodWithAnnotation'), $interceptedService , [], InMemoryReferenceSearchService::createEmpty(), InMemoryChannelResolver::createEmpty(),
            [AroundInterceptorReference::createWithDirectObject("someId", CallWithStdClassInterceptorExample::create(), "callWithStdClass", 0, "")],
            [
                new stdClass()
            ]
        );

        $requestMessage = MessageBuilder::withPayload("test")->build();
        $this->assertNull($methodInvocation->processMessage($requestMessage));
    }

    public function test_calling_interceptor_with_reference_search_service()
    {
        $interceptingService1 = CallWithReferenceSearchServiceExample::create();
        $interceptedService = StubCallSavingService::createWithReturnType("some");
        $methodInvocation = MethodInvoker::createWith(
            InterfaceToCall::create($interceptedService, 'methodWithAnnotation'),
            $interceptedService, [], InMemoryReferenceSearchService::createWith([
            CallWithReferenceSearchServiceExample::class => $interceptingService1
        ]), InMemoryChannelResolver::createEmpty(),
            [AroundInterceptorReference::createWithNoPointcut("someId", CallWithReferenceSearchServiceExample::class, "call")]
        );

        $requestMessage = MessageBuilder::withPayload("test")->build();
        $this->assertNull($methodInvocation->processMessage($requestMessage));
    }

    public function test_throwing_exception_if_registering_around_method_interceptor_with_return_value_but_without_method_invocation()
    {
        $interceptingService1 = CalculatingService::create(0);
        $interceptedService = StubCallSavingService::createWithReturnType("some");
        $interfaceToCall = InterfaceToCall::create($interceptedService, 'callWithReturn');

        $this->expectException(InvalidArgumentException::class);

        MethodInvoker::createWith(
            $interfaceToCall, $interceptedService, [], InMemoryReferenceSearchService::createWith([
            CalculatingService::class => $interceptingService1
        ]), InMemoryChannelResolver::createEmpty(),
            [AroundInterceptorReference::createWithNoPointcut("someId", CalculatingService::class, "sum")]
        );
    }

    public function test_passing_endpoint_annotation()
    {
        $interceptingService1 = CallWithStdClassInterceptorExample::create();
        $interceptedService = StubCallSavingService::createWithReturnType("some");
        $interfaceToCall = InterfaceToCall::create($interceptedService, 'methodWithAnnotation');

        $methodInvocation = MethodInvoker::createWith(
            $interfaceToCall, $interceptedService, [], InMemoryReferenceSearchService::createWith([
            CallWithStdClassInterceptorExample::class => $interceptingService1
        ]), InMemoryChannelResolver::createEmpty(),
            [AroundInterceptorReference::createWithNoPointcut("someId", CallWithStdClassInterceptorExample::class, "callWithStdClass")],
            [new stdClass()]
        );

        $requestMessage = MessageBuilder::withPayload("test")->build();
        $this->assertNull($methodInvocation->processMessage($requestMessage));
    }

    public function test_passing_payload_if_compatible()
    {
        $interceptingService1 = CallWithStdClassInterceptorExample::create();
        $interceptedService = StubCallSavingService::createWithReturnType("some");
        $interfaceToCall = InterfaceToCall::create($interceptedService, 'callWithMessage');

        $methodInvocation = MethodInvoker::createWith(
            $interfaceToCall, $interceptedService, [],
            InMemoryReferenceSearchService::createWith([
            CallWithStdClassInterceptorExample::class => $interceptingService1
        ]), InMemoryChannelResolver::createEmpty(),
            [AroundInterceptorReference::createWithNoPointcut("someId", CallWithStdClassInterceptorExample::class, "callWithStdClass")],
            []
        );

        $requestMessage = MessageBuilder::withPayload(new stdClass())
            ->setContentType(MediaType::createApplicationXPHPWithTypeParameter(stdClass::class))
            ->build();
        $this->assertNull($methodInvocation->processMessage($requestMessage));
    }

    public function test_passing_headers_if_compatible()
    {
        $interceptingService1 = CallWithStdClassInterceptorExample::create();
        $interceptedService = StubCallSavingService::createWithReturnType("some");
        $interfaceToCall = InterfaceToCall::create($interceptedService, 'callWithMessage');

        $methodInvocation = MethodInvoker::createWith(
            $interfaceToCall, $interceptedService, [],
            InMemoryReferenceSearchService::createWith([
            CallWithStdClassInterceptorExample::class => $interceptingService1
        ]), InMemoryChannelResolver::createEmpty(),
            [AroundInterceptorReference::createWithNoPointcut("someId", CallWithStdClassInterceptorExample::class, "callWithStdClassAndHeaders")],
            []
        );

        $mediaType = MediaType::createApplicationXPHPWithTypeParameter(stdClass::class);
        $requestMessage = MessageBuilder::withPayload(new stdClass())
            ->setContentType($mediaType)
            ->setHeader("token", "123")
            ->build();

        $methodInvocation->processMessage($requestMessage);
        $headers = $interceptingService1->getCalledHeaders();
        unset($headers[MessageHeaders::MESSAGE_ID], $headers[MessageHeaders::TIMESTAMP]);

        $this->assertEquals(
            [
                "token" => "123",
                MessageHeaders::CONTENT_TYPE => $mediaType->toString()
            ],
            $headers
        );
    }
}