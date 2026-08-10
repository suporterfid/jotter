(function () {
  var storageKey = 'jotter-theme';
  var root = document.documentElement;
  var media = window.matchMedia('(prefers-color-scheme: dark)');

  function preference() {
    try {
      var stored = window.localStorage.getItem(storageKey);
      return stored === 'light' || stored === 'dark' || stored === 'system' ? stored : 'system';
    } catch (_) {
      return 'system';
    }
  }

  function resolvedTheme(value) {
    return value === 'light' || value === 'dark' ? value : (media.matches ? 'dark' : 'light');
  }

  function apply(value) {
    var theme = resolvedTheme(value);
    root.setAttribute('data-theme', theme);
    var meta = document.querySelector('meta[name="theme-color"]');
    if (meta) meta.setAttribute('content', theme === 'dark' ? '#191919' : '#FFFFFF');
  }

  function initializeControl() {
    var control = document.getElementById('publish-theme-preference');
    if (!control) return;

    control.value = preference();
    control.addEventListener('change', function () {
      try {
        window.localStorage.setItem(storageKey, control.value);
      } catch (_) {}
      apply(control.value);
    });

    media.addEventListener('change', function () {
      if (preference() === 'system') apply('system');
    });
  }

  apply(preference());
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeControl, { once: true });
  } else {
    initializeControl();
  }
})();
