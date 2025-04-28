<?php

namespace Prism\Bedrock\Schemas\Cohere;

use Illuminate\Http\Client\Response;
use Prism\Bedrock\Contracts\BedrockEmbeddingsHandler;
use Prism\Prism\Embeddings\Request;
use Prism\Prism\Embeddings\Response as EmbeddingsResponse;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\ValueObjects\Embedding;
use Prism\Prism\ValueObjects\EmbeddingsUsage;
use Prism\Prism\ValueObjects\Meta;
use Throwable;

class CohereEmbeddingsHandler extends BedrockEmbeddingsHandler
{
    protected Request $request;

    protected Response $httpResponse;

    #[\Override]
    public function handle(Request $request): EmbeddingsResponse
    {
        $this->request = $request;

        try {
            $this->sendRequest();
        } catch (Throwable $e) {
            throw PrismException::providerRequestError($this->request->model(), $e);
        }

        return $this->buildResponse();
    }

    /**
     * @return array<string,mixed>
     */
    public static function buildPayload(Request $request): array
    {
        return array_filter([
            'texts' => $request->inputs(),
            'input_type' => 'search_document', // TODO: Need to PR providerOptions onto embeddings request to allow override.
            'truncate' => null, // TODO: Need to PR providerOptions onto embeddings request to allow override. Default for now.
            'embedding_types' => null, // TODO: Need to PR providerOptions onto embeddings request to allow override. Default for now.
        ]);
    }

    protected function sendRequest(): void
    {
        $this->httpResponse = $this->client->post(
            'invoke',
            static::buildPayload($this->request)
        );
    }

    protected function buildResponse(): EmbeddingsResponse
    {
        $body = $this->httpResponse->json();

        return new EmbeddingsResponse(
            embeddings: array_map(fn (array $item): Embedding => Embedding::fromArray($item), data_get($body, 'embeddings', [])),
            usage: new EmbeddingsUsage(
                tokens: (int) $this->httpResponse->header('X-Amzn-Bedrock-Input-Token-Count')
            ),
            meta: new Meta(
                id: data_get($body, 'id', ''),
                model: ''
            )
        );
    }
}
