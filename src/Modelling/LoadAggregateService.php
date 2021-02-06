<?php declare(strict_types=1);

namespace Ecotone\Modelling;

use Ecotone\Messaging\Handler\Enricher\PropertyPath;
use Ecotone\Messaging\Handler\Enricher\PropertyReaderAccessor;
use Ecotone\Messaging\Handler\TypeDescriptor;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\NullableMessageChannel;
use Ecotone\Messaging\Support\Assert;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Ecotone\Messaging\Support\MessageBuilder;

/**
 * Class LoadAggregateService
 * @package Ecotone\Modelling
 * @author  Dariusz Gafka <dgafka.mail@gmail.com>
 * @internal
 */
class LoadAggregateService
{
    private StandardRepository|EventSourcedRepository $aggregateRepository;
    private string $aggregateClassName;
    private string $aggregateMethod;
    private PropertyReaderAccessor $propertyReaderAccessor;
    private ?array $commandVersionMapping;
    private ?string $eventSourcedFactoryMethod;
    private LoadAggregateMode $loadAggregateMode;
    private bool $isEventSourced;

    public function __construct(StandardRepository|EventSourcedRepository $aggregateRepository, string $aggregateClassName, bool $isEventSourced, string $aggregateMethod, ?array $commandVersionMapping, PropertyReaderAccessor $propertyReaderAccessor, ?string $eventSourcedFactoryMethod, LoadAggregateMode $loadAggregateMode)
    {
        $this->aggregateRepository          = $aggregateRepository;
        $this->aggregateClassName = $aggregateClassName;
        $this->aggregateMethod = $aggregateMethod;
        $this->propertyReaderAccessor = $propertyReaderAccessor;
        $this->commandVersionMapping = $commandVersionMapping;
        $this->eventSourcedFactoryMethod = $eventSourcedFactoryMethod;
        $this->loadAggregateMode = $loadAggregateMode;
        $this->isEventSourced = $isEventSourced;
    }

    /**
     * @param Message $message
     *
     * @return Message
     * @throws AggregateNotFoundException
     * @throws \ReflectionException
     * @throws \Ecotone\Messaging\MessagingException
     */
    public function load(Message $message) : ?Message
    {
        $aggregateIdentifiers = $message->getHeaders()->get(AggregateMessage::AGGREGATE_ID);
        $expectedVersion = null;

        $aggregate = null;
        foreach ($aggregateIdentifiers as $identifierName => $aggregateIdentifier) {
            if (is_null($aggregateIdentifier)) {
                $messageType = TypeDescriptor::createFromVariable($message->getPayload());
                throw AggregateNotFoundException::create("Aggregate identifier {$identifierName} definition found in {$messageType->toString()}, but is null. Can't load aggregate {$this->aggregateClassName} to call {$this->aggregateMethod}.");
            }
        }

        $expectedVersion = $this->commandVersionMapping
            ? (
                $this->propertyReaderAccessor->hasPropertyValue(PropertyPath::createWith(array_key_first($this->commandVersionMapping)), $message->getPayload())
                ? $this->propertyReaderAccessor->getPropertyValue(PropertyPath::createWith(array_key_first($this->commandVersionMapping)), $message->getPayload())
                : null
            )
            : null;

        $aggregate = $this->aggregateRepository->findBy($this->aggregateClassName, $aggregateIdentifiers);
        if ($this->isEventSourced && !is_null($aggregate)) {
            Assert::isIterable($aggregate, "Event Sourced Repository must return iterable events for findBy method");
            $aggregate = call_user_func([$this->aggregateClassName, $this->eventSourcedFactoryMethod], $aggregate);
        }

        if (!$aggregate && $this->loadAggregateMode->isDroppingMessageOnNotFound()) {
            return null;
        }

        if (!$aggregate && $this->loadAggregateMode->isThrowingOnNotFound()) {
            throw AggregateNotFoundException::create("Aggregate {$this->aggregateClassName} for calling {$this->aggregateMethod} was not found using identifiers " . \json_encode($aggregateIdentifiers));
        }

        $messageBuilder = MessageBuilder::fromMessage($message);
        if ($aggregate) {
            $messageBuilder = $messageBuilder->setHeader(AggregateMessage::AGGREGATE_OBJECT, $aggregate);
        }
        if (!is_null($this->commandVersionMapping)) {
            $messageBuilder = $messageBuilder->setHeader(AggregateMessage::TARGET_VERSION, $expectedVersion);
        }

        if (!$message->getHeaders()->containsKey(MessageHeaders::REPLY_CHANNEL)) {
            $messageBuilder = $messageBuilder
                                ->setReplyChannel(NullableMessageChannel::create());
        }

        return $messageBuilder
            ->setHeader(AggregateMessage::AGGREGATE_OBJECT_EXISTS, !is_null($aggregate))
            ->build();
    }
}