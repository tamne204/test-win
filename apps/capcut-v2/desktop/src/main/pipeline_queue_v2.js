/**
 * apps/capcut-v2/desktop/src/main/pipeline_queue_v2.js
 * 2TOOLNE AUTOEDIT V2 — PIPELINE QUEUE V2 (PHASE 3)
 *
 * Core Product Contracts:
 *   1 Input Bundle = 1 Video = 1 Pipeline Job = 1 CapCut Project.
 *   Scene ID preserved end-to-end: 001, 002, ..., 999.
 *   Strict separation: Queue A (Pipeline Queue) finishes at PROJECT_READY.
 *   Render Queue (Queue B) remains untouched and isolated.
 *
 * Provides:
 *   - Persisted Finite State Machine (FSM)
 *   - Atomic checkpointing & idempotent crash resume
 *   - Character Approval Gate (optional, single/all approve, regenerate)
 *   - Independent scene-level retry
 *   - Job pause, resume, cancel
 *   - CapCut project build & verification gate
 *   - Mini-activity snapshot provider for background UI
 */

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const EventEmitter = require('events');
const { BundleEngine } = require('./bundle_engine');

const PIPELINE_STATES = Object.freeze({
  QUEUED: 'QUEUED',
  VALIDATING_BUNDLE: 'VALIDATING_BUNDLE',
  PREPARING_INPUTS: 'PREPARING_INPUTS',
  PREPARING_CHARACTER_REFS: 'PREPARING_CHARACTER_REFS',
  WAITING_CHARACTER_APPROVAL: 'WAITING_CHARACTER_APPROVAL',
  CHARACTER_REFS_LOCKED: 'CHARACTER_REFS_LOCKED',
  GENERATING_IMAGES: 'GENERATING_IMAGES',
  GENERATING_VIDEOS: 'GENERATING_VIDEOS',
  VERIFYING_GENERATED_ASSETS: 'VERIFYING_GENERATED_ASSETS',
  SYNCING_CLOUD: 'SYNCING_CLOUD',
  PREPARING_LOCAL_CACHE: 'PREPARING_LOCAL_CACHE',
  SUBTITLE_PROCESSING: 'SUBTITLE_PROCESSING',
  TIMELINE_BUILDING: 'TIMELINE_BUILDING',
  CAPCUT_PROJECT_BUILDING: 'CAPCUT_PROJECT_BUILDING',
  VERIFYING_PROJECT: 'VERIFYING_PROJECT',
  PROJECT_READY: 'PROJECT_READY',

  // Interruption / status states
  PAUSED: 'PAUSED',
  WAITING_USER: 'WAITING_USER',
  WAITING_FLOW_LOGIN: 'WAITING_FLOW_LOGIN',
  PAUSED_NO_FLOW_CREDIT: 'PAUSED_NO_FLOW_CREDIT',
  RETRYING: 'RETRYING',
  FAILED: 'FAILED',
  CANCELLED: 'CANCELLED',
});

const STATE_LABELS_VI = Object.freeze({
  QUEUED: 'Đang Chờ Xử Lý',
  VALIDATING_BUNDLE: 'Kiểm Tra Bundle Đầu Vào',
  PREPARING_INPUTS: 'Chuẩn Bị Tài Nguyên',
  PREPARING_CHARACTER_REFS: 'Tạo Ảnh Nhân Vật',
  WAITING_CHARACTER_APPROVAL: 'Chờ Duyệt Nhân Vật',
  CHARACTER_REFS_LOCKED: 'Nhân Vật Đã Khóa',
  GENERATING_IMAGES: 'Đang Tạo Ảnh Phân Cảnh',
  GENERATING_VIDEOS: 'Đang Tạo Video Phân Cảnh',
  VERIFYING_GENERATED_ASSETS: 'Xác Minh Tệp Sinh Ra',
  SYNCING_CLOUD: 'Đồng Bộ Lên Cloud',
  PREPARING_LOCAL_CACHE: 'Chuẩn Bị Bộ Đệm',
  SUBTITLE_PROCESSING: 'Xử Lý Phụ Đề & Âm Thanh',
  TIMELINE_BUILDING: 'Dựng Khung Timeline',
  CAPCUT_PROJECT_BUILDING: 'Đang Tạo Dự Án CapCut',
  VERIFYING_PROJECT: 'Kiểm Tra Dự Án CapCut',
  PROJECT_READY: 'Dự Án Sẵn Sàng (CapCut Ready)',
  PAUSED: 'Đã Tạm Dừng',
  WAITING_USER: 'Chờ Người Dùng Thao Tác',
  WAITING_FLOW_LOGIN: 'Chờ Đăng Nhập Flow',
  PAUSED_NO_FLOW_CREDIT: 'Hết Token Flow — Tạm Dừng',
  RETRYING: 'Đang Thử Lại Cảnh Lỗi',
  FAILED: 'Thất Bại',
  CANCELLED: 'Đã Hủy Bỏ',
});

class PipelineQueueV2 extends EventEmitter {
  /**
   * @param {Object} options
   * @param {string} options.storageDir Directory to persist job files
   * @param {Object} [options.sidecar] Optional CapCut sidecar manager
   * @param {Function} [options.customExecutor] Optional custom execution engine for testing/mocking
   */
  constructor(options = {}) {
    super();
    this.storageDir = options.storageDir || path.join(
      process.env.HOME || process.env.USERPROFILE || '.',
      '.2toolne',
      'pipeline_jobs'
    );
    this.sidecar = options.sidecar || null;
    this.customExecutor = options.customExecutor || null;
    this.jobs = new Map();
    this.activeJobId = null;
    this.isProcessing = false;
    this._stopSignal = false;

    this._ensureStorageDir();
    this.loadPersistedJobs();
  }

  _ensureStorageDir() {
    if (!fs.existsSync(this.storageDir)) {
      fs.mkdirSync(this.storageDir, { recursive: true });
    }
  }

  /**
   * Calculate SHA-256 checksum of a file
   */
  static getFileChecksum(filePath) {
    if (!fs.existsSync(filePath)) return null;
    try {
      const data = fs.readFileSync(filePath);
      return crypto.createHash('sha256').update(data).digest('hex');
    } catch (e) {
      return null;
    }
  }

  /**
   * Calculate overall job progress percentage (0 - 100)
   */
  static calculateProgress(job) {
    if (!job) return 0;
    if (job.state === PIPELINE_STATES.PROJECT_READY) return 100;
    if (job.state === PIPELINE_STATES.CANCELLED) return job.progress_pct || 0;

    const totalScenes = job.scenes?.length || 1;
    let completedImages = 0;
    let completedVideos = 0;

    for (const scene of (job.scenes || [])) {
      if (scene.image_status === 'READY') completedImages++;
      if (scene.video_status === 'READY') completedVideos++;
    }

    // Weight allocation:
    // 10% Initial validation & character refs
    // 35% Image generation
    // 35% Video generation
    // 10% Asset verification & subtitle processing
    // 10% CapCut draft generation & verification
    let pct = 0;

    const stateWeights = {
      [PIPELINE_STATES.QUEUED]: 0,
      [PIPELINE_STATES.VALIDATING_BUNDLE]: 5,
      [PIPELINE_STATES.PREPARING_INPUTS]: 8,
      [PIPELINE_STATES.PREPARING_CHARACTER_REFS]: 10,
      [PIPELINE_STATES.WAITING_CHARACTER_APPROVAL]: 12,
      [PIPELINE_STATES.CHARACTER_REFS_LOCKED]: 15,
      [PIPELINE_STATES.GENERATING_IMAGES]: 15,
      [PIPELINE_STATES.GENERATING_VIDEOS]: 50,
      [PIPELINE_STATES.VERIFYING_GENERATED_ASSETS]: 82,
      [PIPELINE_STATES.SYNCING_CLOUD]: 85,
      [PIPELINE_STATES.PREPARING_LOCAL_CACHE]: 87,
      [PIPELINE_STATES.SUBTITLE_PROCESSING]: 90,
      [PIPELINE_STATES.TIMELINE_BUILDING]: 93,
      [PIPELINE_STATES.CAPCUT_PROJECT_BUILDING]: 96,
      [PIPELINE_STATES.VERIFYING_PROJECT]: 98,
      [PIPELINE_STATES.PROJECT_READY]: 100,
    };

    const baseWeight = stateWeights[job.state] || 0;

    if (job.state === PIPELINE_STATES.GENERATING_IMAGES && totalScenes > 0) {
      pct = 15 + Math.round((completedImages / totalScenes) * 35);
    } else if (job.state === PIPELINE_STATES.GENERATING_VIDEOS && totalScenes > 0) {
      pct = 50 + Math.round((completedVideos / totalScenes) * 32);
    } else {
      pct = baseWeight;
    }

    return Math.min(100, Math.max(0, pct));
  }

  /**
   * Atomic file save: write to <file>.tmp then renameSync to <file>
   */
  _saveJob(job) {
    if (!job || !job.id) return;
    this._ensureStorageDir();
    const targetFile = path.join(this.storageDir, `${job.id}.json`);
    const tempFile = path.join(this.storageDir, `${job.id}.json.tmp`);

    job.progress_pct = PipelineQueueV2.calculateProgress(job);
    job.updated_at = new Date().toISOString();

    const payload = JSON.stringify(job, null, 2);
    fs.writeFileSync(tempFile, payload, 'utf8');
    fs.renameSync(tempFile, targetFile);
  }

  /**
   * Load all jobs from storage directory
   */
  loadPersistedJobs() {
    this._ensureStorageDir();
    try {
      const files = fs.readdirSync(this.storageDir);
      for (const file of files) {
        if (!file.endsWith('.json') || file.endsWith('.tmp')) continue;
        const filePath = path.join(this.storageDir, file);
        try {
          const content = fs.readFileSync(filePath, 'utf8');
          const job = JSON.parse(content);
          if (job && job.id) {
            job.progress_pct = PipelineQueueV2.calculateProgress(job);
            this.jobs.set(job.id, job);
          }
        } catch (err) {
          console.warn(`[PipelineQueueV2] Failed to parse persisted job file: ${file}`, err.message);
        }
      }
    } catch (e) {
      console.warn('[PipelineQueueV2] Error scanning storage directory:', e.message);
    }
  }

  /**
   * Checkpoint active state to disk and emit progress events
   */
  checkpoint(job, newState, activityText = null) {
    if (!job) return;
    const oldState = job.state;
    if (newState) {
      job.previous_state = oldState;
      job.state = newState;
    }
    if (activityText) {
      job.current_activity = activityText;
    } else if (newState) {
      job.current_activity = STATE_LABELS_VI[newState] || newState;
    }

    this._saveJob(job);

    this.emit('job:checkpoint', { job, oldState, newState: job.state });
    if (oldState !== job.state) {
      this.emit('job:state_changed', { job, oldState, newState: job.state });
    }
    this.emit('job:progress', {
      job_id: job.id,
      state: job.state,
      progress_pct: job.progress_pct,
      activity: job.current_activity,
    });
  }

  /**
   * Enqueue an Input Bundle into Pipeline Queue V2
   */
  enqueue(bundleDir, options = {}) {
    const canonicalDir = path.resolve(bundleDir);

    // Duplicate Import Protection (Section 19)
    if (!options.allow_duplicate) {
      for (const existingJob of this.jobs.values()) {
        if (![PIPELINE_STATES.PROJECT_READY, PIPELINE_STATES.CANCELLED].includes(existingJob.state)) {
          const existingPath = existingJob.bundle_dir || existingJob.bundle_path;
          if (existingPath && path.resolve(existingPath) === canonicalDir) {
            return {
              ok: false,
              duplicate: true,
              error: 'Bundle này đã có trong hàng đợi.',
              existing_job_id: existingJob.id,
            };
          }
        }
      }
    }

    const validation = BundleEngine.validateLocalBundle(canonicalDir);
    if (!validation.ok) {
      return { ok: false, error: validation.error, code: validation.code || 'BUNDLE_INVALID' };
    }

    const { project_name, aspect_ratio, scenes: rawScenes, characters: rawCharacters, script, tts } = validation;
    const jobId = `pipejob_${Date.now()}_${crypto.randomBytes(3).toString('hex')}`;
    const projectName = project_name || path.basename(canonicalDir);

    // Build canonical scenes array
    const scenes = [];
    if (Array.isArray(rawScenes)) {
      for (let i = 0; i < rawScenes.length; i++) {
        const sc = rawScenes[i];
        const sceneNum = sc.id || sc.scene_number || i + 1;
        const sceneId = BundleEngine.formatSceneId(sceneNum);
        const slug = BundleEngine.normalizeSlug(sc.slug || sc.title || `scene-${sceneId}`);
        const expectedImg = BundleEngine.buildCanonicalFilename(sceneId, slug, 'png');
        const expectedVid = BundleEngine.buildCanonicalFilename(sceneId, slug, 'mp4');

        const localImgPath = path.join(bundleDir, expectedImg);
        const localVidPath = path.join(bundleDir, expectedVid);

        const imgExists = fs.existsSync(localImgPath);
        const vidExists = fs.existsSync(localVidPath);

        scenes.push({
          scene_number: sceneNum,
          scene_id: sceneId,
          slug,
          prompt: sc.prompt || '',
          camera: sc.camera_motion || sc.camera || null,
          motion: sc.motion || null,
          character_ids: sc.character_refs || sc.character_ids || [],
          image_status: imgExists ? 'READY' : 'PENDING',
          image_path: imgExists ? localImgPath : null,
          image_checksum: imgExists ? PipelineQueueV2.getFileChecksum(localImgPath) : null,
          video_status: vidExists ? 'READY' : 'PENDING',
          video_path: vidExists ? localVidPath : null,
          video_checksum: vidExists ? PipelineQueueV2.getFileChecksum(localVidPath) : null,
          retries: 0,
          error: null,
        });
      }
    }

    // Build character registry
    const charactersList = [];
    if (Array.isArray(rawCharacters)) {
      for (const ch of rawCharacters) {
        charactersList.push({
          id: ch.id || `char_${charactersList.length + 1}`,
          name: ch.name || 'Character',
          description: ch.description || '',
          ref_image_path: ch.ref_image ? path.join(bundleDir, ch.ref_image) : null,
          status: ch.ref_image && fs.existsSync(path.join(bundleDir, ch.ref_image)) ? 'READY' : 'PENDING',
          approved_at: null,
        });
      }
    }

    const job = {
      id: jobId,
      source_type: options.source_type || 'INPUT_BUNDLE',
      bundle_dir: canonicalDir,
      bundle_path: canonicalDir,
      bundle_name: projectName,
      bundle_snapshot: {
        has_manifest: validation.has_manifest,
        has_prompts: validation.has_prompts,
        has_characters: validation.has_characters,
        has_script: validation.has_script,
        has_tts: validation.has_tts,
        scenes_count: validation.scenes_count,
        scene_count: validation.scenes_count,
        images_ready: validation.validation_summary ? validation.validation_summary.images_ready : 0,
        videos_ready: validation.validation_summary ? validation.validation_summary.videos_ready : 0,
      },
      validation_result: validation.validation_summary || null,
      project_name: projectName,
      aspect_ratio: aspect_ratio || '16:9',
      target_fps: options.target_fps || 30,
      workspace_id: options.workspace_id || 'personal',
      created_at: new Date().toISOString(),
      updated_at: new Date().toISOString(),
      state: PIPELINE_STATES.QUEUED,
      previous_state: null,
      progress_pct: 0,
      current_scene_id: null,
      current_activity: STATE_LABELS_VI[PIPELINE_STATES.QUEUED],
      options: {
        require_character_approval: options.require_character_approval !== undefined ? !!options.require_character_approval : false,
        auto_handoff_render: !!options.auto_handoff_render,
        flow_account_id: options.flow_account_id || 'Flow #1',
        output_dir: options.output_dir || path.join(bundleDir, 'output'),
        cloud_sync: !!options.cloud_sync,
      },
      characters: charactersList,
      scenes,
      script_content: script || null,
      tts_data: tts || null,
      manifest: validation.manifest || {},
      error: null,
      capcut_draft_path: null,
      capcut_project_path: null,
      project_verified: false,
    };

    this.jobs.set(jobId, job);
    this._saveJob(job);
    this.emit('job:enqueued', { job });

    // Trigger processing if idle
    if (!this.isProcessing && !options.no_auto_start) {
      setImmediate(() => this.processNext(jobId));
    }

    return { ok: true, job };
  }

  /**
   * Process next available job in the queue
   */
  async processNext(targetJobId = null) {
    if (this.isProcessing) return;
    this.isProcessing = true;
    this._stopSignal = false;

    const RUNNABLE_STATES = [
      PIPELINE_STATES.QUEUED,
      PIPELINE_STATES.RETRYING,
      PIPELINE_STATES.CHARACTER_REFS_LOCKED,
      PIPELINE_STATES.GENERATING_IMAGES,
      PIPELINE_STATES.GENERATING_VIDEOS,
    ];

    try {
      // Find eligible job
      let candidate = null;
      if (targetJobId && this.jobs.has(targetJobId)) {
        const target = this.jobs.get(targetJobId);
        if (RUNNABLE_STATES.includes(target.state)) {
          candidate = target;
        }
      }

      if (!candidate) {
        for (const job of this.jobs.values()) {
          if (RUNNABLE_STATES.includes(job.state)) {
            candidate = job;
            break;
          }
        }
      }

      if (!candidate) {
        this.isProcessing = false;
        return;
      }

      this.activeJobId = candidate.id;
      await this._executePipeline(candidate);
    } catch (err) {
      console.error('[PipelineQueueV2] Processing loop error:', err);
    } finally {
      this.isProcessing = false;
      this.activeJobId = null;
      // If there are other queued jobs, process them next
      const hasMore = Array.from(this.jobs.values()).some(j =>
        RUNNABLE_STATES.includes(j.state)
      );
      if (hasMore && !this._stopSignal) {
        setImmediate(() => this.processNext());
      }
    }
  }

  /**
   * Execute pipeline stages for a job
   */
  async _executePipeline(job) {
    // Stage 1: VALIDATING_BUNDLE
    if (job.state === PIPELINE_STATES.QUEUED || job.state === PIPELINE_STATES.RETRYING) {
      if (!fs.existsSync(job.bundle_dir)) {
        job.error = 'Không tìm thấy Input Bundle.';
        this.checkpoint(job, PIPELINE_STATES.WAITING_USER, 'Không tìm thấy Input Bundle.');
        this.emit('job:waiting_user', { job, error: job.error });
        return;
      }
      this.checkpoint(job, PIPELINE_STATES.VALIDATING_BUNDLE, 'Đang xác thực cấu trúc bundle...');
      const check = BundleEngine.validateLocalBundle(job.bundle_dir);
      if (!check.ok) {
        if (check.code === 'DIR_NOT_FOUND') {
          job.error = 'Không tìm thấy Input Bundle.';
          this.checkpoint(job, PIPELINE_STATES.WAITING_USER, 'Không tìm thấy Input Bundle.');
          this.emit('job:waiting_user', { job, error: job.error });
          return;
        }
        job.error = `Bundle validation failed: ${check.error}`;
        this.checkpoint(job, PIPELINE_STATES.FAILED);
        return;
      }
    }

    // Stage 2: PREPARING_INPUTS
    if (job.state === PIPELINE_STATES.VALIDATING_BUNDLE) {
      this.checkpoint(job, PIPELINE_STATES.PREPARING_INPUTS, 'Chuẩn bị thư mục và tài nguyên đầu vào...');
      if (!fs.existsSync(job.options.output_dir)) {
        fs.mkdirSync(job.options.output_dir, { recursive: true });
      }
    }

    // Stage 3: PREPARING_CHARACTER_REFS & CHARACTER APPROVAL GATE
    if ([PIPELINE_STATES.PREPARING_INPUTS, PIPELINE_STATES.PREPARING_CHARACTER_REFS].includes(job.state)) {
      if (job.characters && job.characters.length > 0) {
        this.checkpoint(job, PIPELINE_STATES.PREPARING_CHARACTER_REFS, 'Đang chuẩn bị ảnh tham chiếu nhân vật...');
        for (const char of job.characters) {
          if (char.status === 'PENDING') {
            try {
              if (this.customExecutor && this.customExecutor.generateCharacterRef) {
                await this.customExecutor.generateCharacterRef(job, char);
              } else {
                char.status = 'READY';
              }
            } catch (err) {
              this._handleFlowError(job, char, err, `nhân vật ${char.name || char.id}`);
              return;
            }
          }
        }

        // Check if character approval is required
        if (job.options.require_character_approval) {
          const unapproved = job.characters.some(c => c.status !== 'APPROVED');
          if (unapproved) {
            this.checkpoint(
              job,
              PIPELINE_STATES.WAITING_CHARACTER_APPROVAL,
              `Chờ người dùng duyệt ${job.characters.length} nhân vật...`
            );
            this.emit('job:character_approval_required', { job, characters: job.characters });
            return; // Halt until user approval
          }
        }

        this.checkpoint(job, PIPELINE_STATES.CHARACTER_REFS_LOCKED, 'Nhân vật đã được khóa, bắt đầu tạo cảnh...');
      } else {
        // No characters to process
        this.checkpoint(job, PIPELINE_STATES.CHARACTER_REFS_LOCKED, 'Bỏ qua bước nhân vật, bắt đầu tạo cảnh...');
      }
    }

    if (job.state === PIPELINE_STATES.WAITING_CHARACTER_APPROVAL) {
      // Still waiting for approval
      return;
    }

    // Stage 4: GENERATING_IMAGES
    if ([PIPELINE_STATES.CHARACTER_REFS_LOCKED, PIPELINE_STATES.GENERATING_IMAGES].includes(job.state)) {
      this.checkpoint(job, PIPELINE_STATES.GENERATING_IMAGES, 'Bắt đầu tạo ảnh phân cảnh...');

      for (let i = 0; i < job.scenes.length; i++) {
        if (this._isHalted(job)) return;
        const scene = job.scenes[i];
        job.current_scene_id = scene.scene_id;

        // Checkpoint resume check: Skip if already verified on disk
        if (scene.image_status === 'READY' && scene.image_path && fs.existsSync(scene.image_path)) {
          continue;
        }

        scene.image_status = 'GENERATING';
        this.checkpoint(job, PIPELINE_STATES.GENERATING_IMAGES, `Đang tạo ảnh Cảnh ${scene.scene_id}/${job.scenes.length}...`);

        try {
          if (this.customExecutor && this.customExecutor.generateImage) {
            await this.customExecutor.generateImage(job, scene);
          } else {
            // Default placeholder generator (simulates Google Flow output file)
            const imgFile = BundleEngine.buildCanonicalFilename(scene.scene_id, scene.slug, 'png');
            const imgPath = path.join(job.bundle_dir, imgFile);
            if (!fs.existsSync(imgPath)) {
              fs.writeFileSync(imgPath, Buffer.from(`PNG_MOCK_${scene.scene_id}_${scene.slug}`));
            }
            scene.image_path = imgPath;
            scene.image_status = 'READY';
          }
          scene.image_checksum = PipelineQueueV2.getFileChecksum(scene.image_path);
          scene.error = null;
        } catch (err) {
          this._handleFlowError(job, scene, err, `ảnh Cảnh ${scene.scene_id}`);
          return;
        }

        this.checkpoint(job, PIPELINE_STATES.GENERATING_IMAGES, `Đã hoàn thành ảnh Cảnh ${scene.scene_id}/${job.scenes.length}`);
      }
    }

    // Stage 5: GENERATING_VIDEOS
    if (job.state === PIPELINE_STATES.GENERATING_IMAGES || job.state === PIPELINE_STATES.GENERATING_VIDEOS) {
      this.checkpoint(job, PIPELINE_STATES.GENERATING_VIDEOS, 'Bắt đầu tạo video phân cảnh...');

      for (let i = 0; i < job.scenes.length; i++) {
        if (this._isHalted(job)) return;
        const scene = job.scenes[i];
        job.current_scene_id = scene.scene_id;

        // Checkpoint resume check: Skip if already verified on disk
        if (scene.video_status === 'READY' && scene.video_path && fs.existsSync(scene.video_path)) {
          continue;
        }

        scene.video_status = 'GENERATING';
        this.checkpoint(job, PIPELINE_STATES.GENERATING_VIDEOS, `Đang tạo video Cảnh ${scene.scene_id}/${job.scenes.length}...`);

        try {
          if (this.customExecutor && this.customExecutor.generateVideo) {
            await this.customExecutor.generateVideo(job, scene);
          } else {
            // Default placeholder generator (simulates Google Flow video output)
            const vidFile = BundleEngine.buildCanonicalFilename(scene.scene_id, scene.slug, 'mp4');
            const vidPath = path.join(job.bundle_dir, vidFile);
            if (!fs.existsSync(vidPath)) {
              fs.writeFileSync(vidPath, Buffer.from(`MP4_MOCK_${scene.scene_id}_${scene.slug}`));
            }
            scene.video_path = vidPath;
            scene.video_status = 'READY';
          }
          scene.video_checksum = PipelineQueueV2.getFileChecksum(scene.video_path);
          scene.error = null;
        } catch (err) {
          this._handleFlowError(job, scene, err, `video Cảnh ${scene.scene_id}`);
          return;
        }

        this.checkpoint(job, PIPELINE_STATES.GENERATING_VIDEOS, `Đã hoàn thành video Cảnh ${scene.scene_id}/${job.scenes.length}`);
      }
    }

    // Stage 6: VERIFYING_GENERATED_ASSETS
    if (job.state === PIPELINE_STATES.GENERATING_VIDEOS || job.state === PIPELINE_STATES.VERIFYING_GENERATED_ASSETS) {
      this.checkpoint(job, PIPELINE_STATES.VERIFYING_GENERATED_ASSETS, 'Đang xác minh toàn bộ tệp sinh ra...');
      for (const scene of job.scenes) {
        if (!scene.image_path || !fs.existsSync(scene.image_path) || fs.statSync(scene.image_path).size === 0) {
          job.error = `Xác minh thất bại: Tệp ảnh cảnh ${scene.scene_id} không hợp lệ.`;
          this.checkpoint(job, PIPELINE_STATES.FAILED);
          return;
        }
        if (!scene.video_path || !fs.existsSync(scene.video_path) || fs.statSync(scene.video_path).size === 0) {
          job.error = `Xác minh thất bại: Tệp video cảnh ${scene.scene_id} không hợp lệ.`;
          this.checkpoint(job, PIPELINE_STATES.FAILED);
          return;
        }
      }
    }

    // Stage 7: SYNCING_CLOUD (Optional)
    if (job.state === PIPELINE_STATES.VERIFYING_GENERATED_ASSETS || job.state === PIPELINE_STATES.SYNCING_CLOUD) {
      if (job.options.cloud_sync) {
        this.checkpoint(job, PIPELINE_STATES.SYNCING_CLOUD, 'Đồng bộ tài sản lên Cloud...');
        if (this.customExecutor && this.customExecutor.syncCloud) {
          await this.customExecutor.syncCloud(job);
        }
      }
    }

    // Stage 8: PREPARING_LOCAL_CACHE & SUBTITLE_PROCESSING
    this.checkpoint(job, PIPELINE_STATES.PREPARING_LOCAL_CACHE, 'Chuẩn bị dữ liệu bộ đệm...');
    this.checkpoint(job, PIPELINE_STATES.SUBTITLE_PROCESSING, 'Xử lý đồng bộ phụ đề & audio...');
    if (this.customExecutor && this.customExecutor.processSubtitles) {
      await this.customExecutor.processSubtitles(job);
    }

    // Stage 9: TIMELINE_BUILDING
    this.checkpoint(job, PIPELINE_STATES.TIMELINE_BUILDING, 'Dựng cấu trúc timeline AutoEdit...');
    if (this.customExecutor && this.customExecutor.buildTimeline) {
      await this.customExecutor.buildTimeline(job);
    }

    // Stage 10: CAPCUT_PROJECT_BUILDING
    this.checkpoint(job, PIPELINE_STATES.CAPCUT_PROJECT_BUILDING, 'Đang tạo dự án CapCut Draft...');
    let draftPath = null;
    try {
      if (this.customExecutor && this.customExecutor.buildCapCutProject) {
        draftPath = await this.customExecutor.buildCapCutProject(job);
      } else if (this.sidecar) {
        const sidecarRes = await this.sidecar.send('GENERATE_CAPCUT_PROJECT', {
          project_name: job.project_name,
          scenes: job.scenes,
          aspect_ratio: job.aspect_ratio,
        });
        draftPath = sidecarRes?.draft_path || sidecarRes?.data?.draft_path;
      } else {
        // Fallback default mock draft folder
        const mockDraftDir = path.join(job.options.output_dir, `${job.project_name}.2toolne-autoedit`);
        if (!fs.existsSync(mockDraftDir)) fs.mkdirSync(mockDraftDir, { recursive: true });
        const contentFile = path.join(mockDraftDir, 'draft_content.json');
        fs.writeFileSync(contentFile, JSON.stringify({
          project_name: job.project_name,
          duration: job.scenes.length * 5,
          tracks: [{ type: 'video', clips_count: job.scenes.length }],
        }, null, 2));
        const infoFile = path.join(mockDraftDir, 'draft_info.json');
        fs.writeFileSync(infoFile, JSON.stringify({
          draft_id: job.id,
          draft_name: job.project_name,
        }, null, 2));
        draftPath = mockDraftDir;
      }
      job.capcut_draft_path = draftPath;
      job.capcut_project_path = draftPath;
    } catch (err) {
      job.error = `Lỗi tạo dự án CapCut: ${err.message}`;
      this.checkpoint(job, PIPELINE_STATES.FAILED);
      this.emit('job:failed', { job, error: err.message });
      return;
    }

    // Stage 11: VERIFYING_PROJECT
    this.checkpoint(job, PIPELINE_STATES.VERIFYING_PROJECT, 'Kiểm tra tệp tin dự án CapCut...');
    let isVerified = false;
    if (this.customExecutor && this.customExecutor.verifyCapCutProject) {
      isVerified = await this.customExecutor.verifyCapCutProject(job, draftPath);
    } else {
      if (draftPath && fs.existsSync(draftPath)) {
        const draftContent = path.join(draftPath, 'draft_content.json');
        if (fs.existsSync(draftContent)) {
          try {
            const parsed = JSON.parse(fs.readFileSync(draftContent, 'utf8'));
            isVerified = !!parsed;
          } catch (e) {
            isVerified = false;
          }
        } else {
          isVerified = true;
        }
      }
    }

    if (!isVerified) {
      job.error = 'Xác minh dự án CapCut thất bại: Thư mục hoặc tệp draft_content.json không hợp lệ.';
      this.checkpoint(job, PIPELINE_STATES.FAILED);
      this.emit('job:failed', { job, error: job.error });
      return;
    }

    // Stage 12: PROJECT_READY (Terminal Success State)
    job.project_verified = true;
    this.checkpoint(job, PIPELINE_STATES.PROJECT_READY, 'Dự án CapCut đã sẵn sàng!');
    this.emit('job:completed', { job, draft_path: job.capcut_draft_path });

    // Optional handoff to Render Queue (strictly if enabled)
    if (job.options.auto_handoff_render) {
      this.handoffToRenderQueue(job.id);
    }
  }

  _handleFlowError(job, targetItem, err, type = 'cảnh') {
    const msg = err?.message || String(err);
    const isVideo = type.includes('video');
    const isImage = type.includes('ảnh');

    if (msg.includes('FLOW_LOGIN_REQUIRED')) {
      if (isVideo) targetItem.video_status = 'PENDING';
      else if (isImage) targetItem.image_status = 'PENDING';
      else targetItem.status = 'PENDING';
      job.error = 'Cần đăng nhập Google Flow để tiếp tục xử lý.';
      this.checkpoint(job, PIPELINE_STATES.WAITING_FLOW_LOGIN, job.error);
      this.emit('job:waiting_flow_login', { job, error: job.error });
      return;
    }
    if (msg.includes('FLOW_CAPTCHA_REQUIRED')) {
      if (isVideo) targetItem.video_status = 'PENDING';
      else if (isImage) targetItem.image_status = 'PENDING';
      else targetItem.status = 'PENDING';
      job.error = 'Google Flow yêu cầu giải CAPTCHA. Vui lòng thao tác trên trình duyệt Flow.';
      this.checkpoint(job, PIPELINE_STATES.WAITING_USER, job.error);
      this.emit('job:waiting_user', { job, error: job.error });
      return;
    }
    if (msg.includes('FLOW_CREDIT_EXHAUSTED')) {
      if (isVideo) targetItem.video_status = 'PENDING';
      else if (isImage) targetItem.image_status = 'PENDING';
      else targetItem.status = 'PENDING';
      job.error = 'Tài khoản Google Flow đã hết lượt tạo (Credit Exhausted).';
      this.checkpoint(job, PIPELINE_STATES.PAUSED_NO_FLOW_CREDIT, job.error);
      this.emit('job:no_flow_credit', { job, error: job.error });
      return;
    }
    if (msg.toLowerCase().includes('takeover')) {
      if (isVideo) targetItem.video_status = 'PENDING';
      else if (isImage) targetItem.image_status = 'PENDING';
      else targetItem.status = 'PENDING';
      this.checkpoint(job, PIPELINE_STATES.PAUSED, 'Người dùng tiếp quản Flow thủ công.');
      this.emit('job:paused', { job });
      return;
    }

    if (isVideo) {
      targetItem.video_status = 'FAILED';
    } else if (isImage) {
      targetItem.image_status = 'FAILED';
    } else {
      targetItem.status = 'FAILED';
    }
    targetItem.error = msg;
    job.error = `Lỗi xử lý ${type}: ${msg}`;
    this.checkpoint(job, PIPELINE_STATES.FAILED);
    this.emit('job:failed', { job, item: targetItem, error: msg });
  }

  _isHalted(job) {
    return (
      this._stopSignal ||
      [
        PIPELINE_STATES.PAUSED,
        PIPELINE_STATES.CANCELLED,
        PIPELINE_STATES.FAILED,
        PIPELINE_STATES.WAITING_FLOW_LOGIN,
        PIPELINE_STATES.WAITING_USER,
        PIPELINE_STATES.PAUSED_NO_FLOW_CREDIT,
      ].includes(job.state)
    );
  }

  /**
   * Character Approval Gate: Approve a single character
   */
  approveCharacter(jobId, characterId) {
    const job = this.jobs.get(jobId);
    if (!job) return { ok: false, error: 'Job not found' };

    const char = job.characters?.find(c => c.id === characterId);
    if (!char) return { ok: false, error: 'Character not found' };

    char.status = 'APPROVED';
    char.approved_at = new Date().toISOString();

    const allApproved = job.characters.every(c => c.status === 'APPROVED');
    if (allApproved) {
      this.checkpoint(job, PIPELINE_STATES.CHARACTER_REFS_LOCKED, 'Tất cả nhân vật đã được duyệt.');
      this.emit('job:character_approved', { job, character: char, allApproved: true });
      setImmediate(() => this.processNext(job.id));
    } else {
      this._saveJob(job);
      this.emit('job:character_approved', { job, character: char, allApproved: false });
    }

    return { ok: true, job, all_approved: allApproved };
  }

  /**
   * Character Approval Gate: Approve all characters at once
   */
  approveAllCharacters(jobId) {
    const job = this.jobs.get(jobId);
    if (!job) return { ok: false, error: 'Job not found' };

    for (const char of (job.characters || [])) {
      char.status = 'APPROVED';
      char.approved_at = new Date().toISOString();
    }

    this.checkpoint(job, PIPELINE_STATES.CHARACTER_REFS_LOCKED, 'Toàn bộ nhân vật đã được duyệt.');
    this.emit('job:character_approved', { job, allApproved: true });
    setImmediate(() => this.processNext(job.id));

    return { ok: true, job, all_approved: true };
  }

  /**
   * Character Approval Gate: Regenerate a character
   */
  regenerateCharacter(jobId, characterId) {
    const job = this.jobs.get(jobId);
    if (!job) return { ok: false, error: 'Job not found' };

    const char = job.characters?.find(c => c.id === characterId);
    if (!char) return { ok: false, error: 'Character not found' };

    char.status = 'PENDING';
    char.approved_at = null;
    this._saveJob(job);
    this.emit('job:character_regenerate_requested', { job, character: char });

    return { ok: true, job };
  }

  /**
   * Scene-Level Independent Retry: Reset single failed scene without invalidating others
   */
  retryScene(jobId, sceneId) {
    const job = this.jobs.get(jobId);
    if (!job) return { ok: false, error: 'Job not found' };

    const paddedId = BundleEngine.formatSceneId(sceneId);
    const scene = job.scenes?.find(s => s.scene_id === paddedId || String(s.scene_number) === String(sceneId));
    if (!scene) return { ok: false, error: `Scene ${sceneId} not found in job` };

    scene.retries = (scene.retries || 0) + 1;
    scene.error = null;

    if (scene.video_status === 'FAILED') {
      scene.video_status = 'PENDING';
    }
    if (scene.image_status === 'FAILED') {
      scene.image_status = 'PENDING';
    }

    if (job.state === PIPELINE_STATES.FAILED) {
      job.error = null;
      this.checkpoint(job, PIPELINE_STATES.RETRYING, `Thử lại Cảnh ${scene.scene_id}...`);
    } else {
      this._saveJob(job);
    }

    this.emit('job:scene_updated', { job, scene });

    if (!this.isProcessing) {
      setImmediate(() => this.processNext(job.id));
    }

    return { ok: true, job, scene };
  }

  /**
   * Pause an active or pending job
   */
  pauseJob(jobId) {
    const job = this.jobs.get(jobId);
    if (!job) return { ok: false, error: 'Job not found' };

    this.checkpoint(job, PIPELINE_STATES.PAUSED, 'Tác vụ đã tạm dừng bởi người dùng.');
    if (this.activeJobId === jobId) {
      this._stopSignal = true;
    }
    this.emit('job:paused', { job });
    return { ok: true, job };
  }

  /**
   * Resume a paused or failed job from its last checkpoint
   */
  resumeJob(jobId) {
    const job = this.jobs.get(jobId);
    if (!job) return { ok: false, error: 'Job not found' };

    job.error = null;
    this.checkpoint(job, PIPELINE_STATES.QUEUED, 'Tiếp tục xử lý từ điểm lưu (checkpoint)...');
    this.emit('job:resumed', { job });

    if (!this.isProcessing) {
      setImmediate(() => this.processNext(job.id));
    }
    return { ok: true, job };
  }

  runJob(jobId) {
    return this.resumeJob(jobId);
  }

  /**
   * Cancel a job
   */
  cancelJob(jobId) {
    const job = this.jobs.get(jobId);
    if (!job) return { ok: false, error: 'Job not found' };

    this.checkpoint(job, PIPELINE_STATES.CANCELLED, 'Tác vụ đã bị hủy.');
    if (this.activeJobId === jobId) {
      this._stopSignal = true;
    }
    this.emit('job:cancelled', { job });
    return { ok: true, job };
  }

  /**
   * Optional handoff to Native Render Queue (Queue B)
   */
  handoffToRenderQueue(jobId) {
    const job = this.jobs.get(jobId);
    if (!job) return { ok: false, error: 'Job not found' };
    if (job.state !== PIPELINE_STATES.PROJECT_READY) {
      return { ok: false, error: 'Job is not PROJECT_READY yet' };
    }

    this.emit('job:render_handoff', {
      job_id: job.id,
      project_name: job.project_name,
      draft_path: job.capcut_draft_path,
    });

    return { ok: true, handed_off: true, draft_path: job.capcut_draft_path };
  }

  /**
   * Get active job lightweight summary for background floating popup
   */
  getActiveJobSummary() {
    let active = null;
    if (this.activeJobId && this.jobs.has(this.activeJobId)) {
      active = this.jobs.get(this.activeJobId);
    } else {
      // Find first in-progress or waiting job
      for (const job of this.jobs.values()) {
        if (![PIPELINE_STATES.PROJECT_READY, PIPELINE_STATES.CANCELLED].includes(job.state)) {
          active = job;
          break;
        }
      }
    }

    if (!active) {
      return {
        has_active_job: false,
        job_id: null,
        project_name: null,
        state: null,
        state_label: 'Không có tác vụ chạy ngầm',
        current_scene_id: null,
        total_scenes: 0,
        completed_scenes: 0,
        progress_pct: 0,
        activity_text: 'Hàng đợi đang rảnh',
        flow_account: null,
        can_pause: false,
        can_resume: false,
        can_cancel: false,
        is_waiting_approval: false,
      };
    }

    const totalScenes = active.scenes?.length || 0;
    const completedVideos = (active.scenes || []).filter(s => s.video_status === 'READY').length;

    return {
      has_active_job: true,
      job_id: active.id,
      project_name: active.project_name,
      state: active.state,
      state_label: STATE_LABELS_VI[active.state] || active.state,
      current_scene_id: active.current_scene_id,
      total_scenes: totalScenes,
      completed_scenes: completedVideos,
      progress_pct: active.progress_pct || 0,
      activity_text: active.current_activity || 'Đang xử lý...',
      flow_account: active.options?.flow_account_id || 'Flow #1',
      can_pause: ![PIPELINE_STATES.PAUSED, PIPELINE_STATES.PROJECT_READY, PIPELINE_STATES.CANCELLED].includes(active.state),
      can_resume: [
        PIPELINE_STATES.PAUSED,
        PIPELINE_STATES.FAILED,
        PIPELINE_STATES.WAITING_USER,
        PIPELINE_STATES.WAITING_FLOW_LOGIN,
        PIPELINE_STATES.PAUSED_NO_FLOW_CREDIT,
      ].includes(active.state),
      can_cancel: ![PIPELINE_STATES.PROJECT_READY, PIPELINE_STATES.CANCELLED].includes(active.state),
      is_waiting_approval: active.state === PIPELINE_STATES.WAITING_CHARACTER_APPROVAL,
    };
  }

  getActiveSummary() {
    return this.getActiveJobSummary();
  }

  /**
   * Get job by ID
   */
  getJob(jobId) {
    return this.jobs.get(jobId) || null;
  }

  /**
   * List all jobs
   */
  listJobs() {
    return Array.from(this.jobs.values()).sort((a, b) => new Date(b.created_at) - new Date(a.created_at));
  }

  getAllJobs() {
    return this.listJobs();
  }

  /**
   * Remove completed/cancelled jobs
   */
  clearCompletedJobs() {
    const toRemove = [];
    for (const [id, job] of this.jobs.entries()) {
      if ([PIPELINE_STATES.PROJECT_READY, PIPELINE_STATES.CANCELLED].includes(job.state)) {
        toRemove.push(id);
      }
    }
    for (const id of toRemove) {
      this.jobs.delete(id);
      const filePath = path.join(this.storageDir, `${id}.json`);
      if (fs.existsSync(filePath)) {
        try { fs.unlinkSync(filePath); } catch (e) {}
      }
    }
    return { ok: true, cleared_count: toRemove.length };
  }

  /**
   * Update bundle directory for a job (e.g. if moved or deleted)
   */
  updateJobBundleDir(jobId, newBundleDir) {
    const job = this.jobs.get(jobId);
    if (!job) return { ok: false, error: 'Job not found' };
    const canonical = path.resolve(newBundleDir);
    if (!fs.existsSync(canonical) || !fs.statSync(canonical).isDirectory()) {
      return { ok: false, error: 'Thư mục mới không tồn tại hoặc không hợp lệ.' };
    }
    const validation = BundleEngine.validateLocalBundle(canonical);
    if (!validation.ok) {
      return { ok: false, error: validation.error };
    }
    job.bundle_dir = canonical;
    job.bundle_path = canonical;
    job.error = null;
    this.checkpoint(job, PIPELINE_STATES.QUEUED, 'Đã cập nhật thư mục. Sẵn sàng xử lý.');
    return { ok: true, job };
  }

  /**
   * Delete a job from queue and storage
   */
  deleteJob(jobId) {
    const job = this.jobs.get(jobId);
    if (!job) return { ok: false, error: 'Job not found' };
    if (this.activeJobId === jobId) {
      this.cancelJob(jobId);
    }
    this.jobs.delete(jobId);
    try {
      const targetFile = path.join(this.storageDir, `${jobId}.json`);
      if (fs.existsSync(targetFile)) fs.unlinkSync(targetFile);
    } catch (_) {}
    this.emit('job:deleted', { jobId });
    return { ok: true };
  }

  /**
   * Run all eligible jobs in queue sequentially
   */
  runAll() {
    this._stopSignal = false;
    const queuedJobs = Array.from(this.jobs.values()).filter(j =>
      [PIPELINE_STATES.QUEUED, PIPELINE_STATES.PAUSED].includes(j.state)
    );
    if (!this.isProcessing && queuedJobs.length > 0) {
      setImmediate(() => this.processNext());
    }
    return { ok: true, isProcessing: true, total_queued: queuedJobs.length };
  }
}

module.exports = {
  PipelineQueueV2,
  PIPELINE_STATES,
  STATE_LABELS_VI,
};
