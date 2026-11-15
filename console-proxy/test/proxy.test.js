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
    testWss.close();
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
      args: { width: 80, height: 24 },
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
      args: { width: 120, height: 40 },
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

after(() => {
  server.close();
  redis.disconnect();
});
