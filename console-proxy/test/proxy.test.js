'use strict';

const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const http = require('http');
const { WebSocket } = require('ws');
const { server, redis, redeemTicket, TICKET_REGEX, PinnedHttpsAgent } = require('../server.js');
const https = require('https');
const crypto = require('crypto');
const { execSync } = require('child_process');
const { WebSocketServer } = require('ws');

test('TICKET_REGEX strictly enforces safe alphanumeric/dash format and prevents glob patterns', () => {
  assert.strictEqual(TICKET_REGEX.test('*'), false);
  assert.strictEqual(TICKET_REGEX.test('?'), false);
  assert.strictEqual(TICKET_REGEX.test('*console:ticket:*'), false);
  assert.strictEqual(TICKET_REGEX.test('short'), false);
  assert.strictEqual(TICKET_REGEX.test('valid-ticket-with-proper-length-1234567890'), true);
  assert.strictEqual(TICKET_REGEX.test('a'.repeat(64)), true);
  assert.strictEqual(TICKET_REGEX.test('a'.repeat(129)), false);
  assert.strictEqual(TICKET_REGEX.test('ticket;with;delimiters'), false);
  assert.strictEqual(TICKET_REGEX.test('ticket\nnewline'), false);
});

test('redeemTicket immediately returns null for invalid formats without querying Redis', async () => {
  assert.strictEqual(await redeemTicket('*'), null);
  assert.strictEqual(await redeemTicket('?'), null);
  assert.strictEqual(await redeemTicket(''), null);
  assert.strictEqual(await redeemTicket(null), null);
});

test('WebSocket handshake rejected with 400 when ticket format contains wildcard or invalid characters', async () => {
  const address = server.address();
  const port = address.port;

  await new Promise((resolve) => {
    const ws = new WebSocket(`ws://127.0.0.1:${port}/console?ticket=*`);
    ws.on('error', (err) => {
      assert.ok(err.message.includes('400') || err.message.includes('Unexpected server response'));
      resolve();
    });
    ws.on('open', () => {
      assert.fail('Should not connect with wildcard ticket');
    });
  });
});

test('GET /health returns 200 ok', async () => {
  const address = server.address();
  const port = address.port;

  const res = await new Promise((resolve, reject) => {
    http.get(`http://127.0.0.1:${port}/health`, (response) => {
      let data = '';
      response.on('data', chunk => { data += chunk; });
      response.on('end', () => resolve({ statusCode: response.statusCode, data }));
    }).on('error', reject);
  });

  assert.strictEqual(res.statusCode, 200);
  const json = JSON.parse(res.data);
  assert.strictEqual(json.status, 'ok');
  assert.strictEqual(json.service, 'console-proxy');
});

test('WebSocket handshake rejected with 401 when ticket query param is missing', async () => {
  const address = server.address();
  const port = address.port;

  await new Promise((resolve) => {
    const ws = new WebSocket(`ws://127.0.0.1:${port}/console`);
    ws.on('error', (err) => {
      assert.ok(err.message.includes('401') || err.message.includes('Unexpected server response'));
      resolve();
    });
    ws.on('open', () => {
      assert.fail('Should not connect without ticket');
    });
  });
});

test('WebSocket handshake rejected with 403 when ticket does not exist in Redis', async () => {
  const address = server.address();
  const port = address.port;

  await new Promise((resolve) => {
    const ws = new WebSocket(`ws://127.0.0.1:${port}/console?ticket=non-existent-ticket-9999`);
    ws.on('error', (err) => {
      assert.ok(err.message.includes('403') || err.message.includes('Unexpected server response'));
      resolve();
    });
    ws.on('open', () => {
      assert.fail('Should not connect with invalid ticket');
    });
  });
});

test('PinnedHttpsAgent validates matching server SPKI pin and rejects mismatched pin', async () => {
  const os = require('os');
  const path = require('path');
  const fs = require('fs');

  const tmpDir = fs.mkdtempSync(path.join(os.tmpdir(), 'proxy-test-'));
  const keyPath = path.join(tmpDir, 'key.pem');
  const certPath = path.join(tmpDir, 'cert.pem');

  let testWss;
  let tlsServer;

  try {
    execSync(`openssl req -x509 -newkey rsa:2048 -nodes -keyout "${keyPath}" -out "${certPath}" -days 1 -subj "/CN=127.0.0.1" 2>/dev/null`);
    const key = fs.readFileSync(keyPath);
    const cert = fs.readFileSync(certPath);

    const x509 = new crypto.X509Certificate(cert);
    const spkiDer = x509.publicKey.export({ type: 'spki', format: 'der' });
    const validPin = 'sha256//' + crypto.createHash('sha256').update(spkiDer).digest('base64');
    const invalidPin = 'sha256//' + crypto.createHash('sha256').update('wrong-data').digest('base64');

    tlsServer = https.createServer({ key, cert });
    testWss = new WebSocketServer({ server: tlsServer });
    testWss.on('connection', (client) => {
      client.send('connected-to-tls-upstream');
    });

    await new Promise((resolve) => tlsServer.listen(0, '127.0.0.1', resolve));
    const tlsPort = tlsServer.address().port;

    // 1. Connection with matching pin should succeed
    const validResult = await new Promise((resolve, reject) => {
      const agent = new PinnedHttpsAgent(validPin, { rejectUnauthorized: false });
      const ws = new WebSocket(`wss://127.0.0.1:${tlsPort}`, {
        rejectUnauthorized: false,
        agent,
      });

      ws.on('open', () => {
        ws.close();
        resolve(true);
      });
      ws.on('error', reject);
    });
    assert.strictEqual(validResult, true);

    // 2. Connection with mismatched pin should be destroyed with pin mismatch error
    const mismatchError = await new Promise((resolve) => {
      const agent = new PinnedHttpsAgent(invalidPin, { rejectUnauthorized: false });
      const ws = new WebSocket(`wss://127.0.0.1:${tlsPort}`, {
        rejectUnauthorized: false,
        agent,
      });

      ws.on('open', () => {
        ws.close();
        assert.fail('Should not connect with mismatched pin');
      });
      ws.on('error', (err) => {
        resolve(err);
      });
    });

    assert.ok(mismatchError.message.includes('Server public key pin mismatch'), `Unexpected error message: ${mismatchError.message}`);
  } finally {
    if (testWss) testWss.close();
    if (tlsServer) tlsServer.close();
    fs.rmSync(tmpDir, { recursive: true, force: true });
  }
});

after(() => {
  server.close();
  redis.disconnect();
});
