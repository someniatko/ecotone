<?php
declare(strict_types=1);

namespace Ecotone\Messaging\Handler\ErrorHandler;


use Ecotone\Messaging\Support\Assert;

final class RetryTemplateBuilder
{
    /**
     * @var int in milliseconds
     */
    private $initialDelay;
    /**
     * @var int
     */
    private $multiplier;
    /**
     * @var int|null
     */
    private $maxDelay;
    /**
     * @var int|null
     */
    private $maxAttempts;

    private function __construct(int $initialDelay, int $multiplier, ?int $maxDelay, ?int $maxAttempts)
    {
        Assert::isTrue($maxAttempts > 0 || is_null($maxAttempts), "Max attempts must be greater than 0");
        Assert::isTrue($maxDelay > 0 || is_null($maxDelay), "Max delay must be greater than 0");
        Assert::isTrue($multiplier > 0, "Multiplier must be greater than 0");
        Assert::isTrue($initialDelay > 0, "Initial delay must be greater than 0");

        $this->initialDelay = $initialDelay;
        $this->multiplier = $multiplier;
        $this->maxDelay = $maxDelay;
        $this->maxAttempts = $maxAttempts;
    }

    /**
     * Perform each retry after fixed amount of time
     */
    public static function fixedBackOff(int $initialDelay) : self
    {
        return new self($initialDelay, 1, null, null);
    }

    public static function exponentialBackoff(int $initialDelay, int $multiplier) : self
    {
        return new self($initialDelay, $multiplier, null, null);
    }

    public static function exponentialBackoffWithMaxDelay(int $initialDelay, int $multiplier, int $maxDelay) : self
    {
        return new self($initialDelay, $multiplier, $maxDelay, null);
    }

    public function maxRetryAttempts(int $maxAttempts) : self
    {
        return new self($this->initialDelay, $this->multiplier, $this->maxDelay, $maxAttempts);
    }

    public function build() : RetryTemplate
    {
        return new RetryTemplate($this->initialDelay, $this->multiplier, $this->maxDelay, $this->maxAttempts);
    }
}