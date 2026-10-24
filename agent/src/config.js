import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { homedir } from 'node:os';
import { join } from 'node:path';
import { protect, unprotect } from './secrets.js';

export const VERSION = '0.1.0';

export const DIR = join(process.env.APPDATA ?? join(homedir(), 'AppData', 'Roaming'), 'bugalter-agent');
export const CONFIG_FILE = join(DIR, 'config.json');
export const PROFILE_DIR = join(DIR, 'browser-profile');
export const INSPECT_DIR = join(DIR, 'inspect');

/** Only my.soliq.uz is ever opened. The server comes from the pairing; the portal address is fixed here, not taken from a task. */
export const PORTAL_ORIGIN = 'https://my.soliq.uz';

export async function saveConfig({ server, token }) {
  await mkdir(DIR, { recursive: true });
  await writeFile(CONFIG_FILE, JSON.stringify({ server: normalizeServer(server), token: await protect(token) }, null, 2), { mode: 0o600 });
}

export async function loadConfig() {
  const env = process.env.BUGALTER_SERVER && process.env.BUGALTER_AGENT_TOKEN
    ? { server: normalizeServer(process.env.BUGALTER_SERVER), token: process.env.BUGALTER_AGENT_TOKEN }
    : null;
  if (env) return env;

  try {
    const stored = JSON.parse(await readFile(CONFIG_FILE, 'utf8'));
    return { server: normalizeServer(stored.server), token: await unprotect(stored.token) };
  } catch {
    return null;
  }
}

/** https only (plain http just for localhost during development); no path, no credentials. */
export function normalizeServer(value) {
  const url = new URL(String(value).trim());
  const local = ['localhost', '127.0.0.1'].includes(url.hostname) || url.hostname.endsWith('.localhost');
  if (url.protocol !== 'https:' && !(local && url.protocol === 'http:')) throw new Error('The server address must start with https://');
  if (url.username || url.password) throw new Error('The server address must not contain credentials.');
  return url.origin;
}
