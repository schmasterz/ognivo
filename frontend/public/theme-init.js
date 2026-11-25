(function () {
  try {
    var s = localStorage.getItem('theme_mode');
    var d = s === 'dark' || ((!s || s === 'system') && window.matchMedia('(prefers-color-scheme: dark)').matches);
    if (d) {
      document.documentElement.classList.add('dark');
      document.documentElement.setAttribute('data-theme', 'dark');
      document.documentElement.style.colorScheme = 'dark';
    } else {
      document.documentElement.classList.remove('dark');
      document.documentElement.setAttribute('data-theme', 'light');
      document.documentElement.style.colorScheme = 'light';
    }
  } catch {}
})();
