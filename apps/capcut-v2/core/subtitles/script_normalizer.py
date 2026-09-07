"""
apps/capcut-v2/core/subtitles/script_normalizer.py
Extracts tokens from user script while preserving exact verbatim text and char offsets.
Normalization is used STRICTLY for phonetic/acoustic alignment matching.
The final subtitle text ALWAYS reconstructs the user's original script verbatim.
"""
from __future__ import annotations

import re
import unicodedata
from typing import List, Tuple, Optional

from .models import ScriptToken

# Vietnamese characters for detection
VIETNAMESE_PATTERN = re.compile(
    r"[àáảãạăằắẳẵặâầấẩẫậèéẻẽẹêềếểễệìíỉĩịòóỏõọôồốổỗộơờớởỡợùúủũụưừứửữựỳýỷỹỵđ"
    r"ÀÁẢÃẠĂẰẮẲẴẶÂẦẤẨẪẬÈÉẺẼẸÊỀẾỂỄỆÌÍỈĨỊÒÓỎÕỌÔỒỐỔỖỘƠỜỚỞỠỢÙÚỦŨỤƯỪỨỬỮỰỲÝỶỸỴĐ]",
    re.IGNORECASE
)

# Korean Hangul
KOREAN_PATTERN = re.compile(r"[\uac00-\ud7a3]")

# Japanese Hiragana, Katakana, and Kanji
JAPANESE_PATTERN = re.compile(r"[\u3040-\u309f\u30a0-\u30ff\u4e00-\u9faf]")

# Sentence ending punctuation
SENTENCE_ENDINGS = {".", "?", "!", "…", "。", "！", "？"}
CLAUSE_ENDINGS = {",", ";", ":", "—", "-", "、", "，", "："}


def detect_language(text: str) -> str:
    """
    Detect dominant language from script text.
    Returns: 'vi', 'ko', 'ja', or 'en'.
    """
    if not text or not text.strip():
        return "en"

    # 1. Check Korean
    ko_matches = len(KOREAN_PATTERN.findall(text))
    if ko_matches >= 3:
        return "ko"

    # 2. Check Japanese (Hiragana/Katakana specifically distinguishes from pure Chinese)
    kana_matches = len(re.findall(r"[\u3040-\u309f\u30a0-\u30ff]", text))
    if kana_matches >= 2:
        return "ja"

    # 3. Check Vietnamese
    vi_matches = len(VIETNAMESE_PATTERN.findall(text))
    if vi_matches >= 2:
        return "vi"

    # 4. Fallback default
    return "en"


def normalize_for_matching(text: str, language: str = "en") -> str:
    """
    Produce a normalized string strictly used for matching against ASR tokens.
    Never alters user-facing script text.
    """
    if not text:
        return ""

    # Canonical NFC Unicode normalization
    text = unicodedata.normalize("NFC", text)

    # Lowercase
    text = text.lower()

    # Strip quotes, brackets, and common punctuation
    text = re.sub(r"[\"\'\`\“\”\‘\’\(\)\[\]\{\}\<\>\.,!?:;…\-_/\\|~*^%$#@+=]", "", text)

    # Collapse extra whitespace
    text = re.sub(r"\s+", " ", text).strip()

    return text


def tokenize_script(script_text: str, language: str = "AUTO") -> List[ScriptToken]:
    """
    Tokenize user script into ScriptToken objects with exact character span mapping.
    Preserves exact verbatim wording, casing, punctuation, and whitespace.
    """
    if not script_text or not script_text.strip():
        return []

    # Detect language if requested
    detected_lang = detect_language(script_text) if language.upper() == "AUTO" else language.lower()

    tokens: List[ScriptToken] = []

    # Japanese without spaces: character/word tokenization
    if detected_lang == "ja":
        tokens = _tokenize_cjk_script(script_text, detected_lang)
    else:
        tokens = _tokenize_spaced_script(script_text, detected_lang)

    return tokens


def _tokenize_spaced_script(script_text: str, language: str) -> List[ScriptToken]:
    """
    Tokenize text that uses space-delimited words (Vietnamese, English, Korean, etc.).
    Preserves internal contractions (don't, it's, let's) while stripping outer punctuation.
    """
    tokens: List[ScriptToken] = []
    
    # Matches: (leading whitespace)(word characters possibly containing internal apostrophe/hyphen)(trailing punctuation)
    # Punctuation excluded from word body except internal apostrophe/hyphen surrounded by word chars
    pattern = re.compile(
        r"(\s*)((?:[^\s\.,!?:;…\"\'\`\“\”\‘\’\(\)\[\]\{\}\<\>]|(?<=\w)[\'\’\-](?=\w))+)([\.,!?:;…\"\'\`\“\”\‘\’\(\)\[\]\{\}\<\>]*)",
        re.UNICODE
    )

    pos = 0
    token_idx = 0
    text_len = len(script_text)

    for m in pattern.finditer(script_text):
        leading_ws = m.group(1)
        word_raw = m.group(2)
        trailing_punct = m.group(3)

        start_char = m.start(2)
        end_char = m.end(2)

        # Determine sentence or clause break
        is_sentence = any(p in SENTENCE_ENDINGS for p in trailing_punct)
        is_clause = any(p in CLAUSE_ENDINGS for p in trailing_punct) and not is_sentence

        # Check if immediately followed by newline
        rest_idx = m.end()
        if rest_idx < text_len and "\n" in script_text[end_char:rest_idx + 1]:
            is_sentence = True

        norm_word = normalize_for_matching(word_raw, language)

        if not norm_word:
            # Skip pure symbol tokens that produce no matchable word
            continue

        tokens.append(
            ScriptToken(
                token_index=token_idx,
                raw_text=word_raw,
                normalized_text=norm_word,
                char_start=start_char,
                char_end=end_char,
                leading_whitespace=leading_ws,
                trailing_punctuation=trailing_punct,
                is_sentence_break=is_sentence,
                is_clause_break=is_clause,
            )
        )
        token_idx += 1

    return tokens


def _tokenize_cjk_script(script_text: str, language: str) -> List[ScriptToken]:
    """
    Tokenize CJK unspaced text (such as Japanese) into manageable character/punctuation tokens.
    """
    tokens: List[ScriptToken] = []
    token_idx = 0
    curr_word = ""
    word_start = -1
    leading_ws = ""

    for i, char in enumerate(script_text):
        if char.isspace():
            if curr_word:
                norm = normalize_for_matching(curr_word, language)
                if norm:
                    tokens.append(
                        ScriptToken(
                            token_index=token_idx,
                            raw_text=curr_word,
                            normalized_text=norm,
                            char_start=word_start,
                            char_end=i,
                            leading_whitespace=leading_ws,
                            trailing_punctuation="",
                            is_sentence_break=("\n" in char),
                            is_clause_break=False,
                        )
                    )
                    token_idx += 1
                curr_word = ""
                word_start = -1
            leading_ws += char
            continue

        if char in SENTENCE_ENDINGS or char in CLAUSE_ENDINGS:
            if curr_word:
                norm = normalize_for_matching(curr_word, language)
                if norm:
                    tokens.append(
                        ScriptToken(
                            token_index=token_idx,
                            raw_text=curr_word,
                            normalized_text=norm,
                            char_start=word_start,
                            char_end=i,
                            leading_whitespace=leading_ws,
                            trailing_punctuation=char,
                            is_sentence_break=(char in SENTENCE_ENDINGS),
                            is_clause_break=(char in CLAUSE_ENDINGS),
                        )
                    )
                    token_idx += 1
                curr_word = ""
                word_start = -1
                leading_ws = ""
            continue

        if not curr_word:
            word_start = i
        curr_word += char

        # For CJK, group into chunks of 2-4 characters or kanji compounds if no spaces
        if len(curr_word) >= 3:
            norm = normalize_for_matching(curr_word, language)
            if norm:
                tokens.append(
                    ScriptToken(
                        token_index=token_idx,
                        raw_text=curr_word,
                        normalized_text=norm,
                        char_start=word_start,
                        char_end=i + 1,
                        leading_whitespace=leading_ws,
                        trailing_punctuation="",
                        is_sentence_break=False,
                        is_clause_break=False,
                    )
                )
                token_idx += 1
            curr_word = ""
            word_start = -1
            leading_ws = ""

    if curr_word:
        norm = normalize_for_matching(curr_word, language)
        if norm:
            tokens.append(
                ScriptToken(
                    token_index=token_idx,
                    raw_text=curr_word,
                    normalized_text=norm,
                    char_start=word_start,
                    char_end=len(script_text),
                    leading_whitespace=leading_ws,
                    trailing_punctuation="",
                    is_sentence_break=True,
                    is_clause_break=False,
                )
            )

    return tokens
