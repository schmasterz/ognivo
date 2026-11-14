'use strict';

const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const http = require('http');
const { WebSocket } = require('ws');
const { server, redis, redeemTicket, TICKET_REGEX, PinnedHttpsAgent, handleIncusSession } = require('../server.js');
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

function createTestTlsServer() {
  const os = require('os');
  const path = require('path');
  const fs = require('fs');

  const tmpDir = fs.mkdtempSync(path.join(os.tmpdir(), 'proxy-test-'));
  const keyPath = path.join(tmpDir, 'key.pem');
  const certPath = path.join(tmpDir, 'cert.pem');

  execSync(`openssl req -x509 -newkey rsa:2048 -nodes -keyout "${keyPath}" -out "${certPath}" -days 1 -subj "/CN=127.0.0.1" 2>/dev/null`);
  const key = fs.readFileSync(keyPath);
  const cert = fs.readFileSync(certPath);

  const x509 = new crypto.X509Certificate(cert);
  const spkiDer = x509.publicKey.export({ type: 'spki', format: 'der' });
  const validPin = 'sha256//' + crypto.createHash('sha256').update(spkiDer).digest('base64');
  const invalidPin = 'sha256//' + crypto.createHash('sha256').update('wrong-data').digest('base64');

  const receivedChunks = [];
  const tlsServer = https.createServer({ key, cert });
  tlsServer.on('secureConnection', (socket) => {
    socket.on('data', (chunk) => {
      receivedChunks.push(chunk);
    });
  });

  const testWss = new WebSocketServer({ server: tlsServer });
  testWss.on('connection', (client) => {
    client.send('connected-to-tls-upstream');
  });

  const cleanup = () => {
    testWss.close();
    tlsServer.close();
    fs.rmSync(tmpDir, { recursive: true, force: true });
  };

  return { tlsServer, testWss, key, cert, validPin, invalidPin, receivedChunks, cleanup };
}

test('PinnedHttpsAgent: (a) matching SPKI pin succeeds', async () => {
  const fixture = createTestTlsServer();
  try {
    await new Promise((resolve) => fixture.tlsServer.listen(0, '127.0.0.1', resolve));
    const tlsPort = fixture.tlsServer.address().port;

    const agent = new PinnedHttpsAgent(fixture.validPin, { rejectUnauthorized: false });
    assert.strictEqual(agent.checkServerIdentity('127.0.0.1', { raw: fixture.cert }), undefined);

    const validResult = await new Promise((resolve, reject) => {
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
  } finally {
    fixture.cleanup();
  }
});

test('PinnedHttpsAgent: (b) mismatched SPKI pin fails during TLS handshake before application data is sent', async () => {
  const fixture = createTestTlsServer();
  try {
    await new Promise((resolve) => fixture.tlsServer.listen(0, '127.0.0.1', resolve));
    const tlsPort = fixture.tlsServer.address().port;

    const agent = new PinnedHttpsAgent(fixture.invalidPin, { rejectUnauthorized: false });
    const directCheck = agent.checkServerIdentity('127.0.0.1', { raw: fixture.cert });
    assert.ok(directCheck instanceof Error);
    assert.ok(directCheck.message.includes('Server public key pin mismatch'));

    const mismatchError = await new Promise((resolve) => {
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
    await new Promise((resolve) => setTimeout(resolve, 50));
    assert.strictEqual(fixture.receivedChunks.length, 0, 'Server should not receive any application data when pin mismatches');
  } finally {
    fixture.cleanup();
  }
});

test('PinnedHttpsAgent: (c) missing expectedPin fails closed', async () => {
  const fixture = createTestTlsServer();
  try {
    await new Promise((resolve) => fixture.tlsServer.listen(0, '127.0.0.1', resolve));
    const tlsPort = fixture.tlsServer.address().port;

    for (const emptyPin of [undefined, null, '', '   ']) {
      const agent = new PinnedHttpsAgent(emptyPin, { rejectUnauthorized: false });
      const err = agent.checkServerIdentity('127.0.0.1', { raw: fixture.cert });
      assert.ok(err instanceof Error);
      assert.strictEqual(err.message, 'Missing expected server public key pin');
    }

    const missingError = await new Promise((resolve) => {
      const agent = new PinnedHttpsAgent('', { rejectUnauthorized: false });
      const ws = new WebSocket(`wss://127.0.0.1:${tlsPort}`, {
        rejectUnauthorized: false,
        agent,
      });

      ws.on('open', () => {
        ws.close();
        assert.fail('Should not connect with empty pin');
      });
      ws.on('error', (err) => {
        resolve(err);
      });
    });

    assert.ok(missingError.message.includes('Missing expected server public key pin'), `Unexpected error message: ${missingError.message}`);
  } finally {
    fixture.cleanup();
  }
});

test('handleIncusSession does not leak expected or actual pin hashes to client WebSocket on error', async () => {
  const fixture = createTestTlsServer();
  try {
    await new Promise((resolve) => fixture.tlsServer.listen(0, '127.0.0.1', resolve));
    const tlsPort = fixture.tlsServer.address().port;

    const EventEmitter = require('events');
    const sentMessages = [];
    const mockClientWs = new EventEmitter();
    mockClientWs.readyState = WebSocket.OPEN;
    mockClientWs.send = (msg) => sentMessages.push(msg);

    const closedPromise = new Promise((resolve) => {
      mockClientWs.close = (code, reason) => {
        mockClientWs.readyState = WebSocket.CLOSING;
        mockClientWs.closed = { code, reason };
        resolve();
      };
    });

    handleIncusSession(mockClientWs, {
      host_endpoint: `https://127.0.0.1:${tlsPort}`,
      websocket_path: '/1.0/operations/123/websocket',
      server_public_key_pin: fixture.invalidPin,
    });

    await closedPromise;

    assert.strictEqual(sentMessages.length, 1);
    assert.ok(sentMessages[0].includes('[Console Error: Hypervisor upstream connection failed]'));
    assert.ok(!sentMessages[0].includes(fixture.invalidPin), 'Should not leak expected pin');
    assert.ok(!sentMessages[0].includes(fixture.validPin), 'Should not leak actual pin');
    assert.ok(!sentMessages[0].includes('sha256//'), 'Should not leak pin hashes');
    assert.ok(!sentMessages[0].includes('mismatch'), 'Should not leak pin mismatch details');
    assert.strictEqual(mockClientWs.closed.code, 1011);
  } finally {
    fixture.cleanup();
  }
});

after(() => {
  server.close();
  redis.disconnect();
});
