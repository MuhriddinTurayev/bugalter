import { createInterface } from 'node:readline/promises';
import { Api } from './api.js';
import { CONFIG_FILE, VERSION, loadConfig, saveConfig } from './config.js';
import { inspectEnvironment, problems } from './doctor.js';
import { discoverCabinet } from './discover.js';
import { discoverReporting } from './reporting.js';
import { inspectCabinet } from './inspect.js';
import { probeForm } from './formprobe.js';
import { readOnly } from './formread.js';
import { probePayments } from './paymentprobe.js';
import { probePayments2 } from './paymentprobe2.js';
import { probePayments3 } from './paymentprobe3.js';
import { probeEntrepreneur } from './entprobe.js';
import { probeOrders } from './orderprobe.js';
import { runTask } from './tasks.js';

const log = (line) => console.log(`${new Date().toLocaleTimeString()}  ${line}`);

const commands = {
  async setup() {
    const rl = createInterface({ input: process.stdin, output: process.stdout });
    const server = await rl.question('Server address of your company (e.g. https://acme.bugalter.uz): ');
    const token = (await rl.question('Agent key (shown once on the Tax -> Agent page, starts with bga_): ')).trim();
    rl.close();
    if (!/^bga_[A-Za-z0-9]{40,}$/.test(token)) throw new Error('That does not look like an agent key.');
    await saveConfig({ server, token });
    log(`Saved to ${CONFIG_FILE} (the key is encrypted with Windows DPAPI for this user).`);
    await commands.doctor();
  },

  async doctor() {
    const env = await inspectEnvironment();
    log(`agent ${VERSION} · E-IMZO ${env.eimzo_installed ? (env.eimzo_reachable ? `running ${env.eimzo_version ?? ''}` : 'installed, NOT running') : 'NOT installed'} · browser ${env.browser ?? 'none'} · keys on disk ${env.keys_on_disk ?? '?'}`);
    const list = problems(env);
    list.forEach((p) => log(`PROBLEM: ${p}`));

    const config = await loadConfig();
    if (!config) log('Not paired yet: run "npm run setup".');
    else {
      try {
        await new Api(config).heartbeat(env);
        log(`Server ${config.server}: connected.`);
      } catch (error) {
        log(`Server ${config.server}: ${error.message}`);
        list.push(error.message);
      }
    }
    if (list.length) process.exitCode = 1;
  },

  async run() {
    const config = await loadConfig();
    if (!config) throw new Error('Not paired yet: run "npm run setup".');
    const api = new Api(config);
    log(`Agent ${VERSION} for ${config.server}. Press Ctrl+C to stop.`);

    let delay = 10_000;
    for (;;) {
      try {
        const beat = await api.heartbeat(await inspectEnvironment());
        delay = (beat.poll_seconds ?? 10) * 1000;
        const task = await api.claim();
        if (task) {
          log(`Task ${task.id}: ${task.type}`);
          await runTask(api, task, log);
          if (process.argv.includes('--once')) return;
          continue; // look for the next one at once
        }
      } catch (error) {
        log(`Waiting: ${error.message}`);
        delay = Math.min(delay * 2, 120_000);
      }
      await new Promise((resolve) => setTimeout(resolve, delay));
    }
  },

  inspect: () => inspectCabinet(log),
  form: () => readOnly({ year: Number(process.argv[3]), month: Number(process.argv[4]) }, log),
  payments: () => probePayments({ loginType: process.argv[3] ?? 'individual' }, log),
  payments2: () => probePayments2({ loginType: process.argv[3] ?? 'individual' }, log),
  payments3: () => probePayments3(log),
  entrepreneur: () => probeEntrepreneur(log),
  orders: () => probeOrders(log),
  probe: () => probeForm({ loginType: process.argv[3] ?? 'individual', period: process.argv[4] ?? null }, log),
  reporting: () => discoverReporting({ loginType: process.argv[3] ?? 'individual' }, log),
  discover: () => {
    // npm run discover -- <login type> [/path ...]   (with paths: only those pages, no crawling)
    const [type = 'individual', ...paths] = process.argv.slice(3);
    return discoverCabinet({ loginType: type, seeds: paths, follow: paths.length === 0, maxPages: paths.length || 40 }, log);
  },
};

const name = process.argv[2];
if (!commands[name]) {
  console.log('Usage: node src/cli.js <setup|doctor|run|inspect|discover>');
  process.exit(2);
}

commands[name]().catch((error) => {
  console.error(`ERROR: ${error.message}`);
  process.exit(1);
});
