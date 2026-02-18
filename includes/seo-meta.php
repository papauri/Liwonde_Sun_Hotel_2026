<?php
/**
 * SEO Meta Tags Component
 * Provides comprehensive SEO meta tags and structured data
 * 
 * Usage:
 * $seo_data = [
 *     'title' => 'Page Title',
 *     'description' => 'Page description',
 *     'image' => '/path/to/image.jpg',
 *     'type' => 'website', // or 'article', 'hotel', 'event', etc.
 *     'published_time' => '2024-01-01', // for articles/events
 *     'modified_time' => '2024-01-02',
 *     'author' => 'Author Name',
 *     'section' => 'Section Name',
 *     'tags' => 'tag1, tag2, tag3',
 *     'canonical' => 'https://example.com/page',
 *     'noindex' => false,
 *     'structured_data' => [...] // JSON-LD structured data
 * ];
 * 
 * require_once 'includes/seo-meta.php';
 */

// Get site settings from database
$site_name = getSetting('site_name');
$site_tagline = getSetting('site_tagline');
$site_logo = getSetting('site_logo');
$site_url = getSetting('site_url');
$theme_color = getSetting('theme_color') ?: '#0A1929';
$default_keywords = getSetting('default_keywords');

// Use database URL if available, otherwise construct from host
$base_url = $site_url ?: 'https://' . $_SERVER['HTTP_HOST'];

// Default SEO data
$seo_default = [
    'title' => $site_name,
    'description' => $site_tagline,
    'image' => $site_logo,
    'type' => 'website',
    'published_time' => null,
    'modified_time' => null,
    'author' => $site_name,
    'section' => null,
    'tags' => null,
    'canonical' => null,
    'noindex' => false,
    'structured_data' => null,
    'breadcrumbs' => null
];

// Merge with provided SEO data
$seo = array_merge($seo_default, $seo_data ?? []);

// Build full page title
$page_title = $seo['title'] === $site_name 
    ? $site_name . ' - ' . $site_tagline
    : $seo['title'] . ' | ' . $site_name;

// Build absolute image URL
$seo_image = strpos($seo['image'], 'http') === 0 
    ? $seo['image'] 
    : $base_url . $seo['image'];

// Build canonical URL
$canonical_url = $seo['canonical'] ?: $base_url . $_SERVER['REQUEST_URI'];

// Get current page path for robots
$current_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$disallowed_paths = ['/admin/', '/private/', '/tmp/', '/cache/', '/sessions/', '/logs/', '/invoices/'];
$should_noindex = $seo['noindex'] || strpos($current_path, '/admin/') === 0;
foreach ($disallowed_paths as $path) {
    if (strpos($current_path, $path) === 0) {
        $should_noindex = true;
        break;
    }
}
?>

<!-- Primary Meta Tags -->
<title><?php echo htmlspecialchars($page_title); ?></title>
<meta name="title" content="<?php echo htmlspecialchars($page_title); ?>">
<meta name="description" content="<?php echo htmlspecialchars($seo['description']); ?>">
<meta name="keywords" content="<?php echo htmlspecialchars($seo['tags'] ?: $default_keywords); ?>">
<meta name="author" content="<?php echo htmlspecialchars($seo['author']); ?>">

<?php if ($should_noindex): ?>
<meta name="robots" content="noindex, nofollow">
<?php else: ?>
<meta name="robots" content="index, follow">
<?php endif; ?>

<link rel="canonical" href="<?php echo htmlspecialchars($canonical_url); ?>">

<!-- Open Graph / Facebook -->
<meta property="og:type" content="<?php echo htmlspecialchars($seo['type']); ?>">
<meta property="og:url" content="<?php echo htmlspecialchars($canonical_url); ?>">
<meta property="og:title" content="<?php echo htmlspecialchars($page_title); ?>">
<meta property="og:description" content="<?php echo htmlspecialchars($seo['description']); ?>">
<meta property="og:image" content="<?php echo htmlspecialchars($seo_image); ?>">
<meta property="og:site_name" content="<?php echo htmlspecialchars($site_name); ?>">
<?php if ($seo['published_time']): ?>
<meta property="article:published_time" content="<?php echo htmlspecialchars($seo['published_time']); ?>">
<?php endif; ?>
<?php if ($seo['modified_time']): ?>
<meta property="article:modified_time" content="<?php echo htmlspecialchars($seo['modified_time']); ?>">
<?php endif; ?>
<?php if ($seo['section']): ?>
<meta property="article:section" content="<?php echo htmlspecialchars($seo['section']); ?>">
<?php endif; ?>
<?php if ($seo['tags']): ?>
<meta property="article:tag" content="<?php echo htmlspecialchars($seo['tags']); ?>">
<?php endif; ?>

<!-- Twitter -->
<meta property="twitter:card" content="summary_large_image">
<meta property="twitter:url" content="<?php echo htmlspecialchars($canonical_url); ?>">
<meta property="twitter:title" content="<?php echo htmlspecialchars($page_title); ?>">
<meta property="twitter:description" content="<?php echo htmlspecialchars($seo['description']); ?>">
<meta property="twitter:image" content="<?php echo htmlspecialchars($seo_image); ?>">

<!-- Additional SEO Meta Tags -->
<meta name="theme-color" content="<?php echo htmlspecialchars($theme_color); ?>">
<meta name="msapplication-TileColor" content="<?php echo htmlspecialchars($theme_color); ?>">
<link rel="shortcut icon" href="/favicon.ico" type="image/x-icon">
<link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
<link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">

<?php
// Structured Data (JSON-LD)
if (!empty($seo['structured_data'])):
    if (is_array($seo['structured_data'])):
        $json_ld = json_encode($seo['structured_data'], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
?>
<script type="application/ld+json">
<?php echo $json_ld; ?>
</script>
<?php 
    endif;
endif;

// Breadcrumb Schema
if (!empty($seo['breadcrumbs'])):
?>
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "BreadcrumbList",
  "itemListElement": [
    <?php
    $breadcrumb_items = [];
    $position = 1;
    foreach ($seo['breadcrumbs'] as $crumb):
        $breadcrumb_items[] = sprintf(
            '{
                "@type": "ListItem",
                "position": %d,
                "name": "%s",
                "item": "%s"
            }',
            $position++,
            addslashes($crumb['name']),
            addslashes($crumb['url'])
        );
    endforeach;
    echo implode(",\n    ", $breadcrumb_items);
    ?>
  ]
}
</script>
<?php endif; ?>