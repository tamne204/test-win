"""
tests/fixtures/golden/generator.py
Golden Test Corpus definition and synthetic fixture generator for AutoEdit V2.
Guarantees content-addressed determinism without repository bloat.
"""
from __future__ import annotations

import json
import os
from dataclasses import dataclass, field, asdict
from pathlib import Path
from typing import Any, Dict, List, Optional

from core.dev_cache.cache_keys import compute_content_hash

# Path to the real LONG_01 ASR cache from Phase A0
LONG_01_ASR_PATH = Path(__file__).resolve().parents[3] / "reports" / "accuracy" / "a0" / "long_01_asr_cache.json"


@dataclass
class GoldenWord:
    word: str
    start: float
    end: float
    confidence: float = 0.95
    original_index: int = 0


@dataclass
class GoldenFixture:
    fixture_id: str
    description: str
    language: str
    script: str
    audio_duration_s: float
    words: List[GoldenWord]
    images: List[str]
    metadata: Dict[str, Any] = field(default_factory=dict)

    @property
    def script_hash(self) -> str:
        return compute_content_hash(self.script)

    @property
    def words_hash(self) -> str:
        return compute_content_hash([asdict(w) for w in self.words])

    @property
    def images_hash(self) -> str:
        return compute_content_hash(self.images)

    @property
    def fixture_hash(self) -> str:
        return compute_content_hash({
            "fixture_id": self.fixture_id,
            "script_hash": self.script_hash,
            "words_hash": self.words_hash,
            "images_hash": self.images_hash,
            "audio_duration_s": self.audio_duration_s,
        })

    def to_dict(self) -> Dict[str, Any]:
        return {
            "fixture_id": self.fixture_id,
            "description": self.description,
            "language": self.language,
            "script": self.script,
            "audio_duration_s": self.audio_duration_s,
            "words_count": len(self.words),
            "images_count": len(self.images),
            "script_hash": self.script_hash,
            "words_hash": self.words_hash,
            "images_hash": self.images_hash,
            "fixture_hash": self.fixture_hash,
            "metadata": self.metadata,
        }


class GoldenCorpus:
    """Factory and catalog of standardized golden fixtures."""

    @classmethod
    def get_fixture(cls, fixture_id: str) -> GoldenFixture:
        fixtures = cls.get_all_fixtures()
        if fixture_id not in fixtures:
            raise KeyError(f"Unknown golden fixture: {fixture_id}. Available: {list(fixtures.keys())}")
        return fixtures[fixture_id]

    @classmethod
    def get_all_fixtures(cls) -> Dict[str, GoldenFixture]:
        return {
            "GOLDEN_SHORT_VI": cls._build_short_vi(),
            "GOLDEN_SHORT_KO": cls._build_short_ko(),
            "GOLDEN_LONG_01": cls._build_long_01(),
            "GOLDEN_SILENT_TAIL": cls._build_silent_tail(),
            "GOLDEN_SCRIPT_OMISSION": cls._build_script_omission(),
            "GOLDEN_IMAGE_SHORTAGE": cls._build_image_shortage(),
            "GOLDEN_IMAGE_SURPLUS": cls._build_image_surplus(),
            "GOLDEN_DUPLICATE_FILENAMES": cls._build_duplicate_filenames(),
            "GOLDEN_MANY_PARAGRAPHS": cls._build_many_paragraphs(),
            "GOLDEN_LONG_PARAGRAPH": cls._build_long_paragraph(),
            "GOLDEN_NO_SUBTITLE": cls._build_no_subtitle(),
            "GOLDEN_AUTOSUB": cls._build_autosub(),
            "GOLDEN_FORCED_ALIGNMENT": cls._build_forced_alignment(),
        }

    @staticmethod
    def _build_short_vi() -> GoldenFixture:
        script = "Chào bạn.\nĐây là video thử nghiệm.\nChúc bạn một ngày tốt lành."
        words = [
            GoldenWord("Chào", 0.2, 0.6, 0.98, 0),
            GoldenWord("bạn.", 0.7, 1.1, 0.97, 1),
            GoldenWord("Đây", 1.5, 1.8, 0.95, 2),
            GoldenWord("là", 1.9, 2.1, 0.96, 3),
            GoldenWord("video", 2.2, 2.6, 0.94, 4),
            GoldenWord("thử", 2.7, 2.9, 0.95, 5),
            GoldenWord("nghiệm.", 3.0, 3.4, 0.98, 6),
            GoldenWord("Chúc", 3.8, 4.1, 0.96, 7),
            GoldenWord("bạn", 4.2, 4.4, 0.97, 8),
            GoldenWord("một", 4.5, 4.7, 0.95, 9),
            GoldenWord("ngày", 4.8, 5.0, 0.96, 10),
            GoldenWord("tốt", 5.1, 5.3, 0.97, 11),
            GoldenWord("lành.", 5.4, 5.8, 0.98, 12),
        ]
        images = ["/mock/vi_1.png", "/mock/vi_2.png", "/mock/vi_3.png"]
        return GoldenFixture(
            fixture_id="GOLDEN_SHORT_VI",
            description="Standard short Vietnamese script with 3 paragraphs",
            language="vi",
            script=script,
            audio_duration_s=6.5,
            words=words,
            images=images,
            metadata={"source": "synthetic_standard"},
        )

    @staticmethod
    def _build_short_ko() -> GoldenFixture:
        script = "안녕하세요.\n오늘도 좋은 하루 되세요."
        words = [
            GoldenWord("안녕하세요.", 0.3, 1.2, 0.96, 0),
            GoldenWord("오늘도", 1.8, 2.4, 0.95, 1),
            GoldenWord("좋은", 2.5, 2.9, 0.94, 2),
            GoldenWord("하루", 3.0, 3.4, 0.97, 3),
            GoldenWord("되세요.", 3.5, 4.1, 0.96, 4),
        ]
        images = ["/mock/ko_1.png", "/mock/ko_2.png"]
        return GoldenFixture(
            fixture_id="GOLDEN_SHORT_KO",
            description="Short Korean script with polite sentence structure",
            language="ko",
            script=script,
            audio_duration_s=5.0,
            words=words,
            images=images,
            metadata={"source": "synthetic_standard"},
        )

    @staticmethod
    def _build_long_01() -> GoldenFixture:
        words: List[GoldenWord] = []
        if LONG_01_ASR_PATH.is_file():
            with open(LONG_01_ASR_PATH, "r", encoding="utf-8") as f:
                raw = json.load(f)
            for idx, item in enumerate(raw):
                words.append(
                    GoldenWord(
                        word=item["word"],
                        start=item["start"],
                        end=item["end"],
                        confidence=item.get("confidence", 0.92),
                        original_index=idx,
                    )
                )

        # Mock image list for LONG_01 (278 physical images)
        images = [f"/mock/anh_kb{i:03d}.png" for i in range(1, 279)]
        script = "LONG_01 Master Script Reference (266 paragraphs, 616 sentences)"

        return GoldenFixture(
            fixture_id="GOLDEN_LONG_01",
            description="Production regression benchmark (29m47s audio, 1641s speech, 278 images)",
            language="vi",
            script=script,
            audio_duration_s=1787.233333,
            words=words,
            images=images,
            metadata={
                "source": "production_long_01",
                "real_asr_words_count": len(words),
                "total_paragraphs": 266,
                "total_images": 278,
            },
        )

    @staticmethod
    def _build_silent_tail() -> GoldenFixture:
        script = "Lời kết thúc tại giây thứ năm."
        words = [
            GoldenWord("Lời", 0.5, 1.0, 0.95, 0),
            GoldenWord("kết", 1.1, 1.5, 0.96, 1),
            GoldenWord("thúc", 1.6, 2.0, 0.94, 2),
            GoldenWord("tại", 2.2, 2.5, 0.95, 3),
            GoldenWord("giây", 2.6, 3.0, 0.97, 4),
            GoldenWord("thứ", 3.1, 3.4, 0.96, 5),
            GoldenWord("năm.", 3.5, 4.2, 0.98, 6),
        ]
        images = ["/mock/tail_1.png", "/mock/tail_2.png"]
        return GoldenFixture(
            fixture_id="GOLDEN_SILENT_TAIL",
            description="Speech terminates at 4.2s but audio continues silently until 20.0s",
            language="vi",
            script=script,
            audio_duration_s=20.0,
            words=words,
            images=images,
            metadata={"speech_end_s": 4.2, "silence_duration_s": 15.8},
        )

    @staticmethod
    def _build_script_omission() -> GoldenFixture:
        script = "Đoạn này có trong văn bản nhưng người đọc đã bỏ qua hoàn toàn không nói."
        words = [
            GoldenWord("Đoạn", 0.5, 0.9, 0.95, 0),
            GoldenWord("này", 1.0, 1.3, 0.94, 1),
            GoldenWord("có", 1.4, 1.7, 0.96, 2),
            GoldenWord("trong", 1.8, 2.2, 0.95, 3),
            GoldenWord("văn", 2.3, 2.6, 0.97, 4),
            GoldenWord("bản", 2.7, 3.2, 0.98, 5),
        ]
        images = ["/mock/omission_1.png"]
        return GoldenFixture(
            fixture_id="GOLDEN_SCRIPT_OMISSION",
            description="Script contains tail sentence that was omitted by speaker",
            language="vi",
            script=script,
            audio_duration_s=5.0,
            words=words,
            images=images,
            metadata={"omitted_words_count": 8},
        )

    @staticmethod
    def _build_image_shortage() -> GoldenFixture:
        script = "Một.\nHai.\nBa.\nBốn.\nNăm.\nSáu."
        words = [
            GoldenWord("Một.", 0.5, 1.0, 0.95, 0),
            GoldenWord("Hai.", 1.5, 2.0, 0.95, 1),
            GoldenWord("Ba.", 2.5, 3.0, 0.95, 2),
            GoldenWord("Bốn.", 3.5, 4.0, 0.95, 3),
            GoldenWord("Năm.", 4.5, 5.0, 0.95, 4),
            GoldenWord("Sáu.", 5.5, 6.0, 0.95, 5),
        ]
        # Only 2 images for 6 segments!
        images = ["/mock/short_1.png", "/mock/short_2.png"]
        return GoldenFixture(
            fixture_id="GOLDEN_IMAGE_SHORTAGE",
            description="6 cues but only 2 physical images",
            language="vi",
            script=script,
            audio_duration_s=7.0,
            words=words,
            images=images,
            metadata={"cues": 6, "images": 2},
        )

    @staticmethod
    def _build_image_surplus() -> GoldenFixture:
        script = "Chỉ có hai câu.\nHết rồi."
        words = [
            GoldenWord("Chỉ", 0.5, 0.8, 0.95, 0),
            GoldenWord("có", 0.9, 1.2, 0.95, 1),
            GoldenWord("hai", 1.3, 1.6, 0.96, 2),
            GoldenWord("câu.", 1.7, 2.2, 0.97, 3),
            GoldenWord("Hết", 2.8, 3.2, 0.95, 4),
            GoldenWord("rồi.", 3.3, 3.8, 0.98, 5),
        ]
        # 10 images for 2 segments!
        images = [f"/mock/surplus_{i}.png" for i in range(10)]
        return GoldenFixture(
            fixture_id="GOLDEN_IMAGE_SURPLUS",
            description="2 cues but 10 images provided",
            language="vi",
            script=script,
            audio_duration_s=4.5,
            words=words,
            images=images,
            metadata={"cues": 2, "images": 10},
        )

    @staticmethod
    def _build_duplicate_filenames() -> GoldenFixture:
        script = "Hình ảnh có cùng tên tệp trong các thư mục khác nhau."
        words = [
            GoldenWord("Hình", 0.5, 0.9, 0.95, 0),
            GoldenWord("ảnh", 1.0, 1.4, 0.96, 1),
            GoldenWord("có", 1.5, 1.8, 0.95, 2),
            GoldenWord("cùng", 1.9, 2.2, 0.94, 3),
            GoldenWord("tên.", 2.3, 2.8, 0.97, 4),
        ]
        images = [
            "/mock/folder_a/slide.png",
            "/mock/folder_b/slide.png",
            "/mock/folder_c/slide.png",
        ]
        return GoldenFixture(
            fixture_id="GOLDEN_DUPLICATE_FILENAMES",
            description="Images with duplicate base filenames across different folders",
            language="vi",
            script=script,
            audio_duration_s=3.5,
            words=words,
            images=images,
            metadata={"duplicate_basename": "slide.png"},
        )

    @staticmethod
    def _build_many_paragraphs() -> GoldenFixture:
        paras = [f"Mục số {i}." for i in range(1, 21)]
        script = "\n".join(paras)
        words = []
        t = 0.5
        idx = 0
        for i in range(1, 21):
            words.append(GoldenWord("Mục", round(t, 2), round(t + 0.3, 2), 0.95, idx))
            words.append(GoldenWord("số", round(t + 0.35, 2), round(t + 0.6, 2), 0.95, idx + 1))
            words.append(GoldenWord(f"{i}.", round(t + 0.65, 2), round(t + 1.0, 2), 0.97, idx + 2))
            t += 1.3
            idx += 3
        images = [f"/mock/para_{i}.png" for i in range(1, 21)]
        return GoldenFixture(
            fixture_id="GOLDEN_MANY_PARAGRAPHS",
            description="20 distinct short paragraphs to stress segment boundary logic",
            language="vi",
            script=script,
            audio_duration_s=round(t + 0.5, 2),
            words=words,
            images=images,
            metadata={"paragraph_count": 20},
        )

    @staticmethod
    def _build_long_paragraph() -> GoldenFixture:
        text_words = [
            "Đây", "là", "một", "đoạn", "văn", "bản", "rất", "dài", "không", "hề",
            "có", "dấu", "xuống", "dòng", "nhằm", "mục", "đích", "kiểm", "tra", "khả",
            "năng", "chia", "phân", "đoạn", "tự", "động", "của", "hệ", "thống", "subtitles"
        ]
        script = " ".join(text_words) + "."
        words = []
        t = 0.5
        for idx, w in enumerate(text_words):
            words.append(GoldenWord(w, round(t, 2), round(t + 0.3, 2), 0.96, idx))
            t += 0.35
        images = ["/mock/long_para_1.png", "/mock/long_para_2.png", "/mock/long_para_3.png"]
        return GoldenFixture(
            fixture_id="GOLDEN_LONG_PARAGRAPH",
            description="Single uninterrupted paragraph with 30 words testing sentence splitting",
            language="vi",
            script=script,
            audio_duration_s=round(t + 1.0, 2),
            words=words,
            images=images,
            metadata={"word_count": 30},
        )

    @staticmethod
    def _build_no_subtitle() -> GoldenFixture:
        return GoldenFixture(
            fixture_id="GOLDEN_NO_SUBTITLE",
            description="Slideshow with background audio but without script or subtitles",
            language="und",
            script="",
            audio_duration_s=15.0,
            words=[],
            images=[f"/mock/slide_{i}.png" for i in range(1, 4)],
            metadata={"has_subtitles": False},
        )

    @staticmethod
    def _build_autosub() -> GoldenFixture:
        # User provides no script; pipeline derives script directly from speech
        words = [
            GoldenWord("Tự", 0.5, 0.8, 0.94, 0),
            GoldenWord("động", 0.9, 1.3, 0.95, 1),
            GoldenWord("tạo", 1.4, 1.7, 0.96, 2),
            GoldenWord("phụ", 1.8, 2.1, 0.93, 3),
            GoldenWord("đề.", 2.2, 2.7, 0.97, 4),
        ]
        return GoldenFixture(
            fixture_id="GOLDEN_AUTOSUB",
            description="Speech-only mode where subtitles are generated directly from ASR words",
            language="vi",
            script="",  # Empty script!
            audio_duration_s=4.0,
            words=words,
            images=["/mock/autosub_1.png"],
            metadata={"mode": "autosub"},
        )

    @staticmethod
    def _build_forced_alignment() -> GoldenFixture:
        # Script differs slightly in case / spelling from acoustic words
        script = "TRÍ TUỆ NHÂN TẠO ngày nay phát triển vượt bậc."
        words = [
            GoldenWord("trí", 0.5, 0.8, 0.93, 0),
            GoldenWord("tuệ", 0.9, 1.2, 0.95, 1),
            GoldenWord("nhân", 1.3, 1.6, 0.94, 2),
            GoldenWord("tạo", 1.7, 2.1, 0.96, 3),
            GoldenWord("ngày", 2.3, 2.6, 0.95, 4),
            GoldenWord("nay", 2.7, 3.0, 0.97, 5),
            GoldenWord("phát", 3.2, 3.5, 0.94, 6),
            GoldenWord("triển", 3.6, 4.0, 0.96, 7),
            GoldenWord("vượt", 4.1, 4.4, 0.95, 8),
            GoldenWord("bậc.", 4.5, 5.0, 0.98, 9),
        ]
        images = ["/mock/ai_1.png", "/mock/ai_2.png"]
        return GoldenFixture(
            fixture_id="GOLDEN_FORCED_ALIGNMENT",
            description="Forced alignment with uppercase script preserving original casing",
            language="vi",
            script=script,
            audio_duration_s=6.0,
            words=words,
            images=images,
            metadata={"casing": "UPPERCASE_PRESERVATION"},
        )
