/**
 * ACSO Consulting - Cookie Consent (Google Consent Mode v2)
 *
 * Nothing loads before the visitor accepts. GA4 and Clarity are both injected
 * only on explicit Accept. Reject and "not yet decided" load neither.
 *
 * Consent stored in localStorage key: "acso_cookie_consent"
 * Values: "accepted" | "rejected" | undefined (not yet decided)
 */
(function () {
  'use strict';

  var GA_ID       = 'G-KLF533BDNB';
  var CLARITY_ID  = 'w9dnp3r79k';
  var STORAGE_KEY = 'acso_cookie_consent';

  window.dataLayer = window.dataLayer || [];
  function gtag() { window.dataLayer.push(arguments); }
  window.gtag = gtag;

  var storedConsent;
  try { storedConsent = localStorage.getItem(STORAGE_KEY); } catch (e) {}

  gtag('consent', 'default', {
    ad_storage: 'denied',
    ad_user_data: 'denied',
    ad_personalization: 'denied',
    analytics_storage: 'denied',
    functionality_storage: 'granted',
    personalization_storage: 'denied',
    security_storage: 'granted',
    wait_for_update: 500
  });
  // Kept because it only ever redacts ad identifiers, never enables them.
  // url_passthrough is deliberately absent: it writes gclid into URLs, which
  // is ad tracking by another route and is not covered by the banner.
  gtag('set', 'ads_data_redaction', true);

  function loadGA() {
    if (document.querySelector('script[src*="googletagmanager"]')) return;
    var s = document.createElement('script');
    s.async = true;
    s.src = 'https://www.googletagmanager.com/gtag/js?id=' + GA_ID;
    document.head.appendChild(s);
    gtag('js', new Date());
    gtag('config', GA_ID);
  }

  // Only analytics is ever granted. The banner asks for Google Analytics and
  // Clarity and nothing else, so the advertising signals stay denied for good.
  function grantConsent() {
    gtag('consent', 'update', { analytics_storage: 'granted' });
  }

  function loadClarity() {
    if (window.clarity) return;
    (function(c,l,a,r,i,t,y){
      c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};
      t=l.createElement(r);t.async=1;t.src="https://www.clarity.ms/tag/"+i;
      y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);
    })(window, document, "clarity", "script", CLARITY_ID);
  }

  if (storedConsent === 'accepted') {
    grantConsent();
    loadGA();
    loadClarity();
  }

  function setConsent(value) {
    try { localStorage.setItem(STORAGE_KEY, value); } catch (e) {}
    hideBanner();
    if (value === 'accepted') {
      grantConsent();
      loadGA();
      loadClarity();
    }
  }

  function hideBanner() {
    var b = document.getElementById('acso-cookie-banner');
    if (b) {
      b.style.opacity = '0';
      b.style.transform = 'translateY(16px)';
      setTimeout(function () { b.style.display = 'none'; }, 300);
    }
  }

  function injectBanner() {
    if (document.getElementById('acso-cookie-banner')) return;

    var style = document.createElement('style');
    style.textContent = [
      '#acso-cookie-banner {',
      '  position: fixed; bottom: 0; left: 0; right: 0; z-index: 99999;',
      '  display: flex; align-items: center; justify-content: space-between;',
      '  flex-wrap: wrap; gap: 12px; padding: 14px 24px;',
      // At 400% zoom the text reflows tall enough to bury the page, and a
      // fixed element cannot be scrolled past. Cap it and let it scroll.
      '  max-height: 45vh; overflow-y: auto;',
      '  background: #162234; color: #E8EDF4;',
      '  font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;',
      '  font-size: 0.875rem; line-height: 1.5;',
      '  border-top: 3px solid #3B82F6;',
      '  box-shadow: 0 -4px 24px rgba(0,0,0,0.35);',
      '  opacity: 1; transform: translateY(0);',
      '  transition: opacity 0.3s ease, transform 0.3s ease;',
      '}',
      '#acso-cookie-banner p { margin: 0; flex: 1 1 280px; color: #A8BBCF; }',
      '#acso-cookie-banner a { color: #60A5FA; text-decoration: underline; text-underline-offset: 2px; }',
      '#acso-cookie-banner a:hover { color: #93C5FD; }',
      '.acso-cookie-actions { display: flex; gap: 10px; flex-shrink: 0; flex-wrap: wrap; }',
      // Reject has to carry the same visual weight as Accept: same size, same
      // font weight, both solid. A ghost button next to a filled one is the
      // nudge the EDPB treats as invalidating the consent it collects.
      '.acso-btn-accept, .acso-btn-reject {',
      '  padding: 9px 22px; color: #fff; border: none; border-radius: 8px;',
      '  font-family: inherit; font-size: 0.875rem; font-weight: 600;',
      '  cursor: pointer; transition: background 0.15s;',
      '}',
      '.acso-btn-accept { background: #2563EB; }',
      '.acso-btn-accept:hover { background: #1D4ED8; }',
      '.acso-btn-reject { background: #475569; }',
      '.acso-btn-reject:hover { background: #3B4657; }',
      '.acso-btn-accept:focus-visible, .acso-btn-reject:focus-visible {',
      '  outline: 3px solid #93C5FD; outline-offset: 2px;',
      '}',
      '#acso-cookie-banner a:focus-visible { outline: 3px solid #93C5FD; outline-offset: 2px; }',
      '@media (max-width: 600px) {',
      '  #acso-cookie-banner { padding: 14px 16px; }',
      '  .acso-cookie-actions { width: 100%; }',
      '  .acso-btn-accept, .acso-btn-reject { flex: 1; text-align: center; }',
      '}'
    ].join('\n');
    document.head.appendChild(style);

    var I18N = {
      de: {
        dialog: 'Cookie-Einwilligung',
        body: 'Ohne Ihre Zustimmung werden keine Analyse-Cookies gesetzt und keine ' +
              'Nutzungsdaten an Dritte übermittelt. Klicken Sie auf <strong>Akzeptieren</strong>, ' +
              'um Google Analytics und Microsoft Clarity (Sitzungsaufzeichnung) zuzulassen. ' +
              'Wenn Sie ablehnen, wird nichts geladen. ' +
              'Einzelheiten in unserer <a href="/privacy.html">Datenschutzerklärung</a> und in unseren <a href="/terms.html">AGB</a>.',
        reject: 'Ablehnen',
        accept: 'Akzeptieren'
      },
      it: {
        dialog: 'Consenso ai cookie',
        body: 'Senza il Suo consenso non vengono installati cookie di analisi né trasmessi ' +
              'dati di utilizzo a terzi. Clicchi su <strong>Accetto</strong> per consentire ' +
              'Google Analytics e Microsoft Clarity (registrazione di sessione). ' +
              'Se rifiuta, non viene caricato nulla. ' +
              'Maggiori dettagli nell’<a href="/it/privacy.html">informativa privacy</a> e nelle <a href="/it/condizioni.html">condizioni</a>.',
        reject: 'Rifiuto',
        accept: 'Accetto'
      },
      en: {
        dialog: 'Cookie consent',
        body: 'Without your consent no analytics cookies are set and no usage data ' +
              'is passed to third parties. Click <strong>Accept</strong> to allow ' +
              'Google Analytics and Microsoft Clarity (session recording). ' +
              'If you reject, nothing is loaded. ' +
              'See our <a href="/en/privacy.html">privacy notice</a> and <a href="/en/terms.html">terms</a>.',
        reject: 'Reject',
        accept: 'Accept'
      }
    };
    var t = I18N[(document.documentElement.lang || 'de').slice(0, 2).toLowerCase()] || I18N.de;

    var banner = document.createElement('div');
    banner.id = 'acso-cookie-banner';
    // Not role="dialog": nothing is modal, focus is not trapped and the page
    // stays usable, so announcing a dialog tells a screen reader user to expect
    // behaviour that is not there.
    banner.setAttribute('role', 'region');
    banner.setAttribute('aria-label', t.dialog);
    // No aria-label on the buttons. The visible word has to be contained in the
    // accessible name (SC 2.5.3), and "Accetto" is not inside "Accetta i
    // cookie", so voice control could not activate the Italian buttons.
    banner.innerHTML = [
      '<p>', t.body, '</p>',
      '<div class="acso-cookie-actions">',
      '  <button type="button" class="acso-btn-reject" id="acso-cookie-reject">' + t.reject + '</button>',
      '  <button type="button" class="acso-btn-accept" id="acso-cookie-accept">' + t.accept + '</button>',
      '</div>'
    ].join('');

    document.body.appendChild(banner);

    document.getElementById('acso-cookie-accept').addEventListener('click', function () {
      setConsent('accepted');
    });
    document.getElementById('acso-cookie-reject').addEventListener('click', function () {
      setConsent('rejected');
    });
  }

  function denyConsent() {
    gtag('consent', 'update', { analytics_storage: 'denied' });
    if (window.clarity) { try { window.clarity('stop'); } catch (e) {} }
  }

  // _ga is scoped to the registrable domain, _clck/_clsk to the host, so an
  // expiry has to be replayed across every domain variant to actually bite.
  function deleteAnalyticsCookies() {
    var host = location.hostname;
    var bare = host.replace(/^www\./, '');
    var domains = ['', host, '.' + host];
    if (bare !== host) { domains.push(bare, '.' + bare); }

    document.cookie.split(';').forEach(function (raw) {
      var name = raw.split('=')[0].trim();
      if (!/^(_ga|_gid|_gat|_clck|_clsk|CLID|MUID|ANONCHK|SM)/.test(name)) return;
      domains.forEach(function (d) {
        document.cookie = name + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/' +
          (d ? '; domain=' + d : '');
      });
    });

    try {
      Object.keys(localStorage).forEach(function (k) {
        if (/^(_cl|clarity)/i.test(k)) { localStorage.removeItem(k); }
      });
    } catch (e) {}
  }

  window.acsoCookieReset = function () {
    try { localStorage.removeItem(STORAGE_KEY); } catch (e) {}
    denyConsent();
    deleteAnalyticsCookies();
    location.reload();
  };

  if (!storedConsent) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', injectBanner);
    } else {
      injectBanner();
    }
  }
})();
