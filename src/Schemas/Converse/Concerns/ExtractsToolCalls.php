<?php

namespace Prism\Bedrock\Schemas\Converse\Concerns;

use Prism\Prism\ValueObjects\ToolCall;

trait ExtractsToolCalls
{
    /**
     * @param  array<string, mixed>  $data
     * @return ToolCall[]
     */
    protected function extractToolCalls(array $data): array
    {
        $toolCalls = array_map(function ($content) {

            if (! $use = data_get($content, 'toolUse')) {
                return;
            }

            $input = data_get($use, 'input');

            return new ToolCall(
                id: data_get($use, 'toolUseId'),
                name: data_get($use, 'name'),
                arguments: is_string($input) ? (json_decode($input, true) ?? []) : ($input ?? [])
            );

        }, data_get($data, 'output.message.content', []));

        return array_values(array_filter($toolCalls));
    }
}
