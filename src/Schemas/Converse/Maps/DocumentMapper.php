<?php

namespace Prism\Bedrock\Schemas\Converse\Maps;

use Prism\Bedrock\Enums\Mimes;
use Prism\Prism\Contracts\ProviderMediaMapper;
use Prism\Prism\Enums\Provider;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Media\Media;

class DocumentMapper extends ProviderMediaMapper
{
    /**
     * @param  Document  $media
     * @param  array<string, mixed>  $cacheControl
     */
    public function __construct(
        public readonly Media $media,
        public ?array $cacheControl = null
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toPayload(): array
    {
        return [
            'document' => [
                'format' => $this->media->mimeType() ? Mimes::tryFrom($this->media->mimeType())?->toExtension() : null,
                'name' => $this->media->documentTitle(),
                'source' => ['bytes' => $this->media->base64()],
            ],
        ];
    }

    protected function provider(): string|Provider
    {
        return 'bedrock';
    }

    protected function validateMedia(): bool
    {
        if ($this->media->isUrl()) {
            return false;
        }

        return $this->media->hasRawContent();
    }
}
