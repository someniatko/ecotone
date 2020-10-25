<?php
declare(strict_types=1);

namespace Ecotone\Messaging\Config\Annotation\ModuleConfiguration;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Messaging\Annotation\MessageGateway;
use Ecotone\Messaging\Annotation\ModuleAnnotation;
use Ecotone\Messaging\Annotation\Parameter\Header;
use Ecotone\Messaging\Annotation\Parameter\Headers;
use Ecotone\Messaging\Annotation\Parameter\Payload;
use Ecotone\Messaging\Config\Annotation\AnnotatedDefinitionReference;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Handler\Gateway\GatewayBuilder;
use Ecotone\Messaging\Handler\Gateway\GatewayParameterConverterBuilder;
use Ecotone\Messaging\Handler\Gateway\GatewayProxyBuilder;
use Ecotone\Messaging\Handler\Gateway\ParameterToMessageConverter\GatewayHeaderBuilder;
use Ecotone\Messaging\Handler\Gateway\ParameterToMessageConverter\GatewayHeadersBuilder;
use Ecotone\Messaging\Handler\Gateway\ParameterToMessageConverter\GatewayPayloadBuilder;
use Ecotone\Messaging\Handler\Gateway\ParameterToMessageConverter\GatewayPayloadExpressionBuilder;
use Ecotone\Messaging\Handler\InterfaceParameter;
use Ecotone\Messaging\Handler\InterfaceToCall;

#[ModuleAnnotation]
class GatewayModule extends NoExternalConfigurationModule implements AnnotationModule
{
    public const MODULE_NAME = 'gatewayModule';

    /**
     * @var GatewayBuilder[]
     */
    private array $gatewayBuilders = [];

    /**
     * AnnotationGatewayConfiguration constructor.
     *
     * @param GatewayBuilder[] $gatewayBuilders
     */
    private function __construct(array $gatewayBuilders)
    {
        $this->gatewayBuilders = $gatewayBuilders;
    }

    /**
     * @inheritDoc
     */
    public static function create(AnnotationFinder $annotationRegistrationService): static
    {
        $gatewayBuilders = [];
        foreach ($annotationRegistrationService->findAnnotatedMethods(MessageGateway::class) as $annotationRegistration) {
            /** @var MessageGateway $annotation */
            $annotation      = $annotationRegistration->getAnnotationForMethod();
            $referenceName   = AnnotatedDefinitionReference::getReferenceFor($annotationRegistration);
            $interfaceToCall = InterfaceToCall::create($annotationRegistration->getClassName(), $annotationRegistration->getMethodName());

            $parameterConverters = [];
            foreach ($interfaceToCall->getInterfaceParameters() as $interfaceParameter) {
                $converter = self::getConverterForParameter($interfaceParameter, $referenceName);
                if ($converter) {
                    $parameterConverters[] = $converter;
                }
            }

            $gatewayProxyBuilder = GatewayProxyBuilder::create($referenceName, $annotationRegistration->getClassName(), $annotationRegistration->getMethodName(), $annotation->requestChannel)
                ->withErrorChannel($annotation->errorChannel)
                ->withParameterConverters($parameterConverters)
                ->withRequiredInterceptorNames($annotation->requiredInterceptorNames)
                ->withReplyMillisecondTimeout($annotation->replyTimeoutInMilliseconds);

            if ($annotation->replyContentType) {
                $gatewayProxyBuilder = $gatewayProxyBuilder->withReplyContentType($annotation->replyContentType);
            }

            $gatewayBuilders[] = $gatewayProxyBuilder;
        }

        return new self($gatewayBuilders);
    }

    private static function getConverterForParameter(InterfaceParameter $interfaceParameter, string $referenceName): ?GatewayParameterConverterBuilder
    {
        $annotations = $interfaceParameter->getAnnotations();
        foreach ($annotations as $parameterAnnotation) {
            if ($parameterAnnotation instanceof Payload) {
                if ($parameterAnnotation->getExpression()) {
                    return GatewayPayloadExpressionBuilder::create($interfaceParameter->getName(), $parameterAnnotation->getExpression());
                } else {
                    return GatewayPayloadBuilder::create($interfaceParameter->getName());
                }
            } else if ($parameterAnnotation instanceof Header) {
                if ($parameterAnnotation->getExpression()) {
                    throw ConfigurationException::create("@Header annotation for Gateway ({$referenceName}) cannot be used with expression");
                } else {
                    return GatewayHeaderBuilder::create($interfaceParameter->getName(), $parameterAnnotation->getHeaderName());
                }
            } else if ($parameterAnnotation instanceof Headers) {
                return GatewayHeadersBuilder::create($interfaceParameter->getName());
            }
        }

        return null;
    }

    /**
     * @inheritDoc
     */
    public function canHandle($extensionObject): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return self::MODULE_NAME;
    }

    /**
     * @inheritDoc
     */
    public function prepare(Configuration $configuration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService): void
    {
        foreach ($this->gatewayBuilders as $gatewayBuilder) {
            $configuration->registerGatewayBuilder($gatewayBuilder);
        }
    }
}