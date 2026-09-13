/**
 * apps/capcut-v2/desktop/tests/test_input_bundle_engine.js
 * Comprehensive Automated Test Suite for PHASE 2: Input Bundle Engine.
 * Covers tests P2-01 through P2-15 per Master Implementation Directive.
 */

const assert = require('assert');
const fs = require('fs');
const path = require('path');
const os = require('os');
const { BundleEngine } = require('../src/main/bundle_engine');

function runPhase2TestSuite() {
  console.log('================================================================================');
  console.log('2TOOLNE AUTOEDIT V2 — PHASE 2: INPUT BUNDLE ENGINE');
  console.log('TEST SUITE: P2-01 THROUGH P2-15');
  console.log('================================================================================\n');

  const tempDir = fs.mkdtempSync(path.join(os.tmpdir(), '2toolne_p2_test_'));

  try {
    // -------------------------------------------------------------------------
    // P2-01: Canonical Scene ID Normalization (e.g. 1 -> "001", 42 -> "042")
    // -------------------------------------------------------------------------
    console.log('--- [P2-01] Canonical Scene ID Normalization ---');
    assert.strictEqual(BundleEngine.formatSceneId(1), '001');
    assert.strictEqual(BundleEngine.formatSceneId('1'), '001');
    assert.strictEqual(BundleEngine.formatSceneId(42), '042');
    assert.strictEqual(BundleEngine.formatSceneId('042'), '042');
    assert.strictEqual(BundleEngine.formatSceneId(999), '999');
    assert.strictEqual(BundleEngine.formatSceneId('invalid'), '001');
    console.log('✓ P2-01 PASS: Canonical scene IDs normalized to zero-padded 3 digits');

    // -------------------------------------------------------------------------
    // P2-02: Slug Sanitization
    // -------------------------------------------------------------------------
    console.log('--- [P2-02] Slug Sanitization ---');
    assert.strictEqual(BundleEngine.normalizeSlug('Tokyo Shibuya Crossing!'), 'tokyo-shibuya-crossing');
    assert.strictEqual(BundleEngine.normalizeSlug('HERO__INTRO--01'), 'hero-intro-01');
    assert.strictEqual(BundleEngine.normalizeSlug('   Kyoto Bamboo   '), 'kyoto-bamboo');
    assert.strictEqual(BundleEngine.normalizeSlug('!@#$%^&*()'), 'scene');
    assert.strictEqual(BundleEngine.normalizeSlug(''), 'scene');
    console.log('✓ P2-02 PASS: Slugs safely converted to lowercase alphanumeric hyphens');

    // -------------------------------------------------------------------------
    // P2-03: Canonical Filename Building
    // -------------------------------------------------------------------------
    console.log('--- [P2-03] Canonical Filename Building ---');
    assert.strictEqual(BundleEngine.buildCanonicalFilename(1, 'shibuya', 'png'), '001-shibuya.png');
    assert.strictEqual(BundleEngine.buildCanonicalFilename(42, 'temple_gate', '.MP4'), '042-temple-gate.mp4');
    assert.strictEqual(BundleEngine.buildCanonicalFilename('003', null, 'PNG'), '003-scene-003.png');
    console.log('✓ P2-03 PASS: Filenames formatted as 001-<slug>.ext');

    // -------------------------------------------------------------------------
    // P2-04: Asset Filename Parsing (Canonical)
    // -------------------------------------------------------------------------
    console.log('--- [P2-04] Asset Filename Parsing (Canonical) ---');
    const p1 = BundleEngine.parseAssetFilename('001-tokyo-crossing.png');
    assert.strictEqual(p1.scene_number, 1);
    assert.strictEqual(p1.scene_id, '001');
    assert.strictEqual(p1.slug, 'tokyo-crossing');
    assert.strictEqual(p1.ext, 'png');
    assert.strictEqual(p1.type, 'image');
    assert.strictEqual(p1.is_canonical, true);

    const p2 = BundleEngine.parseAssetFilename('042-cinematic-sunset.mp4');
    assert.strictEqual(p2.scene_number, 42);
    assert.strictEqual(p2.scene_id, '042');
    assert.strictEqual(p2.type, 'video');
    assert.strictEqual(p2.is_canonical, true);
    console.log('✓ P2-04 PASS: Canonical asset filenames parsed accurately');

    // -------------------------------------------------------------------------
    // P2-05: Non-Canonical Filename Tolerance
    // -------------------------------------------------------------------------
    console.log('--- [P2-05] Non-Canonical Filename Tolerance ---');
    const p3 = BundleEngine.parseAssetFilename('1-hero.jpg');
    assert.strictEqual(p3.scene_number, 1);
    assert.strictEqual(p3.scene_id, '001');
    assert.strictEqual(p3.type, 'image');
    assert.strictEqual(p3.is_canonical, false);

    const p4 = BundleEngine.parseAssetFilename('7.webp');
    assert.strictEqual(p4.scene_number, 7);
    assert.strictEqual(p4.scene_id, '007');
    assert.strictEqual(p4.type, 'image');
    console.log('✓ P2-05 PASS: Tolerant parsing normalizes non-padded numbers to 001, 007');

    // -------------------------------------------------------------------------
    // P2-06: Template Generator (createBundleTemplate)
    // -------------------------------------------------------------------------
    console.log('--- [P2-06] Template Generator (createBundleTemplate) ---');
    const bndlDir = path.join(tempDir, 'sample_bundle');
    const initRes = BundleEngine.createBundleTemplate(bndlDir, {
      projectName: 'Tokyo Stories',
      aspectRatio: '16:9',
      scenes: [
        { id: 1, slug: 'shibuya', prompt: 'Busy Shibuya neon' },
        { id: 2, slug: 'shinjuku', prompt: 'Shinjuku golden gai bar' },
        { id: 3, slug: 'akihabara', prompt: 'Akihabara electronics' },
      ],
      characters: [
        { id: 'char_01', name: 'Kenji', description: 'Cyberpunk photographer' },
      ],
      script: 'Scene 1: Tokyo begins\nScene 2: Into the night\nScene 3: Electric dreams',
    });
    assert.strictEqual(initRes.ok, true);
    assert.strictEqual(initRes.project_name, 'Tokyo Stories');
    assert.strictEqual(initRes.scenes_count, 3);
    assert.strictEqual(initRes.aspect_ratio, '16:9');
    assert(fs.existsSync(path.join(bndlDir, '2toolne.json')));
    assert(fs.existsSync(path.join(bndlDir, 'prompts.json')));
    assert(fs.existsSync(path.join(bndlDir, 'characters.json')));
    assert(fs.existsSync(path.join(bndlDir, 'script.txt')));
    console.log('✓ P2-06 PASS: Canonical template files written and verified on disk');

    // -------------------------------------------------------------------------
    // P2-07: prompts.json Scene Plan & Ordering Preservation
    // -------------------------------------------------------------------------
    console.log('--- [P2-07] prompts.json Scene Plan & Ordering Preservation ---');
    const scenes = initRes.scenes;
    assert.strictEqual(scenes[0].scene_id, '001');
    assert.strictEqual(scenes[0].slug, 'shibuya');
    assert.strictEqual(scenes[1].scene_id, '002');
    assert.strictEqual(scenes[1].slug, 'shinjuku');
    assert.strictEqual(scenes[2].scene_id, '003');
    assert.strictEqual(scenes[2].slug, 'akihabara');
    console.log('✓ P2-07 PASS: Strict scene ordering 001 -> 002 -> 003 preserved');

    // -------------------------------------------------------------------------
    // P2-08: characters.json Registry Parsing
    // -------------------------------------------------------------------------
    console.log('--- [P2-08] characters.json Registry Parsing ---');
    assert.strictEqual(initRes.characters.length, 1);
    assert.strictEqual(initRes.characters[0].id, 'char_01');
    assert.strictEqual(initRes.characters[0].name, 'Kenji');
    assert.strictEqual(initRes.characters[0].description, 'Cyberpunk photographer');
    console.log('✓ P2-08 PASS: Characters registry parsed accurately');

    // -------------------------------------------------------------------------
    // P2-09: script.txt Detection & Content
    // -------------------------------------------------------------------------
    console.log('--- [P2-09] script.txt Detection & Content ---');
    assert(initRes.has_script, 'Must detect script.txt');
    assert(initRes.script.includes('Tokyo begins'));
    console.log('✓ P2-09 PASS: script.txt content successfully read');

    // -------------------------------------------------------------------------
    // P2-10: TTS Data Detection
    // -------------------------------------------------------------------------
    console.log('--- [P2-10] TTS Data Detection ---');
    fs.writeFileSync(path.join(bndlDir, 'tts.json'), JSON.stringify({ voice: 'vi-VN-Standard-A', words: [] }));
    const ttsParsed = BundleEngine.validateLocalBundle(bndlDir);
    assert(ttsParsed.has_tts, 'Must detect tts.json');
    assert.strictEqual(ttsParsed.tts.voice, 'vi-VN-Standard-A');
    console.log('✓ P2-10 PASS: tts.json detected and parsed');

    // -------------------------------------------------------------------------
    // P2-11: Auto-Map Preview: Missing Images State
    // -------------------------------------------------------------------------
    console.log('--- [P2-11] Auto-Map Preview: Missing Images State ---');
    const emptyScan = ttsParsed.validation_summary;
    assert.strictEqual(emptyScan.images_ready, 0);
    assert.strictEqual(emptyScan.videos_ready, 0);
    assert.strictEqual(emptyScan.missing_images.length, 3);
    assert.deepStrictEqual(emptyScan.missing_images, ['001', '002', '003']);
    assert.strictEqual(emptyScan.is_ready_for_video_render, false);
    assert.strictEqual(emptyScan.is_ready_for_capcut, false);
    console.log('✓ P2-11 PASS: Missing images accurately mapped to scenes [001, 002, 003]');

    // -------------------------------------------------------------------------
    // P2-12: Auto-Map Preview: Partial Images State
    // -------------------------------------------------------------------------
    console.log('--- [P2-12] Auto-Map Preview: Partial Images State ---');
    fs.writeFileSync(path.join(bndlDir, '001-shibuya.png'), 'fake-image-001');
    fs.writeFileSync(path.join(bndlDir, '002-shinjuku.png'), 'fake-image-002');
    const partialParsed = BundleEngine.validateLocalBundle(bndlDir);
    const partialSummary = partialParsed.validation_summary;
    assert.strictEqual(partialSummary.images_ready, 2);
    assert.deepStrictEqual(partialSummary.missing_images, ['003']);
    assert.strictEqual(partialSummary.is_ready_for_video_render, false);
    console.log('✓ P2-12 PASS: Partial image upload correctly identifies remaining missing scene [003]');

    // -------------------------------------------------------------------------
    // P2-13: Auto-Map Preview: All Images Ready -> Ready for Flow Video Gen
    // -------------------------------------------------------------------------
    console.log('--- [P2-13] Auto-Map Preview: All Images Ready ---');
    fs.writeFileSync(path.join(bndlDir, '003-akihabara.png'), 'fake-image-003');
    const imagesReadyParsed = BundleEngine.validateLocalBundle(bndlDir);
    const imgSummary = imagesReadyParsed.validation_summary;
    assert.strictEqual(imgSummary.images_ready, 3);
    assert.strictEqual(imgSummary.missing_images.length, 0);
    assert.strictEqual(imgSummary.is_ready_for_video_render, true);
    assert.strictEqual(imgSummary.is_ready_for_capcut, false);
    console.log('✓ P2-13 PASS: All images present -> is_ready_for_video_render = true');

    // -------------------------------------------------------------------------
    // P2-14: Auto-Map Preview: All Videos Ready -> Ready for CapCut Draft
    // -------------------------------------------------------------------------
    console.log('--- [P2-14] Auto-Map Preview: All Videos Ready ---');
    fs.writeFileSync(path.join(bndlDir, '001-shibuya.mp4'), 'fake-video-001');
    fs.writeFileSync(path.join(bndlDir, '002-shinjuku.mp4'), 'fake-video-002');
    fs.writeFileSync(path.join(bndlDir, '003-akihabara.mp4'), 'fake-video-003');
    const fullParsed = BundleEngine.validateLocalBundle(bndlDir);
    const fullSummary = fullParsed.validation_summary;
    assert.strictEqual(fullSummary.videos_ready, 3);
    assert.strictEqual(fullSummary.missing_videos.length, 0);
    assert.strictEqual(fullSummary.is_ready_for_capcut, true);

    // Verify mapped scenes details
    const mapped = fullParsed.assets.mapped_scenes;
    assert.strictEqual(mapped[0].image_status, 'READY');
    assert.strictEqual(mapped[0].video_status, 'READY');
    assert.strictEqual(mapped[0].image_file, '001-shibuya.png');
    assert.strictEqual(mapped[0].video_file, '001-shibuya.mp4');
    console.log('✓ P2-14 PASS: Complete bundle mapped -> is_ready_for_capcut = true');

    // -------------------------------------------------------------------------
    // P2-15: Invalid JSON Error Handling
    // -------------------------------------------------------------------------
    console.log('--- [P2-15] Invalid JSON Error Handling ---');
    const corruptDir = path.join(tempDir, 'corrupt_bundle');
    fs.mkdirSync(corruptDir);
    fs.writeFileSync(path.join(corruptDir, '2toolne.json'), '{ corrupt json syntax');
    const corruptRes = BundleEngine.validateLocalBundle(corruptDir);
    assert.strictEqual(corruptRes.ok, false);
    assert.strictEqual(corruptRes.code, 'INVALID_MANIFEST');
    console.log('✓ P2-15 PASS: Malformed manifest cleanly rejected with INVALID_MANIFEST');

    console.log('\n================================================================================');
    console.log('ALL 15 TESTS IN PHASE 2 (P2-01 THROUGH P2-15) PASSED (100% PASS)');
    console.log('================================================================================\n');
  } finally {
    try {
      fs.rmSync(tempDir, { recursive: true, force: true });
    } catch (e) {}
  }
}

if (require.main === module) {
  try {
    runPhase2TestSuite();
    process.exit(0);
  } catch (err) {
    console.error('Phase 2 Test Suite Failed:', err);
    process.exit(1);
  }
}

module.exports = { runPhase2TestSuite };
