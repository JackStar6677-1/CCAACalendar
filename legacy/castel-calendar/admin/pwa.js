(function () {
  if (!('serviceWorker' in navigator)) return;

  window.addEventListener('load', function () {
    navigator.serviceWorker.register('/admin/sw.js', { scope: '/admin/' }).catch(function () {});
  });

  var deferredPrompt = null;

  function updateInstallButtons() {
    Array.prototype.slice.call(document.querySelectorAll('[data-pwa-install]')).forEach(function (button) {
      button.hidden = !deferredPrompt;
      if (button.dataset.pwaReady === '1') return;
      button.dataset.pwaReady = '1';
      button.addEventListener('click', function () {
        if (!deferredPrompt) return;
        deferredPrompt.prompt();
        deferredPrompt.userChoice.finally(function () {
          deferredPrompt = null;
          updateInstallButtons();
        });
      });
    });
  }

  window.addEventListener('beforeinstallprompt', function (event) {
    event.preventDefault();
    deferredPrompt = event;
    updateInstallButtons();
  });

  window.addEventListener('appinstalled', function () {
    deferredPrompt = null;
    updateInstallButtons();
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', updateInstallButtons);
  } else {
    updateInstallButtons();
  }
})();
