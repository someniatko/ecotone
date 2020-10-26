<?php

namespace Test\Ecotone\Messaging\Fixture\Handler\Processor\Interceptor;

use Ecotone\Messaging\Annotation\Interceptor\Around;
use Ecotone\Messaging\Annotation\Interceptor\MethodInterceptor;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation;

class CallWithProceedingInterceptorExample extends BaseInterceptorExample
{
    #[Around]
    public function callWithProceeding(MethodInvocation $methodInvocation) : void
    {
        $methodInvocation->proceed();
        $this->markAsCalled();
    }
}