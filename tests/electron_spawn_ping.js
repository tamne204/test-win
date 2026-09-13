#!/usr/bin/env node
/**
 * tests/electron_spawn_ping.js
 * 
 * Tests sidecar spawn using the EXACT child_process options, environment,
 * and payload contract used by production Electron SidecarManager.
 */
'use strict';

const fs = require('fs');
const path = require('path');
const { spawn } = require('child_process');
const readline = require('readline');
const { randomUUID } = require('crypto');

const corePath = process.argv[2] || process.env.CORE_EXE_PATH;
if (!corePath || !fs.existsSync(corePath)) {
  console.error(`[ElectronSpawnPing][FAIL] Core executable not found: ${corePath}`);
  process.exit(1);
}

console.log('======================================================================');
console.log('2TOOLNE WINDOWS CI LAB — ELECTRON-STYLE SPAWN PING AUDIT');
console.log('======================================================================');
console.log(`Target Executable: ${corePath}`);

const cwd = path.dirname(corePath);

// Exact spawn options from apps/capcut-v2/desktop/src/main/sidecar.js
const spawnOptions = {
  cwd,
  stdio: ['pipe', 'pipe', 'pipe'],
  env: {
    ...process.env,
    PYTHONUNBUFFERED: '1',
  },
  windowsHide: true,
};

const startTime = Date.now();
const child = spawn(corePath, [], spawnOptions);

let receivedPong = false;
let stderrData = '';

child.stderr.on('data', (d) => {
  stderrData += d.toString();
});

const rl = readline.createInterface({
  input: child.stdout,
  terminal: false,
});

const reqId = randomUUID();
const pingPayload = JSON.stringify({
  id: reqId,
  protocol: 1,
  method: 'PING',
  params: {},
}) + '\n';

const timeoutMs = parseInt(process.env.SPAWN_TIMEOUT_MS || '30000', 10);
const timer = setTimeout(() => {
  if (!receivedPong) {
    console.error(`[ElectronSpawnPing][FAIL] Timeout (${timeoutMs}ms) waiting for PING response.`);
    if (stderrData) console.error(`Stderr:\n${stderrData}`);
    try { child.stdin.end(); child.kill('SIGKILL'); } catch (_) {}
    process.exit(1);
  }
}, timeoutMs);

rl.on('line', (line) => {
  const trimmed = line.trim();
  if (!trimmed) return;
  try {
    const res = JSON.parse(trimmed);
    if (res.id === reqId || res.pong || res.result?.pong) {
      const elapsed = Date.now() - startTime;
      receivedPong = true;
      clearTimeout(timer);
      console.log(`✓ Received valid JSON-RPC PONG in ${elapsed}ms: ${trimmed}`);
      console.log('======================================================================');
      console.log('✓ ELECTRON_STYLE_SPAWN_PING=PASS');
      console.log('======================================================================');
      try { child.stdin.end(); child.kill('SIGTERM'); } catch (_) {}
      process.exit(0);
    }
  } catch (e) {
    console.warn(`[ElectronSpawnPing] Non-JSON line ignored: ${line}`);
  }
});

child.on('exit', (code, signal) => {
  if (!receivedPong) {
    clearTimeout(timer);
    console.error(`[ElectronSpawnPing][FAIL] Process exited prematurely: code=${code}, signal=${signal}`);
    if (stderrData) console.error(`Stderr:\n${stderrData}`);
    process.exit(1);
  }
});

// Write PING payload to stdin
child.stdin.write(pingPayload);
