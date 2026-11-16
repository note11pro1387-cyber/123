<?php
require_once __DIR__ . '/app/support/CspReportOnly.php';
CspReportOnly::sendHtmlSecurityHeaders();
/** Dedicated read-only admin audit timeline; no business data is embedded in the HTML. */
header('X-Robots-Tag: noindex, nofollow, noarchive', true);
?><!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
  <meta name="robots" content="noindex, nofollow, noarchive">
  <meta name="referrer" content="same-origin">
  <meta name="theme-color" content="#0f172a">
  <title>گزارش‌های ممیزی | MR NEON</title>
  <link rel="preload" href="assets/fonts/Vazirmatn/webfonts/Vazirmatn-Regular.woff2" as="font" type="font/woff2" crossorigin="anonymous">
  <link rel="stylesheet" href="css/global.css">
  <link rel="stylesheet" href="css/components.css">
</head>
<body class="admin-workspace-page admin-audit-route" data-page="admin-audit">
  <a href="#adminAuditTitle" class="skip-link">پرش به گزارش‌های ممیزی</a>
  <div class="admin-page-topbar" id="adminPageTopbar" tabindex="-1">
    <a class="admin-page-brand" href="admin.php" aria-label="MR NEON — بازگشت به مدیریت">MR<span>•</span>NEON</a>
    <p>گزارش‌های ممیزی مدیریت</p>
    <div class="admin-page-topbar-actions">
      <a class="admin-page-home-link" href="admin-messages.php">صندوق پیام‌ها</a>
      <a class="admin-page-home-link" href="admin-settings.php">تنظیمات</a>
      <a class="admin-page-home-link" href="admin.php">بازگشت به فضای کاری مدیریت</a>
    </div>
  </div>
  <div id="adminPageNotices" class="admin-page-notices" aria-live="polite" aria-relevant="additions text"></div>
  <?php include_once 'components/admin-modal.html'; ?>
  <?php include_once 'components/admin-audit.html'; ?>
  <script type="module" src="js/admin-entry.js"></script>
</body>
</html>
