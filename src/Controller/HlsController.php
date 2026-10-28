<?php

namespace App\Controller;

use App\Core\Auth;
use App\Core\Database;
use App\Core\StorageManager;
use App\Model\Setting;
use App\Service\SeoService;
use App\Service\VideoTranscodeService;

class HlsController
{
    public function show(string $token, string $path): void
    {
        $token = trim($token);
        $path = trim($path);

        if (!preg_match('/^[a-f0-9]{32}$/i', $token)) {
            http_response_code(404);
            echo 'Stream not found';
            return;
        }

        // Strict allowlist: master, rendition playlists, segments only. No traversal.
        if (!preg_match('#^(master\.m3u8|[0-9]{3,4}/index\.m3u8|[0-9]{3,4}/seg_[0-9]+\.ts)$#', $path)) {
            http_response_code(404);
            echo 'Stream not found';
            return;
        }

        $job = VideoTranscodeService::findReadyJobByToken($token);
        if (!$job) {
            http_response_code(404);
            echo 'Stream not found';
            return;
        }

        $storedFileId = (int)($job['stored_file_id'] ?? 0);
        if ($storedFileId <= 0) {
            http_response_code(404);
            echo 'Stream not found';
            return;
        }

        $file = $this->findRepresentativeFile($storedFileId);
        if (!$file) {
            http_response_code(404);
            echo 'Stream not found';
            return;
        }

        // Private files stay owner/admin-only even with the token.
        if (empty($file['is_public']) && !$this->canAccessFile($file)) {
            http_response_code(403);
            echo 'Private stream';
            return;
        }

        $db = Database::getInstance()->getConnection();
        try {
            $storage = StorageManager::getProviderById(
                !empty($file['file_server_id']) ? (int)$file['file_server_id'] : null,
                $db
            );
        } catch (\Throwable $e) {
            http_response_code(404);
            echo 'Stream not found';
            return;
        }

        $objectKey = 'hls/' . strtolower($token) . '/' . $path;

        if (str_ends_with($path, '.m3u8')) {
            $this->servePlaylist($storage, $file, $token, $objectKey, $path);
            return;
        }

        $this->serveSegment($storage, $file, $objectKey);
    }

    private function servePlaylist($storage, array $file, string $token, string $objectKey, string $path): void
    {
        $content = $this->fetchObjectContents($storage, $objectKey);
        if ($content === null) {
            http_response_code(404);
            echo 'Stream not found';
            return;
        }

        $baseUrl = rtrim(SeoService::trustedBaseUrl(), '/');
        $tokenLower = strtolower($token);
        $isMaster = $path === 'master.m3u8';
        $dir = $isMaster ? '' : substr($path, 0, strrpos($path, '/') + 1);

        $out = [];
        foreach (preg_split("/\r\n|\n|\r/", $content) as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                $out[] = $line;
                continue;
            }

            // Rendition entries in master.m3u8 stay on this app so the
            // per-segment token gate keeps working behind a CDN.
            if ($isMaster) {
                $relative = ltrim($trimmed, '/');
                $out[] = $baseUrl . '/stream/hls/' . $tokenLower . '/' . $relative;
                continue;
            }

            // Segments go direct to storage (presigned B2/R2/S3) so PHP
            // never proxies video bytes. Falls back to app URL for local.
            $segmentKey = 'hls/' . $tokenLower . '/' . $dir . $trimmed;
            $direct = $this->directSegmentUrl($storage, $file, $segmentKey);
            $out[] = $direct ?? ($baseUrl . '/stream/hls/' . $tokenLower . '/' . $dir . $trimmed);
        }

        header('Content-Type: application/vnd.apple.mpegurl');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('X-Content-Type-Options: nosniff');
        echo implode("\n", $out);
    }

    private function serveSegment($storage, array $file, string $objectKey): void
    {
        $capabilities = method_exists($storage, 'getCapabilities') ? $storage->getCapabilities() : [];

        if (!empty($capabilities['presigned_download'])) {
            $cdnUrl = $this->buildCdnHlsUrl($file, $objectKey);
            if ($cdnUrl !== null) {
                header('Location: ' . $cdnUrl, true, 302);
                header('Cache-Control: public, max-age=86400');
                return;
            }

            try {
                $signed = $storage->getPresignedUrl($objectKey, 600, [
                    'response_content_type' => 'video/MP2T',
                ]);
            } catch (\Throwable $e) {
                $signed = null;
            }
            if (!$signed) {
                try {
                    $signed = $storage->getPresignedUrl($objectKey, 600);
                } catch (\Throwable $e) {
                    $signed = null;
                }
            }
            if ($signed) {
                header('Location: ' . $signed, true, 302);
                header('Cache-Control: public, max-age=86400');
                return;
            }
        }

        // Local storage fallback: stream bytes via PHP with long cache.
        $head = method_exists($storage, 'head') ? $storage->head($objectKey) : null;
        if ($head === null && method_exists($storage, 'exists') && !$storage->exists($objectKey)) {
            http_response_code(404);
            echo 'Segment not found';
            return;
        }

        header('Content-Type: video/MP2T');
        header('Cache-Control: public, max-age=86400');
        header('X-Content-Type-Options: nosniff');
        header('X-Accel-Buffering: no');
        session_write_close();
        set_time_limit(0);
        $storage->stream($objectKey);
    }

    private function fetchObjectContents($storage, string $objectKey): ?string
    {
        // Local providers expose an absolute path; read directly.
        try {
            $absolute = $storage->getAbsolutePath($objectKey);
        } catch (\Throwable $e) {
            $absolute = '';
        }
        if (is_string($absolute) && $absolute !== '' && is_file($absolute)) {
            $data = @file_get_contents($absolute);
            return is_string($data) && $data !== '' ? $data : null;
        }

        // Object storage: short-lived presigned GET, then rewrite.
        try {
            $url = $storage->getPresignedUrl($objectKey, 120);
        } catch (\Throwable $e) {
            $url = null;
        }
        if (!$url) {
            return null;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT => 'fyuhls-hls/1.0',
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return (is_string($body) && $body !== '' && $code >= 200 && $code < 300) ? $body : null;
    }

    private function directSegmentUrl($storage, array $file, string $segmentKey): ?string
    {
        $capabilities = method_exists($storage, 'getCapabilities') ? $storage->getCapabilities() : [];
        if (empty($capabilities['presigned_download'])) {
            return null;
        }

        $cdnUrl = $this->buildCdnHlsUrl($file, $segmentKey);
        if ($cdnUrl !== null) {
            return $cdnUrl;
        }

        try {
            $signed = $storage->getPresignedUrl($segmentKey, 600, [
                'response_content_type' => 'video/MP2T',
            ]);
        } catch (\Throwable $e) {
            $signed = null;
        }
        if (!$signed) {
            try {
                $signed = $storage->getPresignedUrl($segmentKey, 600);
            } catch (\Throwable $e) {
                $signed = null;
            }
        }

        return $signed ?: null;
    }

    private function buildCdnHlsUrl(array $file, string $objectKey): ?string
    {
        if (empty($file['is_public'])) {
            return null;
        }

        if (Setting::get('cdn_download_redirects_enabled', '0') !== '1') {
            return null;
        }

        $baseUrl = rtrim((string)Setting::get('cdn_download_base_url', ''), '/');
        if ($baseUrl === '') {
            return null;
        }

        $encodedPath = implode('/', array_map('rawurlencode', explode('/', trim($objectKey, '/'))));
        return $baseUrl . '/' . $encodedPath;
    }

    private function findRepresentativeFile(int $storedFileId): ?array
    {
        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("
                SELECT f.*, sf.file_size, sf.mime_type, sf.storage_path, sf.file_server_id
                FROM files f
                JOIN stored_files sf ON sf.id = f.stored_file_id
                WHERE f.stored_file_id = ? AND f.status = 'active'
                ORDER BY f.is_public DESC, f.id ASC
                LIMIT 1
            ");
            $stmt->execute([$storedFileId]);
            $row = $stmt->fetch();
            return $row ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function canAccessFile(array $file): bool
    {
        if (!empty($file['is_public'])) {
            return true;
        }

        if (!Auth::check()) {
            return false;
        }

        $ownerId = isset($file['user_id']) ? (int)$file['user_id'] : 0;
        if ($ownerId > 0 && (int)(Auth::id() ?? 0) === $ownerId) {
            return true;
        }

        return Auth::isAdmin();
    }
}
