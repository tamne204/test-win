"""
apps/capcut-v2/core/subtitles/layout_engine.py
SubtitleLayoutEngine for 2TOOLNE AutoEdit V2.

Prevents any generated CapCut caption from exceeding the visible horizontal safe area
(default 82% of canvas width) while strictly preserving A0 subtitle timing and verbatim SubtitleCue.text.
"""

from __future__ import annotations

import logging
import os
import re
import unicodedata
from dataclasses import dataclass, field
from typing import Any, Dict, List, Optional, Tuple, Union

logger = logging.getLogger(__name__)

# System TrueType font search candidates (in priority order)
MACOS_FONT_CANDIDATES = [
    "/System/Library/Fonts/AppleSDGothicNeo.ttc",
    "/Library/Fonts/Arial Unicode.ttf",
    "/System/Library/Fonts/Supplemental/Arial.ttf",
    "/System/Library/Fonts/Helvetica.ttc",
    "/System/Library/Fonts/SFNS.ttf",
]

LINUX_FONT_CANDIDATES = [
    "/usr/share/fonts/truetype/nanum/NanumGothic.ttf",
    "/usr/share/fonts/truetype/noto/NotoSansCJK-Regular.ttc",
    "/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf",
]

WINDOWS_FONT_CANDIDATES = [
    "C:/Windows/Fonts/malgun.ttf",
    "C:/Windows/Fonts/arial.ttf",
    "C:/Windows/Fonts/segoeui.ttf",
]


@dataclass
class LayoutOptions:
    """Configuration options for subtitle layout and wrapping."""
    canvas_width: int = 1080
    canvas_height: int = 1920
    safe_width_ratio: float = 0.82
    max_lines: int = 2
    min_font_scale: float = 0.85
    default_font_size: float = 8.0
    font_size_scale_factor: float = 6.0  # Calibrated for CapCut 1080p canvas (8.0 -> 48px)
    font_path: Optional[str] = None
    allow_extra_lines_for_overflow_prevention: bool = True

    @property
    def safe_width_px(self) -> float:
        return float(self.canvas_width) * float(self.safe_width_ratio)

    @property
    def base_font_size_px(self) -> float:
        return float(self.default_font_size) * float(self.font_size_scale_factor)


@dataclass
class CaptionLayoutResult:
    """Result of formatting a single caption for presentation."""
    original_text: str
    display_text: str
    font_scale: float
    line_count: int
    line_widths_px: List[float]
    max_line_width_px: float
    safe_width_px: float
    overflow: bool
    font_size_px: float


class FontMetricsManager:
    """
    Measures rendered pixel width for text strings across diverse languages
    (Korean, Vietnamese, English, mixed punctuation).
    
    Uses PIL.ImageFont with auto-detected system TrueType fonts, falling back to
    deterministic Unicode East Asian Width metrics when fonts are unavailable.
    """

    def __init__(self, font_path: Optional[str] = None):
        self._font_path = self._resolve_font_path(font_path)
        self._font_cache: Dict[int, Any] = {}
        self._pil_available: bool = False
        try:
            from PIL import ImageFont  # noqa: F401
            self._pil_available = True
        except ImportError:
            self._pil_available = False
            logger.warning("PIL not available; falling back to deterministic Unicode font metrics.")

    def _resolve_font_path(self, user_font_path: Optional[str]) -> Optional[str]:
        if user_font_path and os.path.isfile(user_font_path):
            return user_font_path

        # Scan candidates based on platform
        all_candidates = MACOS_FONT_CANDIDATES + LINUX_FONT_CANDIDATES + WINDOWS_FONT_CANDIDATES
        for path in all_candidates:
            if os.path.isfile(path):
                return path

        return None

    @property
    def resolved_font_path(self) -> Optional[str]:
        return self._font_path

    def _get_font_instance(self, size_px: int) -> Optional[Any]:
        if not self._pil_available or not self._font_path:
            return None
        if size_px not in self._font_cache:
            try:
                from PIL import ImageFont
                self._font_cache[size_px] = ImageFont.truetype(self._font_path, size_px)
            except Exception as e:
                logger.warning(f"Failed to load font {self._font_path} at size {size_px}: {e}")
                return None
        return self._font_cache.get(size_px)

    def measure_text(self, text: str, font_size_px: float) -> float:
        """
        Measure rendered pixel width of a single-line string.
        """
        if not text:
            return 0.0

        int_size = max(1, int(round(font_size_px)))
        font = self._get_font_instance(int_size)
        if font is not None:
            try:
                bb = font.getbbox(text)
                return float(bb[2] - bb[0])
            except Exception:
                pass

        # Deterministic Unicode-aware fallback metric engine
        return self._measure_fallback(text, font_size_px)

    def _measure_fallback(self, text: str, font_size_px: float) -> float:
        """
        Calibrated fallback based on Unicode character properties.
        Wide/Fullwidth/CJK/Hangul = 1.0 em
        Standard Latin/Vietnamese = ~0.52 em
        Narrow (i, j, l, spaces, punctuation) = ~0.28 em
        Wide Latin (W, M, @) = ~0.85 em
        Combining diacritics = 0.0 em
        """
        total_em = 0.0
        normalized = unicodedata.normalize("NFC", text)
        for ch in normalized:
            if unicodedata.combining(ch) > 0:
                continue
            ea = unicodedata.east_asian_width(ch)
            if ea in ("W", "F"):
                total_em += 1.0
            elif ch in "ijlI.,:;!'| ":
                total_em += 0.28
            elif ch in "frt-()[]{}":
                total_em += 0.38
            elif ch in "WM@%#&":
                total_em += 0.85
            elif ch.isupper() or ch.isdigit():
                total_em += 0.65
            else:
                total_em += 0.52

        return total_em * float(font_size_px)

    def get_max_line_width(self, text: str, font_size_px: float) -> Tuple[List[float], float]:
        """
        Measure all lines in a multiline text block.
        Returns (list_of_widths, max_width).
        """
        lines = text.splitlines() if text else [""]
        widths = [self.measure_text(line, font_size_px) for line in lines]
        max_w = max(widths) if widths else 0.0
        return widths, max_w


class SubtitleLayoutEngine:
    """
    Lays out subtitle captions for CapCut timeline presentation.
    
    Guarantees:
    - Never modifies SubtitleCue.text or subtitle timing.
    - Generates display_text with newline wrapping within safe width (default 82%).
    - Max 2 lines by default, preferring balanced splits at punctuation or whitespace.
    - Fallback to grapheme-cluster splitting for unbroken words without whitespace.
    - Progressive font scale reduction (down to min_font_scale=0.85) when needed.
    - Never crops text or allows overflow.
    """

    def __init__(self, options: Optional[LayoutOptions] = None):
        self.options = options or LayoutOptions()
        self.metrics = FontMetricsManager(font_path=self.options.font_path)

    @staticmethod
    def _get_grapheme_clusters(text: str) -> List[str]:
        """Split text into Unicode grapheme clusters (base char + combining marks)."""
        clusters: List[str] = []
        for c in unicodedata.normalize("NFC", text):
            if clusters and unicodedata.combining(c) > 0:
                clusters[-1] += c
            else:
                clusters.append(c)
        return clusters

    def _get_candidate_splits(self, text: str) -> List[Tuple[str, str, float, str]]:
        """
        Identify candidate 2-line split points with syntactic preference scores.
        Returns list of (line1, line2, bonus_score, split_type).
        Negative bonus improves the split preference.
        """
        candidates: List[Tuple[str, str, float, str]] = []
        n = len(text)
        if n < 2:
            return candidates

        # 1. Whitespace boundaries
        for m in re.finditer(r"\s+", text):
            idx = m.start()
            end_idx = m.end()
            l1 = text[:idx].strip()
            l2 = text[end_idx:].strip()
            if l1 and l2:
                # Bonus for breaking after punctuation
                bonus = 0.0
                if l1[-1] in ".!?。！？":
                    bonus = -0.35  # Major sentence boundary
                elif l1[-1] in ",;:—~、，":
                    bonus = -0.20  # Clause boundary
                elif l1[-1] in "-/":
                    bonus = -0.10
                candidates.append((l1, l2, bonus, "whitespace"))

        # 2. Punctuation boundaries without trailing whitespace (e.g. CJK or dense writing)
        for m in re.finditer(r"[,.;:!?—、，。！？]", text):
            idx = m.end()
            if idx < n and not text[idx].isspace():
                l1 = text[:idx].strip()
                l2 = text[idx:].strip()
                if l1 and l2:
                    punc_char = text[m.start()]
                    bonus = -0.30 if punc_char in ".!?。！？" else -0.15
                    candidates.append((l1, l2, bonus, "punctuation"))

        # 3. Grapheme cluster fallback if candidate count is small (e.g. unbroken words / Korean)
        if len(candidates) < 4:
            clusters = self._get_grapheme_clusters(text)
            num_c = len(clusters)
            if num_c > 1:
                for i in range(1, num_c):
                    l1 = "".join(clusters[:i]).strip()
                    l2 = "".join(clusters[i:]).strip()
                    if l1 and l2:
                        candidates.append((l1, l2, 0.40, "grapheme"))

        return candidates

    def _wrap_multiline_fallback(self, text: str, font_size_px: float, safe_w: float) -> List[str]:
        """
        Multi-line greedy wrapping to guarantee ZERO overflow when 2 lines cannot fit
        even at minimum font scale.
        """
        raw_tokens = re.findall(r"\S+|\s+", text)
        tokens: List[str] = []

        for t in raw_tokens:
            if not t.isspace() and self.metrics.measure_text(t, font_size_px) > safe_w:
                cur_frag = ""
                for g in self._get_grapheme_clusters(t):
                    if self.metrics.measure_text(cur_frag + g, font_size_px) <= safe_w:
                        cur_frag += g
                    else:
                        if cur_frag:
                            tokens.append(cur_frag)
                        cur_frag = g
                if cur_frag:
                    tokens.append(cur_frag)
            else:
                tokens.append(t)

        lines: List[str] = []
        cur_line = ""
        for t in tokens:
            test_line = cur_line + t
            if self.metrics.measure_text(test_line.strip(), font_size_px) <= safe_w:
                cur_line = test_line
            else:
                if cur_line.strip():
                    lines.append(cur_line.strip())
                cur_line = t.lstrip() if t.isspace() else t
        if cur_line.strip():
            lines.append(cur_line.strip())

        return lines if lines else [text]

    def layout_caption(
        self,
        text: str,
        font_size: Optional[float] = None,
    ) -> CaptionLayoutResult:
        """
        Layout a single caption string.
        Returns a CaptionLayoutResult with display_text, font_scale, and line metrics.
        """
        clean_text = text.strip() if text else ""
        if not clean_text:
            return CaptionLayoutResult(
                original_text=text,
                display_text=clean_text,
                font_scale=1.0,
                line_count=1,
                line_widths_px=[0.0],
                max_line_width_px=0.0,
                safe_width_px=self.options.safe_width_px,
                overflow=False,
                font_size_px=self.options.base_font_size_px,
            )

        safe_w = self.options.safe_width_px
        base_size = (
            float(font_size or self.options.default_font_size)
            * self.options.font_size_scale_factor
        )

        # 1. Check if single line fits at 100% font scale
        single_w = self.metrics.measure_text(clean_text, base_size)
        if single_w <= safe_w:
            return CaptionLayoutResult(
                original_text=text,
                display_text=clean_text,
                font_scale=1.0,
                line_count=1,
                line_widths_px=[single_w],
                max_line_width_px=single_w,
                safe_width_px=safe_w,
                overflow=False,
                font_size_px=base_size,
            )

        # 2. Single line exceeds safe width. Try 2-line balanced wrap.
        candidates = self._get_candidate_splits(clean_text)

        # Progressive font scale reduction search: 1.00 down to min_font_scale (0.85)
        scale_steps = int(round((1.0 - self.options.min_font_scale) / 0.01)) + 1
        for step in range(scale_steps):
            scale = round(1.0 - step * 0.01, 3)
            cur_size = base_size * scale

            valid_splits = []
            for l1, l2, bonus, split_type in candidates:
                w1 = self.metrics.measure_text(l1, cur_size)
                w2 = self.metrics.measure_text(l2, cur_size)
                if max(w1, w2) <= safe_w:
                    balance_penalty = abs(w1 - w2) / safe_w
                    score = balance_penalty + bonus
                    valid_splits.append((score, l1, l2, w1, w2))

            if valid_splits:
                valid_splits.sort(key=lambda item: item[0])
                best = valid_splits[0]
                disp = f"{best[1]}\n{best[2]}"
                w_list = [best[3], best[4]]
                return CaptionLayoutResult(
                    original_text=text,
                    display_text=disp,
                    font_scale=scale,
                    line_count=2,
                    line_widths_px=w_list,
                    max_line_width_px=max(w_list),
                    safe_width_px=safe_w,
                    overflow=False,
                    font_size_px=cur_size,
                )

        # 3. If 2 lines cannot fit within safe width even at min_font_scale,
        # wrap to 3+ lines at min_font_scale to strictly prevent any overflow or clipping.
        final_scale = self.options.min_font_scale
        final_size = base_size * final_scale
        lines = self._wrap_multiline_fallback(clean_text, final_size, safe_w)
        line_widths = [self.metrics.measure_text(l, final_size) for l in lines]
        max_w = max(line_widths) if line_widths else 0.0
        overflow = any(w > safe_w for w in line_widths)

        return CaptionLayoutResult(
            original_text=text,
            display_text="\n".join(lines),
            font_scale=final_scale,
            line_count=len(lines),
            line_widths_px=line_widths,
            max_line_width_px=max_w,
            safe_width_px=safe_w,
            overflow=overflow,
            font_size_px=final_size,
        )


class SubtitleLayoutValidator:
    """
    Validates caption layouts and verifies that SUBTITLE_LAYOUT_OVERFLOW == 0.
    """

    @staticmethod
    def validate_captions(
        captions: List[Any],
        safe_width_px: float,
        font_metrics: Optional[FontMetricsManager] = None,
        font_size_scale_factor: float = 6.0,
        default_font_size: float = 8.0,
    ) -> Dict[str, Any]:
        """
        Inspects captions (EditPlanCaption or CaptionLayoutResult) and reports layout metrics.
        """
        metrics = font_metrics or FontMetricsManager()
        caption_count = len(captions)
        wrapped_count = 0
        font_reduced_count = 0
        max_lines = 1
        overflow_count = 0
        max_observed_width = 0.0

        for cap in captions:
            disp = getattr(cap, "display_text", None)
            if disp is None:
                disp = getattr(cap, "text", "")

            lines = disp.splitlines() if disp else [""]
            line_count = len(lines)
            if line_count > 1 or "\n" in disp:
                wrapped_count += 1
            if line_count > max_lines:
                max_lines = line_count

            font_scale = getattr(cap, "font_scale", 1.0)
            if font_scale < 0.999:
                font_reduced_count += 1

            base_font_size = getattr(cap, "font_size", default_font_size)
            eff_font_size_px = base_font_size * font_scale * font_size_scale_factor

            cap_overflow = False
            for line in lines:
                w = metrics.measure_text(line, eff_font_size_px)
                if w > max_observed_width:
                    max_observed_width = w
                if w > safe_width_px:
                    cap_overflow = True

            if cap_overflow:
                overflow_count += 1

        return {
            "caption_count": caption_count,
            "captions_wrapped": wrapped_count,
            "captions_font_reduced": font_reduced_count,
            "max_lines": max_lines,
            "max_observed_width_px": round(max_observed_width, 1),
            "safe_width_px": round(safe_width_px, 1),
            "overflow_count": overflow_count,
            "SUBTITLE_LAYOUT_OVERFLOW": overflow_count,
        }
