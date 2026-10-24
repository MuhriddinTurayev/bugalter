import dns from 'node:dns';
import http from 'node:http';
import https from 'node:https';
import { VERSION } from './config.js';

/** Thrown when Laravel says the task is closed (cancelled, expired, revoked): the agent must stop at once. */
export class TaskClosed extends Error {}

/**
 * "*.localhost" is loopback by definition (RFC 6761); browsers and curl know it, Windows name resolution may not.
 * Development servers such as demo1.localhost:8765 are therefore reached on 127.0.0.1 with their own Host header.
 */
export function lookup(hostname, options, callback) {
  if (hostname === 'localhost' || hostname.endsWith('.localhost')) {
    return options?.all ? callback(null, [{ address: '127.0.0.1', family: 4 }]) : callback(null, '127.0.0.1', 4);
  }
  return dns.lookup(hostname, options, callback);
}

/** One JSON POST; resolves {status, json}. No redirects are followed and the body is capped. */
export function postJson(url, token, body, timeoutMs = 30_000) {
  const target = new URL(url);
  const client = target.protocol === 'https:' ? https : http;
  const payload = JSON.stringify(body);

  return new Promise((resolve, reject) => {
    const req = client.request(target, {
      method: 'POST',
      lookup,
      timeout: timeoutMs,
      headers: {
        Authorization: `Bearer ${token}`,
        'Content-Type': 'application/json',
        'Content-Length': Buffer.byteLength(payload),
        Accept: 'application/json',
        'User-Agent': `bugalter-agent/${VERSION}`,
      },
    }, (res) => {
      const chunks = [];
      let size = 0;
      res.on('data', (chunk) => {
        size += chunk.length;
        if (size > 2_000_000) return req.destroy(new Error('Response too large'));
        chunks.push(chunk);
      });
      res.on('end', () => {
        let json = {};
        try { json = JSON.parse(Buffer.concat(chunks).toString('utf8')); } catch { /* not JSON */ }
        resolve({ status: res.statusCode, json });
      });
    });
    req.on('timeout', () => req.destroy(Object.assign(new Error('timeout'), { code: 'ETIMEDOUT' })));
    req.on('error', reject);
    req.end(payload);
  });
}

export class Api {
  constructor({ server, token }) {
    this.server = server;
    this.token = token;
  }

  async call(path, body = {}) {
    let response;
    try {
      response = await postJson(`${this.server}/api/agent${path}`, this.token, body);
    } catch (error) {
      throw new Error(`Cannot reach the server (${error.code ?? error.message})`);
    }

    const { status, json } = response;
    if (status === 401) throw new Error('The server rejected the agent key. Pair this computer again.');
    if (status === 409 && json.cancelled) throw new TaskClosed(json.error ?? 'task_closed');
    if (status < 200 || status >= 300) throw new Error(`Server error ${status}${json.error ? `: ${json.error}` : ''}`);
    return json;
  }

  heartbeat(environment) {
    return this.call('/heartbeat', { version: VERSION, environment });
  }

  async claim() {
    return (await this.call('/tasks/claim')).task;
  }

  event(task, kind, message, waitingFor = null) {
    return this.call(`/tasks/${task}/events`, { kind, message, ...(waitingFor ? { waiting_for: waitingFor } : {}) });
  }

  preview(task, fields) {
    return this.call(`/tasks/${task}/preview`, { fields });
  }

  decision(task) {
    return this.call(`/tasks/${task}/decision`);
  }

  paymentCheck(task, fields) {
    return this.call(`/tasks/${task}/payment-check`, { fields });
  }

  formCheck(task, fields) {
    return this.call(`/tasks/${task}/form-check`, { fields });
  }

  complete(task, result) {
    return this.call(`/tasks/${task}/complete`, { result });
  }

  fail(task, error, seenIdentifier = null) {
    return this.call(`/tasks/${task}/fail`, { error: String(error).slice(0, 1000), ...(seenIdentifier ? { seen_identifier: seenIdentifier } : {}) });
  }
}
