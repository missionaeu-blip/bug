<?php
/**
 * Missiona — Envoi d'e-mails transactionnels (PHP pur, sans dépendance)
 * ------------------------------------------------------------------
 * Priorité au SMTP (recommandé : le compte e-mail de votre
 * hébergement, ex. Hostinger). Sans configuration SMTP, repli sur
 * la fonction mail() de PHP.
 *
 * Réglages dans « config.local.php » :
 *
 *     define('SMTP_HOST', 'smtp.hostinger.com');
 *     define('SMTP_PORT', 465);        // 465 = SSL, 587 = STARTTLS
 *     define('SMTP_USER', 'contact@votre-domaine');
 *     define('SMTP_PASS', 'mot de passe de la boîte mail');
 *
 * L'expéditeur affiché est SITE_EMAIL (config.php).
 */

/** La configuration SMTP est-elle complète ? */
function email_smtp_actif() {
    return defined('SMTP_HOST') && SMTP_HOST !== ''
        && defined('SMTP_USER') && SMTP_USER !== ''
        && defined('SMTP_PASS') && SMTP_PASS !== '';
}

/**
 * Envoie un e-mail. Renvoie ['ok'=>bool, 'erreur'=>string].
 * N'échoue jamais bruyamment : un e-mail raté ne doit pas casser
 * l'action qui le déclenche ; l'erreur est journalisée.
 */
function email_envoyer($destinataire, $sujet, $html, $texte = '') {
    $destinataire = trim((string) $destinataire);
    if ($destinataire === '' || !filter_var($destinataire, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'erreur' => "Adresse destinataire invalide."];
    }

    $de   = defined('SITE_EMAIL') && SITE_EMAIL !== '' ? SITE_EMAIL : 'entreprise@missiona.eu';
    $nom  = defined('SITE_NOM') ? SITE_NOM : 'Missiona';

    if (email_smtp_actif()) {
        $r = email_smtp_envoyer($destinataire, $de, $nom, $sujet, $html, $texte);
    } else {
        $r = email_mail_envoyer($destinataire, $de, $nom, $sujet, $html, $texte);
    }

    if (!$r['ok']) {
        @error_log('[email] échec vers ' . $destinataire . ' : ' . $r['erreur']);
    }
    return $r;
}

/* ------------------------------------------------------------------ */

/** Construit les en-têtes et le corps MIME (HTML + texte de repli). */
function email_construire($de, $nom, $sujet, $html, $texte) {
    $limite = '----=_Missiona_' . bin2hex(random_bytes(8));
    $headers  = "From: " . sprintf('=?UTF-8?B?%s?= <%s>', base64_encode($nom), $de) . "\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/alternative; boundary=\"" . $limite . "\"\r\n";

    if ($texte === '') {
        $texte = trim(html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8'));
    }

    $corps  = "--" . $limite . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $texte . "\r\n\r\n";
    $corps .= "--" . $limite . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $html . "\r\n\r\n";
    $corps .= "--" . $limite . "--\r\n";

    // Sujet encodé en UTF-8
    $sujet = sprintf('=?UTF-8?B?%s?=', base64_encode($sujet));
    return [$headers, $corps, $sujet];
}

/** Repli : fonction mail() de PHP (suffisant chez beaucoup d'hébergeurs). */
function email_mail_envoyer($a, $de, $nom, $sujet, $html, $texte) {
    [$headers, $corps, $suj] = email_construire($de, $nom, $sujet, $html, $texte);
    $headers .= "Return-Path: <" . $de . ">\r\n";
    $ok = @mail($a, $suj, $corps, $headers, '-f' . $de);
    return $ok
        ? ['ok' => true, 'erreur' => '']
        : ['ok' => false, 'erreur' => "La fonction mail() de l'hébergement a refusé l'envoi."];
}

/* ------------------------- Client SMTP ----------------------------- */

function email_smtp_envoyer($a, $de, $nom, $sujet, $html, $texte) {
    $host = SMTP_HOST;
    $port = defined('SMTP_PORT') ? (int) SMTP_PORT : 465;
    $ssl  = ($port === 465);

    $sock = @stream_socket_client(
        ($ssl ? 'ssl://' : 'tcp://') . $host . ':' . $port,
        $errno, $errstr, 15
    );
    if (!$sock) {
        return ['ok' => false, 'erreur' => "Connexion SMTP impossible : $errstr ($errno)"];
    }
    stream_set_timeout($sock, 15);

    try {
        email_smtp_attente($sock, [220]);
        email_smtp_commande($sock, 'EHLO ' . (gethostname() ?: 'missiona.eu'), [250]);

        if (!$ssl) {
            email_smtp_commande($sock, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT)) {
                throw new RuntimeException('Chiffrement TLS refusé.');
            }
            email_smtp_commande($sock, 'EHLO ' . (gethostname() ?: 'missiona.eu'), [250]);
        }

        email_smtp_commande($sock, 'AUTH LOGIN', [334]);
        email_smtp_commande($sock, base64_encode(SMTP_USER), [334]);
        email_smtp_commande($sock, base64_encode(SMTP_PASS), [235]);

        email_smtp_commande($sock, 'MAIL FROM:<' . $de . '>', [250]);
        email_smtp_commande($sock, 'RCPT TO:<' . $a . '>', [250, 251]);
        email_smtp_commande($sock, 'DATA', [354]);

        [$headers, $corps, $suj] = email_construire($de, $nom, $sujet, $html, $texte);
        $donnees  = "To: <" . $a . ">\r\n";
        $donnees .= "Subject: " . $suj . "\r\n";
        $donnees .= "Date: " . date('r') . "\r\n";
        $donnees .= "Message-ID: <" . bin2hex(random_bytes(12)) . "@missiona>\r\n";
        $donnees .= $headers . "\r\n";
        $donnees .= $corps;
        // Transparence : une ligne commençant par un point doit être doublée
        $donnees = preg_replace('/^\./m', '..', $donnees);

        email_smtp_commande($sock, $donnees . "\r\n.", [250]);
        email_smtp_commande($sock, 'QUIT', [221]);
        fclose($sock);
        return ['ok' => true, 'erreur' => ''];
    } catch (Throwable $e) {
        @fclose($sock);
        return ['ok' => false, 'erreur' => $e->getMessage()];
    }
}

/** Lit la réponse du serveur (gère les lignes multiples « 250-… »). */
function email_smtp_attente($sock, array $codes_attendus) {
    $reponse = '';
    while (($ligne = fgets($sock, 1024)) !== false) {
        $reponse .= $ligne;
        if (!isset($ligne[3]) || $ligne[3] !== '-') { break; }
    }
    $code = (int) substr($reponse, 0, 3);
    if (!in_array($code, $codes_attendus, true)) {
        throw new RuntimeException('Réponse SMTP inattendue : ' . trim($reponse));
    }
    return $reponse;
}

function email_smtp_commande($sock, $cmd, array $codes_attendus) {
    fwrite($sock, $cmd . "\r\n");
    return email_smtp_attente($sock, $codes_attendus);
}

/* ------------------------------------------------------------------ */
/*  Gabarits d'e-mails transactionnels                                  */
/* ------------------------------------------------------------------ */

/** URL absolue (les liens d'e-mail doivent être complets). */
function email_url($chemin) {
    $base = defined('SITE_URL') ? rtrim(SITE_URL, '/') : 'https://missiona.eu';
    return $base . '/' . ltrim($chemin, '/');
}

/** Gabarit HTML commun (en-tête + pied de page). */
function email_gabarit($titre, $contenu_html) {
    $nom   = defined('SITE_NOM') ? SITE_NOM : 'Missiona';
    $url   = defined('SITE_URL') ? rtrim(SITE_URL, '/') : '';
    $annee = date('Y');
    return <<<HTML
<!DOCTYPE html>
<html lang="fr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f2f4f8;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f2f4f8;padding:24px 0;">
    <tr><td align="center">
      <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:10px;overflow:hidden;">
        <tr><td style="background:#0b1c3d;padding:18px 28px;">
          <span style="color:#ffffff;font-size:20px;font-weight:bold;">{$nom}</span>
        </td></tr>
        <tr><td style="padding:28px;">
          <h1 style="font-size:20px;margin:0 0 14px;">{$titre}</h1>
          {$contenu_html}
        </td></tr>
        <tr><td style="background:#f2f4f8;padding:16px 28px;font-size:12px;color:#6b7280;">
          Ce message concerne la sécurité ou le suivi de votre compte / projet&nbsp;:
          il ne contient aucune communication commerciale. Questions&nbsp;: entreprise@missiona.eu.<br>
          © {$annee} {$nom} — {$url}
        </td></tr>
      </table>
    </td></tr>
  </table>
</body></html>
HTML;
}

/** E-mail de bienvenue / confirmation d'inscription. */
function email_bienvenue($email, $prenom, $role) {
    $libelle_role = ['entreprise' => 'entreprise cliente', 'particulier' => 'particulier',
                     'freelance' => 'expert indépendant'][$role] ?? 'membre';
    $sujet = "Bienvenue sur Missiona — votre compte est créé";
    $html  = email_gabarit("Votre compte Missiona est créé",
        "<p>Bonjour " . e($prenom) . ",</p>"
      . "<p>Votre compte <strong>" . e($libelle_role) . "</strong> a bien été créé sur Missiona. "
      . "Notre équipe vous recontacte sous 48 h pour faire connaissance et comprendre votre besoin.</p>"
      . "<p>Vous pouvez dès à présent vous connecter :</p>"
      . '<p style="margin:20px 0;"><a href="' . e(email_url('/connexion')) . '" style="display:inline-block;background:#0b1c3d;color:#ffffff;padding:12px 22px;border-radius:8px;text-decoration:none;font-weight:bold;">Se connecter</a></p>'
      . "<p style=\"color:#6b7280;font-size:13px;\">Vous recevez cet e-mail parce que vous avez créé un compte sur Missiona.</p>");
    return email_envoyer($email, $sujet, $html);
}

/** E-mail à code court : uniquement pour prouver la maîtrise de l'adresse. */
function email_code_verification($email, $prenom, $code) {
    $prenom = trim((string) $prenom) ?: 'Bonjour';
    $code   = preg_replace('/\D/', '', (string) $code);
    $sujet  = "Votre code de vérification Missiona";
    $html   = email_gabarit("Vérifiez votre adresse e-mail",
        "<p>Bonjour " . e($prenom) . ",</p>"
      . "<p>Saisissez ce code pour finaliser votre inscription :</p>"
      . '<p style="margin:24px 0;text-align:center;letter-spacing:7px;font-size:28px;font-weight:bold;color:#0b1c3d;">' . e($code) . '</p>'
      . "<p>Ce code expire dans 15 minutes. Il n'autorise aucun e-mail commercial&nbsp;: il sert uniquement à sécuriser votre compte et le suivi de votre projet.</p>"
      . "<p style=\"color:#6b7280;font-size:13px;\">Si vous n'êtes pas à l'origine de cette inscription, vous pouvez ignorer ce message.</p>");
    return email_envoyer($email, $sujet, $html, "Code Missiona : {$code}. Il expire dans 15 minutes.");
}

/** Notification strictement opérationnelle liée à l'avancement d'un projet. */
function email_suivi_projet($email, $prenom, $projet, $statut, $lien = '') {
    $prenom = trim((string) $prenom) ?: 'Bonjour';
    $projet = trim((string) $projet);
    $statut = trim((string) $statut);
    $sujet  = "Suivi de votre projet — " . ($projet ?: 'Missiona');
    $bouton = $lien !== ''
        ? '<p style="margin:20px 0;"><a href="' . e($lien) . '" style="display:inline-block;background:#0b1c3d;color:#ffffff;padding:12px 22px;border-radius:8px;text-decoration:none;font-weight:bold;">Voir mon projet</a></p>'
        : '';
    $html = email_gabarit("Votre projet avance",
        "<p>Bonjour " . e($prenom) . ",</p>"
      . "<p>Le statut de votre projet <strong>" . e($projet) . "</strong> est&nbsp;: <strong>" . e($statut) . "</strong>.</p>"
      . $bouton
      . "<p style=\"color:#6b7280;font-size:13px;\">Ceci est un e-mail de suivi nécessaire au traitement de votre projet, et non un e-mail commercial.</p>");
    return email_envoyer($email, $sujet, $html);
}

/** E-mail du récapitulatif annuel DAC7 communiqué à un expert. */
function email_recap_dac7($u, $annee) {
    $sujet = "Votre récapitulatif fiscal " . (int) $annee . " (DAC7) est disponible";
    $html  = email_gabarit("Récapitulatif fiscal " . (int) $annee,
        "<p>Bonjour,</p>"
      . "<p>Conformément à la directive DAC7, Missiona a communiqué à l'administration fiscale les informations "
      . "relatives aux revenus que vous avez perçus via la plateforme pour l'année <strong>" . (int) $annee . "</strong>.</p>"
      . "<p>Votre récapitulatif individuel est consultable depuis votre espace, rubrique «&nbsp;Fiscalité&nbsp;» :</p>"
      . '<p style="margin:20px 0;"><a href="' . e(email_url('/profil?onglet=fiscal')) . '" style="display:inline-block;background:#0b1c3d;color:#ffffff;padding:12px 22px;border-radius:8px;text-decoration:none;font-weight:bold;">Consulter mon récapitulatif</a></p>'
      . "<p style=\"color:#6b7280;font-size:13px;\">Ce document est une mention d'information : il ne constitue ni un bilan fiscal, "
      . "ni une déclaration établie en votre nom. Votre déclaration de revenus reste à votre charge.</p>");
    return email_envoyer($u['email'], $sujet, $html);
}

/** E-mail de notification générique (événements importants du compte). */
function email_notification($email, $titre, $corps_html) {
    return email_envoyer($email, $titre . " — Missiona", email_gabarit($titre, $corps_html));
}
