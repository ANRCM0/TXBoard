<?php

namespace App\Protocols;

use App\Models\Server;
use App\Protocols\Contracts\ProtocolDefinition;

abstract class AbstractProtocolDefinition implements ProtocolDefinition
{
    public function normalize(array $settings): array
    {
        $normalized = $this->mergeDefaults($this->defaults(), $settings);

        foreach ($this->rules() as $path => $rule) {
            if (str_contains($path, '*')) {
                continue;
            }

            [$exists, $value] = $this->readPath($normalized, $path);
            if (!$exists) {
                continue;
            }

            $normalized = $this->writePath(
                $normalized,
                $path,
                $this->castByRule($value, (string) $rule),
            );
        }

        return $normalized;
    }

    private function mergeDefaults(array $defaults, array $settings): array
    {
        $result = $defaults;

        foreach ($settings as $key => $value) {
            $default = $result[$key] ?? null;

            if (
                is_array($default)
                && is_array($value)
                && !array_is_list($default)
                && !array_is_list($value)
            ) {
                $result[$key] = $this->mergeDefaults($default, $value);
                continue;
            }

            $result[$key] = $value;
        }

        return $result;
    }

    private function castByRule(mixed $value, string $rule): mixed
    {
        if ($value === null) {
            return null;
        }

        $rules = explode('|', $rule);

        if (in_array('boolean', $rules, true)) {
            if (is_string($value)) {
                $normalized = strtolower($value);
                return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
            }
            return (bool) $value;
        }

        if (in_array('integer', $rules, true)) {
            return (int) $value;
        }

        if (in_array('numeric', $rules, true)) {
            return is_numeric($value) ? $value + 0 : $value;
        }

        if (in_array('string', $rules, true)) {
            return (string) $value;
        }

        if (in_array('array', $rules, true)) {
            return is_array($value) ? $value : (array) $value;
        }

        return $value;
    }

    private function readPath(array $source, string $path): array
    {
        $current = $source;

        foreach (explode('.', $path) as $key) {
            if (!is_array($current) || !array_key_exists($key, $current)) {
                return [false, null];
            }
            $current = $current[$key];
        }

        return [true, $current];
    }

    private function writePath(array $source, string $path, mixed $value): array
    {
        $keys = explode('.', $path);
        $cursor =& $source;

        foreach ($keys as $index => $key) {
            if ($index === count($keys) - 1) {
                $cursor[$key] = $value;
                break;
            }

            if (!isset($cursor[$key]) || !is_array($cursor[$key])) {
                $cursor[$key] = [];
            }

            $cursor =& $cursor[$key];
        }

        return $source;
    }

    protected function baseConfig(Server $node): array
    {
        $settings = $this->normalize($node->protocol_settings ?? []);

        return [
            'protocol' => $this->type(),
            'listen_ip' => '0.0.0.0',
            'server_port' => (int) $node->server_port,
            'network' => data_get($settings, 'network'),
            'networkSettings' => data_get($settings, 'network_settings') ?: null,
        ];
    }

    public function metadata(): array
    {
        return [
            'type' => $this->type(),
            'label' => $this->label(),
            'schema_version' => 1,
            'defaults' => $this->defaults(),
            'form_schema' => $this->formSchema(),
        ];
    }
}
