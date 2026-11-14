'use strict';

const http = require('http');
const https = require('https');
const crypto = require('crypto');
const { URL } = require('url');
const { WebSocket, WebSocketServer } = require('ws');
const Redis = require('ioredis');
const fs = require('fs');

const PORT = parseInt(process.env.PORT || '8085', 10);
const REDIS_HOST = process.env.REDIS_HOST || '127.0.0.1';
const REDIS_PORT = parseInt(process.env.REDIS_PORT || '6379', 10);
const REDIS_PASSWORD = process.env.REDIS_PASSWORD || undefined;
const REDIS_PREFIX = process.env.REDIS_PREFIX || null;
const CLIENT_CERT_PATH = process.env.CLIENT_CERT_PATH || null;
const CLIENT_KEY_PATH = process.env.CLIENT_KEY_PATH || null;

const TICKET_REGEX = /^[A-Za-z0-9_-]{16,128}$/;

const redis = new Redis({
  host: REDIS_HOST,
  port: REDIS_PORT,
  password: REDIS_PASSWORD,
  lazyConnect: true,
  maxRetriesPerRequest: 3,
});

redis.connect().catch((err) => {
  console.warn(`[console-proxy] Redis initial connection failed: ${err.message}. Will retry on request.`);
});

const server = http.createServer((req, res) => {
  const url = new URL(req.url, `http://${req.headers.host || 'localhost'}`);
  if (url.pathname === '/health') {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ status: 'ok', service: 'console-proxy' }));
    return;
  }

  res.writeHead(404, { 'Content-Type': 'text/plain' });
  res.end('Not Found');
});

const wss = new WebSocketServer({ noServer: true });

async function redeemTicket(ticket) {
  if (!ticket || !TICKET_REGEX.test(ticket)) {
    return null;
  }

  // Deterministic candidate keys; never perform full-keyspace glob scans
  const candidates = [
    REDIS_PREFIX ? `${REDIS_PREFIX}console:ticket:${ticket}` : null,
    `ognivo-database-console:ticket:${ticket}`,
    `ognivo-dev-database-console:ticket:${ticket}`,
    `laravel-database-console:ticket:${ticket}`,
    `console:ticket:${ticket}`,
  ].filter(Boolean);

  const uniqueCandidates = [...new Set(candidates)];

  for (const k of uniqueCandidates) {
    try {
      const res = await redis.call('GETDEL', k);
      if (res) return res;
    } catch {
      const res = await redis.eval(
        "local v = redis.call('get', KEYS[1]); if v then redis.call('del', KEYS[1]); end; return v",
        1,
        k
      );
      if (res) return res;
    }
  }

  return null;
}

server.on('upgrade', async (req, socket, head) => {
  const url = new URL(req.url, `http://${req.headers.host || 'localhost'}`);
  if (url.pathname !== '/console') {
    socket.write('HTTP/1.1 404 Not Found\r\n\r\n');
    socket.destroy();
    return;
  }

  const ticket = url.searchParams.get('ticket');
  if (!ticket || ticket.trim() === '') {
    socket.write('HTTP/1.1 401 Unauthorized\r\n\r\n');
    socket.destroy();
    return;
  }

  if (!TICKET_REGEX.test(ticket)) {
    socket.write('HTTP/1.1 400 Bad Request\r\n\r\n');
    socket.destroy();
    return;
  }

  try {
    const rawPayload = await redeemTicket(ticket);

    if (!rawPayload) {
      socket.write('HTTP/1.1 403 Forbidden\r\n\r\n');
      socket.destroy();
      return;
    }

    const payload = JSON.parse(rawPayload);

    wss.handleUpgrade(req, socket, head, (clientWs) => {
      wss.emit('connection', clientWs, req, payload);
    });
  } catch (err) {
    console.error(`[console-proxy] Handshake error: ${err.message}`);
    socket.write('HTTP/1.1 500 Internal Server Error\r\n\r\n');
    socket.destroy();
  }
});

wss.on('connection', (clientWs, req, payload) => {
  // If hypervisor driver is fake, provide interactive mock terminal
  if (payload.host_driver === 'fake') {
    handleFakeSession(clientWs, payload);
    return;
  }

  handleIncusSession(clientWs, payload);
});

function handleFakeSession(clientWs, payload) {
  clientWs.send(
    `\r\n\x1b[32m[Connected to Fake Hypervisor Console: Instance #${payload.virtual_machine_id}]\x1b[0m\r\n` +
    `\x1b[36mConsole Mode: ${payload.console_type || 'console'} (${payload.instance_type})\x1b[0m\r\n\r\n` +
    `Welcome to Linux (TTY console)\r\n` +
    `fake-instance login: `
  );

  let buffer = '';
  clientWs.on('message', (data, isBinary) => {
    if (isBinary) {
      clientWs.send(data, { binary: true });
      return;
    }

    const str = data.toString();
    if (str === '\r' || str === '\n') {
      clientWs.send('\r\nPassword: \r\nfake-instance:~# ');
      buffer = '';
    } else if (str === '\u0003') { // Ctrl+C
      clientWs.send('^C\r\nfake-instance:~# ');
      buffer = '';
    } else if (str === '\u007f') { // Backspace
      if (buffer.length > 0) {
        buffer = buffer.slice(0, -1);
        clientWs.send('\b \b');
      }
    } else {
      buffer += str;
      clientWs.send(str); // Echo back
    }
  });

  clientWs.on('error', (err) => {
    console.error(`[console-proxy] Fake session client error: ${err.message}`);
  });
}

class PinnedHttpsAgent extends https.Agent {
  constructor(expectedPin, options = {}) {
    super({
      ...options,
      checkServerIdentity: (hostname, cert) => this.checkServerIdentity(hostname, cert),
    });
    this.expectedPin = expectedPin;
  }

  checkServerIdentity(hostname, cert) {
    if (!this.expectedPin || typeof this.expectedPin !== 'string' || !this.expectedPin.trim()) {
      return new Error('Missing expected server public key pin');
    }

    if (!cert || !cert.raw) {
      return new Error('Missing peer certificate during TLS handshake');
    }

    try {
      const x509 = new crypto.X509Certificate(cert.raw);
      const spkiDer = x509.publicKey.export({ type: 'spki', format: 'der' });
      const computedPin = 'sha256//' + crypto.createHash('sha256').update(spkiDer).digest('base64');

      if (computedPin !== this.expectedPin.trim()) {
        console.error(`[console-proxy] PIN MISMATCH! Expected ${this.expectedPin}, got ${computedPin}`);
        return new Error(`Server public key pin mismatch: expected ${this.expectedPin}, got ${computedPin}`);
      }
    } catch (err) {
      return new Error(`Failed to parse certificate public key: ${err.message}`);
    }

    return undefined;
  }

  createConnection(options, callback) {
    const opts = {
      ...options,
      checkServerIdentity: (host, cert) => this.checkServerIdentity(host, cert),
    };
    const socket = super.createConnection(opts, callback);

    socket.prependListener('secureConnect', () => {
      const peerCert = socket.getPeerCertificate(true);
      const err = this.checkServerIdentity(opts.host || opts.servername, peerCert);
      if (err) {
        socket.destroy(err);
      }
    });

    return socket;
  }
}

function handleIncusSession(clientWs, payload) {
  const hostEndpoint = payload.host_endpoint || '';
  const wsPath = payload.websocket_path || '';

  const parsedHost = new URL(hostEndpoint);
  const targetProtocol = parsedHost.protocol === 'https:' ? 'wss:' : 'ws:';
  const targetUrl = `${targetProtocol}//${parsedHost.host}${wsPath}`;

  const options = {
    rejectUnauthorized: false,
  };

  if (
    typeof payload.client_certificate === 'string' &&
    payload.client_certificate.trim() !== '' &&
    typeof payload.client_private_key === 'string' &&
    payload.client_private_key.trim() !== ''
  ) {
    options.cert = payload.client_certificate;
    options.key = payload.client_private_key;
  } else {
    if (CLIENT_CERT_PATH && fs.existsSync(CLIENT_CERT_PATH)) {
      options.cert = fs.readFileSync(CLIENT_CERT_PATH);
    }
    if (CLIENT_KEY_PATH && fs.existsSync(CLIENT_KEY_PATH)) {
      options.key = fs.readFileSync(CLIENT_KEY_PATH);
    }
  }

  if (targetProtocol === 'wss:') {
    options.agent = new PinnedHttpsAgent(payload.server_public_key_pin, {
      rejectUnauthorized: false,
      cert: options.cert,
      key: options.key,
    });
  }

  const incusWs = new WebSocket(targetUrl, options);

  incusWs.on('open', () => {
    clientWs.on('message', (msg, isBinary) => {
      if (incusWs.readyState === WebSocket.OPEN) {
        incusWs.send(msg, { binary: isBinary });
      }
    });

    incusWs.on('message', (msg, isBinary) => {
      if (clientWs.readyState === WebSocket.OPEN) {
        clientWs.send(msg, { binary: isBinary });
      }
    });
  });

  incusWs.on('error', (err) => {
    console.error(`[console-proxy] Incus upstream socket error: ${err.message}`);
    if (clientWs.readyState === WebSocket.OPEN) {
      clientWs.send('\r\n\x1b[31m[Console Error: Hypervisor upstream connection failed]\x1b[0m\r\n');
      clientWs.close(1011, 'Hypervisor connection error');
    }
  });

  clientWs.on('close', () => {
    if (incusWs.readyState === WebSocket.OPEN || incusWs.readyState === WebSocket.CONNECTING) {
      incusWs.close();
    }
  });

  incusWs.on('close', () => {
    if (clientWs.readyState === WebSocket.OPEN) {
      clientWs.close();
    }
  });
}

server.listen(PORT, '0.0.0.0', () => {
  console.log(`[console-proxy] Listening on port ${PORT}`);
});

module.exports = { server, redis, redeemTicket, TICKET_REGEX, PinnedHttpsAgent, handleIncusSession };
