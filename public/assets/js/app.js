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

  const copyText = (text, onDone) => {
    if (!text) return;
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(onDone);
    } else {
      const ta = document.createElement('textarea');
      ta.value = text;
      document.body.appendChild(ta);
      ta.select();
      document.execCommand('copy');
      document.body.removeChild(ta);
      onDone();
    }
  };

  document.querySelectorAll('[data-copy-target]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const id = btn.getAttribute('data-copy-target');
      const input = id ? document.getElementById(id) : null;
      if (!input) return;
      input.select();
      input.setSelectionRange(0, 99999);
      const text = input.value;
      const label = btn.textContent;
      copyText(text, () => {
        btn.textContent = 'Copied';
        setTimeout(() => { btn.textContent = label; }, 1500);
      });
    });
  });

  document.querySelectorAll('[data-copy-text]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const text = btn.getAttribute('data-copy-text') || '';
      copyText(text, () => {
        btn.classList.add('copied');
        const prev = btn.getAttribute('data-copy-label') || btn.textContent;
        btn.setAttribute('data-copy-label', prev);
        btn.textContent = 'کپی شد ✓';
        setTimeout(() => {
          btn.classList.remove('copied');
          btn.textContent = prev;
        }, 1400);
      });
    });
  });

  const openModal = (id) => {
    const modal = document.getElementById(id);
    if (!modal) return;
    modal.hidden = false;
    document.body.classList.add('modal-open');
    const focusable = modal.querySelector('input, select, button, textarea');
    if (focusable) focusable.focus();
  };

  const closeModal = (modal) => {
    modal.hidden = true;
    if (!document.querySelector('.app-modal:not([hidden])')) {
      document.body.classList.remove('modal-open');
    }
  };

  document.querySelectorAll('[data-open-modal]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const id = btn.getAttribute('data-open-modal');
      if (id) openModal(id);
    });
  });

  document.querySelectorAll('[data-close-modal]').forEach((el) => {
    el.addEventListener('click', () => {
      const modal = el.closest('.app-modal');
      if (modal) closeModal(modal);
    });
  });

  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    document.querySelectorAll('.app-modal:not([hidden])').forEach((modal) => closeModal(modal));
  });

  document.querySelectorAll('.app-modal[data-auto-open]').forEach((modal) => {
    modal.hidden = false;
    document.body.classList.add('modal-open');
  });

  const adminMenuSheet = document.getElementById('admin-nav-sheet');
  const adminMenuTriggers = document.querySelectorAll('[data-open-admin-menu]');

  const closeAdminMenu = () => {
    if (!adminMenuSheet) return;
    adminMenuSheet.classList.remove('is-open');
    document.body.classList.remove('admin-menu-open');
    adminMenuTriggers.forEach((btn) => btn.setAttribute('aria-expanded', 'false'));
    window.setTimeout(() => {
      if (adminMenuSheet && !adminMenuSheet.classList.contains('is-open')) {
        adminMenuSheet.hidden = true;
      }
    }, 340);
  };

  const positionAdminMenuPanel = () => {
    const trigger = document.querySelector('.bottom-nav-menu-trigger');
    const panel = adminMenuSheet?.querySelector('.admin-nav-sheet-panel');
    if (!trigger || !panel) return;
    const tr = trigger.getBoundingClientRect();
    const width = Math.min(292, window.innerWidth - 16);
    const right = Math.max(8, window.innerWidth - tr.right + (tr.width - width) / 2);
    panel.style.width = `${width}px`;
    panel.style.right = `${right}px`;
    panel.style.left = 'auto';
  };

  const openAdminMenu = () => {
    if (!adminMenuSheet) return;
    adminMenuSheet.hidden = false;
    positionAdminMenuPanel();
    document.body.classList.add('admin-menu-open');
    adminMenuTriggers.forEach((btn) => btn.setAttribute('aria-expanded', 'true'));
    requestAnimationFrame(() => {
      requestAnimationFrame(() => adminMenuSheet.classList.add('is-open'));
    });
  };

  window.addEventListener('resize', () => {
    if (adminMenuSheet?.classList.contains('is-open')) positionAdminMenuPanel();
  });

  adminMenuTriggers.forEach((btn) => {
    btn.addEventListener('click', () => {
      if (adminMenuSheet?.classList.contains('is-open')) closeAdminMenu();
      else openAdminMenu();
    });
  });

  document.querySelectorAll('[data-close-admin-menu]').forEach((el) => {
    el.addEventListener('click', closeAdminMenu);
  });

  adminMenuSheet?.querySelectorAll('a.sidebar-link').forEach((link) => {
    link.addEventListener('click', closeAdminMenu);
  });

  document.querySelectorAll('[data-admin-back]').forEach((link) => {
    link.addEventListener('click', (e) => {
      if (window.history.length > 1) {
        e.preventDefault();
        window.history.back();
      }
    });
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && adminMenuSheet && adminMenuSheet.classList.contains('is-open')) closeAdminMenu();
  });
});
