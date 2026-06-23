<?php

namespace LcmtDev\Consent\Services;

use LcmtDev\Consent\Admin\Settings;

class CookieRegistry
{
    private Settings $settings;
    private ServiceRegistry $registry;

    public function __construct(Settings $settings, ServiceRegistry $registry)
    {
        $this->settings = $settings;
        $this->registry = $registry;
    }

    public static function normalizeRow(array $row): array
    {
        $url = trim((string) ($row['url'] ?? ''));
        if ($url !== '' && !preg_match('#^https?://#i', $url)) {
            $url = '';
        }
        return [
            'name' => trim((string) ($row['name'] ?? '')),
            'purpose' => trim((string) ($row['purpose'] ?? '')),
            'retention' => trim((string) ($row['retention'] ?? '')),
            'issuer' => trim((string) ($row['issuer'] ?? '')),
            'third_party' => !empty($row['third_party']),
            'url' => $url,
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function forService(Service $service): array
    {
        $overrides = (array) $this->settings->get('service_cookies', []);
        if (array_key_exists($service->key, $overrides) && is_array($overrides[$service->key])) {
            $rows = $overrides[$service->key];
        } else {
            $defaults = Settings::defaultServiceCookies();
            $rows = $defaults[$service->key] ?? $service->cookies;
        }

        $out = [];
        foreach ((array) $rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $normalized = self::normalizeRow($row);
            if ($normalized['name'] === '') {
                continue;
            }
            $out[] = $normalized;
        }
        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    public function allRows(bool $includeEssential = true): array
    {
        $rows = [];
        if ($includeEssential) {
            $rows[] = $this->essentialRow();
        }
        foreach ($this->registry->all() as $service) {
            foreach ($this->forService($service) as $row) {
                $row['service'] = $service->name;
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /** @return array<string,mixed> */
    public function essentialRow(): array
    {
        $days = (int) $this->settings->get('cookie_lifetime_days', 365);
        $row = self::normalizeRow([
            'name' => $this->settings->effectiveCookieName(),
            'purpose' => __('Stores your cookie consent choices so the banner is not shown again.', 'lcmt-dev-consent'),
            'retention' => sprintf(
                /* translators: %d = number of days */
                __('%d days', 'lcmt-dev-consent'),
                $days
            ),
            'issuer' => __('This website', 'lcmt-dev-consent'),
            'third_party' => false,
            'url' => '',
        ]);
        $row['service'] = __('Essential', 'lcmt-dev-consent');
        return $row;
    }
}
