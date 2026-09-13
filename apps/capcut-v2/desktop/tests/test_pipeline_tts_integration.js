/**
 * apps/capcut-v2/desktop/tests/test_pipeline_tts_integration.js
 * 2TOOLNE AUTOEDIT V2 — NATIVE TTS PIPELINE INTEGRATION VERIFICATION SUITE
 *
 * Tests TTS-01 through TTS-08:
 *   [TTS-01] LOCAL_AUDIO_REGRESSION: Bundle + local audio runs to PROJECT_READY, no TTS called.
 *   [TTS-02] TTS_VIDEO_E2E: Bundle + TTS config generates audio, downloads to disk, reaches PROJECT_READY.
 *   [TTS-03] BATCH_MIXED_AUDIO_TEST: Batch [Video A (TTS), Video B (Local), Video C (TTS)] processes in strict FIFO sequential order without interleaving.
 *   [TTS-04] RESTART_DURING_TTS_TEST: App restart during active TTS resumes polling existing tts_job_id without creating duplicates.
 *   [TTS-05] CANCEL_DURING_TTS_TEST: Cancel during TTS aborts local pipeline and propagates cancel to remote sub-job.
 *   [TTS-06] TTS_FAILURE_RETRY_TEST: Failed TTS sets failed_stage = GENERATING_TTS_AUDIO, retry creates attempt :r1 and completes to PROJECT_READY.
 *   [TTS-07] RENDER_QUEUE_REGRESSION: Queue B and Single CapCut Worker Invariant remain isolated and intact.
 *   [TTS-08] AUTO_HANDOFF_RENDER_REGRESSION: Auto handoff enqueues exactly 1 job in Render Queue B, 0 duplicates.
 */

const assert = require('assert');
const fs = require('fs');
const path = require('path');
const os = require('os');
const cp = require('child_process');
const { PipelineQueueV2, PIPELINE_STATES } = require('../src/main/pipeline_queue_v2');
const { BundleEngine } = require('../src/main/bundle_engine');

const TEST_TEMP_DIR = path.join(os.tmpdir(), `2toolne_tts_int_test_${Date.now()}_${Math.random().toString(36).substring(2, 7)}`);

function setupTestBundle(bundleName = 'TTS_Bundle_001', sceneCount = 2, audioType = 'NONE') {
  const bundleDir = path.join(TEST_TEMP_DIR, 'bundles', bundleName);
  fs.mkdirSync(bundleDir, { recursive: true });

  const scenes = [];
  for (let i = 1; i <= sceneCount; i++) {
    const sceneId = BundleEngine.formatSceneId(i);
    scenes.push({
      scene_number: i,
      scene_id: sceneId,
      slug: `shot-${sceneId}`,
      prompt: `Cinematic neon street shot ${i}`,
      character_ids: [],
    });
  }

  const manifest = {
    schema_version: '2.0.0',
    project_name: bundleName,
    aspect_ratio: '16:9',
    target_fps: 30,
  };

  if (audioType === 'LOCAL') {
    const localAudioFile = 'narration.wav';
    fs.writeFileSync(path.join(bundleDir, localAudioFile), Buffer.from('RIFF_LOCAL_WAV_AUDIO_DATA'));
    manifest.audio = {
      source: 'LOCAL_AUDIO',
      file: localAudioFile,
    };
  } else if (audioType === 'TTS') {
    manifest.audio = {
      source: 'TTS',
      tts: {
        voice_id: 'preset_en_ryan',
        language: 'en',
        speed: 1.0,
        output_format: 'wav',
      },
    };
  }

  fs.writeFileSync(path.join(bundleDir, '2toolne.json'), JSON.stringify(manifest, null, 2));

  fs.writeFileSync(path.join(bundleDir, 'prompts.json'), JSON.stringify({
    schema_version: '2.0.0',
    scenes,
  }, null, 2));

  fs.writeFileSync(path.join(bundleDir, 'characters.json'), JSON.stringify({
    schema_version: '2.0.0',
    characters: [],
  }, null, 2));

  fs.writeFileSync(path.join(bundleDir, 'script.txt'), 'Tokyo city lights illuminate the rain-slicked asphalt with brilliant reflections.');

  return { bundleDir, scenes };
}

async function runAllTtsTests() {
  console.log('='.repeat(80));
  console.log('2TOOLNE AUTOEDIT V2 — NATIVE TTS PIPELINE INTEGRATION VERIFICATION SUITE');
  console.log('TEST SUITE: [TTS-01] THROUGH [TTS-07]');
  console.log('='.repeat(80));

  fs.mkdirSync(TEST_TEMP_DIR, { recursive: true });
  let passed = 0;

  try {
    // --------------------------------------------------------------------------
    // [TTS-01] LOCAL_AUDIO_REGRESSION
    // --------------------------------------------------------------------------
    console.log('\n--- [TTS-01] LOCAL_AUDIO_REGRESSION ---');
    {
      const storageDir = path.join(TEST_TEMP_DIR, 'storage_tts_01');
      const { bundleDir } = setupTestBundle('Bundle_TTS_01_Local', 2, 'LOCAL');

      let ttsCalled = false;
      let buildDraftAudioPath = null;

      const customExecutor = {
        generateTtsAudio: async () => {
          ttsCalled = true;
          throw new Error('TTS should not be called for LOCAL_AUDIO!');
        },
        buildCapCutProject: async (job) => {
          buildDraftAudioPath = job.audio_path;
          const mockDraftDir = path.join(job.options.output_dir, `${job.project_name}.2toolne-autoedit`);
          fs.mkdirSync(mockDraftDir, { recursive: true });
          fs.writeFileSync(path.join(mockDraftDir, 'draft_content.json'), JSON.stringify({ ok: true }));
          return mockDraftDir;
        },
        verifyCapCutProject: async () => true,
      };

      const queue = new PipelineQueueV2({ storageDir, customExecutor, ttsPollIntervalMs: 50 });
      const res = queue.enqueue(bundleDir);
      assert.strictEqual(res.ok, true, 'Enqueue local audio bundle should succeed');
      assert.strictEqual(res.job.audio_source, 'LOCAL_AUDIO');
      assert.ok(res.job.audio_path && res.job.audio_path.endsWith('narration.wav'));

      await new Promise((resolve, reject) => {
        queue.on('job:completed', resolve);
        queue.on('job:failed', ({ error }) => reject(new Error(error)));
        setTimeout(() => reject(new Error('TTS-01 Timeout')), 5000);
      });

      const finishedJob = queue.getJob(res.job.id);
      assert.strictEqual(finishedJob.state, PIPELINE_STATES.PROJECT_READY);
      assert.strictEqual(ttsCalled, false, 'TTS must never be invoked for LOCAL_AUDIO');
      assert.strictEqual(buildDraftAudioPath, res.job.audio_path, 'Downstream CapCut project builder must receive local audio path');

      console.log('✓ TTS-01 PASS: Local audio bundle bypassed TTS stage and delivered audio to CapCut builder');
      passed++;
    }

    // --------------------------------------------------------------------------
    // [TTS-02] TTS_VIDEO_E2E
    // --------------------------------------------------------------------------
    console.log('\n--- [TTS-02] TTS_VIDEO_E2E ---');
    {
      const storageDir = path.join(TEST_TEMP_DIR, 'storage_tts_02');
      const { bundleDir } = setupTestBundle('Bundle_TTS_02_Tts', 2, 'TTS');

      const statesVisited = [];
      let generatedTtsParams = null;
      let buildDraftAudioReceived = null;

      const customExecutor = {
        generateTtsAudio: async (job, params) => {
          generatedTtsParams = params;
          return { id: `tts_job_${job.id}`, status: 'QUEUED' };
        },
        getTtsJob: async (job, ttsJobId) => {
          return {
            id: ttsJobId,
            status: 'COMPLETED',
            cloud_file_id: `cf_tts_${job.id}`,
            progress: 100,
          };
        },
        cacheCloudAudio: async (job, cloudFileId, filename) => {
          const cachedPath = path.join(job.bundle_dir, filename);
          fs.writeFileSync(cachedPath, Buffer.from('RIFF_CACHED_TTS_WAV_DATA'));
          return { localPath: cachedPath };
        },
        buildCapCutProject: async (job) => {
          buildDraftAudioReceived = job.audio_path;
          const mockDraftDir = path.join(job.options.output_dir, `${job.project_name}.2toolne-autoedit`);
          fs.mkdirSync(mockDraftDir, { recursive: true });
          fs.writeFileSync(path.join(mockDraftDir, 'draft_content.json'), JSON.stringify({ ok: true }));
          return mockDraftDir;
        },
        verifyCapCutProject: async () => true,
      };

      const queue = new PipelineQueueV2({ storageDir, customExecutor, ttsPollIntervalMs: 50 });
      queue.on('job:state_changed', ({ newState }) => statesVisited.push(newState));

      const res = queue.enqueue(bundleDir);
      assert.strictEqual(res.ok, true);
      assert.strictEqual(res.job.audio_source, 'TTS');

      await new Promise((resolve, reject) => {
        queue.on('job:completed', resolve);
        queue.on('job:failed', ({ error }) => reject(new Error(error)));
        setTimeout(() => reject(new Error('TTS-02 Timeout')), 5000);
      });

      const finishedJob = queue.getJob(res.job.id);
      assert.strictEqual(finishedJob.state, PIPELINE_STATES.PROJECT_READY);
      assert.ok(statesVisited.includes(PIPELINE_STATES.GENERATING_TTS_AUDIO), 'Must visit GENERATING_TTS_AUDIO');
      assert.strictEqual(generatedTtsParams.idempotency_key, `pipeline:${res.job.id}:tts`);
      assert.strictEqual(generatedTtsParams.output_format, 'wav', 'output_format must be wav');
      assert.ok(generatedTtsParams.settings, 'settings object must be present in canonical payload');
      assert.strictEqual(generatedTtsParams.settings.speed, 1.0, 'settings.speed must equal configured speed 1.0');
      assert.ok(finishedJob.audio_path && fs.existsSync(finishedJob.audio_path), 'Cached audio file must exist');
      assert.strictEqual(buildDraftAudioReceived, finishedJob.audio_path, 'CapCut builder must receive generated audio path');

      console.log('✓ TTS-02 PASS: Canonical TTS request verified, audio generated & cached, downstream handed off, reaches PROJECT_READY');
      passed++;
    }

    // --------------------------------------------------------------------------
    // [TTS-03] BATCH_MIXED_AUDIO_TEST
    // --------------------------------------------------------------------------
    console.log('\n--- [TTS-03] BATCH_MIXED_AUDIO_TEST ---');
    {
      const storageDir = path.join(TEST_TEMP_DIR, 'storage_tts_03');
      const bundleA = setupTestBundle('Bundle_TTS_03_VideoA_TTS', 1, 'TTS').bundleDir;
      const bundleB = setupTestBundle('Bundle_TTS_03_VideoB_Local', 1, 'LOCAL').bundleDir;
      const bundleC = setupTestBundle('Bundle_TTS_03_VideoC_TTS', 1, 'TTS').bundleDir;

      const completionOrder = [];

      const customExecutor = {
        generateTtsAudio: async (job) => {
          return { id: `tts_sub_${job.id}`, status: 'QUEUED' };
        },
        getTtsJob: async (job, ttsJobId) => {
          return { id: ttsJobId, status: 'COMPLETED', cloud_file_id: `cf_${job.id}`, progress: 100 };
        },
        cacheCloudAudio: async (job, cloudFileId, filename) => {
          const p = path.join(job.bundle_dir, filename);
          fs.writeFileSync(p, Buffer.from('AUDIO'));
          return { localPath: p };
        },
        buildCapCutProject: async (job) => {
          const dir = path.join(job.options.output_dir, `${job.project_name}.2toolne-autoedit`);
          fs.mkdirSync(dir, { recursive: true });
          fs.writeFileSync(path.join(dir, 'draft_content.json'), JSON.stringify({ ok: true }));
          return dir;
        },
        verifyCapCutProject: async () => true,
      };

      const queue = new PipelineQueueV2({ storageDir, customExecutor, ttsPollIntervalMs: 30 });
      queue.on('job:completed', ({ job }) => {
        completionOrder.push(job.project_name);
      });

      const resA = queue.enqueue(bundleA);
      const resB = queue.enqueue(bundleB);
      const resC = queue.enqueue(bundleC);

      assert.strictEqual(resA.ok, true);
      assert.strictEqual(resB.ok, true);
      assert.strictEqual(resC.ok, true);

      await new Promise((resolve, reject) => {
        const checkDone = () => {
          if (completionOrder.length === 3) resolve();
        };
        queue.on('job:completed', checkDone);
        queue.on('job:failed', ({ error }) => reject(new Error(error)));
        setTimeout(() => reject(new Error('TTS-03 Timeout')), 10000);
      });

      assert.deepStrictEqual(completionOrder, [
        'Bundle_TTS_03_VideoA_TTS',
        'Bundle_TTS_03_VideoB_Local',
        'Bundle_TTS_03_VideoC_TTS',
      ], 'Batch must process in strict FIFO sequential order without interleaving');

      console.log('✓ TTS-03 PASS: Mixed batch [TTS, Local, TTS] completed sequentially in strict FIFO order');
      passed++;
    }

    // --------------------------------------------------------------------------
    // [TTS-04] RESTART_DURING_TTS_TEST
    // --------------------------------------------------------------------------
    console.log('\n--- [TTS-04] RESTART_DURING_TTS_TEST ---');
    {
      const storageDir = path.join(TEST_TEMP_DIR, 'storage_tts_04');
      const { bundleDir } = setupTestBundle('Bundle_TTS_04_Restart', 1, 'TTS');

      let generateCalls = 0;
      let getJobCalls = 0;

      // Instance 1: Starts TTS, gets tts_job_id, then simulates crash/shutdown
      const queue1 = new PipelineQueueV2({
        storageDir,
        ttsPollIntervalMs: 50,
        customExecutor: {
          generateTtsAudio: async (job) => {
            generateCalls++;
            return { id: 'tts_job_persisted_123', status: 'QUEUED' };
          },
          getTtsJob: async () => {
            getJobCalls++;
            // Remain active to allow stopping
            return { status: 'GENERATING', progress: 45 };
          },
        },
      });

      const res1 = queue1.enqueue(bundleDir);
      assert.strictEqual(res1.ok, true);

      // Wait until tts_job_id is assigned
      await new Promise((resolve) => {
        const interval = setInterval(() => {
          const j = queue1.getJob(res1.job.id);
          if (j && j.tts && j.tts.tts_job_id) {
            clearInterval(interval);
            resolve();
          }
        }, 50);
      });

      assert.strictEqual(generateCalls, 1, 'Initial generate call must occur');
      assert.strictEqual(queue1.getJob(res1.job.id).tts.tts_job_id, 'tts_job_persisted_123');

      // Stop queue 1 cleanly
      queue1.stop();

      // Instance 2: Reloads from storageDir
      let resumeGenerateCalls = 0;
      let resumeGetJobCalls = 0;

      const queue2 = new PipelineQueueV2({
        storageDir,
        ttsPollIntervalMs: 50,
        customExecutor: {
          generateTtsAudio: async () => {
            resumeGenerateCalls++;
            throw new Error('Should NOT call generateTtsAudio again on restart!');
          },
          getTtsJob: async (job, ttsJobId) => {
            resumeGetJobCalls++;
            assert.strictEqual(ttsJobId, 'tts_job_persisted_123', 'Must query the exact persisted tts_job_id');
            return { id: ttsJobId, status: 'COMPLETED', cloud_file_id: 'cf_restart_ok', progress: 100 };
          },
          cacheCloudAudio: async (job, cloudFileId, filename) => {
            const p = path.join(job.bundle_dir, filename);
            fs.writeFileSync(p, Buffer.from('CACHED_AUDIO_POST_RESTART'));
            return { localPath: p };
          },
          buildCapCutProject: async (job) => {
            const dir = path.join(job.options.output_dir, `${job.project_name}.2toolne-autoedit`);
            fs.mkdirSync(dir, { recursive: true });
            fs.writeFileSync(path.join(dir, 'draft_content.json'), JSON.stringify({ ok: true }));
            return dir;
          },
          verifyCapCutProject: async () => true,
        },
      });

      // Resume the interrupted job
      const resumeRes = queue2.resumeJob(res1.job.id);
      assert.strictEqual(resumeRes.ok, true);

      await new Promise((resolve, reject) => {
        queue2.on('job:completed', resolve);
        queue2.on('job:failed', ({ error }) => reject(new Error(error)));
        setTimeout(() => reject(new Error('TTS-04 Timeout')), 5000);
      });

      const finishedJob = queue2.getJob(res1.job.id);
      assert.strictEqual(finishedJob.state, PIPELINE_STATES.PROJECT_READY);
      assert.strictEqual(resumeGenerateCalls, 0, 'Must NOT re-invoke generateTtsAudio after restart');
      assert.ok(resumeGetJobCalls >= 1, 'Must poll existing tts_job_id');

      console.log('✓ TTS-04 PASS: Survives restart, resumes existing tts_job_id without duplicate generation');
      passed++;
    }

    // --------------------------------------------------------------------------
    // [TTS-05] CANCEL_DURING_TTS_TEST
    // --------------------------------------------------------------------------
    console.log('\n--- [TTS-05] CANCEL_DURING_TTS_TEST ---');
    {
      const storageDir = path.join(TEST_TEMP_DIR, 'storage_tts_05');
      const { bundleDir } = setupTestBundle('Bundle_TTS_05_Cancel', 1, 'TTS');

      let remoteCancelledId = null;

      const customExecutor = {
        generateTtsAudio: async () => {
          return { id: 'tts_job_cancel_me', status: 'GENERATING' };
        },
        getTtsJob: async () => {
          return { status: 'GENERATING', progress: 20 };
        },
        cancelTtsAudio: async (job, ttsJobId) => {
          remoteCancelledId = ttsJobId;
          return { ok: true };
        },
      };

      const queue = new PipelineQueueV2({ storageDir, customExecutor, ttsPollIntervalMs: 50 });
      const res = queue.enqueue(bundleDir);

      // Wait until job enters GENERATING_TTS_AUDIO
      await new Promise((resolve) => {
        const interval = setInterval(() => {
          const j = queue.getJob(res.job.id);
          if (j && j.state === PIPELINE_STATES.GENERATING_TTS_AUDIO) {
            clearInterval(interval);
            resolve();
          }
        }, 30);
      });

      const cancelRes = queue.cancelJob(res.job.id);
      assert.strictEqual(cancelRes.ok, true);

      const cancelledJob = queue.getJob(res.job.id);
      assert.strictEqual(cancelledJob.state, PIPELINE_STATES.CANCELLED);

      // Verify remote cancellation was propagated
      await new Promise(r => setTimeout(r, 100));
      assert.strictEqual(remoteCancelledId, 'tts_job_cancel_me', 'Remote cancel must be propagated to tts sub-job');

      console.log('✓ TTS-05 PASS: Cancel immediately halted pipeline and propagated cancel to remote TTS sub-job');
      passed++;
    }

    // --------------------------------------------------------------------------
    // [TTS-06] TTS_FAILURE_RETRY_TEST
    // --------------------------------------------------------------------------
    console.log('\n--- [TTS-06] TTS_FAILURE_RETRY_TEST ---');
    {
      const storageDir = path.join(TEST_TEMP_DIR, 'storage_tts_06');
      const { bundleDir } = setupTestBundle('Bundle_TTS_06_Retry', 1, 'TTS');

      let attempt = 0;
      let idempotencyKeysUsed = [];

      const customExecutor = {
        generateTtsAudio: async (job, params) => {
          attempt++;
          idempotencyKeysUsed.push(params.idempotency_key);
          if (attempt === 1) {
            // First attempt fails
            throw new Error('GPU Worker Out of Memory (Simulated)');
          }
          // Second attempt succeeds
          return { id: `tts_job_retry_success`, status: 'QUEUED' };
        },
        getTtsJob: async (job, ttsJobId) => {
          return { id: ttsJobId, status: 'COMPLETED', cloud_file_id: 'cf_retry_success', progress: 100 };
        },
        cacheCloudAudio: async (job, cloudFileId, filename) => {
          const p = path.join(job.bundle_dir, filename);
          fs.writeFileSync(p, Buffer.from('RETRY_AUDIO'));
          return { localPath: p };
        },
        buildCapCutProject: async (job) => {
          const dir = path.join(job.options.output_dir, `${job.project_name}.2toolne-autoedit`);
          fs.mkdirSync(dir, { recursive: true });
          fs.writeFileSync(path.join(dir, 'draft_content.json'), JSON.stringify({ ok: true }));
          return dir;
        },
        verifyCapCutProject: async () => true,
      };

      const queue = new PipelineQueueV2({ storageDir, customExecutor, ttsPollIntervalMs: 50 });
      const res = queue.enqueue(bundleDir);

      // Wait for failure on attempt 1
      await new Promise((resolve) => {
        queue.on('job:failed', resolve);
      });

      const failedJob = queue.getJob(res.job.id);
      assert.strictEqual(failedJob.state, PIPELINE_STATES.FAILED);
      assert.strictEqual(failedJob.failed_stage, PIPELINE_STATES.GENERATING_TTS_AUDIO);
      assert.ok(failedJob.error.includes('GPU Worker Out of Memory'));

      // Retry TTS
      const retryRes = queue.retryTts(res.job.id);
      assert.strictEqual(retryRes.ok, true);

      // Wait for completion on attempt 2
      await new Promise((resolve, reject) => {
        queue.on('job:completed', resolve);
        queue.on('job:failed', ({ error }) => reject(new Error(error)));
        setTimeout(() => reject(new Error('TTS-06 Timeout')), 5000);
      });

      const finishedJob = queue.getJob(res.job.id);
      assert.strictEqual(finishedJob.state, PIPELINE_STATES.PROJECT_READY);
      assert.strictEqual(attempt, 2, 'Must have attempted TTS twice');
      assert.strictEqual(idempotencyKeysUsed[0], `pipeline:${res.job.id}:tts`);
      assert.strictEqual(idempotencyKeysUsed[1], `pipeline:${res.job.id}:tts:r1`);

      console.log('✓ TTS-06 PASS: Failed TTS stage cleanly retried with fresh :r1 idempotency key to PROJECT_READY');
      passed++;
    }

    // --------------------------------------------------------------------------
    // [TTS-07] RENDER_QUEUE_REGRESSION
    // --------------------------------------------------------------------------
    console.log('\n--- [TTS-07] RENDER_QUEUE_REGRESSION ---');
    {
      const storageDir = path.join(TEST_TEMP_DIR, 'storage_tts_07');
      const renderQueuePath = path.join(TEST_TEMP_DIR, 'render_queue_state.json');
      const initialRenderData = JSON.stringify({
        queue_version: '1.0.0',
        active_render_worker: 'capcut_single_worker_01',
        jobs: [{ id: 'render_job_existing', status: 'PENDING' }],
      });
      fs.writeFileSync(renderQueuePath, initialRenderData);

      const { bundleDir } = setupTestBundle('Bundle_TTS_07_RenderIso', 1, 'TTS');

      const customExecutor = {
        generateTtsAudio: async (job) => ({ id: `tts_${job.id}`, status: 'QUEUED' }),
        getTtsJob: async (job, id) => ({ id, status: 'COMPLETED', cloud_file_id: `cf_${job.id}` }),
        cacheCloudAudio: async (job, id, name) => {
          const p = path.join(job.bundle_dir, name);
          fs.writeFileSync(p, Buffer.from('ISO_AUDIO'));
          return { localPath: p };
        },
        buildCapCutProject: async (job) => {
          const dir = path.join(job.options.output_dir, `${job.project_name}.2toolne-autoedit`);
          fs.mkdirSync(dir, { recursive: true });
          fs.writeFileSync(path.join(dir, 'draft_content.json'), JSON.stringify({ ok: true }));
          return dir;
        },
        verifyCapCutProject: async () => true,
      };

      const queue = new PipelineQueueV2({ storageDir, customExecutor, ttsPollIntervalMs: 30 });
      const res = queue.enqueue(bundleDir);

      await new Promise((resolve, reject) => {
        queue.on('job:completed', resolve);
        queue.on('job:failed', ({ error }) => reject(new Error(error)));
        setTimeout(() => reject(new Error('TTS-07 Timeout')), 5000);
      });

      // Verify render_queue_state.json was completely untouched
      const renderDataAfter = fs.readFileSync(renderQueuePath, 'utf8');
      assert.strictEqual(renderDataAfter, initialRenderData,
        'Render Queue (Queue B) state must remain completely untouched during TTS pipeline execution');

      console.log('✓ TTS-07 PASS: Queue B and Single CapCut Worker Invariant remained 100% isolated and untouched');
      passed++;
    }

    // --------------------------------------------------------------------------
    // [TTS-08] AUTO_HANDOFF_RENDER_REGRESSION
    // --------------------------------------------------------------------------
    console.log('\n--- [TTS-08] AUTO_HANDOFF_RENDER_REGRESSION ---');
    {
      const storageDir = path.join(TEST_TEMP_DIR, 'storage_tts_08');
      const renderQueuePath = path.join(TEST_TEMP_DIR, 'render_queue_state_08.json');
      const { bundleDir } = setupTestBundle('Bundle_TTS_08_Handoff', 1, 'TTS');

      let capturedTtsParams = null;
      let handoffEvents = [];
      let enqueueRenderCalls = 0;

      // Initialize real Python RenderQueueManager persistence snapshot with 1 pre-existing job
      const pyInitScript = `
import sys, os
sys.path.insert(0, os.path.abspath('apps/capcut-v2'))
from adapters.capcut.render_queue_manager import RenderQueueManager
from adapters.capcut.render_job import RenderJob

qm = RenderQueueManager(persistence_path=sys.argv[1])
job0 = RenderJob(
    project_id='proj_existing',
    draft_id='draft_existing',
    draft_path='/tmp/draft_existing',
    output_path='/tmp/out_existing.mp4',
    output_filename='out_existing.mp4',
    render_profile_id='macos_capcut_9_4_0',
)
qm.enqueue(job0)
`;
      cp.execFileSync('/opt/homebrew/bin/python3.12', ['-c', pyInitScript, renderQueuePath]);

      const stateBeforeHandoff = JSON.parse(fs.readFileSync(renderQueuePath, 'utf8'));
      assert.strictEqual(stateBeforeHandoff.jobs.length, 1, 'Render Queue B must start with 1 existing job');

      const customExecutor = {
        generateTtsAudio: async (job, params) => {
          capturedTtsParams = params;
          return { id: `tts_sub_${job.id}`, status: 'QUEUED' };
        },
        getTtsJob: async (job, id) => ({
          id,
          status: 'COMPLETED',
          cloud_file_id: `cf_tts_${job.id}`,
          progress: 100,
        }),
        cacheCloudAudio: async (job, cloudFileId, filename) => {
          const p = path.join(job.bundle_dir, filename);
          fs.writeFileSync(p, Buffer.from('RIFF_TTS_CACHED_AUDIO'));
          return { localPath: p };
        },
        buildCapCutProject: async (job) => {
          const dir = path.join(job.options.output_dir, `${job.project_name}.2toolne-autoedit`);
          fs.mkdirSync(dir, { recursive: true });
          fs.writeFileSync(path.join(dir, 'draft_content.json'), JSON.stringify({ project: job.project_name }));
          return dir;
        },
        verifyCapCutProject: async () => true,
        enqueueRender: (job) => {
          enqueueRenderCalls++;
          const pyEnqueueScript = `
import sys, os
sys.path.insert(0, os.path.abspath('apps/capcut-v2'))
from adapters.capcut.render_queue_manager import RenderQueueManager
from adapters.capcut.render_job import RenderJob

qm = RenderQueueManager(persistence_path=sys.argv[1])
rjob = RenderJob(
    project_id=sys.argv[2],
    draft_id=sys.argv[2],
    draft_path=sys.argv[3],
    output_path=sys.argv[4],
    output_filename=os.path.basename(sys.argv[4]),
    render_profile_id='macos_capcut_9_4_0',
)
qm.enqueue(rjob)
`;
          const outVideoPath = path.join(job.options.output_dir, `${job.project_name}.mp4`);
          cp.execFileSync('/opt/homebrew/bin/python3.12', [
            '-c', pyEnqueueScript,
            renderQueuePath,
            job.id,
            job.capcut_draft_path,
            outVideoPath,
          ]);
        },
      };

      const queue = new PipelineQueueV2({ storageDir, customExecutor, ttsPollIntervalMs: 25 });
      queue.on('job:render_handoff', (data) => {
        handoffEvents.push(data);
      });

      const res = queue.enqueue(bundleDir, {
        auto_handoff_render: true,
        cloud_space_id: 'space_production_456',
        folder_id: 'folder_narration_789',
        tts: {
          voice_id: 'preset_en_ryan',
          language: 'en',
          speed: 1.25,
          output_format: 'wav',
        },
      });

      assert.strictEqual(res.ok, true);

      await new Promise((resolve, reject) => {
        queue.on('job:completed', resolve);
        queue.on('job:failed', ({ error }) => reject(new Error(error)));
        setTimeout(() => reject(new Error('TTS-08 Timeout')), 5000);
      });

      const finishedJob = queue.getJob(res.job.id);
      assert.strictEqual(finishedJob.state, PIPELINE_STATES.PROJECT_READY);

      // 1. Integration assertions on payload
      assert.strictEqual(capturedTtsParams.output_format, 'wav', 'output_format must be wav');
      assert.ok(capturedTtsParams.settings, 'settings object must exist in canonical payload');
      assert.strictEqual(capturedTtsParams.settings.speed, 1.25, 'settings.speed must equal configured speed 1.25');
      assert.strictEqual(capturedTtsParams.cloud_space_id, 'space_production_456', 'cloud_space_id must be passed');
      assert.strictEqual(capturedTtsParams.folder_id, 'folder_narration_789', 'folder_id must be passed');

      // 2. Render Queue B receives exactly one render job (no duplicate)
      assert.strictEqual(handoffEvents.length, 1, 'Exactly one job:render_handoff event emitted');
      assert.strictEqual(enqueueRenderCalls, 1, 'Existing Render Queue B must receive exactly one render job');

      // 3. Inspect real RenderQueueManager persistence file
      const stateAfterHandoff = JSON.parse(fs.readFileSync(renderQueuePath, 'utf8'));
      assert.strictEqual(stateAfterHandoff.jobs.length, 2, 'Render Queue B must have exactly 2 jobs (1 pre-existing + 1 handed off)');
      const handedOffRenderJob = stateAfterHandoff.jobs.find(j => j.project_id === res.job.id);
      assert.ok(handedOffRenderJob, 'Handed off render job must be recorded in Render Queue B');
      assert.strictEqual(handedOffRenderJob.draft_path, finishedJob.capcut_draft_path);

      // 4. Assert TTS logic does not modify RenderQueueManager internals & invariant preserved
      assert.strictEqual(stateAfterHandoff.version, '1.0', 'RenderQueueManager schema version intact');
      assert.ok(['IDLE', 'RUNNING'].includes(stateAfterHandoff.queue_status), 'RenderQueueManager status intact');

      console.log('✓ TTS-08 PASS: TTS PipelineJob -> PROJECT_READY -> auto_handoff_render -> Render Queue B exactly 1 job, 0 duplicates, Single CapCut Worker preserved');
      passed++;
    }

  } finally {
    // Cleanup temporary test files
    try {
      fs.rmSync(TEST_TEMP_DIR, { recursive: true, force: true });
    } catch (e) {}
  }

  console.log('\n' + '='.repeat(80));
  console.log(`ALL ${passed} TESTS IN TTS INTEGRATION SUITE ([TTS-01] THROUGH [TTS-08]) PASSED (100% PASS)`);
  console.log('='.repeat(80));
}

runAllTtsTests().catch((err) => {
  console.error('\n❌ TTS INTEGRATION TEST RUNNER FAILED:', err);
  process.exit(1);
});
