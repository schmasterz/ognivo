'use strict';

const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const http = require('http');
const { WebSocket } = require('ws');
const { server, redis, redeemTicket, extractTicket, TICKET_REGEX, normalizeOrigin, parseAllowedOrigins, isOriginAllowed, hasValidSessionExpiry, PinnedHttpsAgent, handleIncusSession, handleFakeSession, touchRedisSession, isFakeDriverAllowed, encryptTicketPayload, decryptTicketPayload } = require('../server.js');
const https = require('https');
const crypto = require('crypto');
const { execSync, spawnSync } = require('child_process');
const path = require('path');
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
    const ws = new WebSocket(`ws://127.0.0.1:${port}/console`, ['ognivo-terminal', 'ticket.*'], {
      headers: { Origin: 'http://localhost' },
    });
    ws.on('unexpected-response', (_req, res) => {
      assert.strictEqual(res.statusCode, 400);
      res.resume();
      resolve();
    });
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

test('WebSocket handshake rejected with 401 when ticket subprotocol is missing', async () => {
  const address = server.address();
  const port = address.port;

  await new Promise((resolve) => {
    const ws = new WebSocket(`ws://127.0.0.1:${port}/console`, ['ognivo-terminal'], {
      headers: { Origin: 'http://localhost' },
    });
    ws.on('unexpected-response', (_req, res) => {
      assert.strictEqual(res.statusCode, 401);
      res.resume();
      resolve();
    });
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
  const restoreRedis = stubRedis({
    status: 'ready',
    call: async () => null,
  });

  try {
    const address = server.address();
    const port = address.port;

    await new Promise((resolve) => {
      const ws = new WebSocket(
        `ws://127.0.0.1:${port}/console`,
        ['ognivo-terminal', 'ticket.non-existent-ticket-9999'],
        { headers: { Origin: 'http://localhost' } }
      );
      ws.on('unexpected-response', (_req, res) => {
        assert.strictEqual(res.statusCode, 403);
        res.resume();
        resolve();
      });
      ws.on('error', (err) => {
        assert.ok(err.message.includes('403') || err.message.includes('Unexpected server response'));
        resolve();
      });
      ws.on('open', () => {
        assert.fail('Should not connect with invalid ticket');
      });
    });
  } finally {
    restoreRedis();
  }
});

function createTestTlsServer(serverOptions = {}) {
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
  const peerCertificates = [];
  const tlsServer = https.createServer({ key, cert, ...serverOptions });
  tlsServer.on('secureConnection', (socket) => {
    peerCertificates.push(socket.getPeerCertificate());
    socket.on('data', (chunk) => {
      receivedChunks.push(chunk);
    });
  });

  const testWss = new WebSocketServer({ server: tlsServer });
  testWss.on('connection', (client) => {
    client.send('connected-to-tls-upstream');
  });

  const cleanup = () => {
    for (const ws of testWss.clients) {
      try { ws.terminate(); } catch {}
    }
    testWss.close();
    if (typeof tlsServer.closeAllConnections === 'function') {
      tlsServer.closeAllConnections();
    }
    tlsServer.close();
    fs.rmSync(tmpDir, { recursive: true, force: true });
  };

  return { tlsServer, testWss, key, cert, validPin, invalidPin, receivedChunks, peerCertificates, tmpDir, cleanup };
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

test('handleIncusSession presents client_certificate and client_private_key from payload during TLS handshake', async () => {
  const path = require('path');
  const fs = require('fs');
  const EventEmitter = require('events');

  const fixture = createTestTlsServer({ requestCert: true, rejectUnauthorized: false });
  try {
    await new Promise((resolve) => fixture.tlsServer.listen(0, '127.0.0.1', resolve));
    const tlsPort = fixture.tlsServer.address().port;

    const clientKeyPath = path.join(fixture.tmpDir, 'client-key.pem');
    const clientCertPath = path.join(fixture.tmpDir, 'client-cert.pem');
    execSync(`openssl req -x509 -newkey rsa:2048 -nodes -keyout "${clientKeyPath}" -out "${clientCertPath}" -days 1 -subj "/CN=panel-mtls-client" 2>/dev/null`);
    const clientKey = fs.readFileSync(clientKeyPath, 'utf8');
    const clientCert = fs.readFileSync(clientCertPath, 'utf8');

    const mockClientWs = new EventEmitter();
    mockClientWs.readyState = WebSocket.OPEN;
    mockClientWs.send = () => {};
    mockClientWs.close = () => {};

    const upstreamConnected = new Promise((resolve) => {
      fixture.testWss.once('connection', (ws) => {
        resolve(ws);
      });
    });

    handleIncusSession(mockClientWs, {
      host_endpoint: `https://127.0.0.1:${tlsPort}`,
      websocket_path: '/1.0/operations/123/websocket',
      server_public_key_pin: fixture.validPin,
      client_certificate: clientCert,
      client_private_key: clientKey,
    });

    const upstreamWs = await upstreamConnected;
    assert.ok(upstreamWs, 'Upstream WebSocket should have connected');

    assert.strictEqual(fixture.peerCertificates.length, 1);
    const peerCert = fixture.peerCertificates[0];
    assert.ok(peerCert, 'Peer certificate should be received by upstream TLS server');
    assert.strictEqual(peerCert.subject.CN, 'panel-mtls-client');

    const expectedX509 = new crypto.X509Certificate(clientCert);
    assert.strictEqual(peerCert.fingerprint256, expectedX509.fingerprint256);

    upstreamWs.close();
  } finally {
    fixture.cleanup();
  }
});

test('handleIncusSession connects to control_path, sends initial resize, and forwards client resize commands', async () => {
  const EventEmitter = require('events');
  const fixture = createTestTlsServer();
  try {
    await new Promise((resolve) => fixture.tlsServer.listen(0, '127.0.0.1', resolve));
    const tlsPort = fixture.tlsServer.address().port;

    let serverDataWs = null;
    let serverControlWs = null;
    const controlMessages = [];
    const dataMessages = [];

    const serverConnections = new Promise((resolve) => {
      fixture.testWss.on('connection', (ws, req) => {
        if (req.url && req.url.includes('/control')) {
          serverControlWs = ws;
          ws.on('message', (msg) => {
            controlMessages.push(JSON.parse(msg.toString('utf8')));
          });
        } else if (req.url && req.url.includes('/websocket')) {
          serverDataWs = ws;
          ws.on('message', (msg) => {
            dataMessages.push(msg.toString('utf8'));
          });
        }
        if (serverControlWs && serverDataWs) {
          resolve();
        }
      });
    });

    const mockClientWs = new EventEmitter();
    mockClientWs.readyState = WebSocket.OPEN;
    const clientReceived = [];
    mockClientWs.send = (msg) => {
      clientReceived.push(typeof msg === 'string' ? msg : msg.toString('utf8'));
    };
    mockClientWs.close = (code, reason) => {
      mockClientWs.readyState = WebSocket.CLOSED;
      mockClientWs.closed = { code, reason };
      mockClientWs.emit('close');
    };

    handleIncusSession(mockClientWs, {
      host_endpoint: `https://127.0.0.1:${tlsPort}`,
      websocket_path: '/1.0/operations/123/websocket',
      control_path: '/1.0/operations/123/control',
      server_public_key_pin: fixture.validPin,
    });

    await serverConnections;
    assert.ok(serverControlWs, 'Control socket should be connected');
    assert.ok(serverDataWs, 'Data socket should be connected');

    // 1. Initial window-resize sent on control socket open
    await new Promise((resolve) => {
      if (controlMessages.length >= 1) return resolve();
      serverControlWs.on('message', () => {
        if (controlMessages.length >= 1) resolve();
      });
    });

    assert.strictEqual(controlMessages.length, 1);
    assert.deepStrictEqual(controlMessages[0], {
      command: 'window-resize',
      args: { width: '80', height: '24' },
    });

    // 2. Client sends a resize command
    mockClientWs.emit(
      'message',
      JSON.stringify({ type: 'resize', cols: 120, rows: 40 }),
      false
    );

    await new Promise((resolve) => {
      if (controlMessages.length >= 2) return resolve();
      serverControlWs.on('message', () => {
        if (controlMessages.length >= 2) resolve();
      });
    });

    assert.strictEqual(controlMessages.length, 2);
    assert.deepStrictEqual(controlMessages[1], {
      command: 'window-resize',
      args: { width: '120', height: '40' },
    });
    assert.strictEqual(dataMessages.length, 0, 'Resize command should not be sent to data socket');

    // 3. Bidirectional data flow: client -> upstream dataWs
    mockClientWs.emit('message', 'echo test\n', false);
    await new Promise((resolve) => {
      if (dataMessages.length >= 1) return resolve();
      serverDataWs.on('message', () => {
        if (dataMessages.length >= 1) resolve();
      });
    });
    assert.strictEqual(dataMessages[0], 'echo test\n');

    // 4. Bidirectional data flow: upstream dataWs -> client
    serverDataWs.send('output from vm\n');
    await new Promise((resolve) => {
      if (clientReceived.some((m) => m.includes('output from vm'))) return resolve();
      const interval = setInterval(() => {
        if (clientReceived.some((m) => m.includes('output from vm'))) {
          clearInterval(interval);
          resolve();
        }
      }, 10);
    });
    assert.ok(clientReceived.some((m) => m.includes('output from vm')));

    // 5. Clean teardown: closing clientWs closes both upstream sockets
    const dataClosePromise = new Promise((resolve) => serverDataWs.on('close', resolve));
    const controlClosePromise = new Promise((resolve) => serverControlWs.on('close', resolve));

    mockClientWs.close(1000, 'Normal closure');

    await Promise.all([dataClosePromise, controlClosePromise]);
  } finally {
    fixture.cleanup();
  }
});

test('handleIncusSession buffers client messages until upstream is ready', async () => {
  const EventEmitter = require('events');
  const fixture = createTestTlsServer();
  try {
    await new Promise((resolve) => fixture.tlsServer.listen(0, '127.0.0.1', resolve));
    const tlsPort = fixture.tlsServer.address().port;

    const mockClientWs = new EventEmitter();
    mockClientWs.readyState = WebSocket.OPEN;
    mockClientWs.send = () => {};
    mockClientWs.close = () => {
      mockClientWs.readyState = WebSocket.CLOSED;
      mockClientWs.emit('close');
    };

    const received = [];
    const upstreamConnected = new Promise((resolve) => {
      fixture.testWss.once('connection', (ws) => {
        ws.on('message', (data) => {
          received.push(data.toString());
        });
        resolve(ws);
      });
    });

    handleIncusSession(mockClientWs, {
      host_endpoint: `https://127.0.0.1:${tlsPort}`,
      websocket_path: '/1.0/operations/123/websocket',
      server_public_key_pin: fixture.validPin,
    });

    // Send immediately before upstream opens
    mockClientWs.emit('message', 'early-message', false);

    const upstreamWs = await upstreamConnected;
    await new Promise((resolve) => {
      if (received.length >= 1) return resolve();
      upstreamWs.on('message', () => {
        if (received.length >= 1) resolve();
      });
    });

    assert.strictEqual(received[0], 'early-message');
    mockClientWs.close();
  } finally {
    fixture.cleanup();
  }
});

test('handleIncusSession forwards client input to incusWs as binary frames', async () => {
  const EventEmitter = require('events');
  const fixture = createTestTlsServer();
  try {
    await new Promise((resolve) => fixture.tlsServer.listen(0, '127.0.0.1', resolve));
    const tlsPort = fixture.tlsServer.address().port;

    let serverDataWs = null;
    const receivedFrames = [];

    const serverConnected = new Promise((resolve) => {
      fixture.testWss.on('connection', (ws, req) => {
        if (req.url && req.url.includes('/websocket')) {
          serverDataWs = ws;
          ws.on('message', (msg, isBinary) => {
            receivedFrames.push({ msg, isBinary });
          });
          resolve();
        }
      });
    });

    const mockClientWs = new EventEmitter();
    mockClientWs.readyState = WebSocket.OPEN;
    mockClientWs.send = () => {};
    mockClientWs.close = (code, reason) => {
      mockClientWs.readyState = WebSocket.CLOSED;
      mockClientWs.closed = { code, reason };
      mockClientWs.emit('close');
    };

    handleIncusSession(mockClientWs, {
      host_endpoint: `https://127.0.0.1:${tlsPort}`,
      websocket_path: '/1.0/operations/123/websocket',
      server_public_key_pin: fixture.validPin,
    });

    await serverConnected;

    // Send string data (simulating text frame from client)
    mockClientWs.emit('message', 'ls -la\n', false);

    await new Promise((resolve) => {
      if (receivedFrames.length >= 1) return resolve();
      serverDataWs.on('message', () => {
        if (receivedFrames.length >= 1) resolve();
      });
    });

    assert.strictEqual(receivedFrames.length, 1);
    assert.strictEqual(receivedFrames[0].isBinary, true);
    assert.ok(Buffer.isBuffer(receivedFrames[0].msg));
    assert.strictEqual(receivedFrames[0].msg.toString('utf8'), 'ls -la\n');

    // Send binary buffer data
    const binPayload = Buffer.from([0x01, 0x02, 0x03]);
    mockClientWs.emit('message', binPayload, true);

    await new Promise((resolve) => {
      if (receivedFrames.length >= 2) return resolve();
      serverDataWs.on('message', () => {
        if (receivedFrames.length >= 2) resolve();
      });
    });

    assert.strictEqual(receivedFrames.length, 2);
    assert.strictEqual(receivedFrames[1].isBinary, true);
    assert.ok(Buffer.isBuffer(receivedFrames[1].msg));
    assert.deepStrictEqual(receivedFrames[1].msg, binPayload);

    mockClientWs.close();
  } finally {
    fixture.cleanup();
  }
});

test('extractTicket accepts ONLY ticket.<ticket> from Sec-WebSocket-Protocol', () => {
  const reqProto = {
    headers: {
      'sec-websocket-protocol': 'ognivo-terminal, ticket.subprotocol-test-token-123456',
    },
  };
  assert.strictEqual(extractTicket(reqProto), 'subprotocol-test-token-123456');

  // Bearer authorization header must NOT be accepted
  const reqAuth = {
    headers: {
      authorization: 'Bearer auth-bearer-token-1234567890',
    },
  };
  assert.strictEqual(extractTicket(reqAuth), null);

  // Query parameter must NOT be accepted
  const reqQuery = { headers: {}, url: '/console?ticket=query-ticket-1234567890' };
  assert.strictEqual(extractTicket(reqQuery), null);

  // Missing ticket returns null
  assert.strictEqual(extractTicket({ headers: {} }), null);
});

test('WebSocket handshake extracts ticket from Sec-WebSocket-Protocol header', async () => {
  const restoreRedis = stubRedis({
    status: 'ready',
    call: async () => null,
  });

  try {
    const address = server.address();
    const port = address.port;

    await new Promise((resolve) => {
      const ws = new WebSocket(
        `ws://127.0.0.1:${port}/console`,
        ['ognivo-terminal', 'ticket.subprotocol-valid-format-token-99999'],
        { headers: { Origin: 'http://localhost' } }
      );
      ws.on('unexpected-response', (_req, res) => {
        assert.strictEqual(res.statusCode, 403);
        res.resume();
        resolve();
      });
      ws.on('error', (err) => {
        assert.ok(
          err.message.includes('403') || err.message.includes('Unexpected server response'),
          `Expected 403 error, got: ${err.message}`
        );
        resolve();
      });
      ws.on('open', () => {
        assert.fail('Should not connect with nonexistent ticket in subprotocol');
      });
    });
  } finally {
    restoreRedis();
  }
});

test('Client disconnect on an exec session sends SIGHUP and SIGTERM, closes upstream sockets, and sends DELETE operation', async () => {
  const EventEmitter = require('events');
  const fixture = createTestTlsServer();
  try {
    await new Promise((resolve) => fixture.tlsServer.listen(0, '127.0.0.1', resolve));
    const tlsPort = fixture.tlsServer.address().port;

    let serverDataWs = null;
    let serverControlWs = null;
    const controlMessages = [];
    const httpDeletes = [];

    fixture.tlsServer.on('request', (req, res) => {
      if (req.method === 'DELETE') {
        httpDeletes.push(req.url);
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ type: 'sync', status: 'Success', status_code: 200 }));
      }
    });

    const serverConnections = new Promise((resolve) => {
      fixture.testWss.on('connection', (ws, req) => {
        if (req.url && req.url.includes('/control')) {
          serverControlWs = ws;
          ws.on('message', (msg) => {
            try {
              controlMessages.push(JSON.parse(msg.toString('utf8')));
            } catch {}
          });
        } else if (req.url && req.url.includes('/websocket')) {
          serverDataWs = ws;
        }
        if (serverControlWs && serverDataWs) {
          resolve();
        }
      });
    });

    const mockClientWs = new EventEmitter();
    mockClientWs.readyState = WebSocket.OPEN;
    mockClientWs.send = () => {};
    mockClientWs.close = (code, reason) => {
      mockClientWs.readyState = WebSocket.CLOSED;
      mockClientWs.closed = { code, reason };
      mockClientWs.emit('close');
    };

    const opId = 'op-exec-test-uuid-1234';
    handleIncusSession(mockClientWs, {
      host_endpoint: `https://127.0.0.1:${tlsPort}`,
      websocket_path: `/1.0/operations/${opId}/websocket`,
      control_path: `/1.0/operations/${opId}/control`,
      operation_id: opId,
      virtual_machine_id: 101,
      session_id: 'session-exec-test-1',
      driver: 'exec',
      server_public_key_pin: fixture.validPin,
    });

    await serverConnections;
    assert.ok(serverControlWs, 'Control socket connected');
    assert.ok(serverDataWs, 'Data socket connected');

    // Wait for initial resize message
    await new Promise((resolve) => {
      if (controlMessages.length >= 1) return resolve();
      serverControlWs.on('message', () => {
        if (controlMessages.length >= 1) resolve();
      });
    });

    // Send a message to ensure data socket is fully connected
    mockClientWs.emit('message', 'init\n', false);
    await new Promise((resolve) => {
      serverDataWs.once('message', () => resolve());
    });

    const dataClosePromise = new Promise((resolve) => serverDataWs.on('close', resolve));
    const controlClosePromise = new Promise((resolve) => serverControlWs.on('close', resolve));

    // Simulate client disconnect / tab close
    mockClientWs.close(1001, 'Going away');

    await Promise.all([dataClosePromise, controlClosePromise]);

    // Check control messages for SIGHUP (1) and SIGTERM (15)
    assert.ok(
      controlMessages.some((m) => m.command === 'signal' && m.signal === 1),
      'Must send SIGHUP (signal: 1) on disconnect'
    );
    assert.ok(
      controlMessages.some((m) => m.command === 'signal' && m.signal === 15),
      'Must send SIGTERM (signal: 15) on disconnect'
    );

    // Wait for DELETE operation request
    await new Promise((resolve) => {
      if (httpDeletes.length > 0) return resolve();
      const interval = setInterval(() => {
        if (httpDeletes.length > 0) {
          clearInterval(interval);
          resolve();
        }
      }, 20);
    });

    assert.strictEqual(httpDeletes.length, 1);
    assert.strictEqual(httpDeletes[0], `/1.0/operations/${opId}`);
  } finally {
    fixture.cleanup();
  }
});

test('Idle timeout closes the session and cleans up upstream connections after inactivity', async () => {
  const EventEmitter = require('events');
  const fixture = createTestTlsServer();
  try {
    await new Promise((resolve) => fixture.tlsServer.listen(0, '127.0.0.1', resolve));
    const tlsPort = fixture.tlsServer.address().port;

    let serverDataWs = null;
    let serverControlWs = null;
    const controlMessages = [];
    const httpDeletes = [];

    fixture.tlsServer.on('request', (req, res) => {
      if (req.method === 'DELETE') {
        httpDeletes.push(req.url);
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ status: 'success' }));
      }
    });

    const serverConnections = new Promise((resolve) => {
      fixture.testWss.on('connection', (ws, req) => {
        if (req.url && req.url.includes('/control')) {
          serverControlWs = ws;
          ws.on('message', (msg) => {
            try {
              controlMessages.push(JSON.parse(msg.toString('utf8')));
            } catch {}
          });
        } else if (req.url && req.url.includes('/websocket')) {
          serverDataWs = ws;
        }
        if (serverControlWs && serverDataWs) {
          resolve();
        }
      });
    });

    const clientReceived = [];
    const mockClientWs = new EventEmitter();
    mockClientWs.readyState = WebSocket.OPEN;
    mockClientWs.send = (msg) => {
      clientReceived.push(typeof msg === 'string' ? msg : msg.toString('utf8'));
    };
    const clientClosedPromise = new Promise((resolve) => {
      mockClientWs.close = (code, reason) => {
        mockClientWs.readyState = WebSocket.CLOSED;
        mockClientWs.closed = { code, reason };
        mockClientWs.emit('close');
        resolve();
      };
    });

    const opId = 'op-idle-timeout-1234';
    handleIncusSession(mockClientWs, {
      host_endpoint: `https://127.0.0.1:${tlsPort}`,
      websocket_path: `/1.0/operations/${opId}/websocket`,
      control_path: `/1.0/operations/${opId}/control`,
      operation_id: opId,
      virtual_machine_id: 102,
      session_id: 'session-idle-1',
      idle_timeout_ms: 150,
      server_public_key_pin: fixture.validPin,
    });

    await serverConnections;

    const dataClosePromise = new Promise((resolve) => serverDataWs.on('close', resolve));
    const controlClosePromise = new Promise((resolve) => serverControlWs.on('close', resolve));

    // Await idle timeout trigger (150ms)
    await clientClosedPromise;
    await Promise.all([dataClosePromise, controlClosePromise]);

    assert.strictEqual(mockClientWs.closed.code, 4000);
    assert.strictEqual(mockClientWs.closed.reason, 'Idle timeout exceeded');
    assert.ok(
      clientReceived.some((m) => m.includes('[Console session closed due to inactivity]')),
      'Should send inactivity message to client'
    );
    assert.ok(
      controlMessages.some((m) => m.command === 'signal' && m.signal === 1),
      'Should send SIGHUP'
    );
    assert.ok(
      controlMessages.some((m) => m.command === 'signal' && m.signal === 15),
      'Should send SIGTERM'
    );

    // Wait for DELETE operation request
    await new Promise((resolve) => {
      if (httpDeletes.length > 0) return resolve();
      const interval = setInterval(() => {
        if (httpDeletes.length > 0) {
          clearInterval(interval);
          resolve();
        }
      }, 20);
    });

    assert.strictEqual(httpDeletes.length, 1);
    assert.strictEqual(httpDeletes[0], `/1.0/operations/${opId}`);
  } finally {
    fixture.cleanup();
  }
});

test('Oversized message exceeding max_message_bytes is rejected and closes client connection with 1009', async () => {
  const EventEmitter = require('events');
  const fixture = createTestTlsServer();
  try {
    await new Promise((resolve) => fixture.tlsServer.listen(0, '127.0.0.1', resolve));
    const tlsPort = fixture.tlsServer.address().port;

    let serverDataWs = null;
    const dataReceived = [];

    const serverConnected = new Promise((resolve) => {
      fixture.testWss.once('connection', (ws) => {
        serverDataWs = ws;
        ws.on('message', (msg) => {
          dataReceived.push(msg);
        });
        resolve();
      });
    });

    const mockClientWs = new EventEmitter();
    mockClientWs.readyState = WebSocket.OPEN;
    mockClientWs.send = () => {};
    const clientClosedPromise = new Promise((resolve) => {
      mockClientWs.close = (code, reason) => {
        mockClientWs.readyState = WebSocket.CLOSED;
        mockClientWs.closed = { code, reason };
        mockClientWs.emit('close');
        resolve();
      };
    });

    handleIncusSession(mockClientWs, {
      host_endpoint: `https://127.0.0.1:${tlsPort}`,
      websocket_path: '/1.0/operations/123/websocket',
      max_message_bytes: 100,
      server_public_key_pin: fixture.validPin,
    });

    await serverConnected;

    // Send oversized message (150 bytes)
    const oversizedMsg = 'A'.repeat(150);
    mockClientWs.emit('message', oversizedMsg, false);

    await clientClosedPromise;

    assert.strictEqual(mockClientWs.closed.code, 1009);
    assert.strictEqual(mockClientWs.closed.reason, 'Message too big');
    assert.strictEqual(dataReceived.length, 0, 'Oversized message should not be forwarded to upstream');
  } finally {
    fixture.cleanup();
  }
});

test('handleFakeSession differentiates exec shell vs console login prompt', async () => {
  const EventEmitter = require('events');

  // 1. Exec session
  const execClient = new EventEmitter();
  execClient.readyState = WebSocket.OPEN;
  const execMessages = [];
  execClient.send = (msg) => execMessages.push(typeof msg === 'string' ? msg : msg.toString());

  handleFakeSession(execClient, {
    virtual_machine_id: 88,
    driver: 'exec',
    instance_type: 'container',
    host_driver: 'fake',
  });

  assert.ok(execMessages[0].includes('[Connected to Fake Hypervisor Exec Shell: Instance #88]'));
  assert.ok(execMessages[0].includes('root@fake-instance:~# '));
  assert.ok(!execMessages[0].includes('login: '));

  execClient.emit('message', '\n', false);
  assert.strictEqual(execMessages[execMessages.length - 1], '\r\nroot@fake-instance:~# ');

  // 2. Console session
  const consoleClient = new EventEmitter();
  consoleClient.readyState = WebSocket.OPEN;
  const consoleMessages = [];
  consoleClient.send = (msg) => consoleMessages.push(typeof msg === 'string' ? msg : msg.toString());

  handleFakeSession(consoleClient, {
    virtual_machine_id: 89,
    driver: 'console',
    instance_type: 'virtual-machine',
    host_driver: 'fake',
  });

  assert.ok(consoleMessages[0].includes('[Connected to Fake Hypervisor Console: Instance #89]'));
  assert.ok(consoleMessages[0].includes('fake-instance login: '));

  consoleClient.emit('message', '\r', false);
  assert.strictEqual(consoleMessages[consoleMessages.length - 1], '\r\nPassword: \r\nfake-instance:~# ');

  execClient.emit('close');
  consoleClient.emit('close');
});

function stubRedis(overrides) {
  const keys = ['status', 'call', 'eval', 'hget', 'hset', 'expire', 'hdel'];
  const saved = new Map();
  for (const key of keys) {
    saved.set(key, Object.getOwnPropertyDescriptor(redis, key));
  }
  for (const [key, value] of Object.entries(overrides)) {
    redis[key] = value;
  }
  return () => {
    for (const [key, desc] of saved.entries()) {
      if (desc === undefined) {
        delete redis[key];
      } else {
        Object.defineProperty(redis, key, desc);
      }
    }
  };
}

test('touchRedisSession returns false for incomplete payload or when Redis is not ready', async () => {
  const restoreNotReady = stubRedis({ status: 'wait' });
  try {
    assert.strictEqual(await touchRedisSession(null, true), false);
    assert.strictEqual(await touchRedisSession({ session_id: 's1' }, true), false);
    assert.strictEqual(await touchRedisSession({ virtual_machine_id: 1 }, true), false);
    assert.strictEqual(
      await touchRedisSession({ session_id: 's1', virtual_machine_id: 1 }, true),
      false
    );
  } finally {
    restoreNotReady();
  }
});

test('touchRedisSession updates last_activity_at, sets redeemed_at when markRedeemed=true, and extends TTL via Lua eval', async () => {
  const evalCalls = [];
  const targetKey = 'laravel-database-console:vm_sessions:42';
  const sessionStore = new Map([
    [
      targetKey,
      new Map([
        ['sess-lua-1', JSON.stringify({ session_id: 'sess-lua-1', last_activity_at: 1000, redeemed_at: null })],
      ]),
    ],
  ]);

  const restore = stubRedis({
    status: 'ready',
    eval: async (script, numKeys, key, sid, nowStr, markFlag, ttlStr) => {
      evalCalls.push({ script, numKeys, key, sid, nowStr, markFlag, ttlStr });
      const vmHash = sessionStore.get(key);
      if (!vmHash || !vmHash.has(sid)) return 0;
      const data = JSON.parse(vmHash.get(sid));
      const now = Number(nowStr);
      data.last_activity_at = now;
      if (markFlag === '1' && (data.redeemed_at === undefined || data.redeemed_at === null)) {
        data.redeemed_at = now;
      }
      vmHash.set(sid, JSON.stringify(data));
      return 1;
    },
  });

  try {
    const beforeSec = Date.now() / 1000;
    const ok = await touchRedisSession(
      { session_id: 'sess-lua-1', virtual_machine_id: 42, idle_timeout_seconds: 900 },
      true
    );
    const afterSec = Date.now() / 1000;

    assert.strictEqual(ok, true);
    assert.ok(evalCalls.length >= 1);
    const matchedCall = evalCalls[evalCalls.length - 1];
    assert.strictEqual(matchedCall.key, targetKey);
    assert.strictEqual(matchedCall.numKeys, 1);
    assert.strictEqual(matchedCall.sid, 'sess-lua-1');
    assert.strictEqual(matchedCall.markFlag, '1');
    assert.strictEqual(matchedCall.ttlStr, '960');

    const stored = JSON.parse(sessionStore.get(targetKey).get('sess-lua-1'));
    assert.ok(stored.last_activity_at >= beforeSec && stored.last_activity_at <= afterSec);
    assert.strictEqual(stored.redeemed_at, stored.last_activity_at);
  } finally {
    restore();
  }
});

test('touchRedisSession falls back to hget/hset/expire when redis.eval fails and preserves existing redeemed_at', async () => {
  const targetKey = 'console:vm_sessions:77';
  const vmHash = new Map([
    ['sess-fb-1', JSON.stringify({ session_id: 'sess-fb-1', last_activity_at: 500, redeemed_at: null })],
  ]);
  const expireCalls = [];

  const restore = stubRedis({
    status: 'ready',
    eval: async () => {
      throw new Error('ERR unknown command or missing cjson');
    },
    hget: async (key, field) => (key === targetKey ? (vmHash.get(field) || null) : null),
    hset: async (key, field, value) => {
      if (key === targetKey) vmHash.set(field, value);
      return 0;
    },
    expire: async (key, ttl) => {
      expireCalls.push({ key, ttl });
      return 1;
    },
  });

  try {
    const firstOk = await touchRedisSession(
      { session_id: 'sess-fb-1', virtual_machine_id: 77, idle_timeout_seconds: 300 },
      true
    );
    assert.strictEqual(firstOk, true);

    const afterRedeem = JSON.parse(vmHash.get('sess-fb-1'));
    assert.ok(typeof afterRedeem.redeemed_at === 'number' && afterRedeem.redeemed_at > 500);
    assert.strictEqual(afterRedeem.last_activity_at, afterRedeem.redeemed_at);
    assert.deepStrictEqual(expireCalls[0], { key: targetKey, ttl: 360 });

    const originalRedeemedAt = afterRedeem.redeemed_at;
    await new Promise((resolve) => setTimeout(resolve, 15));

    const secondOk = await touchRedisSession(
      { session_id: 'sess-fb-1', virtual_machine_id: 77, idle_timeout_seconds: 300 },
      false
    );
    assert.strictEqual(secondOk, true);

    const afterRefresh = JSON.parse(vmHash.get('sess-fb-1'));
    assert.strictEqual(afterRefresh.redeemed_at, originalRedeemedAt, 'redeemed_at must remain unchanged');
    assert.ok(afterRefresh.last_activity_at > originalRedeemedAt, 'last_activity_at should advance');
    assert.deepStrictEqual(expireCalls[1], { key: targetKey, ttl: 360 });
  } finally {
    restore();
  }
});

test('handleFakeSession marks session redeemed on open and throttles last_activity_at refreshes', async () => {
  const EventEmitter = require('events');
  const vmKey = 'console:vm_sessions:91';
  const sid = 'sess-fake-throttle-1';
  const vmHash = new Map([
    [sid, JSON.stringify({ session_id: sid, last_activity_at: 100, redeemed_at: null })],
  ]);
  const writeHistory = [];

  const restore = stubRedis({
    status: 'ready',
    eval: async () => {
      throw new Error('use fallback');
    },
    hget: async (key, field) => (key === vmKey ? (vmHash.get(field) || null) : null),
    hset: async (key, field, value) => {
      if (key === vmKey) {
        vmHash.set(field, value);
        writeHistory.push(JSON.parse(value));
      }
      return 0;
    },
    expire: async () => 1,
    hdel: async () => 1,
  });

  try {
    const fakeClient = new EventEmitter();
    fakeClient.readyState = WebSocket.OPEN;
    fakeClient.send = () => {};
    fakeClient.close = () => {
      fakeClient.readyState = WebSocket.CLOSED;
      fakeClient.emit('close');
    };

    handleFakeSession(fakeClient, {
      virtual_machine_id: 91,
      session_id: sid,
      driver: 'exec',
      instance_type: 'container',
      host_driver: 'fake',
      idle_timeout_seconds: 600,
      activity_refresh_interval_ms: 20,
    });

    // Wait for initial touchRedisSession(payload, true)
    await new Promise((resolve) => {
      const check = () => (writeHistory.length >= 1 ? resolve() : setTimeout(check, 5));
      check();
    });

    assert.strictEqual(writeHistory.length, 1);
    const initialRecord = writeHistory[0];
    assert.ok(typeof initialRecord.redeemed_at === 'number' && initialRecord.redeemed_at > 100);
    assert.strictEqual(initialRecord.last_activity_at, initialRecord.redeemed_at);

    // Messages sent within the 20ms throttle window should NOT trigger extra Redis writes
    fakeClient.emit('message', 'a', false);
    fakeClient.emit('message', 'b', false);
    await new Promise((resolve) => setTimeout(resolve, 10));
    assert.strictEqual(writeHistory.length, 1, 'Messages inside throttle window must not spam Redis');

    // Wait past the 20ms throttle interval, then send traffic
    await new Promise((resolve) => setTimeout(resolve, 25));
    fakeClient.emit('message', 'c', false);

    await new Promise((resolve) => {
      const check = () => (writeHistory.length >= 2 ? resolve() : setTimeout(check, 5));
      check();
    });

    assert.strictEqual(writeHistory.length, 2);
    assert.strictEqual(writeHistory[1].redeemed_at, initialRecord.redeemed_at);
    assert.ok(writeHistory[1].last_activity_at > initialRecord.last_activity_at);

    // Close session and ensure no further touches happen afterwards
    fakeClient.close();
    await new Promise((resolve) => setTimeout(resolve, 30));
    fakeClient.emit('message', 'after-close', false);
    await new Promise((resolve) => setTimeout(resolve, 15));
    assert.strictEqual(writeHistory.length, 2, 'Should never touch Redis after cleanup');
  } finally {
    restore();
  }
});

test('handleIncusSession marks session redeemed on open, throttles activity refreshes, and stops after teardown', async () => {
  const EventEmitter = require('events');
  const fixture = createTestTlsServer();
  const vmKey = 'console:vm_sessions:92';
  const sid = 'sess-incus-throttle-1';
  const vmHash = new Map([
    [sid, JSON.stringify({ session_id: sid, last_activity_at: 200, redeemed_at: null })],
  ]);
  const writeHistory = [];

  const restore = stubRedis({
    status: 'ready',
    eval: async () => {
      throw new Error('use fallback');
    },
    hget: async (key, field) => (key === vmKey ? (vmHash.get(field) || null) : null),
    hset: async (key, field, value) => {
      if (key === vmKey) {
        vmHash.set(field, value);
        writeHistory.push(JSON.parse(value));
      }
      return 0;
    },
    expire: async () => 1,
    hdel: async () => 1,
  });

  try {
    await new Promise((resolve) => fixture.tlsServer.listen(0, '127.0.0.1', resolve));
    const tlsPort = fixture.tlsServer.address().port;

    let serverDataWs = null;
    const upstreamReady = new Promise((resolve) => {
      fixture.testWss.once('connection', (ws) => {
        serverDataWs = ws;
        resolve();
      });
    });

    const mockClientWs = new EventEmitter();
    mockClientWs.readyState = WebSocket.OPEN;
    mockClientWs.send = () => {};
    mockClientWs.close = () => {
      mockClientWs.readyState = WebSocket.CLOSED;
      mockClientWs.emit('close');
    };

    handleIncusSession(mockClientWs, {
      host_endpoint: `https://127.0.0.1:${tlsPort}`,
      websocket_path: '/1.0/operations/op-throttle/websocket',
      virtual_machine_id: 92,
      session_id: sid,
      idle_timeout_seconds: 900,
      activity_refresh_interval_ms: 20,
      server_public_key_pin: fixture.validPin,
    });

    await upstreamReady;

    // Wait for initial touchRedisSession(payload, true)
    await new Promise((resolve) => {
      const check = () => (writeHistory.length >= 1 ? resolve() : setTimeout(check, 5));
      check();
    });

    assert.strictEqual(writeHistory.length, 1);
    const initialRecord = writeHistory[0];
    assert.ok(typeof initialRecord.redeemed_at === 'number' && initialRecord.redeemed_at > 200);

    // Send traffic within the 20ms throttle window -> no extra Redis write
    mockClientWs.emit('message', 'x', false);
    await new Promise((resolve) => setTimeout(resolve, 10));
    assert.strictEqual(writeHistory.length, 1);

    // Wait past 20ms throttle window and send traffic
    await new Promise((resolve) => setTimeout(resolve, 25));
    mockClientWs.emit('message', 'y', false);

    await new Promise((resolve) => {
      const check = () => (writeHistory.length >= 2 ? resolve() : setTimeout(check, 5));
      check();
    });

    assert.strictEqual(writeHistory.length, 2);
    assert.strictEqual(writeHistory[1].redeemed_at, initialRecord.redeemed_at);
    assert.ok(writeHistory[1].last_activity_at > initialRecord.last_activity_at);

    const closedPromise = new Promise((resolve) => serverDataWs.on('close', resolve));
    mockClientWs.close();
    await closedPromise;

    await new Promise((resolve) => setTimeout(resolve, 30));
    mockClientWs.emit('message', 'late', false);
    await new Promise((resolve) => setTimeout(resolve, 15));
    assert.strictEqual(writeHistory.length, 2, 'Should never touch Redis after teardown');
  } finally {
    restore();
    fixture.cleanup();
  }
});

test('isFakeDriverAllowed enforces ALLOW_FAKE_DRIVER and NODE_ENV defaults', () => {
  const savedNodeEnv = process.env.NODE_ENV;
  const savedAllowFake = process.env.ALLOW_FAKE_DRIVER;

  try {
    delete process.env.ALLOW_FAKE_DRIVER;
    delete process.env.NODE_ENV;
    assert.strictEqual(isFakeDriverAllowed(), true);

    process.env.NODE_ENV = 'development';
    assert.strictEqual(isFakeDriverAllowed(), true);

    process.env.NODE_ENV = 'production';
    assert.strictEqual(isFakeDriverAllowed(), false);

    process.env.ALLOW_FAKE_DRIVER = 'false';
    assert.strictEqual(isFakeDriverAllowed(), false);

    process.env.ALLOW_FAKE_DRIVER = '0';
    assert.strictEqual(isFakeDriverAllowed(), false);

    process.env.ALLOW_FAKE_DRIVER = 'true';
    assert.strictEqual(isFakeDriverAllowed(), true);

    process.env.ALLOW_FAKE_DRIVER = '1';
    assert.strictEqual(isFakeDriverAllowed(), true);
  } finally {
    if (savedNodeEnv === undefined) {
      delete process.env.NODE_ENV;
    } else {
      process.env.NODE_ENV = savedNodeEnv;
    }
    if (savedAllowFake === undefined) {
      delete process.env.ALLOW_FAKE_DRIVER;
    } else {
      process.env.ALLOW_FAKE_DRIVER = savedAllowFake;
    }
  }
});

test('WebSocket connection to /console with host_driver=fake closes with 1008 when disabled in production', async () => {
  const savedNodeEnv = process.env.NODE_ENV;
  const savedAllowFake = process.env.ALLOW_FAKE_DRIVER;
  const savedAppKey = process.env.APP_KEY;
  const ticket = 'fake-driver-guard-ticket-123456';
  const payload = {
    session_id: 'sess-fake-guard-1',
    virtual_machine_id: 99,
    host_driver: 'fake',
    driver: 'exec',
    instance_type: 'container',
    session_expires_at: Math.floor(Date.now() / 1000) + 300,
  };

  process.env.APP_KEY = 'base64:' + Buffer.alloc(32, 7).toString('base64');
  const encryptedPayload = encryptTicketPayload(payload, ticket);

  const hdelCalls = [];
  const restoreRedis = stubRedis({
    status: 'ready',
    call: async (cmd, key) => {
      if (cmd === 'GETDEL' && key.endsWith(`console:ticket:${ticket}`)) {
        return encryptedPayload;
      }
      return null;
    },
    hdel: async (key, field) => {
      hdelCalls.push({ key, field });
      return 1;
    },
  });

  try {
    process.env.NODE_ENV = 'production';
    process.env.ALLOW_FAKE_DRIVER = 'false';

    const address = server.address();
    const port = address.port;
    const receivedMessages = [];

    const closeEvent = await new Promise((resolve, reject) => {
      const ws = new WebSocket(
        `ws://127.0.0.1:${port}/console`,
        ['ognivo-terminal', `ticket.${ticket}`],
        { headers: { Origin: 'http://localhost' } }
      );
      ws.on('message', (data) => {
        receivedMessages.push(data.toString('utf8'));
      });
      ws.on('close', (code, reason) => {
        resolve({ code, reason: reason.toString('utf8') });
      });
      ws.on('error', reject);
    });

    assert.strictEqual(closeEvent.code, 1008);
    assert.strictEqual(closeEvent.reason, 'Fake hypervisor driver is disabled');
    assert.strictEqual(receivedMessages.length, 0, 'Should not emit fake terminal prompt when disabled');
    assert.ok(hdelCalls.some((c) => c.field === payload.session_id), 'Should clean up Redis session record');
  } finally {
    restoreRedis();
    if (savedNodeEnv === undefined) {
      delete process.env.NODE_ENV;
    } else {
      process.env.NODE_ENV = savedNodeEnv;
    }
    if (savedAllowFake === undefined) {
      delete process.env.ALLOW_FAKE_DRIVER;
    } else {
      process.env.ALLOW_FAKE_DRIVER = savedAllowFake;
    }
    if (savedAppKey === undefined) {
      delete process.env.APP_KEY;
    } else {
      process.env.APP_KEY = savedAppKey;
    }
  }
});

test('encryptTicketPayload and decryptTicketPayload round-trip AES-256-GCM with ticket AAD and APP_PREVIOUS_KEYS rotation', () => {
  const savedAppKey = process.env.APP_KEY;
  const savedPreviousKeys = process.env.APP_PREVIOUS_KEYS;

  try {
    const oldKey = 'base64:' + Buffer.alloc(32, 11).toString('base64');
    const newKey = 'base64:' + Buffer.alloc(32, 22).toString('base64');
    const ticket = 'roundtrip-aad-ticket-1234567890';
    const samplePayload = {
      session_id: 'sess-enc-1',
      virtual_machine_id: 42,
      client_private_key: 'SECRET-PEM-PRIVATE-KEY-CONTENT',
    };

    process.env.APP_KEY = oldKey;
    delete process.env.APP_PREVIOUS_KEYS;

    const rawEnvelope = encryptTicketPayload(samplePayload, ticket);
    assert.ok(!rawEnvelope.includes('SECRET-PEM-PRIVATE-KEY-CONTENT'), 'Ciphertext must not leak plaintext secret');

    const parsedEnvelope = JSON.parse(rawEnvelope);
    assert.strictEqual(parsedEnvelope.v, 1);
    assert.strictEqual(parsedEnvelope.alg, 'AES-256-GCM');
    assert.strictEqual(typeof parsedEnvelope.iv, 'string');
    assert.strictEqual(typeof parsedEnvelope.tag, 'string');
    assert.strictEqual(typeof parsedEnvelope.ct, 'string');

    // Decryption succeeds with matching APP_KEY and ticket
    assert.deepStrictEqual(decryptTicketPayload(rawEnvelope, ticket), samplePayload);

    // Key rotation: old key moved to APP_PREVIOUS_KEYS, new key active in APP_KEY
    process.env.APP_KEY = newKey;
    process.env.APP_PREVIOUS_KEYS = oldKey;
    assert.deepStrictEqual(decryptTicketPayload(rawEnvelope, ticket), samplePayload);

    // Mismatched ticket AAD throws
    assert.throws(
      () => decryptTicketPayload(rawEnvelope, 'different-ticket-1234567890'),
      /Failed to decrypt or authenticate/
    );

    // Tampered ciphertext throws
    const ctBuf = Buffer.from(parsedEnvelope.ct, 'base64');
    ctBuf[0] ^= 0xff;
    const tamperedCt = JSON.stringify({ ...parsedEnvelope, ct: ctBuf.toString('base64') });
    assert.throws(() => decryptTicketPayload(tamperedCt, ticket), /Failed to decrypt or authenticate/);

    // Tampered auth tag throws
    const tagBuf = Buffer.from(parsedEnvelope.tag, 'base64');
    tagBuf[0] ^= 0xff;
    const tamperedTag = JSON.stringify({ ...parsedEnvelope, tag: tagBuf.toString('base64') });
    assert.throws(() => decryptTicketPayload(tamperedTag, ticket), /Failed to decrypt or authenticate/);

    // Plaintext JSON envelope throws
    assert.throws(
      () => decryptTicketPayload(JSON.stringify(samplePayload), ticket),
      /Unencrypted or invalid ticket envelope/
    );
  } finally {
    if (savedAppKey === undefined) {
      delete process.env.APP_KEY;
    } else {
      process.env.APP_KEY = savedAppKey;
    }
    if (savedPreviousKeys === undefined) {
      delete process.env.APP_PREVIOUS_KEYS;
    } else {
      process.env.APP_PREVIOUS_KEYS = savedPreviousKeys;
    }
  }
});

test('WebSocket upgrade to /console returns 403 Forbidden when ticket payload is unencrypted or tampered', async () => {
  const savedAppKey = process.env.APP_KEY;
  const savedPreviousKeys = process.env.APP_PREVIOUS_KEYS;

  try {
    process.env.APP_KEY = 'base64:' + Buffer.alloc(32, 33).toString('base64');
    delete process.env.APP_PREVIOUS_KEYS;

    const plaintextTicket = 'plaintext-ticket-value-123456';
    const tamperedTicket = 'tampered-ticket-value-1234567';

    const validEnvelope = JSON.parse(
      encryptTicketPayload({ session_id: 's-tamper', virtual_machine_id: 5 }, tamperedTicket)
    );
    const ctBuf = Buffer.from(validEnvelope.ct, 'base64');
    ctBuf[0] ^= 0xff;
    const tamperedEnvelope = JSON.stringify({ ...validEnvelope, ct: ctBuf.toString('base64') });

    const restoreRedis = stubRedis({
      status: 'ready',
      call: async (cmd, key) => {
        if (cmd === 'GETDEL' && key.endsWith(`console:ticket:${plaintextTicket}`)) {
          return JSON.stringify({ session_id: 's-plain', virtual_machine_id: 4 });
        }
        if (cmd === 'GETDEL' && key.endsWith(`console:ticket:${tamperedTicket}`)) {
          return tamperedEnvelope;
        }
        return null;
      },
    });

    try {
      const port = server.address().port;

      for (const ticket of [plaintextTicket, tamperedTicket]) {
        await new Promise((resolve) => {
          const ws = new WebSocket(
            `ws://127.0.0.1:${port}/console`,
            ['ognivo-terminal', `ticket.${ticket}`],
            { headers: { Origin: 'http://localhost' } }
          );
          ws.on('unexpected-response', (_req, res) => {
            assert.strictEqual(res.statusCode, 403);
            res.resume();
            resolve();
          });
          ws.on('error', (err) => {
            assert.ok(
              err.message.includes('403') || err.message.includes('Unexpected server response'),
              `Expected 403 error, got: ${err.message}`
            );
            resolve();
          });
          ws.on('open', () => {
            assert.fail(`Should not allow WebSocket upgrade for invalid ticket ${ticket}`);
          });
        });
      }
    } finally {
      restoreRedis();
    }
  } finally {
    if (savedAppKey === undefined) {
      delete process.env.APP_KEY;
    } else {
      process.env.APP_KEY = savedAppKey;
    }
    if (savedPreviousKeys === undefined) {
      delete process.env.APP_PREVIOUS_KEYS;
    } else {
      process.env.APP_PREVIOUS_KEYS = savedPreviousKeys;
    }
  }
});

function expectUpgradeRejected(wsUrl, protocols, options, expectedStatus) {
  return new Promise((resolve, reject) => {
    const ws = protocols !== undefined
      ? new WebSocket(wsUrl, protocols, options)
      : new WebSocket(wsUrl, options);
    let settled = false;

    ws.on('unexpected-response', (_req, res) => {
      if (settled) return;
      settled = true;
      res.resume();
      try {
        assert.strictEqual(res.statusCode, expectedStatus);
        resolve();
      } catch (err) {
        reject(err);
      }
    });
    ws.on('error', (err) => {
      if (settled) return;
      settled = true;
      if (err.message.includes(String(expectedStatus))) {
        resolve();
      } else {
        reject(err);
      }
    });
    ws.on('open', () => {
      if (settled) return;
      settled = true;
      ws.close();
      reject(new Error(`Expected HTTP ${expectedStatus} rejection, but WebSocket opened`));
    });
  });
}

test('normalizeOrigin, parseAllowedOrigins, and isOriginAllowed enforce exact origin allowlist and fail closed in production', () => {
  assert.strictEqual(normalizeOrigin('HTTPS://Panel.Example.COM:8443/console/'), 'https://panel.example.com:8443');
  assert.strictEqual(normalizeOrigin('https://panel.example.com:443/'), 'https://panel.example.com');
  assert.strictEqual(normalizeOrigin('http://localhost:80/foo'), 'http://localhost');
  assert.strictEqual(normalizeOrigin('*'), null);
  assert.strictEqual(normalizeOrigin('https://*.example.com'), null);
  assert.strictEqual(normalizeOrigin('ftp://example.com'), null);
  assert.strictEqual(normalizeOrigin('not-a-url'), null);

  // Non-production empty defaults to ['http://localhost']
  assert.deepStrictEqual(parseAllowedOrigins('', 'development'), ['http://localhost']);
  assert.deepStrictEqual(parseAllowedOrigins(undefined, 'test'), ['http://localhost']);
  assert.deepStrictEqual(
    parseAllowedOrigins('https://Panel.Example.COM/path, http://localhost:3000/', 'production'),
    ['https://panel.example.com', 'http://localhost:3000']
  );

  // Production fails validation on empty or '*'
  assert.throws(() => parseAllowedOrigins('', 'production'), /CONSOLE_ALLOWED_ORIGINS/);
  assert.throws(() => parseAllowedOrigins('   ', 'production'), /CONSOLE_ALLOWED_ORIGINS/);
  assert.throws(() => parseAllowedOrigins('*', 'production'), /\*/);
  assert.throws(() => parseAllowedOrigins('https://panel.example.com, *', 'production'), /\*/);
  assert.throws(() => parseAllowedOrigins('invalid-origin', 'production'), /Invalid origin/);

  // isOriginAllowed exact checks
  const allowSet = new Set(['https://panel.example.com', 'http://localhost']);
  assert.strictEqual(isOriginAllowed('https://panel.example.com', allowSet), true);
  assert.strictEqual(isOriginAllowed('http://localhost', allowSet), true);
  assert.strictEqual(isOriginAllowed('https://panel.example.com/', allowSet), false);
  assert.strictEqual(isOriginAllowed('https://panel.example.com/evil', allowSet), false);
  assert.strictEqual(isOriginAllowed('https://evil.example.com', allowSet), false);
  assert.strictEqual(isOriginAllowed('http://localhost:3000', allowSet), false);
  assert.strictEqual(isOriginAllowed('null', allowSet), false);
  assert.strictEqual(isOriginAllowed('', allowSet), false);
  assert.strictEqual(isOriginAllowed(undefined, allowSet), false);

  // Actual process startup fails in production when CONSOLE_ALLOWED_ORIGINS is '*' or empty
  const serverPath = path.resolve(__dirname, '../server.js');
  for (const badOrigins of ['', '*']) {
    const res = spawnSync(
      process.execPath,
      ['-e', `require(${JSON.stringify(serverPath)})`],
      {
        env: {
          ...process.env,
          NODE_ENV: 'production',
          CONSOLE_ALLOWED_ORIGINS: badOrigins,
          PORT: '0',
        },
        encoding: 'utf8',
      }
    );
    assert.notStrictEqual(res.status, 0, `Expected non-zero exit for CONSOLE_ALLOWED_ORIGINS=${JSON.stringify(badOrigins)}`);
    assert.ok(res.stderr.includes('CONSOLE_ALLOWED_ORIGINS'), `Expected clear error message in stderr, got: ${res.stderr}`);
  }
});

test('Foreign or missing Origin returns 403 and does NOT consume ticket in Redis', async () => {
  const savedAppKey = process.env.APP_KEY;
  process.env.APP_KEY = 'base64:' + Buffer.alloc(32, 41).toString('base64');

  const ticket = 'origin-guard-ticket-1234567890';
  const payload = {
    session_id: 'sess-origin-1',
    virtual_machine_id: 55,
    host_driver: 'fake',
    driver: 'exec',
    instance_type: 'container',
    session_expires_at: Math.floor(Date.now() / 1000) + 300,
  };
  const encryptedPayload = encryptTicketPayload(payload, ticket);

  const ticketStore = new Map([[`console:ticket:${ticket}`, encryptedPayload]]);
  let redisCallCount = 0;

  const restoreRedis = stubRedis({
    status: 'ready',
    call: async (cmd, key) => {
      redisCallCount += 1;
      if (cmd === 'GETDEL' && ticketStore.has(key)) {
        const val = ticketStore.get(key);
        ticketStore.delete(key);
        return val;
      }
      return null;
    },
    hdel: async () => 1,
  });

  try {
    const port = server.address().port;

    // 1. Foreign Origin -> 403, Redis untouched
    await expectUpgradeRejected(
      `ws://127.0.0.1:${port}/console`,
      ['ognivo-terminal', `ticket.${ticket}`],
      { headers: { Origin: 'https://evil.example.com' } },
      403
    );
    assert.strictEqual(redisCallCount, 0, 'Foreign Origin must be rejected before Redis ticket redemption');
    assert.strictEqual(ticketStore.has(`console:ticket:${ticket}`), true, 'Ticket must remain unconsumed in Redis');

    // 2. Missing Origin -> 403, Redis untouched
    await expectUpgradeRejected(
      `ws://127.0.0.1:${port}/console`,
      ['ognivo-terminal', `ticket.${ticket}`],
      {},
      403
    );
    assert.strictEqual(redisCallCount, 0, 'Missing Origin must be rejected before Redis ticket redemption');
    assert.strictEqual(ticketStore.has(`console:ticket:${ticket}`), true, 'Ticket must remain unconsumed in Redis');

    // 3. Legitimate Origin afterwards still succeeds using the unburned ticket
    await new Promise((resolve, reject) => {
      const ws = new WebSocket(
        `ws://127.0.0.1:${port}/console`,
        ['ognivo-terminal', `ticket.${ticket}`],
        { headers: { Origin: 'http://localhost' } }
      );
      ws.on('open', () => {
        ws.close();
        resolve();
      });
      ws.on('error', reject);
    });
    assert.ok(redisCallCount > 0);
    assert.strictEqual(ticketStore.has(`console:ticket:${ticket}`), false);
  } finally {
    restoreRedis();
    if (savedAppKey === undefined) {
      delete process.env.APP_KEY;
    } else {
      process.env.APP_KEY = savedAppKey;
    }
  }
});

test('Query string ?ticket= and Authorization: Bearer transports are rejected with 401', async () => {
  const ticket = 'disallowed-transport-ticket-12345';
  const port = server.address().port;

  await expectUpgradeRejected(
    `ws://127.0.0.1:${port}/console?ticket=${ticket}`,
    ['ognivo-terminal'],
    { headers: { Origin: 'http://localhost' } },
    401
  );

  await expectUpgradeRejected(
    `ws://127.0.0.1:${port}/console`,
    ['ognivo-terminal'],
    {
      headers: {
        Origin: 'http://localhost',
        Authorization: `Bearer ${ticket}`,
      },
    },
    401
  );
});

test('Ticket reuse and expired/missing Redis ticket are rejected with 403', async () => {
  const savedAppKey = process.env.APP_KEY;
  process.env.APP_KEY = 'base64:' + Buffer.alloc(32, 42).toString('base64');

  const ticket = 'single-use-ticket-1234567890';
  const payload = {
    session_id: 'sess-reuse-1',
    virtual_machine_id: 77,
    host_driver: 'fake',
    driver: 'exec',
    instance_type: 'container',
    session_expires_at: Math.floor(Date.now() / 1000) + 300,
  };
  const encryptedPayload = encryptTicketPayload(payload, ticket);
  const ticketStore = new Map([[`console:ticket:${ticket}`, encryptedPayload]]);

  const restoreRedis = stubRedis({
    status: 'ready',
    call: async (cmd, key) => {
      if (cmd === 'GETDEL' && ticketStore.has(key)) {
        const val = ticketStore.get(key);
        ticketStore.delete(key);
        return val;
      }
      return null;
    },
    hdel: async () => 1,
  });

  try {
    const port = server.address().port;

    // First handshake succeeds and consumes ticket
    await new Promise((resolve, reject) => {
      const ws = new WebSocket(
        `ws://127.0.0.1:${port}/console`,
        ['ognivo-terminal', `ticket.${ticket}`],
        { headers: { Origin: 'http://localhost' } }
      );
      ws.on('open', () => {
        ws.close();
        resolve();
      });
      ws.on('error', reject);
    });

    // Second handshake with same ticket is rejected with 403
    await expectUpgradeRejected(
      `ws://127.0.0.1:${port}/console`,
      ['ognivo-terminal', `ticket.${ticket}`],
      { headers: { Origin: 'http://localhost' } },
      403
    );

    // Non-existent / expired Redis entry is rejected with 403
    await expectUpgradeRejected(
      `ws://127.0.0.1:${port}/console`,
      ['ognivo-terminal', 'ticket.expired-missing-redis-ticket-123'],
      { headers: { Origin: 'http://localhost' } },
      403
    );
  } finally {
    restoreRedis();
    if (savedAppKey === undefined) {
      delete process.env.APP_KEY;
    } else {
      process.env.APP_KEY = savedAppKey;
    }
  }
});

test('Proxy connects strictly to target from decrypted ticket payload, ignoring client-supplied IDs', async () => {
  const savedAppKey = process.env.APP_KEY;
  process.env.APP_KEY = 'base64:' + Buffer.alloc(32, 43).toString('base64');

  const fixture = createTestTlsServer();
  let restoreRedis = () => {};

  try {
    await new Promise((resolve) => fixture.tlsServer.listen(0, '127.0.0.1', resolve));
    const tlsPort = fixture.tlsServer.address().port;

    const upstreamUrls = [];
    fixture.testWss.on('connection', (_ws, req) => {
      upstreamUrls.push(req.url);
    });

    const ticket = 'payload-bound-ticket-1234567890';
    const expectedPath = '/1.0/operations/real-op-42/websocket';
    const payload = {
      session_id: 'sess-bound-42',
      virtual_machine_id: 42,
      host_endpoint: `https://127.0.0.1:${tlsPort}`,
      websocket_path: expectedPath,
      server_public_key_pin: fixture.validPin,
      session_expires_at: Math.floor(Date.now() / 1000) + 300,
    };
    const encryptedPayload = encryptTicketPayload(payload, ticket);

    restoreRedis = stubRedis({
      status: 'ready',
      call: async (cmd, key) => {
        if (cmd === 'GETDEL' && key.endsWith(`console:ticket:${ticket}`)) {
          return encryptedPayload;
        }
        return null;
      },
      hdel: async () => 1,
    });

    const port = server.address().port;

    const received = await new Promise((resolve, reject) => {
      const ws = new WebSocket(
        `ws://127.0.0.1:${port}/console?virtual_machine_id=9999&host_endpoint=https://127.0.0.1:1&websocket_path=/evil`,
        ['ognivo-terminal', `ticket.${ticket}`],
        { headers: { Origin: 'http://localhost' } }
      );
      ws.on('message', (data) => {
        ws.close();
        resolve(data.toString('utf8'));
      });
      ws.on('error', reject);
    });

    assert.strictEqual(received, 'connected-to-tls-upstream');
    assert.deepStrictEqual(upstreamUrls, [expectedPath]);
  } finally {
    restoreRedis();
    fixture.cleanup();
    if (savedAppKey === undefined) {
      delete process.env.APP_KEY;
    } else {
      process.env.APP_KEY = savedAppKey;
    }
  }
});

test('Ticket with missing, non-finite, or expired session_expires_at is rejected with 403', async () => {
  const nowSec = 1700000000;
  assert.strictEqual(hasValidSessionExpiry(null, nowSec), false);
  assert.strictEqual(hasValidSessionExpiry({}, nowSec), false);
  assert.strictEqual(hasValidSessionExpiry({ session_expires_at: '1700000100' }, nowSec), false);
  assert.strictEqual(hasValidSessionExpiry({ session_expires_at: Number.NaN }, nowSec), false);
  assert.strictEqual(hasValidSessionExpiry({ session_expires_at: Number.POSITIVE_INFINITY }, nowSec), false);
  assert.strictEqual(hasValidSessionExpiry({ session_expires_at: nowSec }, nowSec), false);
  assert.strictEqual(hasValidSessionExpiry({ session_expires_at: nowSec - 10 }, nowSec), false);
  assert.strictEqual(hasValidSessionExpiry({ session_expires_at: nowSec + 1 }, nowSec), true);

  const savedAppKey = process.env.APP_KEY;
  process.env.APP_KEY = 'base64:' + Buffer.alloc(32, 44).toString('base64');

  const now = Math.floor(Date.now() / 1000);
  const cases = [
    { ticket: 'missing-expiry-ticket-12345678', payload: { session_id: 's1', virtual_machine_id: 1, host_driver: 'fake' } },
    { ticket: 'nan-expiry-ticket-123456789012', payload: { session_id: 's2', virtual_machine_id: 2, host_driver: 'fake', session_expires_at: 'invalid' } },
    { ticket: 'past-expiry-ticket-12345678901', payload: { session_id: 's3', virtual_machine_id: 3, host_driver: 'fake', session_expires_at: now - 30 } },
    { ticket: 'exact-now-expiry-ticket-123456', payload: { session_id: 's4', virtual_machine_id: 4, host_driver: 'fake', session_expires_at: now } },
  ];

  const store = new Map(
    cases.map((c) => [`console:ticket:${c.ticket}`, encryptTicketPayload(c.payload, c.ticket)])
  );

  const restoreRedis = stubRedis({
    status: 'ready',
    call: async (cmd, key) => {
      if (cmd === 'GETDEL' && store.has(key)) {
        const val = store.get(key);
        store.delete(key);
        return val;
      }
      return null;
    },
  });

  try {
    const port = server.address().port;
    for (const c of cases) {
      await expectUpgradeRejected(
        `ws://127.0.0.1:${port}/console`,
        ['ognivo-terminal', `ticket.${c.ticket}`],
        { headers: { Origin: 'http://localhost' } },
        403
      );
    }
  } finally {
    restoreRedis();
    if (savedAppKey === undefined) {
      delete process.env.APP_KEY;
    } else {
      process.env.APP_KEY = savedAppKey;
    }
  }
});

test('Open fake session connection is closed with code 1008 when session_expires_at is reached', async () => {
  const savedAppKey = process.env.APP_KEY;
  process.env.APP_KEY = 'base64:' + Buffer.alloc(32, 45).toString('base64');

  const ticket = 'session-expiry-close-123456789';
  const payload = {
    session_id: 'sess-expiring-fake-1',
    virtual_machine_id: 61,
    host_driver: 'fake',
    driver: 'exec',
    instance_type: 'container',
    session_expires_at: Math.floor(Date.now() / 1000) + 1,
  };
  const encryptedPayload = encryptTicketPayload(payload, ticket);
  const hdelCalls = [];

  const restoreRedis = stubRedis({
    status: 'ready',
    call: async (cmd, key) => {
      if (cmd === 'GETDEL' && key.endsWith(`console:ticket:${ticket}`)) {
        return encryptedPayload;
      }
      return null;
    },
    hdel: async (key, field) => {
      hdelCalls.push({ key, field });
      return 1;
    },
  });

  try {
    const port = server.address().port;
    const closeResult = await new Promise((resolve, reject) => {
      const ws = new WebSocket(
        `ws://127.0.0.1:${port}/console`,
        ['ognivo-terminal', `ticket.${ticket}`],
        { headers: { Origin: 'http://localhost' } }
      );
      const timer = setTimeout(() => {
        ws.terminate();
        reject(new Error('Timed out waiting for session expiry close (1008)'));
      }, 4000);

      ws.on('close', (code, reason) => {
        clearTimeout(timer);
        resolve({ code, reason: reason.toString('utf8') });
      });
      ws.on('error', (err) => {
        clearTimeout(timer);
        reject(err);
      });
    });

    assert.strictEqual(closeResult.code, 1008);
    assert.strictEqual(closeResult.reason, 'Session expired');
    assert.ok(hdelCalls.some((c) => c.field === payload.session_id), 'Should run cleanupRedisSession on expiry');
  } finally {
    restoreRedis();
    if (savedAppKey === undefined) {
      delete process.env.APP_KEY;
    } else {
      process.env.APP_KEY = savedAppKey;
    }
  }
});

test('handleIncusSession closes clientWs with 1008, closes upstream sockets, and cleans Redis when session_expires_at is reached', async () => {
  const EventEmitter = require('events');
  const fixture = createTestTlsServer();
  const hdelCalls = [];
  const restoreRedis = stubRedis({
    status: 'ready',
    hdel: async (key, field) => {
      hdelCalls.push({ key, field });
      return 1;
    },
  });

  try {
    await new Promise((resolve) => fixture.tlsServer.listen(0, '127.0.0.1', resolve));
    const tlsPort = fixture.tlsServer.address().port;

    let serverDataWs = null;
    const upstreamReady = new Promise((resolve) => {
      fixture.testWss.once('connection', (ws) => {
        serverDataWs = ws;
        resolve();
      });
    });

    const mockClientWs = new EventEmitter();
    mockClientWs.readyState = WebSocket.OPEN;
    mockClientWs.send = () => {};
    const clientClosedPromise = new Promise((resolve) => {
      mockClientWs.close = (code, reason) => {
        mockClientWs.readyState = WebSocket.CLOSED;
        mockClientWs.emit('close');
        resolve({ code, reason });
      };
    });

    handleIncusSession(mockClientWs, {
      host_endpoint: `https://127.0.0.1:${tlsPort}`,
      websocket_path: '/1.0/operations/op-expiry/websocket',
      virtual_machine_id: 62,
      session_id: 'sess-expiring-incus-1',
      server_public_key_pin: fixture.validPin,
      session_expires_at: Math.floor(Date.now() / 1000) + 1,
    });

    await upstreamReady;
    const upstreamClosedPromise = new Promise((resolve) => serverDataWs.on('close', resolve));

    const closeResult = await clientClosedPromise;
    await upstreamClosedPromise;

    assert.strictEqual(closeResult.code, 1008);
    assert.strictEqual(closeResult.reason, 'Session expired');
    assert.ok(hdelCalls.some((c) => c.field === 'sess-expiring-incus-1'));
  } finally {
    restoreRedis();
    fixture.cleanup();
  }
});

after(() => {
  server.close();
  if (typeof server.closeAllConnections === 'function') {
    server.closeAllConnections();
  }
  redis.disconnect();
});
