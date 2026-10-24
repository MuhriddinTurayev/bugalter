import { execFile } from 'node:child_process';
import { promisify } from 'node:util';

const run = promisify(execFile);

// The agent's pairing token is protected with Windows DPAPI (current user): the config file is useless on another
// computer or account. This is NOT the E-IMZO key or its password; those never touch the agent.
const PS = (script, env) => run('powershell.exe', ['-NoProfile', '-NonInteractive', '-Command', script], { env: { ...process.env, ...env }, windowsHide: true });

export async function protect(plain) {
  const { stdout } = await PS(
    'Add-Type -AssemblyName System.Security; $b=[Text.Encoding]::UTF8.GetBytes($env:BGA_SECRET); ' +
      '[Convert]::ToBase64String([Security.Cryptography.ProtectedData]::Protect($b,$null,"CurrentUser"))',
    { BGA_SECRET: plain },
  );
  return stdout.trim();
}

export async function unprotect(cipher) {
  const { stdout } = await PS(
    'Add-Type -AssemblyName System.Security; $b=[Convert]::FromBase64String($env:BGA_CIPHER); ' +
      '[Text.Encoding]::UTF8.GetString([Security.Cryptography.ProtectedData]::Unprotect($b,$null,"CurrentUser"))',
    { BGA_CIPHER: cipher },
  );
  return stdout.replace(/\r?\n$/, '');
}
