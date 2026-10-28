<?php

namespace App\Service;

use App\Model\Setting;

/**
 * AdLoadService
 *
 * Single source of truth for the site's ad-intensity tier and its
 * revenue-share cut. Tiers:
 *   full : popunder + overlay + all banners + native  -> 100% revshare
 *   75   : popunder + all banners + native (no overlay) -> 75%
 *   50   : popunder + top/bottom + native              -> 50%
 *   25   : popunder + native only                      -> 25%
 *   zero : 1 popunder only, banners off               -> 0% (cost-cover)
 */
class AdLoadService
{
    public const TIERS = ['full', '75', '50', '25', 'zero'];

    private const TIER_RANK = [
        'full' => 4,
        '75' => 3,
        '50' => 2,
        '25' => 1,
        'zero' => 0,
    ];

    public static function normalizeTier(mixed $value, string $fallback = 'full'): string
    {
        $tier = strtolower(trim((string)$value));
        if (in_array($tier, self::TIERS, true)) {
            return $tier;
        }
        $fallback = strtolower(trim($fallback));
        return in_array($fallback, self::TIERS, true) ? $fallback : 'full';
    }

    /** Most restrictive of two tiers (fewest ads shown). */
    public static function restrictTier(string $a, string $b): string
    {
        $a = self::normalizeTier($a);
        $b = self::normalizeTier($b);
        return (self::TIER_RANK[$a] ?? 4) <= (self::TIER_RANK[$b] ?? 4) ? $a : $b;
    }

    public static function tierLabel(string $tier): string
    {
        return match (self::normalizeTier($tier)) {
            'full' => 'Full ads · 100%',
            '75' => '75% ads · 75%',
            '50' => '50% ads · 50%',
            '25' => '25% ads · 25%',
            default => '0% · pop only',
        };
    }

    /** Uploader's chosen tier from a user row; defaults to full. */
    public static function userTier(?array $user): string
    {
        return self::normalizeTier($user['ad_tier'] ?? 'full');
    }

    /**
     * Uploader tier for a file row (request-cached by user id).
     * Falls back to 'full' when the uploader can't be resolved.
     */
    public static function uploaderTierForFile(array $file): string
    {
        static $cache = [];
        $userId = (int)($file['user_id'] ?? 0);
        if ($userId <= 0) {
            return 'full';
        }
        if (!array_key_exists($userId, $cache)) {
            $tier = 'full';
            try {
                $user = \App\Model\User::find($userId);
                if (is_array($user)) {
                    $tier = self::userTier($user);
                }
            } catch (\Throwable $e) {
                $tier = 'full';
            }
            $cache[$userId] = $tier;
        }
        return $cache[$userId];
    }

    public const SHARE_BY_TIER = [
        'full' => 100,
        '75' => 75,
        '50' => 50,
        '25' => 25,
        'zero' => 0,
    ];

    private const SLOTS_BY_TIER = [
        'full' => ['top', 'bottom', 'left', 'right', 'overlay', 'popunder', 'native'],
        '75' => ['top', 'bottom', 'left', 'right', 'popunder', 'native'],
        '50' => ['top', 'bottom', 'popunder', 'native'],
        '25' => ['popunder', 'native'],
        'zero' => ['popunder'],
    ];

    public static function currentTier(): string
    {
        $tier = strtolower(trim((string)Setting::get('ad_load_tier', 'full')));
        return in_array($tier, self::TIERS, true) ? $tier : 'full';
    }

    public static function revenueSharePercent(): int
    {
        return self::SHARE_BY_TIER[self::currentTier()] ?? 100;
    }

    /** 0.0 - 1.0 multiplier applied to PPD payouts. */
    public static function revenueMultiplier(): float
    {
        return self::revenueSharePercent() / 100;
    }

    public static function slotEnabled(string $slot): bool
    {
        $slot = strtolower(trim($slot));
        return in_array($slot, self::SLOTS_BY_TIER[self::currentTier()] ?? [], true);
    }

    /**
     * Visitor country for ad geo-targeting. Uses the Cloudflare country
     * header when the site sits behind Cloudflare (free, no lookup);
     * otherwise null and the default ad codes serve (fail-open).
     */
    public static function requestCountry(): ?string
    {
        static $cached = false;
        static $code = null;
        if ($cached) {
            return $code;
        }
        $cached = true;

        $raw = trim((string)($_SERVER['HTTP_CF_IPCOUNTRY'] ?? ''));
        if ($raw === '' || $raw === 'XX' || $raw === 'T1') {
            return $code = null;
        }
        $raw = strtoupper($raw);
        return $code = (preg_match('/^[A-Z]{2}$/', $raw) === 1 ? $raw : null);
    }

    /**
     * Geo ad rules: [{id, name, countries:[..], tier:''|tier, slots:{slot:code}}].
     * Multiple rows may target the same country; one is picked at random
     * per pageview so duplicate-geo rows rotate creatives.
     */
    public static function geoRules(): array
    {
        static $cached = null;
        if (is_array($cached)) {
            return $cached;
        }

        $raw = trim((string)Setting::get('ad_geo_rules', ''));
        if ($raw === '') {
            return $cached = [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $cached = [];
        }

        $out = [];
        foreach ($decoded as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $countries = [];
            $rawCountries = $rule['countries'] ?? [];
            if (is_string($rawCountries)) {
                $rawCountries = explode(',', $rawCountries);
            }
            if (is_array($rawCountries)) {
                foreach ($rawCountries as $c) {
                    $c = strtoupper(trim((string)$c));
                    if (preg_match('/^[A-Z]{2}$/', $c) === 1 && !in_array($c, $countries, true)) {
                        $countries[] = $c;
                    }
                }
            }
            if ($countries === []) {
                continue;
            }
            $slots = [];
            if (isset($rule['slots']) && is_array($rule['slots'])) {
                foreach ($rule['slots'] as $slot => $code) {
                    $slot = strtolower(trim((string)$slot));
                    $code = (string)$code;
                    if ($slot !== '' && $code !== '') {
                        $slots[$slot] = $code;
                    }
                }
            }
            $tier = strtolower(trim((string)($rule['tier'] ?? '')));
            if ($tier !== '' && !in_array($tier, self::TIERS, true)) {
                $tier = '';
            }
            $out[] = [
                'id' => (string)($rule['id'] ?? ''),
                'name' => (string)($rule['name'] ?? ''),
                'countries' => $countries,
                'tier' => $tier,
                'slots' => $slots,
            ];
        }

        return $cached = $out;
    }

    /** All rules matching a country; empty country matches nothing. */
    public static function matchingGeoRules(?string $countryCode): array
    {
        if ($countryCode === null || $countryCode === '') {
            return [];
        }
        $countryCode = strtoupper(trim($countryCode));

        $matches = [];
        foreach (self::geoRules() as $rule) {
            if (in_array($countryCode, $rule['countries'], true)) {
                $matches[] = $rule;
            }
        }
        return $matches;
    }

    /** One matching rule (random pick = creative rotation) or null. */
    public static function activeGeoRule(?string $countryCode = null, bool $randomize = true): ?array
    {
        if ($countryCode === null) {
            $countryCode = self::requestCountry();
        }
        $matches = self::matchingGeoRules($countryCode);
        if ($matches === []) {
            return null;
        }

        return $randomize
            ? $matches[random_int(0, count($matches) - 1)]
            : $matches[0];
    }

    /** Effective tier for a country: geo-rule override else global. Null = current request. */
    public static function effectiveTier(?string $countryCode = null): string
    {
        $rule = self::activeGeoRule($countryCode);
        if (is_array($rule) && $rule['tier'] !== '') {
            return $rule['tier'];
        }
        return self::currentTier();
    }

    public static function revenueMultiplierForCountry(?string $countryCode): float
    {
        if ($countryCode === null || $countryCode === '') {
            return self::revenueMultiplier();
        }
        // Payouts use the first matching rule (stable); pageviews rotate.
        $rule = self::activeGeoRule($countryCode, false);
        $tier = (is_array($rule) && $rule['tier'] !== '') ? $rule['tier'] : self::currentTier();
        return (self::SHARE_BY_TIER[$tier] ?? 100) / 100;
    }

    /**
     * Raw ad code for a slot, or '' when the tier disables it.
     * $settingKey is the settings key (e.g. ad_download_top).
     */
    public static function slotCode(string $slot, string $settingKey): string
    {
        if (!self::slotEnabled($slot)) {
            return '';
        }

        return (string)Setting::get($settingKey, '');
    }

    /**
     * Request-aware slot code: geo-rule override wins over the global code,
     * gated by the effective tier for the visitor's country AND the
     * uploader's chosen tier (most restrictive wins, so an uploader picking
     * 25% gets clean links even when the site runs Full).
     */
    public static function renderSlot(bool $showAds, string $slot, string $settingKey, string $uploaderTier = 'full'): string
    {
        if (!$showAds) {
            return '';
        }

        $slot = strtolower(trim($slot));
        $rule = self::activeGeoRule();
        $siteTier = (is_array($rule) && $rule['tier'] !== '') ? $rule['tier'] : self::currentTier();
        $tier = self::restrictTier(self::normalizeTier($uploaderTier), $siteTier);

        if (!in_array($slot, self::SLOTS_BY_TIER[$tier] ?? [], true)) {
            return '';
        }

        if (is_array($rule) && isset($rule['slots'][$slot]) && trim((string)$rule['slots'][$slot]) !== '') {
            return (string)$rule['slots'][$slot];
        }

        return (string)Setting::get($settingKey, '');
    }

    /**
     * Combined payout multiplier: uploader's share x site/geo share.
     * Both the admin (global/geo) and the uploader (per-account) cut compose.
     */
    public static function revenueMultiplierForUpload(string $uploaderTier, ?string $countryCode): float
    {
        $userShare = (self::SHARE_BY_TIER[self::normalizeTier($uploaderTier)] ?? 100) / 100;
        return $userShare * self::revenueMultiplierForCountry($countryCode);
    }
}
