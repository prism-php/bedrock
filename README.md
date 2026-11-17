# Prism Bedrock

> This is a fork of prism bedrock maintained by iseekplant

Unlock the power of AWS Bedrock services in your Laravel applications with Prism Bedrock. This package provides a standalone Bedrock provider for the Prism PHP framework.

## Tagging a release

Commit with a message prefix to trigger a Statis build,

e.g. `minor: my new feature`

You can then `composer update` from a consuming app. While the releases are pre-1.0 you need to match the minior version constraint.

## Installation

```bash
composer config repositories.iseekplant composer https://packages.iseekplant.com.au  

composer require iseekplant/prism-bedrock
```

## Configuration

Add the following to your Prism configuration (`config/prism.php`):

```php
'bedrock' => [ // Key should match Bedrock::KEY
    'region' => env('AWS_REGION', 'us-east-1'),

    // Set to true to ignore other auth configuration and use the AWS SDK default credential chain
    // read more at https://docs.aws.amazon.com/sdk-for-php/v3/developer-guide/guide_credentials_default_chain.html
    'use_default_credential_provider' => env('AWS_USE_DEFAULT_CREDENTIAL_PROVIDER', false), 

    'api_key' => env('AWS_ACCESS_KEY_ID'), //  Ignored with `use_default_credential_provider` === true
    'api_secret' => env('AWS_SECRET_ACCESS_KEY'), //  Ignored with `use_default_credential_provider` === true
    'session_token' => env('AWS_SESSION_TOKEN'), // Only required for temporary credentials. Ignored with `use_default_credential_provider` === true
],
```

## Usage

### Text Generation

```php
use Prism\Prism\Facades\Prism;
use Prism\Bedrock\Bedrock;

$response = Prism::text()
    ->using(Bedrock::KEY, 'anthropic.claude-3-sonnet-20240229-v1:0')
    ->withPrompt('Explain quantum computing in simple terms')
    ->asText();

echo $response->text;
```

### Text Stream

```php
use Prism\Prism\Facades\Prism;
use Prism\Bedrock\Bedrock;

$response = Prism::text()
    ->using(Bedrock::KEY, 'anthropic.claude-3-sonnet-20240229-v1:0')
    ->withPrompt('Explain quantum computing in simple terms')
    ->asStream();

foreach ($response as $event) {
    if ($event instanceof TextDeltaEvent) {
        echo $event->delta;
    }
}

```

### Structured Output (JSON)

```php
use Prism\Prism\Facades\Prism;
use Prism\Bedrock\Bedrock;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Schema\ArraySchema;

$schema = new ObjectSchema(
    name: 'languages',
    description: 'Top programming languages',
    properties: [
        new ArraySchema(
            'languages',
            'List of programming languages',
            items: new ObjectSchema(
                name: 'language',
                description: 'Programming language details',
                properties: [
                    new StringSchema('name', 'The language name'),
                    new StringSchema('popularity', 'Popularity description'),
                ]
            )
        )
    ]
);

$response = Prism::structured()
    ->using(Bedrock::KEY, 'anthropic.claude-3-sonnet-20240229-v1:0')
    ->withSchema($schema)
    // Add validated_json to use validated json output
    ->withProvideroptions([
        'validated_json' => true
    ])
    ->withPrompt('List the top 3 programming languages')
    ->asStructured();

// Access your structured data
$data = $response->structured;
```

### Embeddings (with Cohere models)

```php
use Prism\Prism\Facades\Prism;
use Prism\Bedrock\Bedrock;

$response = Prism::embeddings()
    ->using(Bedrock::KEY, 'cohere.embed-english-v3')
    ->fromArray(['The sky is blue', 'Water is wet'])
    ->asEmbeddings();

// Access the embeddings
$embeddings = $response->embeddings;
```

### Model names

> [!IMPORTANT]
> When using inference profiles via an ARN, you should urlencode the model name.

```php
use Prism\Prism\Facades\Prism;
use Prism\Bedrock\Bedrock;

$response = Prism::text()
    ->using(Bedrock::KEY, urlencode('arn:aws:bedrock:us-east-1:999999999999:inference-profile/us.anthropic.claude-3-7-sonnet-20250219-v1:0'))
    ->withPrompt('Explain quantum computing in simple terms')
    ->asText();
```

## Supported API Schemas

AWS Bedrock supports several API schemas - some LLM vendor specific, some generic.

Prism Bedrock supports three of those API schemas:

- **Converse**: AWS's native interface for chat (default)*
- **Anthropic**: For Claude models**
- **Cohere**: For Cohere embeddings models

Each schema supports different capabilities:

| Schema | Text | Structured | Embeddings | Stream |
|--------|:----:|:----------:|:----------:|:------:|
| Converse | ✅ | ✅ | ❌ | ✅ |
| Anthropic | ✅ | ✅ | ❌ | ❌|
| Cohere | ❌ | ❌ | ✅ | ❌ |

\* A unified interface for multiple providers. See [AWS documentation](https://docs.aws.amazon.com/bedrock/latest/userguide/conversation-inference-supported-models-features.html) for a list of supported models.

\*\* The Converse schema does not support Anthropic's native features (e.g. PDF vision analysis). This schema uses Anthropic's native schema and therefore allows use of Anthropic native features. Please note however that Bedrock's Anthropic schema does not yet have feature parity with Anthropic. Notably it does not support documents or citations, and only supports prompt caching for Claude Sonnet 3.7 and Claude Haiku 3.5. To use documents with Anthropic's models, use them via the Converse schema (though note that this not the same as using Anthropic's PDF vision directly).

## Auto-resolution of API schemas

Prism Bedrock resolves the most appropriate API schema from the model string. If it is unable to resolve a specific schema (e.g. anthropic for Anthropic), it will default to Converse.

Therefore if you use a model that is not supported by AWS Bedrock Converse, and does not have a specific Prism Bedrock implementation, your request will fail.

If you wish to force Prism Bedrock to use Converse instead of a vendor specific interface, you can do so with `withProviderOptions()`:

```php
use Prism\Prism\Facades\Prism;
use Prism\Bedrock\Bedrock;
use Prism\Bedrock\Enums\BedrockSchema;

$response = Prism::text()
    ->using(Bedrock::KEY, 'anthropic.claude-3-sonnet-20240229-v1:0')
    ->withProviderOptions(['apiSchema' => BedrockSchema::Converse])
    ->withPrompt('Explain quantum computing in simple terms')
    ->asText();

```

## Cache-Optimized Prompts

For [supported models](https://docs.aws.amazon.com/bedrock/latest/userguide/prompt-caching.html), you can enable prompt caching to reduce latency and costs:

```php
use Prism\Prism\Facades\Prism;
use Prism\Bedrock\Bedrock;
use Prism\Prism\ValueObjects\Messages\UserMessage;

$response = Prism::text()
    ->using(Bedrock::KEY, 'anthropic.claude-3-sonnet-20240229-v1:0')
    ->withMessages([
        (new UserMessage('Message with cache breakpoint'))->withProviderOptions(['cacheType' => 'ephemeral']),
        (new UserMessage('Message with another cache breakpoint'))->withProviderOptions(['cacheType' => 'ephemeral']),
        new UserMessage('Compare the last two messages.')
    ])
    ->asText();
```

> [!TIP]
> Anthropic currently supports a cacheType of "ephemeral". Converse currently supports a cacheType of "default". It is possible that Anthropic and/or AWS may add additional types in the future.

### Automatically Cache the Last Message (Converse only)

Rather than managing cache breakpoints on individual messages yourself, you can set the `cacheLastMessage` provider option to automatically move the cache breakpoint to the last message of every request. This is useful for multi-turn conversations, where you want to cache as much of the growing conversation history as possible on each turn.

```php
use Prism\Prism\Facades\Prism;
use Prism\Bedrock\Bedrock;

$response = Prism::text()
    ->using(Bedrock::KEY, 'anthropic.claude-3-sonnet-20240229-v1:0')
    ->withProviderOptions(['cacheLastMessage' => true])
    ->withPrompt('Explain quantum computing in simple terms')
    ->asText();
```

> [!TIP]
> When enabled, any manual `cacheType` breakpoints are removed and replaced with a single breakpoint on the last message.

## Structured Adapted Support

Anthropic does not support a native structured format. 

Prism Bedrock has adapted support by appending a prompt asking the model to a response conforming to the schema you provide.

The performance of that prompt may vary by model. You can override it using `withProviderOptions()`:

```php
use Prism\Prism\Facades\Prism;
use Prism\Bedrock\Bedrock;
use Prism\Prism\ValueObjects\Messages\UserMessage;

Prism::structured()
    ->withSchema($schema)
    ->using('bedrock', 'anthropic.claude-3-5-haiku-20241022-v1:0')
    ->withProviderOptions([
        // Override the default message of "Respond with ONLY JSON (i.e. not in backticks or a code block, with NO CONTENT outside the JSON) that matches the following schema:"
        'jsonModeMessage' => 'My custom message', 
    ])
    ->withPrompt('My prompt')
    ->asStructured();
```

### Tool-call structured output (Converse only)

For Converse API models, you can instead have Prism Bedrock convert your schema into a Bedrock tool and force the model to call it, then capture the structured output from the tool call arguments. This can be more reliable than JSON-mode prompting on models that support tool use but not native structured output:

```php
use Prism\Prism\Facades\Prism;
use Prism\Bedrock\Bedrock;
use Prism\Bedrock\Enums\BedrockSchema;

Prism::structured()
    ->withSchema($schema)
    ->using('bedrock', 'anthropic.claude-3-5-haiku-20241022-v1:0')
    ->withProviderOptions([
        'apiSchema' => BedrockSchema::Converse,
        'use_structured_output_tool' => true,
    ])
    ->withPrompt('My prompt')
    ->asStructured();
```

> [!NOTE]
> `use_structured_output_tool` and `validated_schema` are mutually exclusive — enabling both will throw an exception.

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.

## Authors

This library is created by [TJ Miller](https://tjmiller.me) with contributions from the [Open Source Community](https://github.com/echolabsdev/prism-bedrock/graphs/contributors).

