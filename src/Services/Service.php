<?php

namespace LcmtDev\Consent\Services;

class Service
{
    public string $key;
    public string $name;
    public string $description;
    public string $category;
    public string $uri;
    /** @var array<string,string> data passed to injectors (e.g. ['id' => 'GTM-XXX'] or ['url' => ..., 'site_id' => ...]) */
    public array $data;
    public bool $needReload;
    /** @var string|null Client-side JS snippet string: an arrow function body that receives the data object. */
    public ?string $injectJs;
    /** @var callable|null PHP callable returning the <script> HTML for wp_head injection. Receives $data. */
    public $injectPhp;
    public string $source; // 'ui' or 'code'

    public function __construct(array $args)
    {
        $this->key = (string) ($args['key'] ?? '');
        $this->name = (string) ($args['name'] ?? $this->key);
        $this->description = (string) ($args['description'] ?? '');
        $this->category = (string) ($args['category'] ?? 'api');
        $this->uri = (string) ($args['uri'] ?? '');
        $this->data = is_array($args['data'] ?? null) ? $args['data'] : [];
        $this->needReload = (bool) ($args['needReload'] ?? false);
        $this->injectJs = isset($args['inject_js']) ? (string) $args['inject_js'] : null;
        $this->injectPhp = $args['inject_php'] ?? null;
        $this->source = (string) ($args['source'] ?? 'code');
    }

    public function toClientConfig(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'category' => $this->category,
            'needReload' => $this->needReload,
            'data' => $this->data,
        ];
    }
}
