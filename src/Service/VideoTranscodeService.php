<?php

namespace App\Service;

use App\Core\Database;
use App\Core\Logger;
use App\Core\StorageManager;
use App\Model\Setting;
use App\Model\StoredFile;
use App\Service\Database\SchemaService;
use App\Service\Storage\ConfigurableLocalStorage;
use App\Service\Storage\LocalStorage;

/**
 * VideoTranscodeService
 *
 * Turns uploaded video files into an adaptive HLS ladder (multiple renditions)
 * so videos play like a modern video host instead of relying on the visitor's
 * browser to decode the original container/codec.
 *
 * Work is performed by the cron heartbeat (see Cron/Run.php), never inside the
 * web request. Transcoding requires the ffmpeg binary configured under
 * Config Hub -> General (video.ffmpeg_path).
 */
class VideoTranscodeService
{
    private const DEFAULT_RESOLUTIONS = '1080';
    private const SEGMENT_SECONDS = 6;

    /** @var array<int,int> Rendition height => video bitrate (bits/sec). */
    private const BITRATE_BY_HEIGHT = [
        144 => 300000,
        240 => 400000,
        360 => 800000,
        480 => 1400000,
        720 => 2800000,
        1080 => 5000000,
        1440 => 8000000,
        2160 => 14000000,
    ];

    private static bool $schemaReady = false;

    public static function ensureSchema(): void
    {
        if (self::$schemaReady) {
            return;
        }

        SchemaService::ensureTables(['video_transcode_jobs'], false);
        self::$schemaReady = true;
    }

    public static function isEnabled(): bool
    {
        return Setting::get('video_transcode_enabled', '0') === '1'
            && self::ffmpegPath() !== null;
    }

    public static function ffmpegPath(): ?string
    {
        $enabled = Setting::getOrConfig('video.ffmpeg_enabled', '1');
        if ($enabled !== '1') {
            return null;
        }

        $path = trim((string)Setting::getOrConfig('video.ffmpeg_path', ''));
        if ($path === '') {
            return null;
        }

        $resolved = realpath($path);
        if ($resolved === false || !is_file($resolved)) {
            return null;
        }

        if (!preg_match('/^ffmpeg(?:\.exe)?$/i', basename($resolved))) {
            return null;
        }

        return $resolved;
    }

    public static function ffprobePath(): ?string
    {
        $ffmpeg = self::ffmpegPath();
        if ($ffmpeg === null) {
            return null;
        }

        $dir = dirname($ffmpeg);
        $base = str_ends_with(strtolower(basename($ffmpeg)), '.exe') ? 'ffprobe.exe' : 'ffprobe';
        $candidate = $dir . DIRECTORY_SEPARATOR . $base;

        if (!is_file($candidate)) {
            return null;
        }

        return $candidate;
    }

    /**
     * Lazily enqueue a video for transcoding. Idempotent per stored file.
     * Called from the download-page flow so the upload pipeline stays simple.
     */
    public static function enqueueStoredFile(array $file): void
    {
        if (!self::isEnabled()) {
            return;
        }

        $storedFileId = (int)($file['stored_file_id'] ?? 0);
        if ($storedFileId <= 0) {
            return;
        }

        if (!(new DownloadPageService())->isVideoFile($file)) {
            return;
        }

        // Honor the uploader's "disable transcoding" account preference.
        $ownerId = (int)($file['user_id'] ?? 0);
        if ($ownerId > 0) {
            $owner = \App\Model\User::find($ownerId);
            if ($owner && (int)($owner['transcode_disabled'] ?? 0) === 1) {
                return;
            }
        }

        self::ensureSchema();
        $db = Database::getInstance()->getConnection();

        try {
            $stmt = $db->prepare("SELECT id, status FROM video_transcode_jobs WHERE stored_file_id = ? LIMIT 1");
            $stmt->execute([$storedFileId]);
            $existing = $stmt->fetch();

            if ($existing && in_array($existing['status'], ['pending', 'processing', 'ready'], true)) {
                return;
            }

            $token = bin2hex(random_bytes(16));
            if ($existing) {
                $upd = $db->prepare("UPDATE video_transcode_jobs SET hls_token = ?, status = 'pending', progress = 0, error_message = NULL, manifest_path = NULL, renditions_json = NULL, started_at = NULL, completed_at = NULL WHERE id = ?");
                $upd->execute([$token, (int)$existing['id']]);
                return;
            }

            $ins = $db->prepare("INSERT INTO video_transcode_jobs (stored_file_id, hls_token, status) VALUES (?, ?, 'pending')");
            $ins->execute([$storedFileId, $token]);
        } catch (\Throwable $e) {
            Logger::warning('Could not enqueue video transcode job', [
                'stored_file_id' => $storedFileId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function findReadyJobByToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/i', $token)) {
            return null;
        }

        self::ensureSchema();
        $db = Database::getInstance()->getConnection();

        try {
            $stmt = $db->prepare("SELECT * FROM video_transcode_jobs WHERE hls_token = ? AND status = 'ready' LIMIT 1");
            $stmt->execute([$token]);
            return $stmt->fetch() ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function findJobByStoredFileId(int $storedFileId): ?array
    {
        self::ensureSchema();
        $db = Database::getInstance()->getConnection();

        try {
            $stmt = $db->prepare("SELECT * FROM video_transcode_jobs WHERE stored_file_id = ? LIMIT 1");
            $stmt->execute([$storedFileId]);
            return $stmt->fetch() ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function manifestPathForToken(string $token): string
    {
        return 'hls/' . $token . '/master.m3u8';
    }

    /**
     * Public URL of the master playlist for a ready job, or null.
     */
    public static function manifestUrl(?array $job, ?string $baseUrl = null): ?string
    {
        if (!is_array($job) || empty($job['hls_token']) || ($job['status'] ?? '') !== 'ready') {
            return null;
        }

        $baseUrl = $baseUrl ?? SeoService::trustedBaseUrl();
        return rtrim($baseUrl, '/') . '/stream/hls/' . (string)$job['hls_token'] . '/master.m3u8';
    }

    /**
     * Cron worker entry point. Processes up to $limit pending jobs.
     */
    public static function processBatch(int $limit = 1): array
    {
        $result = ['processed' => 0, 'failed' => 0];

        if (!self::isEnabled()) {
            return $result;
        }

        self::ensureSchema();
        $db = Database::getInstance()->getConnection();
        $limit = max(1, min(10, $limit));

        $jobs = [];
        try {
            $stmt = $db->prepare("SELECT * FROM video_transcode_jobs WHERE status = 'pending' ORDER BY created_at ASC LIMIT " . $limit);
            $stmt->execute();
            $rows = $stmt->fetchAll() ?: [];

            $claim = $db->prepare("UPDATE video_transcode_jobs SET status = 'processing', started_at = NOW() WHERE id = ? AND status = 'pending'");
            foreach ($rows as $row) {
                $claim->execute([(int)$row['id']]);
                if ($claim->rowCount() === 1) {
                    $row['status'] = 'processing';
                    $jobs[] = $row;
                }
            }
        } catch (\Throwable $e) {
            Logger::error('Video transcode job claim failed', ['error' => $e->getMessage()]);
            return $result;
        }

        foreach ($jobs as $job) {
            try {
                self::transcodeJob($job);
                $result['processed']++;
            } catch (\Throwable $e) {
                self::markFailed((int)$job['id'], $e->getMessage());
                Logger::error('Video transcode failed', [
                    'job_id' => (int)$job['id'],
                    'error' => $e->getMessage(),
                ]);
                $result['failed']++;
            }
        }

        return $result;
    }

    private static function transcodeJob(array $job): void
    {
        $storedFile = StoredFile::find((int)$job['stored_file_id']);
        if (!$storedFile) {
            throw new \RuntimeException('Stored file record not found.');
        }

        $db = Database::getInstance()->getConnection();
        $storage = StorageManager::getProviderById(
            $storedFile['file_server_id'] ? (int)$storedFile['file_server_id'] : null,
            $db
        );

        $workDir = TemporaryArtifactService::createTempDirectory('fy_hls_');
        $sourcePath = null;
        $cleanupSource = false;

        try {
            $sourcePath = self::prepareLocalSource($storage, $storedFile, $workDir, $cleanupSource);
            if ($sourcePath === null || !is_file($sourcePath)) {
                throw new \RuntimeException('The source video could not be prepared for transcoding.');
            }

            $ffmpeg = self::ffmpegPath();
            if ($ffmpeg === null) {
                throw new \RuntimeException('ffmpeg is not available on this server.');
            }

            $resolution = self::probeResolution($sourcePath);
            $sourceHeight = $resolution !== null ? (int)$resolution[1] : null;
            $heights = self::resolveRenditionHeights($sourceHeight);

            $renditions = [];
            foreach ($heights as $height) {
                $rendition = self::transcodeRendition($ffmpeg, $sourcePath, $workDir, $height);
                if ($rendition !== null) {
                    $renditions[] = $rendition;
                }
            }

            if ($renditions === []) {
                throw new \RuntimeException('No HLS renditions could be produced.');
            }

            $masterPath = $workDir . DIRECTORY_SEPARATOR . 'master.m3u8';
            self::writeMasterPlaylist($masterPath, $renditions, $resolution);

            $token = (string)$job['hls_token'];
            if (!preg_match('/^[a-f0-9]{32}$/i', $token)) {
                $token = bin2hex(random_bytes(16));
                $db->prepare("UPDATE video_transcode_jobs SET hls_token = ? WHERE id = ?")->execute([$token, (int)$job['id']]);
            }
            $files = self::uploadRenditions($storage, $workDir, $token, $renditions);

            self::markReady((int)$job['id'], $token, $renditions, $files);
        } finally {
            if ($cleanupSource && $sourcePath !== null && is_file($sourcePath)) {
                @unlink($sourcePath);
            }
            TemporaryArtifactService::cleanup($workDir);
        }
    }

    private static function prepareLocalSource($storage, array $storedFile, string $workDir, bool &$cleanupSource): ?string
    {
        if ($storage instanceof LocalStorage || $storage instanceof ConfigurableLocalStorage) {
            try {
                $absolute = $storage->getAbsolutePath((string)$storedFile['storage_path']);
                return is_file($absolute) ? $absolute : null;
            } catch (\Throwable $e) {
                return null;
            }
        }

        // Object storage: fetch the original through a presigned URL into a temp file.
        $presigned = $storage->getPresignedUrl((string)$storedFile['storage_path'], 3600);
        if ($presigned === null) {
            return null;
        }

        $tempPath = $workDir . DIRECTORY_SEPARATOR . 'source.bin';
        $expectedBytes = (int)($storedFile['file_size'] ?? 0);

        $ch = curl_init($presigned);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 1800,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_USERAGENT => 'fyuhls-transcode/1.0',
        ]);
        $fp = @fopen($tempPath, 'wb');
        if ($fp === false) {
            curl_close($ch);
            return null;
        }
        curl_setopt($ch, CURLOPT_FILE, $fp);

        $ok = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if ($ok === false) {
            @unlink($tempPath);
            throw new \RuntimeException('Could not download source video for transcoding: ' . $err);
        }

        $size = (int)@filesize($tempPath);
        if ($expectedBytes > 0 && $size !== $expectedBytes) {
            @unlink($tempPath);
            throw new \RuntimeException('Downloaded source video size did not match the stored record.');
        }

        $cleanupSource = true;
        return $tempPath;
    }

    private static function resolveRenditionHeights(?int $sourceHeight = null): array
    {
        $raw = trim((string)Setting::get('video_transcode_resolutions', self::DEFAULT_RESOLUTIONS));
        $heights = [];
        foreach (explode(',', $raw) as $part) {
            $height = (int)trim($part);
            if ($height < 144 || $height > 2160) {
                continue;
            }

            // Never upscale beyond the source resolution: a low-resolution
            // source keeps its original quality instead of being blown up.
            if ($sourceHeight !== null && $sourceHeight > 0 && $height > $sourceHeight) {
                $height = $sourceHeight;
            }

            if (!in_array($height, $heights, true)) {
                $heights[] = $height;
            }
        }
        sort($heights);
        return $heights !== [] ? $heights : [1080];
    }

    private static function bitrateForHeight(int $height): int
    {
        if (isset(self::BITRATE_BY_HEIGHT[$height])) {
            return self::BITRATE_BY_HEIGHT[$height];
        }

        return (int)max(300000, $height * 4600);
    }

    private static function transcodeRendition(string $ffmpeg, string $sourcePath, string $workDir, int $height): ?array
    {
        $heightDir = $workDir . DIRECTORY_SEPARATOR . (string)$height;
        if (!is_dir($heightDir) && !mkdir($heightDir, 0755, true) && !is_dir($heightDir)) {
            return null;
        }

        $bitrate = self::bitrateForHeight($height);
        $segmentPattern = $heightDir . DIRECTORY_SEPARATOR . 'seg_%03d.ts';
        $playlist = $heightDir . DIRECTORY_SEPARATOR . 'index.m3u8';

        $cmd = escapeshellarg($ffmpeg)
            . ' -y -hide_banner -loglevel error'
            . ' -i ' . escapeshellarg($sourcePath)
            . ' -map 0:v:0 -map 0:a:0?'
            . ' -vf ' . escapeshellarg('scale=-2:' . $height)
            . ' -c:v libx264 -preset veryfast -crf 23 -pix_fmt yuv420p'
            . ' -maxrate ' . $bitrate . ' -bufsize ' . (2 * $bitrate)
            . ' -c:a aac -b:a 128k -ac 2'
            . ' -f hls -hls_time ' . self::SEGMENT_SECONDS
            . ' -hls_playlist_type vod -hls_flags independent_segments'
            . ' -hls_segment_filename ' . escapeshellarg($segmentPattern)
            . ' ' . escapeshellarg($playlist)
            . ' 2>&1';

        @shell_exec($cmd);

        if (!is_file($playlist) || (int)@filesize($playlist) <= 0) {
            return null;
        }

        return [
            'height' => $height,
            'bandwidth' => $bitrate,
            'playlist' => (string)$height . '/index.m3u8',
        ];
    }

    private static function probeResolution(string $sourcePath): ?array
    {
        $ffprobe = self::ffprobePath();
        if ($ffprobe === null) {
            return null;
        }

        $out = @shell_exec(
            escapeshellarg($ffprobe)
            . ' -v error -select_streams v:0 -show_entries stream=width,height -of csv=p=0 '
            . escapeshellarg($sourcePath)
            . ' 2>&1'
        );

        if (is_string($out) && preg_match('/(\d+),(\d+)/', $out, $m)) {
            return [(int)$m[1], (int)$m[2]];
        }

        return null;
    }

    private static function writeMasterPlaylist(string $masterPath, array $renditions, ?array $resolution): void
    {
        $lines = [
            '#EXTM3U',
            '#EXT-X-VERSION:3',
        ];

        foreach ($renditions as $rendition) {
            $height = (int)$rendition['height'];
            $attrs = 'BANDWIDTH=' . (int)$rendition['bandwidth'];
            if ($resolution !== null && (int)$resolution[1] > 0) {
                $width = (int)round(((int)$resolution[0] / (int)$resolution[1]) * $height);
                if ($width % 2 !== 0) {
                    $width += 1;
                }
                $attrs .= ',RESOLUTION=' . max(2, $width) . 'x' . $height;
            }
            $lines[] = '#EXT-X-STREAM-INF:' . $attrs;
            $lines[] = (string)$rendition['playlist'];
        }

        file_put_contents($masterPath, implode("\n", $lines) . "\n");
    }

    private static function uploadRenditions($storage, string $workDir, string $token, array $renditions): array
    {
        $root = 'hls/' . $token;
        $files = [];

        // master playlist
        $masterLocal = $workDir . DIRECTORY_SEPARATOR . 'master.m3u8';
        if (is_file($masterLocal)) {
            if (!$storage->save($masterLocal, $root . '/master.m3u8')) {
                throw new \RuntimeException('Could not upload HLS master playlist.');
            }
            $files[] = $root . '/master.m3u8';
        }

        foreach ($renditions as $rendition) {
            $height = (string)$rendition['height'];
            $heightDir = $workDir . DIRECTORY_SEPARATOR . $height;

            $playlistLocal = $heightDir . DIRECTORY_SEPARATOR . 'index.m3u8';
            if (is_file($playlistLocal)) {
                if (!$storage->save($playlistLocal, $root . '/' . $height . '/index.m3u8')) {
                    throw new \RuntimeException('Could not upload HLS rendition playlist for ' . $height . 'p.');
                }
                $files[] = $root . '/' . $height . '/index.m3u8';
            }

            $segments = @glob($heightDir . DIRECTORY_SEPARATOR . 'seg_*.ts') ?: [];
            foreach ($segments as $segment) {
                if (!$storage->save($segment, $root . '/' . $height . '/' . basename($segment))) {
                    throw new \RuntimeException('Could not upload HLS segment ' . basename($segment) . ' for ' . $height . 'p.');
                }
                $files[] = $root . '/' . $height . '/' . basename($segment);
            }
        }

        return $files;
    }

    private static function markReady(int $jobId, string $token, array $renditions, array $files): void
    {
        self::ensureSchema();
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("UPDATE video_transcode_jobs SET status = 'ready', progress = 100, manifest_path = ?, renditions_json = ?, error_message = NULL, completed_at = NOW() WHERE id = ?");
        $stmt->execute([
            'hls/' . $token . '/master.m3u8',
            json_encode(['renditions' => $renditions, 'files' => $files]),
            $jobId,
        ]);
    }

    /**
     * Best-effort deletion of all HLS rendition objects for a stored file.
     * Called from the stored-file purge path so derived HLS output does not
     * leak after the original is deleted.
     */
    public static function deleteRenditionsForStoredFile(int $storedFileId): void
    {
        self::ensureSchema();
        $db = Database::getInstance()->getConnection();

        $job = self::findJobByStoredFileId($storedFileId);
        if (!$job) {
            return;
        }

        $storedFile = StoredFile::find($storedFileId);
        if (!$storedFile) {
            return;
        }

        $provider = StorageManager::getProviderById(
            $storedFile['file_server_id'] ? (int)$storedFile['file_server_id'] : null,
            $db
        );

        $paths = [];
        $manifest = trim((string)($job['manifest_path'] ?? ''));
        if ($manifest !== '') {
            $paths[] = $manifest;
        }

        $decoded = json_decode((string)($job['renditions_json'] ?? ''), true);
        if (is_array($decoded) && !empty($decoded['files']) && is_array($decoded['files'])) {
            foreach ($decoded['files'] as $file) {
                $file = trim((string)$file);
                if ($file !== '' && !in_array($file, $paths, true)) {
                    $paths[] = $file;
                }
            }
        }

        foreach ($paths as $path) {
            try {
                $provider->delete($path);
            } catch (\Throwable $e) {
                Logger::warning('HLS rendition cleanup failed', [
                    'stored_file_id' => $storedFileId,
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private static function markFailed(int $jobId, string $message): void
    {
        self::ensureSchema();
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("UPDATE video_transcode_jobs SET status = 'failed', error_message = ?, completed_at = NOW() WHERE id = ?");
        $stmt->execute([substr($message, 0, 2000), $jobId]);
    }
}
