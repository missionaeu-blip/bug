<?php
/**
 * Sitemap XML : uniquement les pages explicitement indexables dans seo_pages().
 * Les comptes, formulaires, espaces projet, pages légales et pages techniques
 * ne peuvent donc jamais s'y glisser par erreur.
 */
require_once __DIR__ . '/includes/seo.php';

header('Content-Type: application/xml; charset=UTF-8');
header('X-Robots-Tag: noindex, follow'); // le fichier aide les robots, il n'est pas un résultat de recherche

$reglages = [
    'accueil'     => ['1.0', 'weekly'],
    'entreprises' => ['0.9', 'monthly'],
    'freelances'  => ['0.9', 'monthly'],
    'services'    => ['0.8', 'monthly'],
    'faq'         => ['0.7', 'monthly'],
    'a-propos'    => ['0.6', 'yearly'],
    'contact'     => ['0.5', 'yearly'],
];

$fichiers = [
    'accueil'     => 'index.php',
    'entreprises' => 'entreprises.php',
    'freelances'  => 'freelances.php',
    'services'    => 'services.php',
    'faq'         => 'faq.php',
    'a-propos'    => 'a-propos.php',
    'contact'     => 'contact.php',
];

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

foreach (seo_pages() as $cle => $page) {
    if (empty($page['indexable']) || empty($page['path'])) { continue; }
    [$prio, $freq] = $reglages[$cle] ?? ['0.5', 'monthly'];
    $f = __DIR__ . '/' . ($fichiers[$cle] ?? '');
    $date = is_file($f) ? date('Y-m-d', filemtime($f)) : date('Y-m-d');
    echo "  <url>\n";
    echo "    <loc>" . htmlspecialchars(seo_url($page['path']), ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</loc>\n";
    echo "    <lastmod>{$date}</lastmod>\n";
    echo "    <changefreq>{$freq}</changefreq>\n";
    echo "    <priority>{$prio}</priority>\n";
    echo "  </url>\n";
}

echo '</urlset>' . "\n";
