<footer class="footer">
  <div class="container">
    <div class="footer-grid">
      <div>
        <a href="/" class="logo mb-2"><img class="logo-mark" src="<?= asset('assets/img/mark.svg') ?>" alt="" width="32" height="32"> Missiona</a>
        <p class="small" style="max-width:300px">Vous décrivez le projet, on livre le résultat. Notre équipe sélectionne les experts et pilote l'exécution de vos projets par des freelances qualifiés.</p>
      </div>
      <div>
        <p class="footer-title">Plateforme</p>
        <ul>
          <li><a href="/entreprises">Pour les entreprises</a></li>
          <li><a href="/freelances">Pour les freelances</a></li>
          <li><a href="/services">Nos expertises</a></li>
          <li><a href="/a-propos">Qui sommes-nous</a></li>
          <li><a href="/contact">Nous contacter</a></li>
          <li><a href="/a-propos">Notre méthode de sélection</a></li>
          <?php if (!beta_lien_masque('/tarifs')): ?><li><a href="/tarifs">Tarifs et devis</a></li><?php endif; ?>
        </ul>
      </div>
      <div>
        <p class="footer-title">Ressources</p>
        <ul>
          <li><a href="/faq">Questions fréquentes</a></li>
          <li><a href="/inscription">Créer un compte</a></li>
          <li><a href="/connexion">Se connecter</a></li>
        </ul>
      </div>
      <div>
        <p class="footer-title">Légal</p>
        <ul>
          <li><a href="/mentions-legales">Mentions légales</a></li>
          <li><a href="/cgu">Conditions d’utilisation</a></li>
          <li><a href="/cgv">Conditions de vente B2B</a></li>
          <li><a href="/conditions-beta">Conditions de la bêta</a></li>
          <li><a href="/retractation">Rétractation et professionnels</a></li>
        </ul>
        <p class="footer-title footer-title-sub">Données et confiance</p>
        <ul>
          <li><a href="/politique-confidentialite">Politique de confidentialité</a></li>
          <li><a href="/politique-cookies">Politique cookies</a></li>
          <li><a href="/dpa">Accord de sous-traitance</a></li>
          <li><a href="/securite">Sécurité</a></li>
          <li><a href="/moderation-signalement">Modération et signalement</a></li>
          <li><a href="/accessibilite">Accessibilité</a></li>
          <li><a href="/contact-donnees-personnelles">Contacts légal et données</a></li>
          <li><button class="footer-cookie-link" type="button" data-open-cookie-settings>Gérer les cookies</button></li>
        </ul>
      </div>
    </div>

    <div class="footer-translate" aria-label="Traduction du site">
      <div>
        <strong>Traduire le site</strong>
        <span>Choisissez une langue européenne. La traduction automatique s'ouvre avec Google Translate.</span>
      </div>
      <label class="sr-only" for="site-language">Choisir la langue du site</label>
      <select id="site-language" name="site-language">
        <option value="" selected>Choisir une langue</option>
        <optgroup label="Langues de l'Union européenne">
          <option value="bg">Български — Bulgare</option>
          <option value="hr">Hrvatski — Croate</option>
          <option value="cs">Čeština — Tchèque</option>
          <option value="da">Dansk — Danois</option>
          <option value="nl">Nederlands — Néerlandais</option>
          <option value="en">English — Anglais</option>
          <option value="et">Eesti — Estonien</option>
          <option value="fi">Suomi — Finnois</option>
          <option value="fr">Français — Original</option>
          <option value="de">Deutsch — Allemand</option>
          <option value="el">Ελληνικά — Grec</option>
          <option value="hu">Magyar — Hongrois</option>
          <option value="ga">Gaeilge — Irlandais</option>
          <option value="it">Italiano — Italien</option>
          <option value="lv">Latviešu — Letton</option>
          <option value="lt">Lietuvių — Lituanien</option>
          <option value="mt">Malti — Maltais</option>
          <option value="pl">Polski — Polonais</option>
          <option value="pt">Português — Portugais</option>
          <option value="ro">Română — Roumain</option>
          <option value="sk">Slovenčina — Slovaque</option>
          <option value="sl">Slovenščina — Slovène</option>
          <option value="es">Español — Espagnol</option>
          <option value="sv">Svenska — Suédois</option>
        </optgroup>
        <optgroup label="Autres langues européennes">
          <option value="sq">Shqip — Albanais</option>
          <option value="hy">Հայերեն — Arménien</option>
          <option value="az">Azərbaycanca — Azéri</option>
          <option value="eu">Euskara — Basque</option>
          <option value="be">Беларуская — Biélorusse</option>
          <option value="bs">Bosanski — Bosnien</option>
          <option value="ca">Català — Catalan</option>
          <option value="fo">Føroyskt — Féroïen</option>
          <option value="gl">Galego — Galicien</option>
          <option value="cy">Cymraeg — Gallois</option>
          <option value="ka">ქართული — Géorgien</option>
          <option value="is">Íslenska — Islandais</option>
          <option value="lb">Lëtzebuergesch — Luxembourgeois</option>
          <option value="mk">Македонски — Macédonien</option>
          <option value="no">Norsk — Norvégien</option>
          <option value="ru">Русский — Russe</option>
          <option value="sr">Српски — Serbe</option>
          <option value="tr">Türkçe — Turc</option>
          <option value="uk">Українська — Ukrainien</option>
        </optgroup>
      </select>
    </div>

    <div class="footer-bottom">
      <span>© <?= date('Y') ?> Missiona — Tous droits réservés.</span>
      <span>Conçu en France 🇫🇷</span>
    </div>
  </div>
</footer>

<!-- Aucun traceur optionnel n’est chargé avant un choix. Le gestionnaire
     permet d’accepter, refuser, personnaliser puis modifier ce choix. -->
<div id="cookie-consent" class="cookie-consent" hidden
     data-endpoint="<?= e(u('/consentement-cookies')) ?>"
     data-csrf="<?= e(csrf_token()) ?>"
     data-policy-version="1.0-beta">
  <section class="cookie-banner" role="dialog" aria-modal="true" aria-labelledby="cookie-title" aria-describedby="cookie-text">
    <div class="cookie-copy">
      <h2 id="cookie-title">Vos choix de confidentialité</h2>
      <p id="cookie-text">Missiona utilise le cookie de session nécessaire au fonctionnement et une mesure d’audience interne, sans traceur publicitaire ni tiers. Aucun cookie optionnel n’est activé à ce jour. Vous pouvez enregistrer ou modifier vos préférences à tout moment.</p>
      <p><a href="/politique-cookies">Lire la politique cookies</a> · <a href="/politique-confidentialite">Politique de confidentialité</a></p>
    </div>
    <div class="cookie-actions" aria-label="Choix des cookies">
      <button type="button" class="btn btn-primary" data-cookie-action="accept">Tout accepter</button>
      <button type="button" class="btn btn-primary" data-cookie-action="refuse">Tout refuser</button>
      <button type="button" class="btn btn-ghost" data-cookie-action="customize">Personnaliser</button>
    </div>
  </section>
  <section class="cookie-settings" hidden role="dialog" aria-modal="true" aria-labelledby="cookie-settings-title">
    <h2 id="cookie-settings-title">Personnaliser vos choix</h2>
    <p>Les cookies nécessaires à la sécurité et à la connexion restent actifs. Aucune catégorie optionnelle n’est active à ce jour : l’enregistrement ci-dessous conserve votre choix pour cette version de la politique.</p>
    <label class="cookie-choice"><input type="checkbox" checked disabled> <span><strong>Nécessaires</strong><small>Cookie de session <code>sid</code>, sécurité et formulaires.</small></span></label>
    <div class="cookie-choice" role="note"><span><strong>Mesure d’audience interne</strong><small>Elle est réalisée côté serveur, sans cookie publicitaire ni traceur tiers. Son régime d’exemption éventuel doit être maintenu et validé au regard des critères CNIL.</small></span></div>
    <div class="cookie-choice" role="note"><span><strong>Catégories optionnelles</strong><small>Aucun outil fonctionnel, marketing ou de mesure optionnelle n’est actif. Tout ajout sera bloqué tant que votre consentement n’aura pas été recueilli.</small></span></div>
    <div class="cookie-actions"><button type="button" class="btn btn-primary" data-cookie-action="save">Enregistrer mes choix</button><button type="button" class="btn btn-ghost" data-cookie-action="close">Annuler</button></div>
  </section>
</div>

<script src="<?= asset('assets/js/app.js') ?>" defer></script>
<script src="<?= asset('assets/js/consent.js') ?>" defer></script>
<script src="<?= asset('assets/js/translation.js') ?>" defer></script>
</body>
</html>
