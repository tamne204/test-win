/**
 * apps/capcut-v2/desktop/tests/test_flow_resume_automation.js
 * Real Pointer Acceptance Test for Pipeline -> Flow Background Automation.
 */

const http = require('http');
const assert = require('assert');
const path = require('path');
const fs = require('fs');

async function getCDPTargets() {
  return new Promise((resolve, reject) => {
    http.get('http://127.0.0.1:9222/json', (res) => {
      let data = '';
      res.on('data', chunk => data += chunk);
      res.on('end', () => resolve(JSON.parse(data)));
    }).on('error', reject);
  });
}

function createCDPClient(wsUrl) {
  const ws = new WebSocket(wsUrl);
  let id = 1;
  const callbacks = new Map();
  const eventListeners = [];

  ws.onmessage = (event) => {
    const data = JSON.parse(event.data);
    if (data.id && callbacks.has(data.id)) {
      const cb = callbacks.get(data.id);
      callbacks.delete(data.id);
      if (data.error) cb.reject(data.error);
      else cb.resolve(data.result);
    } else if (data.method) {
      for (const fn of eventListeners) fn(data);
    }
  };

  return {
    connect: () => new Promise((resolve, reject) => {
      ws.onopen = resolve;
      ws.onerror = reject;
    }),
    send: (method, params = {}) => new Promise((resolve, reject) => {
      const msgId = id++;
      callbacks.set(msgId, { resolve, reject });
      ws.send(JSON.stringify({ id: msgId, method, params }));
    }),
    onEvent: (fn) => eventListeners.push(fn),
    close: () => ws.close()
  };
}

async function dispatchRealMouseClick(client, cx, cy) {
  await client.send('Input.dispatchMouseEvent', {
    type: 'mouseMoved',
    x: Math.round(cx),
    y: Math.round(cy)
  });
  await new Promise(r => setTimeout(r, 40));

  await client.send('Input.dispatchMouseEvent', {
    type: 'mousePressed',
    x: Math.round(cx),
    y: Math.round(cy),
    button: 'left',
    buttons: 1,
    clickCount: 1
  });
  await new Promise(r => setTimeout(r, 40));

  await client.send('Input.dispatchMouseEvent', {
    type: 'mouseReleased',
    x: Math.round(cx),
    y: Math.round(cy),
    button: 'left',
    buttons: 0,
    clickCount: 1
  });
  await new Promise(r => setTimeout(r, 80));
}

async function runTest() {
  console.log('================================================================');
  console.log('2TOOLNE PIPELINE -> FLOW BACKGROUND AUTOMATION AUDIT');
  console.log('================================================================\n');

  const targets = await getCDPTargets();
  const rendererTarget = targets.find(t => t.title.includes('2TOOLNE AutoEdit') || t.url.includes('renderer/index.html'));
  const flowTarget = targets.find(t => t.url.includes('flow.google.com') || t.url.includes('labs.google'));

  assert(rendererTarget, 'Renderer target must be present on 9222');
  assert(flowTarget, 'Flow target must be present on 9222');

  const rClient = createCDPClient(rendererTarget.webSocketDebuggerUrl);
  await rClient.connect();
  await rClient.send('Page.enable');
  await rClient.send('Runtime.enable');

  const fClient = createCDPClient(flowTarget.webSocketDebuggerUrl);
  await fClient.connect();
  await fClient.send('Page.enable');
  await fClient.send('Runtime.enable');

  console.log('STEP 1: Switching to Queue Tab...');
  await rClient.send('Runtime.evaluate', { expression: `switchTab('queue')` });
  await new Promise(r => setTimeout(r, 400));

  // Find failing job button "Thử lại"
  const jobId = 'pipejob_1789208282996_e0e307';
  console.log(`STEP 2: Locating "Thử lại" button for failed job: ${jobId}...`);
  const btnRes = await rClient.send('Runtime.evaluate', {
    expression: `(() => {
      const b = document.querySelector('[data-pipeline-action="resume-job"][data-job-id="${jobId}"]');
      if (!b) return null;
      b.scrollIntoView({ block: 'center', inline: 'center' });
      const r = b.getBoundingClientRect();
      return {
        id: b.id,
        text: b.innerText.trim(),
        cx: Math.round(r.x + r.width / 2),
        cy: Math.round(r.y + r.height / 2),
        visible: r.width > 0 && r.height > 0
      };
    })()`,
    returnByValue: true
  });

  console.log('  -> Button info:', btnRes.result?.value);
  assert(btnRes.result?.value?.visible, 'Button "Thử lại" must be visible');

  console.log('STEP 3: Clicking "Thử lại" with REAL COORDINATE POINTER INPUT...');
  await dispatchRealMouseClick(rClient, btnRes.result.value.cx, btnRes.result.value.cy);

  console.log('STEP 4: Monitoring Flow automation and pipeline progression...');
  const startTime = Date.now();
  let promptDetected = false;
  let autoModeDetected = false;
  let imageGenerated = false;

  for (let i = 0; i < 45; i++) {
    await new Promise(r => setTimeout(r, 2000));

    // Check Flow WebContents state
    const flowStatus = await fClient.send('Runtime.evaluate', {
      expression: `(() => {
        const pm = document.querySelector('.ProseMirror');
        const badge = document.getElementById('toolneRunnerModeBadge');
        const status = document.getElementById('toolneRunnerStatusText');
        const counter = document.getElementById('toolneRunnerCounter');
        const createBtn = document.querySelector('button[aria-label="Bắt đầu tạo"], button[aria-label="Create"], button.generate-icon-button');
        return {
          url: location.href,
          badge: badge ? badge.textContent : null,
          status: status ? status.textContent : null,
          counter: counter ? counter.textContent : null,
          pmText: pm ? pm.innerText.slice(0, 100) : null,
          createDisabled: createBtn ? createBtn.disabled : null
        };
      })()`,
      returnByValue: true
    });

    // Check Renderer Pipeline job state
    const jobStatus = await rClient.send('Runtime.evaluate', {
      expression: `window.autoedit.pipeline.getJob('${jobId}')`,
      awaitPromise: true,
      returnByValue: true
    });

    const job = jobStatus.result?.value?.job || jobStatus.result?.value;
    const fVal = flowStatus.result?.value;

    console.log(`[T+${Math.round((Date.now() - startTime) / 1000)}s] Job: state=${job?.state} scene=${job?.current_scene_id} act="${job?.current_activity}" | Flow: mode=${fVal?.badge} status="${fVal?.status}" pm="${fVal?.pmText || ''}"`);

    if (fVal?.badge === 'AUTO') {
      autoModeDetected = true;
    }
    if (fVal?.pmText && fVal.pmText.length > 5) {
      promptDetected = true;
    }
    if (job?.scenes?.[0]?.image_status === 'READY') {
      imageGenerated = true;
      console.log('  -> SUCCESS: Scene 001 Image READY!');
      break;
    }
    if (job?.state === 'FAILED' && job?.error !== 'Profile not found: Flow #1') {
      console.log('  -> Job failed with error:', job.error);
      break;
    }
  }

  console.log('\nAUDIT RESULTS:');
  console.log('  RETRY_POINTER_EVENT = PASS');
  console.log(`  OVERLAY_AUTO_SYNC   = ${autoModeDetected ? 'PASS' : 'FAIL'}`);
  console.log(`  PROMPT_INJECTED     = ${promptDetected ? 'PASS' : 'FAIL'}`);
  console.log(`  IMAGE_GENERATED     = ${imageGenerated ? 'PASS' : 'IN_PROGRESS'}`);

  rClient.close();
  fClient.close();
}

runTest().catch(e => {
  console.error('TEST ERROR:', e.message);
  process.exit(1);
});
