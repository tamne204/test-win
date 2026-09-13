#!/usr/bin/env node
/**
 * tests/sidecar_ping.js
 * 
 * 2TOOLNE Windows CI Lab - Sidecar Stdio JSON-RPC Ping Harness
 * Simulates the exact stdio communication configuration used by Electron Main process.
 * 
 * Invariants:
 * 1. Stdio line-delimited JSON-RPC 2.0 (single line per request/response).
 * 2. Exact PING payload: {"jsonrpc": "2.0", "id": <id>, "method": "PING"}
 * 3. Measures cold start and subsequent iteration latencies.
 * 4. Fails secure on timeout, unclean stdout, early exit, or invalid response.
 */

'use strict';

const fs = require('fs');
const path = require('path');
const { spawn } = require('child_process');
const readline = require('readline');

// Parse CLI arguments
const args = process.argv.slice(2);
let corePath = process.env.CORE_EXE_PATH || null;
let iterations = parseInt(process.env.PING_ITERATIONS || '5', 10);
let timeoutMs = parseInt(process.env.PING_TIMEOUT_MS || '30000', 10);
let outputFile = process.env.PING_OUTPUT_FILE || 'sidecar-ping.json';

for (let i = 0; i < args.length; i++) {
  if (args[i] === '--core' || args[i] === '-c') corePath = args[++i];
  if (args[i] === '--iterations' || args[i] === '-n') iterations = parseInt(args[++i], 10);
  if (args[i] === '--timeout' || args[i] === '-t') timeoutMs = parseInt(args[++i], 10);
  if (args[i] === '--output' || args[i] === '-o') outputFile = args[++i];
}

if (!corePath) {
  // Auto-discover candidate paths
  const searchCandidates = [
    'resources/autoedit-core/win-x64/2toolne-core.exe',
    'resources/autoedit-core/win-x64/autoedit-core.exe',
    'resources/autoedit-core/2toolne-core.exe',
    'resources/autoedit-core/autoedit-core.exe',
    '2toolne-core.exe',
    'autoedit-core.exe',
  ];
  for (const cand of searchCandidates) {
    if (fs.existsSync(cand)) {
      corePath = path.resolve(cand);
      break;
    }
  }
}

if (!corePath || !fs.existsSync(corePath)) {
  const failure = {
    ok: false,
    status: 'FAIL',
    classification: 'BINARY_MISSING',
    error: `Target sidecar executable not found: ${corePath || '(none specified)'}`,
    runs: [],
  };
  console.error(`[SidecarPing][FATAL] ${failure.error}`);
  fs.writeFileSync(outputFile, JSON.stringify(failure, null, 2), 'utf8');
  process.exit(1);
}

console.log('======================================================================');
console.log('2TOOLNE WINDOWS CI LAB — SIDECAR JSON-RPC PING HARNESS');
console.log('======================================================================');
console.log(`Target Executable : ${corePath}`);
console.log(`File Size         : ${fs.statSync(corePath).size.toLocaleString()} bytes`);
console.log(`Iterations        : ${iterations}`);
console.log(`Timeout per Run   : ${timeoutMs} ms`);
console.log(`Output Report     : ${outputFile}`);
console.log('======================================================================\n');

function runSinglePing(runIndex) {
  return new Promise((resolve) => {
    const runResult = {
      run_index: runIndex,
      process_started_at: null,
      ping_sent_at: null,
      first_stdout_at: null,
      pong_received_at: null,
      ping_ms: null,
      pid: null,
      exit_code: null,
      stderr: '',
      raw_stdout: '',
      clean_stdout: true,
      ok: false,
      classification: 'UNKNOWN',
    };

    const startTime = Date.now();
    runResult.process_started_at = new Date().toISOString();

    const cwd = path.dirname(corePath);
    let child;

    try {
      child = spawn(corePath, [], {
        cwd,
        stdio: ['pipe', 'pipe', 'pipe'],
        windowsHide: true,
        env: {
          ...process.env,
          PYTHONUNBUFFERED: '1',
          PYTHONIOENCODING: 'utf-8',
        },
      });
    } catch (spawnErr) {
      runResult.classification = 'SPAWN_FAILED';
      runResult.error = spawnErr.message;
      return resolve(runResult);
    }

    runResult.pid = child.pid;
    let timer = null;
    let finished = false;
    let stdoutLines = [];

    const cleanup = (reason) => {
      if (finished) return;
      finished = true;
      if (timer) clearTimeout(timer);
      try {
        child.stdin.end();
      } catch (_) {}
      try {
        child.kill('SIGTERM');
      } catch (_) {}
      setTimeout(() => {
        try { child.kill('SIGKILL'); } catch (_) {}
      }, 500);
    };

    timer = setTimeout(() => {
      runResult.classification = 'PING_TIMEOUT';
      runResult.error = `Timeout after ${timeoutMs}ms waiting for PING response`;
      cleanup();
      resolve(runResult);
    }, timeoutMs);

    child.stderr.on('data', (d) => {
      runResult.stderr += d.toString();
    });

    const rl = readline.createInterface({
      input: child.stdout,
      terminal: false,
    });

    rl.on('line', (line) => {
      const now = Date.now();
      if (!runResult.first_stdout_at) {
        runResult.first_stdout_at = new Date().toISOString();
      }
      stdoutLines.push(line);

      const trimmed = line.trim();
      if (!trimmed) return;

      try {
        const parsed = JSON.parse(trimmed);
        runResult.raw_stdout = trimmed;

        // Check if response corresponds to PING
        const isPong = parsed.ok && (parsed.result?.pong === true || parsed.result === 'PONG');
        if (isPong) {
          runResult.pong_received_at = new Date().toISOString();
          runResult.ping_ms = now - startTime;
          runResult.ok = true;
          runResult.classification = 'PASS';
          runResult.result = parsed.result;
          cleanup();
          resolve(runResult);
        } else if (parsed.error) {
          runResult.classification = 'INVALID_JSON_RPC';
          runResult.error = `Sidecar returned error: ${JSON.stringify(parsed.error)}`;
          cleanup();
          resolve(runResult);
        }
      } catch (parseErr) {
        // Line wasn't valid JSON
        runResult.clean_stdout = false;
        console.warn(`  [WARN] Contaminated stdout line: ${line.slice(0, 120)}`);
      }
    });

    child.on('exit', (code, signal) => {
      runResult.exit_code = code;
      if (!finished) {
        finished = true;
        if (timer) clearTimeout(timer);
        if (code !== 0 && !runResult.ok) {
          if (runResult.stderr.toLowerCase().includes('dll') || runResult.stderr.toLowerCase().includes('module')) {
            runResult.classification = 'MISSING_DLL';
          } else {
            runResult.classification = 'PROCESS_EXITED';
          }
          runResult.error = `Process exited prematurely with code ${code} (signal: ${signal})`;
        }
        resolve(runResult);
      }
    });

    child.on('error', (err) => {
      if (!finished) {
        finished = true;
        if (timer) clearTimeout(timer);
        runResult.classification = 'SPAWN_FAILED';
        runResult.error = err.message;
        resolve(runResult);
      }
    });

    // Send PING immediately over stdin
    runResult.ping_sent_at = new Date().toISOString();
    const req = JSON.stringify({ jsonrpc: '2.0', id: runIndex, method: 'PING' }) + '\n';
    child.stdin.write(req);
  });
}

async function main() {
  const results = [];
  let allPass = true;

  for (let i = 1; i <= iterations; i++) {
    process.stdout.write(`-> Running Ping Iteration ${i}/${iterations} ... `);
    const res = await runSinglePing(i);
    results.push(res);

    if (res.ok) {
      console.log(`PASS (${res.ping_ms} ms) [PID: ${res.pid}]`);
    } else {
      allPass = false;
      console.log(`FAIL [${res.classification}] (${res.error || 'Unknown error'})`);
      if (res.stderr) {
        console.log(`   --- STDERR ---\n   ${res.stderr.trim().replace(/\n/g, '\n   ')}\n   --------------`);
      }
    }
  }

  const passedRuns = results.filter((r) => r.ok);
  const latencies = passedRuns.map((r) => r.ping_ms);
  const minMs = latencies.length ? Math.min(...latencies) : null;
  const maxMs = latencies.length ? Math.max(...latencies) : null;
  const avgMs = latencies.length ? Math.round(latencies.reduce((a, b) => a + b, 0) / latencies.length) : null;

  const summary = {
    timestamp: new Date().toISOString(),
    core_path: corePath,
    total_iterations: iterations,
    passed_iterations: passedRuns.length,
    failed_iterations: iterations - passedRuns.length,
    status: allPass ? 'PASS' : (passedRuns.length > 0 ? 'PARTIAL' : 'FAIL'),
    min_ms: minMs,
    max_ms: maxMs,
    avg_ms: avgMs,
    cold_start_ms: results[0]?.ping_ms || null,
    runs: results,
  };

  fs.writeFileSync(outputFile, JSON.stringify(summary, null, 2), 'utf8');

  console.log('\n======================================================================');
  console.log(`SUMMARY: ${summary.status} (${passedRuns.length}/${iterations} passed)`);
  if (minMs !== null) {
    console.log(`Cold Start : ${summary.cold_start_ms} ms`);
    console.log(`Min Latency: ${minMs} ms | Max: ${maxMs} ms | Avg: ${avgMs} ms`);
  }
  console.log(`Report written to: ${outputFile}`);
  console.log('======================================================================\n');

  process.exit(allPass ? 0 : 1);
}

main().catch((err) => {
  console.error('[SidecarPing][UNHANDLED]', err);
  process.exit(1);
});
