<?php


namespace Ecotone\Modelling\Config;
use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\NoExternalConfigurationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Handler\Gateway\GatewayProxyBuilder;
use Ecotone\Messaging\Handler\Gateway\ParameterToMessageConverter\GatewayHeaderBuilder;
use Ecotone\Messaging\Handler\Gateway\ParameterToMessageConverter\GatewayHeadersBuilder;
use Ecotone\Messaging\Handler\Gateway\ParameterToMessageConverter\GatewayPayloadBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\AllHeadersBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\HeaderBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\PayloadBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\ReferenceBuilder;
use Ecotone\Messaging\Handler\ServiceActivator\ServiceActivatorBuilder;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Modelling\Annotation\Aggregate;
use Ecotone\Modelling\Annotation\Distributed;
use Ecotone\Modelling\Annotation\EventHandler;
use Ecotone\Modelling\CommandBus;
use Ecotone\Modelling\DistributionEntrypoint;
use Ecotone\Modelling\EventBus;
use Ecotone\Modelling\MessageHandling\Distribution\DistributedMessageHandler;

#[ModuleAnnotation]
class DistributedGatewayModule extends NoExternalConfigurationModule implements AnnotationModule
{
    private array $distributedEventHandlerRoutingKeys;
    private array $distributedCommandHandlerRoutingKeys;

    public function __construct(array $distributedEventHandlerRoutingKeys, array $distributedCommandHandlerRoutingKeys)
    {
        $this->distributedEventHandlerRoutingKeys   = $distributedEventHandlerRoutingKeys;
        $this->distributedCommandHandlerRoutingKeys = $distributedCommandHandlerRoutingKeys;
    }

    public static function create(AnnotationFinder $annotationFinder): static
    {
        return new self(self::getDistributedEventHandlerRoutingKeys($annotationFinder), self::getDistributedCommandHandlerRoutingKeys($annotationFinder));
    }

    public static function getDistributedCommandHandlerRoutingKeys(AnnotationFinder $annotationFinder) : array
    {
        $routingKeys = array_merge(
            ModellingMessageRouterModule::getCommandBusByNamesMapping($annotationFinder, true),
            ModellingMessageRouterModule::getCommandBusByObjectMapping($annotationFinder, true)
        );

        return array_keys($routingKeys);
    }

    public static function getDistributedEventHandlerRoutingKeys(AnnotationFinder $annotationFinder) : array
    {
        $routingKeys = array_merge(
            ModellingMessageRouterModule::getEventBusByNamesMapping($annotationFinder, true),
            ModellingMessageRouterModule::getEventBusByObjectsMapping($annotationFinder, true)
        );

        return array_keys($routingKeys);
    }

    public function prepare(Configuration $configuration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService): void
    {
        $configuration->registerGatewayBuilder(
            GatewayProxyBuilder::create(DistributionEntrypoint::class, DistributionEntrypoint::class, "distribute", DistributionEntrypoint::DISTRIBUTED_CHANNEL)
                ->withParameterConverters([
                    GatewayPayloadBuilder::create("payload"),
                    GatewayHeadersBuilder::create("metadata"),
                    GatewayHeaderBuilder::create("payloadType", DistributionEntrypoint::DISTRIBUTED_PAYLOAD_TYPE),
                    GatewayHeaderBuilder::create("routingKey", DistributionEntrypoint::DISTRIBUTED_ROUTING_KEY),
                    GatewayHeaderBuilder::create("mediaType", MessageHeaders::CONTENT_TYPE)
                ])
        );
        $configuration->registerMessageHandler(
            ServiceActivatorBuilder::createWithDirectReference(new DistributedMessageHandler($this->distributedEventHandlerRoutingKeys, $this->distributedCommandHandlerRoutingKeys), "handle")
                ->withInputChannelName(DistributionEntrypoint::DISTRIBUTED_CHANNEL)
                ->withMethodParameterConverters([
                    PayloadBuilder::create("payload"),
                    AllHeadersBuilder::createWith("metadata"),
                    HeaderBuilder::create("payloadType", DistributionEntrypoint::DISTRIBUTED_PAYLOAD_TYPE),
                    HeaderBuilder::create("routingKey", DistributionEntrypoint::DISTRIBUTED_ROUTING_KEY),
                    HeaderBuilder::create("contentType", MessageHeaders::CONTENT_TYPE),
                    ReferenceBuilder::create("commandBus", CommandBus::class),
                    ReferenceBuilder::create("eventBus", EventBus::class),
                ])
        );
    }

    public function canHandle($extensionObject): bool
    {
        return false;
    }
}