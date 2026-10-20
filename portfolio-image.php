<?php
/**
 * Image de portfolio servie après vérification du compte et du fichier.
 * Les vues ne pointent pas directement vers /uploads : cela résiste aux
 * restrictions d'hébergement sur ce dossier et évite d'exposer un chemin
 * de stockage arbitraire.
 */
require_once __DIR__ . '/includes/functions.php';

function portfolio_image_refus($code) {
    http_response_code($code);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    echo $code === 403 ? 'Accès refusé.' : 'Image introuvable.';
    exit;
}

if (!is_logged()) { portfolio_image_refus(403); }

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) { portfolio_image_refus(404); }

$st = db()->prepare('SELECT id, user_id, image FROM portfolio WHERE id=?');
$st->execute([$id]);
$portfolio = $st->fetch();
if (!$portfolio) { portfolio_image_refus(404); }

$visiteur = user();
$autorise = (($visiteur['role'] ?? '') === 'admin')
    || ((int) ($visiteur['id'] ?? 0) === (int) $portfolio['user_id']);
if (!$autorise) { portfolio_image_refus(403); }

// Le chemin venant de la base reste contrôlé : uniquement un fichier du
// sous-dossier portfolio, sans traversée de répertoire ni lien sortant.
$relatif = str_replace('\\', '/', (string) $portfolio['image']);
if (!preg_match('#^portfolio/[A-Za-z0-9][A-Za-z0-9._-]{0,220}$#', $relatif)
    || strpos($relatif, '..') !== false) {
    portfolio_image_refus(404);
}
$racine = realpath(__DIR__ . '/uploads/portfolio');
$fichier = $racine ? realpath($racine . DIRECTORY_SEPARATOR . substr($relatif, strlen('portfolio/'))) : false;
$prefixe = $racine ? rtrim($racine, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR : '';
if (!$racine || !$fichier || strncmp($fichier, $prefixe, strlen($prefixe)) !== 0
    || !is_file($fichier) || !is_readable($fichier)) {
    portfolio_image_refus(404);
}

// L'extension ne suffit pas : contrôle du contenu réel avant d'envoyer l'image.
$infos = @getimagesize($fichier);
$types = [
    IMAGETYPE_GIF  => 'image/gif',
    IMAGETYPE_JPEG => 'image/jpeg',
    IMAGETYPE_PNG  => 'image/png',
];
if (defined('IMAGETYPE_WEBP')) { $types[constant('IMAGETYPE_WEBP')] = 'image/webp'; }
$type = is_array($infos) ? (int) ($infos[2] ?? 0) : 0;
if (!isset($types[$type])) { portfolio_image_refus(404); }

header('Content-Type: ' . $types[$type]);
header('Content-Length: ' . (string) filesize($fichier));
header('Content-Disposition: inline');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=86400');
readfile($fichier);
exit;
