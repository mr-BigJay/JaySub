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

  document.querySelectorAll('select[data-ssl-host-prefill]').forEach((sel) => {
    const form = sel.closest('form');
    const hostInput = form ? form.querySelector('input[name="host"]') : null;
    if (!hostInput) return;
    const isAddForm = sel.id === 'ssl-panel-select';
    sel.addEventListener('change', () => {
      const opt = sel.options[sel.selectedIndex];
      const host = opt ? opt.getAttribute('data-host') || '' : '';
      if (!host) return;
      if (isAddForm || !hostInput.value.trim()) {
        hostInput.value = host;
      }
    });
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

  const migrationPage = document.querySelector('.migration-page');
  if (migrationPage) {
    const form = document.getElementById('migration-form');
    const panels = migrationPage.querySelectorAll('[data-migration-wizard-panel]');
    const navNodes = migrationPage.querySelectorAll('[data-migration-nav-step]');
    const sourceSelect = document.getElementById('migration-source-ssl');
    const reviewSource = document.getElementById('migration-review-source');
    const reviewTarget = document.getElementById('migration-review-target');
    const sourceSummary = document.getElementById('migration-source-summary');
    const targetHost = document.getElementById('migration-target-host');
    const topBar = document.getElementById('migration-top-bar');
    const topBarFill = document.getElementById('migration-top-bar-fill');
    const runBlock = document.getElementById('migration-run-block');
    const startActions = document.getElementById('migration-start-actions');
    const completeBox = document.getElementById('migration-complete');
    const completeMsg = document.getElementById('migration-complete-msg');
    const stepsEl = document.getElementById('migration-steps');
    const logEl = document.getElementById('migration-log');
    const startBtn = document.getElementById('migration-start-btn');
    let wizardStep = 1;

    const selectedSourceLabel = () => {
      if (!sourceSelect || sourceSelect.selectedIndex < 0) return '—';
      const opt = sourceSelect.options[sourceSelect.selectedIndex];
      return opt ? opt.textContent.trim() : '—';
    };

    const updateReview = () => {
      if (reviewSource) reviewSource.textContent = selectedSourceLabel();
      const host = targetHost && targetHost.value.trim() ? targetHost.value.trim() : '—';
      if (reviewTarget) reviewTarget.textContent = host;
    };

    const setWizardStep = (n) => {
      wizardStep = n;
      panels.forEach((el) => {
        const sn = parseInt(el.getAttribute('data-migration-wizard-panel') || '0', 10);
        el.hidden = sn !== n;
      });
      navNodes.forEach((node) => {
        const sn = parseInt(node.getAttribute('data-migration-nav-step') || '0', 10);
        node.classList.toggle('is-active', sn === n);
        node.classList.toggle('is-done', sn < n);
        const circle = node.querySelector('.migration-wizard-circle');
        if (circle && sn < n) circle.textContent = '✓';
        else if (circle) circle.textContent = String(sn);
      });
      if (n === 3) updateReview();
    };

    const validateStep = (n) => {
      if (n === 1 && sourceSelect) {
        const val = sourceSelect.value;
        if (!val) {
          sourceSelect.focus();
          return false;
        }
        const opt = sourceSelect.options[sourceSelect.selectedIndex];
        const panelId = opt ? parseInt(opt.getAttribute('data-panel-id') || '0', 10) : 0;
        if (panelId <= 0) {
          window.alert('این سرور SSL به پنل X-UI لینک نیست. از منوی بکاپ SSL آن را به پنل وصل کنید.');
          return false;
        }
        if (sourceSummary && opt) {
          const pl = opt.getAttribute('data-panel-label') || '';
          sourceSummary.textContent = pl ? 'پنل: ' + pl : '';
          sourceSummary.hidden = !pl;
        }
      }
      if (n === 2 && form) {
        const hostInp = form.querySelector('input[name="target_host"]');
        const secret = form.querySelector('textarea[name="target_secret"]');
        if (hostInp && !hostInp.value.trim()) {
          hostInp.focus();
          return false;
        }
        if (secret && !secret.value.trim()) {
          secret.focus();
          return false;
        }
      }
      return true;
    };

    migrationPage.querySelectorAll('[data-migration-next]').forEach((btn) => {
      btn.addEventListener('click', () => {
        if (!validateStep(wizardStep)) return;
        if (wizardStep < 3) setWizardStep(wizardStep + 1);
      });
    });
    migrationPage.querySelectorAll('[data-migration-prev]').forEach((btn) => {
      btn.addEventListener('click', () => {
        if (wizardStep > 1) setWizardStep(wizardStep - 1);
      });
    });
    if (sourceSelect) {
      sourceSelect.addEventListener('change', () => {
        if (sourceSummary) {
          const opt = sourceSelect.options[sourceSelect.selectedIndex];
          const pl = opt ? opt.getAttribute('data-panel-label') || '' : '';
          sourceSummary.textContent = pl ? 'پنل: ' + pl : '';
          sourceSummary.hidden = !pl;
        }
      });
    }
    if (targetHost) targetHost.addEventListener('input', updateReview);

    const stepIcon = (st) => {
      if (st === 'ok') return '<span class="migration-step-mark ok" aria-hidden="true">✓</span>';
      if (st === 'running') return '<span class="migration-step-mark run" aria-hidden="true"></span>';
      if (st === 'error') return '<span class="migration-step-mark err" aria-hidden="true">!</span>';
      return '<span class="migration-step-mark pending" aria-hidden="true"></span>';
    };

    const renderJob = (job) => {
      if (!job || !Array.isArray(job.steps) || !stepsEl) return;
      const total = job.steps.length;
      let done = 0;
      let hasRunning = false;
      stepsEl.innerHTML = job.steps.map((s) => {
        const st = s.status || 'pending';
        if (st === 'ok') done += 1;
        if (st === 'running') hasRunning = true;
        const cls = 'migration-step migration-step-' + st;
        const msg = s.message ? '<span class="migration-step-msg">' + s.message + '</span>' : '';
        return '<li class="' + cls + '">' + stepIcon(st) + '<span class="migration-step-text"><strong>'
          + (s.label || s.id) + '</strong>' + msg + '</span></li>';
      }).join('');
      if (logEl) {
        const lines = Array.isArray(job.log) ? job.log.map((e) => (e.line || '')) : [];
        logEl.textContent = lines.join('\n');
        logEl.scrollTop = logEl.scrollHeight;
      }
      if (topBarFill) {
        let pct = total > 0 ? (done / total) * 100 : 0;
        if (hasRunning && pct < 95) pct += 8;
        if (job.status === 'done') pct = 100;
        topBarFill.style.width = Math.min(100, pct) + '%';
      }
      if (job.status === 'done' && completeBox) {
        completeBox.hidden = false;
        if (completeMsg) {
          const last = Array.isArray(job.log) && job.log.length ? job.log[job.log.length - 1].line : '';
          completeMsg.textContent = last || 'انتقال با موفقیت انجام شد.';
        }
        navNodes.forEach((node) => {
          node.classList.add('is-done');
          node.classList.remove('is-active');
          const circle = node.querySelector('.migration-wizard-circle');
          if (circle) circle.textContent = '✓';
        });
      }
      if (job.status === 'error' && completeBox) {
        completeBox.hidden = false;
        completeBox.classList.add('is-error');
        if (completeMsg) completeMsg.textContent = 'انتقال با خطا متوقف شد. جزئیات را در لاگ ببینید.';
      }
    };

    const beginJobUi = () => {
      setWizardStep(3);
      if (topBar) topBar.hidden = false;
      if (runBlock) runBlock.hidden = false;
      if (startActions) startActions.hidden = true;
      const review = document.getElementById('migration-review');
      if (review) review.hidden = true;
      if (startBtn) startBtn.disabled = true;
    };

    const jobId = migrationPage.getAttribute('data-migration-job');
    if (jobId && stepsEl && logEl) {
      beginJobUi();
      const poll = () => {
        fetch('/admin/migration/status?job=' + encodeURIComponent(jobId), { credentials: 'same-origin' })
          .then((r) => r.json())
          .then((job) => {
            renderJob(job);
            if (job.status === 'running' || job.status === 'queued') {
              setTimeout(poll, 1200);
            }
          })
          .catch(() => setTimeout(poll, 2500));
      };
      poll();
    } else {
      setWizardStep(1);
    }
  }
});
