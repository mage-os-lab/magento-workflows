<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Psr\Log;

/**
 * Standalone-runner shim for psr/log. Never loaded when the real package
 * is installed (the shim autoloader is registered last).
 */
interface LoggerInterface
{
    public function emergency(string|\Stringable $message, array $context = []): void;

    public function alert(string|\Stringable $message, array $context = []): void;

    public function critical(string|\Stringable $message, array $context = []): void;

    public function error(string|\Stringable $message, array $context = []): void;

    public function warning(string|\Stringable $message, array $context = []): void;

    public function notice(string|\Stringable $message, array $context = []): void;

    public function info(string|\Stringable $message, array $context = []): void;

    public function debug(string|\Stringable $message, array $context = []): void;

    public function log($level, string|\Stringable $message, array $context = []): void;
}
