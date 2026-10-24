import { existsSync } from 'node:fs';
import { readdir } from 'node:fs/promises';
import net from 'node:net';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { findBrowser } from './browser.js';

const run = promisify(execFile);
const EIMZO_DIRS = ['C:\\Program Files (x86)\\E-IMZO', 'C:\\Program Files\\E-IMZO'];
const KEY_DIRS = ['C:\\DSKEYS'];

const portOpen = (port) => new Promise((resolve) => {
  const socket = net.connect({ host: '127.0.0.1', port, timeout: 1500 });
  socket.on('connect', () => { socket.destroy(); resolve(true); });
  socket.on('error', () => resolve(false));
  socket.on('timeout', () => { socket.destroy(); resolve(false); });
});

/** What this computer offers. Counts and versions only: no file names, no key contents, no certificate fields. */
export async function inspectEnvironment() {
  const dir = EIMZO_DIRS.find((d) => existsSync(`${d}\\E-IMZO.exe`));
  let version = null;
  if (dir) {
    try {
      const { stdout } = await run('powershell.exe', ['-NoProfile', '-Command', `(Get-Item '${dir}\\E-IMZO.exe').VersionInfo.ProductVersion`], { windowsHide: true });
      version = stdout.trim() || null;
    } catch { /* version is informational */ }
  }

  let keys = null;
  for (const d of KEY_DIRS) {
    if (existsSync(d)) keys = (keys ?? 0) + (await readdir(d)).filter((f) => /\.(pfx|p12)$/i.test(f)).length;
  }

  return {
    eimzo_installed: Boolean(dir),
    eimzo_reachable: await portOpen(64443),
    eimzo_version: version,
    browser: findBrowser(),
    keys_on_disk: keys,
    os: process.platform === 'win32' ? 'windows' : process.platform,
  };
}

export function problems(env) {
  const list = [];
  if (env.os !== 'windows') list.push('This agent is for Windows (it uses the Windows E-IMZO app and DPAPI).');
  if (!env.eimzo_installed) list.push('E-IMZO is not installed. Install it from https://e-imzo.uz.');
  else if (!env.eimzo_reachable) list.push('E-IMZO is installed but not running (nothing is listening on 127.0.0.1:64443). Start the E-IMZO app.');
  if (!env.browser) list.push('Neither Microsoft Edge nor Google Chrome was found.');
  if (env.keys_on_disk === 0) list.push('E-IMZO is installed but no .pfx key was found in C:\\DSKEYS.');
  return list;
}
