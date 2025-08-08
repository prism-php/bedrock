<?php

namespace Prism\Bedrock\Schemas\Converse\Concerns;

trait ExtractsText
{
    /**
     * @param  array<string, mixed>  $data
     */
    protected function extractText(array $data): string
    {
        $content = data_get($data, 'output.message.content', []);

        foreach ($content as $item) {
            if ($text = data_get($item, 'text')) {
                return $text;
            }
        }

        return '';
    }
}
