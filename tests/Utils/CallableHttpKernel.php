<?php

declare(strict_types=1);

namespace Tests\Baldinof\RoadRunnerBundle\Utils;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\Kernel;

if (Kernel::MAJOR_VERSION >= 6) {
    class CallableHttpKernel implements HttpKernelInterface
    {
        private \Closure $callable;

        public function __construct(\Closure $callable)
        {
            $this->callable = $callable;
        }

        public function handle(Request $request, int $type = self::MASTER_REQUEST, bool $catch = true): Response
        {
            return ($this->callable)($request);
        }
    }
} else {
    class CallableHttpKernel implements HttpKernelInterface
    {
        private \Closure $callable;

        public function __construct(\Closure $callable)
        {
            $this->callable = $callable;
        }

        public function handle(Request $request, $type = self::MASTER_REQUEST, $catch = true)
        {
            return ($this->callable)($request);
        }
    }
}
