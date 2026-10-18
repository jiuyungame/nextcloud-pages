<?php

declare(strict_types=1);

namespace OCA\Pages\Http;

use OCP\AppFramework\Http\Response;

/**
 * Response that returns the body byte-for-byte with a custom Content-Type
 * (Response exposes no setContent(), DataResponse would JSON-encode it).
 */
class RawResponse extends Response
{
    public function __construct(
        private string $body,
        private string $contentType = 'application/octet-stream',
    ) {
        parent::__construct();
        $this->addHeader('Content-Type', $contentType);
        // the preview must always serve the newest build output
        $this->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate');
        $this->addHeader('X-Content-Type-Options', 'nosniff');
    }

    public function render(): string
    {
        return $this->body;
    }

    public function getContentType(): string
    {
        return $this->contentType;
    }
}
