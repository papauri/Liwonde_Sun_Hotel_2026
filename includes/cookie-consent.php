<!-- Cookie Consent Banner - Modern GDPR-style -->
<div id="cookieConsentBanner" class="cookie-banner" style="display:none;">
    <div class="cookie-banner-inner">
        <div class="cookie-banner-content">
            <div class="cookie-icon">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="#D4AF37" stroke-width="1.5"/><circle cx="8" cy="10" r="1.2" fill="#D4AF37"/><circle cx="14" cy="8" r="1" fill="#D4AF37"/><circle cx="10" cy="15" r="1.1" fill="#D4AF37"/><circle cx="15" cy="13" r="0.9" fill="#D4AF37"/><circle cx="6" cy="13" r="0.7" fill="#D4AF37"/></svg>
            </div>
            <div class="cookie-text">
                <h4>We Value Your Privacy</h4>
                <p>We use cookies and session tracking to enhance your browsing experience, analyse website traffic, and understand where our visitors come from. Your data helps us improve our services. 
                    <a href="privacy-policy.php" class="cookie-policy-link">Read our Privacy Policy</a>
                </p>
            </div>
        </div>
        <div class="cookie-banner-actions">
            <button id="cookieAcceptAll" class="cookie-btn cookie-btn-accept">
                <i class="fas fa-check"></i> Accept All
            </button>
            <button id="cookieEssentialOnly" class="cookie-btn cookie-btn-essential">
                Essential Only
            </button>
            <button id="cookieDecline" class="cookie-btn cookie-btn-decline">
                Decline
            </button>
        </div>
    </div>
</div>

<style>
.cookie-banner {
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    z-index: 99999;
    padding: 0;
    animation: cookieSlideUp 0.5s cubic-bezier(0.22, 1, 0.36, 1) forwards;
    font-family: 'Poppins', sans-serif;
}

@keyframes cookieSlideUp {
    from { transform: translateY(100%); opacity: 0; }
    to   { transform: translateY(0);    opacity: 1; }
}

.cookie-banner-inner {
    max-width: 1100px;
    margin: 0 auto 20px;
    background: rgba(255, 255, 255, 0.97);
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    border-radius: 18px;
    box-shadow: 0 -4px 40px rgba(0, 0, 0, 0.15), 0 0 0 1px rgba(212, 175, 55, 0.15);
    padding: 24px 32px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 24px;
    margin-left: 20px;
    margin-right: 20px;
}

.cookie-banner-content {
    display: flex;
    align-items: flex-start;
    gap: 16px;
    flex: 1;
}

.cookie-icon {
    flex-shrink: 0;
    margin-top: 2px;
}

.cookie-text h4 {
    margin: 0 0 6px 0;
    font-size: 16px;
    font-weight: 700;
    color: #1a1a2e;
    font-family: 'Playfair Display', serif;
}

.cookie-text p {
    margin: 0;
    font-size: 13px;
    line-height: 1.6;
    color: #555;
}

.cookie-policy-link {
    color: #D4AF37;
    text-decoration: underline;
    font-weight: 600;
    transition: color 0.2s;
}

.cookie-policy-link:hover {
    color: #B8860B;
}

.cookie-banner-actions {
    display: flex;
    gap: 10px;
    flex-shrink: 0;
}

.cookie-btn {
    padding: 10px 22px;
    border: none;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.25s ease;
    font-family: 'Poppins', sans-serif;
    white-space: nowrap;
}

.cookie-btn-accept {
    background: linear-gradient(135deg, #D4AF37, #B8860B);
    color: #fff;
    box-shadow: 0 4px 14px rgba(212, 175, 55, 0.35);
}

.cookie-btn-accept:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(212, 175, 55, 0.5);
}

.cookie-btn-essential {
    background: #f0f0f0;
    color: #333;
    border: 1px solid #ddd;
}

.cookie-btn-essential:hover {
    background: #e5e5e5;
}

.cookie-btn-decline {
    background: transparent;
    color: #999;
    border: 1px solid #ddd;
}

.cookie-btn-decline:hover {
    background: #f5f5f5;
    color: #666;
}

/* Dark mode if user prefers */
@media (prefers-color-scheme: dark) {
    .cookie-banner-inner {
        background: rgba(26, 26, 46, 0.97);
        box-shadow: 0 -4px 40px rgba(0, 0, 0, 0.4), 0 0 0 1px rgba(212, 175, 55, 0.2);
    }
    .cookie-text h4 { color: #fff; }
    .cookie-text p { color: #bbb; }
    .cookie-btn-essential { background: #2a2a3e; color: #ddd; border-color: #444; }
    .cookie-btn-decline { color: #888; border-color: #444; }
    .cookie-btn-decline:hover { background: #2a2a3e; color: #aaa; }
}

/* Mobile */
@media (max-width: 768px) {
    .cookie-banner-inner {
        flex-direction: column;
        padding: 20px;
        margin: 0 12px 12px;
        border-radius: 14px;
        gap: 16px;
    }
    .cookie-banner-content { gap: 12px; }
    .cookie-icon { display: none; }
    .cookie-text h4 { font-size: 15px; }
    .cookie-text p { font-size: 12px; }
    .cookie-banner-actions {
        width: 100%;
        flex-direction: column;
        gap: 8px;
    }
    .cookie-btn {
        width: 100%;
        padding: 12px;
        text-align: center;
    }
}
</style>

<script>
(function() {
    'use strict';

    var COOKIE_NAME = 'cookie_consent';
    var COOKIE_DAYS = 365;
    var banner = document.getElementById('cookieConsentBanner');

    function getCookie(name) {
        var match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
        return match ? decodeURIComponent(match[2]) : null;
    }

    function setCookie(name, value, days) {
        var d = new Date();
        d.setTime(d.getTime() + (days * 24 * 60 * 60 * 1000));
        document.cookie = name + '=' + encodeURIComponent(value) + ';expires=' + d.toUTCString() + ';path=/;SameSite=Lax';
    }

    function hideBanner() {
        if (banner) {
            banner.style.animation = 'cookieSlideDown 0.3s ease forwards';
            setTimeout(function() { banner.style.display = 'none'; }, 350);
        }
    }

    function logConsent(level) {
        // Fire-and-forget consent log to server
        var xhr = new XMLHttpRequest();
        xhr.open('POST', (typeof siteBaseUrl !== 'undefined' ? siteBaseUrl : '') + 'api/cookie-consent.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.send('consent_level=' + encodeURIComponent(level));
    }

    // Check if consent already given
    var existing = getCookie(COOKIE_NAME);
    if (!existing) {
        // Show banner after a short delay for better UX
        setTimeout(function() {
            if (banner) banner.style.display = 'block';
        }, 1500);
    }

    // Accept All
    document.getElementById('cookieAcceptAll').addEventListener('click', function() {
        setCookie(COOKIE_NAME, 'all', COOKIE_DAYS);
        logConsent('all');
        hideBanner();
    });

    // Essential Only
    document.getElementById('cookieEssentialOnly').addEventListener('click', function() {
        setCookie(COOKIE_NAME, 'essential', COOKIE_DAYS);
        logConsent('essential');
        hideBanner();
    });

    // Decline
    document.getElementById('cookieDecline').addEventListener('click', function() {
        setCookie(COOKIE_NAME, 'declined', COOKIE_DAYS);
        logConsent('declined');
        hideBanner();
    });

    // Add slide-down animation
    var style = document.createElement('style');
    style.textContent = '@keyframes cookieSlideDown { from { transform: translateY(0); opacity: 1; } to { transform: translateY(100%); opacity: 0; } }';
    document.head.appendChild(style);
})();
</script>
