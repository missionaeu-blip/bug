<?php
/**
 * Endpoint Stripe Connect — webhooks signés, idempotents, TEST seulement.
 * Stripe peut rejouer les événements ; aucun état de paiement ne dépend du
 * retour navigateur du Payment Element.
 */
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

function stripe_webhook_repondre($code, $message) {
    http_response_code($code);
    echo json_encode(['ok' => $code >= 200 && $code < 300, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    stripe_webhook_repondre(405, 'Méthode non autorisée.');
}
if (!stripe_actif() || !defined('STRIPE_WEBHOOK_SECRET') || STRIPE_WEBHOOK_SECRET === '') {
    // Ne jamais accepter un événement non authentifiable lorsque le module est inactif.
    stripe_webhook_repondre(503, 'Webhook Stripe de test non configuré.');
}

$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
if (!is_string($payload) || !connect_webhook_valide($payload, $signature, STRIPE_WEBHOOK_SECRET)) {
    stripe_webhook_repondre(400, 'Signature Stripe invalide.');
}
$event = json_decode($payload, true);
if (!is_array($event) || !preg_match('/^evt_[A-Za-z0-9]+$/', (string) ($event['id'] ?? ''))
    || empty($event['type']) || !is_array($event['data']['object'] ?? null)) {
    stripe_webhook_repondre(400, 'Événement Stripe invalide.');
}

$event_id = (string) $event['id'];
$event_type = (string) $event['type'];
$compte = (string) ($event['account'] ?? '');
$objet = $event['data']['object'];

// Un événement déjà finalisé est sans effet. La vérification avant traitement
// est seulement une optimisation ; les opérations de paiement sont aussi
// atomiques et sûres en cas de livraison simultanée.
$vu = db()->prepare("SELECT 1 FROM stripe_evenements WHERE event_id=?");
$vu->execute([$event_id]);
if ($vu->fetchColumn()) { stripe_webhook_repondre(200, 'Événement déjà traité.'); }

try {
    if ($event_type === 'account.updated') {
        connect_synchroniser_evenement_compte($objet);
    } elseif ($event_type === 'payment_intent.succeeded') {
        $paiement_id = (int) ($objet['metadata']['missiona_paiement_id'] ?? 0);
        // Les événements Connect portent l'identifiant du compte connecté au
        // niveau racine. Sans cet en-tête, on refuse de marquer un paiement.
        if ($paiement_id <= 0 || !connect_compte_valide($compte)) {
            throw new RuntimeException('Événement PaymentIntent sans compte Connect ou paiement Missiona.');
        }
        $resultat = stripe_marquer_paiement_direct($paiement_id, $objet, $compte);
        if (!$resultat['ok']) { throw new RuntimeException($resultat['erreur']); }
    }
    // payment_intent.processing, payment_intent.payment_failed et
    // charge.refunded sont journalisés afin que Stripe arrête de les renvoyer ;
    // aucun paiement n'est validé par ces événements.

    db()->prepare("INSERT OR IGNORE INTO stripe_evenements (event_id,type,compte) VALUES (?,?,?)")
        ->execute([$event_id, substr($event_type, 0, 120), substr($compte, 0, 80)]);
    stripe_webhook_repondre(200, 'Événement traité.');
} catch (Throwable $e) {
    @error_log('[stripe-webhook] ' . $event_id . ' : ' . $e->getMessage());
    // 500 invite Stripe à rejouer plus tard, plutôt que de perdre un paiement.
    stripe_webhook_repondre(500, 'Traitement temporairement indisponible.');
}
