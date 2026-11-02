/* Admin Outbounds: native Basics/JSON modal modeled on 3x-ui v3.4.
 * Only prepares type-safe JSON. Network writes are exclusively through
 * existing guarded JaySub routes after a separate preview.
 */
(() => {
  'use strict';
  const dialog = document.getElementById('outbound-create-dialog');
  if (!dialog) return;
  const form = document.getElementById('outbound-create-form');
  const byId = (id) => document.getElementById(id);
  const value = (id) => (byId(id)?.value || '').trim();
  const checked = (id) => !!byId(id)?.checked;
  const error = byId('ob-modal-error');
  const jsonEditor = byId('ob-json-editor');
  let activeTab = 'basics';
  let security = 'none';
  let jsonChanged = false;
  const remote = () => !['freedom', 'blackhole'].includes(value('ob-protocol'));
  const show = (selector, shouldShow) => {
    dialog.querySelectorAll(selector).forEach((el) => {
      el.hidden = !shouldShow;
    });
  };
  const report = (message = '') => {
    if (!error) return;
    error.textContent = message;
    error.hidden = !message;
  };
  const jsonStringify = (object) => JSON.stringify(object, null, 2);
  const requireField = (id, label) => {
    const input = byId(id);
    if (!value(id)) {
      input?.focus();
      throw new Error(label + ' is required');
    }
    return value(id);
  };

  const changeSecurity = (next) => {
    security = next;
    dialog.querySelectorAll('[data-security]').forEach((button) => {
      const selected = button.dataset.security === next;
      button.classList.toggle('is-active', selected);
      button.setAttribute('aria-pressed', String(selected));
    });
    updateVisibility();
  };

  const updateVisibility = () => {
    const protocol = value('ob-protocol');
    const isRemote = remote();
    const isVless = protocol === 'vless';
    const isVmess = protocol === 'vmess';
    const network = value('ob-network');
    show('.ob-remote-only', isRemote);
    show('.ob-account-id', isVless || isVmess);
    show('.ob-vless-only', isVless);
    show('.ob-vmess-only', isVmess);
    show('.ob-credential', ['trojan', 'shadowsocks'].includes(protocol));
    show('.ob-ss-method', protocol === 'shadowsocks');
    show('.ob-tcp-only', isRemote && network === 'tcp');
    show('.ob-http-obfs-row', isRemote && network === 'tcp' && checked('ob-http-obfs'));
    show('.ob-network-options', isRemote && network !== 'tcp');
    show('.ob-security-options', isRemote && security !== 'none');
    show('.ob-reality-only', isRemote && security === 'reality');
    show('.ob-sockopts-row', isRemote && checked('ob-sockopts'));
    if (!isRemote && security !== 'none') changeSecurity('none');
  };

  const buildBasicOutbound = () => {
    const protocol = value('ob-protocol');
    const tag = requireField('ob-tag', 'Tag');
    if (['direct', 'block', 'api'].includes(tag.toLowerCase())) {
      throw new Error('This tag is reserved by Xray');
    }
    const node = { tag, protocol };
    const sendThrough = value('ob-send-through');
    if (remote() && sendThrough) node.sendThrough = sendThrough;

    if (!remote()) {
      node.settings = protocol === 'blackhole'
        ? { response: { type: 'none' } }
        : {};
      return node;
    }

    const address = requireField('ob-address', 'Address');
    const port = Number(value('ob-port'));
    if (!Number.isInteger(port) || port < 1 || port > 65535) {
      throw new Error('Port must be between 1 and 65535');
    }

    if (protocol === 'vless' || protocol === 'vmess') {
      const id = requireField('ob-id', 'UUID');
      if (!/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i.test(id)) {
        throw new Error('Enter a valid UUID');
      }
      if (protocol === 'vless') {
        // 3x-ui v3.4 VLESS outbound wire settings are flat; VMess uses vnext.
        node.settings = { address, port, id, flow: '', encryption: value('ob-encryption') || 'none' };
        if (value('ob-reverse-tag')) node.settings.reverse = { tag: value('ob-reverse-tag') };
      } else {
        node.settings = { vnext: [{ address, port, users: [
          { id, security: value('ob-vmess-security') || 'auto' }
        ] }] };
      }
    } else if (protocol === 'trojan') {
      node.settings = { servers: [{ address, port, password: requireField('ob-password', 'Password') }] };
    } else if (protocol === 'shadowsocks') {
      node.settings = { servers: [{
        address, port, password: requireField('ob-password', 'Password'),
        method: value('ob-method') || 'aes-128-gcm'
      }] };
    } else if (protocol === 'socks' || protocol === 'http') {
      node.settings = { servers: [{ address, port }] };
    } else {
      throw new Error('Protocol is not supported by Basics; use JSON');
    }

    const network = value('ob-network') || 'tcp';
    const stream = { network, security };
    if (network === 'tcp' && checked('ob-http-obfs')) {
      const host = value('ob-obfs-host');
      stream.tcpSettings = {
        header: { type: 'http', request: {
          version: '1.1', method: 'GET', path: ['/'],
          headers: { Host: host ? [host] : [] }
        } }
      };
    } else if (network === 'ws') {
      stream.wsSettings = { path: value('ob-path') || '/', headers: {} };
      if (value('ob-host')) stream.wsSettings.headers.Host = value('ob-host');
    } else if (network === 'grpc') {
      stream.grpcSettings = { serviceName: value('ob-path') || '' };
      if (value('ob-host')) stream.grpcSettings.authority = value('ob-host');
    } else if (network === 'httpupgrade') {
      stream.httpupgradeSettings = { path: value('ob-path') || '/', host: value('ob-host') };
    } else if (network === 'xhttp') {
      stream.xhttpSettings = { path: value('ob-path') || '/', host: value('ob-host') };
    }

    if (security !== 'none') {
      const serverName = requireField('ob-sni', 'SNI / Server Name');
      const fingerprint = value('ob-fingerprint') || 'chrome';
      if (security === 'tls') {
        stream.tlsSettings = { serverName, fingerprint, allowInsecure: false };
      } else {
        const publicKey = requireField('ob-public-key', 'Reality Public Key');
        stream.realitySettings = {
          show: false, fingerprint, serverName, publicKey,
          shortId: value('ob-short-id'), spiderX: value('ob-spider-x') || '/'
        };
      }
    }
    if (checked('ob-sockopts')) {
      stream.sockopt = { tcpFastOpen: checked('ob-tcp-fast-open') };
    }
    node.streamSettings = stream;
    return node;
  };

  const validateJson = (source) => {
    let outbound;
    try { outbound = JSON.parse(source); } catch {
      throw new Error('Outbound JSON is invalid');
    }
    if (!outbound || Array.isArray(outbound) || typeof outbound !== 'object') {
      throw new Error('Outbound JSON must be an object');
    }
    if (typeof outbound.tag !== 'string' || !outbound.tag.trim() ||
        typeof outbound.protocol !== 'string' || !outbound.protocol.trim()) {
      throw new Error('Tag and Protocol are required');
    }
    if (outbound.streamSettings?.wsSettings &&
        Object.prototype.hasOwnProperty.call(outbound.streamSettings.wsSettings, 'headers')) {
      const headers = outbound.streamSettings.wsSettings.headers;
      if (!headers || Array.isArray(headers) || typeof headers !== 'object' ||
          Object.values(headers).some((v) => typeof v !== 'string')) {
        throw new Error('wsSettings.headers must be a JSON object with string values');
      }
    }
    return outbound;
  };

  const switchTab = (next) => {
    report();
    if (next === 'json' && activeTab === 'basics' && !jsonChanged) {
      // Allow opening the JSON tab even if the Basics form is incomplete.
      try { jsonEditor.value = jsonStringify(buildBasicOutbound()); } catch {
        jsonEditor.value = '';
      }
    }
    activeTab = next;
    byId('outbound-basics').hidden = next !== 'basics';
    byId('outbound-json-tab').hidden = next !== 'json';
    for (const tab of ['basics', 'json']) {
      const button = byId('outbound-tab-' + tab);
      button.classList.toggle('is-active', tab === next);
      button.setAttribute('aria-selected', String(tab === next));
    }
  };

  const open = () => {
    report();
    form.reset();
    jsonEditor.value = '';
    jsonChanged = false;
    security = 'none';
    changeSecurity('none');
    activeTab = 'json';
    switchTab('basics');
    dialog.showModal();
    byId('ob-tag')?.focus();
  };
  window.JaySubOutboundModal = { open };

  byId('outbound-modal-close')?.addEventListener('click', () => dialog.close());
  byId('ob-modal-cancel')?.addEventListener('click', () => dialog.close());
  byId('outbound-tab-basics')?.addEventListener('click', () => switchTab('basics'));
  byId('outbound-tab-json')?.addEventListener('click', () => switchTab('json'));
  byId('ob-protocol')?.addEventListener('change', updateVisibility);
  byId('ob-network')?.addEventListener('change', updateVisibility);
  byId('ob-http-obfs')?.addEventListener('change', updateVisibility);
  byId('ob-sockopts')?.addEventListener('change', updateVisibility);
  dialog.querySelectorAll('[data-security]').forEach((button) =>
    button.addEventListener('click', () => changeSecurity(button.dataset.security)));
  byId('ob-tcp-masks')?.addEventListener('click', () => {
    report('Advanced TCP Masks require native Xray settings. Edit this option in the JSON tab.');
    switchTab('json');
  });
  jsonEditor.addEventListener('input', () => { jsonChanged = true; });
  dialog.addEventListener('click', (event) => {
    if (event.target === dialog) dialog.close();
  });
  form.addEventListener('submit', (event) => {
    event.preventDefault();
    report();
    try {
      const json = activeTab === 'json'
        ? jsonEditor.value
        : jsonStringify(buildBasicOutbound());
      validateJson(json);
      document.dispatchEvent(new CustomEvent('jaysub:outbound-built', { detail: { json } }));
      dialog.close();
    } catch (ex) {
      report(ex.message || String(ex));
    }
  });
  updateVisibility();
})();
