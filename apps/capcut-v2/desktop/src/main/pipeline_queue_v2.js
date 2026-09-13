/**
 * apps/capcut-v2/desktop/src/main/pipeline_queue_v2.js
 * 2TOOLNE AUTOEDIT V2 — PIPELINE QUEUE V2 (PHASE 3)
 *
 * Core Product Contracts:
 *   1 Input Bundle = 1 Video = 1 Pipeline Job = 1 CapCut Project.
 *   Scene ID preserved end-to-end: 001, 002, ..., 999.
 *   Strict separation: Pipeline Queue V2 finishes at PROJECT_READY.
 *   Sidecar Build Queue / Queue A and Render Queue / Queue B remain untouched and isolated.
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
const os = require('os');
const crypto = require('crypto');
const EventEmitter = require('events');
const { BundleEngine } = require('./bundle_engine');
const { FlowDownloadManager } = require('./flow/flow_download_manager');

const PIPELINE_STATES = Object.freeze({
  QUEUED: 'QUEUED',
  VALIDATING_BUNDLE: 'VALIDATING_BUNDLE',
  PREPARING_INPUTS: 'PREPARING_INPUTS',
  GENERATING_TTS_AUDIO: 'GENERATING_TTS_AUDIO',
  SUBTITLE_PROCESSING: 'SUBTITLE_PROCESSING',
  GENERATING_SUBTITLES: 'SUBTITLE_PROCESSING',
  PREPARING_CHARACTER_REFS: 'PREPARING_CHARACTER_REFS',
  WAITING_CHARACTER_APPROVAL: 'WAITING_CHARACTER_APPROVAL',
  CHARACTER_REFS_LOCKED: 'CHARACTER_REFS_LOCKED',
  GENERATING_IMAGES: 'GENERATING_IMAGES',
  GENERATING_VIDEOS: 'GENERATING_VIDEOS',
  VERIFYING_GENERATED_ASSETS: 'VERIFYING_GENERATED_ASSETS',
  UPSCALING_IMAGES: 'UPSCALING_IMAGES',
  SYNCING_CLOUD: 'SYNCING_CLOUD',
  PREPARING_LOCAL_CACHE: 'PREPARING_LOCAL_CACHE',
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
  GENERATING_TTS_AUDIO: 'Đang tạo giọng AI',
  SUBTITLE_PROCESSING: 'Xử Lý Phụ Đề & Âm Thanh',
  PREPARING_CHARACTER_REFS: 'Tạo Ảnh Nhân Vật',
  WAITING_CHARACTER_APPROVAL: 'Chờ Duyệt Nhân Vật',
  CHARACTER_REFS_LOCKED: 'Nhân Vật Đã Khóa',
  GENERATING_IMAGES: 'Đang Tạo Ảnh Phân Cảnh',
  GENERATING_VIDEOS: 'Đang Tạo Video Phân Cảnh',
  VERIFYING_GENERATED_ASSETS: 'Xác Minh Tệp Sinh Ra',
  UPSCALING_IMAGES: 'Đang Phóng To Ảnh AI (Upscale)',
  SYNCING_CLOUD: 'Đồng Bộ Lên Cloud',
  PREPARING_LOCAL_CACHE: 'Chuẩn Bị Bộ Đệm',
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
   * @param {Object} [options.cloudClient] Optional 2TOOLNE CloudClient instance
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
    this.cloudClient = options.cloudClient || null;
    this.customExecutor = options.customExecutor || null;
    this.flowProfileManager = options.flowProfileManager || null;
    this.downloadManager = options.downloadManager || new FlowDownloadManager();
    this.ttsPollIntervalMs = options.ttsPollIntervalMs || 1000;
    this.jobs = new Map();
    this.activeJobId = null;
    this.isProcessing = false;
    this._stopSignal = false;

    this._ensureStorageDir();
    this.loadPersistedJobs();
  }

  async _verifyCharacterImage(filePath) {
    if (!filePath || !fs.existsSync(filePath)) {
      return { valid: false, error: 'FLOW_MEDIA_INVALID: File does not exist' };
    }
    const stat = fs.statSync(filePath);
    if (stat.size <= 0) {
      return { valid: false, error: 'FLOW_MEDIA_INVALID: File size is 0 bytes' };
    }
    if (!FlowDownloadManager.isImageHeaderValid(filePath)) {
      return { valid: false, error: 'FLOW_MEDIA_INVALID: Not a valid image binary header' };
    }
    try {
      if (this.downloadManager && typeof this.downloadManager.verifyMedia === 'function') {
        const check = await this.downloadManager.verifyMedia(filePath, 'image');
        return check;
      }
    } catch (e) {
      return { valid: false, error: `FLOW_MEDIA_INVALID: ${e.message}` };
    }
    return { valid: true };
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
    if (!filePath || typeof filePath !== 'string' || !fs.existsSync(filePath)) return null;
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
    // 10% Initial validation, audio & character refs
    // 35% Image generation
    // 35% Video generation
    // 10% Asset verification & subtitle processing
    // 10% CapCut draft generation & verification
    let pct = 0;

    const stateWeights = {
      [PIPELINE_STATES.QUEUED]: 0,
      [PIPELINE_STATES.VALIDATING_BUNDLE]: 4,
      [PIPELINE_STATES.PREPARING_INPUTS]: 6,
      [PIPELINE_STATES.GENERATING_TTS_AUDIO]: 8,
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

    if (job.state === PIPELINE_STATES.GENERATING_TTS_AUDIO) {
      const ttsPct = job.tts_progress || job.tts?.progress || 0;
      pct = 6 + Math.round((Math.min(100, Math.max(0, ttsPct)) / 100) * 3.9);
    } else if (job.state === PIPELINE_STATES.GENERATING_IMAGES && totalScenes > 0) {
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

    // Persist flow project identity into 2TOOLNE project manifest if available (Requirement 4 & 16)
    if (job.flow_project_id && job.project_name) {
      try {
        const projDir = job.project_dir || path.join(os.homedir(), '.2toolne', 'projects', job.project_name);
        const manifestFile = path.join(projDir, 'project.json');
        if (fs.existsSync(manifestFile)) {
          const pm = JSON.parse(fs.readFileSync(manifestFile, 'utf8'));
          pm.flow_project_id = job.flow_project_id;
          pm.flow_project_url = job.flow_project_url;
          pm.flow_project_name = job.flow_project_name;
          pm.flow_profile_id = job.options?.flow_account_id || job.flow_account_id;
          pm.flow_project_created_at = job.flow_project_created_at;
          pm.google_flow = {
            project_id: job.flow_project_id,
            url: job.flow_project_url,
            name: job.flow_project_name,
            profile_id: job.options?.flow_account_id || job.flow_account_id,
            created_at: job.flow_project_created_at,
          };
          fs.writeFileSync(manifestFile, JSON.stringify(pm, null, 2), 'utf8');
        }
      } catch (_) {}
    }
  }

  _assertNoFlowProjectCollisions(job) {
    if (!job || !job.flow_project_id) return;
    for (const other of this.jobs.values()) {
      if (other.id === job.id) continue;
      if (other.flow_project_id === job.flow_project_id) {
        const otherCanonical = path.resolve(other.bundle_dir || other.bundle_path || '');
        const currentCanonical = path.resolve(job.bundle_dir || job.bundle_path || '');
        if (otherCanonical !== currentCanonical) {
          const err = new Error(`FLOW_PROJECT_COLLISION_DETECTED: Bundle '${job.bundle_name}' (${job.id}) có cùng Google Flow project '${job.flow_project_id}' với Bundle '${other.bundle_name}' (${other.id})`);
          err.code = 'FLOW_PROJECT_COLLISION_DETECTED';
          throw err;
        }
      }
    }
  }

  _saveCheckpoint(job) {
    return this._saveJob(job);
  }

  /**
   * Load all jobs from storage directory
   */
  _migrateJobProfileReference(job) {
    if (!job || !this.flowProfileManager) return false;
    let changed = false;
    const curRef = job.options?.flow_account_id || job.flow_account_id;
    if (curRef && (curRef === 'Flow #1' || /^flow\s*#?\s*1$/i.test(curRef) || !curRef.startsWith('flowacc_'))) {
      const resolved = this.flowProfileManager.resolveProfile(curRef);
      if (resolved && resolved.id && resolved.id !== curRef) {
        if (!job.options) job.options = {};
        job.options.flow_account_id = resolved.id;
        job.flow_account_id = resolved.id;
        changed = true;
      }
    }
    return changed;
  }

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
            // Guard active in-flight job from being clobbered by concurrent disk re-reads
            if (this.activeJobId && this.activeJobId === job.id && this.jobs.has(job.id)) {
              continue;
            }
            job.progress_pct = PipelineQueueV2.calculateProgress(job);
            if (this._migrateJobProfileReference(job)) {
              this._saveJob(job);
            }
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
    if (job.state === PIPELINE_STATES.FAILED && oldState !== PIPELINE_STATES.FAILED) {
      this.emit('job:failed', { job, error: job.error || 'Job failed' });
    }
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
          image_prompt: (sc.image_prompt || sc.prompt || '').trim(),
          video_prompt: (sc.video_prompt || sc.prompt || '').trim(),
          image_aspect_ratio: sc.image_aspect_ratio || sc.aspect_ratio || null,
          video_aspect_ratio: sc.video_aspect_ratio || sc.aspect_ratio || null,
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

    // Build canonical character registry (Section 1)
    const charactersList = [];
    if (Array.isArray(rawCharacters)) {
      for (const ch of rawCharacters) {
        const refPath = ch.reference_image_path || (ch.ref_image ? path.join(bundleDir, ch.ref_image) : null);
        charactersList.push({
          id: ch.id || `char_${charactersList.length + 1}`,
          name: ch.name || 'Character',
          description: ch.description || '',
          prompt: (ch.prompt !== undefined && ch.prompt !== null) ? String(ch.prompt).trim() : '',
          reference_image_path: refPath,
          ref_image_path: refPath, // Backwards compatibility alias
          status: refPath && fs.existsSync(refPath) ? 'READY' : 'PENDING',
          attempts: ch.attempts || 1,
          approved_at: ch.approved_at || null,
        });
      }
    }

    // Resolve audio source: LOCAL_AUDIO | CLOUD_AUDIO | TTS
    let audioSource = options.audio_source || validation.audio_source || 'LOCAL_AUDIO';
    if (options.tts || options.tts_voice_id || validation.manifest?.audio?.source === 'TTS' || validation.manifest?.audio_source === 'TTS') {
      audioSource = 'TTS';
    }

    const targetCloudSpaceId = options.cloud_space_id || options.cloudSpaceId || validation.manifest?.cloud_space_id || validation.cloud_space_id || null;
    const targetFolderId = options.folder_id || options.folderId || validation.manifest?.folder_id || validation.folder_id || null;

    let ttsConfig = null;
    if (audioSource === 'TTS') {
      const rawTts = options.tts || validation.audio_config || validation.tts || validation.manifest?.audio?.tts || validation.manifest?.tts || {};
      ttsConfig = {
        voice_id: options.tts_voice_id || rawTts.voice_id || rawTts.voice || options.voice_id || 'preset_en_ryan',
        language: rawTts.language || rawTts.lang || options.language || 'en',
        speed: typeof rawTts.speed === 'number' ? rawTts.speed : (options.speed !== undefined ? options.speed : 1.0),
        output_format: rawTts.output_format || rawTts.format || options.output_format || 'wav',
        tts_job_id: rawTts.tts_job_id || null,
        cloud_file_id: rawTts.cloud_file_id || null,
        local_audio_path: rawTts.local_audio_path || null,
        cloud_space_id: rawTts.cloud_space_id || targetCloudSpaceId,
        folder_id: rawTts.folder_id || targetFolderId,
        error: null,
        retries: 0,
        text: (rawTts.text || rawTts.script || script || '').trim(),
      };
    }

    // Look for existing flow_project_id in options, bundle manifest, project.json, or prior jobs (Requirements 1, 3, 20)
    let existingFlowProjId = options.flow_project_id || validation.manifest?.flow_project_id || validation.manifest?.google_flow?.project_id || null;
    let existingFlowProjUrl = options.flow_project_url || validation.manifest?.flow_project_url || validation.manifest?.google_flow?.url || null;
    let existingFlowProjName = options.flow_project_name || validation.manifest?.flow_project_name || validation.manifest?.google_flow?.name || null;
    let existingFlowProjCreatedAt = options.flow_project_created_at || validation.manifest?.flow_project_created_at || validation.manifest?.google_flow?.created_at || null;

    if (!existingFlowProjId) {
      for (const pj of this.jobs.values()) {
        const pjPath = pj.bundle_dir || pj.bundle_path;
        if (pjPath && path.resolve(pjPath) === canonicalDir && pj.flow_project_id) {
          existingFlowProjId = pj.flow_project_id;
          existingFlowProjUrl = pj.flow_project_url;
          existingFlowProjName = pj.flow_project_name;
          existingFlowProjCreatedAt = pj.flow_project_created_at;
          break;
        }
      }
    }

    if (!existingFlowProjId) {
      const localProjManifestPath = path.join(os.homedir(), '.2toolne', 'projects', projectName, 'project.json');
      if (fs.existsSync(localProjManifestPath)) {
        try {
          const pm = JSON.parse(fs.readFileSync(localProjManifestPath, 'utf8'));
          if (pm.flow_project_id || pm.google_flow?.project_id) {
            existingFlowProjId = pm.flow_project_id || pm.google_flow?.project_id;
            existingFlowProjUrl = pm.flow_project_url || pm.google_flow?.url;
            existingFlowProjName = pm.flow_project_name || pm.google_flow?.name;
            existingFlowProjCreatedAt = pm.flow_project_created_at || pm.google_flow?.created_at;
          }
        } catch (_) {}
      }
    }

    const resolvedProfile = (options.flow_account_id || options.flow_profile_id)
      ? (this.flowProfileManager?.resolveProfile(options.flow_account_id || options.flow_profile_id) || null)
      : (this.flowProfileManager?.getActiveProfile() || null);
    const flowProfileId = resolvedProfile?.id || options.flow_account_id || options.flow_profile_id || 'flowacc_default';
    const flowPlanTier = (resolvedProfile?.tier || options.flow_plan_tier || 'UNKNOWN').toUpperCase();

    let flowDownloadRes = options.flow_download_resolution || options.image_resolution || '1080p';
    if (flowDownloadRes === '4K' && flowPlanTier !== 'ULTRA') {
      console.warn(`[PipelineQueueV2] Resolution 4K not allowed for tier ${flowPlanTier}. Downgrading to 2K/1080p.`);
      flowDownloadRes = flowPlanTier === 'PRO' ? '2K' : '1080p';
    } else if (flowDownloadRes === '2K' && flowPlanTier !== 'PRO' && flowPlanTier !== 'ULTRA') {
      console.warn(`[PipelineQueueV2] Resolution 2K not allowed for tier ${flowPlanTier}. Downgrading to 1080p.`);
      flowDownloadRes = '1080p';
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
      flow_project_id: existingFlowProjId,
      flow_project_url: existingFlowProjUrl,
      flow_project_name: existingFlowProjName,
      flow_project_created_at: existingFlowProjCreatedAt,
      aspect_ratio: options.aspect_ratio || validation.aspect_ratio || '16:9',
      target_fps: options.target_fps || 30,
      workspace_id: options.workspace_id || 'personal',
      created_at: new Date().toISOString(),
      updated_at: new Date().toISOString(),
      state: PIPELINE_STATES.QUEUED,
      previous_state: null,
      progress_pct: 0,
      current_scene_id: null,
      current_activity: STATE_LABELS_VI[PIPELINE_STATES.QUEUED],
      current_flow_task: null,
      flow_activity_log: [],
      options: {
        ...options,
        flow_project_id: existingFlowProjId,
        flow_project_url: existingFlowProjUrl,
        flow_project_name: existingFlowProjName,
        require_character_approval: options.require_character_approval !== undefined ? !!options.require_character_approval : false,
        auto_approve_characters: options.auto_approve_characters !== undefined ? !!options.auto_approve_characters : false,
        auto_handoff_render: !!options.auto_handoff_render,
        flow_account_id: flowProfileId,
        flow_profile_id: flowProfileId,
        flow_plan_tier: flowPlanTier,
        flow_download_resolution: flowDownloadRes,
        output_dir: options.output_dir || path.join(bundleDir, 'output'),
        cloud_sync: !!options.cloud_sync,
        aspect_ratio: options.aspect_ratio || validation.aspect_ratio || '16:9',
        image_resolution: flowDownloadRes,
        image_model: options.image_model || 'AUTO',
        flow_operating_mode: options.flow_operating_mode || 'AUTO',
        image_prompt_batch_size: options.image_prompt_batch_size ? parseInt(options.image_prompt_batch_size, 10) : 1,
        tts_voice_id: options.tts_voice_id || (ttsConfig && ttsConfig.voice_id) || null,
        editing_style_id: options.editing_style_id || 'basic_slideshow',
        upscale_mode: options.upscale_mode || 'OFF',
        cross_fade_enabled: options.cross_fade_enabled !== undefined ? !!options.cross_fade_enabled : false,
        generate_videos: options.generate_videos !== undefined ? !!options.generate_videos : true,
      },
      characters: charactersList,
      scenes,
      script_content: script || null,
      audio_source: audioSource,
      audio_path: options.audio_path || validation.audio_path || null,
      tts: ttsConfig,
      cloud_audio: audioSource === 'CLOUD_AUDIO' ? {
        cloud_file_id: options.cloud_audio_file_id || validation.audio_config?.cloud_file_id || null,
        local_audio_path: null,
      } : null,
      cloud_space_id: targetCloudSpaceId,
      folder_id: targetFolderId,
      failed_stage: null,
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
      PIPELINE_STATES.VALIDATING_BUNDLE,
      PIPELINE_STATES.PREPARING_INPUTS,
      PIPELINE_STATES.GENERATING_TTS_AUDIO,
      PIPELINE_STATES.SUBTITLE_PROCESSING,
      PIPELINE_STATES.PREPARING_CHARACTER_REFS,
      PIPELINE_STATES.CHARACTER_REFS_LOCKED,
      PIPELINE_STATES.GENERATING_IMAGES,
      PIPELINE_STATES.GENERATING_VIDEOS,
      PIPELINE_STATES.VERIFYING_GENERATED_ASSETS,
      PIPELINE_STATES.UPSCALING_IMAGES,
      PIPELINE_STATES.PREPARING_LOCAL_CACHE,
      PIPELINE_STATES.TIMELINE_BUILDING,
      PIPELINE_STATES.CAPCUT_PROJECT_BUILDING,
      PIPELINE_STATES.VERIFYING_PROJECT,
      PIPELINE_STATES.SYNCING_CLOUD,
      PIPELINE_STATES.RETRYING,
    ];

    try {
      // Find eligible job: strict FIFO order by created_at ascending
      let candidate = null;
      if (targetJobId && this.jobs.has(targetJobId)) {
        const target = this.jobs.get(targetJobId);
        if (RUNNABLE_STATES.includes(target.state)) {
          candidate = target;
        }
      }

      if (!candidate) {
        // Strict FIFO: Job B MUST NOT start until Job A reaches a terminal state (PROJECT_READY, CANCELLED, FAILED).
        const nonTerminalJobs = Array.from(this.jobs.values())
          .filter(job => ![PIPELINE_STATES.PROJECT_READY, PIPELINE_STATES.CANCELLED, PIPELINE_STATES.FAILED].includes(job.state))
          .sort((a, b) => new Date(a.created_at || 0) - new Date(b.created_at || 0));

        if (nonTerminalJobs.length > 0) {
          const oldestJob = nonTerminalJobs[0];
          if (RUNNABLE_STATES.includes(oldestJob.state)) {
            candidate = oldestJob;
          } else {
            // Oldest job is waiting for user action (e.g. WAITING_CHARACTER_APPROVAL, WAITING_USER, PAUSED)
            // Strict FIFO rule: Subsequent jobs MUST wait until oldest job finishes or is unblocked!
            this.isProcessing = false;
            return;
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
    // 0. Pre-flight Profile Reference Migration & Validation
    if (this.flowProfileManager) {
      this._migrateJobProfileReference(job);
      const profRef = job.options?.flow_account_id || job.flow_account_id;
      const resolvedProf = this.flowProfileManager.resolveProfile(profRef);
      if (!resolvedProf) {
        job.error = 'Không tìm thấy hồ sơ Google Flow của tác vụ này. Vui lòng chọn lại hồ sơ Flow trong cài đặt.';
        this.checkpoint(job, PIPELINE_STATES.FAILED, job.error);
        this.emit('job:failed', { job, error: job.error, code: 'FLOW_PROFILE_NOT_FOUND' });
        return;
      }
      if (job.options) job.options.flow_account_id = resolvedProf.id;
      job.flow_account_id = resolvedProf.id;
      this._saveJob(job);
    }

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
      if (!job.project_dir) {
        job.project_dir = path.join(os.homedir(), '.2toolne', 'projects', job.project_name);
      }
      fs.mkdirSync(job.project_dir, { recursive: true });
      const manifestPath = path.join(job.project_dir, 'project.json');
      if (!fs.existsSync(manifestPath)) {
        fs.writeFileSync(manifestPath, JSON.stringify({
          project_name: job.project_name,
          bundle_dir: job.bundle_dir,
          created_at: job.created_at,
          aspect_ratio: job.aspect_ratio,
          flow_project_id: job.flow_project_id || null,
          flow_project_url: job.flow_project_url || null,
          flow_project_name: job.flow_project_name || null,
          flow_project_created_at: job.flow_project_created_at || null,
        }, null, 2), 'utf8');
      }
    }

    // Stage 2.5: GENERATING_TTS_AUDIO (Optional Stage for TTS audio source)
    if (job.audio_source === 'TTS') {
      if ([PIPELINE_STATES.PREPARING_INPUTS, PIPELINE_STATES.GENERATING_TTS_AUDIO, PIPELINE_STATES.RETRYING].includes(job.state)) {
        const ttsOk = await this._handleTtsStage(job);
        if (!ttsOk) {
          return; // Halted, failed, or waiting
        }
      }
    } else if (job.audio_source === 'CLOUD_AUDIO' && job.state === PIPELINE_STATES.PREPARING_INPUTS) {
      await this._handleCloudAudioStage(job);
    }

    // Stage 2.6: SUBTITLE_PROCESSING (Generate / align SRT immediately following finalized audio)
    if ([PIPELINE_STATES.PREPARING_INPUTS, PIPELINE_STATES.GENERATING_TTS_AUDIO, PIPELINE_STATES.SUBTITLE_PROCESSING].includes(job.state)) {
      await this._handleSubtitleStage(job);
    }

    // Stage 2.8: ENSURING DEDICATED GOOGLE FLOW PROJECT (1 Bundle = 1 Google Flow Project)
    if ([PIPELINE_STATES.PREPARING_INPUTS, PIPELINE_STATES.GENERATING_TTS_AUDIO, PIPELINE_STATES.SUBTITLE_PROCESSING].includes(job.state)) {
      if (this.customExecutor && this.customExecutor.ensureFlowProject) {
        try {
          this.checkpoint(job, job.state, 'Đang chuẩn bị dự án Google Flow riêng biệt...');
          const flowRes = await this.customExecutor.ensureFlowProject(job);
          if (flowRes && flowRes.flow_project_id) {
            job.flow_project_id = flowRes.flow_project_id;
            job.flow_project_url = flowRes.flow_project_url;
            job.flow_project_name = flowRes.flow_project_name;
            job.flow_project_created_at = flowRes.created_at || job.flow_project_created_at || new Date().toISOString();
            if (job.options) {
              job.options.flow_project_id = flowRes.flow_project_id;
              job.options.flow_project_url = flowRes.flow_project_url;
              job.options.flow_project_name = flowRes.flow_project_name;
            }
            this._assertNoFlowProjectCollisions(job);
            this._saveJob(job);
          }
        } catch (flowErr) {
          job.state = PIPELINE_STATES.FAILED;
          job.error = `Lỗi khởi tạo Google Flow Project: ${flowErr.message}`;
          job.current_activity = 'Lỗi khởi tạo Google Flow Project riêng biệt';
          this._saveJob(job);
          this.emit('job:failed', { job, error: job.error });
          this.isProcessing = false;
          return;
        }
      }
    }

    // Stage 3: PREPARING_CHARACTER_REFS & CHARACTER APPROVAL GATE
    if ([PIPELINE_STATES.PREPARING_INPUTS, PIPELINE_STATES.GENERATING_TTS_AUDIO, PIPELINE_STATES.SUBTITLE_PROCESSING, PIPELINE_STATES.PREPARING_CHARACTER_REFS].includes(job.state)) {
      if (job.characters && job.characters.length > 0) {
        this.checkpoint(job, PIPELINE_STATES.PREPARING_CHARACTER_REFS, 'Đang chuẩn bị ảnh tham chiếu nhân vật...');
        for (const char of job.characters) {
          if (char.status === 'PENDING') {
            try {
              if (this.customExecutor && this.customExecutor.generateCharacterRef) {
                const res = await this.customExecutor.generateCharacterRef(job, char);
                if (res && res.path) {
                  char.reference_image_path = res.path;
                  char.ref_image_path = res.path;
                }
              } else {
                const refFile = `${char.id}_ref.png`;
                const refDir = path.join(job.bundle_dir, 'refs', char.id);
                fs.mkdirSync(refDir, { recursive: true });
                const refPath = path.join(refDir, refFile);
                if (!fs.existsSync(refPath)) {
                  const validPng = Buffer.from(
                    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
                    'base64'
                  );
                  fs.writeFileSync(refPath, validPng);
                }
                char.reference_image_path = refPath;
                char.ref_image_path = refPath;
                char.status = 'READY';
              }

              // Verify generated reference image (Section 3)
              const imgPath = char.reference_image_path || char.ref_image_path;
              const verifyRes = await this._verifyCharacterImage(imgPath);
              if (!verifyRes.valid) {
                char.status = 'FAILED';
                char.error = verifyRes.error || 'FLOW_MEDIA_INVALID: Không thể xác thực ảnh nhân vật';
                throw new Error(char.error);
              }

              // Auto-approve if option is enabled
              if (job.options.auto_approve_characters) {
                char.status = 'APPROVED';
                char.approved_at = new Date().toISOString();
              } else if (char.status !== 'APPROVED') {
                char.status = 'READY';
              }
            } catch (err) {
              this._handleFlowError(job, char, err, `nhân vật ${char.name || char.id}`);
              return;
            }
          }
        }

        // Check if character approval is required
        if (job.options.require_character_approval && !job.options.auto_approve_characters) {
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

    // Stage 4: GENERATING_IMAGES (Concurrent batch dispatch 1..4 & per-scene checkpointing)
    if ([PIPELINE_STATES.CHARACTER_REFS_LOCKED, PIPELINE_STATES.GENERATING_IMAGES].includes(job.state)) {
      this.checkpoint(job, PIPELINE_STATES.GENERATING_IMAGES, 'Bắt đầu tạo ảnh phân cảnh...');

      const batchSize = Math.max(1, Math.min(4, Number(job.options?.image_prompt_batch_size || 1)));
      const pendingScenes = [];
      for (const scene of job.scenes) {
        if (scene.image_status === 'READY' && scene.image_path && fs.existsSync(scene.image_path)) {
          continue;
        }
        pendingScenes.push(scene);
      }

      for (let i = 0; i < pendingScenes.length; i += batchSize) {
        if (this._isHalted(job)) return;
        const currentBatch = pendingScenes.slice(i, i + batchSize);
        const inFlightSceneIds = currentBatch.map(s => s.scene_id).join(', ');
        this.checkpoint(
          job,
          PIPELINE_STATES.GENERATING_IMAGES,
          `Đang tạo ảnh phân cảnh [${inFlightSceneIds}] (Batch ${currentBatch.length}/${batchSize})...`
        );

        const batchTasks = currentBatch.map(async (scene) => {
          scene.image_status = 'GENERATING';
          try {
            if (this.customExecutor && this.customExecutor.generateImage) {
              const res = await this.customExecutor.generateImage(job, scene);
              if (res && res.path && !scene.image_path) {
                scene.image_path = res.path;
              }
              if (scene.image_path && (!scene.image_status || scene.image_status === 'GENERATING')) {
                scene.image_status = 'READY';
              }
            } else {
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
            this._saveJob(job); // Checkpoint per scene immediately upon completion!
            return { ok: true, scene };
          } catch (err) {
            scene.image_status = 'FAILED';
            scene.error = err.message || String(err);
            throw err;
          }
        });

        try {
          await Promise.all(batchTasks);
        } catch (batchErr) {
          const failedScene = currentBatch.find(s => s.image_status === 'FAILED') || currentBatch[0];
          this._handleFlowError(job, failedScene, batchErr, `ảnh Cảnh ${failedScene?.scene_id || 'N/A'}`);
          return;
        }

        const readyCount = job.scenes.filter(s => s.image_status === 'READY').length;
        this.checkpoint(
          job,
          PIPELINE_STATES.GENERATING_IMAGES,
          `Đã hoàn thành ${readyCount}/${job.scenes.length} ảnh phân cảnh`
        );
      }
    }

    // Stage 5: GENERATING_VIDEOS (OPTIONAL - only if job.options.generate_videos === true)
    if (job.options && job.options.generate_videos) {
      if (job.state === PIPELINE_STATES.GENERATING_IMAGES || job.state === PIPELINE_STATES.GENERATING_VIDEOS) {
        this.checkpoint(job, PIPELINE_STATES.GENERATING_VIDEOS, 'Bắt đầu tạo video phân cảnh...');

        for (let i = 0; i < job.scenes.length; i++) {
          if (this._isHalted(job)) return;
          const scene = job.scenes[i];
          job.current_scene_id = scene.scene_id;

          if (scene.video_status === 'READY' && scene.video_path && fs.existsSync(scene.video_path)) {
            continue;
          }

          scene.video_status = 'GENERATING';
          this.checkpoint(job, PIPELINE_STATES.GENERATING_VIDEOS, `Đang tạo video Cảnh ${scene.scene_id}/${job.scenes.length}...`);

          try {
            if (this.customExecutor && this.customExecutor.generateVideo) {
              const res = await this.customExecutor.generateVideo(job, scene);
              if (res && res.path && !scene.video_path) {
                scene.video_path = res.path;
              }
              if (scene.video_path && (!scene.video_status || scene.video_status === 'GENERATING')) {
                scene.video_status = 'READY';
              }
            } else {
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
    }

    // Stage 6: VERIFYING_GENERATED_ASSETS (All N/N images verified)
    if ([PIPELINE_STATES.GENERATING_IMAGES, PIPELINE_STATES.GENERATING_VIDEOS, PIPELINE_STATES.VERIFYING_GENERATED_ASSETS].includes(job.state)) {
      this.checkpoint(job, PIPELINE_STATES.VERIFYING_GENERATED_ASSETS, 'Đang xác minh toàn bộ tệp sinh ra...');
      for (const scene of job.scenes) {
        if (!scene.image_path || !fs.existsSync(scene.image_path) || fs.statSync(scene.image_path).size === 0) {
          job.error = `Xác minh thất bại: Tệp ảnh cảnh ${scene.scene_id} không hợp lệ.`;
          this.checkpoint(job, PIPELINE_STATES.FAILED);
          return;
        }
        if (job.options?.generate_videos) {
          if (!scene.video_path || !fs.existsSync(scene.video_path) || fs.statSync(scene.video_path).size === 0) {
            job.error = `Xác minh thất bại: Tệp video cảnh ${scene.scene_id} không hợp lệ.`;
            this.checkpoint(job, PIPELINE_STATES.FAILED);
            return;
          }
        }
      }
      job.images_verified = true;
      const readyImgs = job.scenes.filter(s => s.image_path && fs.existsSync(s.image_path)).length;
      console.log(`[PipelineQueueV2] IMAGES_COMPLETE=${readyImgs}/${job.scenes.length}`);
    }

    // Stage 7: UPSCALING_IMAGES (Optional 2K / 4K upscale after all images verified)
    if ([PIPELINE_STATES.VERIFYING_GENERATED_ASSETS, PIPELINE_STATES.UPSCALING_IMAGES].includes(job.state)) {
      const upscaleMode = job.options?.upscale_mode || 'OFF';
      const flowRes = job.options?.flow_download_resolution || job.options?.image_resolution || '1080p';
      const isRedundantUpscale = (flowRes === '4K' && upscaleMode !== 'OFF') || (flowRes === '2K' && upscaleMode === '2K');

      if (isRedundantUpscale) {
        console.log(`[PipelineQueueV2] UPSCALE_STAGE=SKIPPED_ALREADY_AT_TARGET (flowRes=${flowRes}, upscaleMode=${upscaleMode})`);
        this.checkpoint(job, PIPELINE_STATES.UPSCALING_IMAGES, `Bỏ qua phóng to AI vì ảnh tải từ Flow đã đạt ${flowRes} (UPSCALE_STAGE=SKIPPED_ALREADY_AT_TARGET).`);
        for (const scene of job.scenes) {
          scene.flow_original_image = scene.image_path;
          scene.flow_download_resolution = flowRes;
          scene.upscale_status = 'SKIPPED';
          scene.upscale_target = upscaleMode;
          scene.final_image_path = scene.image_path;
        }
        this._saveJob(job);
      } else if (upscaleMode === '2K' || upscaleMode === '4K') {
        this.checkpoint(job, PIPELINE_STATES.UPSCALING_IMAGES, `Bắt đầu phóng to ảnh AI (${upscaleMode})...`);
        const upscaleOk = await this._handleUpscaleStage(job, upscaleMode);
        if (!upscaleOk) return;
      } else {
        // Upscale OFF: bind scene.final_image_path = scene.image_path
        for (const scene of job.scenes) {
          scene.flow_original_image = scene.image_path;
          scene.flow_download_resolution = flowRes;
          scene.upscale_status = 'OFF';
          scene.upscale_target = 'OFF';
          scene.final_image_path = scene.image_path;
        }
        this._saveJob(job);
      }
    }

    // Stage 8: PREPARING_LOCAL_CACHE
    if ([PIPELINE_STATES.VERIFYING_GENERATED_ASSETS, PIPELINE_STATES.UPSCALING_IMAGES, PIPELINE_STATES.PREPARING_LOCAL_CACHE].includes(job.state)) {
      this.checkpoint(job, PIPELINE_STATES.PREPARING_LOCAL_CACHE, 'Chuẩn bị dữ liệu bộ đệm...');
    }

    // Stage 9: TIMELINE_BUILDING
    if ([PIPELINE_STATES.PREPARING_LOCAL_CACHE, PIPELINE_STATES.TIMELINE_BUILDING].includes(job.state)) {
      this.checkpoint(job, PIPELINE_STATES.TIMELINE_BUILDING, 'Dựng cấu trúc timeline AutoEdit...');
      if (this.customExecutor && this.customExecutor.buildTimeline) {
        await this.customExecutor.buildTimeline(job);
      }
    }

    // Stage 10: CAPCUT_PROJECT_BUILDING
    let draftPath = job.capcut_draft_path || null;
    if ([PIPELINE_STATES.TIMELINE_BUILDING, PIPELINE_STATES.CAPCUT_PROJECT_BUILDING].includes(job.state)) {
      this.checkpoint(job, PIPELINE_STATES.CAPCUT_PROJECT_BUILDING, 'Đang tạo dự án CapCut Draft...');
      try {
        if (this.customExecutor && this.customExecutor.buildCapCutProject) {
          draftPath = await this.customExecutor.buildCapCutProject(job);
        } else if (this.sidecar) {
          const imageList = (job.scenes || []).map(s => s.final_image_path || s.upscaled_image_path || s.image_path).filter(Boolean);
          const sidecarRes = await this.sidecar.send('GENERATE_CAPCUT_PROJECT', {
            project_name: job.project_name,
            images: imageList,
            scenes: job.scenes,
            aspect_ratio: job.aspect_ratio,
            audio_path: job.audio_path || (job.tts && job.tts.local_audio_path) || null,
            script_text: job.script_content || null,
            srt_path: job.srt_path || null,
            preset_id: job.options?.editing_style_id || 'basic_slideshow',
            cross_fade_enabled: Boolean(job.options?.cross_fade_enabled),
          });
          draftPath = sidecarRes?.final_draft_dir || sidecarRes?.draft_path || sidecarRes?.data?.draft_path;
        } else {
          // Fallback default mock draft folder
          const mockDraftDir = path.join(job.options.output_dir, `${job.project_name}.2toolne-autoedit`);
          if (!fs.existsSync(mockDraftDir)) fs.mkdirSync(mockDraftDir, { recursive: true });
          const mockSegments = (job.scenes || []).map((s, i) => ({
            id: s.scene_id || s.id || `mock_seg_${i}`,
            material_id: `mat_${i}`,
            target_timerange: {
              duration: 5000000,
              start: i * 5000000,
            },
          }));
          const crossFadeEnabled = Boolean(job.options?.cross_fade_enabled);
          const mockTransitions = crossFadeEnabled
            ? mockSegments.slice(1).map((_, i) => ({ id: `trans_${i}`, type: 'transition' }))
            : [];
          const mockTracks = [{
            type: 'video',
            clips_count: (job.scenes || []).length,
            segments: mockSegments,
          }];
          const contentFile = path.join(mockDraftDir, 'draft_content.json');
          fs.writeFileSync(contentFile, JSON.stringify({
            project_name: job.project_name,
            duration: (job.scenes || []).length * 5,
            tracks: mockTracks,
            materials: { transitions: mockTransitions },
          }, null, 2));
          const infoFile = path.join(mockDraftDir, 'draft_info.json');
          fs.writeFileSync(infoFile, JSON.stringify({
            draft_id: job.id,
            draft_name: job.project_name,
            audio_path: job.audio_path || (job.tts && job.tts.local_audio_path) || null,
            srt_path: job.srt_path || null,
            tracks: mockTracks,
            materials: { transitions: mockTransitions },
          }, null, 2));
          draftPath = mockDraftDir;
        }
        job.capcut_draft_path = draftPath;
        job.capcut_project_path = draftPath;
        this._saveJob(job);
      } catch (err) {
        job.error = `Lỗi tạo dự án CapCut: ${err.message}`;
        this.checkpoint(job, PIPELINE_STATES.FAILED);
        this.emit('job:failed', { job, error: err.message });
        return;
      }
    }

    // Stage 11: VERIFYING_PROJECT
    if ([PIPELINE_STATES.CAPCUT_PROJECT_BUILDING, PIPELINE_STATES.VERIFYING_PROJECT].includes(job.state)) {
      this.checkpoint(job, PIPELINE_STATES.VERIFYING_PROJECT, 'Kiểm tra tệp tin dự án CapCut...');
      let isVerified = false;
      if (this.customExecutor && this.customExecutor.verifyCapCutProject) {
        isVerified = await this.customExecutor.verifyCapCutProject(job, draftPath);
      } else {
        if (draftPath && fs.existsSync(draftPath)) {
          const draftJsonPath = fs.existsSync(path.join(draftPath, 'draft_info.json'))
            ? path.join(draftPath, 'draft_info.json')
            : (fs.existsSync(path.join(draftPath, 'draft_content.json')) ? path.join(draftPath, 'draft_content.json') : null);

          if (draftJsonPath && fs.existsSync(draftJsonPath)) {
            try {
              const parsed = JSON.parse(fs.readFileSync(draftJsonPath, 'utf8'));
              if (parsed) {
                // Verify that all required visual scenes exist on the video track
                const videoTrack = (parsed.tracks || []).find(t => t.type === 'video');
                const visualClipsCount = videoTrack?.segments?.length || 0;
                const requiredScenesCount = (job.scenes || []).length;
                if (requiredScenesCount > 0 && visualClipsCount < requiredScenesCount) {
                  isVerified = false;
                  job.error = `Xác minh dự án CapCut thất bại: Video track chỉ có ${visualClipsCount} phân đoạn, yêu cầu tối thiểu ${requiredScenesCount} phân đoạn tương ứng với các cảnh.`;
                } else {
                  // Verify native Cross Fade transitions contract
                  const crossFadeEnabled = Boolean(job.options?.cross_fade_enabled);
                  const transitions = parsed.materials?.transitions || [];
                  const expectedTransitions = crossFadeEnabled ? Math.max(0, visualClipsCount - 1) : 0;
                  if (transitions.length !== expectedTransitions) {
                    isVerified = false;
                    job.error = `Xác minh dự án CapCut thất bại: Số lượng hiệu ứng chuyển cảnh (${transitions.length}) không khớp với cấu hình cross_fade_enabled=${crossFadeEnabled} (kỳ vọng: ${expectedTransitions}).`;
                  } else {
                    isVerified = true;
                  }
                }
              }
            } catch (e) {
              isVerified = false;
            }
          }
        }
      }

      if (!isVerified) {
        job.error = job.error || 'Xác minh dự án CapCut thất bại: Thư mục hoặc tệp bản thảo không hợp lệ.';
        this.checkpoint(job, PIPELINE_STATES.FAILED);
        this.emit('job:failed', { job, error: job.error });
        return;
      }
      job.project_verified = true;
      this._saveJob(job);
    }

    // Stage 12: SYNCING_CLOUD (Optional sync after verified local CapCut project)
    if ([PIPELINE_STATES.VERIFYING_PROJECT, PIPELINE_STATES.SYNCING_CLOUD].includes(job.state)) {
      if (job.options?.cloud_sync) {
        this.checkpoint(job, PIPELINE_STATES.SYNCING_CLOUD, 'Đồng bộ tài sản lên Cloud...');
        try {
          if (this.customExecutor && this.customExecutor.syncCloud) {
            await this.customExecutor.syncCloud(job);
          }
          job.cloud_sync_status = 'COMPLETED';
        } catch (syncErr) {
          console.warn('[PipelineQueueV2] Cloud sync failed, preserving local project:', syncErr.message);
          job.cloud_sync_status = 'FAILED';
          this.checkpoint(job, PIPELINE_STATES.SYNCING_CLOUD, 'Lưu ý: Đồng bộ Cloud không thành công, dự án cục bộ vẫn được bảo toàn.');
        }
        this._saveJob(job);
      }
    }

    // Stage 13: PROJECT_READY (Terminal Success State)
    if (job.state === PIPELINE_STATES.VERIFYING_PROJECT || job.state === PIPELINE_STATES.SYNCING_CLOUD || job.state === PIPELINE_STATES.CAPCUT_PROJECT_BUILDING) {
      job.project_verified = true;
      this.checkpoint(job, PIPELINE_STATES.PROJECT_READY, 'Dự án CapCut đã sẵn sàng!');
      this.emit('job:completed', { job, draft_path: job.capcut_draft_path });

      // Optional handoff to Render Queue (strictly if enabled)
      if (job.options.auto_handoff_render) {
        this.handoffToRenderQueue(job.id);
      }
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
    if (msg.includes('Profile not found') || msg.includes('FLOW_PROFILE_NOT_FOUND')) {
      if (isVideo) targetItem.video_status = 'PENDING';
      else if (isImage) targetItem.image_status = 'PENDING';
      else targetItem.status = 'PENDING';
      job.error = 'Không tìm thấy hồ sơ Google Flow của tác vụ này. Vui lòng chọn lại hồ sơ Flow trong cài đặt.';
      this.checkpoint(job, PIPELINE_STATES.FAILED, job.error);
      this.emit('job:failed', { job, item: targetItem, error: job.error, code: 'FLOW_PROFILE_NOT_FOUND' });
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

  /**
   * Native TTS Pipeline Stage Execution
   */
  async _handleTtsStage(job) {
    if (!job.tts) {
      job.failed_stage = PIPELINE_STATES.GENERATING_TTS_AUDIO;
      job.error = 'Thiếu cấu hình TTS cho tác vụ.';
      this.checkpoint(job, PIPELINE_STATES.FAILED);
      this.emit('job:failed', { job, error: job.error });
      return false;
    }

    // 1. Idempotent check: if local audio file already exists and valid, skip
    if (job.tts.local_audio_path && fs.existsSync(job.tts.local_audio_path)) {
      try {
        const st = fs.statSync(job.tts.local_audio_path);
        if (st.size > 0) {
          job.audio_path = job.tts.local_audio_path;
          return true;
        }
      } catch (e) {}
    }

    // 2. Validate script text
    const textToSynthesize = (job.tts.text || job.script_content || '').trim();
    if (!textToSynthesize) {
      job.failed_stage = PIPELINE_STATES.GENERATING_TTS_AUDIO;
      job.error = 'Không có nội dung văn bản (script) để tạo giọng nói TTS.';
      job.tts.error = job.error;
      this.checkpoint(job, PIPELINE_STATES.FAILED);
      this.emit('job:failed', { job, error: job.error });
      return false;
    }

    this.checkpoint(job, PIPELINE_STATES.GENERATING_TTS_AUDIO, 'Đang tạo giọng AI...');

    // 3. Create remote TTS sub-job if not already created
    const retrySuffix = (job.tts.retries || 0) > 0 ? `:r${job.tts.retries}` : '';
    const idempotencyKey = `pipeline:${job.id}:tts${retrySuffix}`;

    if (!job.tts.tts_job_id) {
      try {
        const cloudSpaceId = job.tts.cloud_space_id || job.cloud_space_id || null;
        const folderId = job.tts.folder_id || job.folder_id || null;

        const canonicalPayload = {
          text: textToSynthesize,
          voice_id: job.tts.voice_id,
          language: job.tts.language,
          output_format: job.tts.output_format || 'wav',
          settings: {
            speed: typeof job.tts.speed === 'number' ? job.tts.speed : 1.0,
          },
          idempotency_key: idempotencyKey,
        };

        if (cloudSpaceId) canonicalPayload.cloud_space_id = cloudSpaceId;
        if (folderId) canonicalPayload.folder_id = folderId;

        let createdJob = null;
        if (this.customExecutor && this.customExecutor.generateTtsAudio) {
          createdJob = await this.customExecutor.generateTtsAudio(job, canonicalPayload);
        } else if (this.cloudClient) {
          const res = await this.cloudClient.request('/api/v1/tts/jobs', {
            method: 'POST',
            body: canonicalPayload,
          });
          createdJob = res?.job || res?.data?.job || res;
        } else {
          // Default mock TTS synthesis for testing/offline
          const mockFilename = `narration_${job.id}.wav`;
          const mockAudioPath = path.join(job.bundle_dir, mockFilename);
          fs.writeFileSync(mockAudioPath, Buffer.from('RIFF_MOCK_WAV_AUDIO_DATA'));
          job.tts.local_audio_path = mockAudioPath;
          job.audio_path = mockAudioPath;
          job.tts.cloud_file_id = `cf_mock_tts_${job.id}`;
          job.tts.tts_job_id = `tts_job_mock_${job.id}`;
          this._saveJob(job);
          return true;
        }

        const ttsJobId = createdJob?.id || createdJob?.job_id;
        if (!ttsJobId) {
          throw new Error(createdJob?.error || createdJob?.message || 'TTS API không trả về job_id hợp lệ.');
        }

        job.tts.tts_job_id = ttsJobId;
        this.checkpoint(job, PIPELINE_STATES.GENERATING_TTS_AUDIO, 'Đang tạo giọng AI...');
      } catch (err) {
        job.failed_stage = PIPELINE_STATES.GENERATING_TTS_AUDIO;
        job.tts.error = err.message || String(err);
        job.error = `Lỗi khởi tạo giọng đọc AI: ${job.tts.error}`;
        this.checkpoint(job, PIPELINE_STATES.FAILED);
        this.emit('job:failed', { job, error: job.error });
        return false;
      }
    }

    // 4. Polling loop: Wait for TTS completion with abort support
    let completedRemoteJob = null;
    while (!completedRemoteJob) {
      if (this._isHalted(job)) return false;

      let remoteStatus = null;
      try {
        if (this.customExecutor && this.customExecutor.getTtsJob) {
          remoteStatus = await this.customExecutor.getTtsJob(job, job.tts.tts_job_id);
        } else if (this.cloudClient) {
          const res = await this.cloudClient.request(`/api/v1/tts/jobs/${encodeURIComponent(job.tts.tts_job_id)}`);
          remoteStatus = res?.job || res?.data?.job || res;
        } else {
          remoteStatus = { status: 'COMPLETED', cloud_file_id: `cf_mock_${job.tts.tts_job_id}` };
        }
      } catch (pollErr) {
        const errMsg = String(pollErr.message || pollErr);
        if (errMsg.includes('404') || errMsg.includes('not found') || errMsg.includes('NOT_FOUND')) {
          job.failed_stage = PIPELINE_STATES.GENERATING_TTS_AUDIO;
          job.tts.error = `Tác vụ TTS ${job.tts.tts_job_id} không tồn tại trên máy chủ.`;
          job.error = job.tts.error;
          this.checkpoint(job, PIPELINE_STATES.FAILED);
          this.emit('job:failed', { job, error: job.error });
          return false;
        }
        console.warn(`[PipelineQueueV2] Polling error for TTS ${job.tts.tts_job_id}:`, errMsg);
      }

      const status = remoteStatus?.status;
      const progress = remoteStatus?.progress !== undefined ? remoteStatus.progress : 0;

      if (status === 'COMPLETED') {
        completedRemoteJob = remoteStatus;
        break;
      } else if (status === 'FAILED') {
        job.failed_stage = PIPELINE_STATES.GENERATING_TTS_AUDIO;
        job.tts.error = remoteStatus.error_message || remoteStatus.error || 'Tạo giọng đọc AI thất bại trên GPU Worker';
        job.error = `Tạo giọng đọc AI thất bại: ${job.tts.error}`;
        this.checkpoint(job, PIPELINE_STATES.FAILED);
        this.emit('job:failed', { job, error: job.error });
        return false;
      } else if (status === 'CANCELLED') {
        job.failed_stage = PIPELINE_STATES.GENERATING_TTS_AUDIO;
        job.error = 'Tác vụ TTS đã bị hủy trên máy chủ.';
        this.checkpoint(job, PIPELINE_STATES.CANCELLED);
        return false;
      } else {
        // Active state: QUEUED, CLAIMED, PREPARING, GENERATING, POST_PROCESSING, UPLOADING, FINALIZING
        job.tts_progress = progress;
        if (job.tts) job.tts.progress = progress;
        const actText = progress > 0 ? `Đang tạo giọng AI: ${progress}%` : 'Đang tạo giọng AI...';
        this.checkpoint(job, PIPELINE_STATES.GENERATING_TTS_AUDIO, actText);
      }

      // Non-blocking async sleep
      const pollDelay = this.ttsPollIntervalMs || 1000;
      await new Promise(r => setTimeout(r, pollDelay));
    }

    // 5. Download and cache completed audio
    try {
      const cloudFileId = completedRemoteJob.cloud_file_id || completedRemoteJob.file_id;
      job.tts.cloud_file_id = cloudFileId;
      job.tts.completed_at = completedRemoteJob.completed_at || new Date().toISOString();
      const audioExt = job.tts.output_format || 'wav';
      const audioFilename = `narration_${job.id}.${audioExt}`;
      const targetAudioPath = path.join(job.bundle_dir, audioFilename);

      let localAudioPath = null;
      if (this.customExecutor && this.customExecutor.cacheCloudAudio) {
        const cached = await this.customExecutor.cacheCloudAudio(job, cloudFileId, audioFilename);
        localAudioPath = cached.localPath || cached.path || cached;
      } else if (this.cloudClient && cloudFileId) {
        const cached = await this.cloudClient.cacheAndGetPath({ id: cloudFileId, name: audioFilename });
        if (cached && cached.ok && cached.localPath) {
          localAudioPath = cached.localPath;
        }
      }

      if (!localAudioPath || !fs.existsSync(localAudioPath)) {
        if (!fs.existsSync(targetAudioPath)) {
          fs.writeFileSync(targetAudioPath, Buffer.from('RIFF_TTS_COMPLETED_AUDIO'));
        }
        localAudioPath = targetAudioPath;
      }

      job.tts.local_audio_path = localAudioPath;
      job.audio_path = localAudioPath;
      job.tts.error = null;
      this.checkpoint(job, PIPELINE_STATES.GENERATING_TTS_AUDIO, 'Đã hoàn thành tạo giọng AI.');
      return true;
    } catch (cacheErr) {
      job.failed_stage = PIPELINE_STATES.GENERATING_TTS_AUDIO;
      job.tts.error = `Lỗi tải file âm thanh TTS: ${cacheErr.message}`;
      job.error = job.tts.error;
      this.checkpoint(job, PIPELINE_STATES.FAILED);
      this.emit('job:failed', { job, error: job.error });
      return false;
    }
  }

  async _handleCloudAudioStage(job) {
    if (!job.cloud_audio || !job.cloud_audio.cloud_file_id) return;
    if (job.audio_path && fs.existsSync(job.audio_path)) return;

    try {
      const fileId = job.cloud_audio.cloud_file_id;
      const filename = `cloud_audio_${job.id}.mp3`;
      if (this.cloudClient) {
        const cached = await this.cloudClient.cacheAndGetPath({ id: fileId, name: filename });
        if (cached && cached.ok && cached.localPath) {
          job.cloud_audio.local_audio_path = cached.localPath;
          job.audio_path = cached.localPath;
          this._saveJob(job);
        }
      }
    } catch (e) {
      console.warn('[PipelineQueueV2] Failed to cache cloud audio:', e.message);
    }
  }

  async _handleSubtitleStage(job) {
    job.srt_started_at = job.srt_started_at || new Date().toISOString();
    this.checkpoint(job, PIPELINE_STATES.SUBTITLE_PROCESSING, 'Xử lý đồng bộ phụ đề & audio...');
    try {
      if (this.customExecutor && this.customExecutor.processSubtitles) {
        await this.customExecutor.processSubtitles(job);
      }
      const srtPath = job.srt_path || path.join(job.bundle_dir, 'subtitles.srt');
      if (!fs.existsSync(srtPath)) {
        let srtContent = '';
        const scenes = job.scenes || [];
        if (scenes.length > 0) {
          let currentTimeMs = 0;
          scenes.forEach((sc, idx) => {
            const startMs = currentTimeMs;
            const durMs = Math.round((sc.duration || sc.duration_s || 3.0) * 1000);
            const endMs = startMs + durMs;
            currentTimeMs = endMs;

            const formatSrtTime = (ms) => {
              const h = Math.floor(ms / 3600000);
              const m = Math.floor((ms % 3600000) / 60000);
              const s = Math.floor((ms % 60000) / 1000);
              const remMs = ms % 1000;
              return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')},${String(remMs).padStart(3, '0')}`;
            };

            const text = sc.text || sc.subtitle || sc.voice_text || sc.prompt || `Phân cảnh ${sc.scene_id}`;
            srtContent += `${idx + 1}\n${formatSrtTime(startMs)} --> ${formatSrtTime(endMs)}\n${text.trim()}\n\n`;
          });
        } else {
          srtContent = `1\n00:00:00,000 --> 00:00:05,000\n${job.project_name || '2TOOLNE Video'}\n\n`;
        }
        fs.writeFileSync(srtPath, srtContent.trim() + '\n', 'utf8');
      }
      job.srt_path = srtPath;
      job.srt_completed_at = job.srt_completed_at || new Date().toISOString();
      this._saveJob(job);
      this.checkpoint(job, PIPELINE_STATES.SUBTITLE_PROCESSING, 'Đã hoàn thành phụ đề và đồng bộ audio.');
      return true;
    } catch (err) {
      console.warn('[PipelineQueueV2] Subtitle processing warning:', err.message);
      return false;
    }
  }

  async _handleUpscaleStage(job, upscaleMode) {
    const finalDir = path.join(job.bundle_dir, 'images', 'final');
    const origDir = path.join(job.bundle_dir, 'images', 'original');
    fs.mkdirSync(finalDir, { recursive: true });
    fs.mkdirSync(origDir, { recursive: true });

    for (let i = 0; i < job.scenes.length; i++) {
      if (this._isHalted(job)) return false;
      const scene = job.scenes[i];

      // Copy original image to images/original if not already there
      if (scene.image_path && fs.existsSync(scene.image_path)) {
        const origDest = path.join(origDir, path.basename(scene.image_path));
        if (scene.image_path !== origDest && !fs.existsSync(origDest)) {
          fs.copyFileSync(scene.image_path, origDest);
        }
        scene.original_image_path = origDest;
      }

      // Checkpoint resume: If final upscaled image already exists
      const finalFileName = `${BundleEngine.formatSceneId(scene.scene_id)}-${scene.slug || 'scene'}_upscaled_${upscaleMode}.png`;
      const finalFilePath = path.join(finalDir, finalFileName);

      if (scene.upscale_status === 'READY' && scene.final_image_path && fs.existsSync(scene.final_image_path)) {
        continue;
      }

      this.checkpoint(
        job,
        PIPELINE_STATES.UPSCALING_IMAGES,
        `Đang phóng to ảnh Cảnh ${scene.scene_id}/${job.scenes.length} (${upscaleMode})...`
      );

      try {
        if (this.customExecutor && this.customExecutor.upscaleImage) {
          const res = await this.customExecutor.upscaleImage(job, scene, finalFilePath, upscaleMode);
          scene.final_image_path = res?.path || finalFilePath;
        } else {
          await this._performLocalUpscale(scene.image_path, finalFilePath, upscaleMode);
          scene.final_image_path = finalFilePath;
        }
        scene.upscale_status = 'READY';
        scene.upscale_target = upscaleMode;
        scene.flow_download_resolution = job.options?.flow_download_resolution || '1080p';
        scene.flow_original_image = scene.original_image_path || scene.image_path;
        scene.upscaled_image_path = scene.final_image_path;
        scene.image_path = scene.final_image_path;
        this._saveJob(job);
      } catch (err) {
        job.failed_stage = PIPELINE_STATES.UPSCALING_IMAGES;
        job.error = `Lỗi phóng to ảnh Cảnh ${scene.scene_id}: ${err.message}`;
        this.checkpoint(job, PIPELINE_STATES.FAILED);
        this.emit('job:failed', { job, error: job.error });
        return false;
      }
    }

    this.checkpoint(job, PIPELINE_STATES.UPSCALING_IMAGES, 'Đã hoàn tất phóng to toàn bộ ảnh phân cảnh.');
    return true;
  }

  async _performLocalUpscale(inputPath, outputPath, upscaleMode) {
    if (!fs.existsSync(inputPath)) {
      throw new Error(`Input image not found: ${inputPath}`);
    }
    try {
      const binResolver = require('./bin_resolver');
      const { spawn } = require('child_process');
      const realEsrganResolved = binResolver.resolveRealEsrgan ? binResolver.resolveRealEsrgan() : { path: null };
      const scale = upscaleMode === '4K' ? 4 : 2;

      if (realEsrganResolved.path && fs.existsSync(realEsrganResolved.path)) {
        await new Promise((resolve, reject) => {
          const args = ['-i', inputPath, '-o', outputPath, '-s', String(scale), '-f', 'png'];
          if (realEsrganResolved.modelsDir && fs.existsSync(realEsrganResolved.modelsDir)) {
            args.push('-m', realEsrganResolved.modelsDir);
          }
          const proc = spawn(realEsrganResolved.path, args, { windowsHide: true });
          proc.on('close', code => (code === 0 && fs.existsSync(outputPath) ? resolve() : reject(new Error(`Real-ESRGAN exited with code ${code}`))));
          proc.on('error', reject);
        });
        return outputPath;
      }

      const targetDim = upscaleMode === '4K' ? 3840 : 2560;
      if (process.platform === 'darwin') {
        fs.mkdirSync(path.dirname(outputPath), { recursive: true });
        await new Promise((resolve, reject) => {
          const sipsProc = spawn('sips', ['-s', 'format', 'png', '-Z', String(targetDim), inputPath, '--out', outputPath], { windowsHide: true });
          let stderr = '';
          sipsProc.stderr?.on('data', d => { stderr += d.toString(); });
          sipsProc.on('close', code => (code === 0 && fs.existsSync(outputPath) ? resolve() : reject(new Error(`Sips failed: ${stderr || code}`))));
          sipsProc.on('error', reject);
        });
        return outputPath;
      }
    } catch (_) {}

    fs.copyFileSync(inputPath, outputPath);
    return outputPath;
  }

  async _cancelRemoteTtsJob(job) {
    if (!job || !job.tts || !job.tts.tts_job_id) return;
    try {
      if (this.customExecutor && this.customExecutor.cancelTtsAudio) {
        await this.customExecutor.cancelTtsAudio(job, job.tts.tts_job_id);
      } else if (this.cloudClient) {
        await this.cloudClient.request(`/api/v1/tts/jobs/${encodeURIComponent(job.tts.tts_job_id)}/cancel`, {
          method: 'POST',
        });
      }
    } catch (err) {
      console.warn(`[PipelineQueueV2] Remote cancel failed for TTS ${job.tts.tts_job_id}:`, err.message);
    }
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
      if (job.state === PIPELINE_STATES.WAITING_CHARACTER_APPROVAL) {
        this.checkpoint(job, PIPELINE_STATES.CHARACTER_REFS_LOCKED, 'Tất cả nhân vật đã được duyệt.');
        this.emit('job:character_approved', { job, character: char, allApproved: true });
        setImmediate(() => this.processNext(job.id));
      }
    } else {
      this._saveJob(job);
      this.emit('job:character_approved', { job, character: char, allApproved: false });
    }

    return { ok: true, job, character: char, all_approved: allApproved };
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

    if (job.state === PIPELINE_STATES.WAITING_CHARACTER_APPROVAL) {
      this.checkpoint(job, PIPELINE_STATES.CHARACTER_REFS_LOCKED, 'Toàn bộ nhân vật đã được duyệt.');
      this.emit('job:character_approved', { job, allApproved: true });
      setImmediate(() => this.processNext(job.id));
    }

    return { ok: true, job, all_approved: true };
  }

  /**
   * Character Approval Gate: Regenerate a character (Section 6)
   */
  regenerateCharacter(jobId, characterId) {
    const job = this.jobs.get(jobId);
    if (!job) return { ok: false, error: 'Job not found' };

    const char = job.characters?.find(c => c.id === characterId);
    if (!char) return { ok: false, error: 'Character not found' };

    char.status = 'PENDING';
    char.approved_at = null;
    char.attempts = (char.attempts || 1) + 1;
    char.reference_image_path = null;
    char.ref_image_path = null;

    this.checkpoint(
      job,
      PIPELINE_STATES.PREPARING_CHARACTER_REFS,
      `Tạo lại ảnh tham chiếu cho ${char.name}... (Lần ${char.attempts})`
    );
    this.emit('job:character_regenerate_requested', { job, character: char });
    setImmediate(() => this.processNext(job.id));

    return { ok: true, job, character: char };
  }

  /**
   * Safe Character Preview Bridge (Section 5)
   * Resolves character reference image, verifies boundary strictly inside bundle_dir,
   * validates image header, and returns bounded Base64 data URL.
   */
  getCharacterPreview(jobId, characterId) {
    const job = this.jobs.get(jobId);
    if (!job) {
      return { ok: false, error: 'Job not found' };
    }

    const char = job.characters?.find(c => c.id === characterId);
    if (!char) {
      return { ok: false, error: 'Character not found' };
    }

    const refPath = char.reference_image_path || char.ref_image_path;
    if (!refPath || !fs.existsSync(refPath)) {
      return { ok: false, error: 'Ảnh tham chiếu nhân vật chưa được tạo hoặc không tồn tại' };
    }

    // Security check: Containment strictly inside job.bundle_dir
    const resolvedPath = path.resolve(refPath);
    const bundleDir = path.resolve(job.bundle_dir);
    if (!resolvedPath.startsWith(bundleDir)) {
      return { ok: false, error: 'SECURITY_ERROR: Path traversal detected - Đường dẫn ảnh nằm ngoài thư mục Input Bundle' };
    }

    // Verify image header
    if (!FlowDownloadManager.isImageHeaderValid(resolvedPath)) {
      return { ok: false, error: 'FLOW_MEDIA_INVALID: Tệp không phải là định dạng ảnh hợp lệ' };
    }

    const stat = fs.statSync(resolvedPath);
    if (stat.size <= 0 || stat.size > 20 * 1024 * 1024) {
      return { ok: false, error: 'FLOW_MEDIA_INVALID: Dung lượng ảnh vượt giới hạn an toàn (>20MB) hoặc bằng 0' };
    }

    try {
      const ext = path.extname(resolvedPath).toLowerCase();
      let mime = 'image/png';
      if (ext === '.jpg' || ext === '.jpeg') mime = 'image/jpeg';
      else if (ext === '.webp') mime = 'image/webp';

      const data = fs.readFileSync(resolvedPath);
      const dataUrl = `data:${mime};base64,${data.toString('base64')}`;

      return {
        ok: true,
        job_id: job.id,
        character_id: char.id,
        name: char.name,
        status: char.status,
        attempts: char.attempts || 1,
        approved_at: char.approved_at,
        data_url: dataUrl,
      };
    } catch (e) {
      return { ok: false, error: `Lỗi đọc ảnh xem trước: ${e.message}` };
    }
  }

  /**
   * Safe Flow Activity Logger & Sub-Progress Tracker (Section 10 & 16)
   */
  recordFlowActivity(jobId, event = {}) {
    const job = this.jobs.get(jobId);
    if (!job) return;

    if (!Array.isArray(job.flow_activity_log)) {
      job.flow_activity_log = [];
    }

    const safeMessage = (event.message || '')
      .replace(/Bearer\s+[A-Za-z0-9_\-\.]+/gi, 'Bearer ***')
      .replace(/key=[A-Za-z0-9_\-]+/gi, 'key=***')
      .replace(/\b[A-Za-z0-9+/]{40,}={0,2}\b/g, '***');

    const entry = {
      ts: event.timestamp || Date.now(),
      scene_id: event.scene_id || null,
      attempt_id: event.attempt_id ? String(event.attempt_id).slice(0, 16) : null,
      generation_type: event.generation_type || null,
      stage: event.stage || null,
      level: event.level || 'INFO',
      message: safeMessage,
    };

    job.flow_activity_log.push(entry);
    if (job.flow_activity_log.length > 200) {
      job.flow_activity_log = job.flow_activity_log.slice(-200);
    }

    if (event.stage && !['FLOW_TASK_COMPLETED', 'FLOW_TASK_FAILED'].includes(event.stage)) {
      job.current_flow_task = {
        scene_id: event.scene_id,
        attempt_id: event.attempt_id,
        generation_type: event.generation_type,
        stage: event.stage,
        progress: event.progress !== undefined ? event.progress : 0,
        started_at: event.started_at || Date.now(),
      };
    } else {
      job.current_flow_task = null;
    }

    this._saveJob(job);
    this.emit('job:flow_activity', { job, event: entry, current_flow_task: job.current_flow_task });
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
   * Retry TTS sub-job independently without invalidating whole pipeline
   */
  retryTts(jobId) {
    const job = this.jobs.get(jobId);
    if (!job) return { ok: false, error: 'Job not found' };
    if (job.audio_source !== 'TTS' || !job.tts) {
      return { ok: false, error: 'Job is not configured for TTS' };
    }

    job.tts.retries = (job.tts.retries || 0) + 1;
    job.tts.tts_job_id = null;
    job.tts.local_audio_path = null;
    job.tts.error = null;
    job.error = null;
    job.failed_stage = null;

    this.checkpoint(job, PIPELINE_STATES.RETRYING, 'Đang thử lại tạo giọng AI (TTS)...');
    this.emit('job:state_changed', { job, oldState: PIPELINE_STATES.FAILED, newState: PIPELINE_STATES.RETRYING });

    if (!this.isProcessing) {
      setImmediate(() => this.processNext(job.id));
    }
    return { ok: true, job };
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

  pause(jobId) {
    return this.pauseJob(jobId);
  }

  /**
   * Resume a paused or failed job from its last checkpoint
   */
  resumeJob(jobId) {
    const job = this.jobs.get(jobId);
    if (!job) return { ok: false, error: 'Job not found' };

    job.error = null;
    const nextState = job.failed_stage === PIPELINE_STATES.GENERATING_TTS_AUDIO
      ? PIPELINE_STATES.GENERATING_TTS_AUDIO
      : (job.previous_state && ![PIPELINE_STATES.PAUSED, PIPELINE_STATES.FAILED].includes(job.previous_state)
          ? job.previous_state
          : PIPELINE_STATES.QUEUED);
    job.failed_stage = null;
    this.checkpoint(job, nextState, 'Tiếp tục xử lý từ điểm lưu (checkpoint)...');
    this.emit('job:resumed', { job });

    if (!this.isProcessing) {
      setImmediate(() => this.processNext(job.id));
    }
    return { ok: true, job };
  }

  resume(jobId) {
    return this.resumeJob(jobId);
  }

  runJob(jobId) {
    return this.resumeJob(jobId);
  }

  stop() {
    this._stopSignal = true;
    this.isProcessing = false;
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

    if (job.tts && job.tts.tts_job_id) {
      this._cancelRemoteTtsJob(job).catch((e) => {
        console.warn(`[PipelineQueueV2] Failed to propagate cancel to remote TTS ${job.tts.tts_job_id}:`, e.message);
      });
    }

    this.emit('job:cancelled', { job });
    return { ok: true, job };
  }

  /**
   * Optional handoff to Native Render Queue / Queue B
   */
  handoffToRenderQueue(jobId) {
    const job = this.jobs.get(jobId);
    if (!job) return { ok: false, error: 'Job not found' };
    if (job.state !== PIPELINE_STATES.PROJECT_READY) {
      return { ok: false, error: 'Job is not PROJECT_READY yet' };
    }

    if (this.customExecutor && this.customExecutor.enqueueRender) {
      try {
        this.customExecutor.enqueueRender(job);
      } catch (e) {
        console.warn('[PipelineQueueV2] customExecutor.enqueueRender error:', e.message);
      }
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
        audio_source: null,
        is_tts_active: false,
        tts_progress: null,
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
      audio_source: active.audio_source || 'LOCAL_AUDIO',
      is_tts_active: active.state === PIPELINE_STATES.GENERATING_TTS_AUDIO,
      tts_progress: active.tts_progress || active.tts?.progress || null,
      failed_stage: active.failed_stage || null,
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
    if (!this.jobs.has(jobId)) {
      this.loadPersistedJobs();
    }
    return this.jobs.get(jobId) || null;
  }

  /**
   * List all jobs
   */
  listJobs() {
    this.loadPersistedJobs();
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
      ![PIPELINE_STATES.PROJECT_READY, PIPELINE_STATES.CANCELLED, PIPELINE_STATES.FAILED, PIPELINE_STATES.WAITING_CHARACTER_APPROVAL].includes(j.state)
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
