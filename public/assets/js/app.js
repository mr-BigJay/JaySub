document.addEventListener('DOMContentLoaded', () => {
  const bars = document.querySelectorAll('[data-progress]');
  bars.forEach((el) => {
    const pct = parseFloat(el.getAttribute('data-progress') || '0');
    const fill = el.querySelector('span');
    if (fill) {
      fill.style.width = Math.min(100, pct) + '%';
    }
    if (pct >= 90) el.classList.add('danger');
    else if (pct >= 80) el.classList.add('warn');
  });

  document.querySelectorAll('[data-copy-target]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const id = btn.getAttribute('data-copy-target');
      const input = id ? document.getElementById(id) : null;
      if (!input) return;
      input.select();
      input.setSelectionRange(0, 99999);
      const text = input.value;
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(() => {
          btn.textContent = 'Copied';
          setTimeout(() => { btn.textContent = 'Copy'; }, 1500);
        });
      } else {
        document.execCommand('copy');
      }
    });
  });
});
