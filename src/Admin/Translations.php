<?php

namespace LcmtDev\Consent\Admin;

class Translations
{
    private const CONTEXT = 'lcmt-dev-consent';
    private Settings $settings;

    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
    }

    public function register(): void
    {
        // Register all admin-entered strings with Polylang/WPML (no-op otherwise).
        add_action('init', [$this, 'registerStrings']);
        // Re-register after save so new values surface in the translation UI.
        add_action('update_option_' . Settings::OPTION_KEY, [$this, 'registerStrings']);
    }

    public function registerStrings(): void
    {
        foreach ($this->collectStrings() as $name => $value) {
            $this->registerSingle($name, (string) $value);
        }
    }

    /** Returns flat map of name => value for every translatable string. */
    private function collectStrings(): array
    {
        $out = [];
        $texts = (array) $this->settings->get('texts', []);
        foreach ($texts as $key => $value) {
            $out['texts.' . $key] = (string) $value;
        }
        $categories = (array) $this->settings->get('categories', []);
        foreach ($categories as $catKey => $cat) {
            if (isset($cat['name'])) {
                $out['categories.' . $catKey . '.name'] = (string) $cat['name'];
            }
            if (isset($cat['description'])) {
                $out['categories.' . $catKey . '.description'] = (string) $cat['description'];
            }
        }
        return $out;
    }

    private function registerSingle(string $name, string $value): void
    {
        if ($value === '') {
            return;
        }
        if (function_exists('pll_register_string')) {
            pll_register_string($name, $value, self::CONTEXT, true);
        } elseif (function_exists('icl_register_string')) {
            icl_register_string(self::CONTEXT, $name, $value);
        }
    }

    /** Translate an arbitrary English source string (not stored in settings). */
    public function translatePassthrough(string $value): string
    {
        return $this->translate('', $value);
    }

    /** Get a translatable string by dot path (e.g. 'texts.title'). */
    public function get(string $path): string
    {
        $value = $this->lookup($path);
        return $this->translate($path, $value);
    }

    public function getCategory(string $categoryKey, string $field, string $fallback): string
    {
        $path = 'categories.' . $categoryKey . '.' . $field;
        $value = $this->lookup($path);
        if ($value === '' && $fallback !== '') {
            $value = $fallback;
        }
        return $this->translate($path, $value);
    }

    private function lookup(string $path): string
    {
        $parts = explode('.', $path);
        $ref = $this->settings->all();
        foreach ($parts as $p) {
            if (!is_array($ref) || !array_key_exists($p, $ref)) {
                return '';
            }
            $ref = $ref[$p];
        }
        return is_scalar($ref) ? (string) $ref : '';
    }

    private function translate(string $name, string $value): string
    {
        if ($value === '') {
            return '';
        }
        // 1. Multilingual plugins (Polylang / WPML) — each language from their string-translation UI.
        if (function_exists('pll__')) {
            $translated = pll__($value);
            if ($translated !== $value) {
                return apply_filters('lcmt_dev_consent_string', $translated, $name);
            }
        } elseif (function_exists('icl_t')) {
            $translated = icl_t(self::CONTEXT, $name, $value);
            if ($translated !== $value) {
                return apply_filters('lcmt_dev_consent_string', $translated, $name);
            }
        }
        // 2. Fallback to WordPress native translation (.mo files in languages/).
        //    If the value matches a default English source string, this returns the localized version.
        //    If the admin entered a custom value, translate() returns it unchanged.
        $value = translate($value, 'lcmt-dev-consent');
        return apply_filters('lcmt_dev_consent_string', $value, $name);
    }
}
