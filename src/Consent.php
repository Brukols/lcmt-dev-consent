<?php

namespace LcmtDev\Consent;

class Consent
{
    public static function getCookies(string $cookieName = 'cookieConsent'): array
    {
        if (!isset($_COOKIE[$cookieName])) {
            return [];
        }
        $raw = (string) $_COOKIE[$cookieName];
        $parts = array_filter(explode('!', $raw), fn($p) => $p !== '');
        $result = [];
        foreach ($parts as $part) {
            $kv = explode('=', $part, 2);
            if (count($kv) === 2) {
                $result[$kv[0]] = $kv[1];
            }
        }
        return $result;
    }

    public static function isAllowed(string $key, string $cookieName = 'cookieConsent'): bool
    {
        $cookies = self::getCookies($cookieName);
        return ($cookies[$key] ?? null) === 'true';
    }

    public static function isComplete(array $expectedKeys, string $cookieName = 'cookieConsent'): bool
    {
        if (empty($expectedKeys)) {
            return true;
        }
        $cookies = self::getCookies($cookieName);
        foreach ($expectedKeys as $key) {
            $status = $cookies[$key] ?? null;
            if ($status === null || $status === 'wait') {
                return false;
            }
        }
        return true;
    }
}
