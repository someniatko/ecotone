<?php
declare(strict_types=1);

namespace Test\SimplyCodedSoftware\Messaging\Unit\Handler\Transformer;

use SimplyCodedSoftware\Messaging\Channel\DirectChannel;
use SimplyCodedSoftware\Messaging\Channel\QueueChannel;
use SimplyCodedSoftware\Messaging\Config\InMemoryChannelResolver;
use SimplyCodedSoftware\Messaging\Conversion\MediaType;
use SimplyCodedSoftware\Messaging\Handler\ExpressionEvaluationService;
use SimplyCodedSoftware\Messaging\Handler\InMemoryReferenceSearchService;
use SimplyCodedSoftware\Messaging\Handler\Processor\MethodInvoker\ConverterBuilder;
use SimplyCodedSoftware\Messaging\Handler\Processor\MethodInvoker\HeaderBuilder;
use SimplyCodedSoftware\Messaging\Handler\Processor\MethodInvoker\MessageToHeaderParameterConverterBuilder;
use SimplyCodedSoftware\Messaging\Handler\Processor\MethodInvoker\AroundInterceptorReference;
use SimplyCodedSoftware\Messaging\Handler\Processor\MethodInvoker\PayloadBuilder;
use SimplyCodedSoftware\Messaging\Handler\SymfonyExpressionEvaluationAdapter;
use SimplyCodedSoftware\Messaging\Handler\Transformer\TransformerBuilder;
use SimplyCodedSoftware\Messaging\Handler\TypeDescriptor;
use SimplyCodedSoftware\Messaging\Support\InvalidArgumentException;
use SimplyCodedSoftware\Messaging\Support\MessageBuilder;
use Test\SimplyCodedSoftware\Messaging\Fixture\Annotation\Interceptor\CalculatingServiceInterceptorExample;
use Test\SimplyCodedSoftware\Messaging\Fixture\Service\ServiceExpectingMessageAndReturningMessage;
use Test\SimplyCodedSoftware\Messaging\Fixture\Service\ServiceExpectingOneArgument;
use Test\SimplyCodedSoftware\Messaging\Fixture\Service\ServiceExpectingTwoArguments;
use Test\SimplyCodedSoftware\Messaging\Fixture\Service\ServiceWithoutReturnValue;
use Test\SimplyCodedSoftware\Messaging\Fixture\Service\ServiceWithReturnValue;
use Test\SimplyCodedSoftware\Messaging\Unit\MessagingTest;

/**
 * Class TransformerBuilder
 * @package SimplyCodedSoftware\Messaging\Handler\Transformer
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 */
class TransformerBuilderTest extends MessagingTest
{
    /**
     * @throws \Exception
     * @throws \SimplyCodedSoftware\Messaging\MessagingException
     */
    public function test_passing_message_to_transforming_class_if_there_is_type_hint_for_it()
    {
        $payload = 'some';
        $outputChannel = QueueChannel::create();
        $outputChannelName = "output";
        $objectToInvoke = "objecToInvoke";
        $transformer = TransformerBuilder::create($objectToInvoke, 'send')
                            ->withOutputMessageChannel($outputChannelName)
                            ->build(
                                InMemoryChannelResolver::createFromAssociativeArray([
                                    $outputChannelName => $outputChannel
                                ]),
                                InMemoryReferenceSearchService::createWith([
                                    $objectToInvoke => ServiceExpectingMessageAndReturningMessage::create($payload)
                                ])
                            );

        $transformer->handle(MessageBuilder::withPayload('some123')->build());

        $this->assertMessages(
            MessageBuilder::withPayload($payload)
                ->build(),
            $outputChannel->receive()
        );
    }

    /**
     * @throws InvalidArgumentException
     * @throws \Exception
     * @throws \SimplyCodedSoftware\Messaging\MessagingException
     */
    public function test_passing_message_payload_as_default()
    {
        $payload = 'someBigPayload';
        $outputChannel = QueueChannel::create();
        $outputChannelName = 'output';
        $objectToInvokeReference = "service-a";
        $transformer = TransformerBuilder::create($objectToInvokeReference, 'withReturnValue')
                            ->withOutputMessageChannel($outputChannelName)
                            ->build(
                                InMemoryChannelResolver::createFromAssociativeArray([
                                    $outputChannelName => $outputChannel
                                ]),
                                InMemoryReferenceSearchService::createWith([
                                    $objectToInvokeReference => ServiceExpectingOneArgument::create()
                                ])
                            );

        $transformer->handle(MessageBuilder::withPayload($payload)->build());

        $this->assertMessages(
            MessageBuilder::withPayload($payload)
                ->setContentType(MediaType::createApplicationXPHPObjectWithTypeParameter(TypeDescriptor::STRING))
                ->build(),
            $outputChannel->receive()
        );
    }

    /**
     * @throws \SimplyCodedSoftware\Messaging\MessagingException
     */
    public function test_throwing_exception_if_void_method_provided_for_transformation()
    {
        $this->expectException(InvalidArgumentException::class);

        $outputChannelName = "outputChannelName";
        $objectToInvokeReference = "service-a";
        TransformerBuilder::create( $objectToInvokeReference, 'setName')
                            ->withOutputMessageChannel($outputChannelName)
                            ->build(
                                InMemoryChannelResolver::createFromAssociativeArray([
                                    $outputChannelName => QueueChannel::create()
                                ]),
                                InMemoryReferenceSearchService::createWith([
                                    $objectToInvokeReference => ServiceWithoutReturnValue::create()
                                ])
                            );
    }

    /**
     * @throws \Exception
     * @throws \SimplyCodedSoftware\Messaging\MessagingException
     */
    public function test_not_sending_message_to_output_channel_if_transforming_method_returns_null()
    {
        $outputChannel = QueueChannel::create();
        $outputChannelName = "output";
        $objectToInvokeReference = "service-a";
        $transformer = TransformerBuilder::create($objectToInvokeReference, 'withNullReturnValue')
                        ->withOutputMessageChannel($outputChannelName)
                        ->build(
                            InMemoryChannelResolver::createFromAssociativeArray([
                                $outputChannelName => $outputChannel
                            ]),
                            InMemoryReferenceSearchService::createWith([
                                $objectToInvokeReference => ServiceExpectingOneArgument::create()
                            ])
                        );

        $transformer->handle(MessageBuilder::withPayload('some')->build());

        $this->assertNull($outputChannel->receive());
    }

    /**
     * @throws \Exception
     * @throws \SimplyCodedSoftware\Messaging\MessagingException
     */
    public function test_transforming_headers_if_array_returned_by_transforming_method()
    {
        $payload = 'someBigPayload';
        $outputChannel = QueueChannel::create();
        $inputChannelName = "input";
        $outputChannelName = "output";
        $objectToInvokeReference = "service-a";
        $transformer = TransformerBuilder::create($objectToInvokeReference, 'withArrayReturnValue')
                            ->withOutputMessageChannel($outputChannelName)
                            ->build(
                                InMemoryChannelResolver::createFromAssociativeArray([
                                    $inputChannelName => DirectChannel::create(),
                                    $outputChannelName => $outputChannel
                                ]),
                                InMemoryReferenceSearchService::createWith([
                                    $objectToInvokeReference => ServiceExpectingOneArgument::create()
                                ])
                            );

        $transformer->handle(
            MessageBuilder::withPayload($payload)
                ->setContentType(MediaType::createApplicationXPHPObject())
                ->build()
        );

        $this->assertMessages(
            MessageBuilder::withPayload($payload)
                ->setHeader('0', $payload)
                ->setContentType(MediaType::createApplicationXPHPObject())
                ->build(),
            $outputChannel->receive()
        );
    }

    /**
     * @throws \Exception
     * @throws \SimplyCodedSoftware\Messaging\MessagingException
     */
    public function test_transforming_headers_if_array_returned_and_message_payload_is_also_array()
    {
        $payload = ["some payload"];
        $outputChannel = QueueChannel::create();
        $outputChannelName = "output";
        $objectToInvokeReference = "service-a";
        $transformer = TransformerBuilder::create($objectToInvokeReference, 'withArrayTypeHintAndArrayReturnValue')
                        ->withOutputMessageChannel($outputChannelName)
                        ->build(
                            InMemoryChannelResolver::createFromAssociativeArray([
                                $outputChannelName => $outputChannel
                            ]),
                            InMemoryReferenceSearchService::createWith([
                                $objectToInvokeReference => ServiceExpectingOneArgument::create()
                            ])
                        );

        $transformer->handle(MessageBuilder::withPayload($payload)->build());

        $this->assertMessages(
            MessageBuilder::withPayload($payload)
                ->setHeader('0', "some payload")
                ->build(),
            $outputChannel->receive()
        );
    }

    /**
     * @throws InvalidArgumentException
     * @throws \Exception
     * @throws \SimplyCodedSoftware\Messaging\MessagingException
     */
    public function test_transforming_with_custom_method_arguments_converters()
    {
        $payload = 'someBigPayload';
        $headerValue = 'abc';
        $outputChannel = QueueChannel::create();
        $outputChannelName = 'output';
        $objectToInvokeReference = "service-a";
        $transformerBuilder = TransformerBuilder::create($objectToInvokeReference, 'withReturnValue')
                                ->withOutputMessageChannel($outputChannelName);
        $transformerBuilder->withMethodParameterConverters([
            PayloadBuilder::create('name'),
            HeaderBuilder::create('surname', 'token')
        ]);
        $transformer = $transformerBuilder
            ->build(
                InMemoryChannelResolver::createFromAssociativeArray([
                    $outputChannelName => $outputChannel
                ]),
                InMemoryReferenceSearchService::createWith([
                    $objectToInvokeReference => ServiceExpectingTwoArguments::create()
                ])
            );

        $transformer->handle(
            MessageBuilder::withPayload($payload)
                ->setHeader('token', $headerValue)
                ->build()
        );

        $this->assertMessages(
            MessageBuilder::withPayload($payload . $headerValue)
                ->setHeader('token', $headerValue)
                ->setContentType(MediaType::createApplicationXPHPObjectWithTypeParameter(TypeDescriptor::STRING))
                ->build(),
            $outputChannel->receive()
        );
    }

    /**
     * @throws InvalidArgumentException
     * @throws \Exception
     * @throws \SimplyCodedSoftware\Messaging\MessagingException
     */
    public function test_transforming_with_header_enricher()
    {
        $payload = 'someBigPayload';
        $headerValue = 'abc';
        $outputChannel = QueueChannel::create();
        $inputChannelName = "input";
        $outputChannelName = "output";
        $transformer = TransformerBuilder::createHeaderEnricher([
                "token" => $headerValue,
                "correlation-id" => 1
            ])
            ->withOutputMessageChannel($outputChannelName)
            ->build(
                InMemoryChannelResolver::createFromAssociativeArray([
                    $inputChannelName => DirectChannel::create(),
                    $outputChannelName => $outputChannel
                ]),
                InMemoryReferenceSearchService::createEmpty()
            );

        $transformer->handle(
            MessageBuilder::withPayload($payload)
                ->build()
        );

        $this->assertMessages(
            MessageBuilder::withPayload($payload)
                ->setHeader('token', $headerValue)
                ->setHeader('correlation-id', 1)
                ->build(),
            $outputChannel->receive()
        );
    }

    /**
     * @throws InvalidArgumentException
     * @throws \Exception
     * @throws \SimplyCodedSoftware\Messaging\MessagingException
     */
    public function test_transforming_with_transformer_instance_of_object()
    {
        $referenceObject = ServiceWithReturnValue::create();

        $transformer = TransformerBuilder::createWithReferenceObject($referenceObject, "getName")
            ->build(
                InMemoryChannelResolver::createEmpty(),
                InMemoryReferenceSearchService::createEmpty()
            );

        $replyChannel = QueueChannel::create();
        $transformer->handle(MessageBuilder::withPayload("some")->setReplyChannel($replyChannel)->build());

        $this->assertMessages(
            MessageBuilder::withPayload("johny")
                ->setContentType(MediaType::createApplicationXPHPObjectWithTypeParameter(TypeDescriptor::STRING))
                ->setReplyChannel($replyChannel)
                ->build(),
            $replyChannel->receive()
        );
    }

    public function test_transforming_payload_using_expression()
    {
        $payload = 13;
        $outputChannel = QueueChannel::create();

        $transformer = TransformerBuilder::createWithExpression("payload + 3")
            ->build(
                InMemoryChannelResolver::createEmpty(),
                InMemoryReferenceSearchService::createWith([
                    ExpressionEvaluationService::REFERENCE => SymfonyExpressionEvaluationAdapter::create()
                ])
            );

        $transformer->handle(
            MessageBuilder::withPayload($payload)
                ->setReplyChannel($outputChannel)
                ->build()
        );

        $this->assertEquals(
            16,
            $outputChannel->receive()->getPayload()
        );
    }

    public function test_converting_to_string()
    {
        $inputChannelName = 'inputChannel';
        $endpointName = "someName";

        $this->assertEquals(
            TransformerBuilder::create("ref-name", "method-name")
                ->withInputChannelName($inputChannelName)
                ->withEndpointId($endpointName),
            sprintf("Transformer - %s:%s with name `%s` for input channel `%s`", "ref-name", "method-name", $endpointName, $inputChannelName)
        );
    }

    /**
     * @throws \SimplyCodedSoftware\Messaging\MessagingException
     */
    public function test_creating_with_interceptors()
    {
        $objectToInvoke = CalculatingServiceInterceptorExample::create(0);
        $replyChannel = QueueChannel::create();

        $serviceActivator = TransformerBuilder::createWithReferenceObject($objectToInvoke, "result")
            ->withInputChannelName("someName")
            ->withEndpointId("someEndpoint")
            ->addAroundInterceptor(AroundInterceptorReference::create("calculator1",CalculatingServiceInterceptorExample::class, "sum", 2, ""))
            ->addAroundInterceptor(AroundInterceptorReference::create("calculator2",CalculatingServiceInterceptorExample::class, "multiply", 1, ""))
            ->build(InMemoryChannelResolver::createEmpty(), InMemoryReferenceSearchService::createWith([
                CalculatingServiceInterceptorExample::class => CalculatingServiceInterceptorExample::create(4)
            ]));

        $serviceActivator->handle(MessageBuilder::withPayload(2)->setReplyChannel($replyChannel)->build());

        $this->assertEquals(
            12,
            $replyChannel->receive()->getPayload()
        );
    }
}