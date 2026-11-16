<?php
require_once __DIR__ . '/app/support/CspReportOnly.php';
CspReportOnly::sendHtmlSecurityHeaders();
require_once __DIR__ . '/app/support/PublicBaseUrl.php';
$publicBaseUrl = mrneonNormalizePublicBaseUrl((string) (getenv('MRNEON_PUBLIC_BASE_URL') ?: ''));
$publicHomeUrl = $publicBaseUrl !== '' ? $publicBaseUrl . '/' : '';
$publicOgImageUrl = $publicBaseUrl !== '' ? $publicBaseUrl . '/assets/images/og-image.png' : '';
?>
<!--
  🧬 MR NEON ARCHITECTURE COMPONENT
  📄 File: index.php
  🎯 Responsibility: پوسته اصلی، SEO، ساختار بخش‌های عمومی سایت و include کامپوننت‌های Modal
  🔌 Dependencies: css/global.css, css/components.css, js/app.js (type=module)
  🚫 Constraints: Do NOT change IDs or DOM structure. JS relies on them.
-->
<!DOCTYPE html>
<html lang="fa" dir="rtl">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0" />
    <title>MR NEON | طراحی و توسعه وب‌سایت</title>
    <meta name="description" content="MR NEON؛ طراحی و توسعه وب‌سایت، رابط کاربری و تجربه کاربری و راهکارهای دیجیتال متناسب با نیاز هر پروژه." />
    <meta name="theme-color" content="#0f172a" />
    <meta name="robots" content="<?= $publicBaseUrl !== '' ? 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1' : 'noindex, nofollow, noarchive' ?>" />
    <meta name="twitter:card" content="<?= $publicOgImageUrl !== '' ? 'summary_large_image' : 'summary' ?>" />
    <meta name="twitter:title" content="MR NEON | طراحی و توسعه وب‌سایت" />
    <meta name="twitter:description" content="طراحی و توسعه وب‌سایت و تجربه‌های دیجیتال متناسب با نیاز پروژه." />
    <meta property="og:title" content="MR NEON | طراحی و توسعه وب‌سایت" />
    <meta property="og:description" content="طراحی و توسعه وب‌سایت، رابط کاربری و تجربه کاربری و راهکارهای دیجیتال متناسب با نیاز هر پروژه." />
    <meta property="og:type" content="website" />
    <meta property="og:site_name" content="MR NEON" />
    <meta property="og:locale" content="fa_IR" />
    <link rel="icon" type="image/svg+xml" href="assets/images/favicon.svg" />
    <link rel="icon" type="image/png" sizes="32x32" href="assets/images/favicon-32x32.png" />
    <link rel="apple-touch-icon" sizes="180x180" href="assets/images/apple-touch-icon.png" />
    <link rel="preload" href="assets/fonts/Vazirmatn/webfonts/Vazirmatn-Regular.woff2" as="font" type="font/woff2" crossorigin="anonymous" />
    <link rel="stylesheet" href="css/global.css" />
    <link rel="stylesheet" href="css/components.css" />
    <link rel="stylesheet" href="css/tech-curved-loop.css" />
    <?php if ($publicBaseUrl !== ''): ?>
      <link rel="canonical" href="<?= htmlspecialchars($publicHomeUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" />
      <meta property="og:url" content="<?= htmlspecialchars($publicHomeUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" />
      <meta property="og:image" content="<?= htmlspecialchars($publicOgImageUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" />
      <meta property="og:image:type" content="image/png" />
      <meta property="og:image:width" content="1200" />
      <meta property="og:image:height" content="630" />
      <meta property="og:image:alt" content="کارت معرفی MR NEON برای طراحی و توسعه وب‌سایت" />
      <meta name="twitter:image" content="<?= htmlspecialchars($publicOgImageUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" />
      <meta name="twitter:image:alt" content="کارت معرفی MR NEON برای طراحی و توسعه وب‌سایت" />
      <?php
        $organizationSchema = [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => 'MR NEON',
            'url' => $publicHomeUrl,
            'logo' => $publicBaseUrl . '/assets/images/logo.png',
            'description' => 'طراحی و توسعه وب‌سایت و راهکارهای دیجیتال متناسب با نیاز پروژه.',
        ];
      ?>
      <script type="application/ld+json"><?= json_encode($organizationSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
    <?php endif; ?>
  </head>
  <body>
    <a href="#main-content" class="skip-link">پرش به محتوای اصلی</a>

    <div class="loading-screen" id="loadingScreen" data-state="loading" aria-busy="true" aria-hidden="false">
      <div class="loading-content">
        <div class="neon-loader" aria-hidden="true"></div>
        <div class="loading-text" aria-hidden="true">MR•NEON</div>
        <p class="loading-status" id="loadingStatus" role="status" aria-live="polite" aria-atomic="true">در حال آماده‌سازی رابط سایت…</p>
        <div class="loading-error" id="loadingError" role="alert" aria-live="assertive" aria-atomic="true" hidden>
          <p>راه‌اندازی کامل نشد. برای بارگذاری دوباره تلاش کنید.</p>
          <button type="button" class="btn btn-primary loading-retry" id="loadingRetryBtn">بارگذاری دوباره</button>
        </div>
      </div>
    </div>

    <nav class="navbar" id="navbar" role="navigation" aria-label="منوی اصلی">
      <div class="container">
        <div class="nav-brand">
          <a href="#hero" class="logo">
            <span class="logo-text">MR•NEON</span>
            <span class="logo-dot"></span>
          </a>
        </div>
        <div class="nav-menu" id="navMenu">
          <ul class="nav-list">
            <li class="nav-item">
              <a href="#hero" class="nav-link active" data-nav="home">خانه</a>
            </li>
            <li class="nav-item">
              <a href="#services" class="nav-link" data-nav="services">خدمات</a>
            </li>
            <li class="nav-item">
              <a href="#portfolio" class="nav-link" data-nav="portfolio"
                >نمونه‌کارها</a
              >
            </li>
            <li class="nav-item">
              <a href="#team" class="nav-link" data-nav="team">تیم ما</a>
            </li>
            <li class="nav-item">
              <a href="#tech" class="nav-link" data-nav="tech">تکنولوژی‌ها</a>
            </li>
            <li class="nav-item">
              <a href="#contact" class="nav-link" data-nav="contact"
                >تماس با ما</a
              >
            </li>
          </ul>
        </div>
        <div class="nav-actions">
          <button
            type="button"
            class="btn btn-outline-light btn-admin-login login-btn-desktop"
            id="loginBtnDesktop"
            aria-label="ورود"
          >
            <span>ورود</span>
            <svg
              width="20"
              height="20"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              stroke-width="2"
              aria-hidden="true"
            >
              <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
              <circle cx="12" cy="7" r="4"></circle>
            </svg>
          </button>
          <button
            type="button"
            class="btn btn-outline-light login-btn-mobile"
            id="loginBtnMobile"
            aria-label="ورود"
          >
            <svg
              width="24"
              height="24"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              stroke-width="2"
              aria-hidden="true"
            >
              <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
              <circle cx="12" cy="7" r="4"></circle>
            </svg>
            <span class="login-btn-mobile-text">ورود</span>
          </button>
          <a href="#contact" class="btn btn-primary btn-neon">
            <span>شروع پروژه</span>
            <svg
              width="20"
              height="20"
              viewBox="0 0 20 20"
              fill="none"
              xmlns="http://www.w3.org/2000/svg"
              aria-hidden="true"
            >
              <path
                d="M3.33301 10H16.6663M16.6663 10L10.833 4.16667M16.6663 10L10.833 15.8333"
                stroke="currentColor"
                stroke-width="1.5"
                stroke-linecap="round"
                stroke-linejoin="round"
              />
            </svg>
          </a>
        </div>
        <button
          type="button"
          class="hamburger"
          id="hamburger"
          aria-label="منو"
          aria-expanded="false"
        >
          <span class="hamburger-line"></span>
          <span class="hamburger-line"></span>
          <span class="hamburger-line"></span>
        </button>
      </div>
    </nav>

    <main id="main-content">
      <section class="hero" id="hero" aria-label="معرفی">
        <div class="container">
          <div class="hero-content">
            <div class="hero-badge">
              <span class="badge-text">تیم توسعه وب حرفه‌ای</span>
              <div class="badge-glow"></div>
            </div>
            <h1 class="hero-title">
              <span class="title-line">سایت کسب‌وکارتان را</span>
              <span class="title-line highlight">
                <span class="highlight-text">در کمتر از ۷۲ ساعت</span>
                <span class="highlight-glow"></span>
              </span>
              <span class="title-line">تحویل می‌گیرید</span>
            </h1>
            <p class="hero-description">
              ما در تیم MR NEON با ترکیبی از خلاقیت، تخصص و فناوری‌های روز،
              وب‌سایت‌هایی می‌سازیم که نه تنها زیبا هستند، بلکه نتایج ملموسی
              برای کسب‌وکار شما به ارمغان می‌آورند.
            </p>
            <div class="hero-features">
              <div class="feature-item">
                <div class="feature-icon" aria-hidden="true">✓</div>
                <span class="feature-text">تحویل ۲۴ تا ۷۲ ساعته</span>
              </div>
              <div class="feature-item">
                <div class="feature-icon" aria-hidden="true">✓</div>
                <span class="feature-text">پشتیبانی فنی تا شش ماه</span>
              </div>
              <div class="feature-item">
                <div class="feature-icon" aria-hidden="true">✓</div>
                <span class="feature-text">طراحی کاملاً واکنش‌گرا</span>
              </div>
              <div class="feature-item">
                <div class="feature-icon" aria-hidden="true">✓</div>
                <span class="feature-text">سئو و بهینه‌سازی داخلی</span>
              </div>
            </div>
            <div class="hero-actions">
              <a href="#portfolio" class="btn btn-primary btn-lg">
                <span>مشاهده نمونه‌کارها</span>
                <svg
                  width="20"
                  height="20"
                  viewBox="0 0 20 20"
                  fill="none"
                  xmlns="http://www.w3.org/2000/svg"
                  aria-hidden="true"
                >
                  <path
                    d="M4.16699 10H15.8337M15.8337 10L10.0003 4.16667M15.8337 10L10.0003 15.8333"
                    stroke="currentColor"
                    stroke-width="1.5"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                  />
                </svg>
              </a>
              <a href="#contact" class="btn btn-outline btn-lg">
                <span>تماس با ما</span>
                <svg
                  width="20"
                  height="20"
                  viewBox="0 0 20 20"
                  fill="none"
                  xmlns="http://www.w3.org/2000/svg"
                  aria-hidden="true"
                >
                  <path
                    d="M2.5 5.83333L9.0755 10.85C9.63533 11.2833 10.3647 11.2833 10.9245 10.85L17.5 5.83333M4.16667 15.8333H15.8333C16.7538 15.8333 17.5 15.0871 17.5 14.1667V5.83333C17.5 4.91286 16.7538 4.16667 15.8333 4.16667H4.16667C3.24619 4.16667 2.5 4.91286 2.5 5.83333V14.1667C2.5 15.0871 3.24619 15.8333 4.16667 15.8333Z"
                    stroke="currentColor"
                    stroke-width="1.5"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                  />
                </svg>
              </a>
            </div>
          </div>
          <div class="hero-visual">
            <div class="code-visual">
              <div class="code-header">
                <div class="code-dots">
                  <span class="code-dot red"></span>
                  <span class="code-dot yellow"></span>
                  <span class="code-dot green"></span>
                </div>
                <div class="code-title">mr-neon-website.js</div>
              </div>
              <div class="code-content">
                <pre><code class="language-javascript">
<span class="code-line"><span class="code-keyword">class</span> <span class="code-class">MRNeonWebsite</span> {</span>
<span class="code-line">  <span class="code-function">constructor</span>() {</span>
<span class="code-line">    <span class="code-keyword">this</span>.<span class="code-property">deliveryTime</span> = <span class="code-string">'72 ساعت'</span>;</span>
<span class="code-line">    <span class="code-keyword">this</span>.<span class="code-property">quality</span> = <span class="code-string">'حرفه‌ای'</span>;</span>
<span class="code-line">    <span class="code-keyword">this</span>.<span class="code-property">support</span> = <span class="code-string">'24/7'</span>;</span>
<span class="code-line">  }</span>
<span class="code-line">  <span class="code-function">async</span> <span class="code-function">developWebsite</span>(<span class="code-param">requirements</span>) {</span>
<span class="code-line">    <span class="code-keyword">const</span> website = <span class="code-keyword">await</span> <span class="code-function">this</span>.<span class="code-function">createDesign</span>();</span>
<span class="code-line">    <span class="code-keyword">const</span> optimized = <span class="code-keyword">await</span> <span class="code-function">this</span>.<span class="code-function">optimizeSEO</span>(website);</span>
<span class="code-line">    <span class="code-keyword">return</span> <span class="code-keyword">await</span> <span class="code-function">this</span>.<span class="code-function">deliver</span>(optimized);</span>
<span class="code-line">  }</span>
<span class="code-line">}</span>
                        </code></pre>
              </div>
            </div>
            <div class="floating-elements">
              <div class="floating-element element-1" aria-hidden="true">
                🚀
              </div>
              <div class="floating-element element-2" aria-hidden="true">
                💻
              </div>
              <div class="floating-element element-3" aria-hidden="true">
                🎨
              </div>
              <div class="floating-element element-4" aria-hidden="true">
                ⚡
              </div>
            </div>
          </div>
        </div>
        <div class="hero-background">
          <div class="particles-container" id="particles"></div>
          <div class="gradient-overlay"></div>
        </div>
      </section>

      <section class="stats" id="stats" aria-label="آمار">
        <div class="container">
          <div class="stats-grid">
            <div class="stat-card" data-count="50" data-speed="2000">
              <div class="stat-number">
                <span class="count">0</span>
                <span class="plus">+</span>
              </div>
              <div class="stat-line"></div>
              <div class="stat-label">پروژه موفق</div>
            </div>
            <div class="stat-card" data-count="95" data-speed="2000">
              <div class="stat-number">
                <span class="count">0</span>
                <span class="percent">%</span>
              </div>
              <div class="stat-line"></div>
              <div class="stat-label">رضایت مشتری</div>
            </div>
            <div class="stat-card" data-count="24" data-speed="2000">
              <div class="stat-number">
                <span class="count">0</span>
                <span class="slash">/</span>
                <span class="count-fixed">7</span>
              </div>
              <div class="stat-line"></div>
              <div class="stat-label">پشتیبانی</div>
            </div>
            <div class="stat-card" data-count="72" data-speed="2000">
              <div class="stat-number">
                <span class="count">0</span>
              </div>
              <div class="stat-line"></div>
              <div class="stat-label">ساعت تحویل</div>
            </div>
          </div>
        </div>
      </section>

      <section class="services" id="services" aria-label="خدمات">
        <div class="container">
          <div class="section-header">
            <h2 class="section-title">خدمات ما</h2>
            <div class="section-line"><div class="line-glow"></div></div>
            <p class="section-description">خدمات تخصصی ما برای کسب‌وکار شما</p>
          </div>
          <div class="services-grid">
            <div class="service-card">
              <div class="service-icon" aria-hidden="true">🛒</div>
              <h3 class="service-title">فروشگاه اینترنتی</h3>
              <p class="service-description">
                طراحی کامل سیستم فروش با درگاه پرداخت، مدیریت محصولات و
                گزارش‌گیری حرفه‌ای
              </p>
              <div class="service-neon-line"></div>
            </div>
            <div class="service-card">
              <div class="service-icon" aria-hidden="true">🏢</div>
              <h3 class="service-title">سایت شرکتی</h3>
              <p class="service-description">
                طراحی پروفایل حرفه‌ای برای معرفی خدمات، تیم و نمونه‌کارهای شرکت
              </p>
              <div class="service-neon-line"></div>
            </div>
            <div class="service-card">
              <div class="service-icon" aria-hidden="true">🎨</div>
              <h3 class="service-title">طراحی UI/UX اختصاصی</h3>
              <p class="service-description">
                طراحی رابط کاربری منحصر به فرد با تمرکز بر تجربه کاربری و زیبایی
                بصری
              </p>
              <div class="service-neon-line"></div>
            </div>
            <div class="service-card">
              <div class="service-icon" aria-hidden="true">⚡</div>
              <h3 class="service-title">بهینه‌سازی سرعت و سئو</h3>
              <p class="service-description">
                بهبود عملکرد سایت و بهینه‌سازی برای موتورهای جستجو جهت رتبه‌بندی
                بهتر
              </p>
              <div class="service-neon-line"></div>
            </div>
            <div class="service-card">
              <div class="service-icon" aria-hidden="true">🔧</div>
              <h3 class="service-title">پشتیبانی و نگهداری</h3>
              <p class="service-description">
                خدمات نگهداری مستمر، بروزرسانی امنیتی و پشتیبانی فنی
              </p>
              <div class="service-neon-line"></div>
            </div>
            <div class="service-card">
              <div class="service-icon" aria-hidden="true">📱</div>
              <h3 class="service-title">طراحی واکنش‌گرا</h3>
              <p class="service-description">
                سازگاری کامل سایت با تمام دستگاه‌های موبایل، تبلت و دسکتاپ
              </p>
              <div class="service-neon-line"></div>
            </div>
          </div>
        </div>
      </section>

      <section class="portfolio" id="portfolio" aria-label="نمونه‌کارها">
        <div class="container">
          <div class="section-header">
            <h2 class="section-title">نمونه‌کارهای ما</h2>
            <div class="section-line"><div class="line-glow"></div></div>
            <p class="section-description">
              پروژه‌هایی که با افتخار انجام داده‌ایم
            </p>
          </div>
          <div class="portfolio-filters" id="portfolioFilters">
            <button
              type="button"
              class="filter-btn active"
              data-filter="all"
              aria-pressed="true"
            >
              همه
            </button>
            <button
              type="button"
              class="filter-btn"
              data-filter="ecommerce"
              aria-pressed="false"
            >
              فروشگاهی
            </button>
            <button
              type="button"
              class="filter-btn"
              data-filter="corporate"
              aria-pressed="false"
            >
              شرکتی
            </button>
            <button
              type="button"
              class="filter-btn"
              data-filter="webapp"
              aria-pressed="false"
            >
              وب‌اپ
            </button>
            <button
              type="button"
              class="filter-btn"
              data-filter="uiux"
              aria-pressed="false"
            >
              UI/UX
            </button>
            <button
              type="button"
              class="filter-btn"
              data-filter="other"
              aria-pressed="false"
            >
              سایر
            </button>
          </div>
          <div class="portfolio-grid" id="portfolioGrid">
            <div class="portfolio-card" data-project="1" data-category="ecommerce">
              <div class="portfolio-image">
                <img
                  src="assets/images/project-1.jpg"
                  alt="فروشگاه مد و زیبایی - طراحی فروشگاه آنلاین"
                  loading="lazy"
                  data-image-fallback="replace"
                  data-image-fallback-alt="تصویر نمونه‌کار در دسترس نیست"
                width="400" height="280" decoding="async" />
                <div class="portfolio-overlay">
                  <div class="overlay-content">
                    <h3 class="overlay-title">فروشگاه مد و زیبایی</h3>
                    <span class="overlay-category">🛒 فروشگاهی</span>
                    <p class="overlay-description">
                      طراحی کامل فروشگاه آنلاین با سیستم مدیریت محصولات پیشرفته
                    </p>
                    <span class="btn btn-outline-light view-details" aria-hidden="true">
                      مشاهده جزئیات
                    </span>
                  </div>
                </div>
              </div>
              <div class="portfolio-info">
                <h3 class="portfolio-item-title">فروشگاه مد و زیبایی</h3>
                <span class="portfolio-category">🛒 فروشگاهی</span>
              </div>
            </div>
            <div class="portfolio-card" data-project="2" data-category="webapp">
              <div class="portfolio-image">
                <img
                  src="assets/images/project-2.jpg"
                  alt="پلتفرم آموزش آنلاین - وب اپلیکیشن آموزشی"
                  loading="lazy"
                  data-image-fallback="replace"
                  data-image-fallback-alt="تصویر نمونه‌کار در دسترس نیست"
                width="800" height="600" decoding="async" />
                <div class="portfolio-overlay">
                  <div class="overlay-content">
                    <h3 class="overlay-title">پلتفرم آموزش آنلاین</h3>
                    <span class="overlay-category">📱 وب‌اپ</span>
                    <p class="overlay-description">
                      پلتفرم تعاملی آموزش با سیستم ویدیو، تمرین و آزمون
                    </p>
                    <span class="btn btn-outline-light view-details" aria-hidden="true">
                      مشاهده جزئیات
                    </span>
                  </div>
                </div>
              </div>
              <div class="portfolio-info">
                <h3 class="portfolio-item-title">پلتفرم آموزش آنلاین</h3>
                <span class="portfolio-category">📱 وب‌اپ</span>
              </div>
            </div>
            <div class="portfolio-card" data-project="3" data-category="corporate">
              <div class="portfolio-image">
                <img
                  src="assets/images/project-3.jpg"
                  alt="سایت شرکتی فناوری - طراحی مدرن شرکتی"
                  loading="lazy"
                  data-image-fallback="replace"
                  data-image-fallback-alt="تصویر نمونه‌کار در دسترس نیست"
                width="800" height="600" decoding="async" />
                <div class="portfolio-overlay">
                  <div class="overlay-content">
                    <h3 class="overlay-title">سایت شرکتی فناوری</h3>
                    <span class="overlay-category">🏢 شرکتی</span>
                    <p class="overlay-description">
                      طراحی مدرن برای معرفی خدمات شرکت فناوری
                    </p>
                    <span class="btn btn-outline-light view-details" aria-hidden="true">
                      مشاهده جزئیات
                    </span>
                  </div>
                </div>
              </div>
              <div class="portfolio-info">
                <h3 class="portfolio-item-title">سایت شرکتی فناوری</h3>
                <span class="portfolio-category">🏢 شرکتی</span>
              </div>
            </div>
            <div class="portfolio-card" data-project="4" data-category="webapp">
              <div class="portfolio-image">
                <img
                  src="assets/images/project-4.jpg"
                  alt="وب‌اپ مدیریت پروژه - اپلیکیشن تحت وب"
                  loading="lazy"
                  data-image-fallback="replace"
                  data-image-fallback-alt="تصویر نمونه‌کار در دسترس نیست"
                width="400" height="280" decoding="async" />
                <div class="portfolio-overlay">
                  <div class="overlay-content">
                    <h3 class="overlay-title">وب‌اپ مدیریت پروژه</h3>
                    <span class="overlay-category">🔧 وب‌اپ</span>
                    <p class="overlay-description">
                      اپلیکیشن تحت وب برای مدیریت تیم و تسک‌ها
                    </p>
                    <span class="btn btn-outline-light view-details" aria-hidden="true">
                      مشاهده جزئیات
                    </span>
                  </div>
                </div>
              </div>
              <div class="portfolio-info">
                <h3 class="portfolio-item-title">وب‌اپ مدیریت پروژه</h3>
                <span class="portfolio-category">🔧 وب‌اپ</span>
              </div>
            </div>
            <div class="portfolio-card" data-project="5" data-category="ecommerce">
              <div class="portfolio-image">
                <img
                  src="assets/images/project-5.jpg"
                  alt="سایت رستوران لوکس - رزرو آنلاین"
                  loading="lazy"
                  data-image-fallback="replace"
                  data-image-fallback-alt="تصویر نمونه‌کار در دسترس نیست"
                width="400" height="280" decoding="async" />
                <div class="portfolio-overlay">
                  <div class="overlay-content">
                    <h3 class="overlay-title">سایت رستوران لوکس</h3>
                    <span class="overlay-category">🛒 فروشگاهی</span>
                    <p class="overlay-description">
                      سایت رزرو آنلاین با منوی دیجیتال و سیستم پرداخت
                    </p>
                    <span class="btn btn-outline-light view-details" aria-hidden="true">
                      مشاهده جزئیات
                    </span>
                  </div>
                </div>
              </div>
              <div class="portfolio-info">
                <h3 class="portfolio-item-title">سایت رستوران لوکس</h3>
                <span class="portfolio-category">🛒 فروشگاهی</span>
              </div>
            </div>
            <button
              type="button"
              class="portfolio-card view-more"
              id="viewMoreProjects"
            >
              <span class="portfolio-image">
                <span class="view-more-content">
                  <span class="view-more-icon">+</span>
                  <span class="view-more-title">پروژه‌های بیشتر</span>
                  <span class="view-more-description"
                    >برای مشاهده سایر پروژه‌ها کلیک کنید</span
                  >
                </span>
              </span>
            </button>
          </div>
        </div>
      </section>

      <div
        class="modal"
        id="projectModal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="modalTitle"
      >
        <div class="modal-overlay"></div>
        <div class="modal-content">
          <button
            type="button"
            class="modal-close"
            id="modalClose"
            aria-label="بستن"
          >
            <svg
              width="24"
              height="24"
              viewBox="0 0 24 24"
              fill="none"
              xmlns="http://www.w3.org/2000/svg"
              aria-hidden="true"
            >
              <path
                d="M18 6L6 18M6 6L18 18"
                stroke="currentColor"
                stroke-width="2"
                stroke-linecap="round"
                stroke-linejoin="round"
              />
            </svg>
          </button>
          <div class="modal-body" id="modalBody">
            <h3 id="modalTitle" hidden></h3>
          </div>
        </div>
      </div>

      <section class="team" id="team" aria-label="تیم ما">
        <div class="container">
          <div class="section-header">
            <h2 class="section-title">تیم ما</h2>
            <div class="section-line"><div class="line-glow"></div></div>
            <p class="section-description">
              متخصصانی که پروژه شما را اجرا می‌کنند
            </p>
          </div>
          <div class="team-grid">
            <div class="team-card">
              <div class="team-avatar">
                <img
                  src="assets/images/team-mj.jpg" loading="lazy"
                  alt="محمدجواد قادری"
                  class="team-photo"
                  width="880" height="1168"
                  decoding="async"
                  data-image-fallback="replace"
                  data-image-fallback-alt="تصویر عضو تیم در دسترس نیست"
                />
                <div class="avatar-glow"></div>
              </div>
              <div class="team-info">
                <h3 class="team-name">محمدجواد قادری</h3>
                <div class="team-role">Front-end Developer</div>
                <p class="team-bio">
                  تخصص در توسعه رابط‌های کاربری مدرن و تعاملی
                </p>
                <div class="team-skills">
                  <span class="skill-tag">React.js</span>
                  <span class="skill-tag">Vue.js</span>
                  <span class="skill-tag">TypeScript</span>
                  <span class="skill-tag">UI/UX Design</span>
                  <span class="skill-tag">Responsive Design</span>
                </div>
                <div class="team-social">
                  <a
                    href="mailto:mj@mrneon.dev"
                    class="social-link"
                    aria-label="ایمیل محمدجواد"
                  >
                    <svg
                      width="20"
                      height="20"
                      viewBox="0 0 24 24"
                      fill="none"
                      xmlns="http://www.w3.org/2000/svg"
                      aria-hidden="true"
                    >
                      <path
                        d="M4 4H20C21.1 4 22 4.9 22 6V18C22 19.1 21.1 20 20 20H4C2.9 20 2 19.1 2 18V6C2 4.9 2.9 4 4 4Z"
                        stroke="currentColor"
                        stroke-width="1.5"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                      />
                      <path
                        d="M22 6L12 13L2 6"
                        stroke="currentColor"
                        stroke-width="1.5"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                      />
                    </svg>
                  </a>
                </div>
              </div>
            </div>
            <div class="team-card">
              <div class="team-avatar">
                <img
                  src="assets/images/team-armin.jpg" loading="lazy"
                  alt="آرمین فرزانه"
                  class="team-photo"
                  width="731" height="1280"
                  decoding="async"
                  data-image-fallback="replace"
                  data-image-fallback-alt="تصویر عضو تیم در دسترس نیست"
                />
                <div class="avatar-glow"></div>
              </div>
              <div class="team-info">
                <h3 class="team-name">آرمین فرزانه</h3>
                <div class="team-role">Back-end Developer</div>
                <p class="team-bio">
                  تخصص در معماری سرور و پایگاه داده‌های امن و مقیاس‌پذیر
                </p>
                <div class="team-skills">
                  <span class="skill-tag">Node.js</span>
                  <span class="skill-tag">Laravel</span>
                  <span class="skill-tag">Python</span>
                  <span class="skill-tag">API Development</span>
                  <span class="skill-tag">Database Design</span>
                </div>
                <div class="team-social">
                  <a
                    href="mailto:armin@mrneon.dev"
                    class="social-link"
                    aria-label="ایمیل آرمین"
                  >
                    <svg
                      width="20"
                      height="20"
                      viewBox="0 0 24 24"
                      fill="none"
                      xmlns="http://www.w3.org/2000/svg"
                      aria-hidden="true"
                    >
                      <path
                        d="M4 4H20C21.1 4 22 4.9 22 6V18C22 19.1 21.1 20 20 20H4C2.9 20 2 19.1 2 18V6C2 4.9 2.9 4 4 4Z"
                        stroke="currentColor"
                        stroke-width="1.5"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                      />
                      <path
                        d="M22 6L12 13L2 6"
                        stroke="currentColor"
                        stroke-width="1.5"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                      />
                    </svg>
                  </a>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>

      <section class="tech-stack" id="tech" aria-labelledby="tech-title">
        <div class="container">
          <div class="section-header">
            <h2 class="section-title" id="tech-title">فناوری‌هایی که متناسب با نیاز پروژه انتخاب می‌کنیم</h2>
            <div class="section-line"><div class="line-glow"></div></div>
            <p class="section-description">
              ترکیب ابزارها با توجه به هدف، پیچیدگی، نگهداشت و الزامات هر پروژه انتخاب می‌شود.
            </p>
          </div>

          <div
            class="tech-curved-loop"
            id="techCurvedLoop"
            role="region"
            aria-roledescription="فهرست فناوری‌ها"
            aria-label="فناوری‌های مورد استفاده: React، Vue.js، Next.js، TypeScript، JavaScript، HTML5، CSS3، Tailwind CSS، Bootstrap، Node.js، Express.js، Python، Django، Laravel، PHP، MongoDB، PostgreSQL، MySQL، Docker و Git."
          >
            <svg
              class="tech-curved-loop__svg"
              viewBox="0 0 1200 360"
              preserveAspectRatio="xMidYMid meet"
              aria-hidden="true"
              focusable="false"
            >
              <defs>
                <path id="techCurvedLoopPath" d="M -1600 180 Q -1200 48 -800 180 T 0 180 T 800 180 T 1600 180 T 2400 180 T 3200 180 T 4000 180 T 4800 180 T 5600 180 T 6400 180 T 7200 180 T 8000 180 T 8800 180 T 9600 180 T 10400 180 T 11200 180 T 12000 180 T 12800 180 T 13600 180 T 14400 180 T 15200 180" />
                <linearGradient
                  id="techCurvedLoopGradient"
                  gradientUnits="userSpaceOnUse"
                  x1="0"
                  y1="0"
                  x2="1200"
                  y2="0"
                >
                  <stop offset="0" stop-color="#0d9488" />
                  <stop offset="0.35" stop-color="#06b6d4" />
                  <stop offset="0.7" stop-color="#22d3ee" />
                  <stop offset="1" stop-color="#059669" />
                </linearGradient>
              </defs>
              <text class="tech-curved-loop__text" direction="ltr" fill="url(#techCurvedLoopGradient)">
                <textPath
                  id="techCurvedLoopTextPath"
                  href="#techCurvedLoopPath"
                  startOffset="0"
                >React ✦ Vue.js ✦ Next.js ✦ TypeScript ✦ JavaScript ✦ HTML5 ✦ CSS3 ✦ Tailwind CSS ✦ Bootstrap ✦ Node.js ✦ Express.js ✦ Python ✦ Django ✦ Laravel ✦ PHP ✦ MongoDB ✦ PostgreSQL ✦ MySQL ✦ Docker ✦ Git ✦ React ✦ Vue.js ✦ Next.js ✦ TypeScript ✦ JavaScript ✦ HTML5 ✦ CSS3 ✦ Tailwind CSS ✦ Bootstrap ✦ Node.js ✦ Express.js ✦ Python ✦ Django ✦ Laravel ✦ PHP ✦ MongoDB ✦ PostgreSQL ✦ MySQL ✦ Docker ✦ Git ✦ </textPath>
              </text>
            </svg>
            <ul class="tech-curved-loop__fallback" aria-hidden="true">
              <li>React</li>
              <li>Vue.js</li>
              <li>Next.js</li>
              <li>TypeScript</li>
              <li>JavaScript</li>
              <li>HTML5</li>
              <li>CSS3</li>
              <li>Tailwind CSS</li>
              <li>Bootstrap</li>
              <li>Node.js</li>
              <li>Express.js</li>
              <li>Python</li>
              <li>Django</li>
              <li>Laravel</li>
              <li>PHP</li>
              <li>MongoDB</li>
              <li>PostgreSQL</li>
              <li>MySQL</li>
              <li>Docker</li>
              <li>Git</li>
            </ul>
          </div>
        </div>
      </section>

      <section class="contact" id="contact" aria-label="تماس با ما">
        <div class="container">
          <div class="section-header">
            <h2 class="section-title">آماده شروع پروژه‌اید؟</h2>
            <div class="section-line"><div class="line-glow"></div></div>
            <p class="section-description">
              برای ثبت پروژه از ربات هوشمند NEO استفاده کنید یا از راه‌های زیر
              با ما در ارتباط باشید
            </p>
          </div>
          <div class="contact-cards-centered">
            <div class="contact-card">
              <div class="contact-card-icon" aria-hidden="true">📧</div>
              <h3>ایمیل</h3>
              <p class="contact-card-value">contact@mrneon.dev</p>
              <button
                type="button"
                class="btn btn-outline btn-sm copy-contact-btn"
                data-text="contact@mrneon.dev"
                data-type="ایمیل"
              >
                <span>📋 کپی</span>
              </button>
            </div>
            <div class="contact-card">
              <div class="contact-card-icon" aria-hidden="true">💬</div>
              <h3>تلگرام</h3>
              <p class="contact-card-value">@MR_NEON_TEAM</p>
              <div class="contact-card-actions">
                <button
                  type="button"
                  class="btn btn-outline btn-sm copy-contact-btn"
                  data-text="@MR_NEON_TEAM"
                  data-type="آیدی تلگرام"
                >
                  <span>📋 کپی</span>
                </button>
                <button
                  type="button"
                  class="btn btn-primary btn-sm telegram-contact-btn"
                >
                  <span>📱 پیام در تلگرام</span>
                </button>
              </div>
            </div>
            <div class="contact-card highlight">
              <div class="contact-card-icon" aria-hidden="true">🤖</div>
              <h3>ربات هوشمند NEO</h3>
              <p>ثبت پروژه در ۵ دقیقه</p>
              <button
                type="button"
                class="btn btn-neon btn-sm"
                id="openNeoFromContact"
              >
                <span>🚀 شروع کنید</span>
              </button>
            </div>
          </div>
        </div>
      </section>
    </main>

    <div
      class="toast-container"
      id="toastContainer"
      role="region"
      aria-live="polite"
      aria-label="نوتیفیکیشن‌ها"
    ></div>

    <footer class="footer" role="contentinfo">
      <div class="container">
        <div class="footer-content">
          <div class="footer-brand">
            <div class="footer-logo">MR•NEON</div>
            <p class="footer-tagline">تیم برنامه‌نویسی وب حرفه‌ای</p>
          </div>
          <div class="footer-social">
            <a
              href="https://t.me/MR_NEON_TEAM"
              class="social-link"
              target="_blank"
              rel="noopener noreferrer"
              aria-label="تلگرام"
            >
              <svg
                width="24"
                height="24"
                viewBox="0 0 24 24"
                fill="none"
                xmlns="http://www.w3.org/2000/svg"
                aria-hidden="true"
              >
                <path
                  d="M21.5 3L2.5 10L9.5 13.5L15.5 8.5L10.5 14.5L18.5 21L21.5 3Z"
                  stroke="currentColor"
                  stroke-width="1.5"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                />
              </svg>
            </a>
            <a
              href="mailto:contact@mrneon.dev"
              class="social-link"
              aria-label="ایمیل"
            >
              <svg
                width="24"
                height="24"
                viewBox="0 0 24 24"
                fill="none"
                xmlns="http://www.w3.org/2000/svg"
                aria-hidden="true"
              >
                <path
                  d="M4 4H20C21.1 4 22 4.9 22 6V18C22 19.1 21.1 20 20 20H4C2.9 20 2 19.1 2 18V6C2 4.9 2.9 4 4 4Z"
                  stroke="currentColor"
                  stroke-width="1.5"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                />
                <path
                  d="M22 6L12 13L2 6"
                  stroke="currentColor"
                  stroke-width="1.5"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                />
              </svg>
            </a>
          </div>
          <div class="footer-links">
            <a href="#hero" class="footer-link">خانه</a>
            <a href="#services" class="footer-link">خدمات</a>
            <a href="#portfolio" class="footer-link">نمونه‌کارها</a>
            <a href="#contact" class="footer-link">تماس با ما</a>
            <button type="button" class="footer-link" id="adminAccessBtn" aria-haspopup="dialog">ورود مدیریت</button>
          </div>
          <div class="footer-copyright">
            <p>© <span id="currentYear">۱۴۰۳</span> - کلیه حقوق محفوظ است</p>
            <p class="copyright-love">طراحی شده توسط تیم MR NEON</p>
          </div>
        </div>
      </div>
    </footer>

    <div class="floating-contact" id="floatingContact">
      <button
        type="button"
        class="floating-contact-btn"
        id="floatingContactBtn"
        aria-label="راه‌های ارتباطی"
        aria-expanded="false"
      >
        <div class="btn-content">
          <svg
            class="icon-message"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
            aria-hidden="true"
          >
            <path
              d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"
            />
          </svg>
          <svg
            class="icon-close"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
            aria-hidden="true"
          >
            <path d="M18 6L6 18M6 6l12 12" />
          </svg>
        </div>
      </button>
      <div
        class="contact-panel"
        id="contactPanel"
        role="dialog"
        aria-label="راه‌های ارتباطی"
        aria-hidden="true"
      >
        <div class="panel-arrow"></div>
        <div class="panel-header">
          <h3>در تماس باشید</h3>
          <p>پاسخگویی ۲۴ ساعته</p>
        </div>
        <div class="panel-body">
          <a
            href="#"
            class="panel-item telegram-item"
            id="floatingTelegramBtn"
            data-app="telegram"
          >
            <div class="item-icon">
              <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path
                  d="M21.5 3L2.5 10L9.5 13.5L15.5 8.5L10.5 14.5L18.5 21L21.5 3Z"
                />
              </svg>
            </div>
            <div class="item-info">
              <span class="item-label">تلگرام</span
              ><span class="item-value">@MR_NEON_TEAM</span>
            </div>
            <span class="item-badge">پیشنهاد میشه</span>
          </a>
          <a href="mailto:contact@mrneon.dev" class="panel-item">
            <div class="item-icon">
              <svg
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                stroke-linecap="round"
                stroke-linejoin="round"
                aria-hidden="true"
              >
                <path
                  d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"
                />
                <polyline points="22,6 12,13 2,6" />
              </svg>
            </div>
            <div class="item-info">
              <span class="item-label">ایمیل</span
              ><span class="item-value">contact@mrneon.dev</span>
            </div>
          </a>
          <button
            type="button"
            class="panel-item copy-item"
            data-copy="@MR_NEON_TEAM"
          >
            <div class="item-icon">
              <svg
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                stroke-linecap="round"
                stroke-linejoin="round"
                aria-hidden="true"
              >
                <rect x="9" y="9" width="13" height="13" rx="2" ry="2" />
                <path
                  d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"
                />
              </svg>
            </div>
            <div class="item-info">
              <span class="item-label">کپی آیدی تلگرام</span>
            </div>
          </button>
          <button
            type="button"
            class="panel-item share-site-btn"
            id="shareSiteBtn"
          >
            <div class="item-icon">
              <svg
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                stroke-linecap="round"
                stroke-linejoin="round"
                aria-hidden="true"
              >
                <circle cx="18" cy="5" r="3" />
                <circle cx="6" cy="12" r="3" />
                <circle cx="18" cy="19" r="3" />
                <path d="M8.59 13.51l6.83 3.98M15.41 6.51l-6.82 3.98" />
              </svg>
            </div>
            <div class="item-info">
              <span class="item-label">اشتراک‌گذاری سایت</span>
            </div>
          </button>
        </div>
      </div>
    </div>

    <div class="tour-overlay" id="tourOverlay">
      <div class="tour-highlight" id="tourHighlight"></div>
      <div class="tour-tooltip" id="tourTooltip">
        <div class="tour-step-count" id="tourStepCount">۱ از ۴</div>
        <h3 class="tour-tooltip-title" id="tourTooltipTitle"></h3>
        <p class="tour-tooltip-text" id="tourTooltipText"></p>
        <div class="tour-tooltip-actions">
          <button
            type="button"
            class="btn btn-outline-light tour-btn-prev"
            id="tourPrevBtn"
          >
            قبلی
          </button>
          <button
            type="button"
            class="btn btn-neon tour-btn-next"
            id="tourNextBtn"
          >
            بعدی
          </button>
          <button type="button" class="tour-btn-skip" id="tourSkipBtn">
            رد کردن تور
          </button>
        </div>
      </div>
    </div>

    <?php include_once 'components/auth-modals.html'; ?>
    <?php include_once 'components/neo-modal.html'; ?>
    <?php include_once 'components/admin-modal.html'; ?>
    <?php include_once 'components/customer-workspace.html'; ?>

    <script src="js/app.js" type="module" defer></script>
  </body>
</html>