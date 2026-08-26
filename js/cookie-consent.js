/**
 * ACSO Consulting — Cookie Consent (Google Consent Mode v2)
 *
 * GA4 loads with consent defaults = "denied" → anonymized cookieless pings
 * under legitimate interest. On Accept, consent is upgraded → full tracking.
 * Clarity (session replay) is gated behind explicit Accept.
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
  gtag('set', 'ads_data_redaction', true);
  gtag('set', 'url_passthrough', true);

  function loadGA() {
    if (document.querySelector('script[src*="googletagmanager"]')) return;
    var s = document.createElement('script');
    s.async = true;
    s.src = 'https://www.googletagmanager.com/gtag/js?id=' + GA_ID;
    document.head.appendChild(s);
    gtag('js', new Date());
    gtag('config', GA_ID, { anonymize_ip: true });
  }
  loadGA();

  function grantConsent() {
    gtag('consent', 'update', {
      ad_storage: 'granted',
      ad_user_data: 'granted',
      ad_personalization: 'granted',
      analytics_storage: 'granted',
      personalization_storage: 'granted'
    });
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
    loadClarity();
  }

  function setConsent(value) {
    try { localStorage.setItem(STORAGE_KEY, value); } catch (e) {}
    hideBanner();
    if (value === 'accepted') {
      grantConsent();
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
      '  background: #162234; color: #E8EDF4;',
      '  font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;',
      '  font-size: 0.875rem; line-height: 1.5;',
      '  border-top: 3px solid #3B82F6;',
      '  box-shadow: 0 -4px 24px rgba(0,0,0,0.35);',
      '  opacity: 1; transform: translateY(0);',
      '  transition: opacity 0.3s ease, transform 0.3s ease;',
      '}',
      '#acso-cookie-banner p { margin: 0; flex: 1 1 280px; color: #A8BBCF; }',
      '#acso-cookie-banner a { color: #3B82F6; text-decoration: underline; text-underline-offset: 2px; }',
      '#acso-cookie-banner a:hover { color: #60A5FA; }',
      '.acso-cookie-actions { display: flex; gap: 10px; flex-shrink: 0; flex-wrap: wrap; }',
      '.acso-btn-accept {',
      '  padding: 9px 22px; background: #3B82F6; color: #fff; border: none;',
      '  border-radius: 8px; font-family: inherit; font-size: 0.875rem;',
      '  font-weight: 600; cursor: pointer; transition: background 0.15s;',
      '}',
      '.acso-btn-accept:hover { background: #2563EB; }',
      '.acso-btn-reject {',
      '  padding: 9px 22px; background: transparent; color: #A8BBCF;',
      '  border: 1.5px solid #1E3050; border-radius: 8px;',
      '  font-family: inherit; font-size: 0.875rem; font-weight: 500;',
      '  cursor: pointer; transition: border-color 0.15s, color 0.15s;',
      '}',
      '.acso-btn-reject:hover { border-color: #3B82F6; color: #E8EDF4; }',
      '@media (max-width: 600px) {',
      '  #acso-cookie-banner { padding: 14px 16px; }',
      '  .acso-cookie-actions { width: 100%; }',
      '  .acso-btn-accept, .acso-btn-reject { flex: 1; text-align: center; }',
      '}'
    ].join('\n');
    document.head.appendChild(style);

    var banner = document.createElement('div');
    banner.id = 'acso-cookie-banner';
    banner.setAttribute('role', 'dialog');
    banner.setAttribute('aria-label', 'Cookie-Einwilligung');
    banner.innerHTML = [
      '<p>',
      '  Wir erfassen anonyme Nutzungsdaten, um die Website zu verbessern. ',
      '  Klicken Sie auf <strong>Akzeptieren</strong>, um zusätzlich personalisierte Analyse ',
      '  (Google Analytics + Microsoft Clarity Sitzungsaufzeichnung) zuzulassen. ',
      '  Siehe unsere <a href="/privacy.html">Datenschutzerklärung</a> und <a href="/terms.html">AGB</a>.',
      '</p>',
      '<div class="acso-cookie-actions">',
      '  <button class="acso-btn-reject" id="acso-cookie-reject" aria-label="Nicht notwendige Cookies ablehnen">Ablehnen</button>',
      '  <button class="acso-btn-accept" id="acso-cookie-accept" aria-label="Cookies akzeptieren">Akzeptieren</button>',
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

  window.acsoCookieReset = function () {
    try { localStorage.removeItem(STORAGE_KEY); } catch (e) {}
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
