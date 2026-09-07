<?php
// Standalone, embeddable video player. Renders ONLY the player, ads, and
// reward tracking — no site header/footer. Loaded via /embed/{id}.
$embedFile = isset($file) && is_array($file) ? $file : [];
$embedShowAds = !empty($showAds);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars((string)($embedFile['filename'] ?? 'Video')) ?></title>
<style>
    html,body{margin:0;padding:0;background:#000;height:100%;overflow:hidden}
    .embed-shell{display:flex;flex-direction:column;height:100%;width:100%}
    .embed-ad{background:#0b0b0b;text-align:center;overflow:hidden;flex:0 0 auto}
    .embed-player{flex:1 1 auto;min-height:0;background:#000;position:relative}
    .embed-player video{width:100%;height:100%;display:block;background:#000}
    .embed-frame{position:absolute;inset:0;width:100%;height:100%}
    .embed-frame iframe{position:absolute;inset:0;width:100%;height:100%;border:0}
    .embed-empty{color:#fff;font-family:system-ui,sans-serif;display:flex;align-items:center;justify-content:center;height:100%;padding:1rem;text-align:center}
</style>
</head>
<body>
<div class="embed-shell">
    <?php if ($embedShowAds && ($adTop ?? '') !== ''): ?>
        <div class="embed-ad"><?= $adTop ?></div>
    <?php endif; ?>

    <div class="embed-player">
        <?php if (($hlsUrl ?? null) !== null): ?>
            <video id="rewardStreamPlayer" controls playsinline></video>
        <?php elseif (($streamUrl ?? null) !== null): ?>
            <video id="rewardStreamPlayer" controls playsinline>
                <source src="<?= htmlspecialchars((string)$streamUrl) ?>" type="<?= htmlspecialchars((string)$displayMimeType) ?>">
            </video>
        <?php elseif (!empty($videoEmbed)): ?>
            <?php if (($videoEmbed['kind'] ?? '') === 'video'): ?>
                <video controls playsinline src="<?= htmlspecialchars((string)$videoEmbed['src']) ?>"></video>
            <?php else: ?>
                <div class="embed-frame">
                    <iframe
                        src="<?= htmlspecialchars((string)$videoEmbed['src'], ENT_QUOTES, 'UTF-8') ?>"
                        allowfullscreen
                        allow="autoplay; fullscreen; encrypted-media; picture-in-picture"
                        referrerpolicy="no-referrer"
                        sandbox="allow-scripts allow-same-origin allow-presentation allow-popups allow-forms"
                    ></iframe>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="embed-empty">This file is not playable as a video.</div>
        <?php endif; ?>
    </div>

    <?php if ($embedShowAds && ($adBottom ?? '') !== ''): ?>
        <div class="embed-ad"><?= $adBottom ?></div>
    <?php endif; ?>

    <?php if ($embedShowAds && ($adPopunder ?? '') !== ''): ?>
        <div style="display:none"><?= $adPopunder ?></div>
    <?php endif; ?>
</div>

<?php if (!empty($blockAdblock)): ?>
    <?= (new \App\Service\SecurityService())->getAntiAdblockScript() ?>
<?php endif; ?>

<script>
<?php if (($hlsUrl ?? null) !== null): ?>
(function() {
    const player = document.getElementById("rewardStreamPlayer");
    if (!player) return;
    const hlsUrl = <?= json_encode((string)$hlsUrl) ?>;

    function attach() {
        if (window.Hls && Hls.isSupported()) {
            const hls = new Hls({ enableWorker: false });
            hls.loadSource(hlsUrl);
            hls.attachMedia(player);
            hls.on(Hls.Events.ERROR, function(event, data) {
                if (!data || !data.fatal) return;
                if (data.type === Hls.ErrorTypes.NETWORK_ERROR) {
                    hls.startLoad();
                } else if (data.type === Hls.ErrorTypes.MEDIA_ERROR) {
                    hls.recoverMediaError();
                }
            });
        } else if (player.canPlayType("application/vnd.apple.mpegurl")) {
            player.src = hlsUrl;
        }
    }

    if (window.Hls) {
        attach();
    } else {
        const s = document.createElement("script");
        s.src = "https://cdn.jsdelivr.net/npm/hls.js@1";
        s.async = true;
        s.onload = attach;
        document.head.appendChild(s);
    }
})();
<?php endif; ?>

<?php if (($streamSessionId ?? null) !== null): ?>
(function() {
    const player = document.getElementById("rewardStreamPlayer");
    if (!player) return;
    const sessionId = <?= json_encode($streamSessionId) ?>;
    const fileId = <?= json_encode((string)($embedFile['short_id'] ?? '')) ?>;
    const csrfToken = <?= json_encode($streamCsrf) ?>;
    let lastReported = 0;
    let completed = false;

    function sendUpdate(state) {
        if (!player.duration || !isFinite(player.duration)) return;
        const current = Math.max(0, player.currentTime || 0);
        const percent = Math.min(100, (current / player.duration) * 100);
        const payload = new URLSearchParams();
        payload.set("csrf_token", csrfToken);
        payload.set("file_id", String(fileId));
        payload.set("session_id", sessionId);
        payload.set("state", state);
        payload.set("watch_seconds", String(Math.floor(current)));
        payload.set("watch_percent", String(percent.toFixed(2)));
        payload.set("current_time", String(current.toFixed(2)));
        payload.set("duration", String(player.duration.toFixed(2)));

        fetch("/file/stream-heartbeat", {
            method: "POST",
            headers: {"Content-Type": "application/x-www-form-urlencoded"},
            credentials: "same-origin",
            body: payload.toString()
        }).catch(function() {});
    }

    player.addEventListener("timeupdate", function() {
        if ((player.currentTime - lastReported) >= 10) {
            lastReported = player.currentTime;
            sendUpdate("progress");
        }
    });

    player.addEventListener("ended", function() {
        if (completed) return;
        completed = true;
        sendUpdate("complete");
    });
})();
<?php endif; ?>
</script>
</body>
</html>
