import { existsSync } from 'node:fs';
import { chromium } from 'playwright-core';
import { PORTAL_ORIGIN, PROFILE_DIR } from './config.js';

const CHANNELS = [
  { channel: 'msedge', paths: ['C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe', 'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe'] },
  { channel: 'chrome', paths: ['C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe', 'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe'] },
];

/** The installed browser the agent drives. Edge ships with Windows; no browser download is needed. */
export function findBrowser() {
  return CHANNELS.find((c) => c.paths.some(existsSync))?.channel ?? null;
}

/**
 * A real, visible browser with its own profile. Visible on purpose: the accountant sees every step and types the
 * E-IMZO password in E-IMZO's own window. The only permission granted is the one the official site needs to
 * reach the E-IMZO app on this computer (wss://127.0.0.1:64443), and only for my.soliq.uz.
 */
export async function openBrowser() {
  const channel = findBrowser();
  if (!channel) throw new Error('Neither Microsoft Edge nor Google Chrome is installed.');

  const context = await chromium.launchPersistentContext(PROFILE_DIR, {
    channel,
    headless: false,
    locale: 'uz-UZ',
    viewport: null,
    args: ['--start-maximized'],
  });
  await context.grantPermissions(['local-network-access'], { origin: PORTAL_ORIGIN });

  // Nothing else is ever opened: a task cannot send this browser to another site.
  await context.route('**/*', (route) => {
    const url = new URL(route.request().url());
    const allowed = url.protocol === 'data:' || url.protocol === 'blob:' || /(^|\.)soliq\.uz$/.test(url.hostname) || url.hostname === 'cdnjs.cloudflare.com' || url.hostname === 'cnt0.www.uz';
    return allowed ? route.continue() : route.abort('blockedbyclient');
  });

  return { context, page: context.pages()[0] ?? (await context.newPage()), channel };
}
