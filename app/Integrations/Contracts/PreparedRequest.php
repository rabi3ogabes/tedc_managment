<?php

namespace App\Integrations\Contracts;

/** A request ready to send: what a contract operation turns into once its templates are filled and its authentication applied. */
final class PreparedRequest
{
    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $query
     * @param  list<string>  $maskedHeaders  header names whose values are credentials
     * @param  list<string>  $maskedQuery  query parameter names whose values are credentials
     */
    public function __construct(
        public readonly string $method,
        public readonly string $base,
        public readonly array $query,
        public readonly array $headers,
        public readonly ?string $body,
        public readonly string $contentType,
        private readonly array $maskedHeaders = [],
        private readonly array $maskedQuery = [],
        public readonly string $url = '',
    ) {}

    /** What would be sent, with every credential replaced by "•••" (for the screens, the logs and the sandbox). @return array{method: string, url: string, headers: array<string, string>, body: ?string} */
    public function masked(): array
    {
        $headers = [];
        foreach ($this->headers as $name => $value) {
            $headers[$name] = in_array(strtolower($name), array_map('strtolower', $this->maskedHeaders), true) ? '•••' : $value;
        }
        $query = [];
        foreach ($this->query as $name => $value) {
            $query[$name] = in_array($name, $this->maskedQuery, true) ? '•••' : $value;
        }

        return ['method' => $this->method, 'url' => $this->base.($query ? '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986) : ''), 'headers' => $headers, 'body' => $this->body];
    }
}
