<?php

namespace Magpie\Laravel;

/**
 * Data attached to every event captured while the scope is active.
 */
final class Scope
{
    /** @var array<string, string> */
    private array $tags = [];

    /** @var array<string, mixed> */
    private array $extra = [];

    /** @var array<string, array<string, mixed>> */
    private array $contexts = [];

    /** @var array<string, mixed>|null */
    private ?array $user = null;

    private ?string $level = null;

    private ?string $transaction = null;

    /** @var list<array<string, mixed>> */
    private array $breadcrumbs = [];

    public function setTag(string $key, string|int|float|bool $value): static
    {
        $this->tags[$key] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;

        return $this;
    }

    /** @param  array<string, string|int|float|bool>  $tags */
    public function setTags(array $tags): static
    {
        foreach ($tags as $key => $value) {
            $this->setTag($key, $value);
        }

        return $this;
    }

    public function setExtra(string $key, mixed $value): static
    {
        $this->extra[$key] = $value;

        return $this;
    }

    /** @param  array<string, mixed>  $context */
    public function setContext(string $name, array $context): static
    {
        $this->contexts[$name] = $context;

        return $this;
    }

    /** @param  array<string, mixed>|null  $user  id, email, username, ip_address and any custom keys */
    public function setUser(?array $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function setLevel(?string $level): static
    {
        $this->level = $level;

        return $this;
    }

    public function setTransaction(?string $name): static
    {
        $this->transaction = $name;

        return $this;
    }

    public function transaction(): ?string
    {
        return $this->transaction;
    }

    /** @param  array<string, mixed>  $breadcrumb */
    public function addBreadcrumb(array $breadcrumb, int $max = 100): static
    {
        $this->breadcrumbs[] = $breadcrumb + ['timestamp' => microtime(true), 'level' => 'info', 'type' => 'default'];
        $this->breadcrumbs = array_slice($this->breadcrumbs, -max(0, $max));

        return $this;
    }

    public function clearBreadcrumbs(): static
    {
        $this->breadcrumbs = [];

        return $this;
    }

    public function clear(): static
    {
        $this->tags = $this->extra = $this->contexts = $this->breadcrumbs = [];
        $this->user = $this->level = $this->transaction = null;

        return $this;
    }

    /**
     * Merge this scope into an event. Values already on the event win.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    public function applyTo(array $event): array
    {
        $event['tags'] = ($event['tags'] ?? []) + $this->tags;
        $event['extra'] = ($event['extra'] ?? []) + $this->extra;
        $event['contexts'] = ($event['contexts'] ?? []) + $this->contexts;
        $event['breadcrumbs'] = $this->breadcrumbs;

        if ($this->user !== null) {
            $event['user'] = ($event['user'] ?? []) + $this->user;
        }
        if ($this->level !== null) {
            $event['level'] = $this->level;
        }
        if ($this->transaction !== null && ! isset($event['transaction'])) {
            $event['transaction'] = $this->transaction;
        }

        return array_filter($event, fn ($v) => $v !== [] && $v !== null);
    }
}
