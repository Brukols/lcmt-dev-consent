<?php

namespace LcmtDev\Consent\Frontend;

use LcmtDev\Consent\Admin\Translations;
use LcmtDev\Consent\Services\CookieRegistry;

class CookieTable
{
    private CookieRegistry $cookies;
    private Translations $t;
    private static bool $styleEmitted = false;

    public function __construct(CookieRegistry $cookies, Translations $t)
    {
        $this->cookies = $cookies;
        $this->t = $t;
    }

    public function register(): void
    {
        add_action('init', function () {
            add_shortcode('lcmt_cookies_table', [$this, 'render']);
        });
    }

    /** @param array<string,mixed>|string $atts */
    public function render($atts = []): string
    {
        $atts = shortcode_atts(['essential' => '1'], $atts);
        $includeEssential = ($atts['essential'] !== '0' && $atts['essential'] !== 0 && $atts['essential'] !== false);

        $rows = $this->cookies->allRows($includeEssential);
        if (empty($rows)) {
            return '';
        }

        $html = $this->styleOnce();
        $html .= '<div class="lcmt-cookies-table-wrap"><table class="lcmt-cookies-table">';
        $html .= '<thead><tr>'
            . '<th>' . esc_html__('Service', 'lcmt-dev-consent') . '</th>'
            . '<th>' . esc_html__('Cookie', 'lcmt-dev-consent') . '</th>'
            . '<th>' . esc_html__('Purpose', 'lcmt-dev-consent') . '</th>'
            . '<th>' . esc_html__('Retention', 'lcmt-dev-consent') . '</th>'
            . '<th>' . esc_html__('Issuer', 'lcmt-dev-consent') . '</th>'
            . '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $html .= '<tr>'
                . '<td>' . esc_html((string) ($row['service'] ?? '')) . '</td>'
                . '<td><code>' . esc_html($row['name']) . '</code></td>'
                . '<td>' . esc_html($this->localize($row['purpose'])) . '</td>'
                . '<td>' . esc_html($this->localize($row['retention'])) . '</td>'
                . '<td>' . $this->issuerCell($row) . '</td>'
                . '</tr>';
        }

        $html .= '</tbody></table></div>';
        return $html;
    }

    private function localize(string $value): string
    {
        return $value === '' ? '' : $this->t->translatePassthrough($value);
    }

    private function issuerCell(array $row): string
    {
        $issuer = $this->localize((string) $row['issuer']);
        $party = $row['third_party']
            ? esc_html__('third-party', 'lcmt-dev-consent')
            : esc_html__('first-party', 'lcmt-dev-consent');
        $label = esc_html($issuer);
        if ($issuer !== '') {
            $label .= ' ';
        }
        $label .= '(' . $party . ')';
        if (!empty($row['url'])) {
            return '<a href="' . esc_url($row['url']) . '" target="_blank" rel="noopener nofollow">' . $label . '</a>';
        }
        return $label;
    }

    private function styleOnce(): string
    {
        if (self::$styleEmitted) {
            return '';
        }
        self::$styleEmitted = true;
        $css = '.lcmt-cookies-table-wrap{overflow-x:auto;margin:1em 0}'
            . '.lcmt-cookies-table{width:100%;border-collapse:collapse;font-size:14px}'
            . '.lcmt-cookies-table th,.lcmt-cookies-table td{border:1px solid #ddd;padding:8px 10px;text-align:left;vertical-align:top}'
            . '.lcmt-cookies-table thead th{background:#f5f5f5;font-weight:600}'
            . '.lcmt-cookies-table tbody tr:nth-child(even){background:#fafafa}'
            . '.lcmt-cookies-table code{background:transparent;padding:0;font-size:13px}'
            . '.lcmt-cookies-table a{font-size:inherit}';
        return '<style id="lcmt-cookies-table-css">' . $css . '</style>';
    }
}
