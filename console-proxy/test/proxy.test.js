'use strict';

const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const http = require('http');
const { WebSocket } = require('ws');
const { server, redis, redeemTicket, TICKET_REGEX } = require('../server.js');

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

after(() => {
  server.close();
  redis.disconnect();
});
