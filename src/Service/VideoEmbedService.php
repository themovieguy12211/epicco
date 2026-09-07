<?php

namespace App\Service;

/**
 * VideoEmbedService
 *
 * Turns a stored per-file embed URL (Doodstream, Streamtape, Voe, YouTube, or
 * a direct video file URL) into a safe, render-ready player descriptor for the
 * public download page. All inputs are treated as untrusted data: URLs are
 * validated and HTML is escaped by the view layer.
 */
class VideoEmbedService
{
    /**
     * Build a player descriptor or null when there is nothing playable.
     *
     * @return array{kind:string, src:string, provider:string, title:string}|null
     */
    public static function build(?string $embedUrl): ?array
    {
        $embedUrl = self::extractEmbedUrl((string)$embedUrl);
        if ($embedUrl === null) {
            return null;
        }

        $safe = self::sanitizeUrl($embedUrl);
        if ($safe === null) {
            return null;
        }

        if (self::isDirectVideoUrl($safe)) {
            return [
                'kind' => 'video',
                'src' => $safe,
                'provider' => 'Direct video',
                'title' => 'Embedded video',
            ];
        }

        $youtubeId = self::youtubeId($safe);
        if ($youtubeId !== null) {
            return [
                'kind' => 'iframe',
                'src' => 'https://www.youtube-nocookie.com/embed/' . rawurlencode($youtubeId),
                'provider' => 'YouTube',
                'title' => 'YouTube video',
            ];
        }

        $doodSrc = self::doodstreamSrc($safe);
        if ($doodSrc !== null) {
            return [
                'kind' => 'iframe',
                'src' => $doodSrc,
                'provider' => 'Doodstream',
                'title' => 'Doodstream video',
            ];
        }

        $streamtapeSrc = self::streamtapeSrc($safe);
        if ($streamtapeSrc !== null) {
            return [
                'kind' => 'iframe',
                'src' => $streamtapeSrc,
                'provider' => 'Streamtape',
                'title' => 'Streamtape video',
            ];
        }

        $voeSrc = self::voeSrc($safe);
        if ($voeSrc !== null) {
            return [
                'kind' => 'iframe',
                'src' => $voeSrc,
                'provider' => 'Voe',
                'title' => 'Voe video',
            ];
        }

        return [
            'kind' => 'iframe',
            'src' => $safe,
            'provider' => 'External',
            'title' => 'Embedded video',
        ];
    }

    /**
     * Accepts either a plain URL or a pasted <iframe ...> snippet and returns
     * the playable URL, or null.
     */
    public static function extractEmbedUrl(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $value)) {
            return $value;
        }

        if (stripos($value, '<iframe') !== false && preg_match('/\bsrc\s*=\s*(["\'])(.*?)\1/is', $value, $m)) {
            $candidate = trim($m[2]);
            if ($candidate !== '' && preg_match('#^https?://#i', $candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    public static function sanitizeUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || preg_match('#^https?://#i', $url) !== 1) {
            return null;
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $url)) {
            return null;
        }

        $parts = @parse_url($url);
        if ($parts === false || empty($parts['host']) || str_contains((string)$parts['host'], ' ')) {
            return null;
        }

        return $url;
    }

    private static function isDirectVideoUrl(string $url): bool
    {
        $path = (string)(parse_url($url, PHP_URL_PATH) ?? '');
        return preg_match('#\.(mp4|webm|ogv|ogg|mov|m4v|m3u8)(\?.*)?$#i', $path) === 1;
    }

    private static function youtubeId(string $url): ?string
    {
        $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?? ''));
        $path = (string)(parse_url($url, PHP_URL_PATH) ?? '');
        $query = (string)(parse_url($url, PHP_URL_QUERY) ?? '');

        $isYoutube = str_ends_with($host, 'youtube.com')
            || str_ends_with($host, 'youtube-nocookie.com')
            || $host === 'youtu.be'
            || str_ends_with($host, 'youtube.com.br');

        if (!$isYoutube) {
            return null;
        }

        if ($host === 'youtu.be' || str_ends_with($host, '.be')) {
            $id = trim($path, '/');
            return $id !== '' ? $id : null;
        }

        if (preg_match('#^/embed/([A-Za-z0-9_-]{6,})$#', $path, $m)) {
            return $m[1];
        }

        parse_str($query, $params);
        $id = (string)($params['v'] ?? '');
        if (preg_match('#^[A-Za-z0-9_-]{6,}$#', $id)) {
            return $id;
        }

        return null;
    }

    private static function doodstreamSrc(string $url): ?string
    {
        $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?? ''));
        $isDood = $host === 'doodstream.com'
            || $host === 'doodstream.co'
            || $host === 'dooood.com'
            || $host === 'd0000d.com'
            || str_starts_with($host, 'dood.');

        if (!$isDood) {
            return null;
        }

        $path = (string)(parse_url($url, PHP_URL_PATH) ?? '');
        if (preg_match('#^/e/([A-Za-z0-9]+)$#i', $path, $m)) {
            return $url;
        }

        if (preg_match('#^/d/([A-Za-z0-9]+)$#i', $path, $m)) {
            return rtrim(preg_replace('#/d/[A-Za-z0-9]+$#i', '/e/' . $m[1], $url), '/');
        }

        return $url;
    }

    private static function streamtapeSrc(string $url): ?string
    {
        $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?? ''));
        $isStreamtape = $host === 'streamtape.com'
            || $host === 'streamtape.to'
            || $host === 'streamtape.net'
            || $host === 'streamtapee.com';

        if (!$isStreamtape) {
            return null;
        }

        $path = (string)(parse_url($url, PHP_URL_PATH) ?? '');
        if (preg_match('#^/e/([A-Za-z0-9]+)$#i', $path)) {
            return $url;
        }

        if (preg_match('#^/v/([A-Za-z0-9]+)#i', $path, $m)) {
            $scheme = parse_url($url, PHP_URL_SCHEME) ?: 'https';
            return $scheme . '://' . parse_url($url, PHP_URL_HOST) . '/e/' . $m[1];
        }

        return $url;
    }

    private static function voeSrc(string $url): ?string
    {
        $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?? ''));
        if ($host !== 'voe.sx' && !str_ends_with($host, '.voe.sx')) {
            return null;
        }

        $path = (string)(parse_url($url, PHP_URL_PATH) ?? '');
        if (preg_match('#^/e/([A-Za-z0-9]+)$#i', $path)) {
            return $url;
        }

        if (preg_match('#^/([A-Za-z0-9]+)$#', $path, $m)) {
            $scheme = parse_url($url, PHP_URL_SCHEME) ?: 'https';
            return $scheme . '://' . parse_url($url, PHP_URL_HOST) . '/e/' . $m[1];
        }

        return $url;
    }
}
