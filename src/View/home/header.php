<?php
$showRewards = \App\Service\FeatureService::rewardsEnabled();
$showAffiliate = $showRewards;
$pageLocale = $pageLocale ?? \App\Service\SiteContentService::requestLocale();

// read site name from DB, fall back to config, fall back to 'fyuhls'
$siteName = \App\Model\Setting::getOrConfig('app.name', \App\Core\Config::get('app_name', 'fyuhls'));
$seoFilename = $filename
    ?? ((isset($file) && is_array($file) && isset($file['filename'])) ? (string)$file['filename'] : '');
$seoIsPublic = $isPublic
    ?? ((isset($file) && is_array($file) && array_key_exists('is_public', $file)) ? !empty($file['is_public']) : true);

$seoDocument = \App\Service\SeoService::getDocumentMeta([
    'title' => $title ?? $siteName,
    'metaDescription' => $metaDescription ?? '',
    'filename' => $seoFilename,
    'isPublic' => $seoIsPublic,
]);
$resolvedTitle = $seoDocument['title'];
$generatedHead = \App\Service\SeoService::buildHead([
    'title' => $title ?? $siteName,
    'metaDescription' => $metaDescription ?? '',
    'filename' => $seoFilename,
    'isPublic' => $seoIsPublic,
]);
$allowRegistrations = \App\Model\Setting::get('allow_registrations', '1') === '1';
$currentUserId = \App\Core\Auth::check() ? (int)(\App\Core\Auth::id() ?? 0) : 0;
$showPromotions = \App\Service\FeatureService::rewardsEnabled()
    && (
        $currentUserId > 0
            ? \App\Service\BonusOfferService::hasVisiblePromotions($currentUserId)
            : \App\Service\BonusOfferService::hasVisiblePromotions()
    );
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars((string)$pageLocale) ?>">
<head>
    <script>
    try {
        var fyuhlsTheme = localStorage.getItem('fyuhls-theme') || 'dark';
        if (fyuhlsTheme !== 'dark' && fyuhlsTheme !== 'light') { fyuhlsTheme = 'dark'; }
        document.documentElement.setAttribute('data-theme', fyuhlsTheme);
        document.documentElement.setAttribute('data-bs-theme', fyuhlsTheme);
    } catch (e) {
        document.documentElement.setAttribute('data-theme', 'dark');
    }
    </script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($resolvedTitle) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/style.css">
    <?= $generatedHead . "\n" ?>
    <style>
        .home-body {
            background-color: #f3f4f6;
            color: #1f2937;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        .home-header {
            background: white;
            border-bottom: 1px solid var(--border-color);
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 50;
        }
        .home-logo {
            font-size: 1.725rem;
            font-weight: 700;
            color: var(--primary-color);
            text-decoration: none;
            letter-spacing: -0.5px;
        }
        .home-nav {
            display: flex;
            gap: 2rem;
            align-items: center;
        }
        .home-nav-link {
            text-decoration: none;
            color: var(--text-color);
            font-weight: 500;
            padding: 0.5rem 0;
        }
        .home-nav-link--strong { font-weight: 600; }
        .home-notification-link { text-decoration: none; position: relative; }
        .home-notification-icon { position: relative; top: 3px; }
        .home-notification-badge {
            position: absolute;
            top: 0;
            right: 0;
            background: #ef4444;
            color: white;
            border-radius: 50%;
            width: 16px;
            height: 16px;
            font-size: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            border: 2px solid white;
        }
        .home-logout-form { margin: 0; }
        .home-logout-btn {
            text-decoration: none;
            color: var(--text-color);
            font-weight: 500;
            background: var(--border-color);
            padding: 0.5rem 1rem;
            border-radius: 6px;
            border: 0;
            cursor: pointer;
        }
        .home-signup-link {
            text-decoration: none;
            color: white;
            font-weight: 500;
            background: var(--primary-color);
            padding: 0.5rem 1.25rem;
            border-radius: 6px;
            box-shadow: 0 4px 6px -1px rgba(37, 99, 235, 0.2);
        }
        .home-main {
            flex: 1;
            display: flex;
            flex-direction: column;
        }
    </style>
    <?= $extraHead ?? '' ?>
</head>
<body class="home-body">

    <!-- Top Navigation -->
    <header class="home-header">
        <a href="/" class="home-logo"><?= htmlspecialchars($siteName) ?></a>

        <div class="home-nav">
            <a href="/" class="home-nav-link">Home</a>
            <a href="<?= htmlspecialchars(\App\Service\SiteContentService::localizeUrl('/plans', $pageLocale)) ?>" class="home-nav-link">Premium</a>
            <a href="<?= htmlspecialchars(\App\Service\SiteContentService::localizeUrl('/faq', $pageLocale)) ?>" class="home-nav-link">FAQ</a>
            <a href="<?= htmlspecialchars(\App\Service\SiteContentService::localizeUrl('/api', $pageLocale)) ?>" class="home-nav-link">API</a>
            <?php if (\App\Core\Auth::check()): ?>
                <?php if ($showAffiliate): ?>
                    <a href="<?= htmlspecialchars(\App\Service\SiteContentService::localizeUrl('/affiliate', $pageLocale)) ?>" class="home-nav-link">Affiliate</a>
                <?php endif; ?>
                <?php if ($showPromotions): ?>
                    <a href="<?= htmlspecialchars(\App\Service\SiteContentService::localizeUrl('/promotions', $pageLocale)) ?>" class="home-nav-link">Promotions</a>
                <?php endif; ?>
            <?php endif; ?>
            <a href="<?= htmlspecialchars(\App\Service\SiteContentService::localizeUrl('/upload', $pageLocale)) ?>" class="home-nav-link home-nav-link--strong">Upload</a>
            <button type="button" class="theme-toggle" data-theme-toggle aria-label="Toggle dark mode" title="Toggle dark mode">
                <svg class="theme-icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path></svg>
                <svg class="theme-icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"></path></svg>
            </button>

            <?php if (\App\Core\Auth::check()): ?>
                <a href="/notifications" class="home-notification-link">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="black" stroke-width="2" class="home-notification-icon">
                        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                        <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                    </svg>
                    <?php
                    try {
                        $unreadNotifications = \App\Service\NotificationService::getUnread(\App\Core\Auth::id() ?? 0);
                        $unreadCount = count($unreadNotifications);
                        if ($unreadCount > 0): ?>
                            <span class="home-notification-badge">
                                <?= $unreadCount ?>
                            </span>
                        <?php endif;
                    } catch (\Exception $e) { /* Ignore fatal unloads */ } ?>
                </a>

                <?php if (\App\Core\Auth::isAdmin()): ?>
                    <a href="/admin" class="home-nav-link home-nav-link--strong">Admin</a>
                <?php endif; ?>

                <form action="/logout" method="POST" class="home-logout-form">
                    <?= \App\Core\Csrf::field() ?>
                    <button type="submit" class="home-logout-btn">Logout</button>
                </form>
            <?php else: ?>
                <a href="<?= htmlspecialchars(\App\Service\SiteContentService::localizeUrl('/login', $pageLocale)) ?>" class="home-nav-link">Login</a>
                <?php if ($allowRegistrations): ?>
                    <a href="<?= htmlspecialchars(\App\Service\SiteContentService::localizeUrl('/register', $pageLocale)) ?>" class="home-signup-link">Sign Up</a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </header>

    <main class="home-main">
