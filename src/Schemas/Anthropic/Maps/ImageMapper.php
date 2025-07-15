<?php

declare(strict_types=1);

namespace Prism\Bedrock\Schemas\Anthropic\Maps;

use Prism\Prism\Providers\Anthropic\Maps\ImageMapper as AnthropicImageMapper;

class ImageMapper extends AnthropicImageMapper
{
    protected function validateMedia(): bool
    {
        if ($this->media->isUrl()) {
            return false;
        }

        return $this->media->hasRawContent();
    }
}
