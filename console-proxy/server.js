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
const DEFAULT_MAX_PAYLOAD = parseInt(process.env.MAX_MESSAGE_BYTES || '65536', 10);
const DEFAULT_IDLE_TIMEOUT_SECONDS = parseInt(process.env.IDLE_TIMEOUT_SECONDS || '900', 10);
const DEFAULT_ACTIVITY_REFRESH_INTERVAL_MS = parseInt(process.env.ACTIVITY_REFRESH_INTERVAL_MS || '30000', 10);

const TICKET_REGEX = /^[A-Za-z0-9_-]{16,128}$/;
const TICKET_HKDF_INFO = 'ognivo-console-ticket-v1';

function resolveTicketKeys() {
  const candidates = [
    process.env.APP_KEY || '',
    ...(process.env.APP_PREVIOUS_KEYS || '').split(','),
  ];
  const keys = [];
  for (const raw of candidates) {
    const keyStr = raw.trim();
    if (!keyStr) continue;
    const rawKeyBuf = keyStr.startsWith('base64:')
      ? Buffer.from(keyStr.slice(7), 'base64')
      : Buffer.from(keyStr, 'utf8');
    if (rawKeyBuf.length === 0) continue;
    const derived = Buffer.from(
      crypto.hkdfSync('sha256', rawKeyBuf, Buffer.alloc(0), Buffer.from(TICKET_HKDF_INFO, 'utf8'), 32)
    );
    keys.push(derived);
  }
  return keys;
}

function encryptTicketPayload(payload, ticket) {
  const keys = resolveTicketKeys();
  if (keys.length === 0) {
    throw new Error('APP_KEY is not configured for ticket encryption');
  }
  const iv = crypto.randomBytes(12);
  const cipher = crypto.createCipheriv('aes-256-gcm', keys[0], iv, { authTagLength: 16 });
  cipher.setAAD(Buffer.from(String(ticket), 'utf8'));
  const ct = Buffer.concat([cipher.update(JSON.stringify(payload), 'utf8'), cipher.final()]);
  const tag = cipher.getAuthTag();
  return JSON.stringify({
    v: 1,
    alg: 'AES-256-GCM',
    iv: iv.toString('base64'),
    tag: tag.toString('base64'),
    ct: ct.toString('base64'),
  });
}

function decryptTicketPayload(rawPayload, ticket) {
  const parsed = JSON.parse(rawPayload);
  if (
    !parsed ||
    typeof parsed !== 'object' ||
    parsed.v !== 1 ||
    typeof parsed.iv !== 'string' ||
    typeof parsed.tag !== 'string' ||
    typeof parsed.ct !== 'string'
  ) {
    throw new Error('Unencrypted or invalid ticket envelope');
  }

  const iv = Buffer.from(parsed.iv, 'base64');
  const tag = Buffer.from(parsed.tag, 'base64');
  const ct = Buffer.from(parsed.ct, 'base64');

  if (iv.length !== 12 || tag.length !== 16 || ct.length === 0) {
    throw new Error('Invalid ticket envelope parameters');
  }

  const keys = resolveTicketKeys();
  if (keys.length === 0) {
    throw new Error('APP_KEY is not configured for ticket decryption');
  }

  for (const derivedKey of keys) {
    try {
      const decipher = crypto.createDecipheriv('aes-256-gcm', derivedKey, iv, { authTagLength: 16 });
      decipher.setAuthTag(tag);
      decipher.setAAD(Buffer.from(String(ticket), 'utf8'));
      const plaintext = Buffer.concat([decipher.update(ct), decipher.final()]).toString('utf8');
      return JSON.parse(plaintext);
    } catch {
      // Try next key in case of APP_PREVIOUS_KEYS rotation
    }
  }

  throw new Error('Failed to decrypt or authenticate console ticket payload');
}

const redis = new Redis({
  host: REDIS_HOST,
  port: REDIS_PORT,
  password: REDIS_PASSWORD,
  lazyConnect: true,
  maxRetriesPerRequest: 3,
});

redis.on('error', () => {
  // Prevent unhandled error event when Redis is offline or restarting
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

const wss = new WebSocketServer({
  noServer: true,
  maxPayload: DEFAULT_MAX_PAYLOAD,
  handleProtocols: (protocols) => {
    const hasOgnivo = typeof protocols.has === 'function'
      ? protocols.has('ognivo-terminal')
      : Array.isArray(protocols) && protocols.includes('ognivo-terminal');
    if (hasOgnivo) {
      return 'ognivo-terminal';
    }
    for (const proto of protocols) {
      if (!proto.startsWith('ticket.')) {
        return proto;
      }
    }
    return false;
  },
});

function extractTicket(req, url) {
  if (req && req.headers) {
    const wsProtocol = req.headers['sec-websocket-protocol'];
    if (typeof wsProtocol === 'string') {
      const parts = wsProtocol.split(',');
      for (const part of parts) {
        const trimmed = part.trim();
        if (trimmed.startsWith('ticket.')) {
          const ticket = trimmed.slice('ticket.'.length).trim();
          if (ticket) return ticket;
        }
      }
    }

    const authHeader = req.headers['authorization'];
    if (typeof authHeader === 'string') {
      const trimmedAuth = authHeader.trim();
      if (trimmedAuth.toLowerCase().startsWith('bearer ')) {
        const token = trimmedAuth.slice(7).trim();
        if (token) return token;
      }
    }
  }

  const parsedUrl = typeof url === 'string'
    ? new URL(url, 'http://localhost')
    : url;

  if (parsedUrl && parsedUrl.searchParams) {
    const queryTicket = parsedUrl.searchParams.get('ticket');
    if (queryTicket && queryTicket.trim()) {
      return queryTicket.trim();
    }
  }

  return null;
}

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

function getRedisPrefixes() {
  const prefixes = [
    REDIS_PREFIX,
    'ognivo-database-',
    'ognivo-dev-database-',
    'laravel-database-',
    '',
  ].filter((p) => p !== null && p !== undefined);

  return [...new Set(prefixes)];
}

const TOUCH_SESSION_LUA = `
local raw = redis.call('HGET', KEYS[1], ARGV[1])
if not raw then return 0 end
local ok, data = pcall(cjson.decode, raw)
if not ok or type(data) ~= 'table' then return 0 end
local now = tonumber(ARGV[2])
data['last_activity_at'] = now
if ARGV[3] == '1' and (not data['redeemed_at'] or data['redeemed_at'] == cjson.null) then
  data['redeemed_at'] = now
end
redis.call('HSET', KEYS[1], ARGV[1], cjson.encode(data))
local ttl = tonumber(ARGV[4])
if ttl and ttl > 0 then
  redis.call('EXPIRE', KEYS[1], ttl)
end
return 1
`.trim();

/**
 * Update last_activity_at (and optionally redeemed_at) for an active session in Redis
 * and refresh the VM session hash TTL.
 */
async function touchRedisSession(payload, markRedeemed = false) {
  if (!payload || !payload.session_id || !payload.virtual_machine_id) {
    return false;
  }

  if (redis.status && redis.status !== 'ready') {
    return false;
  }

  const sid = String(payload.session_id);
  const vmId = String(payload.virtual_machine_id);
  const nowSec = Date.now() / 1000;
  const idleTimeoutSec = parseInt(payload.idle_timeout_seconds, 10) || DEFAULT_IDLE_TIMEOUT_SECONDS;
  const ttlSec = idleTimeoutSec + 60;
  const uniquePrefixes = getRedisPrefixes();

  for (const prefix of uniquePrefixes) {
    const vmKey = `${prefix}console:vm_sessions:${vmId}`;
    if (typeof redis.eval === 'function') {
      try {
        const res = await redis.eval(
          TOUCH_SESSION_LUA,
          1,
          vmKey,
          sid,
          String(nowSec),
          markRedeemed ? '1' : '0',
          String(ttlSec)
        );
        if (res === 1 || res === '1') {
          return true;
        }
        continue;
      } catch {
        // Fallback when redis.eval fails or Lua cjson is unavailable
      }
    }

    try {
      const raw = await redis.hget(vmKey, sid);
      if (!raw) {
        continue;
      }
      const data = JSON.parse(raw);
      if (!data || typeof data !== 'object' || Array.isArray(data)) {
        continue;
      }
      data.last_activity_at = nowSec;
      if (markRedeemed && (data.redeemed_at === undefined || data.redeemed_at === null)) {
        data.redeemed_at = nowSec;
      }
      await redis.hset(vmKey, sid, JSON.stringify(data));
      if (ttlSec > 0) {
        await redis.expire(vmKey, ttlSec);
      }
      return true;
    } catch {
      // Ignore errors so proxy never crashes
    }
  }

  return false;
}

function cleanupRedisSession(payload) {
  if (!payload || !payload.session_id || !payload.virtual_machine_id) {
    return;
  }

  if (redis.status && redis.status !== 'ready') {
    return;
  }

  const sid = String(payload.session_id);
  const vmId = String(payload.virtual_machine_id);
  const uniquePrefixes = getRedisPrefixes();

  for (const prefix of uniquePrefixes) {
    const vmKey = `${prefix}console:vm_sessions:${vmId}`;
    const idxKey = `${prefix}console:session_index`;
    try {
      redis.hdel(vmKey, sid).catch(() => {});
      redis.hdel(idxKey, sid).catch(() => {});
    } catch {
      // Ignore errors if Redis is disconnected in unit tests
    }
  }
}

function cancelIncusOperation(payload, tlsOptions = {}) {
  if (!payload || !payload.operation_id || !payload.host_endpoint || payload.host_driver === 'fake') {
    return;
  }

  try {
    const parsedHost = new URL(payload.host_endpoint);
    const isHttps = parsedHost.protocol === 'https:';
    const httpModule = isHttps ? https : http;

    const reqUrl = `${parsedHost.origin}/1.0/operations/${encodeURIComponent(payload.operation_id)}`;
    const reqOptions = {
      method: 'DELETE',
      timeout: 3000,
    };

    if (isHttps) {
      if (tlsOptions.agent) reqOptions.agent = tlsOptions.agent;
      if (tlsOptions.cert) reqOptions.cert = tlsOptions.cert;
      if (tlsOptions.key) reqOptions.key = tlsOptions.key;
      reqOptions.rejectUnauthorized = false;
    }

    const req = httpModule.request(reqUrl, reqOptions, (res) => {
      res.resume();
    });

    req.on('error', () => {
      // Ignore errors so proxy never crashes
    });

    req.on('timeout', () => {
      req.destroy();
    });

    req.end();
  } catch {
    // Ignore unexpected errors
  }
}

server.on('upgrade', async (req, socket, head) => {
  const url = new URL(req.url, `http://${req.headers.host || 'localhost'}`);
  if (url.pathname !== '/console') {
    socket.write('HTTP/1.1 404 Not Found\r\n\r\n');
    socket.destroy();
    return;
  }

  const ticket = extractTicket(req, url);
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

    let payload;
    try {
      payload = decryptTicketPayload(rawPayload, ticket);
    } catch (decryptErr) {
      console.error(`[console-proxy] Ticket decryption failed: ${decryptErr.message}`);
      socket.write('HTTP/1.1 403 Forbidden\r\n\r\n');
      socket.destroy();
      return;
    }

    wss.handleUpgrade(req, socket, head, (clientWs) => {
      wss.emit('connection', clientWs, req, payload);
    });
  } catch (err) {
    console.error(`[console-proxy] Handshake error: ${err.message}`);
    socket.write('HTTP/1.1 500 Internal Server Error\r\n\r\n');
    socket.destroy();
  }
});

function isFakeDriverAllowed() {
  const allowFake = (process.env.ALLOW_FAKE_DRIVER || '').toLowerCase();
  if (allowFake === 'true' || allowFake === '1') {
    return true;
  }
  if (allowFake === 'false' || allowFake === '0') {
    return false;
  }
  return (process.env.NODE_ENV || 'development').toLowerCase() !== 'production';
}

wss.on('connection', (clientWs, req, payload) => {
  // If hypervisor driver is fake, provide interactive mock terminal when allowed
  if (payload.host_driver === 'fake') {
    if (!isFakeDriverAllowed()) {
      console.error('[console-proxy] Rejected fake hypervisor session: fake driver is disabled in production.');
      cleanupRedisSession(payload);
      if (clientWs.readyState === WebSocket.OPEN) {
        try {
          clientWs.close(1008, 'Fake hypervisor driver is disabled');
        } catch {}
      }
      return;
    }
    handleFakeSession(clientWs, payload);
    return;
  }

  handleIncusSession(clientWs, payload);
});

function handleFakeSession(clientWs, payload) {
  const isExec = payload.driver === 'exec' || (payload.driver !== 'console' && payload.instance_type === 'container');

  const idleTimeoutMs = parseInt(payload.idle_timeout_ms, 10) ||
    ((parseInt(payload.idle_timeout_seconds, 10) || DEFAULT_IDLE_TIMEOUT_SECONDS) * 1000);
  const maxBytes = parseInt(payload.max_message_bytes, 10) || DEFAULT_MAX_PAYLOAD;
  const activityRefreshIntervalMs = parseInt(payload.activity_refresh_interval_ms, 10) || DEFAULT_ACTIVITY_REFRESH_INTERVAL_MS;

  let cleanedUp = false;
  let idleTimer = null;
  let lastActivityTouchMs = Date.now();

  touchRedisSession(payload, true).catch(() => {});

  function teardownFakeSession() {
    if (cleanedUp) return;
    cleanedUp = true;
    if (idleTimer) {
      clearTimeout(idleTimer);
      idleTimer = null;
    }
    cleanupRedisSession(payload);
  }

  function recordActivity() {
    if (cleanedUp) return;
    const nowMs = Date.now();
    if (nowMs - lastActivityTouchMs >= activityRefreshIntervalMs) {
      lastActivityTouchMs = nowMs;
      touchRedisSession(payload, false).catch(() => {});
    }
  }

  function resetIdleTimer(isActivity = false) {
    if (cleanedUp) return;
    if (idleTimer) {
      clearTimeout(idleTimer);
      idleTimer = null;
    }
    if (isActivity) {
      recordActivity();
    }
    if (idleTimeoutMs > 0) {
      idleTimer = setTimeout(() => {
        if (clientWs.readyState === WebSocket.OPEN) {
          try {
            clientWs.send('\r\n\x1b[33m[Console session closed due to inactivity]\x1b[0m\r\n');
          } catch {}
          try {
            clientWs.close(4000, 'Idle timeout exceeded');
          } catch {}
        }
      }, idleTimeoutMs);
      if (typeof idleTimer.unref === 'function') idleTimer.unref();
    }
  }
  resetIdleTimer(false);

  if (isExec) {
    clientWs.send(
      `\r\n\x1b[32m[Connected to Fake Hypervisor Exec Shell: Instance #${payload.virtual_machine_id}]\x1b[0m\r\n` +
      `\x1b[36mDriver: exec (${payload.instance_type || 'container'})\x1b[0m\r\n\r\n` +
      `root@fake-instance:~# `
    );
  } else {
    clientWs.send(
      `\r\n\x1b[32m[Connected to Fake Hypervisor Console: Instance #${payload.virtual_machine_id}]\x1b[0m\r\n` +
      `\x1b[36mConsole Mode: ${payload.console_type || 'console'} (${payload.instance_type})\x1b[0m\r\n\r\n` +
      `Welcome to Linux (TTY console)\r\n` +
      `fake-instance login: `
    );
  }

  let buffer = '';
  clientWs.on('message', (data, isBinary) => {
    if (cleanedUp) return;

    const byteLength = Buffer.isBuffer(data)
      ? data.length
      : (typeof data === 'string' ? Buffer.byteLength(data) : Buffer.from(data).length);
    if (byteLength > maxBytes) {
      if (clientWs.readyState === WebSocket.OPEN) {
        try { clientWs.close(1009, 'Message too big'); } catch {}
      }
      teardownFakeSession();
      return;
    }

    resetIdleTimer(true);

    if (isBinary) {
      clientWs.send(data, { binary: true });
      return;
    }

    const str = data.toString();
    if (isExec) {
      if (str === '\r' || str === '\n') {
        clientWs.send('\r\nroot@fake-instance:~# ');
        buffer = '';
      } else if (str === '\u0003') { // Ctrl+C
        clientWs.send('^C\r\nroot@fake-instance:~# ');
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
      return;
    }

    // console mode
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

  clientWs.on('close', () => {
    teardownFakeSession();
  });

  clientWs.on('error', (err) => {
    console.error(`[console-proxy] Fake session client error: ${err.message}`);
  });
}

class PinnedHttpsAgent extends https.Agent {
  constructor(expectedPin, options = {}) {
    super({
      maxCachedSessions: 0,
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

  let controlWs = null;
  let terminalWidth = parseInt(payload.width, 10) || 80;
  let terminalHeight = parseInt(payload.height, 10) || 24;

  const maxBytes = parseInt(payload.max_message_bytes, 10) || DEFAULT_MAX_PAYLOAD;
  const idleTimeoutMs = parseInt(payload.idle_timeout_ms, 10) ||
    ((parseInt(payload.idle_timeout_seconds, 10) || DEFAULT_IDLE_TIMEOUT_SECONDS) * 1000);
  const activityRefreshIntervalMs = parseInt(payload.activity_refresh_interval_ms, 10) || DEFAULT_ACTIVITY_REFRESH_INTERVAL_MS;

  let cleanedUp = false;
  let idleTimer = null;
  let lastActivityTouchMs = Date.now();
  const pendingClientMessages = [];

  touchRedisSession(payload, true).catch(() => {});

  function teardownSession(terminateRemoteProcess = false) {
    if (cleanedUp) return;
    cleanedUp = true;

    if (idleTimer) {
      clearTimeout(idleTimer);
      idleTimer = null;
    }
    pendingClientMessages.length = 0;

    if (terminateRemoteProcess) {
      if (controlWs && controlWs.readyState === WebSocket.OPEN) {
        try {
          controlWs.send(JSON.stringify({ command: 'signal', signal: 1 }));
        } catch {}
        try {
          controlWs.send(JSON.stringify({ command: 'signal', signal: 15 }));
        } catch {}
      }
      cancelIncusOperation(payload, options);
    }

    if (incusWs && (incusWs.readyState === WebSocket.OPEN || incusWs.readyState === WebSocket.CONNECTING)) {
      try { incusWs.close(); } catch {}
    }
    if (controlWs && (controlWs.readyState === WebSocket.OPEN || controlWs.readyState === WebSocket.CONNECTING)) {
      try { controlWs.close(); } catch {}
    }

    cleanupRedisSession(payload);
  }

  function recordActivity() {
    if (cleanedUp) return;
    const nowMs = Date.now();
    if (nowMs - lastActivityTouchMs >= activityRefreshIntervalMs) {
      lastActivityTouchMs = nowMs;
      touchRedisSession(payload, false).catch(() => {});
    }
  }

  function resetIdleTimer(isActivity = false) {
    if (cleanedUp) return;
    if (idleTimer) {
      clearTimeout(idleTimer);
      idleTimer = null;
    }
    if (isActivity) {
      recordActivity();
    }
    if (idleTimeoutMs > 0 && !cleanedUp) {
      idleTimer = setTimeout(() => {
        if (clientWs.readyState === WebSocket.OPEN) {
          try {
            clientWs.send('\r\n\x1b[33m[Console session closed due to inactivity]\x1b[0m\r\n');
          } catch {}
          try {
            clientWs.close(4000, 'Idle timeout exceeded');
          } catch {}
        }
        teardownSession(true);
      }, idleTimeoutMs);
      if (typeof idleTimer.unref === 'function') idleTimer.unref();
    }
  }

  resetIdleTimer(false);

  if (
    typeof payload.control_path === 'string' &&
    payload.control_path.trim() !== ''
  ) {
    const ctrlUrl = `${targetProtocol}//${parsedHost.host}${payload.control_path}`;
    controlWs = new WebSocket(ctrlUrl, options);
    controlWs.on('open', () => {
      controlWs.send(
        JSON.stringify({
          command: 'window-resize',
          args: { width: String(terminalWidth), height: String(terminalHeight) },
        })
      );
    });
    controlWs.on('error', (err) => {
      console.warn(`[console-proxy] Incus control socket error: ${err.message}`);
    });
  }

  const incusWs = new WebSocket(targetUrl, options);
  let incusReady = false;

  incusWs.on('open', () => {
    incusReady = true;
    while (pendingClientMessages.length > 0) {
      const buf = pendingClientMessages.shift();
      if (incusWs.readyState === WebSocket.OPEN) {
        incusWs.send(buf, { binary: true });
      }
    }
  });

  clientWs.on('message', (msg, isBinary) => {
    if (cleanedUp) return;

    const byteLength = Buffer.isBuffer(msg)
      ? msg.length
      : (typeof msg === 'string' ? Buffer.byteLength(msg) : Buffer.from(msg).length);
    if (byteLength > maxBytes) {
      if (clientWs.readyState === WebSocket.OPEN) {
        try {
          clientWs.close(1009, 'Message too big');
        } catch {}
      }
      teardownSession(true);
      return;
    }

    resetIdleTimer(true);

    // Check if this is a resize control message from client
    if (!isBinary) {
      const text = typeof msg === 'string' ? msg : msg.toString('utf8');
      try {
        const parsed = JSON.parse(text);
        if (parsed && typeof parsed === 'object' && parsed.type === 'resize') {
          const width = parseInt(parsed.cols || parsed.width || 80, 10) || 80;
          const height = parseInt(parsed.rows || parsed.height || 24, 10) || 24;
          terminalWidth = width;
          terminalHeight = height;
          if (controlWs && controlWs.readyState === WebSocket.OPEN) {
            controlWs.send(
              JSON.stringify({
                command: 'window-resize',
                args: { width: String(width), height: String(height) },
              })
            );
          }
          return;
        }
      } catch {
        // Not a JSON message, pass through to incusWs
      }
    }

    const buf = Buffer.isBuffer(msg) ? msg : Buffer.from(msg);

    if (incusReady && incusWs.readyState === WebSocket.OPEN) {
      incusWs.send(buf, { binary: true });
    } else if (!incusReady && incusWs.readyState === WebSocket.CONNECTING) {
      pendingClientMessages.push(buf);
    }
  });

  incusWs.on('message', (msg, isBinary) => {
    if (cleanedUp) return;
    resetIdleTimer(true);
    if (clientWs.readyState === WebSocket.OPEN) {
      clientWs.send(msg, { binary: isBinary });
    }
  });

  incusWs.on('error', (err) => {
    console.error(`[console-proxy] Incus upstream socket error: ${err.message}`);
    if (clientWs.readyState === WebSocket.OPEN) {
      clientWs.send('\r\n\x1b[31m[Console Error: Hypervisor upstream connection failed]\x1b[0m\r\n');
      clientWs.close(1011, 'Hypervisor connection error');
    }
    teardownSession(false);
  });

  incusWs.on('close', () => {
    if (clientWs.readyState === WebSocket.OPEN) {
      clientWs.close();
    }
    teardownSession(false);
  });

  clientWs.on('close', () => {
    teardownSession(true);
  });

  clientWs.on('error', () => {
    teardownSession(true);
  });
}

server.listen(PORT, '0.0.0.0', () => {
  console.log(`[console-proxy] Listening on port ${PORT}`);
});

module.exports = {
  server,
  redis,
  redeemTicket,
  extractTicket,
  TICKET_REGEX,
  PinnedHttpsAgent,
  handleIncusSession,
  handleFakeSession,
  touchRedisSession,
  isFakeDriverAllowed,
  resolveTicketKeys,
  encryptTicketPayload,
  decryptTicketPayload,
};
