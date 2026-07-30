// auth.js — shared portal authentication for Hegalaldia
// Inject body{visibility:hidden} immediately, before DOMContentLoaded
(function () {
  var s = document.createElement('style');
  s.id = 'hega-auth-hide';
  s.textContent = 'body{visibility:hidden!important}';
  document.head.appendChild(s);
})();

(function () {
  var STORAGE_KEY = 'hega_portal_token';

  function revealBody() {
    var el = document.getElementById('hega-auth-hide');
    if (el) el.remove();
    document.body.style.visibility = '';
  }

  function showOverlay() {
    var overlay = document.createElement('div');
    overlay.id = 'hega-auth-overlay';
    overlay.style.cssText = [
      'position:fixed', 'inset:0', 'z-index:9999',
      'background:#f7f9f5',
      'display:flex', 'align-items:center', 'justify-content:center',
      'padding:24px'
    ].join(';');

    overlay.innerHTML = [
      '<div style="width:100%;max-width:400px;background:#fff;border-radius:14px;',
      'box-shadow:0 4px 24px rgba(12,61,34,0.1);padding:40px 36px;text-align:center">',
        '<img src="/LogoHegalaldiaColorCrop.png" alt="Hegalaldia"',
        ' style="width:160px;margin:0 auto 16px;display:block">',
        '<h1 style="font-size:22px;font-weight:700;color:#0c3d22;margin:0 0 4px">Hegalaldia</h1>',
        '<p style="font-size:14px;color:#5a7a62;margin:0 0 28px">Portail de gestion</p>',
        '<div style="position:relative;margin-bottom:12px">',
        '<input id="hega-pwd" type="password" placeholder="Mot de passe"',
        ' style="width:100%;padding:11px 44px 11px 14px;border:1.5px solid #dbe3dc;border-radius:9px;',
        'font-size:15px;outline:none;box-sizing:border-box;color:#1a2e1e">',
        '<button type="button" id="hega-toggle-pwd" title="Afficher / masquer"',
        ' style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;padding:0;color:#9aaa9d;display:flex;align-items:center">',
        '<svg id="hega-eye-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>',
        '</button>',
        '</div>',
        '<button id="hega-submit"',
        ' style="width:100%;padding:12px;background:#0c3d22;color:#fff;border:none;',
        'border-radius:9px;font-size:15px;font-weight:600;cursor:pointer;margin-bottom:12px">',
        'Connexion</button>',
        '<div id="hega-error"',
        ' style="font-size:13px;color:#c0392b;min-height:18px;display:none">',
        '</div>',
      '</div>'
    ].join('');

    document.body.appendChild(overlay);
    revealBody();
    document.getElementById('hega-pwd').focus();

    function doLogin() {
      var pwd = document.getElementById('hega-pwd').value;
      var errEl = document.getElementById('hega-error');
      var btn = document.getElementById('hega-submit');
      if (!pwd) return;
      btn.disabled = true;
      btn.textContent = '…';
      errEl.style.display = 'none';

      fetch('/api/connexion', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({password: pwd})
      })
      .then(function (r) {
        return r.json().then(function(data) { return {status: r.status, data: data}; });
      })
      .then(function (res) {
        var data = res.data;
        if (data.ok) {
          localStorage.setItem(STORAGE_KEY, data.token);
          overlay.remove();
          revealBody();
        } else if (res.status === 429) {
          // Trop de tentatives — IP bloquée
          var mins = Math.ceil((data.retry_after || 900) / 60);
          errEl.textContent = '🔒 Trop de tentatives. Réessayez dans ' + mins + ' min.';
          errEl.style.display = 'block';
          document.getElementById('hega-pwd').disabled = true;
          btn.disabled = true;
          btn.textContent = 'Connexion';
          // Countdown
          var remaining = data.retry_after || 900;
          var countdown = setInterval(function() {
            remaining -= 1;
            if (remaining <= 0) {
              clearInterval(countdown);
              errEl.style.display = 'none';
              document.getElementById('hega-pwd').disabled = false;
              btn.disabled = false;
              btn.textContent = 'Connexion';
            } else {
              var m = Math.floor(remaining / 60), s = remaining % 60;
              errEl.textContent = '🔒 Trop de tentatives. Réessayez dans ' + m + 'min ' + s + 's.';
            }
          }, 1000);
        } else {
          var rem = data.remaining;
          if (typeof rem === 'number' && rem > 0) {
            errEl.textContent = 'Mot de passe incorrect. ' + rem + ' tentative' + (rem > 1 ? 's' : '') + ' restante' + (rem > 1 ? 's' : '') + '.';
          } else {
            errEl.textContent = 'Mot de passe incorrect.';
          }
          errEl.style.display = 'block';
          document.getElementById('hega-pwd').value = '';
          document.getElementById('hega-pwd').focus();
          btn.disabled = false;
          btn.textContent = 'Connexion';
        }
      })
      .catch(function () {
        errEl.textContent = 'Erreur réseau. Réessayez.';
        errEl.style.display = 'block';
        btn.disabled = false;
        btn.textContent = 'Connexion';
      });
    }

    document.getElementById('hega-submit').addEventListener('click', doLogin);
    document.getElementById('hega-pwd').addEventListener('keydown', function (e) {
      if (e.key === 'Enter') doLogin();
    });
    document.getElementById('hega-toggle-pwd').addEventListener('click', function () {
      var inp = document.getElementById('hega-pwd');
      var icon = document.getElementById('hega-eye-icon');
      if (inp.type === 'password') {
        inp.type = 'text';
        icon.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/>';
      } else {
        inp.type = 'password';
        icon.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
      }
      inp.focus();
    });
  }

  function init() {
    var token = localStorage.getItem(STORAGE_KEY);
    if (!token) {
      showOverlay();
      return;
    }
    fetch('/api/connexion/verify', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({token: token})
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (data.ok) {
        revealBody();
      } else {
        localStorage.removeItem(STORAGE_KEY);
        showOverlay();
      }
    })
    .catch(function () {
      // Erreur réseau — on redemande le mot de passe par sécurité
      localStorage.removeItem(STORAGE_KEY);
      showOverlay();
    });
  }

  window.hegaAuth = {
    logout: function () {
      localStorage.removeItem(STORAGE_KEY);
      location.reload();
    }
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
