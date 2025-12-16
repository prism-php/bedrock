<?php

declare(strict_types=1);

namespace Prism\Bedrock\ValueObjects;

use Prism\Prism\Streaming\StreamState;

class ConverseStreamState extends StreamState
{
    public function withBlockIndex(int $index): self
    {
        $this->currentBlockIndex = $index;

        return $this;
    }

    public function withBlockType(string $type): self
    {
        $this->currentBlockType = $type;

        return $this;
    }
}
