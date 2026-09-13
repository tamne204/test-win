#!/usr/bin/env node
/**
 * tests/sidecar_module_smoke.js
 * 
 * Tests safe, non-destructive RPC commands against compiled 2toolne-core.exe
 * to prove that core, adapters, and data dependencies are fully loaded.
 */
'use strict';

const fs = require('fs');
const path = require('path');
const { spawn } = require('child_process');
const readline = require('readline');
const { randomUUID } = require('crypto');

const corePath = process.argv[2] || process.env.CORE_EXE_PATH;
if (!corePath || !fs.existsSync(corePath)) {
  console.error(`[ModuleSmoke][FAIL] Core binary not found: ${corePath}`);
  process.exit(1);
}

console.log('======================================================================');
console.log('2TOOLNE WINDOWS CI LAB — SIDECAR FUNCTIONAL MODULE SMOKE TEST');
console.log('======================================================================');
console.log(`Target Executable: ${corePath}`);

const child = spawn(corePath, [], {
  cwd: path.dirname(corePath),
  stdio: ['pipe', 'pipe', 'pipe'],
  windowsHide: true,
  env: {
    ...process.env,
    PYTHONUNBUFFERED: '1',
  },
});

let childExited = false;
let stderrLogs = '';

child.stderr.on('data', (d) => {
  stderrLogs += d.toString();
});

child.on('exit', (code, signal) => {
  childExited = true;
});

const rl = readline.createInterface({
  input: child.stdout,
  terminal: false,
});

const pending = new Map();

rl.on('line', (line) => {
  const trimmed = line.trim();
  if (!trimmed) return;
  try {
    const res = JSON.parse(trimmed);
    const reqId = res.id;
    if (reqId && pending.has(reqId)) {
      const { resolve } = pending.get(reqId);
      pending.delete(reqId);
      resolve(res);
    }
  } catch (e) {
    console.warn(`[ModuleSmoke] Non-JSON stdout: ${line}`);
  }
});

function sendRpc(method, params = {}, timeoutMs = 15000) {
  return new Promise((resolve, reject) => {
    const id = randomUUID();
    const timer = setTimeout(() => {
      pending.delete(id);
      reject(new Error(`Timeout waiting for RPC response for ${method}`));
    }, timeoutMs);

    pending.set(id, {
      resolve: (res) => {
        clearTimeout(timer);
        resolve(res);
      },
    });

    const payload = JSON.stringify({ id, protocol: 1, method, params }) + '\n';
    child.stdin.write(payload, (err) => {
      if (err) {
        clearTimeout(timer);
        pending.delete(id);
        reject(err);
      }
    });
  });
}

async function runSmoke() {
  try {
    // 1. PING
    console.log('[1/4] Testing RPC: PING ...');
    const pingRes = await sendRpc('PING');
    const isPong = pingRes.ok && (pingRes.result?.pong === true || pingRes.result === 'PONG');
    if (!isPong && !pingRes.pong) {
      throw new Error(`PING failed: ${JSON.stringify(pingRes)}`);
    }
    console.log(`  ✓ PONG received: version=${pingRes.result?.version || 'N/A'}, protocol=${pingRes.result?.protocol}`);

    // 2. GET_APP_INFO
    console.log('[2/4] Testing RPC: GET_APP_INFO ...');
    const infoRes = await sendRpc('GET_APP_INFO');
    if (!infoRes.ok && !infoRes.result) {
      throw new Error(`GET_APP_INFO failed: ${JSON.stringify(infoRes)}`);
    }
    const info = infoRes.result;
    console.log(`  ✓ App Info: product_id=${info.product_id}, version=${info.version}, python=${info.python_version}`);

    // 3. DETECT_CAPCUT
    console.log('[3/4] Testing RPC: DETECT_CAPCUT ...');
    const detectRes = await sendRpc('DETECT_CAPCUT');
    if (!detectRes.ok && !detectRes.result) {
      throw new Error(`DETECT_CAPCUT failed: ${JSON.stringify(detectRes)}`);
    }
    const detect = detectRes.result;
    console.log(`  ✓ CapCut Detection: status=${detect.status}, installed=${detect.capcut_installed}`);

    // 4. GET_PRESETS
    console.log('[4/4] Testing RPC: GET_PRESETS ...');
    const presetsRes = await sendRpc('GET_PRESETS');
    if (!presetsRes.ok && !presetsRes.result) {
      throw new Error(`GET_PRESETS failed: ${JSON.stringify(presetsRes)}`);
    }
    const presets = presetsRes.result;
    const presetCount = Array.isArray(presets) ? presets.length : Object.keys(presets).length;
    console.log(`  ✓ Presets loaded: count=${presetCount}`);

    console.log('\n======================================================================');
    console.log('✓ CORE_MODULE_SMOKE=PASS (All core modules and non-destructive RPCs functional)');
    console.log('======================================================================');

    child.stdin.end();
    child.kill('SIGTERM');
    process.exit(0);
  } catch (err) {
    console.error(`\n[ModuleSmoke][FAIL] Smoke test encountered failure: ${err.message}`);
    if (stderrLogs) {
      console.error(`Stderr output:\n${stderrLogs}`);
    }
    child.stdin.end();
    child.kill('SIGKILL');
    process.exit(1);
  }
}

runSmoke();
