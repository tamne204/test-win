"""
apps/capcut-v2/core/dev_tools/pipeline_replay.py
Pipeline Replay Harness for AutoEdit V2.
Enables stage-specific execution using actual production components:
- HierarchicalScriptAligner
- SubtitleSegmenter
- srt_generator
- TimelineBuilder
- RuleEngine
- CapCutAdapter
- DraftNormalizer
"""
from __future__ import annotations

import json
import os
import tempfile
import time
from dataclasses import dataclass, field, asdict
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple, Union

from core.dev_cache import (
    ArtifactCache,
    CacheStage,
    compute_content_hash,
    build_alignment_key,
    build_subtitle_key,
)
from core.dev_tools.draft_normalizer import DraftNormalizer
from core.edit_plan import EditPlan
from core.preset_manager import RulePreset, PRESET_BASIC_SLIDESHOW
from core.subtitles.hierarchical_aligner import HierarchicalScriptAligner
from core.subtitles.models import (
    AlignmentOptions,
    AlignmentResult,
    AlignedToken,
    ScriptToken,
    RegionHealth,
    UnmatchedScriptSpan,
    SpeechWordTimestamp,
    SubtitleCue,
    ConfidenceLevel,
    MatchType,
)
from core.subtitles.script_normalizer import tokenize_script, detect_language
from core.subtitles.srt_generator import generate_srt
from core.subtitles.subtitle_segmenter import SubtitleSegmenter
from core.timeline_builder import TimelineBuilder, TIMING_MODE_SRT_DRIVEN
from adapters.capcut.adapter import CapCutAdapter


@dataclass
class StageTrace:
    stage_name: str
    cache_hit: bool
    duration_ms: float
    input_hash: str
    artifact_hash: str
    warnings: List[str] = field(default_factory=list)


@dataclass
class DevRunManifest:
    start_time: float
    total_duration_ms: float
    stages: List[StageTrace] = field(default_factory=list)
    engine_versions: Dict[str, str] = field(default_factory=dict)
    summary: Dict[str, Any] = field(default_factory=dict)

    def to_dict(self) -> Dict[str, Any]:
        return {
            "start_time": self.start_time,
            "total_duration_ms": self.total_duration_ms,
            "stages": [asdict(s) for s in self.stages],
            "engine_versions": self.engine_versions,
            "summary": self.summary,
        }


@dataclass
class ReplayResult:
    manifest: DevRunManifest
    aligned_tokens: Optional[List[AlignedToken]] = None
    subtitles: Optional[List[SubtitleCue]] = None
    srt_content: Optional[str] = None
    edit_plan: Optional[EditPlan] = None
    draft_dir: Optional[str] = None
    normalized_draft: Optional[Dict[str, Any]] = None


class PipelineReplayHarness:
    """
    Reusable test harness executing partial or complete pipelines
    using real production components and artifact cache.
    """

    def __init__(self, cache_dir: Optional[str] = None):
        self.cache = ArtifactCache(cache_dir=cache_dir)
        self.adapter = CapCutAdapter()

    def replay_from_asr(
        self,
        script_text: str,
        speech_words: List[SpeechWordTimestamp],
        images: List[str],
        audio_duration_s: float,
        audio_path: Optional[str] = None,
        options: Optional[AlignmentOptions] = None,
        preset: Optional[RulePreset] = None,
        draft_target_dir: Optional[str] = None,
        use_cache: bool = True,
    ) -> ReplayResult:
        """
        Replay pipeline from ASR stage:
        Cached ASR -> HierarchicalScriptAligner -> SubtitleSegmenter -> TimelineBuilder -> CapCutAdapter
        """
        t0 = time.perf_counter()
        opts = options or AlignmentOptions()
        traces: List[StageTrace] = []

        detected_lang = detect_language(script_text) if opts.language.upper() == "AUTO" else opts.language.lower()
        script_tokens = tokenize_script(script_text, language=detected_lang)

        # --- Stage 1: Alignment ---
        s1_start = time.perf_counter()
        words_data = [w.__dict__ for w in speech_words]
        asr_hash = compute_content_hash(words_data)
        script_hash = compute_content_hash(script_text)
        align_key = build_alignment_key(script_hash, asr_hash, opts.engine.value)

        cached_align = self.cache.get(CacheStage.ALIGNMENT, align_key) if use_cache else None
        if cached_align:
            s1_hit = True
            aligned_tokens = [
                AlignedToken(
                    script_token=ScriptToken(
                        token_index=t["token_index"],
                        raw_text=t["raw_text"],
                        normalized_text=t["normalized_text"],
                        char_start=t["char_start"],
                        char_end=t["char_end"],
                        leading_whitespace=t.get("leading_whitespace", ""),
                        trailing_punctuation=t.get("trailing_punctuation", ""),
                        paragraph_id=t.get("paragraph_id", 0),
                        sentence_id=t.get("sentence_id", 0),
                        clause_id=t.get("clause_id", 0),
                    ),
                    start_s=t["start_s"],
                    end_s=t["end_s"],
                    confidence=ConfidenceLevel(t["confidence"]),
                    match_type=MatchType(t["match_type"]),
                )
                for t in cached_align["tokens"]
            ]
            align_hash = compute_content_hash(cached_align)
        else:
            s1_hit = False
            aligner = HierarchicalScriptAligner()
            aligned_tokens, _, _, _ = aligner.align(
                script_tokens=script_tokens,
                speech_timestamps=speech_words,
                audio_duration_s=audio_duration_s,
                language=detected_lang,
            )
            cached_payload = {
                "tokens": [
                    {
                        "token_index": t.script_token.token_index,
                        "raw_text": t.script_token.raw_text,
                        "normalized_text": t.script_token.normalized_text,
                        "char_start": t.script_token.char_start,
                        "char_end": t.script_token.char_end,
                        "paragraph_id": t.script_token.paragraph_id,
                        "sentence_id": t.script_token.sentence_id,
                        "clause_id": t.script_token.clause_id,
                        "leading_whitespace": t.script_token.leading_whitespace,
                        "trailing_punctuation": t.script_token.trailing_punctuation,
                        "start_s": t.start_s,
                        "end_s": t.end_s,
                        "confidence": t.confidence.value,
                        "match_type": t.match_type.value,
                    }
                    for t in aligned_tokens
                ]
            }
            align_hash = self.cache.put(CacheStage.ALIGNMENT, align_key, cached_payload)

        s1_dur = (time.perf_counter() - s1_start) * 1000.0
        traces.append(StageTrace("alignment", s1_hit, s1_dur, script_hash, align_hash))

        # --- Stage 2: Subtitle Segmentation & SRT ---
        s2_start = time.perf_counter()
        sub_key = build_subtitle_key(align_hash, opts.to_dict() if hasattr(opts, "to_dict") else None)
        cached_sub = self.cache.get(CacheStage.SUBTITLE, sub_key) if use_cache else None
        if cached_sub:
            s2_hit = True
            sub_cues = [
                SubtitleCue(
                    index=c["index"],
                    start_s=c["start_s"],
                    end_s=c["end_s"],
                    text=c["text"],
                    confidence=ConfidenceLevel(c.get("confidence", "HIGH")),
                    source_token_start=c.get("source_token_start", 0),
                    source_token_end=c.get("source_token_end", 0),
                    paragraph_ids=c.get("paragraph_ids", []),
                    sentence_ids=c.get("sentence_ids", []),
                    alignment_confidence=c.get("alignment_confidence", 1.0),
                )
                for c in cached_sub["cues"]
            ]
            srt_content = cached_sub["srt_content"]
            sub_hash = compute_content_hash(cached_sub)
        else:
            s2_hit = False
            segmenter = SubtitleSegmenter(options=opts)
            sub_cues = segmenter.segment(aligned_tokens)
            srt_content = generate_srt(sub_cues)
            sub_dict = {
                "cues": [c.to_dict() for c in sub_cues],
                "srt_content": srt_content,
            }
            sub_hash = self.cache.put(CacheStage.SUBTITLE, sub_key, sub_dict)

        s2_dur = (time.perf_counter() - s2_start) * 1000.0
        traces.append(StageTrace("subtitle_segmenter", s2_hit, s2_dur, align_hash, sub_hash))

        # --- Stage 3: Timeline Generation ---
        s3_start = time.perf_counter()
        tb = TimelineBuilder(preset=preset or PRESET_BASIC_SLIDESHOW)
        edit_plan = tb.build(
            images=images,
            audio_path=audio_path,
            srt_source=srt_content,
            script_text=script_text,
            timing_mode=TIMING_MODE_SRT_DRIVEN,
        )
        plan_dict = edit_plan.to_dict()
        plan_hash = compute_content_hash(plan_dict)
        s3_dur = (time.perf_counter() - s3_start) * 1000.0
        traces.append(StageTrace("timeline_builder", False, s3_dur, sub_hash, plan_hash))

        # --- Stage 4: Draft Generation & Normalization ---
        s4_start = time.perf_counter()
        if not draft_target_dir:
            temp_dir = tempfile.mkdtemp(prefix="replay_draft_")
            draft_target_dir = temp_dir

        gen_res = self.adapter.generate(
            edit_plan=edit_plan,
            target_dir=draft_target_dir,
            draft_root_path=str(Path(draft_target_dir).parent),
        )
        assert gen_res["validated"] is True, "Generated draft failed schema validation"

        norm_info, _ = DraftNormalizer.normalize_draft_directory(draft_target_dir)
        draft_hash = compute_content_hash(norm_info)
        s4_dur = (time.perf_counter() - s4_start) * 1000.0
        traces.append(StageTrace("capcut_adapter", False, s4_dur, plan_hash, draft_hash))

        total_ms = (time.perf_counter() - t0) * 1000.0
        manifest = DevRunManifest(
            start_time=t0,
            total_duration_ms=total_ms,
            stages=traces,
            engine_versions={
                "alignment": opts.engine.value,
                "segmenter": "a0_subtitle_v1",
                "timeline": "timeline_builder_v2",
                "adapter": "capcut_9_3",
            },
            summary={
                "cues_count": len(sub_cues),
                "clips_count": len(edit_plan.clips),
                "total_timeline_duration_us": edit_plan.project.duration_us,
            },
        )

        return ReplayResult(
            manifest=manifest,
            aligned_tokens=aligned_tokens,
            subtitles=sub_cues,
            srt_content=srt_content,
            edit_plan=edit_plan,
            draft_dir=draft_target_dir,
            normalized_draft=norm_info,
        )

    def replay_from_srt(
        self,
        srt_content_or_path: str,
        images: List[str],
        script_text: Optional[str] = None,
        audio_path: Optional[str] = None,
        preset: Optional[RulePreset] = None,
        draft_target_dir: Optional[str] = None,
    ) -> ReplayResult:
        """
        Replay pipeline from existing SRT:
        SRT -> TimelineBuilder -> CapCutAdapter -> DraftNormalizer
        """
        t0 = time.perf_counter()
        traces: List[StageTrace] = []

        if os.path.isfile(srt_content_or_path):
            with open(srt_content_or_path, "r", encoding="utf-8") as f:
                srt_content = f.read()
        else:
            srt_content = srt_content_or_path

        srt_hash = compute_content_hash(srt_content)

        # Stage 1: Timeline
        s1_start = time.perf_counter()
        tb = TimelineBuilder(preset=preset or PRESET_BASIC_SLIDESHOW)
        edit_plan = tb.build(
            images=images,
            audio_path=audio_path,
            srt_source=srt_content,
            script_text=script_text,
            timing_mode=TIMING_MODE_SRT_DRIVEN,
        )
        plan_dict = edit_plan.to_dict()
        plan_hash = compute_content_hash(plan_dict)
        s1_dur = (time.perf_counter() - s1_start) * 1000.0
        traces.append(StageTrace("timeline_builder", False, s1_dur, srt_hash, plan_hash))

        # Stage 2: Draft
        s2_start = time.perf_counter()
        if not draft_target_dir:
            temp_dir = tempfile.mkdtemp(prefix="replay_draft_")
            draft_target_dir = temp_dir

        gen_res = self.adapter.generate(
            edit_plan=edit_plan,
            target_dir=draft_target_dir,
            draft_root_path=str(Path(draft_target_dir).parent),
        )
        assert gen_res["validated"] is True

        norm_info, _ = DraftNormalizer.normalize_draft_directory(draft_target_dir)
        draft_hash = compute_content_hash(norm_info)
        s2_dur = (time.perf_counter() - s2_start) * 1000.0
        traces.append(StageTrace("capcut_adapter", False, s2_dur, plan_hash, draft_hash))

        total_ms = (time.perf_counter() - t0) * 1000.0
        manifest = DevRunManifest(
            start_time=t0,
            total_duration_ms=total_ms,
            stages=traces,
            summary={
                "clips_count": len(edit_plan.clips),
                "total_timeline_duration_us": edit_plan.project.duration_us,
            },
        )

        return ReplayResult(
            manifest=manifest,
            srt_content=srt_content,
            edit_plan=edit_plan,
            draft_dir=draft_target_dir,
            normalized_draft=norm_info,
        )

    def replay_from_editplan(
        self,
        edit_plan: EditPlan,
        draft_target_dir: Optional[str] = None,
    ) -> ReplayResult:
        """
        Replay pipeline from EditPlan:
        EditPlan -> CapCutAdapter -> DraftNormalizer
        """
        t0 = time.perf_counter()
        traces: List[StageTrace] = []

        plan_hash = compute_content_hash(edit_plan.to_dict())

        s_start = time.perf_counter()
        if not draft_target_dir:
            temp_dir = tempfile.mkdtemp(prefix="replay_draft_")
            draft_target_dir = temp_dir

        gen_res = self.adapter.generate(
            edit_plan=edit_plan,
            target_dir=draft_target_dir,
            draft_root_path=str(Path(draft_target_dir).parent),
        )
        assert gen_res["validated"] is True

        norm_info, _ = DraftNormalizer.normalize_draft_directory(draft_target_dir)
        draft_hash = compute_content_hash(norm_info)
        s_dur = (time.perf_counter() - s_start) * 1000.0
        traces.append(StageTrace("capcut_adapter", False, s_dur, plan_hash, draft_hash))

        total_ms = (time.perf_counter() - t0) * 1000.0
        manifest = DevRunManifest(
            start_time=t0,
            total_duration_ms=total_ms,
            stages=traces,
            summary={
                "clips_count": len(edit_plan.clips),
                "total_timeline_duration_us": edit_plan.project.duration_us,
            },
        )

        return ReplayResult(
            manifest=manifest,
            edit_plan=edit_plan,
            draft_dir=draft_target_dir,
            normalized_draft=norm_info,
        )
