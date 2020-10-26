<?php
declare(strict_types=1);

namespace Ecotone\Messaging\Annotation\Interceptor;

use Ecotone\Messaging\Precedence;

#[\Attribute(\Attribute::TARGET_METHOD)]
class After
{
    public int $precedence;

    public string $pointcut;

    public bool $changeHeaders;

    public function __construct(int $precedence = Precedence::DEFAULT_PRECEDENCE, string $pointcut = "", bool $changeHeaders = false)
    {
        $this->precedence    = $precedence;
        $this->pointcut      = $pointcut;
        $this->changeHeaders = $changeHeaders;
    }

    public function getPrecedence(): int
    {
        return $this->precedence;
    }

    public function getPointcut(): string
    {
        return $this->pointcut;
    }

    public function isChangeHeaders(): bool
    {
        return $this->changeHeaders;
    }
}