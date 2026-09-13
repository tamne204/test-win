/**
 * apps/capcut-v2/desktop/src/renderer/i18n.js
 * Internationalization (i18n) Engine for 2TOOLNE AutoEdit (GAP-13).
 * Provides Vietnamese (vi) and English (en) dictionaries and dynamic DOM localization.
 */

const translations = {
  vi: {
    // App Header
    'nav.studio': 'Studio Làm Việc',
    'nav.subtitles': 'Phân Hệ Phụ Đề',
    'nav.queue': 'Hàng Đợi Xử Lý',
    'nav.projects': 'Dự Án Đã Tạo',
    'nav.flow': 'Google Flow',
    'nav.upscale': 'AI Upscale 4K',
    'nav.cloud': 'Cloud Lưu Trữ',
    'nav.account': 'Tài Khoản & Bản Quyền',
    'nav.settings': 'Cài Đặt',
    'header.badge_ready': 'SẴN SÀNG',
    'header.tokens': 'Token',

    // Studio View
    'studio.title': 'Studio Tạo Dự Án CapCut',
    'studio.sub': 'Tự động tính toán khung hình, căn chỉnh kịch bản và hiệu ứng chuyển cảnh CapCut chuẩn xác',
    'studio.drop_title': 'Kéo thả ảnh, thư mục hoặc file ZIP / RAR vào đây',
    'studio.drop_hint': 'Hỗ trợ PNG, JPG, WEBP, BMP, TIFF, ZIP, RAR, Folder',
    'studio.btn_browse_files': '📁 Chọn tệp',
    'studio.btn_browse_folder': '📂 Chọn thư mục',
    'studio.auto_upscale': '⚡ Tự động AI Upscale ảnh lên 2K/4K (Trừ Token ví)',
    'studio.media_list': 'Danh sách hình ảnh',
    'studio.btn_clear_media': '✕ Xóa tất cả',
    'studio.project_name': 'Tên Dự Án CapCut:',
    'studio.ratio': 'Tỉ Lệ Khung Hình:',
    'studio.timing_mode': 'Chế Độ Căn Thời Gian (Timing Mode):',
    'studio.btn_create_project': '🚀 Tạo Dự Án Ngay',
    'studio.btn_add_queue': '➕ Thêm Vào Hàng Đợi',

    // Subtitle View
    'sub.title': 'Phân Hệ Phụ Đề Tự Động (AutoSub & Forced Alignment)',
    'sub.sub': 'So khớp kịch bản lời thoại hoặc tự động tạo phụ đề bằng trí tuệ nhân tạo Whisper',
    'sub.tab_fa': '🎯 1. So Khớp Kịch Bản (Forced Alignment)',
    'sub.tab_stt': '🎙️ 2. Tự Động Tạo Sub (AutoSub)',
    'sub.audio_title': 'Chọn tệp âm thanh (giọng đọc)',
    'sub.btn_choose_audio': '📁 Chọn File Audio / Voice',
    'sub.btn_start_align': '🎯 BẮT ĐẦU SO KHỚP KỊCH BẢN (Forced Alignment)',
    'sub.btn_start_autosub': '🎙️ BẮT ĐẦU TỰ ĐỘNG TẠO PHỤ ĐỀ (AutoSub)',

    // Queue View
    'queue.title': 'Hàng Đợi Xử Lý (Pipeline Queue V2)',
    'queue.sub': 'Quản lý hàng đợi xử lý Pipeline AI và tạo dự án CapCut tự động theo thứ tự FIFO',
    'queue.btn_resume': 'Chạy Hàng Đợi',
    'queue.btn_pause': 'Tạm Dừng',
    'queue.btn_clear': 'Xóa Đã Xong',

    // AI Upscale View
    'upscale.title': 'Phân Hệ 2TOOLNE AI Upscale 4K',
    'upscale.sub': 'Xử lý nâng cấp độ phân giải ảnh 2K / 4K trực tiếp bằng GPU Vulkan NCNN nội bộ.',
    'upscale.drop_title': 'Chọn ảnh cần Upscale 2K / 4K',
    'upscale.btn_browse': '📁 Chọn ảnh',
    'upscale.btn_run': '✨ BẮT ĐẦU UPSCALE',
    'upscale.btn_open_dir': '📂 Mở Thư Mục',

    // Settings View
    'settings.title': 'Cấu Hình Ứng Dụng',
    'settings.capcut_ver': 'Phiên Bản CapCut:',
    'settings.capcut_path': 'Thư Mục Dự Án CapCut:',
    'settings.auto_open': 'Tự động kích hoạt CapCut mở dự án ngay khi tạo xong',
    'settings.render_dir': '📁 Thư Mục Xuất File MP4 Mặc Định',
    'settings.language': '🌐 Ngôn Ngữ Giao Diện (Language)',
    'settings.asr_engine': '⚡ Động Cơ Nhận Diện Thoại & Phụ Đề (ASR Engine)',
    'settings.diagnostics': '🩺 Chẩn Đoán & Hỗ Trợ Kỹ Thuật',
    'settings.btn_export_diag': '📦 Xuất Gói Chẩn Đoán (Diagnostic Bundle)',
    'settings.update': '🔄 Cập Nhật Phiên Bản Ứng Dụng',
    'settings.btn_check_update': '🔄 Kiểm Tra Cập Nhật',
  },
  en: {
    // App Header
    'nav.studio': 'Studio Workspace',
    'nav.subtitles': 'Subtitle Studio',
    'nav.queue': 'Job Queue',
    'nav.projects': 'Projects',
    'nav.flow': 'Google Flow',
    'nav.upscale': 'AI Upscale 4K',
    'nav.cloud': 'Cloud Storage',
    'nav.account': 'Account & License',
    'nav.settings': 'Settings',
    'header.badge_ready': 'READY',
    'header.tokens': 'Tokens',

    // Studio View
    'studio.title': 'CapCut Project Studio',
    'studio.sub': 'Automated keyframe timing, script alignment, and cinematic motion curves for CapCut',
    'studio.drop_title': 'Drag & drop images, folders, or ZIP / RAR archives here',
    'studio.drop_hint': 'Supports PNG, JPG, WEBP, BMP, TIFF, ZIP, RAR, Folders',
    'studio.btn_browse_files': '📁 Browse Files',
    'studio.btn_browse_folder': '📂 Browse Folder',
    'studio.auto_upscale': '⚡ Auto AI Upscale to 2K/4K (Consumes Wallet Tokens)',
    'studio.media_list': 'Image Media Sequence',
    'studio.btn_clear_media': '✕ Clear All',
    'studio.project_name': 'CapCut Project Name:',
    'studio.ratio': 'Aspect Ratio:',
    'studio.timing_mode': 'Timing Mode:',
    'studio.btn_create_project': '🚀 CREATE CAPCUT PROJECT NOW',
    'studio.btn_add_queue': '➕ Add to Job Queue',

    // Subtitle View
    'sub.title': 'Automatic Subtitle Studio (AutoSub & Forced Alignment)',
    'sub.sub': 'Acoustic forced alignment or standalone speech transcription powered by Whisper',
    'sub.tab_fa': '🎯 1. Forced Alignment (With Script)',
    'sub.tab_stt': '🎙️ 2. AutoSub (Speech to Text)',
    'sub.audio_title': 'Select voice / dialogue audio file',
    'sub.btn_choose_audio': '📁 Select Audio / Voice File',
    'sub.btn_start_align': '🎯 START FORCED ALIGNMENT',
    'sub.btn_start_autosub': '🎙️ START AUTOSUB GENERATION',

    // Queue View
    'queue.title': 'Pipeline Queue V2 (AI Workflow & CapCut)',
    'queue.sub': 'Manage unattended, sequential AI video pipeline jobs safely',
    'queue.btn_resume': 'Resume Queue',
    'queue.btn_pause': 'Pause Queue',
    'queue.btn_clear': 'Clear Completed',

    // AI Upscale View
    'upscale.title': '2TOOLNE AI Upscale 4K Engine',
    'upscale.sub': 'Hardware-accelerated 2K / 4K image super-resolution using local Vulkan NCNN.',
    'upscale.drop_title': 'Select images for 2K / 4K super-resolution',
    'upscale.btn_browse': '📁 Browse Images',
    'upscale.btn_run': '✨ START UPSCALE',
    'upscale.btn_open_dir': '📂 Open Folder',

    // Settings View
    'settings.title': 'Application Settings',
    'settings.capcut_ver': 'CapCut Version:',
    'settings.capcut_path': 'CapCut Project Root:',
    'settings.auto_open': 'Automatically launch CapCut when project draft is created',
    'settings.render_dir': '📁 Default MP4 Video Output Directory',
    'settings.language': '🌐 Interface Language',
    'settings.asr_engine': '⚡ Speech Recognition & Subtitle Engine (ASR)',
    'settings.diagnostics': '🩺 Diagnostics & Technical Support',
    'settings.btn_export_diag': '📦 Export Diagnostic Bundle',
    'settings.update': '🔄 Application Version & Updates',
    'settings.btn_check_update': '🔄 Check for Updates',
  },
};

class I18nEngine {
  constructor() {
    this.currentLang = 'vi';
  }

  init() {
    let saved = 'vi';
    try {
      saved = localStorage.getItem('app_language') || 'vi';
    } catch (_) {}
    this.setLanguage(saved, false);
  }

  t(key, fallback = '') {
    const dict = translations[this.currentLang] || translations.vi;
    if (dict && dict[key]) return dict[key];
    if (translations.vi && translations.vi[key]) return translations.vi[key];
    if (translations.en && translations.en[key]) return translations.en[key];
    return fallback;
  }

  setLanguage(lang, persist = true) {
    if (!translations[lang]) lang = 'vi';
    this.currentLang = lang;
    if (persist) {
      try {
        localStorage.setItem('app_language', lang);
      } catch (_) {}
    }
    this.applyToDOM();
  }

  applyToDOM() {
    if (typeof document === 'undefined') return;
    document.querySelectorAll('[data-i18n]').forEach((el) => {
      const key = el.getAttribute('data-i18n');
      const text = this.t(key);
      if (text) {
        if (el.tagName === 'INPUT' && el.type === 'button') {
          el.value = text;
        } else {
          el.textContent = text;
        }
      }
    });

    document.querySelectorAll('[data-i18n-placeholder]').forEach((el) => {
      const key = el.getAttribute('data-i18n-placeholder');
      const text = this.t(key);
      if (text) el.placeholder = text;
    });

    document.querySelectorAll('[data-i18n-title]').forEach((el) => {
      const key = el.getAttribute('data-i18n-title');
      const text = this.t(key);
      if (text) el.title = text;
    });
  }
}

const i18n = new I18nEngine();
if (typeof window !== 'undefined') {
  window.i18n = i18n;
}
if (typeof module !== 'undefined' && module.exports) {
  module.exports = { i18n, translations };
}
