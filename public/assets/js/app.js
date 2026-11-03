document.addEventListener('DOMContentLoaded', () => {
  // The shared admin clock must not depend on the browser or VPS timezone.
  const tehranClock = document.querySelector('[data-tehran-clock]');
  if (tehranClock && typeof Intl !== 'undefined' && Intl.DateTimeFormat) {
    try {
      const formatter = new Intl.DateTimeFormat('fa-IR', {
        timeZone: 'Asia/Tehran',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
      });
      const updateTehranClock = () => {
        const now = new Date();
        tehranClock.textContent = formatter.format(now);
        tehranClock.dateTime = now.toISOString();
      };
      updateTehranClock();
      window.setInterval(updateTehranClock, 15000);
      document.addEventListener('visibilitychange', () => {
        if (!document.hidden) updateTehranClock();
      });
    } catch (_) {
      // PHP renders an Asia/Tehran timestamp as the no-JavaScript fallback.
    }
  }
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
    const reviewInstallMode = document.getElementById('migration-review-install-mode');
    const offlineInstallCheckbox = document.getElementById('migration-xui-install-mode');
    const offlineInstallModeValue = document.getElementById('migration-xui-install-mode-value');
    const syncOfflineInstallModeField = () => {
      if (offlineInstallModeValue) {
        offlineInstallModeValue.value = offlineInstallCheckbox && offlineInstallCheckbox.checked ? 'offline' : 'online';
      }
    };
    offlineInstallCheckbox?.addEventListener('change', syncOfflineInstallModeField);
    syncOfflineInstallModeField();
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
      if (reviewInstallMode) {
        reviewInstallMode.textContent = offlineInstallCheckbox && offlineInstallCheckbox.checked
          ? 'آفلاین — فایل از سرور مدیریت JaySub'
          : 'آنلاین (پیش‌فرض)';
      }
    };

    const syncWizardFields = () => {
      panels.forEach((el) => {
        const isHidden = el.hidden;
        el.querySelectorAll('input, select, textarea').forEach((field) => {
          if (isHidden) {
            if (field.required) {
              field.dataset.migrationRequired = '1';
              field.required = false;
            }
            field.disabled = true;
          } else {
            field.disabled = false;
            if (field.dataset.migrationRequired === '1') {
              field.required = true;
            }
          }
        });
      });
    };

    const prepareWizardSubmit = () => {
      panels.forEach((el) => {
        el.querySelectorAll('input, select, textarea').forEach((field) => {
          field.disabled = false;
          if (field.dataset.migrationRequired === '1') {
            field.required = true;
          }
        });
      });
    };

    const setWizardStep = (n) => {
      wizardStep = n;
      panels.forEach((el) => {
        const sn = parseInt(el.getAttribute('data-migration-wizard-panel') || '0', 10);
        el.hidden = sn !== n;
      });
      syncWizardFields();
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

    if (form) {
      form.addEventListener('submit', (e) => {
        if (!validateStep(1)) {
          e.preventDefault();
          setWizardStep(1);
          return;
        }
        if (!validateStep(2)) {
          e.preventDefault();
          setWizardStep(2);
          return;
        }
        syncOfflineInstallModeField();
        prepareWizardSubmit();
      });
    }

    const stepIcon = (st) => {
      if (st === 'ok') return '<span class="migration-step-mark ok" aria-hidden="true">✓</span>';
      if (st === 'skipped') return '<span class="migration-step-mark skip" aria-hidden="true">—</span>';
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
        if (st === 'ok' || st === 'skipped') done += 1;
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
        if (job.status === 'done' || job.status === 'partial') pct = 100;
        topBarFill.style.width = Math.min(100, pct) + '%';
      }
      if ((job.status === 'done' || job.status === 'partial') && completeBox) {
        completeBox.hidden = false;
        completeBox.classList.remove('is-error');
        if (job.status === 'partial') {
          completeBox.classList.add('is-partial');
        } else {
          completeBox.classList.remove('is-partial');
        }
        if (completeMsg) {
          if (job.migration_outcome === 'verified_only' || job.status === 'partial') {
            completeMsg.textContent = job.result_summary || 'فقط بررسی شد؛ SSL و Restore انجام نشد.';
          } else {
            const last = Array.isArray(job.log) && job.log.length ? job.log[job.log.length - 1].line : '';
            completeMsg.textContent = last || 'انتقال با موفقیت انجام شد.';
          }
        }
        navNodes.forEach((node) => {
          node.classList.add('is-done');
          node.classList.remove('is-active');
          const circle = node.querySelector('.migration-wizard-circle');
          if (circle) circle.textContent = job.status === 'partial' ? 'i' : '✓';
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
          .then((r) => {
            if (!r.ok) {
              throw new Error('status ' + r.status);
            }
            return r.json();
          })
          .then((job) => {
            if (job && job.error) {
              if (logEl) logEl.textContent = 'خطا: ' + job.error;
              return;
            }
            renderJob(job);
            if (job.status === 'running' || job.status === 'queued') {
              setTimeout(poll, 1200);
            }
          })
          .catch((err) => {
            if (logEl) logEl.textContent = 'خطا در دریافت وضعیت job. صفحه را رفرش کنید.\n' + (err.message || '');
            setTimeout(poll, 2500);
          });
      };
      poll();
    } else {
      setWizardStep(1);
    }
  }

  const routingPage = document.querySelector('.routing-page');
  if (routingPage) {
    const csrf = routingPage.getAttribute('data-csrf') || '';
    const panelListEl = document.getElementById('routing-panel-list');
    const selectAllEl = document.getElementById('routing-select-all');
    const editorStatus = document.getElementById('routing-editor-status');
    const rulesWrap = document.getElementById('routing-rules-wrap');
    const rulesBody = document.getElementById('routing-rules-body');
    const rulesCardsEl = document.getElementById('routing-rules-cards');
    const rulesActionRoot = document.getElementById('routing-rules-wrap');
    const bulkOp = document.getElementById('routing-bulk-op');
    const bulkDirectionWrap = document.getElementById('routing-bulk-direction-wrap');
    const bulkResults = document.getElementById('routing-bulk-results');
    const ruleDialog = document.getElementById('routing-rule-dialog');

    let panels = [];
    let activePanelId = 0;
    let editableRules = [];

    const statusClass = (p) => {
      const st = (p.connection_status || '').toLowerCase();
      if (st === 'ok' || st === 'connected') return 'ok';
      if (st === 'error' || st === 'failed') return 'err';
      return 'unknown';
    };

    const selectedPanelIds = () =>
      panels.filter((p) => p._checked).map((p) => p.id);

    const fetchJson = (url, options = {}) =>
      fetch(url, { credentials: 'same-origin', ...options }).then((r) => r.json());

    const renderPanelList = () => {
      if (!panelListEl) return;
      if (!panels.length) {
        panelListEl.textContent = 'پنلی ثبت نشده است.';
        return;
      }
      panelListEl.innerHTML = '';
      panels.forEach((p) => {
        const row = document.createElement('div');
        row.className = 'routing-panel-item' + (p.id === activePanelId ? ' is-active' : '');
        const err = p.last_error ? `<span class="muted">${p.last_error}</span>` : '';
        row.innerHTML =
          `<label class="routing-panel-check" aria-label="انتخاب پنل">` +
          `<input type="checkbox" class="routing-panel-cb" data-id="${p.id}" ${p._checked ? 'checked' : ''} />` +
          `</label>` +
          `<div class="routing-panel-meta"><strong class="routing-panel-name">${p.name}</strong>` +
          `<span class="routing-panel-customer muted">${p.customer_name || ''}</span>` +
          `<span class="routing-status ${statusClass(p)}">${p.connection_status || 'unknown'}</span>${err}</div>`;
        row.querySelector('.routing-panel-meta')?.addEventListener('click', () => loadPanelRules(p.id));
        row.querySelector('.routing-panel-cb')?.addEventListener('click', (ev) => ev.stopPropagation());
        row.querySelector('.routing-panel-cb')?.addEventListener('change', (ev) => {
          p._checked = ev.target.checked;
        });
        panelListEl.appendChild(row);
      });
    };

    const ruleActionButtons = (idx) =>
      `<button type="button" class="btn btn-ghost btn small routing-action-btn" data-move="up" data-idx="${idx}" aria-label="بالا">↑</button>` +
      `<button type="button" class="btn btn-ghost btn small routing-action-btn" data-move="down" data-idx="${idx}" aria-label="پایین">↓</button>` +
      `<button type="button" class="btn btn-ghost btn small routing-action-btn" data-edit="${idx}">ویرایش</button>` +
      `<button type="button" class="btn btn-ghost btn small routing-action-btn" data-del="${idx}">حذف</button>`;

    const ruleDisplay = (rule) => {
      const domain = Array.isArray(rule.domain) ? rule.domain.join(', ') : rule.domain || '—';
      const ip = Array.isArray(rule.ip) ? rule.ip.join(', ') : rule.ip || '—';
      const inbound = Array.isArray(rule.inboundTag) ? rule.inboundTag.join(', ') : rule.inboundTag || '—';
      const dest = rule.balancerTag || rule.outboundTag || '—';
      const type = rule.type || 'field';
      return { domain, ip, inbound, dest, type };
    };

    const handleRuleAction = (btn) => {
      const idx = parseInt(btn.getAttribute('data-idx') || btn.getAttribute('data-edit') || btn.getAttribute('data-del') || '0', 10);
      if (btn.hasAttribute('data-move')) {
        const dir = btn.getAttribute('data-move');
        if (dir === 'up' && idx > 0) {
          const tmp = editableRules[idx - 1];
          editableRules[idx - 1] = editableRules[idx];
          editableRules[idx] = tmp;
        }
        if (dir === 'down' && idx < editableRules.length - 1) {
          const tmp = editableRules[idx + 1];
          editableRules[idx + 1] = editableRules[idx];
          editableRules[idx] = tmp;
        }
        renderRulesTable();
        return;
      }
      if (btn.hasAttribute('data-edit')) {
        openRuleDialog(idx);
        return;
      }
      if (btn.hasAttribute('data-del')) {
        editableRules.splice(idx, 1);
        renderRulesTable();
      }
    };

    if (rulesActionRoot && !rulesActionRoot.dataset.routingActionsBound) {
      rulesActionRoot.dataset.routingActionsBound = '1';
      rulesActionRoot.addEventListener('click', (ev) => {
        const btn = ev.target.closest('[data-move],[data-edit],[data-del]');
        if (!btn || !rulesActionRoot.contains(btn)) return;
        ev.preventDefault();
        handleRuleAction(btn);
      });
    }

    const renderRulesTable = () => {
      if (rulesBody) rulesBody.innerHTML = '';
      if (rulesCardsEl) {
        rulesCardsEl.innerHTML = '';
        rulesCardsEl.hidden = editableRules.length === 0;
      }
      editableRules.forEach((rule, idx) => {
        const { domain, ip, inbound, dest, type } = ruleDisplay(rule);
        const priority = idx + 1;
        if (rulesBody) {
          const tr = document.createElement('tr');
          tr.innerHTML =
            `<td>${priority}</td><td>${type}</td><td>${domain}</td><td>${ip}</td>` +
            `<td>${inbound}</td><td>${dest}</td>` +
            `<td class="routing-rule-actions">${ruleActionButtons(idx)}</td>`;
          rulesBody.appendChild(tr);
        }
        if (rulesCardsEl) {
          const card = document.createElement('article');
          card.className = 'routing-rule-card';
          card.innerHTML =
            `<header class="routing-rule-card-head">` +
            `<span class="routing-rule-priority">اولویت ${priority}</span>` +
            `<span class="routing-rule-type muted">${type}</span>` +
            `</header>` +
            `<dl class="routing-rule-dl">` +
            `<div><dt>دامنه</dt><dd>${domain}</dd></div>` +
            `<div><dt>IP</dt><dd>${ip}</dd></div>` +
            `<div><dt>Inbound</dt><dd>${inbound}</dd></div>` +
            `<div><dt>مقصد</dt><dd>${dest}</dd></div>` +
            `</dl>` +
            `<div class="routing-rule-actions routing-rule-card-actions">${ruleActionButtons(idx)}</div>`;
          rulesCardsEl.appendChild(card);
        }
      });
    };

    const loadPanelRules = (panelId) => {
      activePanelId = panelId;
      renderPanelList();
      if (editorStatus) editorStatus.textContent = 'در حال دریافت قوانین…';
      if (rulesWrap) rulesWrap.hidden = true;
      fetchJson('/admin/routing/api/panel/' + panelId + '/rules')
        .then((data) => {
          if (!data.ok) {
            if (editorStatus) {
              editorStatus.textContent = data.error || data.connection_error || 'خطا در خواندن routing';
            }
            return;
          }
          editableRules = (data.rules || [])
            .filter((r) => !r.protected)
            .map((r) => r.raw || {});
          if (editorStatus) {
            editorStatus.textContent =
              `اتصال: ${data.connection_ok ? 'OK' : 'خطا'} — ${data.protected_count || 0} قانون محافظت‌شده`;
          }
          if (rulesWrap) rulesWrap.hidden = false;
          renderRulesTable();
        })
        .catch(() => {
          if (editorStatus) editorStatus.textContent = 'خطا در ارتباط با سرور JaySub';
        });
    };

    const splitCsv = (v) =>
      v
        .split(',')
        .map((s) => s.trim())
        .filter(Boolean);

    const openRuleDialog = (index = -1) => {
      const rule = index >= 0 ? editableRules[index] : { type: 'field' };
      document.getElementById('routing-rule-index').value = String(index);
      document.getElementById('routing-rule-type').value = rule.type || 'field';
      document.getElementById('routing-rule-domain').value = Array.isArray(rule.domain)
        ? rule.domain.join(', ')
        : rule.domain || '';
      document.getElementById('routing-rule-ip').value = Array.isArray(rule.ip) ? rule.ip.join(', ') : rule.ip || '';
      document.getElementById('routing-rule-inbound').value = Array.isArray(rule.inboundTag)
        ? rule.inboundTag.join(', ')
        : rule.inboundTag || '';
      document.getElementById('routing-rule-outbound').value = rule.outboundTag || '';
      document.getElementById('routing-rule-balancer').value = rule.balancerTag || '';
      ruleDialog?.showModal();
    };

    document.getElementById('routing-add-rule')?.addEventListener('click', () => openRuleDialog(-1));
    document.getElementById('routing-rule-form')?.addEventListener('submit', (ev) => {
      ev.preventDefault();
      const idx = parseInt(document.getElementById('routing-rule-index').value || '-1', 10);
      const rule = { type: document.getElementById('routing-rule-type').value || 'field' };
      const d = splitCsv(document.getElementById('routing-rule-domain').value || '');
      const ip = splitCsv(document.getElementById('routing-rule-ip').value || '');
      const inbound = splitCsv(document.getElementById('routing-rule-inbound').value || '');
      if (d.length) rule.domain = d.length === 1 ? d[0] : d;
      if (ip.length) rule.ip = ip.length === 1 ? ip[0] : ip;
      if (inbound.length) rule.inboundTag = inbound.length === 1 ? inbound[0] : inbound;
      const ob = (document.getElementById('routing-rule-outbound').value || '').trim();
      const bal = (document.getElementById('routing-rule-balancer').value || '').trim();
      if (ob) rule.outboundTag = ob;
      if (bal) rule.balancerTag = bal;
      if (idx >= 0) editableRules[idx] = rule;
      else editableRules.push(rule);
      ruleDialog?.close();
      renderRulesTable();
    });
    document.querySelectorAll('[data-close-routing-dialog]').forEach((el) => {
      el.addEventListener('click', () => ruleDialog?.close());
    });

    document.getElementById('routing-save-rules')?.addEventListener('click', () => {
      if (!activePanelId) return;
      fetchJson('/admin/routing/api/panel/' + activePanelId + '/rules', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ _csrf: csrf, rules: editableRules }),
      }).then((res) => {
        if (editorStatus) {
          editorStatus.textContent = res.ok ? res.msg || 'ذخیره شد' : res.error || 'ذخیره ناموفق';
        }
        if (res.ok) loadPanelRules(activePanelId);
      });
    });

    const bulkPayload = () => {
      const op = bulkOp?.value || 'add';
      const domain = (document.getElementById('routing-bulk-domain')?.value || '').trim();
      const ip = (document.getElementById('routing-bulk-ip')?.value || '').trim();
      const inbound = (document.getElementById('routing-bulk-inbound')?.value || '').trim();
      const outbound = (document.getElementById('routing-bulk-outbound')?.value || '').trim();
      const balancer = (document.getElementById('routing-bulk-balancer')?.value || '').trim();
      const match = {};
      if (domain) match.domain = domain;
      if (ip) match.ip = ip;
      if (inbound) match.inboundTag = inbound;
      if (outbound) match.outboundTag = outbound;
      if (balancer) match.balancerTag = balancer;
      const rule = { type: 'field' };
      if (domain) rule.domain = domain.includes(',') ? splitCsv(domain) : domain;
      if (ip) rule.ip = ip.includes(',') ? splitCsv(ip) : ip;
      if (inbound) rule.inboundTag = inbound.includes(',') ? splitCsv(inbound) : inbound;
      if (outbound) rule.outboundTag = outbound;
      if (balancer) rule.balancerTag = balancer;
      return {
        _csrf: csrf,
        panel_ids: selectedPanelIds(),
        operation: op,
        rule: op === 'add' ? rule : undefined,
        match: op === 'add' ? undefined : match,
        direction: document.getElementById('routing-bulk-direction')?.value || 'up',
      };
    };

    const renderBulkResults = (rows) => {
      if (!bulkResults) return;
      bulkResults.hidden = false;
      bulkResults.innerHTML = rows
        .map(
          (r) =>
            `<div class="routing-bulk-row ${r.status}">` +
            `#${r.panel_id} ${r.panel_name || ''} — ${r.status}: ${r.message || ''}</div>`,
        )
        .join('');
    };

    bulkOp?.addEventListener('change', () => {
      if (bulkDirectionWrap) bulkDirectionWrap.hidden = bulkOp.value !== 'move';
    });

    document.getElementById('routing-bulk-preview')?.addEventListener('click', () => {
      const body = bulkPayload();
      if (!body.panel_ids.length) {
        alert('حداقل یک پنل را انتخاب کنید');
        return;
      }
      fetchJson('/admin/routing/api/bulk/preview', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
      }).then((res) => renderBulkResults(res.panels || []));
    });

    document.getElementById('routing-bulk-apply')?.addEventListener('click', () => {
      const body = bulkPayload();
      if (!body.panel_ids.length) {
        alert('حداقل یک پنل را انتخاب کنید');
        return;
      }
      if (!window.confirm('تغییرات گروهی روی ' + body.panel_ids.length + ' پنل اعمال شود؟')) return;
      fetchJson('/admin/routing/api/bulk/apply', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
      }).then((res) => renderBulkResults(res.results || []));
    });

    selectAllEl?.addEventListener('change', () => {
      panels.forEach((p) => {
        p._checked = !!selectAllEl.checked;
      });
      renderPanelList();
    });

    fetchJson('/admin/routing/api/panels')
      .then((res) => {
        // Default to all connected panels for bulk preview; operators may opt out.
        panels = (res.panels || []).map((p) => ({ ...p, _checked: true }));
        if (selectAllEl) selectAllEl.checked = panels.length > 0;
        renderPanelList();
      })
      .catch(() => {
        if (panelListEl) panelListEl.textContent = 'بارگذاری پنل‌ها ناموفق بود.';
      });
  }

  const outboundPage = document.querySelector('.outbound-page');
  if (outboundPage) {
    const csrf = outboundPage.getAttribute('data-csrf') || '';
    const byId = (id) => document.getElementById(id);
    const panelListEl = byId('outbound-panel-list');
    const selectAllEl = byId('outbound-select-all');
    const editorStatus = byId('outbound-editor-status');
    const listWrap = byId('outbound-list-wrap');
    const templateBody = byId('outbound-template-body');
    const templateCards = byId('outbound-template-cards');
    const subscriptionBody = byId('outbound-subscription-body');
    const subscriptionCards = byId('outbound-subscription-cards');
    let panels = [];
    let activePanelId = 0;
    let persistEnabled = false;
    let canaryPanelId = null;
    let createBusy = false;

    const selectedPanelIds = () => panels.filter((p) => p._checked).map((p) => Number(p.id));
    const modal = () => window.JaySubOutboundModal;
    const appendTextCell = (tr, text) => {
      const td = document.createElement('td');
      td.textContent = text == null ? '' : String(text);
      tr.appendChild(td);
    };

    const fetchJson = async (url, options = {}) => {
      const response = await fetch(url, { credentials: 'same-origin', ...options });
      let data;
      try {
        data = await response.json();
      } catch {
        throw new Error('پاسخ سرور JSON معتبر نبود؛ عملیات را دوباره اجرا نکنید و وضعیت پنل‌ها را بررسی کنید.');
      }
      if (!response.ok || data.ok === false) {
        throw new Error(data.error || 'درخواست با خطا مواجه شد (HTTP ' + response.status + ')');
      }
      return data;
    };

    const postJson = (url, payload) => fetchJson(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ _csrf: csrf, ...payload }),
    });

    const normalizedStatus = (status) => String(status || '').replace(/[^a-z0-9_-]/gi, '');
    const labelForStatus = (status) => ({
      ready: 'آماده افزودن', already_exists: 'از قبل موجود',
      tag_conflict: 'تداخل Tag', invalid: 'نامعتبر',
      connection_failed: 'عدم اتصال', failed: 'ناموفق',
      success: 'ایجاد شد', critical: 'خطای بحرانی',
    })[status] || status || 'نامشخص';

    const renderActionResults = (rows, summary) => {
      const box = byId('outbound-action-results');
      const list = byId('outbound-action-rows');
      if (!box || !list) return;
      box.hidden = false;
      byId('outbound-action-summary').textContent = summary;
      list.replaceChildren();
      (rows || []).forEach((r) => {
        const line = document.createElement('div');
        line.className = 'outbound-action-row outbound-status-' + normalizedStatus(r.status);
        const name = r.panel_name || ('#' + (r.panel_id || '?'));
        const details = r.message ? ' — ' + r.message : '';
        line.textContent = name + ': ' + labelForStatus(r.status) + details;
        list.appendChild(line);
      });
    };

    const statusClass = (p) => {
      const st = (p.connection_status || '').toLowerCase();
      if (st === 'ok' || st === 'connected') return 'ok';
      if (st === 'error' || st === 'failed') return 'err';
      return 'unknown';
    };

    const renderPanelList = () => {
      if (!panelListEl) return;
      panelListEl.replaceChildren();
      if (!panels.length) {
        panelListEl.textContent = 'پنلی ثبت نشده است.';
        if (selectAllEl) selectAllEl.checked = false;
        return;
      }
      panels.forEach((p) => {
        const row = document.createElement('div');
        row.className = 'routing-panel-item' + (p.id === activePanelId ? ' is-active' : '');
        const label = document.createElement('label');
        label.className = 'routing-panel-check';
        const cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.className = 'routing-panel-cb';
        cb.checked = !!p._checked;
        label.appendChild(cb);
        const meta = document.createElement('div');
        meta.className = 'routing-panel-meta';
        const strong = document.createElement('strong');
        strong.className = 'routing-panel-name';
        strong.textContent = p.name || '';
        const st = document.createElement('span');
        st.className = 'routing-status ' + statusClass(p);
        st.textContent = p.connection_status || 'unknown';
        meta.appendChild(strong);
        meta.appendChild(st);
        row.appendChild(label);
        row.appendChild(meta);
        meta.addEventListener('click', () => loadOutbounds(p.id));
        cb.addEventListener('click', (ev) => ev.stopPropagation());
        cb.addEventListener('change', () => {
          p._checked = cb.checked;
          if (selectAllEl) {
            selectAllEl.checked = panels.every((x) => x._checked);
            selectAllEl.indeterminate = panels.some((x) => x._checked) && !selectAllEl.checked;
          }
        });
        panelListEl.appendChild(row);
      });
      if (selectAllEl) {
        selectAllEl.checked = panels.every((x) => x._checked);
        selectAllEl.indeterminate = panels.some((x) => x._checked) && !selectAllEl.checked;
      }
    };

    const fillOutboundTable = (tbody, cardsEl, rows, showKind) => {
      tbody?.replaceChildren();
      cardsEl?.replaceChildren();
      (rows || []).forEach((ob) => {
        const prot = ob.protected ? 'محافظت‌شده' : 'عادی';
        const kind = ob.kind === 'subscription' ? 'اشتراک' : 'قالب';
        if (tbody) {
          const tr = document.createElement('tr');
          appendTextCell(tr, ob.priority);
          appendTextCell(tr, ob.tag);
          appendTextCell(tr, ob.protocol);
          appendTextCell(tr, showKind ? kind : prot);
          tbody.appendChild(tr);
        }
        if (cardsEl) {
          const card = document.createElement('article');
          card.className = 'routing-rule-card';
          const title = document.createElement('strong');
          title.textContent = ob.tag || '';
          const detail = document.createElement('span');
          detail.textContent = ' — ' + (ob.protocol || '') + ' (' + (showKind ? kind : prot) + ')';
          card.append(title, detail);
          cardsEl.appendChild(card);
        }
      });
    };

    const loadOutbounds = async (panelId) => {
      activePanelId = panelId;
      renderPanelList();
      if (editorStatus) editorStatus.textContent = 'در حال دریافت…';
      if (listWrap) listWrap.hidden = true;
      try {
        const data = await fetchJson('/admin/outbounds/api/panel/' + panelId + '/outbounds');
        if (activePanelId !== panelId) return;
        const tc = (data.template_outbounds || []).length;
        const sc = (data.subscription_outbounds || []).length;
        if (editorStatus) editorStatus.textContent = 'اتصال: ' + (data.connection_ok ? 'OK' : 'خطا') + ' — ' + tc + ' قالب، ' + sc + ' اشتراک';
        if (listWrap) listWrap.hidden = false;
        fillOutboundTable(templateBody, templateCards, data.template_outbounds || [], false);
        fillOutboundTable(subscriptionBody, subscriptionCards, data.subscription_outbounds || [], true);
      } catch (error) {
        if (editorStatus && activePanelId === panelId) editorStatus.textContent = error.message || 'خطا در خواندن پنل';
      }
    };

    byId('outbound-add-btn')?.addEventListener('click', () => modal()?.open());
    selectAllEl?.addEventListener('change', () => {
      panels.forEach((p) => { p._checked = !!selectAllEl.checked; });
      renderPanelList();
    });

    // "Create" is the only write entry point. Always read/preview all selected
    // panels first, fail closed on any conflict, then call the existing guarded
    // apply endpoint. The backend lock remains authoritative.
    document.addEventListener('jaysub:outbound-create', async (event) => {
      if (createBusy) return;
      const raw = event.detail?.json;
      const ids = selectedPanelIds();
      if (!ids.length) {
        modal()?.setState({ kind: 'error', message: 'حداقل یک پنل مقصد را انتخاب کنید.' });
        return;
      }
      if (typeof raw !== 'string' || !raw.trim()) {
        modal()?.setState({ kind: 'error', message: 'JSON اوتباند نامعتبر است.' });
        return;
      }

      createBusy = true;
      modal()?.setState({ busy: true, kind: 'info', message: 'در حال بررسی همه پنل‌های انتخاب‌شده…', rows: [] });
      const payload = { outbound_json: raw, panel_ids: ids };
      try {
        const preview = await postJson('/admin/outbounds/api/bulk/preview-json', payload);
        const rows = Array.isArray(preview.panels) ? preview.panels : [];
        if (rows.length !== ids.length) {
          modal()?.setState({ kind: 'error', message: 'پاسخ پیش‌نمایش برای همه پنل‌ها کامل نیست؛ هیچ تغییری اعمال نشد.', rows });
          return;
        }
        const invalid = rows.filter((r) => !['ready', 'already_exists'].includes(r.status));
        if (invalid.length) {
          modal()?.setState({
            kind: 'error',
            message: invalid.length + ' پنل آماده نیستند (تداخل Tag یا مشکل اتصال). برای ایمنی هیچ اوتباندی ایجاد نشد.',
            rows,
          });
          return;
        }
        if (!persistEnabled) {
          modal()?.setState({
            kind: 'warning',
            message: 'پیش‌نمایش ' + ids.length + ' پنل انجام شد، اما ذخیره واقعی Outbound هنوز قفل است؛ هیچ تغییری روی سرورها انجام نشد.',
            rows,
          });
          return;
        }
        if (canaryPanelId === null || ids.length !== 1 || ids[0] !== canaryPanelId) {
          modal()?.setState({
            kind: 'warning',
            message: 'ذخیره فقط برای یک پنل آزمایشی مجاز است. انتخاب پنل‌ها را محدود کنید؛ هیچ تغییری اعمال نشد.',
            rows,
          });
          return;
        }
        if (rows.every((r) => r.status === 'already_exists')) {
          modal()?.setState({ kind: 'info', message: 'این Outbound از قبل روی تمام پنل‌های انتخاب‌شده وجود دارد.', rows });
          return;
        }

        const ready = rows.filter((r) => r.status === 'ready').length;
        // A single explicit confirmation after all read-only checks.
        if (!window.confirm('ایجاد Outbound روی ' + ready + ' پنل؟ این عملیات تنظیمات Xray را تغییر می‌دهد.')) {
          modal()?.setState({ kind: 'info', message: 'عملیات لغو شد؛ هیچ تغییری انجام نشد.', rows });
          return;
        }

        modal()?.setState({ kind: 'info', message: 'در حال اعمال روی پنل‌ها؛ فرم را نبندید…', rows });
        const applied = await postJson('/admin/outbounds/api/bulk/apply-json', payload);
        const results = Array.isArray(applied.results) ? applied.results : [];
        const allOk = !applied.stopped && results.length === ids.length
          && results.every((r) => r.ok === true && ['success', 'already_exists'].includes(r.status));
        renderActionResults(
          results,
          allOk ? 'ایجاد Outbound روی پنل‌های انتخاب‌شده انجام شد.' : 'عملیات کامل نشد؛ وضعیت هر پنل را بررسی کنید.',
        );
        if (!allOk) {
          modal()?.setState({ kind: 'error', message: 'برخی پنل‌ها ناموفق بودند؛ نتیجه هر پنل را بررسی کنید. برای جلوگیری از تکرار، عملیات را بدون بررسی دوباره اجرا نکنید.', rows: results });
          return;
        }
        modal()?.setState({ kind: 'success', message: 'Outbound با موفقیت روی پنل‌های انتخاب‌شده ایجاد شد.', rows: results, close: true });
        if (activePanelId) void loadOutbounds(activePanelId);
      } catch (error) {
        modal()?.setState({
          kind: 'error',
          message: (error.message || 'خطای نامشخص') + ' — وضعیت پنل‌ها را قبل از تلاش مجدد بررسی کنید.',
        });
      } finally {
        createBusy = false;
        modal()?.setState({ busy: false });
      }
    });

    fetchJson('/admin/outbounds/api/panels')
      .then((res) => {
        persistEnabled = !!res.persist_enabled;
        const candidate = Number(res.canary_panel_id);
        canaryPanelId = persistEnabled && Number.isInteger(candidate) && candidate > 0
          ? candidate : null;
        // In locked mode all panels are selected for preview.
        // In canary mode ONLY the approved non-production target is selected.
        panels = (res.panels || []).map((p) => ({
          ...p, id: Number(p.id),
          _checked: canaryPanelId === null || Number(p.id) === canaryPanelId,
        }));
        const hint = outboundPage.querySelector('.outbound-safety-hint');
        if (hint && canaryPanelId !== null) {
          hint.textContent = 'حالت Canary: ذخیره فقط روی پنل آزمایشی #' + canaryPanelId
            + ' مجاز است؛ ایجاد گروهی روی پنل‌های عملیاتی همچنان قفل است.';
        }
        renderPanelList();
      })
      .catch((error) => {
        if (panelListEl) panelListEl.textContent = error.message || 'بارگذاری پنل‌ها ناموفق بود.';
      });

  }
});
