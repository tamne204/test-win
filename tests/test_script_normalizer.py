"""
tests/test_script_normalizer.py
Unit tests for script_normalizer.py.
Verifies exact verbatim token preservation, char span indexing, and language detection.
"""
import pytest
import sys
import os

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "../apps/capcut-v2")))

from core.subtitles.script_normalizer import (
    tokenize_script,
    normalize_for_matching,
    detect_language,
)


def test_vietnamese_script_tokenization_and_offsets():
    """Verify that every token exactly maps back to the original script substring."""
    script = "Tôi đang phát triển 2TOOLNE AutoEdit. Bạn có thích tính năng này không?"
    tokens = tokenize_script(script, language="vi")

    assert len(tokens) > 0

    # Ensure each token's raw_text matches the exact slice from original script
    for tok in tokens:
        slice_text = script[tok.char_start:tok.char_end]
        assert slice_text == tok.raw_text, f"Offset mismatch: expected '{tok.raw_text}', got '{slice_text}'"

    # Verify brand name preservation
    raw_words = [t.raw_text for t in tokens]
    assert "2TOOLNE" in raw_words
    assert "AutoEdit" in raw_words

    # Verify sentence break flag on the token preceding period or question mark
    autoedit_tok = next(t for t in tokens if t.raw_text == "AutoEdit")
    assert autoedit_tok.trailing_punctuation == "."
    assert autoedit_tok.is_sentence_break is True

    last_tok = tokens[-1]
    assert last_tok.raw_text == "không"
    assert last_tok.trailing_punctuation == "?"
    assert last_tok.is_sentence_break is True


def test_english_contractions_and_punctuation():
    """Verify contractions and punctuation handling in English."""
    script = "Hello world! We don't stop here, do we? Let's check 2TOOLNE's speed."
    tokens = tokenize_script(script, language="en")

    raw_words = [t.raw_text for t in tokens]
    assert "Hello" in raw_words
    assert "don't" in raw_words or "dont" in raw_words
    assert "Let's" in raw_words or "Lets" in raw_words

    for tok in tokens:
        assert script[tok.char_start:tok.char_end] == tok.raw_text


def test_language_detection():
    """Verify auto-detection of Vietnamese, Korean, Japanese, and English."""
    assert detect_language("Xin chào tất cả các bạn, đây là video hướng dẫn.") == "vi"
    assert detect_language("안녕하세요 만나서 반갑습니다. 오늘 하루도 좋은 하루 되세요.") == "ko"
    assert detect_language("こんにちは、世界。CapCutの自動編集ツールです。") == "ja"
    assert detect_language("Welcome to the automated CapCut editing engine.") == "en"


def test_cjk_japanese_tokenization():
    """Verify that Japanese text without spaces is properly tokenized."""
    ja_script = "こんにちは。これはテストです！"
    tokens = tokenize_script(ja_script, language="ja")

    assert len(tokens) >= 2
    # Ensure all token spans are valid
    for tok in tokens:
        assert ja_script[tok.char_start:tok.char_end] == tok.raw_text


def test_matching_normalization_does_not_alter_token_raw_text():
    """Verify that matching normalization removes diacritics/case without altering raw_text."""
    script = "2TOOLNE AutoEdit: Phiên Bản Mới Nhất!"
    tokens = tokenize_script(script, language="vi")

    brand_tok = next(t for t in tokens if t.raw_text == "2TOOLNE")
    assert brand_tok.raw_text == "2TOOLNE"
    assert brand_tok.normalized_text == "2toolne"

    accent_tok = next(t for t in tokens if t.raw_text == "Nhất")
    assert accent_tok.raw_text == "Nhất"
    assert accent_tok.normalized_text == "nhat" or "nhất" in accent_tok.normalized_text
