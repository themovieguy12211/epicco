<div class="config-section-shell">
    <div class="config-section-nav">
        <div class="config-section-nav__eyebrow">General Sections</div>
        <div class="nav flex-column nav-pills">
            <a class="nav-link text-start active" href="#general-identity"><i class="bi bi-badge-ad me-2"></i> Site Identity</a>
            <a class="nav-link text-start" href="#general-platform"><i class="bi bi-toggles me-2"></i> Platform Controls</a>
            <a class="nav-link text-start" href="#general-thumbnails"><i class="bi bi-image me-2"></i> Thumbnail Generation</a>
            <a class="nav-link text-start" href="#general-transcoding"><i class="bi bi-film me-2"></i> Video Transcoding</a>
        </div>
    </div>
    <div class="config-section-content">
        <div class="config-section-intro">
            <div>
                <h5 class="config-section-intro__title">General</h5>
                <p class="config-section-intro__text">Manage the core site identity, member-facing platform switches, thumbnail generation, and the HLS video transcoding pipeline.</p>
            </div>
        </div>
        <details class="config-help-panel">
            <summary>How this works</summary>
            <div class="config-help-panel__body">
                <p>These are the install-wide defaults that define what the site is called, whether new users can sign up, and how media is processed. Image thumbnails need the GD extension; video thumbnails and HLS renditions need the FFmpeg binary below. Transcoding runs on the cron heartbeat and serves through <code>/stream/hls/</code> and <code>/embed/</code> with no extra setup.</p>
            </div>
        </details>

<form method="POST" action="/admin/configuration/save">
    <?= \App\Core\Csrf::field() ?>
    <input type="hidden" name="section" value="general">

    <div id="general-identity"></div>
    <?php renderAdminCardStart('Site Identity', ['cardClass' => 'border-0 shadow-sm mb-4 config-section-card']); ?>
        <div class="row">
            <div class="col-md-6 mb-4">
                <label class="form-label fw-bold">Site Name</label>
                <input type="text" class="form-control" name="app_name" value="<?= htmlspecialchars($appName) ?>" placeholder="Fyuhls">
                <small class="config-form-note">The name of your file hosting platform.</small>
            </div>
            <div class="col-md-6 mb-4">
                <label class="form-label fw-bold">Admin Notification Email</label>
                <input type="email" class="form-control" name="admin_notification_email" value="<?= htmlspecialchars($adminEmail) ?>" placeholder="admin@example.com">
                <small class="config-form-note">Where alerts (DMCA, Abuse) are sent.</small>
            </div>
        </div>
        <div class="mb-0">
            <label class="form-label fw-bold">Reserved Usernames</label>
            <input type="text" class="form-control" name="reserved_usernames" value="<?= htmlspecialchars($reservedUsernames) ?>">
            <small class="config-form-note">Comma-separated list of names that cannot be registered.</small>
        </div>
    <?php renderAdminCardEnd(); ?>

    <div id="general-platform"></div>
    <?php renderAdminCardStart('Platform Controls', ['cardClass' => 'border-0 shadow-sm mb-4 config-section-card']); ?>
        <div class="row">
            <div class="col-md-6">
            <div class="mb-3">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="allow_registrations" id="allowReg" value="1" <?= ($allowRegistrations === '1') ? 'checked' : '' ?>>
                    <label class="form-check-label fw-bold" for="allowReg">Allow New Registrations</label>
                </div>
                <small class="config-form-note">Turn off to close public signups.</small>
            </div>
            <div class="mb-3">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="require_email_verification" id="requireEmailVer" value="1" <?= ($requireEmailVer === '1') ? 'checked' : '' ?>>
                    <label class="form-check-label fw-bold" for="requireEmailVer">Require Email Verification</label>
                </div>
                <small class="config-form-note">Users must confirm their email before they can log in. New installs default to On.</small>
            </div>
            <div class="mb-3">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="show_powered_by_footer" id="showPoweredBy" value="1" <?= ($showPoweredBy === '1') ? 'checked' : '' ?>>
                    <label class="form-check-label" for="showPoweredBy">Show 'Powered by' in Footer</label>
                </div>
                <small class="config-form-note">We'd appreciate the support of leaving this enabled, as a lot of time has been put into making this script. Please consider a <a href="https://buymeacoffee.com/softerfish" target="_blank" rel="noopener noreferrer">Buy Me a Coffee</a> if you disable it.</small>
            </div>
        </div>
        <div class="col-md-6">
            <div class="mb-3">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="maintenance_mode" id="maintenanceMode" value="1" <?= ($maintenanceMode === '1') ? 'checked' : '' ?>>
                    <label class="form-check-label fw-bold text-danger" for="maintenanceMode">Maintenance Mode</label>
                </div>
                <small class="config-form-note">Only admins can access the site when enabled.</small>
            </div>
            <div class="mb-3">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="demo_mode" id="demoMode" value="1" <?= ($demoMode === '1') ? 'checked' : '' ?>>
                    <label class="form-check-label fw-bold text-danger" for="demoMode">Demo Mode</label>
                </div>
                <small class="config-form-note text-danger">Enables demo-mode behavior for the site and its designated demo admin account. A staff manager can predesignate that admin from Users before turning demo mode on, so the target account does not need to sign into itself first. If no one is designated yet, enabling demo mode falls back to the current admin. Default: Off.</small>
            </div>
            </div>
        </div>
    <?php renderAdminCardEnd(); ?>

    <div id="general-thumbnails"></div>
    <?php renderAdminCardStart('Thumbnail Generation', ['cardClass' => 'border-0 shadow-sm mb-4 config-section-card']); ?>
        <div class="row">
            <div class="col-lg-6 mb-4">
                <label class="form-label fw-bold">Image Thumbnails</label>
                <?php if (!empty($gdOk)): ?>
                    <div class="config-soft-callout config-soft-callout--success">
                        <p class="mb-0"><i class="bi bi-check-circle me-2"></i>GD is available - image thumbnails generate automatically at upload time.</p>
                    </div>
                <?php else: ?>
                    <div class="config-soft-callout config-soft-callout--warning">
                        <p class="mb-2"><i class="bi bi-exclamation-triangle me-2"></i>PHP GD is missing, so image thumbnails are skipped.</p>
                        <p class="mb-0">Ask your host to enable the <code>gd</code> extension, then uploads start producing thumbnails with no further setup.</p>
                    </div>
                <?php endif; ?>
            </div>
            <div class="col-lg-6 mb-4">
                <label class="form-label fw-bold">Video Thumbnails</label>
                <?php if (!empty($ffmpegOk)): ?>
                    <div class="config-soft-callout config-soft-callout--success">
                        <p class="mb-0"><i class="bi bi-check-circle me-2"></i>FFmpeg is configured - video thumbnails generate automatically at upload time.</p>
                    </div>
                <?php else: ?>
                    <div class="config-soft-callout config-soft-callout--warning">
                        <p class="mb-2"><i class="bi bi-camera-video me-2"></i>Video thumbnails need the FFmpeg binary set below.</p>
                        <p class="mb-0">Shared hosting often does not provide FFmpeg or blocks <code>shell_exec</code> - on those plans video uploads simply skip thumbnails and keep working.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php renderAdminCardEnd(); ?>

    <div id="general-transcoding"></div>
    <?php renderAdminCardStart('Video Transcoding (HLS)', ['cardClass' => 'border-0 shadow-sm mb-4 config-section-card']); ?>
        <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" name="video_transcode_enabled" id="videoTranscodeEnabled" value="1" <?= (($transcodeEnabled ?? '0') === '1') ? 'checked' : '' ?>>
            <label class="form-check-label fw-bold" for="videoTranscodeEnabled">Enable HLS Transcoding</label>
            <div class="config-form-note mt-1">Turns uploads into adaptive multi-quality streams played through <code>/stream/hls/</code> and the <code>/embed/</code> player. The cron heartbeat picks up pending jobs automatically.</div>
        </div>
        <div class="row">
            <div class="col-lg-6 mb-4">
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" name="ffmpeg_enabled" id="ffmpegEnabled" value="1" <?= (($ffmpegEnabled ?? '1') === '1') ? 'checked' : '' ?>>
                    <label class="form-check-label fw-bold" for="ffmpegEnabled">Enable FFmpeg</label>
                </div>
                <label class="form-label fw-bold" for="ffmpegPath">FFmpeg Binary Path</label>
                <input type="text" class="form-control font-monospace" name="ffmpeg_path" id="ffmpegPath" value="<?= htmlspecialchars((string)($ffmpegPath ?? '')) ?>" placeholder="/usr/bin/ffmpeg">
                <div class="config-form-note mt-1">
                    <?php if (!empty($ffmpegOk)): ?>
                        <span class="text-success"><i class="bi bi-check-circle me-1"></i>Binary found and ready.</span>
                    <?php else: ?>
                        <span class="text-warning"><i class="bi bi-exclamation-triangle me-1"></i>Binary not found at this path. Run <code>which ffmpeg</code> over SSH, or leave it empty on hosts without FFmpeg.</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-lg-6 mb-4">
                <label class="form-label fw-bold" for="transcodeResolutions">Rendition Ladder (heights, px)</label>
                <input type="text" class="form-control font-monospace" name="transcode_resolutions" id="transcodeResolutions" value="<?= htmlspecialchars((string)($transcodeResolutions ?? '1080')) ?>" placeholder="360,480,720,1080">
                <div class="config-form-note mt-1">Comma-separated heights from 144 to 2160. Sources are never upscaled past their own resolution. Fewer renditions = faster jobs + less storage.</div>
            </div>
        </div>
        <div class="config-soft-callout mb-0">
            <div class="fw-bold mb-1">Transcode queue<?= (!empty($transcodeCounts['pending']) || !empty($transcodeCounts['processing'])) ? ' - working through jobs' : '' ?></div>
            <?php if ($transcodeCounts['pending'] === null): ?>
                <span class="small text-muted">Queue stats appear after Database Health &gt; Deep Repair creates the jobs table.</span>
            <?php else: ?>
                <span class="small text-muted">
                    Pending <?= (int)$transcodeCounts['pending'] ?> &middot;
                    Processing <?= (int)$transcodeCounts['processing'] ?> &middot;
                    Ready <?= (int)$transcodeCounts['ready'] ?> &middot;
                    Failed <?= (int)$transcodeCounts['failed'] ?>
                </span>
            <?php endif; ?>
        </div>
    <?php renderAdminCardEnd(); ?>

    <div class="config-sticky-save">
        <p class="config-sticky-save__text">General settings control the install-wide defaults most users feel first, while the media sections above preview the thumbnail and transcoding features planned for a future release.</p>
        <button type="submit" class="btn btn-primary px-5">
            <i class="bi bi-save me-2"></i> Save General Configuration
        </button>
    </div>
</form>
</div>
</div>
