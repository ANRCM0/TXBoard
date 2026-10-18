<?php

namespace App\Domains\Installation;

use App\Core\Host\Contracts\TransportSchema;
use RuntimeException;

final class NativePreparationInput
{
    public static function validate(#[\SensitiveParameter] array $input, ?string $part = null): void
    {
        $schema = json_decode(file_get_contents(resource_path('contracts/native-preparation.v1.json')), true, 32, JSON_THROW_ON_ERROR);
        if ($part !== null) $schema = $schema['properties'][$part];
        if (!(new TransportSchema)->accepts($input, $schema)) throw new RuntimeException('NATIVE_PREPARATION_INPUT_INVALID');
        if ($part === null) {
            $groups = array_column($input['network']['groups'], 'id');
            foreach ([$input['network']['groups'], $input['network']['nodes'], $input['catalog']['plans']] as $items) {
                $ids = array_column($items, 'id');
                if (count(array_unique($ids)) !== count($ids)) throw new RuntimeException('NATIVE_PREPARATION_INPUT_INVALID');
            }
            foreach ($input['network']['nodes'] as $node) {
                if (array_diff($node['group_ids'], $groups)) throw new RuntimeException('NATIVE_PREPARATION_INPUT_INVALID');
            }
            foreach ($input['catalog']['plans'] as $plan) {
                if ($plan['prices_minor'] === [] || !in_array($plan['group_id'], $groups, true)) throw new RuntimeException('NATIVE_PREPARATION_INPUT_INVALID');
            }
            if (filter_var($input['identity']['email'], FILTER_VALIDATE_EMAIL) === false) throw new RuntimeException('NATIVE_PREPARATION_INPUT_INVALID');
        }
    }
}
