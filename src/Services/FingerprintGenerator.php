<?php

namespace CoreVisys\License\Services;

use Illuminate\Support\Facades\Request;

/**
 * Produces a deterministic, non-reversible fingerprint for this
 * installation. The raw inputs (domain, IP, etc.) are never sent to the
 * API or written to logs — only the resulting hash ever leaves this class.
 */
class FingerprintGenerator
{
    public function __construct(protected array $config)
    {
    }

    public function generate(): string
    {
        $parts = $this->collectParts();
        $payload = implode('|', $parts);

        $algorithm = $this->config['algorithm'] ?? 'sha256';

        if ($algorithm === 'hmac-sha256') {
            $secret = $this->config['hmac_secret'] ?? '';

            return hash_hmac('sha256', $payload, (string) $secret);
        }

        return hash('sha256', $payload);
    }

    /**
     * @return string[] Ordered, normalized inputs that feed the hash.
     */
    protected function collectParts(): array
    {
        $parts = [];

        if ($this->config['include_domain'] ?? true) {
            $parts[] = 'domain:'.$this->normalizedDomain();
        }

        if ($this->config['include_ip'] ?? true) {
            $parts[] = 'ip:'.$this->serverIp();
        }

        $parts[] = 'product:'.(config('corevisys-license.product_code') ?? '');
        $parts[] = 'env:'.app()->environment();

        if ($this->config['include_app_key'] ?? false) {
            $parts[] = 'app_key:'.substr((string) config('app.key'), 0, 16);
        }

        if ($this->config['include_machine_data'] ?? false) {
            $parts[] = 'machine:'.php_uname('n');
        }

        return $parts;
    }

    public function normalizedDomain(): string
    {
        $url = config('app.url', '');

        if (app()->runningInConsole() === false) {
            try {
                $url = Request::getHost() ?: $url;
            } catch (\Throwable) {
                // fall back to config('app.url')
            }
        }

        // Extract host only (strip scheme, port, path, query, fragment)
        $raw = trim((string) $url);
        if ($raw === '') {
            return '';
        }

        if (str_contains($raw, '://')) {
            $parsedHost = parse_url($raw, PHP_URL_HOST);
        } elseif (str_starts_with($raw, '//')) {
            $parsedHost = parse_url('http:' . $raw, PHP_URL_HOST);
        } else {
            $parsedHost = parse_url('http://' . ltrim($raw, '/'), PHP_URL_HOST);
        }

        $domain = (string) ($parsedHost ?: $raw);

        // Strip residual port or path if parse_url fell back
        if (str_contains($domain, ':')) {
            $domain = explode(':', $domain)[0];
        }
        if (str_contains($domain, '/')) {
            $domain = explode('/', $domain)[0];
        }

        $domain = strtolower(trim($domain));

        // Strip ONE leading www.
        if (($this->config['strip_www'] ?? true) && str_starts_with($domain, 'www.')) {
            $domain = substr($domain, 4);
        }

        // Localhost mapping
        if (in_array($domain, ['localhost', '127.0.0.1', '::1', '[::1]'], true)) {
            $domain = '127.0.0.1';
        }

        return $domain;
    }

    protected function serverIp(): string
    {
        return (string) (
            $_SERVER['SERVER_ADDR']
            ?? gethostbyname(gethostname() ?: 'localhost')
            ?? '127.0.0.1'
        );
    }
}
