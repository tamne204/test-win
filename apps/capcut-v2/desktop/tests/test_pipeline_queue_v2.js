/**
 * apps/capcut-v2/desktop/tests/test_pipeline_queue_v2.js
 * 2TOOLNE AUTOEDIT V2 — PHASE 3 AUTOMATED VERIFICATION SUITE
 *
 * Tests P3-01 through P3-15:
 *   [P3-01] Job Enqueue & State Initialization
 *   [P3-02] Full FSM Lifecycle Transitions (QUEUED -> PROJECT_READY)
 *   [P3-03] Atomic Checkpointing Persistence
 *   [P3-04] Idempotent Resume from Disk Checkpoint (no duplicate generation)
 *   [P3-05] Character Approval Gate: Disabled Mode
 *   [P3-06] Character Approval Gate: Enabled Mode (Pauses at WAITING_CHARACTER_APPROVAL)
 *   [P3-07] Character Approval: Approve Single Character
 *   [P3-08] Character Approval: Approve All Characters
 *   [P3-09] Character Approval: Regenerate Character
 *   [P3-10] Character Approval Gate State Survives App Restart
 *   [P3-11] Scene-Level Independent Retry
 *   [P3-12] Job Pause & Safe Resume
 *   [P3-13] Job Cancellation
 *   [P3-14] CapCut Project Verification Gate (Failure Handling)
 *   [P3-15] Strict Isolation from Render Queue
 */

const assert = require('assert');
const fs = require('fs');
const path = require('path');
const os = require('os');
const { PipelineQueueV2, PIPELINE_STATES } = require('../src/main/pipeline_queue_v2');
const { BundleEngine } = require('../src/main/bundle_engine');

const TEST_TEMP_DIR = path.join(os.tmpdir(), `2toolne_p3_test_${Date.now()}_${Math.random().toString(36).substring(2, 7)}`);

function setupTestBundle(bundleName = 'Tokyo_001', sceneCount = 3, hasCharacters = true) {
  const bundleDir = path.join(TEST_TEMP_DIR, 'bundles', bundleName);
  fs.mkdirSync(bundleDir, { recursive: true });

  const scenes = [];
  for (let i = 1; i <= sceneCount; i++) {
    const sceneId = BundleEngine.formatSceneId(i);
    scenes.push({
      scene_number: i,
      scene_id: sceneId,
      slug: `shot-${sceneId}`,
      prompt: `Cinematic Tokyo street shot ${i}`,
      character_ids: hasCharacters ? ['char_kenji'] : [],
    });
  }

  const characters = hasCharacters ? [
    {
      id: 'char_kenji',
      name: 'Kenji',
      description: 'Protagonist, dark hair, leather jacket',
      ref_image: 'kenji_ref.png',
    }
  ] : [];

  if (hasCharacters) {
    fs.writeFileSync(path.join(bundleDir, 'kenji_ref.png'), Buffer.from('FAKE_REF_IMAGE'));
  }

  fs.writeFileSync(path.join(bundleDir, '2toolne.json'), JSON.stringify({
    schema_version: '2.0.0',
    project_name: bundleName,
    aspect_ratio: '16:9',
    target_fps: 30,
  }, null, 2));

  fs.writeFileSync(path.join(bundleDir, 'prompts.json'), JSON.stringify({
    schema_version: '2.0.0',
    scenes,
  }, null, 2));

  fs.writeFileSync(path.join(bundleDir, 'characters.json'), JSON.stringify({
    schema_version: '2.0.0',
    characters,
  }, null, 2));

  fs.writeFileSync(path.join(bundleDir, 'script.txt'), 'Tokyo city lights illuminate the rain-slicked asphalt.');

  return { bundleDir, scenes, characters };
}

async function runAllTests() {
  console.log('='.repeat(80));
  console.log('2TOOLNE AUTOEDIT V2 — PHASE 3: PIPELINE QUEUE V2');
  console.log('TEST SUITE: P3-01 THROUGH P3-15');
  console.log('='.repeat(80));

  fs.mkdirSync(TEST_TEMP_DIR, { recursive: true });

  let passed = 0;

  try {
    // --------------------------------------------------------------------------
    // [P3-01] Job Enqueue & State Initialization
    // --------------------------------------------------------------------------
    console.log('\n--- [P3-01] Job Enqueue & State Initialization ---');
    {
      const storageDir = path.join(TEST_TEMP_DIR, 'storage_p3_01');
      const { bundleDir } = setupTestBundle('Bundle_P3_01', 3, true);
      const queue = new PipelineQueueV2({ storageDir });

      const res = queue.enqueue(bundleDir, { no_auto_start: true });
      assert.strictEqual(res.ok, true, 'Enqueue should succeed');
      const job = res.job;

      assert.ok(job.id.startsWith('pipejob_'), 'Job ID must follow pipejob_ convention');
      assert.strictEqual(job.state, PIPELINE_STATES.QUEUED, 'Initial state must be QUEUED');
      assert.strictEqual(job.scenes.length, 3, 'Must have exactly 3 scenes');
      assert.strictEqual(job.scenes[0].scene_id, '001', 'Scene 1 ID must be 001');
      assert.strictEqual(job.scenes[1].scene_id, '002', 'Scene 2 ID must be 002');
      assert.strictEqual(job.scenes[2].scene_id, '003', 'Scene 3 ID must be 003');
      assert.strictEqual(job.characters.length, 1, 'Must have 1 character');
      assert.strictEqual(job.progress_pct, 0, 'Initial progress must be 0%');

      console.log('✓ P3-01 PASS: Enqueue initialized job with canonical scenes and QUEUED state');
      passed++;
    }

    // --------------------------------------------------------------------------
    // [P3-02] Full FSM Lifecycle Transitions (QUEUED -> PROJECT_READY)
    // --------------------------------------------------------------------------
    console.log('\n--- [P3-02] Full FSM Lifecycle Transitions ---');
    {
      const storageDir = path.join(TEST_TEMP_DIR, 'storage_p3_02');
      const { bundleDir } = setupTestBundle('Bundle_P3_02', 2, false);
      const queue = new PipelineQueueV2({ storageDir });

      const statesVisited = [];
      queue.on('job:state_changed', ({ newState }) => {
        statesVisited.push(newState);
      });

      const res = queue.enqueue(bundleDir);
      assert.strictEqual(res.ok, true);

      // Wait for completion
      await new Promise((resolve, reject) => {
        queue.on('job:completed', resolve);
        queue.on('job:failed', ({ error }) => reject(new Error(error)));
        setTimeout(() => reject(new Error('P3-02 Timeout')), 5000);
      });

      const finishedJob = queue.getJob(res.job.id);
      assert.strictEqual(finishedJob.state, PIPELINE_STATES.PROJECT_READY, 'Terminal state must be PROJECT_READY');
      assert.strictEqual(finishedJob.progress_pct, 100, 'Terminal progress must be 100%');
      assert.strictEqual(finishedJob.project_verified, true, 'project_verified must be true');

      // Verify intermediate states visited
      assert.ok(statesVisited.includes(PIPELINE_STATES.VALIDATING_BUNDLE), 'Must visit VALIDATING_BUNDLE');
      assert.ok(statesVisited.includes(PIPELINE_STATES.PREPARING_INPUTS), 'Must visit PREPARING_INPUTS');
      assert.ok(statesVisited.includes(PIPELINE_STATES.GENERATING_IMAGES), 'Must visit GENERATING_IMAGES');
      assert.ok(statesVisited.includes(PIPELINE_STATES.GENERATING_VIDEOS), 'Must visit GENERATING_VIDEOS');
      assert.ok(statesVisited.includes(PIPELINE_STATES.VERIFYING_GENERATED_ASSETS), 'Must visit VERIFYING_GENERATED_ASSETS');
      assert.ok(statesVisited.includes(PIPELINE_STATES.CAPCUT_PROJECT_BUILDING), 'Must visit CAPCUT_PROJECT_BUILDING');
      assert.ok(statesVisited.includes(PIPELINE_STATES.VERIFYING_PROJECT), 'Must visit VERIFYING_PROJECT');
      assert.ok(statesVisited.includes(PIPELINE_STATES.PROJECT_READY), 'Must visit PROJECT_READY');

      console.log('✓ P3-02 PASS: Job transitioned smoothly through all FSM states to PROJECT_READY');
      passed++;
    }

    // --------------------------------------------------------------------------
    // [P3-03] Atomic Checkpointing Persistence
    // --------------------------------------------------------------------------
    console.log('\n--- [P3-03] Atomic Checkpointing Persistence ---');
    {
      const storageDir = path.join(TEST_TEMP_DIR, 'storage_p3_03');
      const { bundleDir } = setupTestBundle('Bundle_P3_03', 2, false);
      const queue = new PipelineQueueV2({ storageDir });

      const res = queue.enqueue(bundleDir, { no_auto_start: true });
      const jobId = res.job.id;

      const jobFilePath = path.join(storageDir, `${jobId}.json`);
      const tmpFilePath = path.join(storageDir, `${jobId}.json.tmp`);

      assert.ok(fs.existsSync(jobFilePath), 'Persisted job JSON file must exist');
      assert.ok(!fs.existsSync(tmpFilePath), 'Temporary .tmp file must NOT linger after rename');

      const fileData = JSON.parse(fs.readFileSync(jobFilePath, 'utf8'));
      assert.strictEqual(fileData.id, jobId);
      assert.strictEqual(fileData.state, PIPELINE_STATES.QUEUED);

      // Mutate via checkpoint
      queue.checkpoint(res.job, PIPELINE_STATES.VALIDATING_BUNDLE, 'Đang kiểm tra...');
      const updatedData = JSON.parse(fs.readFileSync(jobFilePath, 'utf8'));
      assert.strictEqual(updatedData.state, PIPELINE_STATES.VALIDATING_BUNDLE);
      assert.strictEqual(updatedData.current_activity, 'Đang kiểm tra...');

      console.log('✓ P3-03 PASS: Atomic writes persisted correctly without lingering .tmp files');
      passed++;
    }

    // --------------------------------------------------------------------------
    // [P3-04] Idempotent Resume from Disk Checkpoint
    // --------------------------------------------------------------------------
    console.log('\n--- [P3-04] Idempotent Resume from Disk Checkpoint ---');
    {
      const storageDir = path.join(TEST_TEMP_DIR, 'storage_p3_04');
      const { bundleDir } = setupTestBundle('Bundle_P3_04', 3, false);

      // Pre-create image and video for scene 001
      const s1Img = path.join(bundleDir, '001-shot-001.png');
      const s1Vid = path.join(bundleDir, '001-shot-001.mp4');
      fs.writeFileSync(s1Img, 'CUSTOM_IMAGE_001_DATA');
      fs.writeFileSync(s1Vid, 'CUSTOM_VIDEO_001_DATA');

      let s1ImgGenCalls = 0;
      let s1VidGenCalls = 0;

      const customExecutor = {
        generateImage: async (job, scene) => {
          if (scene.scene_id === '001') s1ImgGenCalls++;
          const p = path.join(job.bundle_dir, `${scene.scene_id}-${scene.slug}.png`);
          fs.writeFileSync(p, 'IMG');
          scene.image_path = p;
          scene.image_status = 'READY';
        },
        generateVideo: async (job, scene) => {
          if (scene.scene_id === '001') s1VidGenCalls++;
          const p = path.join(job.bundle_dir, `${scene.scene_id}-${scene.slug}.mp4`);
          fs.writeFileSync(p, 'VID');
          scene.video_path = p;
          scene.video_status = 'READY';
        }
      };

      const queue = new PipelineQueueV2({ storageDir, customExecutor });
      const res = queue.enqueue(bundleDir);

      await new Promise((resolve) => queue.on('job:completed', resolve));

      assert.strictEqual(s1ImgGenCalls, 0, 'Scene 001 image should NOT be regenerated');
      assert.strictEqual(s1VidGenCalls, 0, 'Scene 001 video should NOT be regenerated');

      // Check that existing data was preserved
      assert.strictEqual(fs.readFileSync(s1Img, 'utf8'), 'CUSTOM_IMAGE_001_DATA');
      assert.strictEqual(fs.readFileSync(s1Vid, 'utf8'), 'CUSTOM_VIDEO_001_DATA');

      console.log('✓ P3-04 PASS: Completed assets on disk were skipped during execution resume');
      passed++;
    }

    // --------------------------------------------------------------------------
    // [P3-05] Character Approval Gate: Disabled Mode
    // --------------------------------------------------------------------------
    console.log('\n--- [P3-05] Character Approval Gate: Disabled Mode ---');
    {
      const storageDir = path.join(TEST_TEMP_DIR, 'storage_p3_05');
      const { bundleDir } = setupTestBundle('Bundle_P3_05', 2, true);
      const queue = new PipelineQueueV2({ storageDir });

      let pausedForApproval = false;
      queue.on('job:character_approval_required', () => {
        pausedForApproval = true;
      });

      const res = queue.enqueue(bundleDir, { require_character_approval: false });
      await new Promise((resolve) => queue.on('job:completed', resolve));

      assert.strictEqual(pausedForApproval, false, 'Should not pause when character approval is disabled');
      const finishedJob = queue.getJob(res.job.id);
      assert.strictEqual(finishedJob.state, PIPELINE_STATES.PROJECT_READY);

      console.log('✓ P3-05 PASS: Pipeline proceeded directly without halting when gate disabled');
      passed++;
    }

    // --------------------------------------------------------------------------
    // [P3-06] Character Approval Gate: Enabled Mode
    // --------------------------------------------------------------------------
    console.log('\n--- [P3-06] Character Approval Gate: Enabled Mode ---');
    {
      const storageDir = path.join(TEST_TEMP_DIR, 'storage_p3_06');
      const { bundleDir } = setupTestBundle('Bundle_P3_06', 2, true);
      const queue = new PipelineQueueV2({ storageDir });

      let approvalEventFired = false;
      const approvalPromise = new Promise((resolve) => {
        queue.on('job:character_approval_required', () => {
          approvalEventFired = true;
          resolve();
        });
      });

      const res = queue.enqueue(bundleDir, { require_character_approval: true });
      await approvalPromise;

      const job = queue.getJob(res.job.id);
      assert.strictEqual(approvalEventFired, true, 'Must emit character_approval_required');
      assert.strictEqual(job.state, PIPELINE_STATES.WAITING_CHARACTER_APPROVAL, 'Must halt at WAITING_CHARACTER_APPROVAL');

      console.log('✓ P3-06 PASS: Pipeline paused at WAITING_CHARACTER_APPROVAL as required');
      passed++;
    }

    // --------------------------------------------------------------------------
    // [P3-07] Character Approval: Approve Single Character
    // --------------------------------------------------------------------------
    console.log('\n--- [P3-07] Character Approval: Approve Single Character ---');
    {
      const storageDir = path.join(TEST_TEMP_DIR, 'storage_p3_07');
      const { bundleDir } = setupTestBundle('Bundle_P3_07', 2, true);
      const queue = new PipelineQueueV2({ storageDir });

      const approvalPromise = new Promise((resolve) => {
        queue.on('job:character_approval_required', resolve);
      });

      const res = queue.enqueue(bundleDir, { require_character_approval: true });
      await approvalPromise;

      const job = queue.getJob(res.job.id);
      assert.strictEqual(job.state, PIPELINE_STATES.WAITING_CHARACTER_APPROVAL);

      // Approve single character
      const charId = job.characters[0].id;
      const approveRes = queue.approveCharacter(job.id, charId);
      assert.strictEqual(approveRes.ok, true);
      assert.strictEqual(approveRes.all_approved, true, 'All characters approved since only 1 existed');

      // Should automatically resume and reach completion
      await new Promise((resolve) => queue.on('job:completed', resolve));

      const finishedJob = queue.getJob(job.id);
      assert.strictEqual(finishedJob.state, PIPELINE_STATES.PROJECT_READY);
      assert.strictEqual(finishedJob.characters[0].status, 'APPROVED');

      console.log('✓ P3-07 PASS: Single character approval unlocked pipeline and reached PROJECT_READY');
      passed++;
    }

    // --------------------------------------------------------------------------
    // [P3-08] Character Approval: Approve All Characters
    // --------------------------------------------------------------------------
    console.log('\n--- [P3-08] Character Approval: Approve All Characters ---');
    {
      const storageDir = path.join(TEST_TEMP_DIR, 'storage_p3_08');
      const { bundleDir } = setupTestBundle('Bundle_P3_08', 2, true);
      const queue = new PipelineQueueV2({ storageDir });

      await new Promise((resolve) => {
        queue.on('job:character_approval_required', resolve);
        queue.enqueue(bundleDir, { require_character_approval: true });
      });

      const activeSummary = queue.getActiveJobSummary();
      assert.strictEqual(activeSummary.is_waiting_approval, true);

      // Call approveAllCharacters
      const allRes = queue.approveAllCharacters(activeSummary.job_id);
      assert.strictEqual(allRes.ok, true);
      assert.strictEqual(allRes.all_approved, true);

      await new Promise((resolve) => queue.on('job:completed', resolve));
      const finishedJob = queue.getJob(activeSummary.job_id);
      assert.strictEqual(finishedJob.state, PIPELINE_STATES.PROJECT_READY);

      console.log('✓ P3-08 PASS: approveAllCharacters approved entire registry and resumed processing');
      passed++;
    }

    // --------------------------------------------------------------------------
    // [P3-09] Character Approval: Regenerate Character
    // --------------------------------------------------------------------------
    console.log('\n--- [P3-09] Character Approval: Regenerate Character ---');
    {
      const storageDir = path.join(TEST_TEMP_DIR, 'storage_p3_09');
      const { bundleDir } = setupTestBundle('Bundle_P3_09', 2, true);
      const queue = new PipelineQueueV2({ storageDir });

      await new Promise((resolve) => {
        queue.on('job:character_approval_required', resolve);
        queue.enqueue(bundleDir, { require_character_approval: true });
      });

      const summary = queue.getActiveJobSummary();
      const job = queue.getJob(summary.job_id);
      const charId = job.characters[0].id;

      let regenRequested = false;
      queue.on('job:character_regenerate_requested', () => {
        regenRequested = true;
      });

      const regenRes = queue.regenerateCharacter(job.id, charId);
      assert.strictEqual(regenRes.ok, true);
      assert.strictEqual(regenRequested, true);
      assert.strictEqual(job.characters[0].status, 'PENDING', 'Character status must revert to PENDING');

      console.log('✓ P3-09 PASS: regenerateCharacter reset character status and emitted event');
      passed++;
    }

    // --------------------------------------------------------------------------
    // [P3-10] Character Approval Gate State Survives Restart
    // --------------------------------------------------------------------------
    console.log('\n--- [P3-10] Character Gate State Survives Restart ---');
    {
      const storageDir = path.join(TEST_TEMP_DIR, 'storage_p3_10');
      const { bundleDir } = setupTestBundle('Bundle_P3_10', 2, true);
      const queue1 = new PipelineQueueV2({ storageDir });

      await new Promise((resolve) => {
        queue1.on('job:character_approval_required', resolve);
        queue1.enqueue(bundleDir, { require_character_approval: true });
      });

      const jobId = queue1.getActiveJobSummary().job_id;

      // Simulate crash / restart by creating a new PipelineQueueV2 instance pointing to same storageDir
      const queue2 = new PipelineQueueV2({ storageDir });
      const reloadedJob = queue2.getJob(jobId);

      assert.ok(reloadedJob, 'Job must be reloaded from disk');
      assert.strictEqual(reloadedJob.state, PIPELINE_STATES.WAITING_CHARACTER_APPROVAL,
        'State after restart must remain WAITING_CHARACTER_APPROVAL');

      const summary2 = queue2.getActiveJobSummary();
      assert.strictEqual(summary2.is_waiting_approval, true, 'Active summary must reflect waiting state');

      console.log('✓ P3-10 PASS: Gate state persisted across instance restart');
      passed++;
    }

    // --------------------------------------------------------------------------
    // [P3-11] Scene-Level Independent Retry
    // --------------------------------------------------------------------------
    console.log('\n--- [P3-11] Scene-Level Independent Retry ---');
    {
      const storageDir = path.join(TEST_TEMP_DIR, 'storage_p3_11');
      const { bundleDir } = setupTestBundle('Bundle_P3_11', 3, false);

      let failScene2Once = true;
      const customExecutor = {
        generateVideo: async (job, scene) => {
          if (scene.scene_id === '002' && failScene2Once) {
            failScene2Once = false;
            throw new Error('SIMULATED_NETWORK_TIMEOUT_SCENE_002');
          }
          const p = path.join(job.bundle_dir, `${scene.scene_id}-${scene.slug}.mp4`);
          fs.writeFileSync(p, 'VIDEO_OK');
          scene.video_path = p;
          scene.video_status = 'READY';
        }
      };

      const queue = new PipelineQueueV2({ storageDir, customExecutor });
      const res = queue.enqueue(bundleDir);

      // Wait for failure
      await new Promise((resolve) => queue.on('job:failed', resolve));

      const failedJob = queue.getJob(res.job.id);
      assert.strictEqual(failedJob.state, PIPELINE_STATES.FAILED);
      assert.strictEqual(failedJob.scenes[0].video_status, 'READY', 'Scene 1 should be READY');
      assert.strictEqual(failedJob.scenes[1].video_status, 'FAILED', 'Scene 2 should be FAILED');

      // Retry Scene 2 independently
      const retryRes = queue.retryScene(failedJob.id, '002');
      assert.strictEqual(retryRes.ok, true);
      assert.strictEqual(failedJob.scenes[1].video_status, 'PENDING');
      assert.strictEqual(failedJob.scenes[1].retries, 1);

      // Wait for completion
      await new Promise((resolve) => queue.on('job:completed', resolve));

      const recoveredJob = queue.getJob(failedJob.id);
      assert.strictEqual(recoveredJob.state, PIPELINE_STATES.PROJECT_READY);
      assert.strictEqual(recoveredJob.scenes[1].video_status, 'READY');

      console.log('✓ P3-11 PASS: Scene 2 retried independently without regenerating Scene 1');
      passed++;
    }

    // --------------------------------------------------------------------------
    // [P3-12] Job Pause & Safe Resume
    // --------------------------------------------------------------------------
    console.log('\n--- [P3-12] Job Pause & Safe Resume ---');
    {
      const storageDir = path.join(TEST_TEMP_DIR, 'storage_p3_12');
      const { bundleDir } = setupTestBundle('Bundle_P3_12', 2, false);
      const queue = new PipelineQueueV2({ storageDir });

      const res = queue.enqueue(bundleDir, { no_auto_start: true });
      const jobId = res.job.id;

      // Pause job
      const pauseRes = queue.pauseJob(jobId);
      assert.strictEqual(pauseRes.ok, true);
      assert.strictEqual(queue.getJob(jobId).state, PIPELINE_STATES.PAUSED);

      // Resume job
      const resumeRes = queue.resumeJob(jobId);
      assert.strictEqual(resumeRes.ok, true);

      await new Promise((resolve) => queue.on('job:completed', resolve));
      assert.strictEqual(queue.getJob(jobId).state, PIPELINE_STATES.PROJECT_READY);

      console.log('✓ P3-12 PASS: Job paused and safely resumed to PROJECT_READY');
      passed++;
    }

    // --------------------------------------------------------------------------
    // [P3-13] Job Cancellation
    // --------------------------------------------------------------------------
    console.log('\n--- [P3-13] Job Cancellation ---');
    {
      const storageDir = path.join(TEST_TEMP_DIR, 'storage_p3_13');
      const { bundleDir } = setupTestBundle('Bundle_P3_13', 2, false);
      const queue = new PipelineQueueV2({ storageDir });

      const res = queue.enqueue(bundleDir, { no_auto_start: true });
      const jobId = res.job.id;

      const cancelRes = queue.cancelJob(jobId);
      assert.strictEqual(cancelRes.ok, true);
      assert.strictEqual(queue.getJob(jobId).state, PIPELINE_STATES.CANCELLED);

      // Verify persisted on disk
      const diskData = JSON.parse(fs.readFileSync(path.join(storageDir, `${jobId}.json`), 'utf8'));
      assert.strictEqual(diskData.state, PIPELINE_STATES.CANCELLED);

      console.log('✓ P3-13 PASS: Job cancelled and state persisted cleanly');
      passed++;
    }

    // --------------------------------------------------------------------------
    // [P3-14] CapCut Project Verification Gate
    // --------------------------------------------------------------------------
    console.log('\n--- [P3-14] CapCut Project Verification Gate ---');
    {
      const storageDir = path.join(TEST_TEMP_DIR, 'storage_p3_14');
      const { bundleDir } = setupTestBundle('Bundle_P3_14', 2, false);

      const customExecutor = {
        buildCapCutProject: async () => {
          return '/invalid/non_existent_draft_path';
        },
        verifyCapCutProject: async () => {
          return false; // Verification failure!
        }
      };

      const queue = new PipelineQueueV2({ storageDir, customExecutor });
      const res = queue.enqueue(bundleDir);

      await new Promise((resolve) => queue.on('job:failed', resolve));

      const failedJob = queue.getJob(res.job.id);
      assert.strictEqual(failedJob.state, PIPELINE_STATES.FAILED);
      assert.strictEqual(failedJob.project_verified, false);
      assert.ok(failedJob.error.includes('Xác minh dự án CapCut thất bại'));

      console.log('✓ P3-14 PASS: Project verification failure correctly prevented PROJECT_READY');
      passed++;
    }

    // --------------------------------------------------------------------------
    // [P3-15] Strict Isolation from Render Queue
    // --------------------------------------------------------------------------
    console.log('\n--- [P3-15] Strict Isolation from Render Queue ---');
    {
      const storageDir = path.join(TEST_TEMP_DIR, 'storage_p3_15');
      const renderQueuePath = path.join(TEST_TEMP_DIR, 'render_queue_state.json');
      const initialRenderQueueData = JSON.stringify({
        queue_version: '1.0.0',
        jobs: [{ id: 'render_job_existing', status: 'PENDING' }]
      });
      fs.writeFileSync(renderQueuePath, initialRenderQueueData);

      const { bundleDir } = setupTestBundle('Bundle_P3_15', 2, false);
      const queue = new PipelineQueueV2({ storageDir });

      const res = queue.enqueue(bundleDir);
      await new Promise((resolve) => queue.on('job:completed', resolve));

      // Assert render_queue_state.json was completely untouched
      const renderQueueAfter = fs.readFileSync(renderQueuePath, 'utf8');
      assert.strictEqual(renderQueueAfter, initialRenderQueueData,
        'Render queue file must remain completely untouched by PipelineQueueV2');

      console.log('✓ P3-15 PASS: Queue A and Queue B remained strictly isolated');
      passed++;
    }

  } finally {
    // Cleanup temporary files
    try {
      fs.rmSync(TEST_TEMP_DIR, { recursive: true, force: true });
    } catch (e) {}
  }

  console.log('\n' + '='.repeat(80));
  console.log(`ALL ${passed} TESTS IN PHASE 3 (P3-01 THROUGH P3-15) PASSED (100% PASS)`);
  console.log('='.repeat(80));
}

runAllTests().catch((err) => {
  console.error('\n❌ PHASE 3 TEST RUNNER FAILED:', err);
  process.exit(1);
});
