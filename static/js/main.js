/**
 * main.js — Slideshow Builder + Independent Multi-Track Studio + Live Preview Monitor
 * Features:
 * - Decoupled Subtitle Track (Acoustic Audio Sync via Faster-Whisper) & Image Track
 * - WaveSurfer.js Gold Standard Audio Waveform
 * - Live Preview Monitor (Real-time synchronized image & subtitle overlay)
 * - Timeline Resizing, Drag & Drop reordering, Keyboard Shortcuts (Space, X, Arrow keys)
 * - Direct JSON Script Import & Alignment
 */

'use strict';

// ─── State ───────────────────────────────────────────────────────────────────
const state = {
  images:       [],     // raw File[]
  audio:        null,   // uploaded File | null
  ttsJobId:     null,   // completed TTS job ID | null
  ttsAudioUrl:  null,   // generated audio URL
  activeTab:    'tts',  // 'tts' | 'upload'
  jobId:        null,   // current render job
  ttsEvt:       null,   // TTS SSE source
  renderEvt:    null,   // render SSE source
  ttsRefAudio:  null,   // reference audio File for cloning

  // ── Decoupled Timeline Data ──
  subtitles:    [],     // Array of { id, startTime, endTime, text } (Acoustic Audio Sync)
  imagesData:   [],     // Array of { index, sceneId, imageFile, imageUrl, startTime, endTime, duration }
  currentTime:  0.0,    // Current playhead in seconds
  isPlaying:    false,  // Is timeline playing
  zoomPxPerSec: 60,     // Pixels per second on timeline
  selectedType: 'image',// 'image' | 'subtitle'
  selectedIdx:  0,      // Selected index for inspector
  audioEl:      new Audio(), // Fallback audio playback element
  playTimer:    null,
  isDraggingResizer: false,
  draggedSceneIdx: null,
  markIn:       null,   // In-Point selection time in seconds
  markOut:      null,   // Out-Point selection time in seconds
  isDraggingInHandle: false,
  isDraggingOutHandle: false,
};

// ─── DOM refs ─────────────────────────────────────────────────────────────────
const $ = id => document.getElementById(id);
const $$ = sel => document.querySelectorAll(sel);

// Studio Navigation
const navTabs         = $$('.nav-tab');
const viewSetup       = $('view-setup');
const viewTimeline    = $('view-timeline');
const navTabTimeline  = $('nav-tab-timeline');
const btnJumpTimeline = $('btn-jump-timeline');

// Images
const imgInput    = $('img-input');
const imgDrop     = $('img-drop');
const imgList     = $('img-list');
const imgCount    = $('img-count');
const clearBtn    = $('clear-images');

// Upload audio
const audioInput  = $('audio-input');
const audioDrop   = $('audio-drop');
const audioInfo   = $('audio-info');
const audioName   = $('audio-name');
const audioRemove = $('audio-remove');

// Audio source tabs
const tabToggleBtns = $$('#audio-tab-toggle .tab-btn');
const tabUpload   = $('tab-upload');
const tabTts      = $('tab-tts');

// TTS form
const ttsText     = $('tts-text');
const ttsStyle    = $('tts-style');
const ttsRefDrop  = $('tts-ref-drop');
const ttsRefInput = $('tts-ref-input');
const ttsRefInfo  = $('tts-ref-info');
const ttsRefName  = $('tts-ref-name');
const ttsRefRemove= $('tts-ref-remove');
const ttsGenBtn   = $('tts-gen-btn');
const ttsProgWrap = $('tts-prog-wrap');
const ttsProgFill = $('tts-prog-fill');
const ttsProgPct  = $('tts-prog-pct');
const ttsMsg      = $('tts-msg');
const ttsPreview  = $('tts-preview');
const ttsPlayer   = $('tts-audio-player');
const ttsJobIdHid = $('tts-job-id');
const imgDursHid  = $('image-durations-json');

// Subtitles Studio
const chkSubtitlesMain   = $('chk-subtitles-main');
const subScriptInput     = $('sub-script-input');
const btnParseScript     = $('btn-parse-script');
const subFileInput       = $('sub-file-input');
const selSubLang         = $('sel-sub-lang');
const customSrtText      = $('custom-srt-text');
const btnClearSub        = $('btn-clear-sub');

// Live Preview Monitor DOM
const monitorImg         = $('monitor-img');
const monitorPlaceholder = $('monitor-placeholder');
const monitorSubtitle    = $('monitor-subtitle');
const btnPrevScene       = $('btn-prev-scene');
const btnPlayPause       = $('btn-play-pause');
const btnNextScene       = $('btn-next-scene');
const mcTimeCurrent      = $('mc-time-current');
const mcTimeTotal        = $('mc-time-total');
const mcSceneIndicator   = $('mc-badge');

// Inspector DOM
const inspectorEmpty     = $('inspector-empty');
const inspectorContent   = $('inspector-content');
const inspSceneId        = $('insp-scene-id');
const inspSubtitleText   = $('insp-subtitle-text');
const inspSceneDur       = $('insp-scene-dur');
const btnDurMinus        = $('btn-dur-minus');
const btnDurPlus         = $('btn-dur-plus');
const inspTimeRange      = $('insp-time-range');

// Timeline DOM
const tlViewport         = $('timeline-viewport');
const tlPlayhead         = $('tl-playhead');
const tlRuler            = $('tl-ruler');
const tlSubTrack         = $('tl-sub-track');
const tlImgTrack         = $('tl-img-track');
const tlTotalBadge       = $('tl-total-badge');
const tlZoomSlider       = $('tl-zoom-slider');
const btnTlResetDurs     = $('btn-tl-reset-durs');
const btnTlDeleteSelected= $('btn-tl-delete-selected');
const tlJsonFileInput    = $('tl-json-file-input');

// Render
const renderBtn   = $('render-btn');
const dlBtn       = $('dl-btn');
const dlSrtBtn    = $('dl-srt-btn');
const progWrap    = $('prog-wrap');
const progFill    = $('prog-fill');
const progPct     = $('prog-pct');
const errBox      = $('err-box');
const chkTransit  = $('chk-transition');
const transitSub  = $('transition-sub');
const inpDuration = $('inp-duration');
const fiImages    = $('fi-images');
const fiAudio     = $('fi-audio');
const fiDur       = $('fi-dur');

let wavesurfer = null;


// ─── Navigation Switcher ──────────────────────────────────────────────────────

navTabs.forEach(tab => {
  tab.addEventListener('click', () => {
    navTabs.forEach(t => t.classList.toggle('active', t === tab));
    const view = tab.dataset.view;
    if (view === 'setup') {
      if (viewSetup) viewSetup.classList.add('active');
      if (viewTimeline) viewTimeline.classList.remove('active');
    } else {
      if (viewSetup) viewSetup.classList.remove('active');
      if (viewTimeline) viewTimeline.classList.add('active');
      rebuildTimelineFromInputs();
      if (state.ttsAudioUrl) {
        setTimeout(() => {
          initWaveSurfer(state.ttsAudioUrl);
        }, 80);
      }
    }
  });
});

if (btnJumpTimeline) {
  btnJumpTimeline.addEventListener('click', () => {
    if (navTabTimeline) navTabTimeline.click();
  });
}


// ─── Keyboard Shortcuts ───────────────────────────────────────────────────────

window.addEventListener('keydown', e => {
  const activeEl = document.activeElement;
  if (activeEl && (activeEl.tagName === 'INPUT' || activeEl.tagName === 'TEXTAREA' || activeEl.tagName === 'SELECT')) {
    return;
  }

  // Space -> Play / Pause preview
  if (e.code === 'Space') {
    e.preventDefault();
    togglePlayPause();
    return;
  }

  // Key 'I' -> Mark In Point at Playhead
  if (e.key === 'i' || e.key === 'I') {
    e.preventDefault();
    if (typeof setMarkIn === 'function') setMarkIn();
    return;
  }

  // Key 'O' -> Mark Out Point at Playhead
  if (e.key === 'o' || e.key === 'O') {
    e.preventDefault();
    if (typeof setMarkOut === 'function') setMarkOut();
    return;
  }

  // Key 'Alt+X' -> Clear In/Out Range
  if (e.altKey && (e.key === 'x' || e.key === 'X')) {
    e.preventDefault();
    if (typeof clearMarkInOut === 'function') clearMarkInOut();
    return;
  }

  // Key 'Delete' or 'Backspace' -> Delete currently selected item
  if (e.key === 'Delete' || e.key === 'Backspace') {
    e.preventDefault();
    deleteSelectedTimelineItem();
    return;
  }

  // Key 'S' or 'Ctrl+B' / 'Cmd+B' -> Split selected clip at playhead
  if (e.key === 's' || e.key === 'S' || ((e.ctrlKey || e.metaKey) && (e.key === 'b' || e.key === 'B'))) {
    e.preventDefault();
    if (typeof splitSelectedClip === 'function') splitSelectedClip();
    return;
  }

  // Left Arrow -> Previous image
  if (e.code === 'ArrowLeft') {
    e.preventDefault();
    if (btnPrevScene) btnPrevScene.click();
    return;
  }

  // Right Arrow -> Next image
  if (e.code === 'ArrowRight') {
    e.preventDefault();
    if (btnNextScene) btnNextScene.click();
    return;
  }
});


// ─── Helpers ─────────────────────────────────────────────────────────────────

function trailingNum(name) {
  const base = name.replace(/\.[^.]+$/, '');
  const nums = base.match(/\d+/g);
  return nums ? parseInt(nums[nums.length - 1], 10) : 0;
}

function sortFiles(files) {
  return [...files].sort((a, b) => {
    const na = trailingNum(a.name), nb = trailingNum(b.name);
    return na !== nb ? na - nb : a.name.localeCompare(b.name);
  });
}

function isImageFile(name) {
  return /\.(jpe?g|png|webp|bmp|tiff?|gif)$/i.test(name);
}

function isAudioFile(name) {
  return /\.(mp3|wav|aac|m4a|ogg|flac)$/i.test(name);
}

function formatSecs(sec) {
  sec = Math.max(0, sec);
  const m = Math.floor(sec / 60);
  const s = Math.floor(sec % 60);
  const ms = Math.floor((sec - Math.floor(sec)) * 100);
  return `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}.${String(ms).padStart(2, '0')}`;
}

function formatSrtTime(sec) {
  sec = Math.max(0, sec);
  const h = Math.floor(sec / 3600);
  const m = Math.floor((sec % 3600) / 60);
  const s = Math.floor(sec % 60);
  const ms = Math.min(999, Math.round((sec - Math.floor(sec)) * 1000));
  return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')},${String(ms).padStart(3, '0')}`;
}


// ─── Images Handling ──────────────────────────────────────────────────────────

function setImages(files) {
  const valid = Array.from(files).filter(f => isImageFile(f.name));
  state.images = sortFiles(valid);
  renderImageList();
  updateFooterInfo();
  syncImagesToTimeline();
}

function addImages(files) {
  const existingNames = new Set(state.images.map(f => f.name));
  const newFiles = Array.from(files).filter(f => isImageFile(f.name) && !existingNames.has(f.name));
  state.images = sortFiles([...state.images, ...newFiles]);
  renderImageList();
  updateFooterInfo();
  syncImagesToTimeline();
}

function removeImage(idx) {
  state.images.splice(idx, 1);
  renderImageList();
  updateFooterInfo();
  syncImagesToTimeline();
}

function renderImageList() {
  imgList.innerHTML = '';
  imgCount.textContent = state.images.length;
  clearBtn.style.display = state.images.length > 0 ? 'inline-block' : 'none';

  state.images.forEach((file, idx) => {
    const item = document.createElement('div');
    item.className = 'img-item';

    const thumb = document.createElement('img');
    thumb.className = 'img-thumb';
    thumb.src = URL.createObjectURL(file);
    thumb.alt = file.name;

    const info = document.createElement('div');
    info.className = 'img-info';

    const name = document.createElement('div');
    name.className = 'img-name';
    name.textContent = file.name;
    name.title = file.name;

    const num = document.createElement('div');
    num.className = 'img-num';
    num.textContent = `#${idx + 1}`;

    info.appendChild(name);
    info.appendChild(num);

    const del = document.createElement('button');
    del.className = 'btn-icon-sm';
    del.type = 'button';
    del.textContent = '✕';
    del.addEventListener('click', e => {
      e.stopPropagation();
      removeImage(idx);
    });

    item.appendChild(thumb);
    item.appendChild(info);
    item.appendChild(del);
    imgList.appendChild(item);
  });
  if (typeof renderDrawerImageList === 'function') renderDrawerImageList();
}

if (clearBtn) clearBtn.addEventListener('click', () => {
  state.images = [];
  renderImageList();
  updateFooterInfo();
  syncImagesToTimeline();
});

if (imgInput) imgInput.addEventListener('change', () => {
  if (imgInput.files.length) addImages(imgInput.files);
  imgInput.value = '';
});

if (imgDrop) {
  imgDrop.addEventListener('click', () => imgInput.click());
  imgDrop.addEventListener('dragover', e => { e.preventDefault(); imgDrop.classList.add('drag-over'); });
  imgDrop.addEventListener('dragleave', () => imgDrop.classList.remove('drag-over'));
  imgDrop.addEventListener('drop', e => {
    e.preventDefault();
    imgDrop.classList.remove('drag-over');
    if (e.dataTransfer.files.length) addImages(e.dataTransfer.files);
  });
}


// ─── Upload Audio Handling ────────────────────────────────────────────────────

function handleAudioFile(f) {
  if (!f || !isAudioFile(f.name)) return;
  state.audio = f;
  state.audioName = f.name;
  state.activeTab = 'upload';
  if (audioName) audioName.textContent = f.name;
  if (audioInfo) audioInfo.style.display = 'flex';

  const url = URL.createObjectURL(f);
  state.ttsAudioUrl = url;
  if (state.audioEl) state.audioEl.src = url;

  // Probe audio duration
  const probe = new Audio();
  probe.src = url;
  probe.onloadedmetadata = () => {
    state.audioDuration = probe.duration;
    console.log('Audio duration loaded:', state.audioDuration);
    syncImagesToTimeline();
    renderTimelineUI();
    updateFooterInfo();
    if (viewTimeline && viewTimeline.classList.contains('active')) {
      initWaveSurfer(url);
    }
  };

  updateFooterInfo();
  syncImagesToTimeline();
  renderTimelineUI();
}

if (audioInput) audioInput.addEventListener('change', () => {
  const f = audioInput.files[0];
  handleAudioFile(f);
});

if (audioDrop) {
  audioDrop.addEventListener('click', () => audioInput.click());
  audioDrop.addEventListener('dragover', e => { e.preventDefault(); audioDrop.classList.add('drag-over'); });
  audioDrop.addEventListener('dragleave', () => audioDrop.classList.remove('drag-over'));
  audioDrop.addEventListener('drop', e => {
    e.preventDefault();
    audioDrop.classList.remove('drag-over');
    const f = e.dataTransfer.files[0];
    handleAudioFile(f);
  });
}

if (audioRemove) audioRemove.addEventListener('click', () => {
  state.audio = null;
  state.audioName = '';
  state.audioDuration = null;
  state.ttsAudioUrl = null;
  if (state.audioEl) state.audioEl.src = '';
  if (audioInput) audioInput.value = '';
  if (audioInfo) audioInfo.style.display = 'none';
  if (wavesurfer) {
    try { wavesurfer.destroy(); } catch(e) {}
    wavesurfer = null;
  }
  syncImagesToTimeline();
  renderTimelineUI();
  updateFooterInfo();
});


// ─── Audio source tabs ────────────────────────────────────────────────────────

tabToggleBtns.forEach(btn => {
  btn.addEventListener('click', () => {
    const tab = btn.dataset.tab;
    state.activeTab = tab;
    tabToggleBtns.forEach(b => b.classList.toggle('active', b === btn));
    if (tabUpload) tabUpload.style.display = tab === 'upload' ? 'flex' : 'none';
    if (tabTts)    tabTts.style.display    = tab === 'tts'    ? 'flex' : 'none';
    updateFooterInfo();
  });
});


// ─── TTS Reference Audio ──────────────────────────────────────────────────────

if (ttsRefInput) {
  ttsRefInput.addEventListener('change', () => {
    const f = ttsRefInput.files[0];
    if (f && isAudioFile(f.name)) {
      state.ttsRefAudio = f;
      ttsRefName.textContent = f.name;
      ttsRefInfo.style.display = 'flex';
    }
  });
}
if (ttsRefRemove) ttsRefRemove.addEventListener('click', () => {
  state.ttsRefAudio = null;
  if (ttsRefInput) ttsRefInput.value = '';
  ttsRefInfo.style.display = 'none';
});


// ─── TTS Generation ───────────────────────────────────────────────────────────

// ─── TTS Generation (Edge-TTS) ────────────────────────────────────────────────
const selTtsVoice = $('sel-tts-voice');
const ttsRateRange = $('tts-rate-range');
const ttsSpeedVal = $('tts-speed-val');
const btnTtsSpeeds = document.querySelectorAll('.btn-tts-speed');

if (ttsRateRange && ttsSpeedVal) {
  ttsRateRange.addEventListener('input', () => {
    const val = parseInt(ttsRateRange.value);
    const speedMult = (1.0 + val / 100).toFixed(2);
    ttsSpeedVal.textContent = `${speedMult}x (${val >= 0 ? '+' : ''}${val}%)`;
    btnTtsSpeeds.forEach(btn => {
      btn.classList.toggle('active', parseInt(btn.dataset.rate) === val);
    });
  });
}

btnTtsSpeeds.forEach(btn => {
  btn.addEventListener('click', () => {
    const r = parseInt(btn.dataset.rate || 0);
    if (ttsRateRange) {
      ttsRateRange.value = r;
      ttsRateRange.dispatchEvent(new Event('input'));
    }
  });
});

if (ttsGenBtn) ttsGenBtn.addEventListener('click', async () => {
  let text = ttsText ? ttsText.value.trim() : '';
  const unifiedRaw = subScriptInput ? subScriptInput.value.trim() : '';

  if (!text && unifiedRaw) {
    text = unifiedRaw;
  }

  if (!text) {
    showTtsMsg('⚠️ Vui lòng dán kịch bản vào ô Kịch bản chung bên trên trước.');
    return;
  }

  setTtsGenerating(true);
  hideTtsMsg();

  const chosenVoice = selTtsVoice ? selTtsVoice.value : 'ko-KR-InJoonNeural';
  const rVal = ttsRateRange ? parseInt(ttsRateRange.value) : 0;
  const rateParam = `${rVal >= 0 ? '+' : ''}${rVal}%`;

  const fd = new FormData();
  fd.append('text',  text);
  fd.append('voice', chosenVoice);
  fd.append('rate', rateParam);
  fd.append('pitch', '+0Hz');
  fd.append('volume', '+0%');

  try {
    const res  = await fetch('/tts/generate', { method: 'POST', body: fd });
    let data;
    try {
      data = await res.json();
    } catch {
      throw new Error(`Server error (${res.status}): ${res.statusText || 'No response'}`);
    }
    if (!res.ok || data.error) throw new Error(data.error || 'TTS failed');
    startTtsSSE(data.job_id);
  } catch (err) {
    showTtsMsg('❌ ' + err.message);
    setTtsGenerating(false);
  }
});

function startTtsSSE(jobId) {
  if (state.ttsEvt) state.ttsEvt.close();
  if (ttsProgWrap) ttsProgWrap.style.display = 'flex';
  setTtsProgress(0);

  const es = new EventSource(`/progress/${jobId}`);
  state.ttsEvt = es;

  es.onmessage = e => {
    const msg = JSON.parse(e.data);
    if (msg.heartbeat) return;

    if (msg.message) showTtsMsg(msg.message);

    if (msg.error) {
      showTtsMsg('❌ ' + msg.error);
      setTtsGenerating(false);
      es.close();
      return;
    }

    const pct = msg.progress || 0;
    setTtsProgress(pct);

    if (msg.done || pct >= 100) {
      setTtsProgress(100);
      setTtsGenerating(false);
      state.ttsJobId = jobId;
      state.activeTab = 'tts';
      if (ttsJobIdHid) ttsJobIdHid.value = jobId;
      showTtsPreview(jobId);

      // Auto update dynamic SRT and Audio to Timeline (Instant 0.001s)
      if (msg.srt && customSrtText) {
        customSrtText.value = msg.srt;
      }
      setupAudioPlayback(`/tts/audio/${jobId}`);
      rebuildTimelineFromInputs();

      updateFooterInfo();
      es.close();
    }
  };

  es.onerror = () => {
    showTtsMsg('❌ Mất kết nối máy chủ khi tạo voice.');
    setTtsGenerating(false);
    es.close();
  };
}

function setTtsGenerating(active) {
  if (ttsGenBtn) {
    ttsGenBtn.disabled    = active;
    ttsGenBtn.textContent = active ? '⏳ Đang tạo giọng đọc Edge-TTS...' : '🎤 TẠO GIỌNG ĐỌC AI (Edge-TTS 2s)';
  }
  if (!active && ttsProgWrap) ttsProgWrap.style.display = 'none';
}

function setTtsProgress(pct) {
  if (ttsProgFill) ttsProgFill.style.width = pct + '%';
  if (ttsProgPct)  ttsProgPct.textContent  = pct + '%';
}

function showTtsPreview(jobId) {
  if (!ttsPreview) return;
  ttsPreview.style.display = 'flex';
  ttsPreview.style.flexDirection = 'column';
  ttsPreview.style.gap = '6px';
  if (ttsPlayer) ttsPlayer.src = `/tts/audio/${jobId}?t=${Date.now()}`;
}

function showTtsMsg(msg) {
  if (!ttsMsg) return;
  ttsMsg.textContent  = msg;
  ttsMsg.style.display = 'block';
}
function hideTtsMsg() {
  if (ttsMsg) ttsMsg.style.display = 'none';
}


// ─── Subtitles Studio & File Opening ──────────────────────────────────────────

// Button: ⚡ Đồng bộ Kịch bản sang Timeline
if (btnParseScript) {
  btnParseScript.addEventListener('click', async () => {
    const raw = subScriptInput ? subScriptInput.value.trim() : '';
    if (!raw) {
      alert('Vui lòng dán nội dung kịch bản hoặc JSON vào ô bên trên.');
      return;
    }

    btnParseScript.disabled = true;
    btnParseScript.textContent = '⏳ Đang dùng Gemini 3.6 Flash căn chỉnh...';

    try {
      await runAcousticForcedAlignment(raw);
    } catch (err) {
      console.error('Lỗi Forced Alignment:', err);
      showToast('❌ Lỗi đồng bộ kịch bản: ' + (err.message || err), 'error', 5000);
    } finally {
      btnParseScript.textContent = '⚡ Đồng bộ Kịch bản sang Timeline';
      btnParseScript.disabled = false;
    }
  });
}

const btnTlAutosub      = $('btn-tl-autosub');
const tlAutosubProgWrap = $('tl-autosub-prog-wrap');
const tlAutosubProgFill = $('tl-autosub-prog-fill');
const tlAutosubProgPct  = $('tl-autosub-prog-pct');

function setAutoSubProgress(active, pct = 0, msg = '') {
  if (tlAutosubProgWrap) {
    tlAutosubProgWrap.style.display = active ? 'inline-flex' : 'none';
  }
  if (tlAutosubProgFill) {
    tlAutosubProgFill.style.width = pct + '%';
  }
  if (tlAutosubProgPct) {
    tlAutosubProgPct.textContent = pct + '%';
  }
  if (btnTlAutosub && active) {
    btnTlAutosub.innerHTML = `⏳ ${msg || 'Đang quét sóng âm'} (${pct}%)`;
  }
}

function runSubtitleJobSSE(jobId) {
  return new Promise((resolve, reject) => {
    const es = new EventSource(`/progress/${jobId}`);
    es.onmessage = e => {
      const msg = JSON.parse(e.data);
      if (msg.heartbeat) return;
      if (msg.error) {
        es.close();
        reject(new Error(msg.error));
        return;
      }
      const pct = msg.progress || 0;
      setAutoSubProgress(true, pct, msg.message);

      if (msg.done || pct >= 100) {
        es.close();
        resolve(msg);
      }
    };
    es.onerror = () => {
      es.close();
      reject(new Error('Mất kết nối với máy chủ khi chạy AutoSub.'));
    };
  });
}

// ─── Auto-Write SRT Engine from AI Models (Speech-to-Text) ─────────────────
async function runAutoWriteSrt() {
  const hasAudio = state.audio || state.ttsJobId || state.ttsAudioUrl;
  if (!hasAudio) {
    alert('⚠️ Vui lòng tải lên file âm thanh ở Cột 1 hoặc sinh giọng Voice AI trước khi chạy AutoSub.');
    return;
  }

  const btnAutoWrite = $('btn-auto-write-srt');
  const cardProgWrap = $('card-autosub-prog-wrap');
  const cardProgMsg  = $('card-autosub-prog-msg');
  const cardProgPct  = $('card-autosub-prog-pct');
  const cardProgFill = $('card-autosub-prog-fill');

  if (btnAutoWrite) {
    btnAutoWrite.disabled = true;
    btnAutoWrite.innerHTML = '<span class="spinner-sm"></span> ⏳ Đang Khởi Tạo Model AI...';
  }
  if (btnTlAutosub) {
    btnTlAutosub.disabled = true;
    btnTlAutosub.innerHTML = '<span class="spinner-sm"></span> ⏳ Đang Tạo...';
  }
  if (cardProgWrap) {
    cardProgWrap.style.display = 'flex';
    if (cardProgMsg) cardProgMsg.textContent = 'Đang khởi tạo model AI...';
    if (cardProgPct) cardProgPct.textContent = '5%';
    if (cardProgFill) cardProgFill.style.width = '5%';
  }
  setAutoSubProgress(true, 5, 'Khởi động AI');

  try {
    const fd = new FormData();
    fd.append('language', selSubLang ? selSubLang.value : 'auto');

    const engineSel = $('sel-autosub-engine');
    const chkDemucs = $('chk-demucs');
    const inpInitPrompt = $('inp-initial-prompt');
    const inpGeminiKey = $('inp-gemini-key');

    let engineType = engineSel ? engineSel.value : 'gemini-cloud-multimodal';
    let modelSize = 'base';
    const demucsVal = false;
    const promptVal = inpInitPrompt ? inpInitPrompt.value : '';
    const geminiKeyVal = (inpGeminiKey ? inpGeminiKey.value.trim() : '') || localStorage.getItem('gemini_api_key') || '';
    if (inpGeminiKey && inpGeminiKey.value) localStorage.setItem('gemini_api_key', inpGeminiKey.value.trim());

    const inpGroundTruthVoice = $('inp-ground-truth-voice');
    const scriptTextVal = (inpGroundTruthVoice ? inpGroundTruthVoice.value.trim() : '') || (subScriptInput ? subScriptInput.value.trim() : '') || (typeof inpSubJsonScript !== 'undefined' && inpSubJsonScript ? inpSubJsonScript.value.trim() : '');

    fd.append('engine_type', engineType);
    fd.append('model_size', modelSize);
    fd.append('demucs', demucsVal ? 'true' : 'false');
    fd.append('initial_prompt', promptVal);
    fd.append('ground_truth_script', scriptTextVal);
    fd.append('gemini_api_key', geminiKeyVal);

    console.log('🚀 [Frontend AutoSub] Đang gửi payload:', {
      engineType,
      modelSize,
      demucs: demucsVal,
      geminiKeyLength: geminiKeyVal.length,
      scriptLength: scriptTextVal.length,
      hasAudio: Boolean(state.audio),
      ttsJobId: state.ttsJobId
    });

    if (state.activeTab === 'tts' && state.ttsJobId) {
      fd.append('tts_job_id', state.ttsJobId);
    } else if (state.audio) {
      fd.append('audio', state.audio);
    } else if (state.ttsJobId) {
      fd.append('tts_job_id', state.ttsJobId);
    } else if (state.ttsAudioUrl) {
      const m = state.ttsAudioUrl.match(/\/tts\/audio\/([a-zA-Z0-9_-]+)/);
      if (m) fd.append('tts_job_id', m[1]);
    }

    const res = await fetch('/subtitles/preview', { method: 'POST', body: fd });
    const initData = await res.json();
    if (!res.ok || initData.error) throw new Error(initData.error || 'Lỗi nhận diện');

    let result = null;
    if (initData.job_id) {
      // Connect to SSE for progress
      result = await new Promise((resolve, reject) => {
        let errorReceived = false;
        const es = new EventSource(`/subtitles/progress/${initData.job_id}`);
        es.onmessage = e => {
          try {
            const d = JSON.parse(e.data);
            if (d.status === 'error' || d.error) {
              errorReceived = true;
              es.close();
              const errMsg = d.message || d.error || 'Lỗi không xác định từ máy chủ';
              reject(new Error(errMsg));
              return;
            }

            const pct = d.progress || 0;
            const msg = d.message || '';
            setAutoSubProgress(true, pct, msg);
            if (cardProgMsg) cardProgMsg.textContent = msg;
            if (cardProgPct) cardProgPct.textContent = `${pct}%`;
            if (cardProgFill) cardProgFill.style.width = `${pct}%`;
            if (btnAutoWrite) btnAutoWrite.innerHTML = `<span class="spinner-sm"></span> ⏳ Đang Tạo Phụ Đề... (${pct}%)`;
            if (btnTlAutosub) btnTlAutosub.innerHTML = `<span class="spinner-sm"></span> ⏳ Tạo Sub (${pct}%)`;

            if (d.done) {
              es.close();
              resolve(d);
            }
          } catch(err) {
            es.close();
            reject(err);
          }
        };
        es.onerror = () => {
          es.close();
          if (!errorReceived) {
            reject(new Error('Mất kết nối với máy chủ khi chạy AutoSub.'));
          }
        };
      });
    } else {
      result = initData;
    }

    if (customSrtText) {
      customSrtText.value = result.srt || '';
    }
    state.subtitles = result.subtitles || [];

    // Render AI Verification & Timecode Report Banner
    const rep = result.verification_report;
    const repBox = $('ai-verify-report-box');
    if (repBox && rep) {
      repBox.style.display = 'block';
      const repIcon = $('ai-report-icon');
      const repTitle = $('ai-report-title');
      const repSubs = $('ai-report-subs');
      const repFixes = $('ai-report-fixes');
      const repTimecode = $('ai-report-timecode');
      const repCoverage = $('ai-report-coverage');

      if (repIcon) repIcon.textContent = rep.gemini_verified ? '✨' : '⚡';
      if (repTitle) repTitle.textContent = rep.gemini_verified
        ? `Gemini 2.5 Flash: Đã Đối Soát (${rep.mode}) & Chuẩn Hóa!`
        : `${rep.model_used}: Đã Nhận Diện & Chuẩn Hóa Timecode!`;
      if (repSubs) repSubs.textContent = `${rep.subs_count} câu`;
      if (repFixes) repFixes.textContent = `${rep.corrections_count} câu`;
      if (repTimecode) repTimecode.textContent = rep.timecode_status;
      if (repCoverage) repCoverage.textContent = rep.audio_coverage;
    }

    const subStatusBadge = $('sub-status-badge');
    const subLineCount = $('sub-line-count');
    if (subStatusBadge) subStatusBadge.style.display = 'block';
    if (subLineCount) subLineCount.textContent = state.subtitles.length;

    syncImagesToTimeline();
    renderTimelineUI();
    updateMonitor(state.currentTime);
    updateInspector();

    setAutoSubProgress(false);
    if (cardProgWrap) {
      if (cardProgMsg) cardProgMsg.textContent = `✅ Đã đối soát & chuẩn hóa ${state.subtitles.length} câu .SRT!`;
      if (cardProgPct) cardProgPct.textContent = '100%';
      if (cardProgFill) cardProgFill.style.width = '100%';
      setTimeout(() => {
        cardProgWrap.style.display = 'none';
      }, 4000);
    }

    if (btnAutoWrite) {
      btnAutoWrite.textContent = `✅ Đã tạo xong ${state.subtitles.length} câu .SRT!`;
      setTimeout(() => {
        btnAutoWrite.textContent = '🎙️ TỰ ĐỘNG TẠO PHỤ ĐỀ (Speech-to-Text)';
        btnAutoWrite.disabled = false;
      }, 3500);
    }
    if (btnTlAutosub) {
      btnTlAutosub.innerHTML = `✅ Đã gán ${state.subtitles.length} câu!`;
      setTimeout(() => {
        btnTlAutosub.innerHTML = '⚡ AutoSub';
        btnTlAutosub.disabled = false;
      }, 3500);
    }
  } catch (err) {
    setAutoSubProgress(false);
    if (cardProgWrap) cardProgWrap.style.display = 'none';
    alert('Lỗi AutoSub: ' + err.message);
    if (btnAutoWrite) {
      btnAutoWrite.textContent = '🎙️ TỰ ĐỘNG TẠO PHỤ ĐỀ (Speech-to-Text)';
      btnAutoWrite.disabled = false;
    }
    if (btnTlAutosub) {
      btnTlAutosub.innerHTML = '⚡ AutoSub';
      btnTlAutosub.disabled = false;
    }
  }
}

async function runForcedAlignment() {
  const audio = state.audio;
  const ttsJobId = state.ttsJobId;
  if (!audio && !ttsJobId) {
    showToast('⚠️ Vui lòng tải lên file Audio Voice hoặc sinh giọng TTS trước khi chạy Forced Alignment.', 'warning');
    return;
  }

  let scriptText = (inpGroundTruthVoiceEl ? inpGroundTruthVoiceEl.value.trim() : '');
  if (!scriptText) {
    const rawCard1 = (subScriptInput ? subScriptInput.value.trim() : '') || (ttsText ? ttsText.value.trim() : '');
    if (rawCard1) {
      scriptText = extractPureVoiceFromText(rawCard1);
      if (inpGroundTruthVoiceEl) inpGroundTruthVoiceEl.value = scriptText;
    }
  }

  if (!scriptText) {
    showToast('⚠️ Vui lòng nhập kịch bản gốc .txt vào ô "Kịch Bản Voice Đối Soát" để so khớp 100%.', 'warning');
    if (inpGroundTruthVoiceEl) inpGroundTruthVoiceEl.focus();
    return;
  }

  const btnFA = $('btn-forced-alignment');
  const btnTlFA = $('btn-tl-forced-align');
  const cardProgWrap = $('card-autosub-prog-wrap');
  const cardProgMsg = $('card-autosub-prog-msg');
  const cardProgPct = $('card-autosub-prog-pct');
  const cardProgFill = $('card-autosub-prog-fill');

  try {
    if (btnFA) {
      btnFA.disabled = true;
      btnFA.innerHTML = '<span class="spinner-sm"></span> ⏳ Đang So Khớp Âm Học... (10%)';
    }
    if (btnTlFA) {
      btnTlFA.disabled = true;
      btnTlFA.innerHTML = '<span class="spinner-sm"></span> ⏳ Đang So Khớp...';
    }
    if (cardProgWrap) {
      cardProgWrap.style.display = 'flex';
      if (cardProgMsg) cardProgMsg.textContent = 'Đang khởi chạy Forced Alignment Engine...';
      if (cardProgPct) cardProgPct.textContent = '10%';
      if (cardProgFill) cardProgFill.style.width = '10%';
    }
    const engine = state.forcedAlignEngine || 'gemini';
    const selFaLang = $('sel-fa-lang');
    const faLang = (selFaLang ? selFaLang.value : 'auto') || (selSubLang ? selSubLang.value : 'auto');

    const formData = new FormData();
    if (audio) formData.append('audio', audio);
    if (ttsJobId) formData.append('tts_job_id', ttsJobId);
    formData.append('script_text', scriptText);
    formData.append('language', faLang);
    formData.append('engine', engine);

    const key = (inpGeminiKey ? inpGeminiKey.value.trim() : '') || (typeof getStoredGeminiKey === 'function' ? getStoredGeminiKey() : '');
    if (key) formData.append('gemini_api_key', key);

    if (engine === 'gemini' && !key) {
      throw new Error('Vui lòng nhập Gemini API Key để dùng chế độ Gemini Cloud, hoặc chọn "💻 Stable-Whisper (Offline)" để chạy trực tiếp trên máy.');
    }

    const res = await fetch('/api/forced-align', {
      method: 'POST',
      body: formData
    });

    const data = await res.json();
    if (!res.ok || data.error) {
      throw new Error(data.error || 'Lỗi xử lý Forced Alignment');
    }

    const jobId = data.job_id;
    const es = new EventSource(`/subtitles/progress/${jobId}`);

    es.onmessage = e => {
      try {
        const payload = JSON.parse(e.data);
        if (payload.progress !== undefined) {
          const pct = Math.max(10, Math.min(100, payload.progress));
          if (cardProgPct) cardProgPct.textContent = `${pct}%`;
          if (cardProgFill) cardProgFill.style.width = `${pct}%`;
          if (cardProgMsg) cardProgMsg.textContent = payload.message || 'Đang so khớp âm học...';
          if (btnFA) btnFA.innerHTML = `<span class="spinner-sm"></span> ⏳ Đang So Khớp... (${pct}%)`;
          if (btnTlFA) btnTlFA.innerHTML = `<span class="spinner-sm"></span> ⏳ So Khớp (${pct}%)`;
        }

        if (payload.done) {
          es.close();
          setAutoSubProgress(false);
          if (cardProgWrap) cardProgWrap.style.display = 'none';
          if (btnFA) {
            btnFA.disabled = false;
            btnFA.innerHTML = '🎯 BẮT ĐẦU SO KHỚP KỊCH BẢN (Forced Alignment)';
          }
          if (btnTlFA) {
            btnTlFA.disabled = false;
            btnTlFA.innerHTML = '🎯 So Khớp Kịch Bản';
          }

          if (customSrtText) customSrtText.value = payload.srt;
          if (payload.subtitles && payload.subtitles.length > 0) {
            state.subtitles = payload.subtitles;
          } else {
            rebuildTimelineFromInputs();
          }

          syncImagesToTimeline();
          renderTimelineUI();
          updateInspector();
          updateFooterInfo();

          showToast(`🎉 Hoàn tất Forced Alignment! Đã so khớp chính xác 100% ${payload.count || state.subtitles.length} câu kịch bản.`, 'success');
        } else if (payload.error) {
          es.close();
          throw new Error(payload.error);
        }
      } catch (err) {
        es.close();
        setAutoSubProgress(false);
        if (cardProgWrap) cardProgWrap.style.display = 'none';
        if (btnFA) {
          btnFA.disabled = false;
          btnFA.innerHTML = '🎯 BẮT ĐẦU SO KHỚP KỊCH BẢN (Forced Alignment)';
        }
        if (btnTlFA) {
          btnTlFA.disabled = false;
          btnTlFA.innerHTML = '🎯 So Khớp Kịch Bản';
        }
        showToast('Lỗi Forced Alignment: ' + err.message, 'error');
      }
    };

    es.onerror = () => {
      es.close();
      setAutoSubProgress(false);
      if (cardProgWrap) cardProgWrap.style.display = 'none';
      if (btnFA) {
        btnFA.disabled = false;
        btnFA.innerHTML = '🎯 BẮT ĐẦU SO KHỚP KỊCH BẢN (Forced Alignment)';
      }
      if (btnTlFA) {
        btnTlFA.disabled = false;
        btnTlFA.innerHTML = '🎯 So Khớp Kịch Bản';
      }
    };

  } catch (err) {
    setAutoSubProgress(false);
    if (cardProgWrap) cardProgWrap.style.display = 'none';
    if (btnFA) {
      btnFA.disabled = false;
      btnFA.innerHTML = '🎯 BẮT ĐẦU SO KHỚP KỊCH BẢN (Forced Alignment)';
    }
    if (btnTlFA) {
      btnTlFA.disabled = false;
      btnTlFA.innerHTML = '🎯 So Khớp Kịch Bản';
    }
    showToast('Lỗi Forced Alignment: ' + err.message, 'error');
  }
}

// ─── Card 2 Switcher & Engine Selection ────────────────────────────────────────

const c2TabBtns = $$('#card2-mode-toggle .c2-tab-btn');
const c2BlockFA = $('c2-block-forced-align');
const c2BlockSTT = $('c2-block-autosub-stt');

c2TabBtns.forEach(btn => {
  btn.addEventListener('click', () => {
    c2TabBtns.forEach(b => {
      const isSelected = (b === btn);
      b.classList.toggle('active', isSelected);
      b.style.background = isSelected ? '#0284c7' : 'transparent';
      b.style.color = isSelected ? '#ffffff' : '#94a3b8';
    });
    const mode = btn.dataset.mode;
    if (c2BlockFA) c2BlockFA.style.display = (mode === 'forced-align') ? 'block' : 'none';
    if (c2BlockSTT) c2BlockSTT.style.display = (mode === 'autosub') ? 'block' : 'none';
  });
});

state.forcedAlignEngine = 'whisperx';
const faEngineBtns = $$('#forced-align-engine-group .btn-option');
faEngineBtns.forEach(btn => {
  btn.addEventListener('click', () => {
    faEngineBtns.forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    state.forcedAlignEngine = btn.dataset.engine || 'whisperx';
    const engName = state.forcedAlignEngine === 'whisperx' ? '🎯 WhisperX (Khuyên Dùng - Wav2Vec2 CTC)' : '☁️ Gemini Cloud (2-3s)';
    showToast(`Đã chọn Engine: ${engName}`, 'info');
  });
});

const btnAutoWriteSrt = $('btn-auto-write-srt');
if (btnAutoWriteSrt) {
  btnAutoWriteSrt.addEventListener('click', runAutoWriteSrt);
}
if (btnTlAutosub) {
  btnTlAutosub.addEventListener('click', runAutoWriteSrt);
}

const btnForcedAlignment = $('btn-forced-alignment');
if (btnForcedAlignment) {
  btnForcedAlignment.addEventListener('click', runForcedAlignment);
}
const btnTlForcedAlign = $('btn-tl-forced-align');
if (btnTlForcedAlign) {
  btnTlForcedAlign.addEventListener('click', runForcedAlignment);
}
const btnTlDlSrtClean = $('btn-tl-dl-srt-clean');
if (btnTlDlSrtClean) {
  btnTlDlSrtClean.addEventListener('click', () => {
    if (btnDownloadSrtFile) btnDownloadSrtFile.click();
  });
}

// Download .SRT File directly to computer
const btnDownloadSrtFile = $('btn-download-srt-file');
if (btnDownloadSrtFile) {
  btnDownloadSrtFile.addEventListener('click', () => {
    const srtContent = customSrtText ? customSrtText.value.trim() : '';
    if (!srtContent) {
      alert('⚠️ Chưa có nội dung phụ đề .SRT để tải về. Vui lòng bấm "Tự Động Viết .SRT" trước.');
      return;
    }
    const projTitle = ($('project-title') ? $('project-title').value.trim() : '') || 'subtitles';
    const blob = new Blob([srtContent], { type: 'text/plain;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `${projTitle.replace(/[^a-zA-Z0-9_-]/g, '_')}.srt`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
  });
}

// Gemini API Key persistence
const inpGeminiKey = $('inp-gemini-key');
// Helper to extract pure voice text
function extractPureVoiceFromText(rawText) {
  if (!rawText) return '';
  let txt = rawText.trim();
  const codeBlockMatch = txt.match(/```(?:voice_text|voice|text)\s*\n([\s\S]*?)\n```/i);
  if (codeBlockMatch) return codeBlockMatch[1].trim();

  const secMatch = txt.match(/(?:\[VOICE_SCRIPT_ONLY\]|PHẦN 2[^\n]*)\s*\n([\s\S]*)/i);
  if (secMatch) txt = secMatch[1].trim();

  const lines = txt.split('\n');
  const cleaned = [];
  for (let l of lines) {
    l = l.trim();
    if (!l || l.startsWith('#') || l.startsWith('---') || l.startsWith('***')) continue;
    l = l.replace(/\[(?:Cảnh|Scene|Phân cảnh)[^\]]*\]/gi, '');
    l = l.replace(/\((?:nhạc|tiếng|cười|khóc|bgm|sfx)[^\)]*\)/gi, '');
    l = l.replace(/^(?:Người dẫn|Narrator|Nhân vật|Host|[A-Z])\s*[:：]\s*/i, '');
    l = l.trim();
    if (l) cleaned.push(l);
  }
  return cleaned.join('\n') || rawText.trim();
}

const btnSyncScriptCard1 = $('btn-sync-script-card1');
const fileGroundTruthScript = $('file-ground-truth-script');
const inpGroundTruthVoiceEl = $('inp-ground-truth-voice');

if (btnSyncScriptCard1) {
  btnSyncScriptCard1.addEventListener('click', () => {
    const rawCard1 = (subScriptInput ? subScriptInput.value.trim() : '') || (typeof inpSubJsonScript !== 'undefined' && inpSubJsonScript ? inpSubJsonScript.value.trim() : '');
    if (!rawCard1) {
      alert('⚠️ Chưa có văn bản kịch bản trong Card 1 (TTS). Vui lòng nhập ở Card 1 trước.');
      return;
    }
    const pure = extractPureVoiceFromText(rawCard1);
    if (inpGroundTruthVoiceEl) {
      inpGroundTruthVoiceEl.value = pure;
      inpGroundTruthVoiceEl.style.borderColor = '#10b981';
      setTimeout(() => inpGroundTruthVoiceEl.style.borderColor = '', 1500);
    }
  });
}

if (fileGroundTruthScript) {
  fileGroundTruthScript.addEventListener('change', e => {
    const file = e.target.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = ev => {
      const pure = extractPureVoiceFromText(ev.target.result || '');
      if (inpGroundTruthVoiceEl) {
        inpGroundTruthVoiceEl.value = pure;
        inpGroundTruthVoiceEl.style.borderColor = '#38bdf8';
        setTimeout(() => inpGroundTruthVoiceEl.style.borderColor = '', 1500);
      }
    };
    reader.readAsText(file, 'utf-8');
  });
}

// Open file (.json, .srt, .txt) from Setup tab
if (subFileInput) {
  subFileInput.addEventListener('change', e => {
    handleScriptFile(e.target.files[0]);
  });
}

// Direct JSON File Input on Timeline Toolbar
if (tlJsonFileInput) {
  tlJsonFileInput.addEventListener('change', e => {
    handleScriptFile(e.target.files[0]);
  });
}

async function handleScriptFile(file) {
  if (!file) return;
  const reader = new FileReader();
  reader.onload = async ev => {
    const content = ev.target.result;
    if (file.name.endsWith('.srt')) {
      if (customSrtText) customSrtText.value = content;
      rebuildTimelineFromInputs();
    } else if (file.name.endsWith('.json')) {
      try {
        const parsed = JSON.parse(content);
        if (parsed.language && selSubLang) {
          selSubLang.value = parsed.language;
        }
        if (parsed.project_title) {
          state.projectTitle = parsed.project_title;
          if (!state.currentProjectId) {
            createNewProject(parsed.project_title);
          }
        }
      } catch (err) {}

      if (subScriptInput) subScriptInput.value = content;
      await runAcousticForcedAlignment(content);
    } else {
      if (subScriptInput) subScriptInput.value = content;
      await runAcousticForcedAlignment(content);
    }
  };
  reader.readAsText(file);
}

/**
 * Runs Faster-Whisper Script-to-Audio Forced Alignment
 */
async function runAcousticForcedAlignment(scriptRawText, specificAudioPath = null) {
  const dur = parseFloat(inpDuration ? inpDuration.value : 3.5) || 3.5;
  const fd = new FormData();
  fd.append('script_text', scriptRawText);
  fd.append('duration_per_image', dur);
  fd.append('image_count', state.images.length);
  fd.append('language', selSubLang ? selSubLang.value : 'auto');

  const geminiKeyVal = (typeof inpGeminiKey !== 'undefined' && inpGeminiKey ? inpGeminiKey.value.trim() : '') || localStorage.getItem('gemini_api_key') || '';
  if (geminiKeyVal) {
    fd.append('gemini_api_key', geminiKeyVal);
  }

  if (state.activeTab === 'tts' && state.ttsJobId) {
    fd.append('tts_job_id', state.ttsJobId);
  } else if (state.audio) {
    fd.append('audio', state.audio);
  } else if (state.ttsJobId) {
    fd.append('tts_job_id', state.ttsJobId);
  } else if (state.ttsAudioUrl) {
    const m = state.ttsAudioUrl.match(/\/tts\/audio\/([a-zA-Z0-9_-]+)/);
    if (m) fd.append('tts_job_id', m[1]);
  }

  try {
    const res = await fetch('/subtitles/align', { method: 'POST', body: fd });
    const initData = await res.json();
    if (!res.ok || initData.error) throw new Error(initData.error || 'Lỗi căn mốc Gemini AI');

    let data = initData;
    if (initData.job_id) {
      data = await runSubtitleJobSSE(initData.job_id);
    }

    if (customSrtText) {
      customSrtText.value = data.srt || '';
    }
    state.subtitles = data.subtitles || [];
    syncImagesToTimeline();
    renderTimelineUI();
    updateMonitor(state.currentTime);
    updateInspector();
    return data;
  } catch (err) {
    console.error('Forced alignment error:', err);
    throw err;
  }
}

// Clear subtitle button
if (btnClearSub) {
  btnClearSub.addEventListener('click', () => {
    if (customSrtText) customSrtText.value = '';
    state.subtitles = [];
    renderTimelineUI();
    updateMonitor(state.currentTime);
  });
}


// ═══════════════════════════════════════════════════════════════════════════════
// TIMELINE STUDIO & LIVE PREVIEW MONITOR ENGINE
// ═══════════════════════════════════════════════════════════════════════════════

function initWaveSurfer(url) {
  const container = $('tl-wavesurfer-container');
  if (!container || typeof WaveSurfer === 'undefined') return;

  if (wavesurfer) {
    try {
      wavesurfer.destroy();
    } catch(e) {}
    wavesurfer = null;
  }

  try {
    wavesurfer = WaveSurfer.create({
      container: container,
      waveColor: '#0284c7',
      progressColor: '#38bdf8',
      cursorColor: 'transparent',
      height: 38,
      barWidth: 2,
      barGap: 1,
      barRadius: 2,
      normalize: true,
      minPxPerSec: state.zoomPxPerSec || 60,
      fillParent: false,
      autoScroll: false,
      autoCenter: false,
      url: url
    });

    wavesurfer.on('ready', () => {
      const dur = wavesurfer.getDuration();
      if (dur > 0 && Math.abs(dur - (state.audioDuration || 0)) > 0.3) {
        state.audioDuration = dur;
        syncImagesToTimeline();
        renderTimelineUI();
      }
      if (tlViewport && wavesurfer && wavesurfer.renderer) {
        try {
          wavesurfer.renderer.setScroll(tlViewport.scrollLeft);
        } catch(e) {}
      }
    });

    wavesurfer.on('timeupdate', currentTime => {
      state.currentTime = currentTime;
      updatePlayhead(currentTime);
      updateMonitor(currentTime);
    });

    wavesurfer.on('interaction', newTime => {
      state.currentTime = newTime;
      updatePlayhead(newTime);
      updateMonitor(newTime);
    });

    wavesurfer.on('finish', () => {
      setPlaying(false);
    });
  } catch (err) {
    console.warn('WaveSurfer init error:', err);
  }
}

function setupAudioPlayback(url) {
  state.ttsAudioUrl = url;
  if (state.audioEl) state.audioEl.src = url;
  
  const probe = new Audio();
  probe.src = url;
  probe.onloadedmetadata = () => {
    state.audioDuration = probe.duration;
    syncImagesToTimeline();
    renderTimelineUI();
    updateFooterInfo();
    if (viewTimeline && viewTimeline.classList.contains('active')) {
      initWaveSurfer(url);
    }
  };
}

const EFFECT_LABELS = {
  none:     '⏸️ Ảnh tĩnh',
  static:   '⏸️ Ảnh tĩnh',
  zoom_in:  '🔍 Zoom In',
  zoom_out: '🔍 Zoom Out',
  pan_lr:   '↔️ Pan L→R',
  pan_rl:   '↔️ Pan R→L',
  tilt_ud:  '↕️ Tilt U→D',
  tilt_du:  '↕️ Tilt D→U'
};
const EFFECT_POOL = ['zoom_in', 'zoom_out', 'pan_lr', 'pan_rl', 'tilt_ud', 'tilt_du'];

function syncImagesToTimeline() {
  const images = state.images;
  const defaultDur = parseFloat(inpDuration ? inpDuration.value : 3.5) || 3.5;
  const totalAudioDur = state.audioDuration || (state.subtitles.length > 0 ? state.subtitles[state.subtitles.length - 1].end : (images.length * defaultDur));

  // Distribute images along the timeline
  const count = Math.max(images.length, 1);
  const durPerImg = images.length > 0 && totalAudioDur > 0 ? Math.max(1.0, totalAudioDur / images.length) : defaultDur;

  const imgData = [];
  let curT = 0.0;
  let lastEff = null;
  for (let i = 0; i < count; i++) {
    const f = images[i] || null;
    const choices = EFFECT_POOL.filter(e => e !== lastEff);
    const eff = choices[Math.floor(Math.random() * choices.length)];
    lastEff = eff;

    imgData.push({
      index: i,
      sceneId: `scene_${String(i + 1).padStart(3, '0')}`,
      imageFile: f,
      imageUrl: f ? URL.createObjectURL(f) : '',
      duration: durPerImg,
      effect: (state.imagesData[i] && state.imagesData[i].effect) || eff,
      startTime: curT,
      endTime: curT + durPerImg
    });
    curT += durPerImg;
  }
  state.imagesData = imgData;
  updateDurationsHidden();
  updateEffectsHidden();
  renderTimelineUI();
  updateMonitor(state.currentTime);
}

function updateEffectsHidden() {
  const hid = $('image-effects-json');
  if (!hid) return;
  const effs = state.imagesData.map(d => d.effect || 'zoom_in');
  hid.value = JSON.stringify(effs);
}

function randomizeAllImageEffects() {
  let lastEff = null;
  state.imagesData.forEach(item => {
    const choices = EFFECT_POOL.filter(e => e !== lastEff);
    const eff = choices[Math.floor(Math.random() * choices.length)];
    item.effect = eff;
    lastEff = eff;
  });
  renderTimelineUI();
  updateInspector();
  updateEffectsHidden();
}

/**
 * Parses current images + SRT subtitles into independent timeline tracks
 */
function rebuildTimelineFromInputs() {
  const srtRaw = customSrtText ? customSrtText.value.trim() : '';
  const srtBlocks = [];

  if (srtRaw) {
    const cleanText = srtRaw.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
    const lines = cleanText.split('\n');
    const tcPattern = /(?:(?:(\d{1,2}):)?(\d{1,2}):(\d{1,2})(?:[,.](\d{1,4}))?)\s*-->\s*(?:(?:(\d{1,2}):)?(\d{1,2}):(\d{1,2})(?:[,.](\d{1,4}))?)/;
    
    function parseTime(h, m, s, ms) {
      const hVal = h ? parseInt(h, 10) : 0;
      const mVal = m ? parseInt(m, 10) : 0;
      const sVal = s ? parseInt(s, 10) : 0;
      const msVal = ms ? parseFloat('0.' + ms) : 0.0;
      return hVal * 3600.0 + mVal * 60.0 + sVal + msVal;
    }

    function isNextCueAhead(startIdx) {
      let k = startIdx;
      while (k < lines.length && !lines[k].trim()) k++;
      if (k >= lines.length) return false;
      const firstNonEmpty = lines[k].trim();
      if (tcPattern.test(firstNonEmpty)) return true;
      if (/^\d+$/.test(firstNonEmpty)) {
        let k2 = k + 1;
        while (k2 < lines.length && !lines[k2].trim()) k2++;
        if (k2 < lines.length && tcPattern.test(lines[k2].trim())) return true;
      }
      return false;
    }

    let i = 0;
    while (i < lines.length) {
      const line = lines[i].trim();
      const m = line.match(tcPattern);
      if (m) {
        const st = parseTime(m[1], m[2], m[3], m[4]);
        let et = parseTime(m[5], m[6], m[7], m[8]);
        if (et <= st) et = st + 1.0;

        const textParts = [];
        i++;
        while (i < lines.length) {
          const nextLine = lines[i].trim();
          if (!nextLine) {
            if (isNextCueAhead(i + 1)) break;
            i++;
            continue;
          }
          if (tcPattern.test(nextLine)) break;
          if (/^\d+$/.test(nextLine) && isNextCueAhead(i)) break;
          textParts.push(nextLine);
          i++;
        }

        while (textParts.length && /^\d+$/.test(textParts[textParts.length - 1].trim())) {
          textParts.pop();
        }

        const txt = textParts.join(' ').trim();
        if (txt) {
          srtBlocks.push({ id: srtBlocks.length + 1, start: st, end: et, text: txt });
        }
      } else {
        i++;
      }
    }
  }

  state.subtitles = srtBlocks;
  syncImagesToTimeline();
  renderTimelineUI();
  updateMonitor(state.currentTime);
  updateInspector();
}

function updateDurationsHidden() {
  if (!imgDursHid) return;
  const durs = state.imagesData.map(d => d.duration);
  imgDursHid.value = JSON.stringify(durs);
}

function generateSrtFromSubtitles() {
  const lines = [];
  if (Array.isArray(state.subtitles)) {
    state.subtitles.forEach((sub, idx) => {
      if (!sub) return;
      const text = (sub.text || sub.content || sub.subtitle || sub.trans_text || sub.orig_text || sub.origText || '').trim();
      const start = (typeof sub.start === 'number') ? sub.start : parseFloat(sub.start || sub.start_time || 0);
      const end = (typeof sub.end === 'number') ? sub.end : parseFloat(sub.end || sub.end_time || (start + 2.0));
      if (text) {
        lines.push(`${idx + 1}`);
        lines.push(`${formatSrtTime(start)} --> ${formatSrtTime(end)}`);
        lines.push(text);
        lines.push('');
      }
    });
  }
  return lines.join('\n');
}

/**
 * Delete currently selected image or subtitle
 */
function deleteSelectedTimelineItem() {
  saveUndoSnapshot();
  if (state.selectedType === 'image') {
    if (state.imagesData.length <= 1) return;
    const idx = state.selectedIdx;
    state.imagesData.splice(idx, 1);
    if (idx < state.images.length) {
      state.images.splice(idx, 1);
      renderImageList();
      updateFooterInfo();
    }
    // Recalculate image timings
    let curT = 0.0;
    state.imagesData.forEach((d, i) => {
      d.index = i;
      d.sceneId = `scene_${String(i + 1).padStart(3, '0')}`;
      d.startTime = curT;
      d.endTime = curT + d.duration;
      curT += d.duration;
    });
    state.selectedIdx = Math.max(0, Math.min(idx, state.imagesData.length - 1));
  } else {
    // Delete subtitle chip
    const idx = state.selectedIdx;
    if (idx >= 0 && idx < state.subtitles.length) {
      state.subtitles.splice(idx, 1);
      if (customSrtText) customSrtText.value = generateSrtFromSubtitles();
      state.selectedIdx = Math.max(0, Math.min(idx, state.subtitles.length - 1));
    }
  }

  renderTimelineUI();
  updateInspector();
  updateMonitor(state.currentTime);
  updateDurationsHidden();
}

if (btnTlDeleteSelected) {
  btnTlDeleteSelected.addEventListener('click', deleteSelectedTimelineItem);
}

// ─── CapCut Multi-Track & Subtitle Studio DOM Elements ───────────────────────
const tlSubTransTrack       = $('tl-sub-track');
const tlSubOrigTrack        = $('tl-sub-orig-track');
const tlImgTrackRow         = $('tl-row-images');
const tlSubOrigRow          = $('tl-row-sub-orig');
const btnTlSplit            = $('btn-tl-split');
const btnTlSnap             = $('btn-tl-snap');
const btnTlBilingualToggle  = $('btn-tl-bilingual-toggle');
const btnTlTranslate        = $('btn-tl-translate');
const inspTabPropsBtn       = $('insp-tab-props-btn');
const inspTabSublistBtn     = $('insp-tab-sublist-btn');
const inspPaneProps         = $('insp-pane-props');
const inspPaneSublist       = $('insp-pane-sublist');
const bilingualSublistScroll= $('bilingual-sublist-scroll');
const sublistSearchInp      = $('sublist-search-inp');
const btnSublistTranslateAll= $('btn-sublist-translate-all');
const inspSubCount          = $('insp-sub-count');
const tlMinimapWrap         = $('tl-minimap-wrap');
const tlMinimapHandle       = $('tl-minimap-handle');
const inspSubtitleOrigText  = $('insp-subtitle-orig-text');

state.sourceSubtitles = [];  // Array of original { id, start, end, text }
state.isBilingual = true;
state.isMagnetic = true;

function renderTimelineUI() {
  if (!tlSubTransTrack || !tlImgTrack || !tlRuler) return;

  const pxPerSec = state.zoomPxPerSec;
  const imgTotalDur = state.imagesData.reduce((acc, d) => acc + d.duration, 0);
  const subTotalDur = state.subtitles.length > 0 ? state.subtitles[state.subtitles.length - 1].end : 0;
  const totalDur = Math.max(imgTotalDur, subTotalDur, 1.0);
  const totalWidth = Math.max(800, totalDur * pxPerSec + 120);

  // Set track widths for all rows
  tlRuler.style.width = totalWidth + 'px';
  tlSubTransTrack.style.width = totalWidth + 'px';
  if (tlSubOrigTrack) tlSubOrigTrack.style.width = totalWidth + 'px';
  tlImgTrack.style.width = totalWidth + 'px';
  const maskTrack = $('tl-mask-track');
  if (maskTrack) maskTrack.style.width = totalWidth + 'px';
  const bgmTrack = $('tl-bgm-track');
  if (bgmTrack) bgmTrack.style.width = totalWidth + 'px';

  // 1. Render Time Ruler
  tlRuler.innerHTML = '';
  const stepSec = pxPerSec > 100 ? 1 : (pxPerSec > 50 ? 2 : 5);
  for (let s = 0; s <= totalDur + 5; s += stepSec) {
    const mark = document.createElement('div');
    mark.className = 'tl-ruler-mark';
    mark.style.left = (s * pxPerSec) + 'px';
    mark.textContent = `${s}s`;
    tlRuler.appendChild(mark);
  }

function getSubLanguageFlag(text) {
  if (!text) return '🌐';
  if (/[\uac00-\ud7a3\u1100-\u11ff\u3130-\u318f]/.test(text)) return '🇰🇷';
  if (/[àáạảãâầấậẩẫăằắặẳẵèéẹẻẽêềếệểễìíịỉĩòóọỏõôồốộổỗơờớợởỡùúụủũưừứựửữỳýỵỷỹđ]/i.test(text)) return '🇻🇳';
  if (/[\u3040-\u30ff]/.test(text)) return '🇯🇵';
  if (/[\u4e00-\u9fff]/.test(text)) return '🇨🇳';
  return '🇺🇸';
}

  // 2. Render Track 1: Target / Translated Subtitles (VI/KO/EN)
  tlSubTransTrack.innerHTML = '';
  state.subtitles.forEach((sub, idx) => {
    if (!sub.text) return;
    const dur = Math.max(0.3, sub.end - sub.start);
    const chip = document.createElement('div');
    chip.className = `tl-sub-chip tl-sub-chip-trans ${state.selectedType === 'subtitle' && idx === state.selectedIdx ? 'active' : ''}`;
    chip.style.left = (sub.start * pxPerSec) + 'px';
    chip.style.width = Math.max(25, (dur * pxPerSec) - 3) + 'px';
    chip.title = `${formatSecs(sub.start)} - ${formatSecs(sub.end)}: ${sub.text}`;
    const flagIcon = getSubLanguageFlag(sub.text);
    chip.innerHTML = `<span class="tl-sub-icon">${flagIcon}</span> <span class="tl-sub-text-label">${sub.text}</span>`;
    
    // Left & Right Trim Resizers
    const resizerL = document.createElement('div');
    resizerL.className = 'tl-sub-resizer tl-sub-resizer-left';
    resizerL.title = 'Kéo mép trái để đổi mốc bắt đầu';
    resizerL.addEventListener('mousedown', e => {
      e.stopPropagation();
      startResizingSubtitle(e, idx, 'left');
    });

    const resizerR = document.createElement('div');
    resizerR.className = 'tl-sub-resizer tl-sub-resizer-right';
    resizerR.title = 'Kéo mép phải để đổi mốc kết thúc';
    resizerR.addEventListener('mousedown', e => {
      e.stopPropagation();
      startResizingSubtitle(e, idx, 'right');
    });

    chip.appendChild(resizerL);
    chip.appendChild(resizerR);

    // Mouse down drag-to-move
    chip.addEventListener('mousedown', e => {
      if (e.target.classList.contains('tl-sub-resizer')) return;
      state.selectedType = 'subtitle';
      state.selectedIdx = idx;
      startDraggingSubtitle(e, idx);
    });

    // Click selection
    chip.addEventListener('click', e => {
      e.stopPropagation();
      state.selectedType = 'subtitle';
      state.selectedIdx = idx;
      renderTimelineUI();
      updateInspector();
      seekTo(sub.start);
    });

    // Double-click inline text editing
    chip.addEventListener('dblclick', e => {
      e.stopPropagation();
      const newText = prompt('Chỉnh sửa phụ đề:', sub.text);
      if (newText !== null && newText.trim() !== '') {
        sub.text = newText.trim();
        if (customSrtText) customSrtText.value = generateSrtFromSubtitles();
        renderTimelineUI();
        updateInspector();
      }
    });

    tlSubTransTrack.appendChild(chip);
  });

  // 3. Render Track 2: Source Subtitles (Original Language)
  if (tlSubOrigTrack) {
    tlSubOrigTrack.innerHTML = '';
    const srcList = state.sourceSubtitles.length > 0 ? state.sourceSubtitles : state.subtitles;
    srcList.forEach((sub, idx) => {
      const origTxt = sub.original_text || sub.text;
      if (!origTxt) return;
      const dur = Math.max(0.3, sub.end - sub.start);
      const chip = document.createElement('div');
      chip.className = 'tl-sub-chip-orig';
      chip.style.left = (sub.start * pxPerSec) + 'px';
      chip.style.width = Math.max(25, (dur * pxPerSec) - 3) + 'px';
      chip.title = `[Gốc] ${origTxt}`;
      chip.innerHTML = `<span style="font-size:9.5px;color:#d8b4fe">🌐</span> <span>${origTxt}</span>`;
      chip.addEventListener('click', e => {
        e.stopPropagation();
        state.selectedType = 'subtitle';
        state.selectedIdx = idx;
        renderTimelineUI();
        updateInspector();
        seekTo(sub.start);
      });
      tlSubOrigTrack.appendChild(chip);
    });
  }

  // 4. Render Track 3: Visuals / Images with Ken Burns badges & Transition Connectors
  tlImgTrack.innerHTML = '';
  state.imagesData.forEach((item, idx) => {
    const card = document.createElement('div');
    card.className = `tl-img-card ${state.selectedType === 'image' && idx === state.selectedIdx ? 'active' : ''}`;
    card.style.left = (item.startTime * pxPerSec) + 'px';
    card.style.width = Math.max(38, (item.duration * pxPerSec) - 3) + 'px';
    card.draggable = true;

    const curEff = item.effect || 'zoom_in';
    const isNone = curEff === 'none' || curEff === 'static';
    const thumb = item.imageUrl ? `<img src="${item.imageUrl}" class="tl-img-thumb" />` : `<div class="tl-img-thumb" style="display:flex;align-items:center;justify-content:center;font-size:15px">🖼️</div>`;

    card.innerHTML = `
      ${thumb}
      <div class="tl-img-info">
        <span class="tl-img-title">${item.sceneId}</span>
        <div class="tl-img-meta">
          <span class="tl-img-dur">${item.duration.toFixed(1)}s</span>
          <select class="tl-img-effect-select" data-idx="${idx}" title="Đổi hiệu ứng chuyển động cho ảnh này">
            <option value="none" ${isNone ? 'selected' : ''}>⏸️ Tĩnh</option>
            <option value="zoom_in" ${curEff === 'zoom_in' ? 'selected' : ''}>🔍 Zoom In</option>
            <option value="zoom_out" ${curEff === 'zoom_out' ? 'selected' : ''}>🔍 Zoom Out</option>
            <option value="pan_lr" ${curEff === 'pan_lr' ? 'selected' : ''}>↔️ Pan L-R</option>
            <option value="pan_rl" ${curEff === 'pan_rl' ? 'selected' : ''}>↔️ Pan R-L</option>
            <option value="tilt_ud" ${curEff === 'tilt_ud' ? 'selected' : ''}>↕️ Tilt U-D</option>
            <option value="tilt_du" ${curEff === 'tilt_du' ? 'selected' : ''}>↕️ Tilt D-U</option>
          </select>
        </div>
      </div>
      <div class="tl-resizer-handle" title="Kéo mép để co giãn thời lượng"></div>
    `;

    // Quick Effect Select Event on Card
    const effSel = card.querySelector('.tl-img-effect-select');
    if (effSel) {
      effSel.addEventListener('click', e => e.stopPropagation());
      effSel.addEventListener('mousedown', e => e.stopPropagation());
      effSel.addEventListener('change', e => {
        e.stopPropagation();
        item.effect = effSel.value;
        if (state.selectedType === 'image' && state.selectedIdx === idx && inspSceneEffect) {
          inspSceneEffect.value = item.effect;
        }
        updateEffectsHidden();
        updateMonitor(state.currentTime);
      });
    }

    // Resizer Handle Mouse Events
    const resizer = card.querySelector('.tl-resizer-handle');
    if (resizer) {
      resizer.addEventListener('mousedown', e => {
        e.stopPropagation();
        e.preventDefault();
        startResizingDuration(e, idx);
      });
    }

    // Drag and Drop Reordering
    card.addEventListener('dragstart', e => {
      state.draggedSceneIdx = idx;
      e.dataTransfer.setData('text/plain', idx);
      card.style.opacity = '0.5';
    });
    card.addEventListener('dragend', () => {
      card.style.opacity = '1';
      $$('.tl-img-card').forEach(c => c.classList.remove('drag-over-left', 'drag-over-right'));
    });
    card.addEventListener('dragover', e => {
      e.preventDefault();
      const rect = card.getBoundingClientRect();
      const mid = rect.left + rect.width / 2;
      card.classList.toggle('drag-over-left', e.clientX < mid);
      card.classList.toggle('drag-over-right', e.clientX >= mid);
    });
    card.addEventListener('dragleave', () => {
      card.classList.remove('drag-over-left', 'drag-over-right');
    });
    card.addEventListener('drop', e => {
      e.preventDefault();
      card.classList.remove('drag-over-left', 'drag-over-right');
      const fromIdx = state.draggedSceneIdx;
      const toIdx = idx;
      if (fromIdx !== null && fromIdx !== toIdx) {
        reorderImages(fromIdx, toIdx);
      }
    });

    // Card selection
    card.addEventListener('click', e => {
      e.stopPropagation();
      state.selectedType = 'image';
      state.selectedIdx = idx;
      renderTimelineUI();
      updateInspector();
      seekTo(item.startTime);
    });

    tlImgTrack.appendChild(card);

    // Transition Connector icon between clips
    if (idx < state.imagesData.length - 1) {
      const conn = document.createElement('div');
      conn.className = 'tl-transition-connector';
      conn.style.left = (item.endTime * pxPerSec - 11) + 'px';
      conn.title = 'Chuyển cảnh hòa tan (Cross Dissolve 0.5s)';
      conn.textContent = '⧓';
      conn.addEventListener('click', e => {
        e.stopPropagation();
        alert(`Chuyển cảnh giữa ${item.sceneId} và Scene tiếp theo: Cross Dissolve (0.5s)`);
      });
      tlImgTrack.appendChild(conn);
    }
  });

  // 5. Render Track 5: Voice Audio Waveform Track
  const tlAudioTrack = $('tl-audio-track');
  if (tlAudioTrack) {
    tlAudioTrack.style.width = totalWidth + 'px';
    const hasAudio = state.audio || state.ttsAudioUrl || state.ttsJobId;
    if (hasAudio) {
      const audioDur = state.audioDuration || (state.audioEl && state.audioEl.duration) || totalDur;
      const clipWidth = Math.max(80, (audioDur * pxPerSec) - 2);
      const audioLabel = (state.audio && state.audio.name) || state.audioName || 'Voice AI Audio';

      let clipCard = tlAudioTrack.querySelector('.tl-audio-clip-card');
      if (!clipCard) {
        tlAudioTrack.innerHTML = `
          <div class="tl-audio-clip-card" style="left:0px;width:${clipWidth}px;">
            <div class="tl-audio-clip-inner">
              <span class="tl-audio-clip-icon">🎙️</span>
              <span class="tl-audio-clip-title">${audioLabel}</span>
              <span class="tl-audio-clip-dur">${formatSecs(audioDur)}</span>
            </div>
            <div id="tl-wavesurfer-container" class="tl-wavesurfer-container"></div>
          </div>
        `;
      } else {
        clipCard.style.width = `${clipWidth}px`;
        const titleEl = clipCard.querySelector('.tl-audio-clip-title');
        const durEl = clipCard.querySelector('.tl-audio-clip-dur');
        if (titleEl) titleEl.textContent = audioLabel;
        if (durEl) durEl.textContent = formatSecs(audioDur);
      }
    } else {
      tlAudioTrack.innerHTML = `<div id="tl-wavesurfer-container" class="tl-wavesurfer-container"></div>`;
    }
  }

  // Update Toolbar Badges & Inspector sublist
  if (tlTotalBadge) {
    tlTotalBadge.textContent = `${state.imagesData.length} Ảnh · ${state.subtitles.length} Câu Sub · ~${totalDur.toFixed(1)}s`;
  }
  if (inspSubCount) {
    inspSubCount.textContent = state.subtitles.length;
  }
  if (mcTimeTotal) {
    mcTimeTotal.textContent = formatSecs(totalDur);
  }

  // Update Mini-map Navigator and Bilingual Sublist
  updateMiniMapNavigator(totalDur);
  renderBilingualSublist();
}

/**
 * Reorder Images on Timeline Track 2
 */
function reorderImages(fromIdx, toIdx) {
  const movedItem = state.imagesData.splice(fromIdx, 1)[0];
  state.imagesData.splice(toIdx, 0, movedItem);

  if (state.images.length > 0) {
    const movedImg = state.images.splice(fromIdx, 1)[0];
    if (movedImg) state.images.splice(toIdx, 0, movedImg);
    renderImageList();
  }

  // Recalculate IDs and timestamps
  let curT = 0.0;
  state.imagesData.forEach((d, i) => {
    d.index = i;
    d.sceneId = `scene_${String(i + 1).padStart(3, '0')}`;
    d.startTime = curT;
    d.endTime = curT + d.duration;
    curT += d.duration;
  });

  state.selectedIdx = toIdx;
  renderTimelineUI();
  updateInspector();
  updateMonitor(state.currentTime);
  updateDurationsHidden();
  updateEffectsHidden();
}

/**
 * Interactive Edge Resizing for Image Duration
 */
function startResizingDuration(e, idx) {
  state.isDraggingResizer = true;
  const startX = e.clientX;
  const item = state.imagesData[idx];
  const initialDur = item.duration;
  const pxPerSec = state.zoomPxPerSec;

  function onMouseMove(moveEvent) {
    const deltaX = moveEvent.clientX - startX;
    const newDur = Math.max(0.5, Math.round((initialDur + deltaX / pxPerSec) * 10) / 10);
    item.duration = newDur;

    // Recalculate subsequent timestamps
    let curT = 0.0;
    state.imagesData.forEach(d => {
      d.startTime = curT;
      d.endTime = curT + d.duration;
      curT += d.duration;
    });

    renderTimelineUI();
    updateInspector();
    updateMonitor(state.currentTime);
  }

  function onMouseUp() {
    state.isDraggingResizer = false;
    window.removeEventListener('mousemove', onMouseMove);
    window.removeEventListener('mouseup', onMouseUp);
    updateDurationsHidden();
  }

  window.addEventListener('mousemove', onMouseMove);
  window.addEventListener('mouseup', onMouseUp);
}

/**
 * Interactive Drag to Move Subtitle Timing
 */
function startDraggingSubtitle(e, idx) {
  if (e.button !== 0) return;
  state.isDraggingResizer = true;
  const startX = e.clientX;
  const sub = state.subtitles[idx];
  if (!sub) return;
  const origStart = sub.start;
  const origEnd = sub.end;
  const dur = origEnd - origStart;
  const pxPerSec = state.zoomPxPerSec;

  function onMouseMove(moveEvent) {
    const deltaX = moveEvent.clientX - startX;
    const deltaSec = deltaX / pxPerSec;
    const newStart = Math.max(0, Math.round((origStart + deltaSec) * 20) / 20);
    sub.start = newStart;
    sub.end = Math.round((newStart + dur) * 20) / 20;

    renderTimelineUI();
    updateInspector();
    updateMonitor(state.currentTime);
  }

  function onMouseUp() {
    state.isDraggingResizer = false;
    window.removeEventListener('mousemove', onMouseMove);
    window.removeEventListener('mouseup', onMouseUp);
    if (customSrtText) customSrtText.value = generateSrtFromSubtitles();
  }

  window.addEventListener('mousemove', onMouseMove);
  window.addEventListener('mouseup', onMouseUp);
}

/**
 * Interactive Edge Trim for Subtitle Duration
 */
function startResizingSubtitle(e, idx, edge) {
  if (e.button !== 0) return;
  state.isDraggingResizer = true;
  const startX = e.clientX;
  const sub = state.subtitles[idx];
  if (!sub) return;
  const origStart = sub.start;
  const origEnd = sub.end;
  const pxPerSec = state.zoomPxPerSec;

  function onMouseMove(moveEvent) {
    const deltaX = moveEvent.clientX - startX;
    const deltaSec = deltaX / pxPerSec;
    if (edge === 'left') {
      const newStart = Math.max(0, Math.min(origEnd - 0.2, Math.round((origStart + deltaSec) * 20) / 20));
      sub.start = newStart;
    } else {
      const newEnd = Math.max(sub.start + 0.2, Math.round((origEnd + deltaSec) * 20) / 20);
      sub.end = newEnd;
    }

    renderTimelineUI();
    updateInspector();
    updateMonitor(state.currentTime);
  }

  function onMouseUp() {
    state.isDraggingResizer = false;
    window.removeEventListener('mousemove', onMouseMove);
    window.removeEventListener('mouseup', onMouseUp);
    if (customSrtText) customSrtText.value = generateSrtFromSubtitles();
  }

  window.addEventListener('mousemove', onMouseMove);
  window.addEventListener('mouseup', onMouseUp);
}

const inspSceneEffect = $('insp-scene-effect');
if (inspSceneEffect) {
  inspSceneEffect.addEventListener('change', () => {
    if (state.selectedType === 'image') {
      const item = state.imagesData[state.selectedIdx];
      if (item) {
        item.effect = inspSceneEffect.value;
        renderTimelineUI();
        updateEffectsHidden();
      }
    }
  });
}

const btnApplyEffectAll = $('btn-apply-effect-all');
if (btnApplyEffectAll) {
  btnApplyEffectAll.addEventListener('click', () => {
    if (state.imagesData.length > 0) {
      const eff = inspSceneEffect ? inspSceneEffect.value : 'zoom_in';
      state.imagesData.forEach(item => item.effect = eff);
      renderTimelineUI();
      updateEffectsHidden();
      updateMonitor(state.currentTime);
      showToast(`✨ Đã áp dụng hiệu ứng ${EFFECT_LABELS[eff] || eff} cho toàn bộ ${state.imagesData.length} ảnh!`, 'success');
    }
  });
}

const btnTlRandomEffects = $('btn-tl-random-effects');
if (btnTlRandomEffects) {
  btnTlRandomEffects.addEventListener('click', () => {
    randomizeAllImageEffects();
  });
}

const btnTlStaticAll = $('btn-tl-static-all');
if (btnTlStaticAll) {
  btnTlStaticAll.addEventListener('click', () => {
    if (state.imagesData.length > 0) {
      state.imagesData.forEach(item => item.effect = 'none');
      renderTimelineUI();
      updateInspector();
      updateEffectsHidden();
      updateMonitor(state.currentTime);
      showToast('⏸️ Đã chuyển toàn bộ ảnh sang chế độ Ảnh Tĩnh (không chuyển động)!', 'info');
    }
  });
}

const inspTypeIcon      = $('insp-type-icon');
const inspTypeLabel     = $('insp-type-label');
const inspSubFields     = $('insp-sub-fields');
const inspImgFields     = $('insp-img-fields');
const inspAudioFields   = $('insp-audio-fields');
const inspDurGroup      = $('insp-dur-group');
const inspDurLabel      = $('insp-dur-label');

function updateInspector() {
  if (state.selectedType === 'audio') {
    if (inspectorEmpty) inspectorEmpty.style.display = 'none';
    if (inspectorContent) inspectorContent.style.display = 'flex';

    if (inspTypeIcon) inspTypeIcon.textContent = '🎙️';
    if (inspTypeLabel) inspTypeLabel.textContent = 'Giọng Đọc (Voice AI):';
    if (inspSceneId) inspSceneId.textContent = (state.audio && state.audio.name) || state.audioName || 'Voice Track';

    if (inspImgFields) inspImgFields.style.display = 'none';
    if (inspSubFields) inspSubFields.style.display = 'none';
    if (inspAudioFields) inspAudioFields.style.display = 'flex';
    if (inspDurGroup) inspDurGroup.style.display = 'none';

    const audioDur = state.audioDuration || (state.audioEl && state.audioEl.duration) || 0;
    if (inspTimeRange) inspTimeRange.textContent = `00:00.00 → ${formatSecs(audioDur)}`;
  } else if (state.selectedType === 'image') {
    const item = state.imagesData[state.selectedIdx];
    if (!item) {
      if (inspectorEmpty) inspectorEmpty.style.display = 'block';
      if (inspectorContent) inspectorContent.style.display = 'none';
      return;
    }
    if (inspectorEmpty) inspectorEmpty.style.display = 'none';
    if (inspectorContent) inspectorContent.style.display = 'flex';

    if (inspTypeIcon) inspTypeIcon.textContent = '🖼️';
    if (inspTypeLabel) inspTypeLabel.textContent = 'Hình Ảnh:';
    if (inspSceneId) inspSceneId.textContent = item.sceneId;

    if (inspSubFields) inspSubFields.style.display = 'none';
    if (inspAudioFields) inspAudioFields.style.display = 'none';
    if (inspImgFields) inspImgFields.style.display = 'flex';
    if (inspDurGroup) inspDurGroup.style.display = 'flex';

    const bulkDurActions = $('insp-bulk-dur-actions');
    const btnApplyAll = $('btn-insp-apply-all-dur');
    if (bulkDurActions) bulkDurActions.style.display = 'flex';
    if (btnApplyAll) btnApplyAll.textContent = `🌐 Áp dụng cho TẤT CẢ ${state.imagesData.length} ảnh`;

    if (inspSceneEffect) inspSceneEffect.value = item.effect || 'zoom_in';
    if (inspDurLabel) inspDurLabel.textContent = '⏱️ Thời lượng hiển thị ảnh:';
    if (inspSceneDur) inspSceneDur.value = item.duration.toFixed(1);
    if (inspTimeRange) inspTimeRange.textContent = `${formatSecs(item.startTime)} → ${formatSecs(item.endTime)}`;
  } else {
    const sub = state.subtitles[state.selectedIdx];
    if (!sub) {
      if (inspectorEmpty) inspectorEmpty.style.display = 'block';
      if (inspectorContent) inspectorContent.style.display = 'none';
      return;
    }
    if (inspectorEmpty) inspectorEmpty.style.display = 'none';
    if (inspectorContent) inspectorContent.style.display = 'flex';

    if (inspTypeIcon) inspTypeIcon.textContent = '🔤';
    if (inspTypeLabel) inspTypeLabel.textContent = 'Phụ Đề:';
    if (inspSceneId) inspSceneId.textContent = `#${sub.id || (state.selectedIdx + 1)}`;

    if (inspImgFields) inspImgFields.style.display = 'none';
    if (inspAudioFields) inspAudioFields.style.display = 'none';
    if (inspSubFields) inspSubFields.style.display = 'flex';
    if (inspDurGroup) inspDurGroup.style.display = 'flex';

    const bulkDurActions = $('insp-bulk-dur-actions');
    if (bulkDurActions) bulkDurActions.style.display = 'none';

    if (inspSubtitleText) inspSubtitleText.value = sub.text;
    if (inspSubtitleOrigText) inspSubtitleOrigText.value = sub.original_text || (state.sourceSubtitles[state.selectedIdx] ? state.sourceSubtitles[state.selectedIdx].text : '');
    if (inspDurLabel) inspDurLabel.textContent = '⏱️ Thời lượng câu thoại:';
    if (inspSceneDur) inspSceneDur.value = (sub.end - sub.start).toFixed(1);
    if (inspTimeRange) inspTimeRange.textContent = `${formatSecs(sub.start)} → ${formatSecs(sub.end)}`;
  }
}

// ─── CapCut Split Clip Tool (Phím S / Cmd+B) ─────────────────────────────────
function splitSelectedClip() {
  saveUndoSnapshot();
  const curTime = state.currentTime;
  if (state.selectedType === 'image') {
    const idx = state.imagesData.findIndex(img => curTime > img.startTime && curTime < img.endTime);
    if (idx === -1) return;
    const target = state.imagesData[idx];
    const firstDur = curTime - target.startTime;
    const secondDur = target.endTime - curTime;
    if (firstDur < 0.2 || secondDur < 0.2) return;

    target.duration = Math.round(firstDur * 10) / 10;
    const duplicated = {
      index: idx + 1,
      sceneId: `${target.sceneId}_b`,
      imageFile: target.imageFile,
      imageUrl: target.imageUrl,
      duration: Math.round(secondDur * 10) / 10,
      effect: target.effect,
      startTime: curTime,
      endTime: curTime + secondDur
    };

    state.imagesData.splice(idx + 1, 0, duplicated);

    // Recalculate timestamps
    let curT = 0.0;
    state.imagesData.forEach((d, i) => {
      d.index = i;
      d.startTime = curT;
      d.endTime = curT + d.duration;
      curT += d.duration;
    });

    state.selectedIdx = idx + 1;
    renderTimelineUI();
    updateInspector();
    updateDurationsHidden();
    updateEffectsHidden();
  } else if (state.selectedType === 'subtitle') {
    const idx = state.subtitles.findIndex(sub => curTime > sub.start && curTime < sub.end);
    if (idx === -1) return;
    const target = state.subtitles[idx];
    const words = target.text.split(' ');
    const mid = Math.max(1, Math.floor(words.length / 2));
    const text1 = words.slice(0, mid).join(' ');
    const text2 = words.slice(mid).join(' ');

    const splitTime = curTime;
    const oldEnd = target.end;
    target.end = splitTime;
    target.text = text1;

    const newSub = {
      id: state.subtitles.length + 1,
      start: splitTime,
      end: oldEnd,
      text: text2,
      original_text: target.original_text || ''
    };

    state.subtitles.splice(idx + 1, 0, newSub);
    if (customSrtText) customSrtText.value = generateSrtFromSubtitles();
    state.selectedIdx = idx + 1;
    renderTimelineUI();
    updateInspector();
  }
}

if (btnTlSplit) btnTlSplit.addEventListener('click', splitSelectedClip);

// Magnetic Snap Toggle
if (btnTlSnap) {
  btnTlSnap.addEventListener('click', () => {
    state.isMagnetic = !state.isMagnetic;
    btnTlSnap.classList.toggle('active', state.isMagnetic);
    btnTlSnap.style.color = state.isMagnetic ? '#10b981' : '#64748b';
  });
}

// Bilingual Toggle
if (btnTlBilingualToggle) {
  btnTlBilingualToggle.addEventListener('click', () => {
    state.isBilingual = !state.isBilingual;
    if (tlSubOrigRow) tlSubOrigRow.style.display = state.isBilingual ? 'flex' : 'none';
    btnTlBilingualToggle.classList.toggle('active', state.isBilingual);
    btnTlBilingualToggle.style.color = state.isBilingual ? '#c084fc' : '#64748b';
  });
}

// ─── Automatic Translation Engine Handler ────────────────────────────────────
async function runSubtitleTranslation() {
  if (state.subtitles.length === 0) {
    alert('⚠️ Chưa có phụ đề để dịch. Vui lòng nạp kịch bản hoặc chạy AutoSub trước.');
    return;
  }

  if (btnTlTranslate) {
    btnTlTranslate.disabled = true;
    btnTlTranslate.textContent = '⏳ Đang dịch...';
  }

  try {
    const res = await fetch('/api/subtitles/translate', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        subtitles: state.subtitles,
        source_lang: 'auto',
        target_lang: 'vi'
      })
    });
    const data = await res.json();
    if (data.success && data.subtitles) {
      state.sourceSubtitles = state.subtitles.map(s => ({ ...s }));
      state.subtitles = data.subtitles;
      if (customSrtText) customSrtText.value = generateSrtFromSubtitles();
      renderTimelineUI();
      updateInspector();
      alert(`✅ Đã dịch thành công ${data.subtitles.length} câu phụ đề sang Tiếng Việt!`);
    } else {
      alert('Lỗi dịch: ' + (data.error || 'Không xác định'));
    }
  } catch (err) {
    alert('Lỗi kết nối dịch: ' + err.message);
  } finally {
    if (btnTlTranslate) {
      btnTlTranslate.disabled = false;
      btnTlTranslate.textContent = '🌐 Dịch sang Tiếng Việt';
    }
  }
}

if (btnTlTranslate) btnTlTranslate.addEventListener('click', runSubtitleTranslation);
if (btnSublistTranslateAll) btnSublistTranslateAll.addEventListener('click', runSubtitleTranslation);
const btnDashTranslate = $('btn-dash-translate');
if (btnDashTranslate) btnDashTranslate.addEventListener('click', runSubtitleTranslation);

// ─── Bilingual Subtitle List Inspector (Option C) ───────────────────────────
function renderBilingualSublist() {
  if (!bilingualSublistScroll) return;
  const query = sublistSearchInp ? sublistSearchInp.value.toLowerCase().trim() : '';
  bilingualSublistScroll.innerHTML = '';

  if (state.subtitles.length === 0) {
    bilingualSublistScroll.innerHTML = '<div style="color:#64748b;font-size:12px;text-align:center;padding:20px 0">Chưa có dữ liệu phụ đề.</div>';
    return;
  }

  state.subtitles.forEach((sub, idx) => {
    const origTxt = sub.original_text || (state.sourceSubtitles[idx] ? state.sourceSubtitles[idx].text : '');
    const viTxt = sub.text || '';

    if (query && !viTxt.toLowerCase().includes(query) && !origTxt.toLowerCase().includes(query)) {
      return;
    }

    const row = document.createElement('div');
    row.className = `sublist-row ${state.selectedType === 'subtitle' && idx === state.selectedIdx ? 'active' : ''}`;
    row.innerHTML = `
      <div class="sublist-header">
        <span>#${idx + 1} · ${formatSecs(sub.start)} - ${formatSecs(sub.end)}</span>
        <span style="color:#38bdf8;font-size:10px">▶ Nhảy tới</span>
      </div>
      <div class="sublist-trans-text">${viTxt}</div>
      ${origTxt ? `<div class="sublist-orig-text">${origTxt}</div>` : ''}
    `;

    row.addEventListener('click', () => {
      state.selectedType = 'subtitle';
      state.selectedIdx = idx;
      renderTimelineUI();
      updateInspector();
      seekTo(sub.start);
    });

    bilingualSublistScroll.appendChild(row);
  });
}

if (sublistSearchInp) {
  sublistSearchInp.addEventListener('input', renderBilingualSublist);
}

// Inspector Tabs Toggling
if (inspTabPropsBtn && inspTabSublistBtn) {
  inspTabPropsBtn.addEventListener('click', () => {
    inspTabPropsBtn.style.background = '#334155';
    inspTabPropsBtn.style.color = '#38bdf8';
    inspTabSublistBtn.style.background = 'transparent';
    inspTabSublistBtn.style.color = '#94a3b8';
    if (inspPaneProps) inspPaneProps.style.display = 'block';
    if (inspPaneSublist) inspPaneSublist.style.display = 'none';
  });

  inspTabSublistBtn.addEventListener('click', () => {
    inspTabSublistBtn.style.background = '#334155';
    inspTabSublistBtn.style.color = '#38bdf8';
    inspTabPropsBtn.style.background = 'transparent';
    inspTabPropsBtn.style.color = '#94a3b8';
    if (inspPaneProps) inspPaneProps.style.display = 'none';
    if (inspPaneSublist) inspPaneSublist.style.display = 'block';
    renderBilingualSublist();
  });
}

// ─── Mini-map Navigator Scrubber (30-Minute Video Pan) ──────────────────────
function updateMiniMapNavigator(totalDur) {
  if (!tlMinimapHandle || !tlMinimapWrap) return;
  const viewport = $('timeline-viewport');
  if (!viewport) return;
  const viewWidth = viewport.clientWidth;
  const scrollWidth = viewport.scrollWidth;
  const scrollLeft = viewport.scrollLeft;

  const mapWidth = tlMinimapWrap.clientWidth;
  if (scrollWidth <= 0 || mapWidth <= 0) return;

  const handleWidth = Math.max(25, (viewWidth / scrollWidth) * mapWidth);
  const handleLeft = (scrollLeft / scrollWidth) * mapWidth;

  tlMinimapHandle.style.width = handleWidth + 'px';
  tlMinimapHandle.style.left = handleLeft + 'px';
}

if (tlMinimapWrap) {
  tlMinimapWrap.addEventListener('click', e => {
    const rect = tlMinimapWrap.getBoundingClientRect();
    const clickX = e.clientX - rect.left;
    const mapWidth = rect.width;
    const viewport = $('timeline-viewport');
    if (viewport && mapWidth > 0) {
      const targetScroll = (clickX / mapWidth) * viewport.scrollWidth - viewport.clientWidth / 2;
      viewport.scrollLeft = Math.max(0, targetScroll);
    }
  });
}

// ─── Studio Left Drawer Engine ──────────────────────────────────────────────
const dnavBtns = $$('.dnav-btn');
const dpaneItems = $$('.dpane-item');
const drawerImgList = $('drawer-img-list');
const drawerImgCount = $('drawer-img-count');

dnavBtns.forEach(btn => {
  btn.addEventListener('click', () => {
    dnavBtns.forEach(b => b.classList.toggle('active', b === btn));
    const targetPaneId = btn.dataset.pane;
    dpaneItems.forEach(p => {
      p.style.display = p.id === targetPaneId ? 'flex' : 'none';
    });
    if (targetPaneId === 'dpane-media') renderDrawerImageList();
  });
});

function renderDrawerImageList() {
  if (!drawerImgList) return;
  if (drawerImgCount) drawerImgCount.textContent = state.images.length;
  if (state.images.length === 0) {
    drawerImgList.innerHTML = '<div class="drawer-empty-msg">Chưa có ảnh nào.</div>';
    return;
  }
  drawerImgList.innerHTML = '';
  state.images.forEach((f, idx) => {
    const item = document.createElement('div');
    item.className = 'drawer-img-item';
    const url = URL.createObjectURL(f);
    item.innerHTML = `
      <img src="${url}" alt="Img ${idx + 1}" />
      <span class="drawer-img-label">#${idx + 1}</span>
    `;
    item.addEventListener('click', () => {
      state.selectedType = 'image';
      state.selectedIdx = Math.min(idx, state.imagesData.length - 1);
      renderTimelineUI();
      updateInspector();
      if (state.imagesData[state.selectedIdx]) {
        seekTo(state.imagesData[state.selectedIdx].startTime);
      }
    });
    drawerImgList.appendChild(item);
  });
}

// Drawer Subtitle Style Templates
$$('.dtpl-card').forEach(card => {
  card.addEventListener('click', () => {
    $$('.dtpl-card').forEach(c => c.classList.toggle('active', c === card));
    const tpl = card.dataset.tpl;
    const monitorSub = $('monitor-subtitle');
    if (monitorSub) {
      if (tpl === 'tiktok') {
        monitorSub.style.background = 'rgba(0,0,0,0.85)';
        monitorSub.style.color = '#ffffff';
        monitorSub.style.border = '1px solid rgba(255,255,255,0.2)';
      } else if (tpl === 'yellow') {
        monitorSub.style.background = '#000000';
        monitorSub.style.color = '#facc15';
        monitorSub.style.border = '1px solid #facc15';
      } else if (tpl === 'bilingual') {
        monitorSub.style.background = 'rgba(0,0,0,0.85)';
        monitorSub.style.color = '#38bdf8';
        monitorSub.style.border = 'none';
      }
    }
  });
});

// Drawer Action Shortcuts
const btnDrawerTts = $('btn-drawer-tts');
if (btnDrawerTts && ttsGenBtn) {
  btnDrawerTts.addEventListener('click', () => ttsGenBtn.click());
}
const btnDrawerAutosub = $('btn-drawer-autosub');
if (btnDrawerAutosub && btnTlAutosub) {
  btnDrawerAutosub.addEventListener('click', () => btnTlAutosub.click());
}
const btnDrawerTranslate = $('btn-drawer-translate');
if (btnDrawerTranslate) {
  btnDrawerTranslate.addEventListener('click', runSubtitleTranslation);
}

// ─── Monitor Aspect Ratio & Fullscreen Controls ─────────────────────────────
const selMonitorRatio = $('sel-monitor-ratio');
const previewScreen = $('preview-screen');
if (selMonitorRatio && previewScreen) {
  selMonitorRatio.addEventListener('change', () => {
    const val = selMonitorRatio.value;
    previewScreen.className = `preview-screen aspect-${val.replace(':', '-')}`;
    const hidRatio = $('hid-aspect-ratio');
    if (hidRatio) hidRatio.value = val;
  });
}

const btnMonitorFullscreen = $('btn-monitor-fullscreen');
if (btnMonitorFullscreen && previewScreen) {
  btnMonitorFullscreen.addEventListener('click', () => {
    if (!document.fullscreenElement) {
      previewScreen.requestFullscreen().catch(e => console.warn(e));
    } else {
      document.exitFullscreen().catch(e => console.warn(e));
    }
  });
}

// ─── Audio Inspector Controls (Volume & Speed) ──────────────────────────────
const slAudioVol = $('sl-audio-vol');
const inspAudioVolVal = $('insp-audio-vol-val');
if (slAudioVol) {
  slAudioVol.addEventListener('input', () => {
    const vol = parseFloat(slAudioVol.value) || 100;
    if (inspAudioVolVal) inspAudioVolVal.textContent = `${vol}%`;
    const normVol = Math.min(1.0, vol / 100);
    if (wavesurfer && typeof wavesurfer.setVolume === 'function') {
      wavesurfer.setVolume(normVol);
    }
    if (state.audioEl) {
      state.audioEl.volume = normVol;
    }
  });
}

const audioSpeedBtns = $$('#audio-speed-group .bopt');
audioSpeedBtns.forEach(btn => {
  btn.addEventListener('click', () => {
    audioSpeedBtns.forEach(b => b.classList.toggle('active', b === btn));
    const spd = parseFloat(btn.dataset.spd) || 1.0;
    if (wavesurfer && typeof wavesurfer.setPlaybackRate === 'function') {
      wavesurfer.setPlaybackRate(spd);
    }
    if (state.audioEl) {
      state.audioEl.playbackRate = spd;
    }
  });
});

// ─── Undo Action Support ───────────────────────────────────────────────────
state.undoStack = [];
function saveUndoSnapshot() {
  state.undoStack.push({
    imagesData: JSON.parse(JSON.stringify(state.imagesData)),
    subtitles: JSON.parse(JSON.stringify(state.subtitles))
  });
  if (state.undoStack.length > 25) state.undoStack.shift();
}

const btnTlUndo = $('btn-tl-undo');
if (btnTlUndo) {
  btnTlUndo.addEventListener('click', () => {
    if (state.undoStack.length === 0) return;
    const snap = state.undoStack.pop();
    state.imagesData = snap.imagesData;
    state.subtitles = snap.subtitles;
    if (customSrtText) customSrtText.value = generateSrtFromSubtitles();
    renderTimelineUI();
    updateInspector();
    updateMonitor(state.currentTime);
  });
}

// Inspector duration buttons
if (btnDurPlus) btnDurPlus.addEventListener('click', () => adjustSelectedDuration(0.5));
if (btnDurMinus) btnDurMinus.addEventListener('click', () => adjustSelectedDuration(-0.5));
if (inspSceneDur) inspSceneDur.addEventListener('change', () => {
  const val = parseFloat(inspSceneDur.value) || 3.0;
  setSelectedDuration(val);
});

if (inspSubtitleText) inspSubtitleText.addEventListener('input', () => {
  if (state.selectedType === 'subtitle') {
    const sub = state.subtitles[state.selectedIdx];
    if (sub) {
      sub.text = inspSubtitleText.value;
      if (customSrtText) customSrtText.value = generateSrtFromSubtitles();
      renderTimelineUI();
      updateMonitor(state.currentTime);
    }
  }
});

if (inspSubtitleOrigText) inspSubtitleOrigText.addEventListener('input', () => {
  if (state.selectedType === 'subtitle') {
    const sub = state.subtitles[state.selectedIdx];
    if (sub) {
      sub.original_text = inspSubtitleOrigText.value;
      if (state.sourceSubtitles && state.sourceSubtitles[state.selectedIdx]) {
        state.sourceSubtitles[state.selectedIdx].text = inspSubtitleOrigText.value;
      }
      renderTimelineUI();
      updateMonitor(state.currentTime);
    }
  }
});

function adjustSelectedDuration(delta) {
  saveUndoSnapshot();
  if (state.selectedType === 'image') {
    const item = state.imagesData[state.selectedIdx];
    if (!item) return;
    setSelectedDuration(Math.max(0.5, item.duration + delta));
  } else {
    const sub = state.subtitles[state.selectedIdx];
    if (!sub) return;
    sub.end = Math.max(sub.start + 0.3, sub.end + delta);
    if (customSrtText) customSrtText.value = generateSrtFromSubtitles();
    renderTimelineUI();
    updateInspector();
    updateMonitor(state.currentTime);
  }
}

function setSelectedDuration(dur) {
  if (state.selectedType === 'image') {
    const item = state.imagesData[state.selectedIdx];
    if (!item) return;
    item.duration = Math.max(0.5, dur);

    let curT = 0.0;
    state.imagesData.forEach(d => {
      d.startTime = curT;
      d.endTime = curT + d.duration;
      curT += d.duration;
    });

    renderTimelineUI();
    updateInspector();
    updateMonitor(state.currentTime);
    updateDurationsHidden();
  }
}

// ─── Bulk Duration & Quick Presets Listeners ─────────────────────────────────
$$('#dur-preset-btns .bopt-mini').forEach(btn => {
  btn.addEventListener('click', () => {
    const dur = parseFloat(btn.dataset.dur) || 3.5;
    saveUndoSnapshot();
    if (state.selectedType === 'image') {
      setSelectedDuration(dur);
    } else if (state.selectedType === 'subtitle') {
      const sub = state.subtitles[state.selectedIdx];
      if (sub) {
        sub.end = sub.start + dur;
        if (customSrtText) customSrtText.value = generateSrtFromSubtitles();
        renderTimelineUI();
        updateInspector();
        updateMonitor(state.currentTime);
      }
    }
  });
});

const btnInspApplyAllDur = $('btn-insp-apply-all-dur');
if (btnInspApplyAllDur) {
  btnInspApplyAllDur.addEventListener('click', () => {
    if (state.imagesData.length === 0) {
      alert('⚠️ Chưa có ảnh nào trên Timeline để áp dụng.');
      return;
    }
    const currentDur = parseFloat(inspSceneDur ? inspSceneDur.value : 3.5) || 3.5;
    saveUndoSnapshot();

    let curT = 0.0;
    state.imagesData.forEach((d, i) => {
      d.duration = currentDur;
      d.startTime = curT;
      d.endTime = curT + currentDur;
      curT += currentDur;
    });

    renderTimelineUI();
    updateInspector();
    updateMonitor(state.currentTime);
    updateDurationsHidden();
    alert(`✅ Đã đặt thời lượng ${currentDur}s cho toàn bộ ${state.imagesData.length} ảnh!`);
  });
}

const btnInspAutofitVoice = $('btn-insp-autofit-voice');
if (btnInspAutofitVoice) {
  btnInspAutofitVoice.addEventListener('click', () => {
    if (state.imagesData.length === 0) {
      alert('⚠️ Chưa có ảnh nào trên Timeline để cân đối.');
      return;
    }
    const totalVoiceDur = state.audioDuration || (state.audioEl && state.audioEl.duration) || 0;
    if (totalVoiceDur <= 0) {
      alert('⚠️ Chưa phát hiện file âm thanh / Voice AI. Vui lòng nạp audio trước.');
      return;
    }
    saveUndoSnapshot();

    const perImgDur = Math.max(0.5, Math.round((totalVoiceDur / state.imagesData.length) * 10) / 10);
    let curT = 0.0;
    state.imagesData.forEach((d, i) => {
      // Last image takes remaining time to match perfectly
      const dur = (i === state.imagesData.length - 1) ? Math.max(0.5, Math.round((totalVoiceDur - curT) * 10) / 10) : perImgDur;
      d.duration = dur;
      d.startTime = curT;
      d.endTime = curT + dur;
      curT += dur;
    });

    renderTimelineUI();
    updateInspector();
    updateMonitor(state.currentTime);
    updateDurationsHidden();
    alert(`✅ Đã cân đối đều ~${perImgDur}s/ảnh cho ${state.imagesData.length} ảnh khớp 100% với Voice AI (${formatSecs(totalVoiceDur)})!`);
  });
}

// ─── Subtitle Timing Nudge Listeners ─────────────────────────────────────────
const btnSubNudgeLeft = $('btn-sub-nudge-left');
const btnSubNudgeRight = $('btn-sub-nudge-right');
const btnSubNudgeAllLeft = $('btn-sub-nudge-all-left');
const btnSubNudgeAllRight = $('btn-sub-nudge-all-right');

if (btnSubNudgeLeft) {
  btnSubNudgeLeft.addEventListener('click', () => {
    if (state.selectedType === 'subtitle') {
      const sub = state.subtitles[state.selectedIdx];
      if (sub && sub.start >= 0.1) {
        saveUndoSnapshot();
        sub.start = Math.max(0, Math.round((sub.start - 0.1) * 20) / 20);
        sub.end = Math.max(sub.start + 0.2, Math.round((sub.end - 0.1) * 20) / 20);
        if (customSrtText) customSrtText.value = generateSrtFromSubtitles();
        renderTimelineUI();
        updateInspector();
        seekTo(sub.start);
      }
    }
  });
}

if (btnSubNudgeRight) {
  btnSubNudgeRight.addEventListener('click', () => {
    if (state.selectedType === 'subtitle') {
      const sub = state.subtitles[state.selectedIdx];
      if (sub) {
        saveUndoSnapshot();
        sub.start = Math.round((sub.start + 0.1) * 20) / 20;
        sub.end = Math.round((sub.end + 0.1) * 20) / 20;
        if (customSrtText) customSrtText.value = generateSrtFromSubtitles();
        renderTimelineUI();
        updateInspector();
        seekTo(sub.start);
      }
    }
  });
}

if (btnSubNudgeAllLeft) {
  btnSubNudgeAllLeft.addEventListener('click', () => {
    if (state.subtitles.length === 0) return;
    saveUndoSnapshot();
    state.subtitles.forEach(s => {
      s.start = Math.max(0, Math.round((s.start - 0.5) * 10) / 10);
      s.end = Math.max(s.start + 0.2, Math.round((s.end - 0.5) * 10) / 10);
    });
    if (customSrtText) customSrtText.value = generateSrtFromSubtitles();
    renderTimelineUI();
    updateInspector();
    updateMonitor(state.currentTime);
  });
}

if (btnSubNudgeAllRight) {
  btnSubNudgeAllRight.addEventListener('click', () => {
    if (state.subtitles.length === 0) return;
    saveUndoSnapshot();
    state.subtitles.forEach(s => {
      s.start = Math.round((s.start + 0.5) * 10) / 10;
      s.end = Math.round((s.end + 0.5) * 10) / 10;
    });
    if (customSrtText) customSrtText.value = generateSrtFromSubtitles();
    renderTimelineUI();
    updateInspector();
    updateMonitor(state.currentTime);
  });
}

// Reset all image durations to uniform
if (btnTlResetDurs) btnTlResetDurs.addEventListener('click', () => {
  const def = parseFloat(inpDuration ? inpDuration.value : 3.5) || 3.5;
  state.imagesData.forEach(d => { d.duration = def; });
  let curT = 0.0;
  state.imagesData.forEach(d => {
    d.startTime = curT;
    d.endTime = curT + d.duration;
    curT += d.duration;
  });
  renderTimelineUI();
  updateInspector();
  updateDurationsHidden();
});

// Zoom slider
if (tlZoomSlider) tlZoomSlider.addEventListener('input', () => {
  state.zoomPxPerSec = parseInt(tlZoomSlider.value, 10) || 60;
  if (wavesurfer && typeof wavesurfer.zoom === 'function') {
    try {
      wavesurfer.zoom(state.zoomPxPerSec);
    } catch(e) {}
  }
  renderTimelineUI();
  updatePlayhead(state.currentTime);
});

// Click on Timeline Viewport to Seek
if (tlViewport) {
  tlViewport.addEventListener('click', e => {
    if (state.isDraggingResizer) return;
    const rect = tlViewport.getBoundingClientRect();
    const clickX = e.clientX - rect.left + tlViewport.scrollLeft - 105;
    if (clickX >= 0) {
      const seekSec = Math.max(0, clickX / state.zoomPxPerSec);
      seekTo(seekSec);
    }
  });

  tlViewport.addEventListener('scroll', () => {
    updateMiniMapNavigator();
    if (wavesurfer && wavesurfer.renderer) {
      try {
        wavesurfer.renderer.setScroll(tlViewport.scrollLeft);
      } catch(e) {}
    }
  });
}

function seekTo(sec) {
  state.currentTime = Math.max(0, sec);
  updatePlayhead(state.currentTime);
  updateMonitor(state.currentTime);
  if (wavesurfer && typeof wavesurfer.setTime === 'function') {
    try {
      wavesurfer.setTime(state.currentTime);
    } catch(e) {}
  } else if (state.audioEl && state.audioEl.src) {
    try {
      state.audioEl.currentTime = state.currentTime;
    } catch(e) {}
  }
}

function updatePlayhead(sec) {
  if (!tlPlayhead) return;
  const leftPx = 105 + (sec * state.zoomPxPerSec);
  tlPlayhead.style.left = leftPx + 'px';
}

// ─── Timeline In/Out Selection (Mark In / Mark Out) ───────────────────────────

function setMarkIn(time) {
  const t = (typeof time === 'number') ? time : state.currentTime;
  if (state.markOut !== null && t >= state.markOut) {
    state.markOut = null;
  }
  state.markIn = Math.max(0, Math.round(t * 100) / 100);
  updateInOutOverlay();
  showToast(`📥 Đã đặt mốc In: ${formatSecs(state.markIn)}`, 'info', 2000);
}

function setMarkOut(time) {
  let t = (typeof time === 'number') ? time : state.currentTime;
  if (state.markIn === null) {
    state.markIn = 0.0;
  }
  if (t <= state.markIn) {
    t = state.markIn + 1.0;
  }
  state.markOut = Math.round(t * 100) / 100;
  updateInOutOverlay();
  const dur = (state.markOut - state.markIn).toFixed(1);
  showToast(`📤 Đã đặt mốc Out: ${formatSecs(state.markOut)} (Vùng chọn: ~${dur}s)`, 'info', 2500);
}

function clearMarkInOut() {
  state.markIn = null;
  state.markOut = null;
  updateInOutOverlay();
  showToast('✖ Đã xóa vùng chọn In/Out', 'info', 1800);
}

function updateInOutOverlay() {
  const overlay = $('tl-in-out-overlay');
  const badge = $('tl-in-out-badge');
  const btnClear = $('btn-tl-clear-in-out');
  const btnRender = $('btn-tl-render-direct');
  if (!overlay) return;

  if (state.markIn === null && state.markOut === null) {
    overlay.style.display = 'none';
    if (badge) badge.style.display = 'none';
    if (btnClear) btnClear.style.display = 'none';
    if (btnRender) btnRender.innerHTML = '🎬 Xuất Video';
    return;
  }

  overlay.style.display = 'block';
  if (btnClear) btnClear.style.display = 'inline-block';

  const pxPerSec = state.zoomPxPerSec;
  const headerW = 105;
  const inT = state.markIn !== null ? state.markIn : 0.0;
  
  // Total timeline duration
  const imgTotalDur = state.imagesData.reduce((acc, d) => acc + d.duration, 0);
  const subTotalDur = state.subtitles.length > 0 ? state.subtitles[state.subtitles.length - 1].end : 0;
  const totalDur = Math.max(imgTotalDur, subTotalDur, 1.0);
  const outT = state.markOut !== null ? state.markOut : totalDur;

  const inPx = headerW + (inT * pxPerSec);
  const outPx = headerW + (outT * pxPerSec);
  const rangeW = Math.max(16, outPx - inPx);

  // Position Left Dim
  const dimL = $('tl-in-out-dim-left');
  if (dimL) {
    dimL.style.left = headerW + 'px';
    dimL.style.width = Math.max(0, inPx - headerW) + 'px';
  }

  // Position Range Box
  const rangeBox = $('tl-in-out-range-box');
  if (rangeBox) {
    rangeBox.style.left = inPx + 'px';
    rangeBox.style.width = rangeW + 'px';
  }

  // Position Right Dim
  const dimR = $('tl-in-out-dim-right');
  if (dimR) {
    dimR.style.left = outPx + 'px';
    dimR.style.right = '0px';
  }

  // Update Badge
  const durSec = Math.max(0, outT - inT).toFixed(1);
  if (badge) {
    badge.style.display = 'inline-block';
    badge.textContent = `🎯 Vùng In/Out: ${formatSecs(inT)} → ${formatSecs(outT)} (~${durSec}s)`;
  }

  if (btnRender) {
    btnRender.innerHTML = `🎯 Xuất Vùng Chọn (~${durSec}s)`;
  }
}

// In/Out Toolbar Buttons & Drag Listeners
const btnMarkIn = $('btn-tl-mark-in');
if (btnMarkIn) btnMarkIn.addEventListener('click', () => setMarkIn());

const btnMarkOut = $('btn-tl-mark-out');
if (btnMarkOut) btnMarkOut.addEventListener('click', () => setMarkOut());

const btnClearInOut = $('btn-tl-clear-in-out');
if (btnClearInOut) btnClearInOut.addEventListener('click', () => clearMarkInOut());

const inHandle = $('tl-in-handle');
if (inHandle) {
  inHandle.addEventListener('mousedown', e => {
    e.stopPropagation();
    state.isDraggingInHandle = true;
    const onMove = ev => {
      if (!state.isDraggingInHandle || !tlViewport) return;
      const rect = tlViewport.getBoundingClientRect();
      const clickX = ev.clientX - rect.left + tlViewport.scrollLeft - 105;
      const newIn = Math.max(0, clickX / state.zoomPxPerSec);
      if (state.markOut === null || newIn < state.markOut) {
        state.markIn = Math.round(newIn * 100) / 100;
        updateInOutOverlay();
      }
    };
    const onUp = () => {
      state.isDraggingInHandle = false;
      window.removeEventListener('mousemove', onMove);
      window.removeEventListener('mouseup', onUp);
    };
    window.addEventListener('mousemove', onMove);
    window.addEventListener('mouseup', onUp);
  });
}

const outHandle = $('tl-out-handle');
if (outHandle) {
  outHandle.addEventListener('mousedown', e => {
    e.stopPropagation();
    state.isDraggingOutHandle = true;
    const onMove = ev => {
      if (!state.isDraggingOutHandle || !tlViewport) return;
      const rect = tlViewport.getBoundingClientRect();
      const clickX = ev.clientX - rect.left + tlViewport.scrollLeft - 105;
      const newOut = Math.max(0, clickX / state.zoomPxPerSec);
      if (state.markIn === null || newOut > state.markIn) {
        state.markOut = Math.round(newOut * 100) / 100;
        updateInOutOverlay();
      }
    };
    const onUp = () => {
      state.isDraggingOutHandle = false;
      window.removeEventListener('mousemove', onMove);
      window.removeEventListener('mouseup', onUp);
    };
    window.addEventListener('mousemove', onMove);
    window.addEventListener('mouseup', onUp);
  });
}

function updateMonitor(time) {
  if (mcTimeCurrent) mcTimeCurrent.textContent = formatSecs(time);

  // 1. Find active image at current time
  const activeImg = state.imagesData.find(d => time >= d.startTime && time < d.endTime) || state.imagesData[state.imagesData.length - 1];

  if (activeImg && activeImg.imageUrl) {
    if (monitorImg) {
      if (monitorImg.src !== activeImg.imageUrl) {
        monitorImg.src = activeImg.imageUrl;
      }
      monitorImg.style.display = 'block';

      // Real-time Live Ken Burns Motion Preview
      const imgDur = Math.max(0.1, activeImg.duration || 5.0);
      const t = Math.max(0, Math.min(1, (time - activeImg.startTime) / imgDur));
      const eff = activeImg.effect || 'zoom_in';

      let transformStr = 'scale(1.0)';
      if (eff === 'zoom_in') {
        const s = 1.0 + 0.18 * t;
        transformStr = `scale(${s.toFixed(3)})`;
      } else if (eff === 'zoom_out') {
        const s = 1.18 - 0.18 * t;
        transformStr = `scale(${s.toFixed(3)})`;
      } else if (eff === 'pan_lr') {
        const tx = (-4 + 8 * t).toFixed(2);
        transformStr = `scale(1.15) translateX(${tx}%)`;
      } else if (eff === 'pan_rl') {
        const tx = (4 - 8 * t).toFixed(2);
        transformStr = `scale(1.15) translateX(${tx}%)`;
      } else if (eff === 'tilt_ud') {
        const ty = (-4 + 8 * t).toFixed(2);
        transformStr = `scale(1.15) translateY(${ty}%)`;
      } else if (eff === 'tilt_du') {
        const ty = (4 - 8 * t).toFixed(2);
        transformStr = `scale(1.15) translateY(${ty}%)`;
      }

      monitorImg.style.transform = transformStr;
    }
    if (monitorPlaceholder) monitorPlaceholder.style.display = 'none';
    if (mcSceneIndicator) {
      mcSceneIndicator.textContent = `Ảnh ${activeImg.index + 1} / ${state.imagesData.length}`;
    }
  } else {
    if (monitorImg) {
      monitorImg.style.display = 'none';
      monitorImg.style.transform = 'scale(1.0)';
    }
    if (monitorPlaceholder) monitorPlaceholder.style.display = 'flex';
  }

  // 2. Find active subtitle at current time (independent acoustic lookup!)
  const activeSub = state.subtitles.find(s => time >= s.start && time < s.end);
  if (activeSub && activeSub.text && activeSub.text.trim()) {
    if (monitorSubtitle) {
      monitorSubtitle.textContent = activeSub.text.trim();
      monitorSubtitle.style.display = 'block';
      applySubtitleStylesToMonitor();
    }
  } else {
    if (monitorSubtitle) monitorSubtitle.style.display = 'none';
  }
}

function applySubtitleStylesToMonitor() {
  if (!monitorSubtitle) return;
  const font = state.subFont || 'paperlogy';
  const size = state.subSize || 36;
  const color = state.subColor || '#ffffff';

  const strokeEnabled = state.subStrokeEnabled !== false;
  const strokeColor = state.subStrokeColor || '#000000';
  const strokeWidth = state.subStrokeWidth || 4;

  const bgEnabled = state.subBgEnabled === true;
  const bgColor = state.subBgColor || '#000000';
  const bgOpacity = ((state.subBgOpacity !== undefined ? state.subBgOpacity : 75) / 100.0);
  const bgRadius = state.subBgRadius || 16;

  const posY = (state.subPosY !== undefined) ? state.subPosY : 6.5;
  const posX = (state.subPosX !== undefined) ? state.subPosX : 0.0;
  const layerZ = state.subLayerZ || 100;

  monitorSubtitle.className = 'monitor-subtitle';
  monitorSubtitle.classList.add(`sub-font-${font}`);

  // Base font size in preview (scaled to match backend video canvas 1:1)
  const monitorBox = $('preview-screen') || monitorSubtitle.parentElement;
  let pw = monitorBox ? monitorBox.clientWidth : 640;
  let ph = monitorBox ? monitorBox.clientHeight : 360;
  if (!pw || pw <= 0) pw = 640;
  if (!ph || ph <= 0) ph = 360;
  const isVertical = ph > pw;

  let scaledSize, scaledStrokeW;
  if (isVertical) {
    scaledSize = Math.max(12, Math.round(size * 3.74 * (ph / 1920.0)));
    scaledStrokeW = Math.max(1, Math.round(strokeWidth * 3.74 * (ph / 1920.0) * 0.5));
  } else {
    scaledSize = Math.max(12, Math.round(size * 2.2 * (ph / 1080.0)));
    scaledStrokeW = Math.max(1, Math.round(strokeWidth * 2.2 * (ph / 1080.0) * 0.5));
  }

  monitorSubtitle.style.color = color;
  monitorSubtitle.style.fontSize = `${scaledSize}px`;
  monitorSubtitle.style.letterSpacing = `${state.subLetterSpacing || 0}px`;
  monitorSubtitle.style.lineHeight = `${state.subLineSpacing || 1.25}`;
  monitorSubtitle.style.zIndex = layerZ;

  // Stroke / Outline
  if (strokeEnabled && strokeWidth > 0) {
    const sw = scaledStrokeW;
    const sc = strokeColor;
    monitorSubtitle.style.textShadow = `
      -${sw}px -${sw}px 0 ${sc}, ${sw}px -${sw}px 0 ${sc},
      -${sw}px ${sw}px 0 ${sc}, ${sw}px ${sw}px 0 ${sc},
      -${sw+1}px 0 0 ${sc}, ${sw+1}px 0 0 ${sc},
      0 -${sw+1}px 0 ${sc}, 0 ${sw+1}px 0 ${sc},
      0 4px 10px rgba(0,0,0,0.85)
    `;
  } else {
    monitorSubtitle.style.textShadow = 'none';
  }

  // Background Box
  if (bgEnabled) {
    let r = 0, g = 0, b = 0;
    try {
      const cleanHex = bgColor.replace('#', '');
      r = parseInt(cleanHex.slice(0, 2), 16) || 0;
      g = parseInt(cleanHex.slice(2, 4), 16) || 0;
      b = parseInt(cleanHex.slice(4, 6), 16) || 0;
    } catch(e) {}
    const padX = Math.max(8, Math.round(scaledSize * 0.35));
    const padY = Math.max(4, Math.round(scaledSize * 0.18));
    const rad = Math.max(4, Math.round(bgRadius * (scaledSize / 50.0)));
    monitorSubtitle.style.background = `rgba(${r}, ${g}, ${b}, ${bgOpacity})`;
    monitorSubtitle.style.padding = `${padY}px ${padX}px`;
    monitorSubtitle.style.borderRadius = `${rad}px`;
  } else {
    monitorSubtitle.style.background = 'transparent';
    monitorSubtitle.style.padding = '0';
    monitorSubtitle.style.borderRadius = '0';
  }

  // Position X & Y (with 1-line vertical centering at midpoint of 2-line box)
  let effectivePosY = posY;
  const subText = monitorSubtitle.textContent || '';
  const isOneLine = !subText.includes('\n');
  if (isOneLine) {
    const lineStepPx = scaledSize * (state.subLineSpacing || 1.25);
    const offsetPercent = (lineStepPx / 2.0 / ph) * 100;
    effectivePosY = posY + offsetPercent;
  }

  monitorSubtitle.style.bottom = `${effectivePosY.toFixed(2)}%`;
  if (posX !== 0) {
    monitorSubtitle.style.transform = `translateX(${posX}%)`;
  } else {
    monitorSubtitle.style.transform = 'none';
  }
}

// Player controls
if (btnPlayPause) btnPlayPause.addEventListener('click', togglePlayPause);
if (btnPrevScene) btnPrevScene.addEventListener('click', () => {
  const curIdx = state.selectedIdx > 0 ? state.selectedIdx - 1 : 0;
  state.selectedIdx = curIdx;
  if (state.imagesData[curIdx]) seekTo(state.imagesData[curIdx].startTime);
});
if (btnNextScene) btnNextScene.addEventListener('click', () => {
  const curIdx = state.selectedIdx < state.imagesData.length - 1 ? state.selectedIdx + 1 : state.selectedIdx;
  state.selectedIdx = curIdx;
  if (state.imagesData[curIdx]) seekTo(state.imagesData[curIdx].startTime);
});

function togglePlayPause() {
  setPlaying(!state.isPlaying);
}

function setPlaying(playing) {
  state.isPlaying = playing;
  if (btnPlayPause) btnPlayPause.textContent = playing ? '⏸️' : '▶️';

  if (playing) {
    if (wavesurfer && typeof wavesurfer.play === 'function') {
      wavesurfer.play();
    } else if (state.audioEl && state.audioEl.src) {
      state.audioEl.play().catch(() => {});
    }
    const imgTotalDur = state.imagesData.reduce((acc, d) => acc + d.duration, 0);
    const subTotalDur = state.subtitles.length > 0 ? state.subtitles[state.subtitles.length - 1].end : 0;
    const totalDur = Math.max(imgTotalDur, subTotalDur, 1.0);

    clearInterval(state.playTimer);
    state.playTimer = setInterval(() => {
      if (!wavesurfer && (!state.audioEl || !state.audioEl.src || state.audioEl.paused)) {
        state.currentTime += 0.05;
        if (state.currentTime >= totalDur) {
          state.currentTime = 0;
          setPlaying(false);
        }
        updatePlayhead(state.currentTime);
        updateMonitor(state.currentTime);
      }
    }, 50);
  } else {
    if (wavesurfer && typeof wavesurfer.pause === 'function') {
      wavesurfer.pause();
    } else if (state.audioEl && state.audioEl.src) {
      state.audioEl.pause();
    }
    clearInterval(state.playTimer);
  }
}


// ─── Settings / Duration sync ─────────────────────────────────────────────────

if (chkTransit) {
  chkTransit.addEventListener('change', () => {
    if (transitSub) transitSub.style.display = chkTransit.checked ? 'flex' : 'none';
  });
}

function updateFooterInfo() {
  fiImages.textContent = `${state.images.length} images`;

  if (state.activeTab === 'tts') {
    const hasTts = state.ttsJobId || (ttsJobIdHid && ttsJobIdHid.value);
    fiAudio.textContent = hasTts ? '🎤 TTS audio ready' : '🎤 TTS (not generated)';
    fiAudio.style.color = hasTts ? '#22c55e' : '';
  } else {
    fiAudio.textContent = state.audio ? state.audio.name : 'No audio';
    fiAudio.style.color = state.audio ? '#22c55e' : '';
  }

  const dur = parseFloat(inpDuration.value) || 3.5;
  const total = (state.images.length * dur).toFixed(0);
  fiDur.textContent = `~${total}s video`;
}

if (inpDuration) inpDuration.addEventListener('input', updateFooterInfo);

// Resolution & Aspect Ratio toggle buttons
$$('.btn-group').forEach(group => {
  const hiddenName = group.dataset.hidden;
  const hiddenInput = group.parentElement.querySelector(`input[name="${hiddenName}"]`);
  group.querySelectorAll('.bopt').forEach(btn => {
    btn.addEventListener('click', () => {
      group.querySelectorAll('.bopt').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      if (hiddenInput) hiddenInput.value = btn.dataset.val;

      // Update Preview Monitor Aspect Ratio
      if (hiddenName === 'aspect_ratio') {
        const aspectVal = btn.dataset.val.replace(':', '-');
        const screen = $('preview-screen');
        if (screen) {
          screen.className = `preview-screen aspect-${aspectVal}`;
        }
        setTimeout(() => {
          applySubtitleStylesToMonitor();
          updateMonitor(state.currentTime);
        }, 60);
      }
    });
  });
});


// ─── Real-Time Floating Toast Notifications ─────────────────────────────────
function showToast(msg, type = 'info', duration = 4500) {
  const container = $('toast-container');
  if (!container) return;

  const toast = document.createElement('div');
  toast.className = `toast-msg toast-${type}`;
  
  const icons = {
    info: 'ℹ️',
    success: '✅',
    warning: '⚠️',
    error: '❌'
  };

  toast.innerHTML = `
    <span style="font-size:16px">${icons[type] || 'ℹ️'}</span>
    <span style="flex:1">${msg}</span>
  `;

  container.appendChild(toast);

  setTimeout(() => {
    toast.style.opacity = '0';
    toast.style.transform = 'translateY(10px) scale(0.95)';
    setTimeout(() => {
      try { container.removeChild(toast); } catch(e) {}
    }, 250);
  }, duration);
}

// ─── Diagnostic Error Modal Suite ───────────────────────────────────────────
function showErrorModal({ code = 'ERR_UNKNOWN', message = 'Có lỗi xảy ra', details = '', suggestion = '' }) {
  const modal = $('diag-error-modal');
  if (!modal) {
    alert(`[${code}] ${message}\n\nChi tiết:\n${details}`);
    return;
  }

  const elCode = $('diag-error-code');
  const elMsg  = $('diag-error-msg');
  const elDet  = $('diag-error-details');
  const elSugg = $('diag-error-sugg');

  if (elCode) elCode.textContent = code;
  if (elMsg)  elMsg.textContent  = message;
  if (elDet)  elDet.textContent  = details || 'Không có thêm thông tin kỹ thuật.';
  if (elSugg) elSugg.textContent = suggestion || 'Vui lòng kiểm tra lại cấu hình hoặc liên hệ hỗ trợ.';

  modal.style.display = 'flex';
}

function hideErrorModal() {
  const modal = $('diag-error-modal');
  if (modal) modal.style.display = 'none';
}

function hideError() {
  const errBox = $('err-box');
  if (errBox) errBox.style.display = 'none';
  hideErrorModal();
}

const btnDiagClose   = $('btn-diag-close');
const btnDiagDismiss = $('btn-diag-dismiss');
const btnDiagCopy    = $('btn-diag-copy');

if (btnDiagClose)   btnDiagClose.addEventListener('click', hideErrorModal);
if (btnDiagDismiss) btnDiagDismiss.addEventListener('click', hideErrorModal);
if (btnDiagCopy) {
  btnDiagCopy.addEventListener('click', () => {
    const code = ($('diag-error-code') && $('diag-error-code').textContent) || 'ERR';
    const msg  = ($('diag-error-msg') && $('diag-error-msg').textContent) || '';
    const det  = ($('diag-error-details') && $('diag-error-details').textContent) || '';
    const copyText = `[MÃ LỖI: ${code}]\nThông báo: ${msg}\nChi tiết kỹ thuật:\n${det}`;
    navigator.clipboard.writeText(copyText).then(() => {
      showToast('📋 Đã sao chép mã lỗi vào Clipboard!', 'success', 2500);
    }).catch(() => {
      alert(copyText);
    });
  });
}

// ─── Live Server Health Polling ──────────────────────────────────────────────
let isServerOnline = true;
async function checkServerHealth() {
  const badge = $('server-health-badge');
  const txt   = $('server-health-text');
  const dot   = badge && typeof badge.querySelector === 'function' ? badge.querySelector('.health-dot') : null;

  try {
    const res = await fetch('/api/health', { method: 'GET', cache: 'no-store' });
    if (res.ok) {
      if (!isServerOnline) {
        showToast('🟢 Đã khôi phục kết nối máy chủ!', 'success', 3000);
      }
      isServerOnline = true;
      if (badge) badge.className = 'badge badge-ok';
      if (txt) txt.textContent = 'Server: Online (8080)';
      if (dot) dot.className = 'health-dot health-dot-online';
    } else {
      throw new Error(`Status ${res.status}`);
    }
  } catch (err) {
    if (isServerOnline) {
      showToast('🔴 Cảnh báo: Mất kết nối tới máy chủ Flask (Port 8080)!', 'error', 6000);
    }
    isServerOnline = false;
    if (badge) badge.className = 'badge badge-warn';
    if (txt) txt.textContent = 'Server: OFFLINE (Mất kết nối)';
    if (dot) dot.className = 'health-dot health-dot-offline';
  }
}

try {
  setInterval(checkServerHealth, 4000);
  checkServerHealth();
} catch(e) {
  console.warn('Health check timer error:', e);
}

// ─── Render Video Execution ───────────────────────────────────────────────────

let isExecutingRender = false;

async function executeRenderVideo(e) {
  if (e && e.preventDefault) e.preventDefault();
  if (isExecutingRender) {
    console.warn('⚠️ Render đang được tiến hành, vui lòng đợi...');
    return;
  }
  isExecutingRender = true;

  try {
    console.log('🎬 [executeRenderVideo] Images count:', state.images ? state.images.length : 0);
    
    if (state.images && state.images.length > 0) {
      showToast(`🔍 [Bước 1/4] Chuẩn bị xuất video với ${state.images.length} hình ảnh...`, 'info', 2500);
    } else {
      showToast(`🎨 [Bước 1/4] Tự động tạo phân cảnh hình nền theo Timeline và Phụ đề...`, 'info', 2500);
    }

    hideError();
    setRendering(true);

    if (progWrap) {
      progWrap.style.display = 'flex';
      progWrap.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    const fd = new FormData();

    // Images if user uploaded them
    if (Array.isArray(state.images)) {
      state.images.forEach(img => {
        if (img) fd.append('images', img);
      });
    }

    // Audio: reliable lookup
    const ttsId = state.ttsJobId || (ttsJobIdHid ? ttsJobIdHid.value : '');
    if (state.activeTab === 'upload' && state.audio) {
      fd.append('audio', state.audio);
    } else if (ttsId) {
      fd.append('tts_job_id', ttsId);
    } else if (state.audio) {
      fd.append('audio', state.audio);
    }

    // Dynamic Image Durations and Effects from Timeline (only if user uploaded images)
    if (Array.isArray(state.images) && state.images.length > 0 && Array.isArray(state.imagesData) && state.imagesData.length > 0) {
      const durs = state.imagesData.map(d => (d && typeof d.duration === 'number') ? d.duration : 5.0);
      fd.append('image_durations', JSON.stringify(durs));
      const effs = state.imagesData.map(d => (d && d.effect) ? d.effect : 'zoom_in');
      fd.append('image_effects', JSON.stringify(effs));
    }

    // Active Project ID
    const curProjEl = document.getElementById('current-project-id');
    const projId = curProjEl ? curProjEl.value.trim() : (state.currentProjectId || '');
    if (projId) {
      fd.append('project_id', projId);
    }

    // Subtitles: Collect from Timeline, customSrtText, or subScriptInput
    let finalSrt = '';
    try {
      finalSrt = generateSrtFromSubtitles();
    } catch(srtErr) {
      console.warn('generateSrtFromSubtitles warning:', srtErr);
    }

    if (!finalSrt && customSrtText && customSrtText.value && customSrtText.value.trim()) {
      finalSrt = customSrtText.value.trim();
    }
    if (!finalSrt && subScriptInput && subScriptInput.value && subScriptInput.value.includes('-->')) {
      finalSrt = subScriptInput.value.trim();
    }

    const hasSubs = Boolean(finalSrt && finalSrt.trim());
    const isSubEnabled = (chkSubtitlesMain && chkSubtitlesMain.checked) || hasSubs;
    fd.append('enable_subtitles', isSubEnabled ? 'true' : 'false');
    if (selSubLang) fd.append('subtitle_language', selSubLang.value || 'vi');

    if (hasSubs) {
      fd.append('custom_srt', finalSrt.trim());
      fd.append('enable_subtitles', 'true');
      console.log(`🎬 [Render Frontend] Đã gửi kèm ${finalSrt.split('\n').length} dòng phụ đề SRT sang máy chủ!`);
    }

    // Form Settings
    const form = document.getElementById('render-form');
    const defaultVals = {
      aspect_ratio: '16:9',
      resolution: '1080p',
      fps: '25',
      duration_per_image: '5.0',
      transition_duration: '1.0',
      weight_zoom_in: '25',
      weight_zoom_out: '25',
      weight_pan: '25',
      weight_tilt: '25',
      zoom_magnitude: '0.2',
      pan_magnitude: '0.2',
      tilt_magnitude: '0.2',
      crf: '19',
      codec: 'h264',
      preset: 'medium'
    };

    Object.keys(defaultVals).forEach(name => {
      const el = form ? form.querySelector(`[name="${name}"]`) : null;
      fd.append(name, (el && el.value) ? el.value : defaultVals[name]);
    });
    fd.append('use_transition', (chkTransit && chkTransit.checked) ? 'true' : 'false');
    
    // Live Subtitle Styling Parameters Direct from Inspector / Card 3 / State
    const inspSelFont = $('insp-sel-sub-font');
    const selSubFont = $('sel-sub-font');
    const chosenFont = (inspSelFont && inspSelFont.value) ? inspSelFont.value : (selSubFont ? selSubFont.value : (state.subFont || 'paperlogy'));
    
    const inspSlSize = $('insp-sl-sub-size');
    const slSubSize = $('sl-sub-size');
    const chosenSize = (inspSlSize && inspSlSize.value) ? parseInt(inspSlSize.value, 10) : (slSubSize ? parseInt(slSubSize.value, 10) : (state.subSize || 36));

    const inspCpColor = $('insp-cp-sub-color');
    const chosenColor = (inspCpColor && inspCpColor.value) ? inspCpColor.value : (state.subColor || '#ffffff');

    const inspChkStroke = $('insp-chk-sub-stroke');
    const chkStrokeC3 = $('chk-sub-stroke-c3');
    const chosenStrokeEnabled = (inspChkStroke ? inspChkStroke.checked : (chkStrokeC3 ? chkStrokeC3.checked : (state.subStrokeEnabled !== false)));

    const inspCpStrokeColor = $('insp-cp-sub-stroke-color');
    const chosenStrokeColor = (inspCpStrokeColor && inspCpStrokeColor.value) ? inspCpStrokeColor.value : (state.subStrokeColor || '#000000');

    const inspSlStrokeW = $('insp-sl-sub-stroke-w');
    const chosenStrokeWidth = (inspSlStrokeW && inspSlStrokeW.value) ? parseInt(inspSlStrokeW.value, 10) : (state.subStrokeWidth || 4);

    const inspChkBg = $('insp-chk-sub-bg');
    const chkBgC3 = $('chk-sub-bg-c3');
    const chosenBgEnabled = (inspChkBg ? inspChkBg.checked : (chkBgC3 ? chkBgC3.checked : Boolean(state.subBgEnabled)));

    const inspCpBgColor = $('insp-cp-sub-bg-color');
    const chosenBgColor = (inspCpBgColor && inspCpBgColor.value) ? inspCpBgColor.value : (state.subBgColor || '#000000');

    const inspSlBgOpacity = $('insp-sl-sub-bg-opacity');
    const chosenBgOpacity = (inspSlBgOpacity && inspSlBgOpacity.value) ? parseInt(inspSlBgOpacity.value, 10) : (state.subBgOpacity !== undefined ? state.subBgOpacity : 75);

    const chosenBgRadius = state.subBgRadius || 16;

    const inspSlPosY = $('insp-sl-sub-pos-y');
    const chosenPosY = (inspSlPosY && inspSlPosY.value) ? parseFloat(inspSlPosY.value) : (state.subPosY !== undefined ? state.subPosY : 14.0);

    const inspSlPosX = $('insp-sl-sub-pos-x');
    const chosenPosX = (inspSlPosX && inspSlPosX.value) ? parseFloat(inspSlPosX.value) : (state.subPosX !== undefined ? state.subPosX : 0.0);

    const inspSlLetterSpacing = $('insp-sl-sub-letter-spacing');
    const chosenLetterSpacing = (inspSlLetterSpacing && inspSlLetterSpacing.value) ? parseFloat(inspSlLetterSpacing.value) : (state.subLetterSpacing !== undefined ? state.subLetterSpacing : 0.0);

    const inspSlLineSpacing = $('insp-sl-sub-line-spacing');
    const chosenLineSpacing = (inspSlLineSpacing && inspSlLineSpacing.value) ? parseFloat(inspSlLineSpacing.value) : (state.subLineSpacing !== undefined ? state.subLineSpacing : 1.25);

    fd.append('sub_font', chosenFont);
    fd.append('sub_size', String(chosenSize));
    fd.append('sub_color', chosenColor);
    fd.append('sub_stroke_enabled', chosenStrokeEnabled ? 'true' : 'false');
    fd.append('sub_stroke_color', chosenStrokeColor);
    fd.append('sub_stroke_width', String(chosenStrokeWidth));
    fd.append('sub_bg_enabled', chosenBgEnabled ? 'true' : 'false');
    fd.append('sub_bg_color', chosenBgColor);
    fd.append('sub_bg_opacity', String(chosenBgOpacity));
    fd.append('sub_bg_radius', String(chosenBgRadius));
    fd.append('sub_pos_y', String(chosenPosY));
    fd.append('sub_pos_x', String(chosenPosX));
    fd.append('sub_letter_spacing', String(chosenLetterSpacing));
    fd.append('sub_line_spacing', String(chosenLineSpacing));

    // In/Out Selection Range Slice
    if (state.markIn !== null || state.markOut !== null) {
      const inVal = state.markIn !== null ? state.markIn : 0.0;
      const imgTotalDur = state.imagesData.reduce((acc, d) => acc + d.duration, 0);
      const subTotalDur = state.subtitles.length > 0 ? state.subtitles[state.subtitles.length - 1].end : 0;
      const totalDur = Math.max(imgTotalDur, subTotalDur, 1.0);
      const outVal = state.markOut !== null ? state.markOut : totalDur;

      if (outVal > inVal) {
        fd.append('render_in', inVal.toFixed(3));
        fd.append('render_out', outVal.toFixed(3));
        console.log(`🎯 [executeRenderVideo] Slicing In/Out range: ${inVal.toFixed(2)}s -> ${outVal.toFixed(2)}s (~${(outVal - inVal).toFixed(1)}s)`);
      }
    }

    const countLabel = (state.images && state.images.length > 0) ? `${state.images.length} ảnh` : 'dữ liệu Timeline';
    const isInOutActive = (state.markIn !== null || state.markOut !== null);
    if (isInOutActive) {
      showToast(`🎯 [Bước 2/4] Đang gửi phân đoạn In/Out (${countLabel}) sang máy chủ...`, 'info', 3000);
    } else {
      showToast(`📦 [Bước 2/4] Đang gửi ${countLabel} sang máy chủ...`, 'info', 3000);
    }

    const res = await fetch('/render', { method: 'POST', body: fd });
    let data;
    try {
      data = await res.json();
    } catch(jsonErr) {
      throw new Error(`Máy chủ trả về dữ liệu không hợp lệ (${res.status}): ${res.statusText || 'No response'}`);
    }

    let errorModalShown = false;
    if (!res.ok || (data && data.error)) {
      const errMsg = (data && data.error) ? data.error : `HTTP Status: ${res.status}`;
      showErrorModal({
        code: `ERR_RENDER_HTTP_${res.status}`,
        message: 'Máy chủ từ chối yêu cầu xuất video',
        details: errMsg,
        suggestion: 'Kiểm tra xem các tệp âm thanh hoặc thông số video có hợp lệ không.'
      });
      errorModalShown = true;
      throw new Error(errMsg);
    }
    
    showToast(`🚀 [Bước 3/4] Máy chủ đã nhận lệnh, bắt đầu Render FFmpeg...`, 'info', 3500);
    startRenderSSE(data.job_id);

  } catch (err) {
    console.error('❌ executeRenderVideo catch:', err);
    showToast(`❌ Lỗi Render: ${err.message}`, 'error', 7000);
    if (typeof errorModalShown !== 'undefined' && !errorModalShown) {
      showErrorModal({
        code: 'ERR_RENDER_CLIENT_EXCEPTION',
        message: 'Có sự cố trong quá trình chuẩn bị dữ liệu render',
        details: `${err.name || 'Error'}: ${err.message || err}\n${err.stack || ''}`,
        suggestion: 'Vui lòng làm mới trang (F5) hoặc kiểm tra lại file âm thanh / phụ đề.'
      });
    }
    showError(err.message);
    setRendering(false);
  } finally {
    isExecutingRender = false;
  }
}

// Expose globally for inline onclick handlers
window.executeRenderVideo = executeRenderVideo;

const btnTlRenderDirect = $('btn-tl-render-direct');
if (btnTlRenderDirect) {
  btnTlRenderDirect.addEventListener('click', executeRenderVideo);
}

if (renderBtn) {
  renderBtn.addEventListener('click', executeRenderVideo);
}

function startRenderSSE(jobId) {
  if (state.renderEvt) state.renderEvt.close();
  if (progWrap) progWrap.style.display = 'flex';
  setRenderProgress(0);

  const es = new EventSource(`/progress/${jobId}`);
  state.renderEvt = es;

  let lastReportedTier = -1;

  es.onmessage = e => {
    const msg = JSON.parse(e.data);
    if (msg.heartbeat) return;

    if (msg.error) {
      showToast(`❌ FFmpeg Lỗi: ${msg.error}`, 'error', 8000);
      showErrorModal({
        code: 'ERR_FFMPEG_PROCESSING_CRASH',
        message: 'FFmpeg gặp sự cố trong quá trình render video',
        details: msg.error,
        suggestion: 'Xem chi tiết lỗi FFmpeg ở trên (ví dụ: codec không hỗ trợ, thiếu bộ nhớ, hoặc file nguồn bị hỏng).'
      });
      showError(msg.error);
      setRendering(false);
      es.close();
      return;
    }

    const pct = msg.progress || 0;
    setRenderProgress(pct);

    const currentTier = Math.floor(pct / 25);
    if (currentTier > lastReportedTier && pct > 0 && pct < 100) {
      lastReportedTier = currentTier;
      showToast(`🎬 Đang render video: ${pct}%...`, 'info', 2000);
    }

    if (msg.done || pct >= 100) {
      setRenderProgress(100);
      setRendering(false);
      showToast(`🎉 [Bước 4/4] Xuất Video hoàn tất 100%! Đã có link tải.`, 'success', 6000);
      showDownloadButtons(jobId, msg.has_srt);
      es.close();
    }
  };

  es.onerror = () => {
    showToast(`❌ Mất kết nối đến tiến trình Render máy chủ.`, 'error', 6000);
    showErrorModal({
      code: 'ERR_SSE_CONNECTION_LOST',
      message: 'Mất kết nối luồng tiến trình với máy chủ',
      details: `EventSource endpoint /progress/${jobId} bị ngắt kết nối.`,
      suggestion: 'Kiểm tra terminal máy chủ xem Flask có bị dừng đột ngột không.'
    });
    showError('Connection lost during render.');
    setRendering(false);
    es.close();
  };
}

function setRendering(active) {
  if (renderBtn) {
    renderBtn.disabled    = active;
    renderBtn.textContent = active ? '⏳ Đang Render Video…' : '🎬 Render Video Hoàn Chỉnh';
  }
  if (btnTlRenderDirect) {
    btnTlRenderDirect.disabled = active;
    btnTlRenderDirect.textContent = active ? '⏳ Đang Render...' : '🎬 Xuất Video';
  }
  if (!active && progWrap) progWrap.style.display = 'none';
}

function setRenderProgress(pct) {
  if (progFill) progFill.style.width = pct + '%';
  if (progPct)  progPct.textContent  = pct + '%';
  if (btnTlRenderDirect) {
    btnTlRenderDirect.textContent = `⏳ Render (${pct}%)`;
  }
}

function showDownloadButtons(jobId, hasSrt) {
  if (dlBtn) {
    dlBtn.href = `/download/${jobId}`;
    dlBtn.style.display = 'inline-flex';
  }
  if (dlSrtBtn) {
    dlSrtBtn.href = `/download_srt/${jobId}`;
    dlSrtBtn.style.display = 'inline-flex';
  }
  const btnTlDl = $('btn-tl-dl-direct');
  if (btnTlDl) {
    btnTlDl.href = `/download/${jobId}`;
    btnTlDl.style.display = 'inline-flex';
  }
  alert('🎉 Xuất Video thành công! Bạn có thể bấm "Tải Video .MP4" trên thanh công cụ Timeline để lưu về máy.');
}

function showError(msg) {
  if (!errBox) return;
  errBox.textContent   = msg;
  errBox.style.display = 'block';
}
// ─── Project Management Studio (Auto-Save & Storage) ───────────────────────────
const selProjectList     = $('sel-project-list');
const btnProjNew         = $('btn-proj-new');
const btnProjSave        = $('btn-proj-save');
const autosaveStatus     = $('autosave-status');
const currentProjIdInput = $('current-project-id');

async function fetchProjectList() {
  try {
    const res = await fetch('/api/projects');
    const data = await res.json();
    if (selProjectList && data.projects) {
      const cur = state.currentProjectId;
      selProjectList.innerHTML = '<option value="">(Chưa chọn dự án)</option>' +
        data.projects.map(p => `<option value="${p.id}" ${p.id === cur ? 'selected' : ''}>${p.id} (${p.image_count} ảnh)</option>`).join('');
    }
  } catch (err) {
    console.warn('Failed to fetch projects:', err);
  }
}

async function createNewProject(suggestedTitle = '') {
  let title = suggestedTitle;
  if (!title) {
    title = prompt('Nhập tên dự án mới (ví dụ: Tap_01_Cau_Chuyen_Cuoc_Song):', 'Video_Moi');
    if (!title) return;
  }
  try {
    const res = await fetch('/api/projects/create', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ title })
    });
    const data = await res.json();
    if (data.success && data.project_id) {
      state.currentProjectId = data.project_id;
      state.projectTitle = data.title;
      if (currentProjIdInput) currentProjIdInput.value = data.project_id;
      await fetchProjectList();
      if (selProjectList) selProjectList.value = data.project_id;
      updateAutoSaveBadge('🟢 Đã tạo dự án');
    }
  } catch (err) {
    alert('Lỗi tạo dự án: ' + err.message);
  }
}

async function saveCurrentProject(isAuto = false) {
  if (!state.currentProjectId) {
    if (!isAuto) {
      await createNewProject(state.projectTitle || 'Video_Moi');
    }
    if (!state.currentProjectId) return;
  }

  if (isAuto && autosaveStatus) {
    autosaveStatus.textContent = '⏳ Đang lưu...';
  }

  const payload = {
    project_id: state.currentProjectId,
    title: state.projectTitle || state.currentProjectId,
    images_data: state.imagesData.map(img => ({
      index: img.index,
      sceneId: img.sceneId,
      duration: img.duration,
      effect: img.effect || 'zoom_in',
      startTime: img.startTime,
      endTime: img.endTime
    })),
    subtitles: state.subtitles,
    srt_content: customSrtText ? customSrtText.value : '',
    script_text: subScriptInput ? subScriptInput.value : '',
    duration: state.subtitles.length > 0 ? state.subtitles[state.subtitles.length - 1].end : 0,
    settings: {
      aspect_ratio: $('hid-aspect-ratio') ? $('hid-aspect-ratio').value : '16:9',
      resolution: document.querySelector('input[name="resolution"]') ? document.querySelector('input[name="resolution"]').value : '1080p',
      fps: document.querySelector('input[name="fps"]') ? document.querySelector('input[name="fps"]').value : '25'
    }
  };

  try {
    const res = await fetch('/api/projects/save', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const data = await res.json();
    if (data.success) {
      updateAutoSaveBadge(`🟢 Đã lưu lúc ${data.saved_at}`);
    }
  } catch (err) {
    if (!isAuto) alert('Lỗi lưu dự án: ' + err.message);
  }
}

async function loadProject(projectId) {
  if (!projectId) return;
  try {
    const res = await fetch(`/api/projects/${projectId}/load`);
    const data = await res.json();
    if (data.success && data.project) {
      const p = data.project;
      state.currentProjectId = p.id;
      state.projectTitle = p.title || p.id;
      if (currentProjIdInput) currentProjIdInput.value = p.id;

      if (p.script_text && subScriptInput) subScriptInput.value = p.script_text;
      if (p.srt_content && customSrtText) customSrtText.value = p.srt_content;
      if (p.subtitles) state.subtitles = p.subtitles;
      if (p.images_data && p.images_data.length > 0) {
        state.imagesData = p.images_data;
      }
      renderTimelineUI();
      updateInspector();
      updateFooterInfo();
      updateAutoSaveBadge('🟢 Đã mở dự án');
    }
  } catch (err) {
    alert('Lỗi tải dự án: ' + err.message);
  }
}

function updateAutoSaveBadge(text) {
  if (autosaveStatus) {
    autosaveStatus.textContent = text;
  }
}

if (btnProjNew) btnProjNew.addEventListener('click', () => createNewProject());
if (btnProjSave) btnProjSave.addEventListener('click', () => saveCurrentProject(false));
if (selProjectList) {
  selProjectList.addEventListener('change', e => {
    if (e.target.value) loadProject(e.target.value);
  });
}

// Auto-Save Timer: Runs every 30 seconds
setInterval(() => {
  if (state.currentProjectId && (state.imagesData.length > 0 || state.subtitles.length > 0)) {
    saveCurrentProject(true);
  }
}, 30000);

// Smart Auto-Fit 200 Images Button
const btnTlAutofit = $('btn-tl-autofit-images');
if (btnTlAutofit) {
  btnTlAutofit.addEventListener('click', () => {
    if (state.imagesData.length === 0) {
      alert('⚠️ Vui lòng tải ảnh lên trước khi bấm Cân đối ảnh.');
      return;
    }
    const totalAudioDur = state.subtitles.length > 0
      ? state.subtitles[state.subtitles.length - 1].end
      : (wavesurfer ? wavesurfer.getDuration() : 0);

    if (totalAudioDur <= 0) {
      alert('⚠️ Vui lòng nạp Voice Audio hoặc Kịch bản thoại để có tổng thời lượng.');
      return;
    }

    const count = state.imagesData.length;
    const durPerImg = Math.max(1.0, totalAudioDur / count);

    let curT = 0.0;
    let lastEff = null;
    state.imagesData.forEach((d, i) => {
      const choices = EFFECT_POOL.filter(e => e !== lastEff);
      const eff = choices[Math.floor(Math.random() * choices.length)];
      lastEff = eff;

      d.duration = durPerImg;
      d.effect = eff;
      d.startTime = curT;
      d.endTime = curT + durPerImg;
      curT += durPerImg;
    });

    renderTimelineUI();
    updateInspector();
    updateDurationsHidden();
    updateEffectsHidden();
    alert(`✅ Đã cân đối đều ${count} ảnh theo tổng thời lượng ${totalAudioDur.toFixed(1)}s (mỗi ảnh ~${durPerImg.toFixed(1)}s kèm hiệu ứng Ken Burns)!`);
  });
}

// ─── Accordion Collapsible Sections ───────────────────────────────────────────
$$('.acc-header').forEach(header => {
  header.addEventListener('click', () => {
    const targetId = header.dataset.target;
    const body = $(targetId);
    const arrow = header.querySelector('.acc-arrow');
    if (body) {
      const isClosed = body.style.display === 'none';
      body.style.display = isClosed ? 'block' : 'none';
      if (arrow) arrow.classList.toggle('open', isClosed);
    }
  });
});

// Ken Burns effect sliders real-time sync
['zi', 'zo', 'pan', 'tilt'].forEach(key => {
  const sl = $(`sl-${key}`);
  const sv = $(`sv-${key}`);
  const hid = $(`hid-${key}`);
  if (sl && sv && hid) {
    sl.addEventListener('input', () => {
      sv.textContent = sl.value;
      hid.value = sl.value;
    });
  }
});

// Quality CRF slider sync
const slCrf = $('sl-crf');
const inpCrfNum = $('inp-crf-num');
if (slCrf && inpCrfNum) {
  slCrf.addEventListener('input', () => {
    inpCrfNum.value = slCrf.value;
  });
  inpCrfNum.addEventListener('input', () => {
    slCrf.value = Math.max(15, Math.min(28, parseInt(inpCrfNum.value, 10) || 19));
  });
}

// ─── Comprehensive Subtitle Font, Stroke, Background & Position Management ───
state.subFont = localStorage.getItem('sub_font') || 'paperlogy';
state.subSize = parseInt(localStorage.getItem('sub_size'), 10) || 36;
state.subColor = localStorage.getItem('sub_color') || '#ffffff';
state.subLetterSpacing = parseFloat(localStorage.getItem('sub_letter_spacing')) || 0.0;
state.subLineSpacing = parseFloat(localStorage.getItem('sub_line_spacing')) || 1.25;

state.subStrokeEnabled = localStorage.getItem('sub_stroke_enabled') !== 'false';
state.subStrokeColor = localStorage.getItem('sub_stroke_color') || '#000000';
state.subStrokeWidth = parseInt(localStorage.getItem('sub_stroke_width'), 10) || 4;

state.subBgEnabled = localStorage.getItem('sub_bg_enabled') === 'true';
state.subBgColor = localStorage.getItem('sub_bg_color') || '#000000';
state.subBgOpacity = parseInt(localStorage.getItem('sub_bg_opacity'), 10) || 75;
state.subBgRadius = parseInt(localStorage.getItem('sub_bg_radius'), 10) || 16;

state.subPosY = parseFloat(localStorage.getItem('sub_pos_y')) || 6.0;
state.subPosX = parseFloat(localStorage.getItem('sub_pos_x')) || 0.0;
state.subLayerZ = parseInt(localStorage.getItem('sub_layer_z'), 10) || 100;

function syncSubtitleStyleControls() {
  const font = state.subFont || 'paperlogy';
  const size = state.subSize || 36;
  const color = state.subColor || '#ffffff';

  const strokeEnabled = state.subStrokeEnabled !== false;
  const strokeColor = state.subStrokeColor || '#000000';
  const strokeWidth = state.subStrokeWidth || 4;

  const bgEnabled = state.subBgEnabled === true;
  const bgColor = state.subBgColor || '#000000';
  const bgOpacity = state.subBgOpacity !== undefined ? state.subBgOpacity : 75;
  const bgRadius = state.subBgRadius || 16;

  const posY = state.subPosY !== undefined ? state.subPosY : 6.5;
  const posX = state.subPosX !== undefined ? state.subPosX : 0.0;
  const layerZ = state.subLayerZ || 100;

  // Card 3 Elements
  const selSubFont = $('sel-sub-font');
  const slSubSize = $('sl-sub-size');
  const inpSubSizeNum = $('inp-sub-size-num');
  const chkStrokeC3 = $('chk-sub-stroke-c3');
  const chkBgC3 = $('chk-sub-bg-c3');

  if (selSubFont && selSubFont.value !== font) selSubFont.value = font;
  if (slSubSize && parseInt(slSubSize.value, 10) !== size) slSubSize.value = size;
  if (inpSubSizeNum && parseInt(inpSubSizeNum.value, 10) !== size) inpSubSizeNum.value = size;
  if (chkStrokeC3) chkStrokeC3.checked = strokeEnabled;
  if (chkBgC3) chkBgC3.checked = bgEnabled;

  // 1. Inspector Elements
  const inspSelFont = $('insp-sel-sub-font');
  const inspCpColor = $('insp-cp-sub-color');
  const inspSlSize = $('insp-sl-sub-size');
  const inspInpSize = $('insp-inp-sub-size');

  if (inspSelFont && inspSelFont.value !== font) inspSelFont.value = font;
  if (inspCpColor && inspCpColor.value !== color) inspCpColor.value = color;
  if (inspSlSize && parseInt(inspSlSize.value, 10) !== size) inspSlSize.value = size;
  if (inspInpSize && parseInt(inspInpSize.value, 10) !== size) inspInpSize.value = size;

  // Spacing controls
  const inspSlLetterSpacing = $('insp-sl-sub-letter-spacing');
  const inspInpLetterSpacing = $('insp-inp-sub-letter-spacing');
  const inspSlLineSpacing = $('insp-sl-sub-line-spacing');
  const inspInpLineSpacing = $('insp-inp-sub-line-spacing');

  if (inspSlLetterSpacing) inspSlLetterSpacing.value = state.subLetterSpacing !== undefined ? state.subLetterSpacing : 0;
  if (inspInpLetterSpacing) inspInpLetterSpacing.value = state.subLetterSpacing !== undefined ? state.subLetterSpacing : 0;
  if (inspSlLineSpacing) inspSlLineSpacing.value = state.subLineSpacing !== undefined ? state.subLineSpacing : 1.25;
  if (inspInpLineSpacing) inspInpLineSpacing.value = state.subLineSpacing !== undefined ? state.subLineSpacing : 1.25;

  // Stroke controls
  const chkStroke = $('insp-chk-sub-stroke');
  const cpStrokeColor = $('insp-cp-sub-stroke-color');
  const slStrokeW = $('insp-sl-sub-stroke-w');
  const inpStrokeW = $('insp-inp-sub-stroke-w');
  const strokeOpts = $('insp-sub-stroke-opts');

  if (chkStroke) chkStroke.checked = strokeEnabled;
  if (cpStrokeColor) cpStrokeColor.value = strokeColor;
  if (slStrokeW) slStrokeW.value = strokeWidth;
  if (inpStrokeW) inpStrokeW.value = strokeWidth;
  if (strokeOpts) strokeOpts.style.display = strokeEnabled ? 'flex' : 'none';

  // Background controls
  const chkBg = $('insp-chk-sub-bg');
  const cpBgColor = $('insp-cp-sub-bg-color');
  const slBgOp = $('insp-sl-sub-bg-op');
  const inpBgOp = $('insp-inp-sub-bg-op');
  const bgOpts = $('insp-sub-bg-opts');

  if (chkBg) chkBg.checked = bgEnabled;
  if (cpBgColor) cpBgColor.value = bgColor;
  if (slBgOp) slBgOp.value = bgOpacity;
  if (inpBgOp) inpBgOp.value = bgOpacity;
  if (bgOpts) bgOpts.style.display = bgEnabled ? 'block' : 'none';

  // Position controls
  const slPosY = $('insp-sl-sub-pos-y');
  const inpPosY = $('insp-inp-sub-pos-y');
  const slPosX = $('insp-sl-sub-pos-x');
  const inpPosX = $('insp-inp-sub-pos-x');
  const selZ = $('insp-sel-sub-z');

  if (slPosY) slPosY.value = posY;
  if (inpPosY) inpPosY.value = posY;
  if (slPosX) slPosX.value = posX;
  if (inpPosX) inpPosX.value = posX;
  if (selZ) selZ.value = layerZ;

  // Radius buttons active state
  $$('.btn-radius-quick').forEach(b => {
    b.classList.toggle('active', parseInt(b.dataset.rad, 10) === bgRadius);
  });

  applySubtitleStylesToMonitor();
  updateMonitor(state.currentTime);
}

// Card 3 Event Listeners
const selSubFont = $('sel-sub-font');
if (selSubFont) selSubFont.addEventListener('change', () => {
  state.subFont = selSubFont.value;
  localStorage.setItem('sub_font', state.subFont);
  syncSubtitleStyleControls();
});

const slSubSize = $('sl-sub-size');
const inpSubSizeNum = $('inp-sub-size-num');
if (slSubSize) slSubSize.addEventListener('input', () => {
  state.subSize = parseInt(slSubSize.value, 10) || 36;
  localStorage.setItem('sub_size', state.subSize);
  syncSubtitleStyleControls();
});
if (inpSubSizeNum) inpSubSizeNum.addEventListener('input', () => {
  state.subSize = Math.max(16, Math.min(100, parseInt(inpSubSizeNum.value, 10) || 36));
  localStorage.setItem('sub_size', state.subSize);
  syncSubtitleStyleControls();
});

const chkStrokeC3 = $('chk-sub-stroke-c3');
if (chkStrokeC3) chkStrokeC3.addEventListener('change', () => {
  state.subStrokeEnabled = chkStrokeC3.checked;
  localStorage.setItem('sub_stroke_enabled', state.subStrokeEnabled ? 'true' : 'false');
  syncSubtitleStyleControls();
});

const chkBgC3 = $('chk-sub-bg-c3');
if (chkBgC3) chkBgC3.addEventListener('change', () => {
  state.subBgEnabled = chkBgC3.checked;
  localStorage.setItem('sub_bg_enabled', state.subBgEnabled ? 'true' : 'false');
  syncSubtitleStyleControls();
});

// Inspector Event Listeners
const inspSelFont = $('insp-sel-sub-font');
if (inspSelFont) inspSelFont.addEventListener('change', () => {
  state.subFont = inspSelFont.value;
  localStorage.setItem('sub_font', state.subFont);
  syncSubtitleStyleControls();
});

const inspCpColor = $('insp-cp-sub-color');
if (inspCpColor) inspCpColor.addEventListener('input', () => {
  state.subColor = inspCpColor.value;
  localStorage.setItem('sub_color', state.subColor);
  syncSubtitleStyleControls();
});

$$('.btn-color-quick').forEach(btn => {
  btn.addEventListener('click', () => {
    state.subColor = btn.dataset.color;
    localStorage.setItem('sub_color', state.subColor);
    syncSubtitleStyleControls();
  });
});

const inspSlSize = $('insp-sl-sub-size');
const inspInpSize = $('insp-inp-sub-size');
if (inspSlSize) inspSlSize.addEventListener('input', () => {
  state.subSize = parseInt(inspSlSize.value, 10) || 36;
  localStorage.setItem('sub_size', state.subSize);
  syncSubtitleStyleControls();
});
if (inspInpSize) inspInpSize.addEventListener('input', () => {
  state.subSize = Math.max(16, Math.min(100, parseInt(inspInpSize.value, 10) || 36));
  localStorage.setItem('sub_size', state.subSize);
  syncSubtitleStyleControls();
});

// Spacing events
const inspSlLetterSpacing = $('insp-sl-sub-letter-spacing');
const inspInpLetterSpacing = $('insp-inp-sub-letter-spacing');
if (inspSlLetterSpacing) inspSlLetterSpacing.addEventListener('input', () => {
  state.subLetterSpacing = parseFloat(inspSlLetterSpacing.value) || 0.0;
  localStorage.setItem('sub_letter_spacing', state.subLetterSpacing);
  syncSubtitleStyleControls();
});
if (inspInpLetterSpacing) inspInpLetterSpacing.addEventListener('input', () => {
  state.subLetterSpacing = Math.max(-5, Math.min(20, parseFloat(inspInpLetterSpacing.value) || 0.0));
  localStorage.setItem('sub_letter_spacing', state.subLetterSpacing);
  syncSubtitleStyleControls();
});

const inspSlLineSpacing = $('insp-sl-sub-line-spacing');
const inspInpLineSpacing = $('insp-inp-sub-line-spacing');
if (inspSlLineSpacing) inspSlLineSpacing.addEventListener('input', () => {
  state.subLineSpacing = parseFloat(inspSlLineSpacing.value) || 1.25;
  localStorage.setItem('sub_line_spacing', state.subLineSpacing);
  syncSubtitleStyleControls();
});
if (inspInpLineSpacing) inspInpLineSpacing.addEventListener('input', () => {
  state.subLineSpacing = Math.max(0.8, Math.min(3.0, parseFloat(inspInpLineSpacing.value) || 1.25));
  localStorage.setItem('sub_line_spacing', state.subLineSpacing);
  syncSubtitleStyleControls();
});

// Stroke events
const chkStroke = $('insp-chk-sub-stroke');
if (chkStroke) chkStroke.addEventListener('change', () => {
  state.subStrokeEnabled = chkStroke.checked;
  localStorage.setItem('sub_stroke_enabled', state.subStrokeEnabled ? 'true' : 'false');
  syncSubtitleStyleControls();
});

const cpStrokeColor = $('insp-cp-sub-stroke-color');
if (cpStrokeColor) cpStrokeColor.addEventListener('input', () => {
  state.subStrokeColor = cpStrokeColor.value;
  localStorage.setItem('sub_stroke_color', state.subStrokeColor);
  syncSubtitleStyleControls();
});

const slStrokeW = $('insp-sl-sub-stroke-w');
const inpStrokeW = $('insp-inp-sub-stroke-w');
if (slStrokeW) slStrokeW.addEventListener('input', () => {
  state.subStrokeWidth = parseInt(slStrokeW.value, 10) || 4;
  localStorage.setItem('sub_stroke_width', state.subStrokeWidth);
  syncSubtitleStyleControls();
});
if (inpStrokeW) inpStrokeW.addEventListener('input', () => {
  state.subStrokeWidth = Math.max(1, Math.min(20, parseInt(inpStrokeW.value, 10) || 4));
  localStorage.setItem('sub_stroke_width', state.subStrokeWidth);
  syncSubtitleStyleControls();
});

// Background events
const chkBg = $('insp-chk-sub-bg');
if (chkBg) chkBg.addEventListener('change', () => {
  state.subBgEnabled = chkBg.checked;
  localStorage.setItem('sub_bg_enabled', state.subBgEnabled ? 'true' : 'false');
  syncSubtitleStyleControls();
});

const cpBgColor = $('insp-cp-sub-bg-color');
if (cpBgColor) cpBgColor.addEventListener('input', () => {
  state.subBgColor = cpBgColor.value;
  localStorage.setItem('sub_bg_color', state.subBgColor);
  syncSubtitleStyleControls();
});

const slBgOp = $('insp-sl-sub-bg-op');
const inpBgOp = $('insp-inp-sub-bg-op');
if (slBgOp) slBgOp.addEventListener('input', () => {
  state.subBgOpacity = parseInt(slBgOp.value, 10) || 75;
  localStorage.setItem('sub_bg_opacity', state.subBgOpacity);
  syncSubtitleStyleControls();
});
if (inpBgOp) inpBgOp.addEventListener('input', () => {
  state.subBgOpacity = Math.max(0, Math.min(100, parseInt(inpBgOp.value, 10) || 75));
  localStorage.setItem('sub_bg_opacity', state.subBgOpacity);
  syncSubtitleStyleControls();
});

$$('.btn-radius-quick').forEach(b => {
  b.addEventListener('click', () => {
    state.subBgRadius = parseInt(b.dataset.rad, 10) || 16;
    localStorage.setItem('sub_bg_radius', state.subBgRadius);
    syncSubtitleStyleControls();
  });
});

// Position X & Y & Z events
const slPosY = $('insp-sl-sub-pos-y');
const inpPosY = $('insp-inp-sub-pos-y');
if (slPosY) slPosY.addEventListener('input', () => {
  state.subPosY = parseFloat(slPosY.value) || 6.0;
  localStorage.setItem('sub_pos_y', state.subPosY);
  syncSubtitleStyleControls();
});
if (inpPosY) inpPosY.addEventListener('input', () => {
  state.subPosY = Math.max(0, Math.min(95, parseFloat(inpPosY.value) || 6.0));
  localStorage.setItem('sub_pos_y', state.subPosY);
  syncSubtitleStyleControls();
});

$$('.btn-posy-preset').forEach(btn => {
  btn.addEventListener('click', () => {
    state.subPosY = parseFloat(btn.dataset.val) || 6.0;
    localStorage.setItem('sub_pos_y', state.subPosY);
    syncSubtitleStyleControls();
  });
});

const slPosX = $('insp-sl-sub-pos-x');
const inpPosX = $('insp-inp-sub-pos-x');
if (slPosX) slPosX.addEventListener('input', () => {
  state.subPosX = parseFloat(slPosX.value) || 0.0;
  localStorage.setItem('sub_pos_x', state.subPosX);
  syncSubtitleStyleControls();
});
if (inpPosX) inpPosX.addEventListener('input', () => {
  state.subPosX = Math.max(-50, Math.min(50, parseFloat(inpPosX.value) || 0.0));
  localStorage.setItem('sub_pos_x', state.subPosX);
  syncSubtitleStyleControls();
});

const selZ = $('insp-sel-sub-z');
if (selZ) selZ.addEventListener('change', () => {
  state.subLayerZ = parseInt(selZ.value, 10) || 100;
  localStorage.setItem('sub_layer_z', state.subLayerZ);
  syncSubtitleStyleControls();
});

// Bulk Apply to All Subtitles button
const btnApplyAllSubs = $('btn-insp-apply-all-subs');
if (btnApplyAllSubs) {
  btnApplyAllSubs.addEventListener('click', () => {
    syncSubtitleStyleControls();
    showToast(`✅ Đã áp dụng định dạng & vị trí X: ${state.subPosX}%, Y: ${state.subPosY}% cho TẤT CẢ ${state.subtitles.length} câu phụ đề!`, 'success', 3500);
  });
}

// ─── 🔐 License Authentication & Activation Management ───
let currentHWID = '';

async function checkLicenseStatus(showAlertIfInvalid = false) {
  const badge = $('license-badge');
  const badgeIcon = $('lic-badge-icon');
  const badgeText = $('lic-badge-text');
  const hwidDisplay = $('lic-hwid-text');
  const licModal = $('license-modal');

  try {
    const res = await fetch('/api/license/status');
    const data = await res.json();
    currentHWID = data.hwid || '';
    if (hwidDisplay) hwidDisplay.textContent = currentHWID || 'Không xác định';

    if (data.ok) {
      if (badge) {
        badge.style.background = 'rgba(16, 185, 129, 0.15)';
        badge.style.borderColor = 'rgba(16, 185, 129, 0.35)';
        badge.style.color = '#34d399';
      }
      if (badgeIcon) badgeIcon.textContent = '💎';
      const daysText = data.days_left > 365 ? 'Vĩnh viễn' : `Còn ${data.days_left} ngày`;
      if (badgeText) badgeText.textContent = `${data.tier || 'VIP'} · ${daysText}`;
      if (licModal) licModal.style.display = 'none';
      return true;
    } else {
      if (badge) {
        badge.style.background = 'rgba(239, 68, 68, 0.15)';
        badge.style.borderColor = 'rgba(239, 68, 68, 0.35)';
        badge.style.color = '#f87171';
      }
      if (badgeIcon) badgeIcon.textContent = '🔒';
      if (badgeText) badgeText.textContent = 'Chưa kích hoạt';
      if (showAlertIfInvalid && licModal) {
        licModal.style.display = 'flex';
      }
      return false;
    }
  } catch (err) {
    console.warn('[License] Status check failed:', err);
    return false;
  }
}

// License Modal bindings
const licBadge = $('license-badge');
const licModal = $('license-modal');
const btnLicClose = $('btn-license-close');
const btnLicCancel = $('btn-license-cancel');
const btnCopyHwid = $('btn-copy-hwid');
const btnLicSubmit = $('btn-license-submit');
const inpLicKey = $('inp-license-key');
const licStatusMsg = $('lic-status-msg');

if (licBadge) {
  licBadge.addEventListener('click', () => {
    if (licModal) licModal.style.display = 'flex';
  });
}
if (btnLicClose) {
  btnLicClose.addEventListener('click', () => {
    if (licModal) licModal.style.display = 'none';
  });
}
if (btnLicCancel) {
  btnLicCancel.addEventListener('click', () => {
    if (licModal) licModal.style.display = 'none';
  });
}
if (btnCopyHwid) {
  btnCopyHwid.addEventListener('click', () => {
    if (currentHWID) {
      navigator.clipboard.writeText(currentHWID).then(() => {
        showToast('📋 Đã sao chép mã HWID vào bộ nhớ đệm!', 'success');
      }).catch(() => {
        showToast(`HWID: ${currentHWID}`, 'info', 5000);
      });
    }
  });
}
if (btnLicSubmit && inpLicKey) {
  btnLicSubmit.addEventListener('click', async () => {
    const key = inpLicKey.value.trim();
    if (!key) {
      if (licStatusMsg) {
        licStatusMsg.style.display = 'block';
        licStatusMsg.style.color = '#f87171';
        licStatusMsg.textContent = '⚠️ Vui lòng nhập mã bản quyền (License Key).';
      }
      return;
    }

    btnLicSubmit.disabled = true;
    btnLicSubmit.textContent = '⏳ Đang xác thực...';
    if (licStatusMsg) {
      licStatusMsg.style.display = 'block';
      licStatusMsg.style.color = '#38bdf8';
      licStatusMsg.textContent = 'Đang kết nối đến máy chủ 2tamne.site...';
    }

    try {
      const res = await fetch('/api/license/activate', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ license_key: key })
      });
      const data = await res.json();
      if (data.ok) {
        if (licStatusMsg) {
          licStatusMsg.style.color = '#34d399';
          licStatusMsg.textContent = `✅ ${data.message || 'Kích hoạt bản quyền thành công!'}`;
        }
        showToast(`🎉 Kích hoạt thành công gói ${data.tier || 'VIP'}!`, 'success', 4000);
        await checkLicenseStatus();
        setTimeout(() => {
          if (licModal) licModal.style.display = 'none';
        }, 1200);
      } else {
        if (licStatusMsg) {
          licStatusMsg.style.color = '#f87171';
          licStatusMsg.textContent = `❌ ${data.message || 'Mã bản quyền không hợp lệ!'}`;
        }
        showToast(`Lỗi: ${data.message || 'Kích hoạt thất bại'}`, 'error', 5000);
      }
    } catch (err) {
      if (licStatusMsg) {
        licStatusMsg.style.color = '#f87171';
        licStatusMsg.textContent = `❌ Không thể kết nối đến máy chủ 2tamne.site (${err.message})`;
      }
    } finally {
      btnLicSubmit.disabled = false;
      btnLicSubmit.textContent = '⚡ Kích Hoạt Ngay';
    }
  });
}

// ─── 🚀 Auto-Update Management ───
let latestUpdateInfo = null;

async function checkForAppUpdates() {
  const updBadge = $('update-badge');
  const updModal = $('update-modal');
  const curVEl = $('upd-current-v');
  const latVEl = $('upd-latest-v');
  const notesEl = $('upd-release-notes');
  const devMsgEl = $('upd-dev-msg');
  const btnNow = $('btn-update-now');

  try {
    const res = await fetch('/api/update/check');
    const data = await res.json();
    latestUpdateInfo = data;

    if (data.update_available) {
      if (curVEl) curVEl.textContent = data.current_version;
      if (latVEl) latVEl.textContent = data.latest_version;
      if (notesEl) notesEl.textContent = data.release_notes || 'Không có mô tả chi tiết.';

      // Show header badge
      if (updBadge) {
        updBadge.style.display = 'inline-flex';
        const updText = $('update-badge-text');
        if (updText) updText.textContent = `Có bản v${data.latest_version}!`;
      }

      // Check if user skipped this specific version
      const skippedV = localStorage.getItem('skipped_update_version');
      if (skippedV !== data.latest_version) {
        if (updModal) updModal.style.display = 'flex';
      }

      // If dev mode, inform user
      if (data.is_dev_mode) {
        if (devMsgEl) devMsgEl.style.display = 'block';
        if (btnNow) {
          btnNow.textContent = '🌐 Xem Release trên GitHub';
          btnNow.onclick = () => {
            if (data.release_url) window.open(data.release_url, '_blank');
          };
        }
      }
    } else {
      if (updBadge) updBadge.style.display = 'none';
    }
  } catch (err) {
    console.warn('[Updater] Check failed silently:', err);
  }
}

// Update Modal event listeners
const updBadge = $('update-badge');
const updModal = $('update-modal');
const btnUpdClose = $('btn-update-close');
const btnUpdLater = $('btn-update-later');
const btnUpdSkip = $('btn-update-skip');
const btnUpdNow = $('btn-update-now');

if (updBadge) {
  updBadge.addEventListener('click', () => {
    if (updModal) updModal.style.display = 'flex';
  });
}
if (btnUpdClose) {
  btnUpdClose.addEventListener('click', () => {
    if (updModal) updModal.style.display = 'none';
  });
}
if (btnUpdLater) {
  btnUpdLater.addEventListener('click', () => {
    if (updModal) updModal.style.display = 'none';
  });
}
if (btnUpdSkip) {
  btnUpdSkip.addEventListener('click', () => {
    if (latestUpdateInfo && latestUpdateInfo.latest_version) {
      localStorage.setItem('skipped_update_version', latestUpdateInfo.latest_version);
      showToast(`Đã bỏ qua thông báo cho phiên bản v${latestUpdateInfo.latest_version}`, 'info', 3000);
    }
    if (updModal) updModal.style.display = 'none';
  });
}
if (btnUpdNow) {
  btnUpdNow.addEventListener('click', async () => {
    if (latestUpdateInfo && latestUpdateInfo.is_dev_mode) {
      if (latestUpdateInfo.release_url) window.open(latestUpdateInfo.release_url, '_blank');
      return;
    }

    if (!latestUpdateInfo || !latestUpdateInfo.asset || !latestUpdateInfo.asset.download_url) {
      showToast('⚠️ Không tìm thấy gói tải về phù hợp cho hệ điều hành này.', 'warning', 4000);
      if (latestUpdateInfo && latestUpdateInfo.release_url) {
        window.open(latestUpdateInfo.release_url, '_blank');
      }
      return;
    }

    const progBox = $('upd-progress-box');
    const progText = $('upd-progress-text');
    const progPct = $('upd-progress-pct');
    const progBar = $('upd-progress-bar');

    if (progBox) progBox.style.display = 'block';
    btnUpdNow.disabled = true;
    btnUpdNow.textContent = '⏳ Đang xử lý...';

    try {
      if (progText) progText.textContent = 'Đang tải bản cập nhật...';
      if (progPct) progPct.textContent = '35%';
      if (progBar) progBar.style.width = '35%';

      const resDl = await fetch('/api/update/download', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ download_url: latestUpdateInfo.asset.download_url })
      });
      const dlData = await resDl.json();

      if (!dlData.ok) {
        throw new Error(dlData.message || 'Lỗi khi tải file cập nhật');
      }

      if (progText) progText.textContent = 'Đang giải nén và kiểm tra file...';
      if (progPct) progPct.textContent = '75%';
      if (progBar) progBar.style.width = '75%';

      const resApp = await fetch('/api/update/apply', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ staging_dir: dlData.staging_dir })
      });
      const appData = await resApp.json();

      if (!appData.ok) {
        throw new Error(appData.message || 'Lỗi khi cài đặt bản cập nhật');
      }

      if (progText) progText.textContent = '🚀 Đang tự động nạp phiên bản mới...';
      if (progPct) progPct.textContent = '100%';
      if (progBar) progBar.style.width = '100%';

      showToast('🎉 Đã cập nhật lên v' + (latestUpdateInfo.latest_version || '') + ' thành công! Máy chủ đang tự khởi động lại...', 'success', 8000);
      if (updModal) updModal.style.display = 'none';
      if (updBadge) updBadge.style.display = 'none';

      // Auto poll health until new server responds, then seamlessly refresh
      let pollAttempts = 0;
      setTimeout(() => {
        const pollTimer = setInterval(async () => {
          pollAttempts++;
          try {
            const checkRes = await fetch('/api/health?_t=' + Date.now());
            if (checkRes.ok) {
              clearInterval(pollTimer);
              location.reload();
            }
          } catch (e) {
            if (pollAttempts > 25) {
              clearInterval(pollTimer);
              location.reload();
            }
          }
        }, 800);
      }, 1500);

    } catch (err) {
      showToast(`❌ Cập nhật thất bại: ${err.message}`, 'error', 6000);
      if (progText) progText.textContent = `Lỗi: ${err.message}`;
    } finally {
      btnUpdNow.disabled = false;
      btnUpdNow.textContent = '🚀 Cập Nhật Ngay';
    }
  });
}

// =============================================================================
// SERVER SHUTDOWN MANAGEMENT
// =============================================================================
const btnShutdownApp = $('btn-shutdown-app');
const shutdownModal = $('shutdown-modal');
const btnShutdownClose = $('btn-shutdown-close');
const btnShutdownCancel = $('btn-shutdown-cancel');
const btnShutdownConfirm = $('btn-shutdown-confirm');

if (btnShutdownApp && shutdownModal) {
  btnShutdownApp.addEventListener('click', () => {
    shutdownModal.style.display = 'flex';
  });

  const closeShutdownModal = () => {
    shutdownModal.style.display = 'none';
  };

  if (btnShutdownClose) btnShutdownClose.addEventListener('click', closeShutdownModal);
  if (btnShutdownCancel) btnShutdownCancel.addEventListener('click', closeShutdownModal);

  if (btnShutdownConfirm) {
    btnShutdownConfirm.addEventListener('click', async () => {
      btnShutdownConfirm.disabled = true;
      btnShutdownConfirm.textContent = '⏳ Đang tắt...';
      try {
        await fetch('/api/server/shutdown', { method: 'POST' });
        shutdownModal.innerHTML = `
          <div class="diag-modal-card" style="max-width:420px;border:1px solid #64748b;text-align:center;padding:32px 20px;background:#0f172a">
            <div style="font-size:44px;margin-bottom:12px">💤</div>
            <div style="font-size:16px;font-weight:800;color:#f8fafc;margin-bottom:8px">Ứng Dụng Đã Tắt Hoàn Toàn</div>
            <div style="font-size:13px;color:#94a3b8;line-height:1.5">Máy chủ cục bộ và toàn bộ tiến trình ngầm đã dừng thành công. Bạn có thể an tâm đóng tab trình duyệt này.</div>
          </div>
        `;
        showToast('🛑 Ứng dụng đã tắt hoàn toàn. Tạm biệt!', 'info', 10000);
      } catch (e) {
        showToast('🛑 Đã gửi lệnh tắt ứng dụng.', 'info', 5000);
      }
    });
  }
}

// Initial initialization
updateFooterInfo();
fetchProjectList();
syncSubtitleStyleControls();
checkLicenseStatus(true);
checkForAppUpdates();



