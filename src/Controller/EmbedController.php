<?php

namespace App\Controller;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\View;
use App\Model\File;
use App\Model\Package;
use App\Model\Setting;
use App\Service\DownloadManager;
use App\Service\DownloadPageService;
use App\Service\VideoTranscodeService;

/**
 * Dood-style embeddable player: GET /embed/{short_id}
 * Framable anywhere, HLS-first, popunder + banner ads, watch-gated rewards.
 */
class EmbedController
{
    public function show(string $id): void
    {
        header_remove('X-Frame-Options');

        $file = File::findByShortId(trim($id));
        if (!$file) {
            http_response_code(404);
            echo 'Video not found';
            return;
        }

        if (!$this->canAccessFile($file)) {
            http_response_code(403);
            echo 'Private video';
            return;
        }

        $package = Auth::check()
            ? Package::getUserPackage((int)(Auth::id() ?? 0))
            : Package::getGuestPackage();
        if (!is_array($package)) {
            $package = Package::getGuestPackage();
        }

        $downloadPageService = new DownloadPageService();
        $viewModel = $downloadPageService->buildShowViewModel(is_array($file) ? $file : [], is_array($package) ? $package : []);
        $streamingEligible = (bool)($viewModel['streamingEligible'] ?? false);
        $showAds = (bool)($viewModel['showAds'] ?? false);

        $hlsUrl = null;
        if ($streamingEligible) {
            try {
                VideoTranscodeService::enqueueStoredFile($file);
                $storedFileId = (int)($file['stored_file_id'] ?? 0);
                if ($storedFileId > 0) {
                    $job = VideoTranscodeService::findJobByStoredFileId($storedFileId);
                    if (is_array($job) && ($job['status'] ?? '') === 'ready') {
                        $hlsUrl = VideoTranscodeService::manifestUrl($job);
                    }
                }
            } catch (\Throwable $e) {
                $hlsUrl = null;
            }
        }

        // Embeds always get a stream session when the video is playable;
        // watch thresholds still gate the actual reward server-side.
        $streamSessionId = null;
        $streamUrl = null;
        $streamCsrf = Csrf::generate();
        if ($streamingEligible) {
            try {
                $fraud = new \App\Service\RewardFraudService();
                $fraud->ensureVisitorCookie();
                $session = $fraud->createDownloadSession($file, Auth::id() ? (int)Auth::id() : null, [], 'stream');
                $streamSessionId = $session['public_id'] ?? null;
                if ($streamSessionId !== null) {
                    $streamUrl = (new DownloadManager())->generateSignedUrl(
                        (string)($file['short_id'] ?? $file['id']),
                        $file['filename'],
                        $streamSessionId,
                        'stream'
                    ) . '&stream=1';
                }
            } catch (\Throwable $e) {
                $streamSessionId = null;
                $streamUrl = null;
            }
        }

        View::render('home/partials/embed_player.php', [
            'file' => $file,
            'showAds' => $showAds,
            'adTop' => \App\Service\AdLoadService::renderSlot($showAds, 'top', 'ad_download_top', \App\Service\AdLoadService::uploaderTierForFile($file)),
            'adBottom' => \App\Service\AdLoadService::renderSlot($showAds, 'bottom', 'ad_download_bottom', \App\Service\AdLoadService::uploaderTierForFile($file)),
            'adPopunder' => \App\Service\AdLoadService::renderSlot($showAds, 'popunder', 'ad_popunder', \App\Service\AdLoadService::uploaderTierForFile($file)),
            'adNative' => \App\Service\AdLoadService::renderSlot($showAds, 'native', 'ad_native', \App\Service\AdLoadService::uploaderTierForFile($file)),
            'blockAdblock' => !empty($package['block_adblock']),
            'hlsUrl' => $hlsUrl,
            'streamUrl' => $streamUrl,
            'displayMimeType' => $downloadPageService->resolveDisplayMimeType($file),
            'streamSessionId' => $streamSessionId,
            'streamCsrf' => $streamCsrf,
            'videoEmbed' => null,
        ]);
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
