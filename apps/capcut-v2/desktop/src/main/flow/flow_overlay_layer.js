/**
 * apps/capcut-v2/desktop/src/main/flow/flow_overlay_layer.js
 * 
 * 2TOOLNE Google Flow Modern Overlay Layer (In-Page WebContents Layer)
 * Single Canonical Google Flow UI Overlay Module
 *
 * Responsibilities:
 * - FlowExecutionScrim (AutoExecutionFrame): Crisp 2px border (20px radius) + wide uniform inward breathing glow (~48-64px).
 * - FlowRealtimeStatusPill: Top-center floating single-line status indicator with auto-dismiss.
 * - FlowMiniRunner: Bottom-right 340px glassmorphic runner showing task progress, single status line, pause, and takeover.
 * - FlowLauncherPill: Minimized 42x42px launcher with 2TOOLNE branding and status dot when IDLE / minimized.
 * - FlowSettingsButton: Header [ Settings ] button with profile selector and persistent configuration modal.
 * - FlowTileOverlayLayer: MutationObserver observing Flow media tiles, injecting [+] reference pin and highlight borders.
 * 
 * Invariants:
 * - Single instance guarantee: SettingsButton <= 1, MiniRunner <= 1, Launcher <= 1, StatusPill <= 1, AutoFrame <= 1.
 * - Resilient MutationObserver ensureMounted() lifecycle surviving all Google Flow SPA re-renders and navigations.
 * - Immediate state rehydration across tab switches, profile changes, and DOM recreations.
 * - Pointer safety: decorative layers pointer-events: none; interactive controls pointer-events: auto.
 */

(function init2ToolneFlowOverlay() {
  if (window.__2toolneFlowOverlayMounted && window.__toolneFlowOverlay) {
    try {
      window.__toolneFlowOverlay.ensureMounted();
    } catch (_) {}
    return;
  }
  window.__2toolneFlowOverlayMounted = true;

  // --- BRAND DESIGN TOKENS ---
  const TOKENS = {
    brandPrimary: '#FF7A00',
    brandPrimaryRgb: '255, 122, 0',
    brandGlow: 'rgba(255, 122, 0, 0.45)',
    success: '#35C46A',
    successRgb: '53, 196, 106',
    warning: '#F5A623',
    warningRgb: '245, 166, 35',
    danger: '#F05A5A',
    dangerRgb: '240, 90, 90',
    bgDarkGlass: 'linear-gradient(180deg, rgba(20, 22, 28, 0.88), rgba(12, 14, 18, 0.92))',
    borderGlass: 'rgba(255, 255, 255, 0.12)',
    borderGlassTop: 'rgba(255, 255, 255, 0.22)',
    fontFamily: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Inter", sans-serif',
  };

  // --- STYLES INJECTION ---
  function injectOverlayStyles() {
    let styleEl = document.getElementById('toolne-flow-overlay-styles');
    if (!styleEl) {
      styleEl = document.createElement('style');
      styleEl.id = 'toolne-flow-overlay-styles';
      (document.head || document.documentElement).appendChild(styleEl);
    }
    styleEl.textContent = `
      /* ==========================================================
         2TOOLNE AUTO BORDER & AMBIENT GLOW SYSTEM
         Architecture Layer: In-Page WebContents Overlay
         Composition:
           - Frame Container: fixed inset:0, border-radius: 20px, overflow: hidden
           - Layer 1 (Border): Crisp 2px border, stable opacity, zero blink
           - Layer 2 (Ambient Glow): Inward multi-stop spread (~48-64px), breathing pulse
         ========================================================== */
      :root {
        --toolne-auto-frame-radius: 20px;
        --toolne-auto-border-width: 2px;
      }

      [id="2toolne-flow-auto-frame"],
      #toolne-execution-scrim,
      .toolne-flow-auto-frame {
        position: fixed;
        inset: 0;
        z-index: 2147483640;
        pointer-events: none !important;
        box-sizing: border-box;
        border-radius: var(--toolne-auto-frame-radius, 20px);
        overflow: hidden;
        transition: opacity 0.22s ease-out;
      }

      /* Unified Base Bindings for Border (.toolne-auto-border) and Glow (.toolne-auto-glow) */
      .toolne-auto-border,
      .toolne-auto-glow,
      [id="2toolne-flow-auto-frame"]::before,
      [id="2toolne-flow-auto-frame"]::after,
      #toolne-execution-scrim::before,
      #toolne-execution-scrim::after,
      .toolne-flow-auto-frame::before,
      .toolne-flow-auto-frame::after {
        content: '';
        position: absolute;
        inset: 0;
        border-radius: inherit;
        pointer-events: none !important;
        box-sizing: border-box;
        opacity: 0;
        transition: opacity 0.22s ease-out, border-color 0.22s ease-out;
      }

      /* --- LAYER 1: AUTO BORDER --- */
      /* Crisp 2px border, perfectly rounded 4 corners, stable opacity, zero animation */
      .toolne-auto-border,
      [id="2toolne-flow-auto-frame"]::before,
      #toolne-execution-scrim::before,
      .toolne-flow-auto-frame::before {
        z-index: 2;
        border: var(--toolne-auto-border-width, 2px) solid transparent;
      }

      /* --- LAYER 2: AMBIENT GLOW --- */
      /* Wide uniform inward fade (~48-64px), multi-stop spread, subtle breathing pulse */
      .toolne-auto-glow,
      [id="2toolne-flow-auto-frame"]::after,
      #toolne-execution-scrim::after,
      .toolne-flow-auto-frame::after {
        z-index: 1;
      }

      /* --- 1. AUTO ACTIVE / RUNNING / PROCESSING --- */
      [id="2toolne-flow-auto-frame"].scrim-auto .toolne-auto-border,
      [id="2toolne-flow-auto-frame"].scrim-auto::before,
      #toolne-execution-scrim.scrim-auto .toolne-auto-border,
      #toolne-execution-scrim.scrim-auto::before,
      .toolne-flow-auto-frame.scrim-auto .toolne-auto-border,
      .toolne-flow-auto-frame.scrim-auto::before {
        opacity: 1;
        border-color: ${TOKENS.brandPrimary};
        box-shadow: inset 0 0 2px rgba(${TOKENS.brandPrimaryRgb}, 0.40);
      }

      [id="2toolne-flow-auto-frame"].scrim-auto .toolne-auto-glow,
      [id="2toolne-flow-auto-frame"].scrim-auto::after,
      #toolne-execution-scrim.scrim-auto .toolne-auto-glow,
      #toolne-execution-scrim.scrim-auto::after,
      .toolne-flow-auto-frame.scrim-auto .toolne-auto-glow,
      .toolne-flow-auto-frame.scrim-auto::after {
        opacity: 0.94;
        box-shadow:
          inset 0 0 16px 2px rgba(${TOKENS.brandPrimaryRgb}, 0.28),
          inset 0 0 32px 4px rgba(${TOKENS.brandPrimaryRgb}, 0.18),
          inset 0 0 48px 6px rgba(${TOKENS.brandPrimaryRgb}, 0.10),
          inset 0 0 64px 8px rgba(${TOKENS.brandPrimaryRgb}, 0.04);
        animation: twoToolneAutoGlowBreath 2.8s ease-in-out infinite;
      }

      /* --- 2. AUTO WAITING (Subtle, slower breathing, lower intensity) --- */
      [id="2toolne-flow-auto-frame"].scrim-waiting .toolne-auto-border,
      [id="2toolne-flow-auto-frame"].scrim-waiting::before,
      #toolne-execution-scrim.scrim-waiting .toolne-auto-border,
      #toolne-execution-scrim.scrim-waiting::before,
      .toolne-flow-auto-frame.scrim-waiting .toolne-auto-border,
      .toolne-flow-auto-frame.scrim-waiting::before {
        opacity: 0.85;
        border-color: ${TOKENS.brandPrimary};
      }

      [id="2toolne-flow-auto-frame"].scrim-waiting .toolne-auto-glow,
      [id="2toolne-flow-auto-frame"].scrim-waiting::after,
      #toolne-execution-scrim.scrim-waiting .toolne-auto-glow,
      #toolne-execution-scrim.scrim-waiting::after,
      .toolne-flow-auto-frame.scrim-waiting .toolne-auto-glow,
      .toolne-flow-auto-frame.scrim-waiting::after {
        opacity: 0.70;
        box-shadow:
          inset 0 0 14px 2px rgba(${TOKENS.brandPrimaryRgb}, 0.20),
          inset 0 0 28px 4px rgba(${TOKENS.brandPrimaryRgb}, 0.12),
          inset 0 0 44px 6px rgba(${TOKENS.brandPrimaryRgb}, 0.06);
        animation: twoToolneAutoGlowBreathWaiting 3.4s ease-in-out infinite;
      }

      /* --- 3. PAUSED (Amber border, subtle glow, breathing OFF) --- */
      [id="2toolne-flow-auto-frame"].scrim-paused .toolne-auto-border,
      [id="2toolne-flow-auto-frame"].scrim-paused::before,
      #toolne-execution-scrim.scrim-paused .toolne-auto-border,
      #toolne-execution-scrim.scrim-paused::before,
      .toolne-flow-auto-frame.scrim-paused .toolne-auto-border,
      .toolne-flow-auto-frame.scrim-paused::before {
        opacity: 0.90;
        border-color: ${TOKENS.warning};
        box-shadow: inset 0 0 2px rgba(${TOKENS.warningRgb}, 0.35);
      }

      [id="2toolne-flow-auto-frame"].scrim-paused .toolne-auto-glow,
      [id="2toolne-flow-auto-frame"].scrim-paused::after,
      #toolne-execution-scrim.scrim-paused .toolne-auto-glow,
      #toolne-execution-scrim.scrim-paused::after,
      .toolne-flow-auto-frame.scrim-paused .toolne-auto-glow,
      .toolne-flow-auto-frame.scrim-paused::after {
        opacity: 0.76;
        box-shadow:
          inset 0 0 16px 2px rgba(${TOKENS.warningRgb}, 0.20),
          inset 0 0 32px 4px rgba(${TOKENS.warningRgb}, 0.12),
          inset 0 0 48px 6px rgba(${TOKENS.warningRgb}, 0.06);
        animation: none; /* Breathing OFF */
      }

      /* --- 4. ERROR (Red border, static glow, breathing OFF) --- */
      [id="2toolne-flow-auto-frame"].scrim-error .toolne-auto-border,
      [id="2toolne-flow-auto-frame"].scrim-error::before,
      #toolne-execution-scrim.scrim-error .toolne-auto-border,
      #toolne-execution-scrim.scrim-error::before,
      .toolne-flow-auto-frame.scrim-error .toolne-auto-border,
      .toolne-flow-auto-frame.scrim-error::before {
        opacity: 0.95;
        border-color: ${TOKENS.danger};
        box-shadow: inset 0 0 3px rgba(${TOKENS.dangerRgb}, 0.40);
      }

      [id="2toolne-flow-auto-frame"].scrim-error .toolne-auto-glow,
      [id="2toolne-flow-auto-frame"].scrim-error::after,
      #toolne-execution-scrim.scrim-error .toolne-auto-glow,
      #toolne-execution-scrim.scrim-error::after,
      .toolne-flow-auto-frame.scrim-error .toolne-auto-glow,
      .toolne-flow-auto-frame.scrim-error::after {
        opacity: 0.85;
        box-shadow:
          inset 0 0 18px 2px rgba(${TOKENS.dangerRgb}, 0.22),
          inset 0 0 36px 4px rgba(${TOKENS.dangerRgb}, 0.12),
          inset 0 0 54px 6px rgba(${TOKENS.dangerRgb}, 0.06);
        animation: none;
      }

      /* --- 5. COMPLETE / SUCCESS (Green flash then collapse) --- */
      [id="2toolne-flow-auto-frame"].scrim-success .toolne-auto-border,
      [id="2toolne-flow-auto-frame"].scrim-success::before,
      #toolne-execution-scrim.scrim-success .toolne-auto-border,
      #toolne-execution-scrim.scrim-success::before,
      .toolne-flow-auto-frame.scrim-success .toolne-auto-border,
      .toolne-flow-auto-frame.scrim-success::before {
        opacity: 1;
        border-color: ${TOKENS.success};
        box-shadow: inset 0 0 3px rgba(${TOKENS.successRgb}, 0.45);
      }

      [id="2toolne-flow-auto-frame"].scrim-success .toolne-auto-glow,
      [id="2toolne-flow-auto-frame"].scrim-success::after,
      #toolne-execution-scrim.scrim-success .toolne-auto-glow,
      #toolne-execution-scrim.scrim-success::after,
      .toolne-flow-auto-frame.scrim-success .toolne-auto-glow,
      .toolne-flow-auto-frame.scrim-success::after {
        opacity: 0.90;
        box-shadow:
          inset 0 0 18px 2px rgba(${TOKENS.successRgb}, 0.22),
          inset 0 0 36px 4px rgba(${TOKENS.successRgb}, 0.12),
          inset 0 0 54px 6px rgba(${TOKENS.successRgb}, 0.06);
        animation: none;
      }

      /* --- 6. MANUAL / IDLE (Zero glow, zero border, completely off) --- */
      [id="2toolne-flow-auto-frame"].scrim-manual .toolne-auto-border,
      [id="2toolne-flow-auto-frame"].scrim-manual .toolne-auto-glow,
      [id="2toolne-flow-auto-frame"].scrim-idle .toolne-auto-border,
      [id="2toolne-flow-auto-frame"].scrim-idle .toolne-auto-glow,
      #toolne-execution-scrim.scrim-manual .toolne-auto-border,
      #toolne-execution-scrim.scrim-manual .toolne-auto-glow,
      #toolne-execution-scrim.scrim-idle .toolne-auto-border,
      #toolne-execution-scrim.scrim-idle .toolne-auto-glow,
      .toolne-flow-auto-frame.scrim-manual .toolne-auto-border,
      .toolne-flow-auto-frame.scrim-manual .toolne-auto-glow,
      .toolne-flow-auto-frame.scrim-idle .toolne-auto-border,
      .toolne-flow-auto-frame.scrim-idle .toolne-auto-glow,
      [id="2toolne-flow-auto-frame"].scrim-manual::before,
      [id="2toolne-flow-auto-frame"].scrim-manual::after,
      [id="2toolne-flow-auto-frame"].scrim-idle::before,
      [id="2toolne-flow-auto-frame"].scrim-idle::after,
      #toolne-execution-scrim.scrim-manual::before,
      #toolne-execution-scrim.scrim-manual::after,
      #toolne-execution-scrim.scrim-idle::before,
      #toolne-execution-scrim.scrim-idle::after,
      .toolne-flow-auto-frame.scrim-manual::before,
      .toolne-flow-auto-frame.scrim-manual::after,
      .toolne-flow-auto-frame.scrim-idle::before,
      .toolne-flow-auto-frame.scrim-idle::after {
        opacity: 0 !important;
        border-color: transparent !important;
        box-shadow: none !important;
        animation: none !important;
      }

      /* --- BREATHING PULSE KEYFRAMES --- */
      /* Smooth organic breath between 0.70 and 1.00 intensity on glow layer only */
      @keyframes twoToolneAutoGlowBreath {
        0%, 100% {
          opacity: 0.70;
        }
        50% {
          opacity: 1.00;
        }
      }

      @keyframes twoToolneAutoGlowBreathWaiting {
        0%, 100% {
          opacity: 0.50;
        }
        50% {
          opacity: 0.75;
        }
      }

      /* Reduced motion support */
      @media (prefers-reduced-motion: reduce) {
        .toolne-auto-glow,
        [id="2toolne-flow-auto-frame"]::after,
        #toolne-execution-scrim::after,
        .toolne-flow-auto-frame::after {
          animation: none !important;
          opacity: 0.85 !important;
        }
      }

      @keyframes toolneSpin {
        to { transform: rotate(360deg); }
      }

      /* Single Realtime Status Pill */
      [id="2toolne-flow-status-pill"],
      #toolne-status-pill {
        position: fixed;
        top: 68px;
        left: 50%;
        transform: translateX(-50%);
        z-index: 2147483645;
        display: none;
        align-items: center;
        gap: 9px;
        padding: 8px 18px;
        border-radius: 20px;
        background: ${TOKENS.bgDarkGlass};
        border: 1px solid ${TOKENS.borderGlass};
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.45), inset 0 1px 0 ${TOKENS.borderGlassTop};
        backdrop-filter: blur(16px);
        -webkit-backdrop-filter: blur(16px);
        color: #fff;
        font-family: ${TOKENS.fontFamily};
        font-size: 12.5px;
        font-weight: 600;
        pointer-events: none;
        user-select: none;
        width: max-content;
        width: fit-content;
        max-width: calc(100vw - 40px);
        white-space: nowrap;
        transition: opacity 0.2s ease, transform 0.2s ease;
      }

      /* Flow Mini Runner */
      [id="2toolne-flow-mini-runner"],
      #toolne-mini-runner {
        position: fixed;
        bottom: 16px;
        right: 16px;
        width: 340px;
        z-index: 2147483646;
        display: none;
        flex-direction: column;
        border-radius: 14px;
        background: ${TOKENS.bgDarkGlass};
        border: 1px solid ${TOKENS.borderGlass};
        box-shadow: 0 18px 45px rgba(0, 0, 0, 0.45), inset 0 1px 0 ${TOKENS.borderGlassTop};
        backdrop-filter: blur(20px) saturate(1.2);
        -webkit-backdrop-filter: blur(20px) saturate(1.2);
        color: #F5F5F5;
        font-family: ${TOKENS.fontFamily};
        font-size: 12px;
        overflow: hidden;
        user-select: none;
        pointer-events: auto;
        transition: transform 0.18s ease, opacity 0.18s ease;
      }
      .toolne-runner-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 9px 12px;
        background: rgba(255, 255, 255, 0.03);
        border-bottom: 1px solid rgba(255, 255, 255, 0.06);
      }
      .toolne-runner-title-group {
        display: flex;
        align-items: center;
        gap: 6px;
      }
      .toolne-runner-logo {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 20px;
        height: 20px;
        border-radius: 5px;
        background: rgba(${TOKENS.brandPrimaryRgb}, 0.2);
        color: ${TOKENS.brandPrimary};
        font-weight: 800;
        font-size: 11px;
      }
      .toolne-runner-title {
        font-weight: 700;
        font-size: 12.5px;
        color: #FFF;
        letter-spacing: 0.2px;
      }
      .toolne-runner-mode-badge {
        font-size: 10px;
        font-weight: 700;
        padding: 2px 6px;
        border-radius: 10px;
        text-transform: uppercase;
      }
      .toolne-runner-mode-badge.auto {
        background: rgba(${TOKENS.brandPrimaryRgb}, 0.2);
        color: ${TOKENS.brandPrimary};
        border: 1px solid rgba(${TOKENS.brandPrimaryRgb}, 0.35);
      }
      .toolne-runner-mode-badge.manual {
        background: rgba(${TOKENS.warningRgb}, 0.2);
        color: ${TOKENS.warning};
        border: 1px solid rgba(${TOKENS.warningRgb}, 0.35);
      }
      .toolne-runner-ctrls {
        display: flex;
        align-items: center;
        gap: 4px;
      }
      .toolne-runner-icon-btn {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 22px;
        height: 22px;
        border: none;
        border-radius: 5px;
        background: rgba(255, 255, 255, 0.06);
        color: rgba(255, 255, 255, 0.7);
        cursor: pointer;
        pointer-events: auto;
        transition: background 0.12s ease, color 0.12s ease;
      }
      .toolne-runner-icon-btn:hover {
        background: rgba(255, 255, 255, 0.14);
        color: #FFF;
      }
      .toolne-runner-body {
        padding: 10px 12px;
        display: flex;
        flex-direction: column;
        gap: 7px;
      }
      .toolne-runner-meta {
        display: flex;
        align-items: center;
        justify-content: space-between;
        font-size: 11.5px;
      }
      .toolne-runner-counter {
        font-weight: 700;
        color: ${TOKENS.brandPrimary};
      }
      .toolne-runner-scene-info {
        color: rgba(255, 255, 255, 0.7);
        font-size: 11px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        max-width: 200px;
      }
      .toolne-runner-progress-bar {
        position: relative;
        width: 100%;
        height: 4px;
        border-radius: 2px;
        background: rgba(255, 255, 255, 0.08);
        overflow: hidden;
      }
      .toolne-runner-progress-fill {
        height: 100%;
        width: 100%;
        background: ${TOKENS.brandPrimary};
        border-radius: 2px;
        transform: scaleX(0);
        transform-origin: left;
        transition: transform 0.25s ease-out;
      }
      .toolne-runner-status-line {
        display: flex;
        align-items: center;
        gap: 6px;
        font-size: 11.5px;
        color: #E2E8F0;
      }
      .toolne-runner-dot {
        width: 6px;
        height: 6px;
        border-radius: 50%;
        background: ${TOKENS.brandPrimary};
        flex-shrink: 0;
      }
      .toolne-runner-attention-box {
        display: none;
        align-items: center;
        justify-content: space-between;
        padding: 6px 10px;
        border-radius: 6px;
        background: rgba(${TOKENS.warningRgb}, 0.14);
        border: 1px solid rgba(${TOKENS.warningRgb}, 0.35);
        color: #FFF;
        font-size: 11px;
        margin-top: 2px;
      }
      .toolne-runner-footer {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 6px;
        padding: 8px 12px;
        background: rgba(0, 0, 0, 0.25);
        border-top: 1px solid rgba(255, 255, 255, 0.05);
      }
      .toolne-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 5px;
        padding: 5px 10px;
        border-radius: 6px;
        font-size: 11px;
        font-weight: 600;
        cursor: pointer;
        pointer-events: auto;
        border: 1px solid transparent;
        transition: background 0.12s ease, transform 0.1s ease;
      }
      .toolne-btn:active {
        transform: scale(0.97);
      }
      .toolne-btn-subtle {
        background: rgba(255, 255, 255, 0.08);
        border-color: rgba(255, 255, 255, 0.12);
        color: #E2E8F0;
      }
      .toolne-btn-subtle:hover {
        background: rgba(255, 255, 255, 0.15);
        color: #FFF;
      }
      .toolne-btn-takeover {
        background: rgba(${TOKENS.warningRgb}, 0.18);
        border-color: rgba(${TOKENS.warningRgb}, 0.4);
        color: ${TOKENS.warning};
      }
      .toolne-btn-takeover:hover {
        background: rgba(${TOKENS.warningRgb}, 0.28);
      }
      .toolne-btn-resume {
        background: rgba(${TOKENS.brandPrimaryRgb}, 0.2);
        border-color: rgba(${TOKENS.brandPrimaryRgb}, 0.45);
        color: ${TOKENS.brandPrimary};
      }
      .toolne-btn-resume:hover {
        background: rgba(${TOKENS.brandPrimaryRgb}, 0.32);
      }
      .toolne-btn-primary {
        background: ${TOKENS.brandPrimary};
        color: #FFF;
      }
      .toolne-btn-primary:hover {
        background: #FF8F1F;
      }

      /* Flow Launcher Pill (Minimized) */
      [id="2toolne-flow-launcher"],
      #toolne-launcher-pill {
        position: fixed;
        bottom: 16px;
        right: 16px;
        width: 42px;
        height: 42px;
        border-radius: 12px;
        z-index: 2147483646;
        display: none;
        align-items: center;
        justify-content: center;
        background: ${TOKENS.bgDarkGlass};
        border: 1px solid rgba(${TOKENS.brandPrimaryRgb}, 0.4);
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.4), inset 0 1px 0 rgba(255, 255, 255, 0.2);
        backdrop-filter: blur(16px);
        -webkit-backdrop-filter: blur(16px);
        cursor: pointer;
        pointer-events: auto;
        transition: transform 0.15s ease, box-shadow 0.15s ease;
      }
      [id="2toolne-flow-launcher"]:hover,
      #toolne-launcher-pill:hover {
        transform: translateY(-2px);
        box-shadow: 0 12px 28px rgba(${TOKENS.brandPrimaryRgb}, 0.25);
      }
      .toolne-launcher-icon {
        font-size: 18px;
        line-height: 1;
      }
      .toolne-launcher-dot {
        position: absolute;
        top: 4px;
        right: 4px;
        width: 7px;
        height: 7px;
        border-radius: 50%;
        background: ${TOKENS.brandPrimary};
        border: 1px solid #14161C;
      }

      /* Tile Overlay & Reference Pin */
      .toolne-tile-pin {
        position: absolute;
        top: 8px;
        right: 8px;
        width: 26px;
        height: 26px;
        border-radius: 6px;
        background: rgba(18, 20, 24, 0.82);
        border: 1px solid rgba(255, 255, 255, 0.18);
        color: #FFFFFF;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        opacity: 0;
        transition: opacity 0.15s ease, transform 0.15s ease, background 0.15s ease;
        z-index: 40;
        backdrop-filter: blur(8px);
        pointer-events: auto;
      }
      .toolne-tile-pin svg {
        width: 13px;
        height: 13px;
        stroke: currentColor;
        stroke-width: 2.2;
      }
      div[data-tile]:hover .toolne-tile-pin,
      div[role="listitem"]:hover .toolne-tile-pin,
      div[class*="tile"]:hover .toolne-tile-pin,
      div[class*="media-card"]:hover .toolne-tile-pin {
        opacity: 1;
      }
      .toolne-tile-pin:hover {
        background: ${TOKENS.brandPrimary};
        border-color: ${TOKENS.brandPrimary};
        transform: scale(1.1);
      }
      .toolne-tile-verified {
        outline: 2px solid ${TOKENS.success} !important;
        outline-offset: -2px;
      }
      .toolne-tile-selected {
        outline: 2px solid ${TOKENS.brandPrimary} !important;
        outline-offset: -2px;
      }
      .toolne-tile-char-badge {
        position: absolute;
        top: 8px;
        left: 8px;
        padding: 2px 7px;
        border-radius: 4px;
        font-size: 10px;
        font-weight: 700;
        background: rgba(18, 20, 24, 0.85);
        border: 1px solid rgba(255, 255, 255, 0.15);
        color: #FFF;
        backdrop-filter: blur(8px);
        z-index: 35;
      }
      .toolne-tile-char-badge.approved {
        border-color: ${TOKENS.success};
        color: ${TOKENS.success};
      }
      .toolne-tile-char-badge.pending {
        border-color: ${TOKENS.brandPrimary};
        color: ${TOKENS.brandPrimary};
      }

      /* In-Page Header Settings Button */
      [id="2toolne-flow-settings-button"] {
        position: relative;
        height: 38px;
        padding: 0 14px;
        gap: 8px;
        border-radius: 20px;
        background: rgba(28, 29, 32, 0.88);
        border: 1px solid rgba(255, 255, 255, 0.14);
        color: #F5F5F5;
        font-family: ${TOKENS.fontFamily};
        font-size: 13px;
        font-weight: 500;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        backdrop-filter: blur(8px);
        -webkit-backdrop-filter: blur(8px);
        user-select: none;
        box-sizing: border-box;
        transition: background 0.15s ease, border-color 0.15s ease, color 0.15s ease, transform 0.15s ease;
        flex-shrink: 0;
        z-index: 50;
        pointer-events: auto;
      }
      [id="2toolne-flow-settings-button"]:hover {
        background: rgba(255, 122, 0, 0.14);
        border-color: rgba(255, 122, 0, 0.45);
        color: #FFFFFF;
      }
      [id="2toolne-flow-settings-button"]:hover svg {
        color: ${TOKENS.brandPrimary};
      }
      [id="2toolne-flow-settings-button"] svg {
        color: #E0E0E0;
        transition: color 0.15s ease;
      }
      [id="2toolne-settings-dot"] {
        position: absolute;
        top: 6px;
        right: 10px;
        width: 6px;
        height: 6px;
        border-radius: 50%;
        background: ${TOKENS.brandPrimary};
        display: none;
      }

      /* In-Page Settings Backdrop */
      [id="2toolne-flow-settings-backdrop"] {
        position: fixed;
        inset: 0;
        z-index: 2147483645;
        background: rgba(0, 0, 0, 0.48);
        backdrop-filter: blur(7px) saturate(0.85);
        -webkit-backdrop-filter: blur(7px) saturate(0.85);
        opacity: 0;
        pointer-events: auto;
        transition: opacity 160ms ease;
      }

      /* In-Page Settings Modal */
      [id="2toolne-flow-settings-modal"] {
        position: fixed;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%) scale(0.96);
        width: 440px;
        max-width: calc(100vw - 32px);
        max-height: min(720px, calc(100vh - 48px));
        overflow-y: auto;
        z-index: 2147483646;
        background: rgba(20, 21, 24, 0.94);
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
        border: 1px solid rgba(255, 255, 255, 0.12);
        border-radius: 16px;
        box-shadow: 0 24px 64px rgba(0, 0, 0, 0.48);
        color: #F5F5F5;
        font-family: ${TOKENS.fontFamily};
        display: flex;
        flex-direction: column;
        opacity: 0;
        pointer-events: auto;
        transition: opacity 160ms ease, transform 160ms ease;
        box-sizing: border-box;
      }
      .toolne-modal-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 16px 20px;
        border-bottom: 1px solid rgba(255, 255, 255, 0.08);
      }
      .toolne-modal-body {
        padding: 20px;
        display: flex;
        flex-direction: column;
        gap: 16px;
      }
      .toolne-modal-footer {
        padding: 14px 20px;
        border-top: 1px solid rgba(255, 255, 255, 0.08);
        background: rgba(14, 15, 17, 0.6);
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 10px;
        border-radius: 0 0 16px 16px;
      }
      .toolne-field-group {
        display: flex;
        flex-direction: column;
        gap: 6px;
      }
      .toolne-field-label {
        font-size: 12px;
        font-weight: 600;
        color: rgba(255, 255, 255, 0.85);
      }
      .toolne-field-select {
        width: 100%;
        height: 38px;
        background: rgba(255, 255, 255, 0.06);
        border: 1px solid rgba(255, 255, 255, 0.14);
        border-radius: 8px;
        color: #FFFFFF;
        padding: 0 12px;
        font-size: 13px;
        outline: none;
        box-sizing: border-box;
        transition: border-color 0.15s ease;
      }
      .toolne-field-select:focus {
        border-color: ${TOKENS.brandPrimary};
      }
      .toolne-field-select option {
        background: #181A1E;
        color: #FFF;
      }
      .toolne-checkbox-row {
        display: flex;
        align-items: center;
        gap: 8px;
        cursor: pointer;
        font-size: 12.5px;
        color: rgba(255, 255, 255, 0.85);
        user-select: none;
      }
      .toolne-checkbox {
        accent-color: ${TOKENS.brandPrimary};
        width: 16px;
        height: 16px;
        cursor: pointer;
      }
      .toolne-notice-box {
        display: flex;
        align-items: flex-start;
        gap: 8px;
        padding: 10px 12px;
        border-radius: 8px;
        background: rgba(${TOKENS.warningRgb}, 0.12);
        border: 1px solid rgba(${TOKENS.warningRgb}, 0.3);
        color: #FFF;
        font-size: 11.5px;
        line-height: 1.4;
      }

      /* Segmented Control for Concurrency */
      .toolne-segmented-control {
        display: flex;
        align-items: center;
        width: 100%;
        background: rgba(255, 255, 255, 0.05);
        border: 1px solid rgba(255, 255, 255, 0.12);
        border-radius: 8px;
        padding: 3px;
        box-sizing: border-box;
        gap: 4px;
      }
      .toolne-segmented-btn {
        flex: 1;
        height: 32px;
        border: none;
        border-radius: 6px;
        background: transparent;
        color: rgba(255, 255, 255, 0.7);
        font-family: ${TOKENS.fontFamily};
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        user-select: none;
        box-sizing: border-box;
        transition: background 0.15s ease, color 0.15s ease, box-shadow 0.15s ease;
      }
      .toolne-segmented-btn:hover:not(.active) {
        background: rgba(255, 255, 255, 0.08);
        color: #FFFFFF;
      }
      .toolne-segmented-btn.active {
        background: ${TOKENS.brandPrimary};
        color: #FFFFFF;
        box-shadow: 0 2px 8px rgba(${TOKENS.brandPrimaryRgb}, 0.4);
      }
    `;
    (document.head || document.documentElement).appendChild(styleEl);
  }

  // --- TRUSTED TYPES COMPATIBILITY (Google Flow CSP) ---
  let _ttPolicy = null;
  function getTrustedPolicy() {
    if (_ttPolicy) return _ttPolicy;
    if (typeof window !== 'undefined' && window.trustedTypes && typeof window.trustedTypes.createPolicy === 'function') {
      try {
        _ttPolicy = window.trustedTypes.createPolicy('default', {
          createHTML: (s) => s,
        });
      } catch (e) {
        try {
          _ttPolicy = window.trustedTypes.createPolicy('toolne-policy', {
            createHTML: (s) => s,
          });
        } catch (_) {
          _ttPolicy = window.trustedTypes.defaultPolicy || null;
        }
      }
    }
    return _ttPolicy;
  }
  try {
    getTrustedPolicy();
  } catch (_) {}

  function setSafeHTML(el, html) {
    if (!el) return;
    const policy = getTrustedPolicy();
    el.innerHTML = policy ? policy.createHTML(html) : html;
  }

  // --- STATE CONTAINER ---
  const state = {
    isMounted: false,
    mode: 'AUTO', // 'AUTO' | 'MANUAL'
    executionState: 'IDLE', // 'IDLE' | 'RUNNING' | 'WAITING' | 'PAUSED' | 'ERROR' | 'COMPLETE'
    isRunnerMinimized: false,
    task: null, // { scene_idx, total_scenes, scene_desc, progress }
    status: {
      message: 'Sẵn sàng',
      type: 'info', // 'info' | 'success' | 'warning' | 'error'
    },
    characterPending: {
      count: 0,
      characters: [],
    },
    isSettingsOpen: false,
    settingsData: null,
    selectedMediaIds: new Set(),
    verifiedMediaIds: new Set(),
    statusHideTimer: null,
    scrimCompleteTimer: null,
  };

  // --- DOM NODES ---
  let dom = {
    scrim: null,
    statusPill: null,
    miniRunner: null,
    launcherPill: null,
    settingsButton: null,
    settingsBackdrop: null,
    settingsModal: null,
  };

  // --- COMMUNICATION BRIDGE ---
  function dispatchToHost(action, payload = {}) {
    window.postMessage({
      source: '2toolne-flow-overlay',
      action,
      payload,
    }, '*');
  }

  function normalizeSelector(sel) {
    if (!sel || typeof sel !== 'string') return sel;
    return sel.replace(/#([0-9][\w-]*)/g, '[id="$1"]');
  }

  function deduplicate(selector) {
    try {
      const safeSelector = normalizeSelector(selector);
      const els = Array.from(document.querySelectorAll(safeSelector));
      if (els.length > 1) {
        for (let i = 1; i < els.length; i++) {
          els[i].remove();
        }
      }
      return els[0] || null;
    } catch (err) {
      console.warn('[2TOOLNE Overlay] deduplicate error for selector:', selector, err);
      return null;
    }
  }

  // --- BUILD DOM ELEMENTS ---
  function buildScrim() {
    let scrim = document.getElementById('2toolne-flow-auto-frame') || document.getElementById('toolne-execution-scrim');
    if (!scrim) {
      scrim = document.createElement('div');
      scrim.id = '2toolne-flow-auto-frame';
      scrim.className = 'toolne-flow-auto-frame';
      scrim.setAttribute('data-element', '2toolne-flow-auto-frame');
      scrim.setAttribute('data-legacy-id', 'toolne-execution-scrim');

      const borderEl = document.createElement('div');
      borderEl.className = 'toolne-auto-border';

      const glowEl = document.createElement('div');
      glowEl.className = 'toolne-auto-glow';

      scrim.appendChild(borderEl);
      scrim.appendChild(glowEl);
      (document.body || document.documentElement).appendChild(scrim);
    } else {
      scrim.id = '2toolne-flow-auto-frame';
      scrim.classList.add('toolne-flow-auto-frame');
      scrim.setAttribute('data-element', '2toolne-flow-auto-frame');
      if (!scrim.querySelector('.toolne-auto-border')) {
        const borderEl = document.createElement('div');
        borderEl.className = 'toolne-auto-border';
        scrim.appendChild(borderEl);
      }
      if (!scrim.querySelector('.toolne-auto-glow')) {
        const glowEl = document.createElement('div');
        glowEl.className = 'toolne-auto-glow';
        scrim.appendChild(glowEl);
      }
    }
    dom.scrim = scrim;
  }

  function buildStatusPill() {
    let pill = document.getElementById('2toolne-flow-status-pill') || document.getElementById('toolne-status-pill');
    if (!pill) {
      pill = document.createElement('div');
      pill.id = '2toolne-flow-status-pill';
      pill.setAttribute('data-element', '2toolne-flow-status-pill');
      pill.setAttribute('data-legacy-id', 'toolne-status-pill');
      setSafeHTML(pill, `
        <span class="toolne-status-pill-icon" style="display:flex; align-items:center;"></span>
        <span class="toolne-status-pill-text"></span>
      `);
      (document.body || document.documentElement).appendChild(pill);
    } else {
      pill.id = '2toolne-flow-status-pill';
    }
    dom.statusPill = pill;
  }

  function buildMiniRunner() {
    let runner = document.getElementById('2toolne-flow-mini-runner') || document.getElementById('toolne-mini-runner');
    if (!runner) {
      runner = document.createElement('div');
      runner.id = '2toolne-flow-mini-runner';
      runner.setAttribute('data-element', '2toolne-flow-mini-runner');
      runner.setAttribute('data-legacy-id', 'toolne-mini-runner');
      setSafeHTML(runner, `
        <!-- Header -->
        <div class="toolne-runner-header">
          <div class="toolne-runner-title-group">
            <span class="toolne-runner-logo">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
            </span>
            <span class="toolne-runner-title">Auto Flow</span>
            <span class="toolne-runner-mode-badge auto" id="toolneRunnerModeBadge">AUTO</span>
          </div>
          <div class="toolne-runner-ctrls">
            <button type="button" class="toolne-runner-icon-btn" id="toolneBtnReopenSetup" title="Cài đặt Flow">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
            <button type="button" class="toolne-runner-icon-btn" id="toolneBtnMinimizeRunner" title="Thu nhỏ (42px)">
              <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="5" y1="12" x2="19" y2="12"/></svg>
            </button>
          </div>
        </div>

        <!-- Body -->
        <div class="toolne-runner-body">
          <div class="toolne-runner-meta">
            <span class="toolne-runner-counter" id="toolneRunnerCounter">0 / 0</span>
            <span class="toolne-runner-scene-info" id="toolneRunnerSceneInfo">Sẵn sàng tiếp nhận tác vụ</span>
          </div>
          <div class="toolne-runner-progress-bar">
            <div class="toolne-runner-progress-fill" id="toolneRunnerProgressFill"></div>
          </div>
          <div class="toolne-runner-status-line">
            <span class="toolne-runner-dot" id="toolneRunnerDot"></span>
            <span id="toolneRunnerStatusText" style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">Sẵn sàng</span>
          </div>

          <!-- Attention Box (Expand on approval / warning) -->
          <div class="toolne-runner-attention-box" id="toolneRunnerAttentionBox">
            <div style="display:flex; align-items:center; gap:6px; min-width:0;">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><circle cx="12" cy="8" r="5"/><path d="M20 21a8 8 0 0 0-16 0"/></svg>
              <span id="toolneRunnerAttentionMsg" style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">Nhân vật cần duyệt</span>
            </div>
            <button type="button" class="toolne-btn toolne-btn-primary" id="toolneBtnAttentionAction" style="font-size:10.5px; padding:3px 8px; flex-shrink:0;">
              Duyệt ảnh
            </button>
          </div>
        </div>

        <!-- Footer -->
        <div class="toolne-runner-footer">
          <button type="button" class="toolne-btn toolne-btn-subtle" id="toolneBtnPause">
            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="4" height="16" x="6" y="4"/><rect width="4" height="16" x="14" y="4"/></svg>
            Tạm dừng
          </button>
          <button type="button" class="toolne-btn toolne-btn-takeover" id="toolneBtnTakeover">
            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" x2="4" y1="21" y2="14"/><line x1="4" x2="4" y1="10" y2="3"/><line x1="12" x2="12" y1="21" y2="12"/><line x1="12" x2="12" y1="8" y2="3"/><line x1="20" x2="20" y1="21" y2="16"/><line x1="20" x2="20" y1="12" y2="3"/><line x1="1" x2="7" y1="14" y2="14"/><line x1="9" x2="15" y1="8" y2="8"/><line x1="17" x2="23" y1="16" y2="16"/></svg>
            Điều khiển
          </button>
          <button type="button" class="toolne-btn toolne-btn-resume" id="toolneBtnResumeAuto" style="display:none;">
            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="6 3 20 12 6 21 6 3"/></svg>
            Tiếp tục tự động
          </button>
        </div>
      `);
      (document.body || document.documentElement).appendChild(runner);

      // Event listeners
      runner.querySelector('#toolneBtnMinimizeRunner')?.addEventListener('click', () => {
        minimizeRunner();
      });
      runner.querySelector('#toolneBtnReopenSetup')?.addEventListener('click', () => {
        openSettingsModal();
      });
      runner.querySelector('#toolneBtnPause')?.addEventListener('click', () => {
        dispatchToHost('pause');
      });
      runner.querySelector('#toolneBtnTakeover')?.addEventListener('click', () => {
        dispatchToHost('takeover');
      });
      runner.querySelector('#toolneBtnResumeAuto')?.addEventListener('click', () => {
        dispatchToHost('resume-auto');
      });
      runner.querySelector('#toolneBtnAttentionAction')?.addEventListener('click', () => {
        dispatchToHost('open-character-approval');
      });
    } else {
      runner.id = '2toolne-flow-mini-runner';
    }
    dom.miniRunner = runner;
  }

  function buildLauncherPill() {
    let launcher = document.getElementById('2toolne-flow-launcher') || document.getElementById('toolne-launcher-pill');
    if (!launcher) {
      launcher = document.createElement('div');
      launcher.id = '2toolne-flow-launcher';
      launcher.setAttribute('data-element', '2toolne-flow-launcher');
      launcher.setAttribute('data-legacy-id', 'toolne-launcher-pill');
      launcher.title = 'Mở 2TOOLNE Flow Runner';
      setSafeHTML(launcher, `
        <span class="toolne-launcher-icon" style="display:flex; align-items:center; justify-content:center; color:${TOKENS.brandPrimary};">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
        </span>
        <span class="toolne-launcher-dot" id="toolneLauncherDot"></span>
      `);
      (document.body || document.documentElement).appendChild(launcher);

      launcher.addEventListener('click', () => {
        restoreRunner();
      });
    } else {
      launcher.id = '2toolne-flow-launcher';
    }
    dom.launcherPill = launcher;
  }

  // --- STATE MUTATION HELPERS ---
  function minimizeRunner() {
    state.isRunnerMinimized = true;
    if (dom.miniRunner) dom.miniRunner.style.display = 'none';
    if (dom.launcherPill) dom.launcherPill.style.display = 'flex';
  }

  function restoreRunner() {
    state.isRunnerMinimized = false;
    if (dom.launcherPill) dom.launcherPill.style.display = 'none';
    if (dom.miniRunner) dom.miniRunner.style.display = 'flex';
  }

  function updateExecutionScrim() {
    if (!dom.scrim) return;
    dom.scrim.className = 'toolne-flow-auto-frame';
    if (state.mode === 'MANUAL') {
      // Glow completely removed in manual mode
      dom.scrim.classList.add('scrim-manual');
      return;
    }
    if (state.executionState === 'RUNNING' || state.executionState === 'AUTO_ACTIVE' || state.executionState === 'AUTO_PROCESSING') {
      dom.scrim.classList.add('scrim-auto');
    } else if (state.executionState === 'WAITING' || state.executionState === 'AUTO_WAITING') {
      dom.scrim.classList.add('scrim-waiting');
    } else if (state.executionState === 'PAUSED') {
      dom.scrim.classList.add('scrim-paused');
    } else if (state.executionState === 'ERROR') {
      dom.scrim.classList.add('scrim-error');
    } else if (state.executionState === 'COMPLETE' || state.executionState === 'COMPLETED' || state.executionState === 'SUCCESS') {
      dom.scrim.classList.add('scrim-success');
      clearTimeout(state.scrimCompleteTimer);
      state.scrimCompleteTimer = setTimeout(() => {
        if (dom.scrim && dom.scrim.classList.contains('scrim-success')) {
          dom.scrim.classList.remove('scrim-success');
          dom.scrim.classList.add('scrim-idle');
        }
      }, 700);
    } else {
      dom.scrim.classList.add('scrim-idle');
    }
  }

  const SPINNER_SVG = `
    <span style="width:13px; height:13px; border:2px solid rgba(255,255,255,0.25); border-top-color:${TOKENS.brandPrimary}; border-radius:50%; animation:toolneSpin 0.8s linear infinite; display:inline-block;"></span>
  `;
  const CHECK_SVG = `
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="${TOKENS.success}" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
  `;
  const WARN_SVG = `
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="${TOKENS.warning}" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
  `;
  const ERROR_SVG = `
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="${TOKENS.danger}" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
  `;

  function setRealtimeStatus(message, type = 'info', autoHideMs = 0) {
    if (!dom.statusPill) return;
    clearTimeout(state.statusHideTimer);
    state.status.message = message;
    state.status.type = type;

    const iconEl = dom.statusPill.querySelector('.toolne-status-pill-icon');
    const textEl = dom.statusPill.querySelector('.toolne-status-pill-text');

    if (type === 'success') {
      setSafeHTML(iconEl, CHECK_SVG);
      dom.statusPill.style.borderColor = `rgba(${TOKENS.successRgb}, 0.5)`;
    } else if (type === 'warning') {
      setSafeHTML(iconEl, WARN_SVG);
      dom.statusPill.style.borderColor = `rgba(${TOKENS.warningRgb}, 0.5)`;
    } else if (type === 'error') {
      setSafeHTML(iconEl, ERROR_SVG);
      dom.statusPill.style.borderColor = `rgba(${TOKENS.dangerRgb}, 0.5)`;
    } else {
      setSafeHTML(iconEl, SPINNER_SVG);
      dom.statusPill.style.borderColor = `rgba(${TOKENS.brandPrimaryRgb}, 0.4)`;
    }

    if (textEl) textEl.textContent = message;
    dom.statusPill.style.display = 'flex';

    if (autoHideMs > 0) {
      state.statusHideTimer = setTimeout(() => {
        if (dom.statusPill) dom.statusPill.style.display = 'none';
      }, autoHideMs);
    }
  }

  function rehydrateRunnerDOM() {
    if (!dom.miniRunner) return;

    // 1. Mode badge and controls
    const badge = dom.miniRunner.querySelector('#toolneRunnerModeBadge');
    const btnTakeover = dom.miniRunner.querySelector('#toolneBtnTakeover');
    const btnResume = dom.miniRunner.querySelector('#toolneBtnResumeAuto');

    if (badge) {
      if (state.mode === 'AUTO') {
        badge.textContent = 'AUTO';
        badge.className = 'toolne-runner-mode-badge auto';
        if (btnTakeover) btnTakeover.style.display = 'inline-flex';
        if (btnResume) btnResume.style.display = 'none';
      } else {
        badge.textContent = 'THỦ CÔNG';
        badge.className = 'toolne-runner-mode-badge manual';
        if (btnTakeover) btnTakeover.style.display = 'none';
        if (btnResume) btnResume.style.display = 'inline-flex';
      }
    }

    // 2. Task metadata and progress
    if (state.task) {
      const counterEl = dom.miniRunner.querySelector('#toolneRunnerCounter');
      const sceneEl = dom.miniRunner.querySelector('#toolneRunnerSceneInfo');
      const fillEl = dom.miniRunner.querySelector('#toolneRunnerProgressFill');

      if (counterEl) {
        const cur = state.task.scene_idx || 1;
        const tot = state.task.total_scenes || 1;
        counterEl.textContent = `${cur} / ${tot}`;
      }
      if (sceneEl) {
        sceneEl.textContent = state.task.scene_desc || `Cảnh ${state.task.scene_idx || 1}`;
      }
      if (fillEl) {
        const pct = Math.max(0, Math.min(100, state.task.progress || 0));
        fillEl.style.transform = `scaleX(${pct / 100})`;
      }
    }

    // 3. Status text
    const statusTextEl = dom.miniRunner.querySelector('#toolneRunnerStatusText');
    if (statusTextEl && state.status?.message) {
      statusTextEl.textContent = state.status.message;
    }

    // 4. Character Attention Box
    const attentionBox = dom.miniRunner.querySelector('#toolneRunnerAttentionBox');
    const attentionMsg = dom.miniRunner.querySelector('#toolneRunnerAttentionMsg');
    if (state.characterPending && state.characterPending.count > 0) {
      if (attentionBox) attentionBox.style.display = 'flex';
      if (attentionMsg) attentionMsg.textContent = `${state.characterPending.count} ảnh nhân vật cần duyệt`;
    } else {
      if (attentionBox) attentionBox.style.display = 'none';
    }

    // 5. Visibility routing according to state rules:
    // AUTO + RUNNING -> Mini Runner visible
    // AUTO + WAITING -> Mini Runner visible with attention
    // AUTO + COMPLETE -> Mini Runner visible briefly then collapse
    // IDLE -> optional minimized launcher only
    // MANUAL -> runner visible or launcher
    const isActivelyWorking = ['RUNNING', 'AUTO_ACTIVE', 'AUTO_PROCESSING', 'WAITING', 'AUTO_WAITING', 'PAUSED', 'ERROR'].includes(state.executionState);

    if (isActivelyWorking) {
      if (!state.isRunnerMinimized) {
        dom.miniRunner.style.display = 'flex';
        if (dom.launcherPill) dom.launcherPill.style.display = 'none';
      } else {
        dom.miniRunner.style.display = 'none';
        if (dom.launcherPill) dom.launcherPill.style.display = 'flex';
      }
    } else if (state.executionState === 'COMPLETE' || state.executionState === 'SUCCESS') {
      dom.miniRunner.style.display = 'flex';
      if (dom.launcherPill) dom.launcherPill.style.display = 'none';
    } else {
      // IDLE
      if (state.isRunnerMinimized || !state.task) {
        dom.miniRunner.style.display = 'none';
        if (dom.launcherPill) dom.launcherPill.style.display = 'flex';
      } else {
        dom.miniRunner.style.display = 'flex';
        if (dom.launcherPill) dom.launcherPill.style.display = 'none';
      }
    }

    // 6. Launcher dot color
    const launcherDot = dom.launcherPill?.querySelector('#toolneLauncherDot');
    if (launcherDot) {
      if (state.executionState === 'RUNNING') launcherDot.style.background = TOKENS.brandPrimary;
      else if (state.executionState === 'PAUSED' || state.executionState === 'WAITING') launcherDot.style.background = TOKENS.warning;
      else if (state.executionState === 'ERROR') launcherDot.style.background = TOKENS.danger;
      else launcherDot.style.background = TOKENS.brandPrimary;
    }
  }

  function formatBrandedStatus(text) {
    if (!text || typeof text !== 'string') return '2TOOLNE đang xử lý tự động...';
    const t = text.trim();
    if (t.includes('Google Flow đang xử lý video') || t.includes('tự động tạo video')) {
      return '2TOOLNE đang tự động tạo video...';
    }
    if (t.includes('Google Flow đang xử lý ảnh') || t.includes('tự động tạo ảnh')) {
      return '2TOOLNE đang tự động tạo ảnh...';
    }
    if (t.includes('chuẩn bị ảnh tham chiếu') || t.includes('tải ảnh tham chiếu')) {
      return '2TOOLNE đang chuẩn bị ảnh tham chiếu...';
    }
    if (t.includes('tải kết quả') || t.includes('tải media')) {
      return '2TOOLNE đang tải kết quả...';
    }
    if (t.includes('chờ Google Flow')) {
      return '2TOOLNE đang chờ Google Flow xử lý...';
    }
    if (t.includes('Google Flow đang xử lý') || t.includes('Google Flow đang tạo') || t.includes('Google Flow đang')) {
      return '2TOOLNE đang xử lý tự động...';
    }
    return t;
  }

  function setRunnerState(data = {}) {
    if (data.mode) {
      state.mode = data.mode;
    }
    if (data.executionState) {
      state.executionState = data.executionState;
    }
    if (data.task) {
      state.task = data.task;
    }
    if (data.statusText) {
      state.status.message = formatBrandedStatus(data.statusText);
    }
    if (data.characterPending !== undefined) {
      state.characterPending = data.characterPending;
    }

    updateExecutionScrim();
    rehydrateRunnerDOM();
    updateSettingsAttentionDot();
  }

  // --- IN-PAGE HEADER SETTINGS & MODAL ---

  function isProjectPage() {
    try {
      const pathname = window.location.pathname || '';
      const href = window.location.href || '';
      // 1. URL pattern check
      if (/\/project\/[a-zA-Z0-9_-]+/i.test(pathname) || pathname.includes('/project/') || href.includes('/project/')) {
        return true;
      }
      // 2. Project page DOM markers
      if (
        document.querySelector('.ProseMirror') ||
        document.querySelector('flow-prompt-box') ||
        document.querySelector('flow-prompt-box-settings') ||
        document.querySelector('.settings-trigger-button') ||
        document.querySelector('header.header-base')
      ) {
        return true;
      }
    } catch (_) {}
    return false;
  }

  function findNavAnchor() {
    // Project page strictly never provides a nav anchor for the header Settings button
    if (isProjectPage()) {
      return null;
    }

    // 1. Semantic search: Flow Music button
    const musicBtn = document.querySelector('.header-music-button');
    if (musicBtn && musicBtn.parentElement) {
      return { container: musicBtn.parentElement, referenceNode: musicBtn };
    }

    const allLinks = Array.from(document.querySelectorAll('a, button, [role="button"]'));
    const musicLink = allLinks.find(el => (el.innerText || el.textContent || '').includes('Flow Music'));
    if (musicLink && musicLink.parentElement) {
      return { container: musicLink.parentElement, referenceNode: musicLink };
    }

    // 2. Fallback: Flow TV button
    const tvBtn = document.querySelector('.header-tv-button');
    if (tvBtn && tvBtn.parentElement) {
      return { container: tvBtn.parentElement, referenceNode: tvBtn };
    }

    // 3. Fallback: header.flow-header .header-right
    const headerRight = document.querySelector('header.flow-header .header-right, header .header-right');
    if (headerRight) {
      return { container: headerRight, referenceNode: headerRight.firstChild };
    }

    // 4. Fallback: Home flow-header element only (never on project page)
    const header = document.querySelector('header.flow-header, header[role="banner"]');
    if (header && !isProjectPage()) {
      return { container: header, referenceNode: null };
    }

    return null;
  }

  function injectSettingsButton() {
    // In Google Flow Project pages: Strictly REMOVE any project-level settings button
    if (isProjectPage()) {
      const existing = document.getElementById('2toolne-flow-settings-button');
      if (existing) {
        existing.remove();
      }
      dom.settingsButton = null;
      return;
    }

    let btn = deduplicate('[id="2toolne-flow-settings-button"]');
    if (btn && document.body.contains(btn)) {
      dom.settingsButton = btn;
      return;
    }

    const anchor = findNavAnchor();
    if (!anchor || !anchor.container) {
      return;
    }

    btn = document.createElement('button');
    btn.type = 'button';
    btn.id = '2toolne-flow-settings-button';
    btn.title = '2TOOLNE Flow Settings';
    setSafeHTML(btn, `
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
        <line x1="21" y1="4" x2="14" y2="4"/><line x1="10" y1="4" x2="3" y2="4"/><line x1="21" y1="12" x2="12" y2="12"/><line x1="8" y1="12" x2="3" y2="12"/><line x1="21" y1="20" x2="16" y2="20"/><line x1="12" y1="20" x2="3" y2="20"/><line x1="14" y1="2" x2="14" y2="6"/><line x1="8" y1="10" x2="8" y2="14"/><line x1="16" y1="18" x2="16" y2="22"/>
      </svg>
      <span>Settings</span>
      <span id="2toolne-settings-dot"></span>
    `);

    btn.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      openSettingsModal();
    });

    if (anchor.referenceNode && anchor.container.contains(anchor.referenceNode)) {
      anchor.container.insertBefore(btn, anchor.referenceNode);
    } else {
      anchor.container.appendChild(btn);
    }
    dom.settingsButton = btn;
    updateSettingsAttentionDot();
  }

  function updateSettingsAttentionDot() {
    const dot = document.getElementById('2toolne-settings-dot');
    if (!dot) return;
    const needAttention = (state.characterPending && state.characterPending.count > 0) || state.executionState === 'ERROR';
    if (needAttention) {
      dot.style.display = 'block';
      dot.style.background = state.executionState === 'ERROR' ? TOKENS.danger : TOKENS.brandPrimary;
    } else {
      dot.style.display = 'none';
    }
  }

  function renderSettingsModalContent() {
    const profiles = state.settingsData?.profiles || [
      { id: 'flowacc_default', name: 'Google Flow Default', email: 'flow@google.com', is_active: true }
    ];
    const settings = state.settingsData?.settings || {
      default_aspect: '9:16',
      mode: state.mode || 'AUTO',
      char_approval_mode: 'MANUAL',
      auto_approve_valid: true,
      image_model: 'AUTO',
      image_prompt_batch_size: 1,
    };
    const isJobRunning = !!state.settingsData?.isJobRunning;
    const batchSize = Math.max(1, Math.min(4, parseInt(settings.image_prompt_batch_size || 1, 10)));
    const currentModel = settings.image_model || 'AUTO';

    const profileOptions = profiles.map(p =>
      `<option value="${p.id}" ${p.is_active ? 'selected' : ''}>${p.email || p.name || 'Tài khoản Flow'}</option>`
    ).join('');

    return `
      <div class="toolne-modal-header">
        <div style="display:flex; align-items:center; gap:8px;">
          <span style="display:flex; align-items:center; justify-content:center; width:22px; height:22px; border-radius:6px; background:rgba(${TOKENS.brandPrimaryRgb}, 0.2); color:${TOKENS.brandPrimary};">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
          </span>
          <span style="font-weight:700; font-size:14px; color:#FFF;">Cài đặt Google Flow</span>
        </div>
        <button type="button" class="toolne-runner-icon-btn" id="2toolne-flow-settings-close-btn" title="Đóng">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>

      <div class="toolne-modal-body">
        <!-- 1. Tài khoản Google Flow -->
        <div class="toolne-field-group">
          <label class="toolne-field-label">Tài khoản Google Flow</label>
          <div style="display:flex; gap:8px;">
            <select class="toolne-field-select" id="2toolne-flow-select-profile" ${isJobRunning ? 'disabled' : ''}>
              ${profileOptions}
            </select>
            <button type="button" class="toolne-btn toolne-btn-subtle" id="2toolne-flow-add-profile-btn" title="Thêm tài khoản mới" ${isJobRunning ? 'disabled' : ''} style="flex-shrink:0; padding:0 10px;">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
              <span>Thêm</span>
            </button>
          </div>
        </div>

        <!-- 2. Chế độ vận hành mặc định -->
        <div class="toolne-field-group">
          <label class="toolne-field-label">Chế độ vận hành mặc định</label>
          <select class="toolne-field-select" id="2toolne-flow-select-mode">
            <option value="AUTO" ${settings.mode === 'AUTO' ? 'selected' : ''}>Tự động hoàn toàn (AUTO)</option>
            <option value="MANUAL" ${settings.mode === 'MANUAL' ? 'selected' : ''}>Thủ công (MANUAL)</option>
          </select>
        </div>

        <!-- 3. Model tạo ảnh -->
        <div class="toolne-field-group">
          <label class="toolne-field-label">Model tạo ảnh</label>
          <select class="toolne-field-select" id="2toolne-flow-select-image-model">
            <option value="AUTO" ${currentModel === 'AUTO' ? 'selected' : ''}>[AUTO] Tự động theo tài khoản Flow</option>
            <option value="🍌 Nano Banana Pro" ${currentModel === '🍌 Nano Banana Pro' ? 'selected' : ''}>🍌 Nano Banana Pro</option>
            <option value="🍌 Nano Banana 2" ${currentModel === '🍌 Nano Banana 2' ? 'selected' : ''}>🍌 Nano Banana 2</option>
            <option value="🍌 Nano Banana 2 Lite" ${currentModel === '🍌 Nano Banana 2 Lite' ? 'selected' : ''}>🍌 Nano Banana 2 Lite</option>
          </select>
        </div>

        <!-- 4. Số ảnh tạo đồng thời -->
        <div class="toolne-field-group">
          <label class="toolne-field-label">Số ảnh tạo đồng thời</label>
          <div class="toolne-segmented-control" id="2toolne-flow-concurrency-control" data-value="${batchSize}">
            <button type="button" class="toolne-segmented-btn ${batchSize === 1 ? 'active' : ''}" data-batch="1">1</button>
            <button type="button" class="toolne-segmented-btn ${batchSize === 2 ? 'active' : ''}" data-batch="2">2</button>
            <button type="button" class="toolne-segmented-btn ${batchSize === 3 ? 'active' : ''}" data-batch="3">3</button>
            <button type="button" class="toolne-segmented-btn ${batchSize === 4 ? 'active' : ''}" data-batch="4">4</button>
          </div>
        </div>

        <!-- 5. Tỷ lệ khung hình mặc định -->
        <div class="toolne-field-group">
          <label class="toolne-field-label">Tỷ lệ khung hình mặc định</label>
          <select class="toolne-field-select" id="2toolne-flow-select-aspect">
            <option value="9:16" ${settings.default_aspect === '9:16' ? 'selected' : ''}>9:16 (Dọc - TikTok, Reels, Shorts)</option>
            <option value="16:9" ${settings.default_aspect === '16:9' ? 'selected' : ''}>16:9 (Ngang - YouTube, Video chuẩn)</option>
          </select>
        </div>

        <!-- 6. Cơ chế phê duyệt nhân vật -->
        <div class="toolne-field-group" style="gap:10px; margin-top:2px;">
          <label class="toolne-field-label">Cơ chế phê duyệt nhân vật</label>
          <label class="toolne-checkbox-row">
            <input type="checkbox" class="toolne-checkbox" id="2toolne-flow-chk-auto-approve" ${settings.auto_approve_valid ? 'checked' : ''}>
            <span>Tự động duyệt ảnh nhân vật hợp lệ (bỏ qua chờ xác nhận)</span>
          </label>
        </div>

        ${isJobRunning ? `
          <div class="toolne-notice-box">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="${TOKENS.warning}" stroke-width="2.5" style="flex-shrink:0;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <span>Tác vụ đang chạy. Một số tùy chọn tài khoản tạm thời khóa để tránh gián đoạn tiến trình.</span>
          </div>
        ` : ''}
      </div>

      <div class="toolne-modal-footer">
        <button type="button" class="toolne-btn toolne-btn-subtle" id="2toolne-flow-settings-cancel-btn">Hủy</button>
        <button type="button" class="toolne-btn toolne-btn-primary" id="2toolne-flow-settings-save-btn">Lưu Cài Đặt</button>
      </div>
    `;
  }

  function bindSettingsModalEvents(modal) {
    if (!modal) return;
    modal.querySelector('[id="2toolne-flow-settings-close-btn"]')?.addEventListener('click', closeSettingsModal);
    modal.querySelector('[id="2toolne-flow-settings-cancel-btn"]')?.addEventListener('click', closeSettingsModal);

    // Concurrency segmented control events
    const concurrencyCtrl = modal.querySelector('[id="2toolne-flow-concurrency-control"]');
    if (concurrencyCtrl) {
      concurrencyCtrl.querySelectorAll('.toolne-segmented-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
          e.preventDefault();
          e.stopPropagation();
          const val = btn.getAttribute('data-batch') || '1';
          concurrencyCtrl.setAttribute('data-value', val);
          concurrencyCtrl.querySelectorAll('.toolne-segmented-btn').forEach(b => b.classList.remove('active'));
          btn.classList.add('active');
        });
      });
    }

    modal.querySelector('[id="2toolne-flow-add-profile-btn"]')?.addEventListener('click', () => {
      const name = prompt('Nhập tên gợi nhớ hoặc email cho tài khoản Google Flow mới:');
      if (name && name.trim()) {
        dispatchToHost('create-profile', { name: name.trim() });
      }
    });

    modal.querySelector('[id="2toolne-flow-settings-save-btn"]')?.addEventListener('click', () => {
      const profileId = modal.querySelector('[id="2toolne-flow-select-profile"]')?.value;
      const mode = modal.querySelector('[id="2toolne-flow-select-mode"]')?.value || 'AUTO';
      const imageModel = modal.querySelector('[id="2toolne-flow-select-image-model"]')?.value || 'AUTO';
      const batchSize = parseInt(modal.querySelector('[id="2toolne-flow-concurrency-control"]')?.getAttribute('data-value') || '1', 10);
      const aspect = modal.querySelector('[id="2toolne-flow-select-aspect"]')?.value || '9:16';
      const autoApprove = !!modal.querySelector('[id="2toolne-flow-chk-auto-approve"]')?.checked;

      const payload = {
        active_profile_id: profileId,
        settings: {
          mode,
          default_aspect: aspect,
          auto_approve_valid: autoApprove,
          char_approval_mode: autoApprove ? 'AUTO' : 'MANUAL',
          image_model: imageModel,
          image_prompt_batch_size: batchSize,
        }
      };

      state.mode = mode;
      updateExecutionScrim();
      rehydrateRunnerDOM();

      dispatchToHost('save-settings', payload);
      closeSettingsModal();
    });
  }

  function openSettingsModal() {
    if (state.isSettingsOpen || document.getElementById('2toolne-flow-settings-modal')) {
      return;
    }
    state.isSettingsOpen = true;

    dispatchToHost('get-settings');

    const backdrop = document.createElement('div');
    backdrop.id = '2toolne-flow-settings-backdrop';
    backdrop.addEventListener('click', () => {
      closeSettingsModal();
    });
    (document.body || document.documentElement).appendChild(backdrop);
    dom.settingsBackdrop = backdrop;

    const modal = document.createElement('div');
    modal.id = '2toolne-flow-settings-modal';
    setSafeHTML(modal, renderSettingsModalContent());
    (document.body || document.documentElement).appendChild(modal);
    dom.settingsModal = modal;

    requestAnimationFrame(() => {
      backdrop.style.opacity = '1';
      modal.style.opacity = '1';
      modal.style.transform = 'translate(-50%, -50%) scale(1)';
    });

    bindSettingsModalEvents(modal);

    const escHandler = (e) => {
      if (e.key === 'Escape') {
        e.preventDefault();
        closeSettingsModal();
      }
    };
    modal._escHandler = escHandler;
    window.addEventListener('keydown', escHandler);
  }

  function closeSettingsModal() {
    if (!state.isSettingsOpen && !document.getElementById('2toolne-flow-settings-modal')) {
      return;
    }
    state.isSettingsOpen = false;

    if (dom.settingsModal?._escHandler) {
      window.removeEventListener('keydown', dom.settingsModal._escHandler);
    }

    if (dom.settingsBackdrop) {
      dom.settingsBackdrop.style.opacity = '0';
    }
    if (dom.settingsModal) {
      dom.settingsModal.style.opacity = '0';
      dom.settingsModal.style.transform = 'translate(-50%, -50%) scale(0.96)';
    }

    setTimeout(() => {
      dom.settingsBackdrop?.remove();
      dom.settingsModal?.remove();
      dom.settingsBackdrop = null;
      dom.settingsModal = null;
      // Guarantee overlay elements remain preserved after closing settings
      ensureMounted();
    }, 170);

    dispatchToHost('close-settings');
  }

  // --- TILE OVERLAY LAYER (DOM MutationObserver) ---
  const PLUS_SVG = `
    <svg viewBox="0 0 24 24" fill="none"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
  `;

  function resolveTileMediaId(tileEl) {
    if (!tileEl) return null;
    const video = tileEl.querySelector('video');
    const img = tileEl.querySelector('img');
    const src = video?.currentSrc || video?.src || img?.currentSrc || img?.src || '';
    if (src) {
      const asbMatch = src.match(/\/asb\/(AB-[A-Za-z0-9_-]+)/);
      if (asbMatch) return asbMatch[1];
      const mediaMatch = src.match(/\/media\/([A-Za-z0-9_-]{8,})/);
      if (mediaMatch) return mediaMatch[1];
    }
    return tileEl.dataset?.tileId || tileEl.dataset?.mediaId || null;
  }

  function decorateTile(tileEl) {
    if (!tileEl || tileEl.querySelector('.toolne-tile-pin')) return;
    tileEl.style.position = 'relative';

    const pin = document.createElement('button');
    pin.type = 'button';
    pin.className = 'toolne-tile-pin';
    pin.title = 'Thêm ảnh tham chiếu cho phân cảnh (2TOOLNE Pin)';
    setSafeHTML(pin, PLUS_SVG);

    ['mousedown', 'touchstart'].forEach(evt => pin.addEventListener(evt, e => e.stopPropagation()));
    pin.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      const mediaId = resolveTileMediaId(tileEl);
      const img = tileEl.querySelector('img') || tileEl.querySelector('video');
      const thumbUrl = img?.currentSrc || img?.src || '';
      dispatchToHost('add-reference', {
        mediaId,
        thumbUrl,
      });
      pin.style.transform = 'scale(1.25)';
      pin.style.background = TOKENS.success;
      setTimeout(() => {
        pin.style.transform = '';
        pin.style.background = '';
      }, 800);
    });

    tileEl.appendChild(pin);
  }

  function scanAndDecorateTiles() {
    try {
      const candidates = document.querySelectorAll(`
        div[data-tile],
        div[role="listitem"],
        div[class*="tile"],
        div[class*="media-card"]
      `);

      candidates.forEach(el => {
        if (el.querySelector('video') || el.querySelector('img')) {
          decorateTile(el);
        }
      });

      if (state.selectedMediaIds.size > 0 || state.verifiedMediaIds.size > 0) {
        candidates.forEach(tile => {
          const mId = resolveTileMediaId(tile);
          if (!mId) return;
          if (state.selectedMediaIds.has(mId)) {
            tile.classList.add('toolne-tile-selected');
          } else {
            tile.classList.remove('toolne-tile-selected');
          }
          if (state.verifiedMediaIds.has(mId)) {
            tile.classList.add('toolne-tile-verified');
          } else {
            tile.classList.remove('toolne-tile-verified');
          }
        });
      }
    } catch (err) {
      console.warn('[2TOOLNE Overlay] scanTiles graceful degrade:', err.message);
    }
  }

  let tileObserver = null;
  function initTileObserver() {
    if (tileObserver) return;
    let debounceTimer = null;
    tileObserver = new MutationObserver(() => {
      clearTimeout(debounceTimer);
      debounceTimer = setTimeout(scanAndDecorateTiles, 400);
    });
    tileObserver.observe(document.body || document.documentElement, { childList: true, subtree: true });
    scanAndDecorateTiles();
  }

  // --- CANONICAL ENSURE MOUNTED LIFECYCLE ---
  function ensureMounted() {
    injectOverlayStyles();

    // 1. Scrim / Auto Frame (ensure max 1)
    let scrim = deduplicate('[id="2toolne-flow-auto-frame"], #toolne-execution-scrim');
    if (!scrim || !document.body.contains(scrim)) {
      buildScrim();
    } else {
      dom.scrim = scrim;
    }

    // 2. Status Pill (ensure max 1)
    let pill = deduplicate('[id="2toolne-flow-status-pill"], #toolne-status-pill');
    if (!pill || !document.body.contains(pill)) {
      buildStatusPill();
    } else {
      dom.statusPill = pill;
    }

    // 3. Mini Runner (ensure max 1)
    let runner = deduplicate('[id="2toolne-flow-mini-runner"], #toolne-mini-runner');
    if (!runner || !document.body.contains(runner)) {
      buildMiniRunner();
    } else {
      dom.miniRunner = runner;
    }

    // 4. Launcher Pill (ensure max 1)
    let launcher = deduplicate('[id="2toolne-flow-launcher"], #toolne-launcher-pill');
    if (!launcher || !document.body.contains(launcher)) {
      buildLauncherPill();
    } else {
      dom.launcherPill = launcher;
    }

    // 5. Settings Button (ensure max 1 on Home page only; strictly remove on Project page)
    if (isProjectPage()) {
      const existing = document.getElementById('2toolne-flow-settings-button');
      if (existing) {
        existing.remove();
      }
      dom.settingsButton = null;
    } else {
      let btn = deduplicate('[id="2toolne-flow-settings-button"]');
      if (!btn || !document.body.contains(btn)) {
        injectSettingsButton();
      } else {
        dom.settingsButton = btn;
      }
    }

    // Immediately rehydrate state
    updateExecutionScrim();
    rehydrateRunnerDOM();
    updateSettingsAttentionDot();
  }

  // --- LIFECYCLE MUTATION OBSERVER ---
  let lifecycleObserver = null;
  function initLifecycleObserver() {
    if (lifecycleObserver) return;
    let debounceTimer = null;
    lifecycleObserver = new MutationObserver(() => {
      clearTimeout(debounceTimer);
      debounceTimer = setTimeout(() => {
        const needsProjectClean = isProjectPage() && !!document.getElementById('2toolne-flow-settings-button');
        const needsHomeSettings = !isProjectPage() && !document.getElementById('2toolne-flow-settings-button');
        const missing =
          needsProjectClean ||
          needsHomeSettings ||
          !document.getElementById('2toolne-flow-auto-frame') ||
          !document.getElementById('2toolne-flow-mini-runner') ||
          !document.getElementById('2toolne-flow-launcher') ||
          !document.getElementById('2toolne-flow-status-pill');
        if (missing) {
          ensureMounted();
        }
      }, 150);
    });
    lifecycleObserver.observe(document.body || document.documentElement, { childList: true, subtree: true });

    // Also listen to SPA history / popstate changes
    window.addEventListener('popstate', () => ensureMounted());
    window.addEventListener('hashchange', () => ensureMounted());
  }

  // --- CANONICAL LIFECYCLE API ---
  function mount() {
    ensureMounted();
    initLifecycleObserver();
    initTileObserver();
    dispatchToHost('get-settings');
    state.isMounted = true;
    console.info('[2TOOLNE] Single Canonical Flow Overlay Layer mounted successfully.');
  }

  function unmount() {
    if (lifecycleObserver) {
      lifecycleObserver.disconnect();
      lifecycleObserver = null;
    }
    closeSettingsModal();
    dom.settingsButton?.remove();
    dom.settingsButton = null;

    if (tileObserver) {
      tileObserver.disconnect();
      tileObserver = null;
    }
    dom.scrim?.remove();
    dom.statusPill?.remove();
    dom.miniRunner?.remove();
    dom.launcherPill?.remove();
    document.getElementById('toolne-flow-overlay-styles')?.remove();
    state.isMounted = false;
    window.__2toolneFlowOverlayMounted = false;
  }

  function setAutomationState(mode, executionState) {
    if (mode) state.mode = mode;
    if (executionState) state.executionState = executionState;
    updateExecutionScrim();
    rehydrateRunnerDOM();
    updateSettingsAttentionDot();
  }

  function setTaskState(taskInfo) {
    if (taskInfo) {
      state.task = {
        scene_idx: taskInfo.scene_idx || 1,
        total_scenes: taskInfo.total_scenes || 1,
        scene_desc: taskInfo.scene_desc || taskInfo.scene_title || `Cảnh ${taskInfo.scene_idx || 1}`,
        progress: taskInfo.progress !== undefined ? taskInfo.progress : (state.task?.progress || 0),
      };
    }
    rehydrateRunnerDOM();
  }

  function setStatus(message, type = 'info', autoHideMs = 0) {
    const brandedMessage = formatBrandedStatus(message);
    setRealtimeStatus(brandedMessage, type, autoHideMs);
    if (state.status) {
      state.status.message = brandedMessage;
      state.status.type = type;
    }
    const statusTextEl = dom.miniRunner?.querySelector('#toolneRunnerStatusText');
    if (statusTextEl) statusTextEl.textContent = brandedMessage;
  }

  function setProgress(progressPct) {
    if (!state.task) state.task = { scene_idx: 1, total_scenes: 1, scene_desc: 'Đang xử lý...', progress: 0 };
    state.task.progress = Math.max(0, Math.min(100, progressPct));
    const fillEl = dom.miniRunner?.querySelector('#toolneRunnerProgressFill');
    if (fillEl) fillEl.style.transform = `scaleX(${state.task.progress / 100})`;
  }

  // --- LISTEN FOR STATE UPDATES FROM ELECTRON HOST ---
  window.addEventListener('message', (event) => {
    if (!event.data || event.data.target !== '2toolne-flow-overlay') return;
    const { action, payload } = event.data;

    switch (action) {
      case 'set-runner-state':
        setRunnerState(payload);
        break;
      case 'set-automation-state':
        setAutomationState(payload.mode, payload.executionState);
        break;
      case 'set-task-state':
        setTaskState(payload.task);
        break;
      case 'set-status':
        setStatus(payload.message, payload.type, payload.autoHideMs);
        break;
      case 'set-progress':
        setProgress(payload.progress);
        break;
      case 'set-realtime-status':
        setRealtimeStatus(payload.message, payload.type, payload.autoHideMs);
        break;
      case 'set-execution-state':
        state.executionState = payload.executionState || 'IDLE';
        updateExecutionScrim();
        rehydrateRunnerDOM();
        updateSettingsAttentionDot();
        break;
      case 'ensure-mounted':
        ensureMounted();
        break;
      case 'set-selected-media':
        state.selectedMediaIds = new Set(payload.mediaIds || []);
        scanAndDecorateTiles();
        break;
      case 'set-verified-media':
        state.verifiedMediaIds = new Set(payload.mediaIds || []);
        scanAndDecorateTiles();
        break;
      case 'collapse-runner':
        minimizeRunner();
        break;
      case 'restore-runner':
        restoreRunner();
        break;
      case 'sync-settings':
        state.settingsData = payload;
        if (dom.settingsModal) {
          const selProf = dom.settingsModal.querySelector('[id="2toolne-flow-select-profile"]');
          if (selProf && payload.profiles) {
            const activeId = payload.createdProfileId || payload.settings?.active_profile_id || (payload.profiles.find(p => p.is_active)?.id);
            setSafeHTML(selProf, payload.profiles.map(p =>
              `<option value="${p.id}" ${p.id === activeId ? 'selected' : ''}>${p.email || p.name || 'Tài khoản Flow'}</option>`
            ).join(''));
          }
          const s = payload.settings;
          if (s) {
            const selMode = dom.settingsModal.querySelector('[id="2toolne-flow-select-mode"]');
            if (selMode && s.mode) selMode.value = s.mode;

            const selImgModel = dom.settingsModal.querySelector('[id="2toolne-flow-select-image-model"]');
            if (selImgModel && s.image_model) selImgModel.value = s.image_model;

            const concurrencyCtrl = dom.settingsModal.querySelector('[id="2toolne-flow-concurrency-control"]');
            if (concurrencyCtrl && s.image_prompt_batch_size !== undefined) {
              const bVal = String(s.image_prompt_batch_size);
              concurrencyCtrl.setAttribute('data-value', bVal);
              concurrencyCtrl.querySelectorAll('.toolne-segmented-btn').forEach(btn => {
                btn.classList.toggle('active', btn.getAttribute('data-batch') === bVal);
              });
            }

            const selAspect = dom.settingsModal.querySelector('[id="2toolne-flow-select-aspect"]');
            if (selAspect && s.default_aspect) selAspect.value = s.default_aspect;

            const chkAuto = dom.settingsModal.querySelector('[id="2toolne-flow-chk-auto-approve"]');
            if (chkAuto && typeof s.auto_approve_valid === 'boolean') chkAuto.checked = s.auto_approve_valid;
          }
        }
        break;
      case 'save-settings-result':
        if (dom.settingsModal) {
          const saveBtn = dom.settingsModal.querySelector('[id="2toolne-flow-settings-save-btn"]');
          if (saveBtn) {
            saveBtn.style.background = TOKENS.success;
            saveBtn.style.borderColor = TOKENS.success;
            setSafeHTML(saveBtn, `
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#FFF" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
              <span>Đã lưu cài đặt</span>
            `);
          }
          setTimeout(() => {
            closeSettingsModal();
          }, 380);
        }
        break;
      case 'open-settings':
        openSettingsModal();
        break;
      case 'close-settings':
        closeSettingsModal();
        break;
      case 'unmount':
        unmount();
        break;
      default:
        break;
    }
  });

  // Expose Single Canonical Module
  const FlowOverlay = {
    mount,
    ensureMounted,
    unmount,
    setAutomationState,
    setTaskState,
    setStatus,
    setProgress,
    setRunnerState,
    setRealtimeStatus,
    setExecutionState: (s) => setAutomationState(null, s),
    scanTiles: scanAndDecorateTiles,
    openSettings: openSettingsModal,
    closeSettings: closeSettingsModal,
    injectSettingsButton,
    restoreRunner,
    minimizeRunner,
    getState: () => ({ ...state }),
  };

  window.__toolneFlowOverlay = FlowOverlay;
  window.FlowOverlayLayer = FlowOverlay;

  // Mount immediately
  mount();
})();
