<?php

declare(strict_types=1);

namespace AgentAutomation;

final class Scope
{
    public function __construct(
        public readonly string $tenant,
        public readonly string $environment,
        public readonly string $actor,
        public readonly string $role,
    ) {
    }

    public function keys(): array
    {
        return [$this->tenant, $this->environment];
    }

    public function require(string ...$roles): void
    {
        if ($this->role !== 'admin' && !in_array($this->role, $roles, true)) {
            throw Problem::denied();
        }
    }

    public static function environment(mixed $value): string
    {
        if ($value !== 'test' && $value !== 'production') {
            throw Problem::invalid('Environment must be test or production.');
        }
        return $value;
    }
}
