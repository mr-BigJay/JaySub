'use strict';

const assert = require('node:assert/strict');
const { test } = require('node:test');
const { parse } = require('../../public/assets/js/outbound-import.js');

const uuid = '123e4567-e89b-42d3-a456-426614174000';

test('imports VLESS TLS WebSocket, retaining empty headers object', () => {
  const link = 'vless://' + uuid + '@vless.example.com:443?security=tls&sni=cdn.example.com&fp=chrome&type=ws&path=%2Fws#VLESS-DE';
  const node = parse(link);
  assert.equal(node.tag, 'VLESS-DE');
  assert.equal(node.protocol, 'vless');
  assert.equal(node.settings.address, 'vless.example.com');
  assert.equal(node.settings.port, 443);
  assert.equal(node.settings.id, uuid);
  assert.equal(node.streamSettings.network, 'ws');
  assert.equal(node.streamSettings.security, 'tls');
  assert.equal(node.streamSettings.tlsSettings.serverName, 'cdn.example.com');
  assert.equal(node.streamSettings.wsSettings.path, '/ws');
  assert.deepEqual(node.streamSettings.wsSettings.headers, {});
  assert.match(JSON.stringify(node), /"headers":\{\}/);
});

test('imports VLESS REALITY with its keys preserved', () => {
  const node = parse('vless://' + uuid + '@reality.example.com:2053?type=tcp&security=reality&sni=www.example.org&pbk=pubtest&sid=abcd&spx=%2F&fp=chrome&flow=xtls-rprx-vision#reality');
  assert.equal(node.settings.flow, 'xtls-rprx-vision');
  assert.equal(node.streamSettings.security, 'reality');
  assert.equal(node.streamSettings.realitySettings.publicKey, 'pubtest');
  assert.equal(node.streamSettings.realitySettings.shortId, 'abcd');
  assert.equal(node.streamSettings.realitySettings.serverName, 'www.example.org');
  assert.equal(node.streamSettings.tcpSettings.header.type, 'none');
});

test('imports VLESS gRPC serviceName', () => {
  const node = parse('vless://' + uuid + '@grpc.example.com:443?type=grpc&serviceName=grpc-test&security=tls&sni=grpc.example.com');
  assert.equal(node.streamSettings.grpcSettings.serviceName, 'grpc-test');
});

test('imports base64 VMess JSON retaining vnext credential and transport', () => {
  const vmess = {
    v: '2', ps: 'vm-test', add: 'vm.example.com', port: '443',
    id: uuid, aid: '0', net: 'ws', host: 'cdn.example.com',
    path: '/vm', tls: 'tls', sni: 'cdn.example.com', scy: 'auto'
  };
  const link = 'vmess://' + Buffer.from(JSON.stringify(vmess), 'utf8').toString('base64');
  const node = parse(link);
  assert.equal(node.protocol, 'vmess');
  assert.equal(node.settings.vnext[0].users[0].id, uuid);
  assert.equal(node.streamSettings.wsSettings.headers.Host, 'cdn.example.com');
  assert.equal(node.streamSettings.security, 'tls');
});

test('imports Trojan TLS and gRPC with password', () => {
  const node = parse('trojan://fakeSecret@trojan.example.com:443?type=grpc&serviceName=hello&security=tls&sni=sni.example.com#trojan-node');
  assert.equal(node.protocol, 'trojan');
  assert.equal(node.settings.servers[0].password, 'fakeSecret');
  assert.equal(node.streamSettings.grpcSettings.serviceName, 'hello');
  assert.equal(node.streamSettings.tlsSettings.serverName, 'sni.example.com');
});

test('imports SIP002 Shadowsocks base64 userinfo', () => {
  const creds = Buffer.from('aes-256-gcm:fakePassword').toString('base64url');
  const node = parse('ss://' + creds + '@ss.example.com:8388#ss-node');
  assert.equal(node.protocol, 'shadowsocks');
  assert.equal(node.settings.servers[0].method, 'aes-256-gcm');
  assert.equal(node.settings.servers[0].password, 'fakePassword');
  assert.equal(node.settings.servers[0].port, 8388);
});

test('rejects unsupported and ambiguous inputs without guessing', () => {
  assert.throws(() => parse('wireguard://fake@server:1234'), /not supported safely/);
  assert.throws(() => parse('hysteria2://fake@server:443'), /not supported safely/);
  assert.throws(() => parse('vless://' + uuid + '@example.com:443?type=kcp'), /Unsupported transport/);
  assert.throws(() => parse('vless://' + uuid + '@example.com:443?security=reality'), /Reality SNI/);
  assert.throws(() => parse('vless://' + uuid + '@example.com:99999'), /Invalid vless|Port/);
  assert.throws(() => parse('ss://YWVzLTI1Ni1nY206cA@ss.example.com:8388?plugin=obfs-local'), /plugins are not supported/);
  assert.throws(() => parse('not-a-link'), /supported connection link/);
});
