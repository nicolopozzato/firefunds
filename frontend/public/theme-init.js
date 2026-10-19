// Dark mode from the first frame (src/lib/theme.ts applies the full theme a
// moment later): no white flash. A file, not an inline script, so the
// Content-Security-Policy can allow scripts from this origin only.
try {
  var config = JSON.parse(localStorage.getItem('theme-config') || '{}')
  if (config.mode === 'dark') {
    document.documentElement.classList.add('dark')
    document.documentElement.style.colorScheme = 'dark'
    document.documentElement.style.backgroundColor = '#0c0c0e'
    document.querySelector('meta[name="theme-color"]').setAttribute('content', '#0c0c0e')
  }
} catch (e) {}
