/**
 * apps/capcut-v2/desktop/src/main/bundle_engine.js
 * 2TOOLNE AUTOEDIT V2 — INPUT BUNDLE ENGINE (PHASE 2)
 *
 * Core Product Contracts:
 *   1 Input Bundle = 1 Video = 1 Pipeline Job = 1 CapCut Project.
 *   Scene ID must be preserved end-to-end (001, 002, ..., 999).
 *   Canonical Naming Scheme: 001-<slug>.png / 001-<slug>.mp4
 *
 * Provides:
 *   - Local & Cloud bundle manifest validation (2toolne.json, prompts.json, characters.json, script.txt, tts.json)
 *   - Scene canonical ID normalization (zero-padded 3 digits)
 *   - Asset pairing and auto-map preview
 *   - Missing asset detection & validation reporting
 */

const fs = require('fs');
const path = require('path');

const CANONICAL_ASSET_REGEX = /^(\d{1,4})(?:-([a-z0-9_\-\.]+))?\.(png|jpg|jpeg|webp|mp4|mov|m4v|mp3|wav|m4a)$/i;

class BundleEngine {
  /**
   * Format scene number to canonical zero-padded 3 digits (e.g. 1 -> "001", 42 -> "042")
   */
  static formatSceneId(id) {
    const num = parseInt(id, 10);
    if (isNaN(num) || num < 0) return '001';
    return String(num).padStart(3, '0');
  }

  /**
   * Normalize slug to lowercase alphanumeric with hyphens
   */
  static normalizeSlug(slug, fallback = 'scene') {
    if (!slug) return fallback;
    const clean = String(slug)
      .toLowerCase()
      .trim()
      .replace(/[^a-z0-9\-]+/g, '-')
      .replace(/-+/g, '-')
      .replace(/^-+|-+$/g, '');
    return clean || fallback;
  }

  /**
   * Build canonical filename: <sceneId>-<slug>.<ext>
   */
  static buildCanonicalFilename(sceneId, slug, ext) {
    const paddedId = this.formatSceneId(sceneId);
    const cleanSlug = this.normalizeSlug(slug, `scene-${paddedId}`);
    const cleanExt = ext.startsWith('.') ? ext.slice(1).toLowerCase() : ext.toLowerCase();
    return `${paddedId}-${cleanSlug}.${cleanExt}`;
  }

  /**
   * Parse an asset filename to extract scene ID, slug, and file type
   */
  static parseAssetFilename(filename) {
    const match = filename.match(CANONICAL_ASSET_REGEX);
    if (!match) return null;

    const rawId = parseInt(match[1], 10);
    const sceneId = this.formatSceneId(rawId);
    const rawSlug = match[2] || '';
    const ext = match[3].toLowerCase();

    let type = 'other';
    if (['png', 'jpg', 'jpeg', 'webp'].includes(ext)) type = 'image';
    else if (['mp4', 'mov', 'm4v'].includes(ext)) type = 'video';
    else if (['mp3', 'wav', 'm4a'].includes(ext)) type = 'audio';

    return {
      scene_number: rawId,
      scene_id: sceneId,
      slug: this.normalizeSlug(rawSlug, `scene-${sceneId}`),
      ext,
      type,
      filename,
      is_canonical: filename.startsWith(`${sceneId}-`),
    };
  }

  /**
   * Validate and parse a local directory bundle
   */
  static validateLocalBundle(bundleDir) {
    if (!fs.existsSync(bundleDir) || !fs.statSync(bundleDir).isDirectory()) {
      return { ok: false, error: `Directory not found: ${bundleDir}`, code: 'DIR_NOT_FOUND' };
    }

    const files = fs.readdirSync(bundleDir);
    const fileMap = {};
    for (const f of files) {
      fileMap[f.toLowerCase()] = f;
    }

    // 1. Read manifest (2toolne.json) if present
    let manifest = null;
    if (fileMap['2toolne.json']) {
      try {
        const raw = fs.readFileSync(path.join(bundleDir, fileMap['2toolne.json']), 'utf8');
        manifest = JSON.parse(raw);
      } catch (e) {
        return { ok: false, error: `Invalid JSON in 2toolne.json: ${e.message}`, code: 'INVALID_MANIFEST' };
      }
    }

    // 2. Read prompts.json
    const promptsFile = manifest?.prompts || fileMap['prompts.json'];
    let promptsData = null;
    if (promptsFile && fs.existsSync(path.join(bundleDir, promptsFile))) {
      try {
        const raw = fs.readFileSync(path.join(bundleDir, promptsFile), 'utf8');
        promptsData = JSON.parse(raw);
      } catch (e) {
        return { ok: false, error: `Invalid JSON in prompts.json: ${e.message}`, code: 'INVALID_PROMPTS' };
      }
    }

    // 3. Read characters.json
    const charFile = manifest?.characters || fileMap['characters.json'];
    let charactersData = null;
    if (charFile && fs.existsSync(path.join(bundleDir, charFile))) {
      try {
        const raw = fs.readFileSync(path.join(bundleDir, charFile), 'utf8');
        charactersData = JSON.parse(raw);
      } catch (e) {
        return { ok: false, error: `Invalid JSON in characters.json: ${e.message}`, code: 'INVALID_CHARACTERS' };
      }
    }

    // 4. Read script.txt
    const scriptFile = manifest?.script || fileMap['script.txt'];
    let scriptContent = null;
    if (scriptFile && fs.existsSync(path.join(bundleDir, scriptFile))) {
      scriptContent = fs.readFileSync(path.join(bundleDir, scriptFile), 'utf8');
    }

    // 5. Read TTS
    const ttsFile = manifest?.tts || fileMap['tts.json'] || fileMap['tts.txt'];
    let ttsData = null;
    if (ttsFile && fs.existsSync(path.join(bundleDir, ttsFile))) {
      const raw = fs.readFileSync(path.join(bundleDir, ttsFile), 'utf8');
      try {
        ttsData = JSON.parse(raw);
      } catch (e) {
        ttsData = { raw_text: raw };
      }
    }

    // 5.5 Detect audio configuration and files
    let bundleAudioFile = null;
    let bundleAudioPath = null;
    const commonAudioNames = ['audio.mp3', 'audio.wav', 'audio.m4a', 'narration.mp3', 'narration.wav', 'narration.m4a', 'voice.mp3', 'voice.wav'];
    const declaredAudio = manifest?.audio_file || (typeof manifest?.audio === 'string' ? manifest.audio : manifest?.audio?.file);
    if (declaredAudio && fs.existsSync(path.join(bundleDir, declaredAudio))) {
      bundleAudioFile = declaredAudio;
      bundleAudioPath = path.join(bundleDir, declaredAudio);
    } else {
      for (const name of commonAudioNames) {
        if (fileMap[name] && fs.existsSync(path.join(bundleDir, fileMap[name]))) {
          bundleAudioFile = fileMap[name];
          bundleAudioPath = path.join(bundleDir, fileMap[name]);
          break;
        }
      }
    }

    // Resolve audio_source: LOCAL_AUDIO | CLOUD_AUDIO | TTS
    let resolvedAudioSource = 'LOCAL_AUDIO';
    let audioConfig = null;
    if (manifest?.audio?.source) {
      resolvedAudioSource = String(manifest.audio.source).toUpperCase();
      audioConfig = manifest.audio;
    } else if (manifest?.audio_source) {
      resolvedAudioSource = String(manifest.audio_source).toUpperCase();
    } else if (manifest?.cloud_audio_file_id || manifest?.audio?.cloud_file_id) {
      resolvedAudioSource = 'CLOUD_AUDIO';
      audioConfig = manifest.audio || { cloud_file_id: manifest.cloud_audio_file_id };
    } else if (bundleAudioFile) {
      resolvedAudioSource = 'LOCAL_AUDIO';
    } else if (ttsData !== null || manifest?.tts || manifest?.audio?.tts) {
      resolvedAudioSource = 'TTS';
      audioConfig = manifest?.audio?.tts || ttsData;
    }

    // Normalize scenes from promptsData
    const rawScenes = promptsData?.scenes || (Array.isArray(promptsData) ? promptsData : []);
    const normalizedScenes = [];

    for (let i = 0; i < rawScenes.length; i++) {
      const s = rawScenes[i];
      const rawId = s.id !== undefined ? s.id : (i + 1);
      const sceneId = this.formatSceneId(rawId);
      const slug = this.normalizeSlug(s.slug || s.title || `scene-${sceneId}`);
      const prompt = (s.prompt || s.text || '').trim();

      normalizedScenes.push({
        index: i,
        id: parseInt(sceneId, 10),
        scene_id: sceneId,
        slug,
        prompt,
        image_prompt: (s.image_prompt || prompt).trim(),
        video_prompt: (s.video_prompt || prompt).trim(),
        image_aspect_ratio: s.image_aspect_ratio || s.aspect_ratio || null,
        video_aspect_ratio: s.video_aspect_ratio || s.aspect_ratio || null,
        negative_prompt: s.negative_prompt || '',
        character_refs: Array.isArray(s.character_refs) ? s.character_refs : (s.characters || []),
        camera_motion: s.camera_motion || s.motion || 'pan_left_right',
        duration_s: s.duration_s || s.duration || 5.0,
        transition: s.transition || 'none',
        expected_image: `${sceneId}-${slug}.png`,
        expected_video: `${sceneId}-${slug}.mp4`,
      });
    }

    // Normalize characters ensuring char.prompt is preserved
    const rawChars = charactersData?.characters || (Array.isArray(charactersData) ? charactersData : []);
    const normalizedCharacters = rawChars.map((c, idx) => {
      const refImg = c.ref_image || c.reference_image_path || null;
      return {
        id: c.id || `char_${idx + 1}`,
        name: c.name || `Character ${idx + 1}`,
        description: c.description || '',
        prompt: (c.prompt !== undefined && c.prompt !== null) ? String(c.prompt).trim() : '',
        ref_image: refImg,
        reference_image_path: c.reference_image_path || (refImg ? path.join(bundleDir, refImg) : null),
      };
    });

    // 6. Scan existing media assets in directory
    const assetScan = this.scanDirectoryAssets(bundleDir, normalizedScenes);

    const hasManifest = manifest !== null;
    const projectName = manifest?.project_name || promptsData?.project_name || path.basename(bundleDir);
    const aspectRatio = manifest?.aspect_ratio || promptsData?.aspect_ratio || '9:16';

    return {
      ok: true,
      bundle_dir: path.resolve(bundleDir),
      project_name: projectName,
      bundle_name: projectName,
      aspect_ratio: aspectRatio,
      schema_version: manifest?.schema_version || '2.0.0',
      manifest: manifest || {},
      has_manifest: hasManifest,
      has_prompts: promptsData !== null,
      has_characters: charactersData !== null,
      has_script: scriptContent !== null,
      has_tts: ttsData !== null,
      audio_source: resolvedAudioSource,
      audio_file: bundleAudioFile,
      audio_path: bundleAudioPath,
      audio_config: audioConfig,
      scenes_count: normalizedScenes.length,
      scenes: normalizedScenes,
      characters: normalizedCharacters,
      script: scriptContent,
      tts: ttsData,
      assets: assetScan,
      validation_summary: {
        total_scenes: normalizedScenes.length,
        images_ready: assetScan.images_ready_count,
        videos_ready: assetScan.videos_ready_count,
        missing_images: assetScan.missing_images,
        missing_videos: assetScan.missing_videos,
        is_ready_for_flow: normalizedScenes.length > 0,
        is_ready_for_video_render: assetScan.images_ready_count === normalizedScenes.length && normalizedScenes.length > 0,
        is_ready_for_capcut: assetScan.videos_ready_count === normalizedScenes.length && normalizedScenes.length > 0,
      },
    };
  }

  /**
   * Scan directory files and map them to defined scenes
   */
  static scanDirectoryAssets(bundleDir, scenes) {
    const allFiles = fs.readdirSync(bundleDir);
    const parsedAssets = [];

    for (const f of allFiles) {
      const parsed = this.parseAssetFilename(f);
      if (parsed) {
        parsed.full_path = path.join(bundleDir, f);
        parsedAssets.push(parsed);
      }
    }

    const sceneMap = {};
    for (const sc of scenes) {
      sceneMap[sc.scene_id] = {
        scene: sc,
        image: null,
        video: null,
        audio: null,
      };
    }

    // Match parsed assets to scenes
    for (const asset of parsedAssets) {
      if (sceneMap[asset.scene_id]) {
        if (asset.type === 'image' && !sceneMap[asset.scene_id].image) {
          sceneMap[asset.scene_id].image = asset;
        } else if (asset.type === 'video' && !sceneMap[asset.scene_id].video) {
          sceneMap[asset.scene_id].video = asset;
        } else if (asset.type === 'audio' && !sceneMap[asset.scene_id].audio) {
          sceneMap[asset.scene_id].audio = asset;
        }
      }
    }

    const missingImages = [];
    const missingVideos = [];
    let imagesReadyCount = 0;
    let videosReadyCount = 0;

    const mappedScenes = scenes.map((sc) => {
      const match = sceneMap[sc.scene_id];
      const hasImg = !!match.image;
      const hasVid = !!match.video;

      if (hasImg) imagesReadyCount++;
      else missingImages.push(sc.scene_id);

      if (hasVid) videosReadyCount++;
      else missingVideos.push(sc.scene_id);

      return {
        scene_id: sc.scene_id,
        slug: sc.slug,
        prompt: sc.prompt,
        image_status: hasImg ? 'READY' : 'MISSING',
        image_file: match.image ? match.image.filename : null,
        image_path: match.image ? match.image.full_path : null,
        video_status: hasVid ? 'READY' : 'PENDING',
        video_file: match.video ? match.video.filename : null,
        video_path: match.video ? match.video.full_path : null,
      };
    });

    return {
      all_found_assets: parsedAssets,
      mapped_scenes: mappedScenes,
      images_ready_count: imagesReadyCount,
      videos_ready_count: videosReadyCount,
      missing_images: missingImages,
      missing_videos: missingVideos,
    };
  }

  /**
   * Helper to initialize a new canonical bundle template on disk
   */
  static createBundleTemplate(targetDir, { projectName, scenes = [], characters = [], aspectRatio = '9:16', script = '' } = {}) {
    fs.mkdirSync(targetDir, { recursive: true });

    const cleanScenes = scenes.map((s, idx) => {
      const id = s.id || (idx + 1);
      const sceneId = this.formatSceneId(id);
      const slug = this.normalizeSlug(s.slug || `scene-${sceneId}`);
      return {
        id: parseInt(sceneId, 10),
        slug,
        prompt: s.prompt || `Scene ${sceneId} prompt`,
        negative_prompt: s.negative_prompt || '',
        character_refs: s.character_refs || [],
        camera_motion: s.camera_motion || 'pan_left_right',
        duration_s: s.duration_s || 5.0,
      };
    });

    const manifest = {
      schema_version: '2.0.0',
      project_name: projectName || path.basename(targetDir),
      aspect_ratio: aspectRatio,
      scenes_count: cleanScenes.length,
      prompts: 'prompts.json',
      characters: 'characters.json',
      script: 'script.txt',
    };

    const promptsData = {
      project_name: manifest.project_name,
      aspect_ratio: aspectRatio,
      scenes: cleanScenes,
    };

    const charactersData = {
      characters: characters.map((c, i) => ({
        id: c.id || `char_${String(i + 1).padStart(2, '0')}`,
        name: c.name || `Character ${i + 1}`,
        description: c.description || '',
        reference_images: c.reference_images || [],
      })),
    };

    fs.writeFileSync(path.join(targetDir, '2toolne.json'), JSON.stringify(manifest, null, 2), 'utf8');
    fs.writeFileSync(path.join(targetDir, 'prompts.json'), JSON.stringify(promptsData, null, 2), 'utf8');
    fs.writeFileSync(path.join(targetDir, 'characters.json'), JSON.stringify(charactersData, null, 2), 'utf8');
    if (script) {
      fs.writeFileSync(path.join(targetDir, 'script.txt'), script, 'utf8');
    }

    return this.validateLocalBundle(targetDir);
  }
}

module.exports = {
  BundleEngine,
  CANONICAL_ASSET_REGEX,
};
