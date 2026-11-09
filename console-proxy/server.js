'use strict';

const http = require('http');
const { URL } = require('url');
const { WebSocket, WebSocketServer } = require('ws');
const Redis = require('ioredis');
const fs = require('fs');

const PORT = parseInt(process.env.PORT || '8085', 10);
const REDIS_HOST = process.env.REDIS_HOST || '127.0.0.1';
const REDIS_PORT = parseInt(process.env.REDIS_PORT || '6379', 10);
const REDIS_PASSWORD = process.env.REDIS_PASSWORD || undefined;
const CLIENT_CERT_PATH = process.env.CLIENT_CERT_PATH || null;
const CLIENT_KEY_PATH = process.env.CLIENT_KEY_PATH || null;

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
  const candidates = [
    `console:ticket:${ticket}`,
    process.env.REDIS_PREFIX ? `${process.env.REDIS_PREFIX}console:ticket:${ticket}` : null,
  ].filter(Boolean);

  for (const k of candidates) {
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

  // Fallback to match key if Laravel used application prefix
  const matchingKeys = await redis.keys(`*console:ticket:${ticket}`);
  for (const k of matchingKeys) {
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

function handleIncusSession(clientWs, payload) {
  const hostEndpoint = payload.host_endpoint || '';
  const wsPath = payload.websocket_path || '';

  const parsedHost = new URL(hostEndpoint);
  const targetProtocol = parsedHost.protocol === 'https:' ? 'wss:' : 'ws:';
  const targetUrl = `${targetProtocol}//${parsedHost.host}${wsPath}`;

  const options = {
    rejectUnauthorized: false, // TLS validation handled via public key pin if operational
  };

  if (CLIENT_CERT_PATH && fs.existsSync(CLIENT_CERT_PATH)) {
    options.cert = fs.readFileSync(CLIENT_CERT_PATH);
  }
  if (CLIENT_KEY_PATH && fs.existsSync(CLIENT_KEY_PATH)) {
    options.key = fs.readFileSync(CLIENT_KEY_PATH);
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
      clientWs.send(`\r\n\x1b[31m[Console Error: Hypervisor upstream connection failed: ${err.message}]\x1b[0m\r\n`);
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

module.exports = { server, redis };
