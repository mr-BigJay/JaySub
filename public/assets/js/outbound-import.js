/* Browser-only 3x-ui-style share link -> Xray Outbound JSON importer.
 * Does not fetch remote URLs, send credentials or write panel settings.
 * Fail closed for unimplemented/ambiguous schemes and transports.
 */
(function (root, factory) {
  'use strict';
  const importer = factory();
  if (root) root.JaySubOutboundImport = importer;
  if (typeof module === 'object' && module.exports) module.exports = importer;
})(typeof window !== 'undefined' ? window : null, function () {
  'use strict';

  const text = (value) => String(value == null ? '' : value).trim();
  const required = (value, name) => {
    const s = text(value);
    if (!s) throw new Error(name + ' is required');
    return s;
  };
  const portNumber = (value) => {
    const p = Number(value);
    if (!Number.isInteger(p) || p <= 0 || p > 65535) {
      throw new Error('Port must be between 1 and 65535');
    }
    return p;
  };
  const base64Decode = (encoded) => {
    const raw = text(encoded).replace(/-/g, '+').replace(/_/g, '/');
    if (!raw || !/^[A-Za-z0-9+/]*={0,2}$/.test(raw)) throw new Error('Invalid Base64');
    const padded = raw.padEnd(Math.ceil(raw.length / 4) * 4, '=');
    if (typeof atob !== 'function') throw new Error('Base64 decoder is unavailable');
    let bytes;
    try {
      bytes = Uint8Array.from(atob(padded), (ch) => ch.charCodeAt(0));
    } catch {
      throw new Error('Invalid Base64');
    }
    return new TextDecoder('utf-8', { fatal: true }).decode(bytes);
  };
  const decode = (value) => {
    try { return decodeURIComponent(value || ''); }
    catch { throw new Error('Invalid percent-encoding in link'); }
  };
  const uuidValid = (id) => /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(id);
  const tagFrom = (remark, protocol, host) => {
    const candidate = text(remark || protocol + '-' + host)
      .replace(/[^a-zA-Z0-9_.-]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 100);
    const tag = candidate || 'import-' + protocol;
    if (['direct', 'block', 'api'].includes(tag.toLowerCase())) return 'import-' + tag;
    return tag;
  };
  const hostOf = (url) => required(url.hostname.replace(/^\[|\]$/g, ''), 'Address');

  function transport(stream, network, options) {
    const net = text(network || 'tcp').toLowerCase();
    stream.network = net === 'raw' ? 'tcp' : net;
    const path = text(options.path);
    const host = text(options.host);
    const headerType = text(options.headerType || 'none').toLowerCase();
    if (stream.network === 'tcp') {
      if (!['none', 'http'].includes(headerType)) throw new Error('Unsupported TCP header type');
      if (headerType === 'http') {
        stream.tcpSettings = { header: {
          type: 'http',
          request: { version: '1.1', method: 'GET', path: path ? [path] : ['/'],
            headers: { Host: host ? [host] : [] } }
        } };
      } else {
        stream.tcpSettings = { header: { type: 'none' } };
      }
    } else if (stream.network === 'ws') {
      stream.wsSettings = { path: path || '/', headers: {} };
      if (host) stream.wsSettings.headers.Host = host;
    } else if (stream.network === 'grpc') {
      stream.grpcSettings = { serviceName: path, multiMode: false };
      if (host) stream.grpcSettings.authority = host;
    } else if (stream.network === 'httpupgrade') {
      stream.httpupgradeSettings = { path: path || '/', host };
    } else if (stream.network === 'xhttp') {
      stream.xhttpSettings = { path: path || '/', host };
    } else {
      throw new Error('Unsupported transport "' + net + '". Use the JSON tab for advanced connections.');
    }
  }

  function security(stream, securityName, options) {
    const mode = text(securityName || 'none').toLowerCase();
    if (!['none', 'tls', 'reality'].includes(mode)) {
      throw new Error('Unsupported security "' + mode + '". Use JSON for advanced settings.');
    }
    stream.security = mode;
    if (mode === 'tls') {
      const tls = { serverName: text(options.sni || options.address), allowInsecure: false };
      if (options.fp) tls.fingerprint = text(options.fp);
      const alpn = text(options.alpn).split(',').map((v) => v.trim()).filter(Boolean);
      if (alpn.length) tls.alpn = alpn;
      stream.tlsSettings = tls;
    } else if (mode === 'reality') {
      stream.realitySettings = {
        show: false, fingerprint: text(options.fp || 'chrome'),
        serverName: required(options.sni, 'Reality SNI'),
        publicKey: required(options.pbk, 'Reality public key'),
        shortId: text(options.sid), spiderX: text(options.spx || '/')
      };
    }
  }

  function fromStandardUrl(link, protocol) {
    let url;
    try { url = new URL(link); }
    catch { throw new Error('Invalid ' + protocol + ' connection link'); }
    if (url.protocol !== protocol + ':') throw new Error('Protocol mismatch');
    const address = hostOf(url);
    const port = portNumber(url.port);
    const query = url.searchParams;
    const remark = decode((url.hash || '').replace(/^#/, ''));
    const tag = tagFrom(remark, protocol, address);
    const node = { tag, protocol, settings: {} };

    if (protocol === 'vless') {
      const id = required(decode(url.username), 'VLESS UUID');
      if (!uuidValid(id)) throw new Error('Invalid VLESS UUID');
      node.settings = {
        address, port, id,
        flow: text(query.get('flow')),
        encryption: text(query.get('encryption') || 'none')
      };
    } else if (protocol === 'trojan') {
      node.settings = {
        servers: [{ address, port, password: required(decode(url.username), 'Trojan password') }]
      };
    }

    const stream = {};
    const securityType = query.get('security') || (protocol === 'trojan' ? 'tls' : 'none');
    transport(stream, query.get('type') || query.get('network') || 'tcp', {
      path: query.get('path'),
      host: query.get('host'),
      headerType: query.get('headerType')
    });
    security(stream, securityType, {
      address, sni: query.get('sni') || query.get('peer'),
      fp: query.get('fp'), pbk: query.get('pbk'), sid: query.get('sid'),
      spx: query.get('spx'), alpn: query.get('alpn')
    });
    node.streamSettings = stream;
    return node;
  }

  function fromVmess(link) {
    const encoded = link.slice('vmess://'.length).split('#')[0].split('?')[0];
    let data;
    try { data = JSON.parse(base64Decode(encoded)); }
    catch (err) { throw new Error('Invalid VMess Base64 JSON: ' + (err.message || err)); }
    if (!data || Array.isArray(data) || typeof data !== 'object') {
      throw new Error('VMess payload must contain a JSON object');
    }
    const address = required(data.add, 'Address');
    const port = portNumber(data.port);
    const id = required(data.id, 'VMess UUID');
    if (!uuidValid(id)) throw new Error('Invalid VMess UUID');
    const aid = Number(data.aid || 0);
    if (!Number.isInteger(aid) || aid < 0) throw new Error('Invalid alterId');
    const node = {
      tag: tagFrom(data.ps, 'vmess', address),
      protocol: 'vmess',
      settings: { vnext: [{ address, port, users: [
        { id, alterId: aid, security: text(data.scy || 'auto') }
      ] }] }
    };
    const stream = {};
    transport(stream, data.net || 'tcp', {
      path: data.path, host: data.host, headerType: data.type
    });
    security(stream, data.tls || 'none', {
      address, sni: data.sni || data.host, fp: data.fp, alpn: data.alpn,
      pbk: data.pbk, sid: data.sid, spx: data.spx
    });
    node.streamSettings = stream;
    return node;
  }

  function fromShadowsocks(link) {
    let payload = link.slice('ss://'.length);
    const hashIndex = payload.indexOf('#');
    const remark = hashIndex >= 0 ? decode(payload.slice(hashIndex + 1)) : '';
    if (hashIndex >= 0) payload = payload.slice(0, hashIndex);
    const queryIndex = payload.indexOf('?');
    if (queryIndex >= 0) {
      const qs = new URLSearchParams(payload.slice(queryIndex + 1));
      if (qs.has('plugin')) throw new Error('Shadowsocks plugins are not supported by the importer; use JSON');
      if ([...qs.keys()].length) throw new Error('Unsupported Shadowsocks parameters; use JSON');
      payload = payload.slice(0, queryIndex);
    }
    let userinfo, address, port;
    const at = payload.lastIndexOf('@');
    if (at >= 0) {
      const left = payload.slice(0, at);
      let url;
      try { url = new URL('ss://x@' + payload.slice(at + 1)); }
      catch { throw new Error('Invalid Shadowsocks address or port'); }
      address = hostOf(url);
      port = portNumber(url.port);
      userinfo = left.includes(':') ? decode(left) : base64Decode(left);
    } else {
      const decoded = base64Decode(payload);
      const match = decoded.match(/^(.+?)@(\[[^\]]+\]|[^:]+):(\d+)$/);
      if (!match) throw new Error('Invalid Shadowsocks Base64 payload');
      userinfo = match[1];
      address = match[2].replace(/^\[|\]$/g, '');
      port = portNumber(match[3]);
    }
    const separator = userinfo.indexOf(':');
    if (separator <= 0) throw new Error('Shadowsocks userinfo must include method:password');
    const method = required(userinfo.slice(0, separator), 'Encryption method');
    const password = required(userinfo.slice(separator + 1), 'Password');
    return {
      tag: tagFrom(remark, 'ss', address),
      protocol: 'shadowsocks',
      settings: { servers: [{ address, port, method, password }] },
      streamSettings: { network: 'tcp', security: 'none', tcpSettings: { header: { type: 'none' } } }
    };
  }

  function parse(link) {
    const input = required(link, 'Connection link');
    // One link only: never accidentally merge several credentials into one node.
    if (/\s/.test(input)) throw new Error('Import one connection link at a time');
    const scheme = input.match(/^([a-z][a-z0-9+.-]*):\/\//i)?.[1]?.toLowerCase();
    if (!scheme) throw new Error('Paste a supported connection link');
    if (input.length > 16384) throw new Error('Connection link is too long');
    if (scheme === 'vless' || scheme === 'trojan') return fromStandardUrl(input, scheme);
    if (scheme === 'vmess') return fromVmess(input);
    if (scheme === 'ss') return fromShadowsocks(input);
    if (scheme === 'hysteria2' || scheme === 'hy2' || scheme === 'wireguard') {
      throw new Error(scheme + ' link import is not supported safely yet. Enter a verified Xray outbound in JSON.');
    }
    throw new Error('Unsupported connection scheme: ' + scheme);
  }

  return { parse };
});
