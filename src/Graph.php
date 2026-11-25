<?php

declare(strict_types=1);

namespace AgentAutomation;

final class Graph
{
    private const CONTRACTS = [
        'start' => ['in' => [], 'out' => ['message' => 'text', 'sender' => 'email', 'subject' => 'text', 'body' => 'text'],
            'branches' => ['next']],
        'classify' => ['in' => ['subject' => 'text', 'body' => 'text'],
            'out' => ['category' => 'text', 'order_no' => 'text'], 'branches' => ['after_sale', 'inquiry', 'unknown']],
        'ticket' => ['in' => ['message' => 'text', 'category' => 'text', 'order_no' => 'text'],
            'out' => ['ticket' => 'text'], 'branches' => ['next']],
        'order' => ['in' => ['order_no' => 'text'], 'out' => ['order_no' => 'text', 'state' => 'text', 'version' => 'integer'],
            'branches' => ['found', 'missing'], 'binding' => 'orders'],
        'draft' => ['in' => ['sender' => 'email', 'subject' => 'text', 'body' => 'text', 'order_no' => 'text',
            'state' => 'text', 'version' => 'integer'], 'out' => ['draft' => 'draft'], 'branches' => ['ready', 'error'],
            'binding' => 'model'],
        'approval' => ['in' => ['draft' => 'draft'], 'out' => ['approval' => 'approval'],
            'branches' => ['approved', 'rejected'], 'binding' => 'inbox'],
        'deliver' => ['in' => ['approval' => 'approval'], 'out' => ['receipt' => 'text'], 'branches' => ['sent', 'error'],
            'binding' => 'inbox'],
        'stop' => ['in' => [], 'out' => [], 'branches' => []],
    ];

    public static function contracts(): array
    {
        return self::CONTRACTS;
    }

    public static function compile(array $graph): array
    {
        $nodes = $graph['nodes'] ?? null;
        if (array_diff(array_keys($graph), ['nodes']) || !is_array($nodes) || count($nodes) < 2 || count($nodes) > 64) {
            throw Problem::invalid('A graph must contain 2–64 named nodes.');
        }
        foreach ($nodes as $id => $node) {
            Values::identifier($id, 'node id');
            if (!is_array($node) || array_diff(array_keys($node), ['kind', 'inputs', 'edges', 'binding', 'options'])) {
                throw Problem::invalid("Invalid node definition for $id.");
            }
            $type = $node['kind'] ?? null;
            if (!is_string($type) || !array_key_exists($type, self::CONTRACTS) || ($id === 'start') !== ($type === 'start')) {
                throw Problem::invalid("Unknown or misplaced node type for $id.");
            }
            $spec = self::CONTRACTS[$type];
            $node['inputs'] = self::exactMap($node['inputs'] ?? [], $spec['in'], "$id inputs");
            $node['edges'] = self::exactMap($node['edges'] ?? [], array_fill_keys($spec['branches'], true), "$id edges");
            foreach ($node['edges'] as $next) {
                if (!is_string($next) || !isset($nodes[$next]) || $next === 'start') {
                    throw Problem::invalid("Node $id points to an unknown or invalid next node.");
                }
            }
            if (isset($spec['binding'])) {
                $node['binding'] = Values::identifier($node['binding'] ?? null, "$id connection alias");
            } elseif (array_key_exists('binding', $node)) {
                throw Problem::invalid("Node $id does not accept a connection.");
            }
            $node['options'] = self::options($id, $type, $node['options'] ?? []);
            $nodes[$id] = $node;
        }
        if (!isset($nodes['start'])) {
            throw Problem::invalid('A start node is required.');
        }
        ksort($nodes, SORT_STRING);
        $order = self::order($nodes);
        $parents = array_fill_keys(array_keys($nodes), []);
        foreach ($nodes as $id => $node) {
            foreach (array_unique($node['edges']) as $next) {
                $parents[$next][] = $id;
            }
        }
        $available = ['start' => []];
        foreach (array_slice($order, 1) as $id) {
            $sets = [];
            foreach ($parents[$id] as $parent) {
                foreach ($nodes[$parent]['edges'] as $edge => $target) {
                    if ($target !== $id) {
                        continue;
                    }
                    $sets[] = self::outputsOn($nodes[$parent]['kind'], $edge)
                        ? [...$available[$parent], $parent]
                        : $available[$parent];
                }
            }
            $available[$id] = count($sets) === 1 ? $sets[0] : array_values(array_intersect(...$sets));
            foreach ($nodes[$id]['inputs'] as $name => $reference) {
                self::validateReference($nodes, $available[$id], $id, $name, $reference);
            }
            if ($nodes[$id]['kind'] === 'draft') {
                $inputs = $nodes[$id]['inputs'];
                $orderNodes = array_map(static fn (string $port): string => explode('.', $inputs[$port], 2)[0],
                    ['order_no', 'state', 'version']);
                if (count(array_unique($orderNodes)) !== 1) {
                    throw Problem::invalid('A draft must use one completed order lookup as its business evidence.');
                }
            }
        }
        return ['nodes' => $nodes, 'order' => $order];
    }

    public static function bindingKinds(array $graph): array
    {
        $bindings = [];
        foreach ($graph['nodes'] as $node) {
            if (!isset($node['binding'])) {
                continue;
            }
            $kind = self::CONTRACTS[$node['kind']]['binding'];
            $alias = $node['binding'];
            if (isset($bindings[$alias]) && $bindings[$alias] !== $kind) {
                throw Problem::invalid("Connection alias $alias is used for incompatible capabilities.");
            }
            $bindings[$alias] = $kind;
        }
        ksort($bindings, SORT_STRING);
        return $bindings;
    }

    private static function options(string $id, string $kind, mixed $options): array
    {
        $keys = match ($kind) {
            'order' => ['human_correction'],
            'draft' => ['mode', 'prompt_revision', 'max_tokens'],
            'approval' => ['expires_minutes'],
            'stop' => ['result'],
            default => [],
        };
        if (!is_array($options) || array_diff(array_keys($options), $keys)) {
            throw Problem::invalid("Unknown options for $id.");
        }
        return match ($kind) {
            'order' => ['human_correction' => match ($options['human_correction'] ?? false) {
                true => true, false => false, default => throw Problem::invalid('Invalid human correction setting.'),
            }],
            'draft' => [
                'mode' => match ($options['mode'] ?? 'rule') {
                    'rule' => 'rule', 'live' => 'live', 'replay' => 'replay',
                    default => throw Problem::invalid('Unsupported draft mode.'),
                },
                'prompt_revision' => Values::identifier($options['prompt_revision'] ?? 'customer-reply-v1', 'prompt revision'),
                'max_tokens' => Values::number($options['max_tokens'] ?? 220, 'response token limit', 50, 600),
            ],
            'approval' => ['expires_minutes' => Values::number($options['expires_minutes'] ?? 120, 'review duration', 1, 1440)],
            'stop' => ['result' => Values::identifier($options['result'] ?? 'completed', 'result')],
            default => [],
        };
    }

    private static function exactMap(mixed $provided, array $required, string $label): array
    {
        if (!is_array($provided) || array_diff_key($provided, $required) || array_diff_key($required, $provided)) {
            throw Problem::invalid("Incorrect ports on $label.");
        }
        ksort($provided, SORT_STRING);
        return $provided;
    }

    private static function order(array $nodes): array
    {
        $queued = [];
        $done = [];
        $order = [];
        $visit = function (string $id) use (&$visit, &$queued, &$done, &$order, $nodes): void {
            if (isset($queued[$id])) {
                throw Problem::invalid("Cycle through $id.");
            }
            if (isset($done[$id])) {
                return;
            }
            $queued[$id] = true;
            foreach ($nodes[$id]['edges'] as $next) {
                $visit($next);
            }
            unset($queued[$id]);
            $done[$id] = true;
            $order[] = $id;
        };
        $visit('start');
        if (count($done) !== count($nodes)) {
            throw Problem::invalid('Unreachable nodes: ' . implode(', ', array_keys(array_diff_key($nodes, $done))));
        }
        return array_reverse($order);
    }

    private static function outputsOn(string $kind, string $edge): bool
    {
        return match ($kind) {
            'order' => $edge === 'found',
            'draft' => $edge === 'ready',
            'approval' => $edge === 'approved',
            'deliver' => $edge === 'sent',
            default => true,
        };
    }

    private static function validateReference(array $nodes, array $visible, string $id, string $name, mixed $reference): void
    {
        $expected = self::CONTRACTS[$nodes[$id]['kind']]['in'][$name];
        if (!is_string($reference) || preg_match('/^([a-z][a-z0-9_-]{0,63})\.([a-z][a-z0-9_]{0,31})$/D', $reference, $parts) !== 1 ||
            !in_array($parts[1], $visible, true) ||
            (self::CONTRACTS[$nodes[$parts[1]]['kind']]['out'][$parts[2]] ?? null) !== $expected) {
            throw Problem::invalid("Input $id.$name must reference a compatible predecessor on every branch.");
        }
        if ($nodes[$id]['kind'] === 'deliver' && $nodes[$id]['binding'] !== $nodes[$parts[1]]['binding']) {
            throw Problem::invalid('The delivery and approval must bind the same connection.');
        }
        if ($nodes[$id]['kind'] === 'draft' && in_array($name, ['order_no', 'state', 'version'], true) &&
            ($nodes[$parts[1]]['kind'] !== 'order' || $parts[2] !== $name)) {
            throw Problem::invalid("Draft input $name needs a completed order lookup.");
        }
    }
}
