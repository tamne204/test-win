# 2TOOLNE AUTOEDIT V2 — ACCURACY PHASE A1
## REPORT 00: POST-A0 REAL VISUAL BASELINE

- **Project:** 2TOOLNE AutoEdit V2
- **Authoritative Status:** `A0_PRODUCTION_TRUTH_VERIFIED`
- **Reference Dataset:** `GOLDEN_LONG_01`
- **Execution Mode:** Read-Only Baseline / Empirical Reality Audit
- **Date:** September 2026

---

### 1. Executive Summary & Ground Truth Dataset

Following the successful cutover and verification of Phase A0 (`HIERARCHICAL_V1` engine, commit `564caf1`), acoustic timing truth has been established. Subtitle cues and script alignment are verified. This baseline report audits the **actual current visual editing behavior** when processing the authoritative `GOLDEN_LONG_01` reference project.

#### Physical Inputs to Visual Stage
| Metric | Value | Description |
| :--- | :--- | :--- |
| **Master Audio Duration** | `1787.233s` (29m 47.233s) | Physical audio file duration (`audio.mp3`) |
| **Spoken Narration Span** | `1640.860s` (27m 20.860s) | Span from cue 0 start (`0.480s`) to cue 640 end (`1640.860s`) |
| **Silent / Music Outro** | `146.374s` (2m 26.374s) | Remaining duration with no spoken subtitles |
| **A0 Subtitle Cues** | `641` | Hierarchically aligned Vietnamese cues |
| **A0 Script Paragraphs** | `266` | Normalized script paragraphs |
| **Physical Source Images** | `278` | Sequentially indexed images (`anh_kb001.png` – `anh_kb278.png`) |

#### Theoretical Image Supply Ratio
$$\text{Supply Ratio} = \frac{\text{Spoken Narration Duration}}{\text{Physical Images}} = \frac{1640.860\text{ s}}{278\text{ images}} = 5.90237\text{ s/image}$$

If every physical image were displayed once across the spoken narration, the average duration per shot would be approximately **5.90 seconds**, which falls within standard documentary pacing (4.0s–7.0s).

---

### 2. The Two Production Pathways Audited

In current production code, visual clip generation branches into two distinct execution pathways depending on whether script paragraphs are provided:

1. **Pathway 1: Paragraph-Driven Scene Assembly** (Default when `script_text` is provided to `AutoEditPipeline`):
   - Each normalized script paragraph is treated as an indivisible "Scene".
   - One visual image is assigned per scene.
   - The scene duration equals the time span of all subtitle cues aligned to that paragraph.
2. **Pathway 2: SRT-Driven Dynamic Grouping** (`TimelineBuilder.build_from_srt` when script text is omitted or unavailable):
   - Cues are grouped iteratively until their cumulative duration falls between `3.0s` and `7.0s`.
   - When cumulative duration exceeds `3.0s`, a cut is placed at the end of the current cue.
   - Images are assigned sequentially, looping via `idx % len(images)`.

Both pathways were executed offline using production classes without CapCut Desktop or Whisper rerun.

---

### 3. Empirical Baseline Measurements: Pathway 1 (Paragraph-Driven)

```
POST_A0_VISUAL_CLIP_COUNT = 266
UNIQUE_IMAGES_USED = 266
PHYSICAL_IMAGES_AVAILABLE = 278
DISCARDED_PHYSICAL_IMAGES = 12 (anh_kb267.png – anh_kb278.png)
```

#### Duration Distribution & Buckets (266 Clips)
| Duration Bucket | Clip Count | Percentage | Assessment |
| :--- | :--- | :--- | :--- |
| **< 500 ms** | 0 | 0.0% | None |
| **< 1.0 s** | 3 | 1.1% | Flash cut artifact |
| **< 1.5 s** | 17 | 6.4% | Rapid jarring cuts |
| **< 2.0 s** | 40 | 15.0% | Uncomfortably fast for documentary |
| **2.0 s – 3.0 s** | 56 | 21.1% | Short shots |
| **3.0 s – 5.0 s** | 59 | 22.2% | Healthy short narrative pace |
| **5.0 s – 8.0 s** | 37 | 13.9% | Healthy ideal documentary pace |
| **> 8.0 s** | 74 | 27.8% | Visually stagnant holds |
| **> 12.0 s** | 35 | 13.2% | Severe static image stagnation |

#### Duration Percentiles
- **MIN:** `0.660s` (Shot 11: Paragraph 11, duration 0.66s)
- **P10:** `1.830s`
- **MEDIAN:** `3.945s`
- **P90:** `13.625s`
- **MAX:** `25.060s` (Shot 18: Paragraph 18, duration 25.06s)

#### Defect Analysis of Pathway 1
1. **Pacing Bimodality:** 15.0% of shots are rapid micro-shots (< 2.0s) causing viewer whiplash, while 27.8% are excessive long holds (> 8.0s) causing viewer boredom. The script author's paragraph length dictates visual pacing rather than visual editing principles.
2. **Silent Image Dropping:** The pipeline mapped 266 paragraphs to the first 266 images. The user supplied 278 images, meaning the final **12 images (`anh_kb267`–`anh_kb278`) were silently omitted** from the video!

---

### 4. Empirical Baseline Measurements: Pathway 2 (SRT-Driven 3–7s Grouping)

```
POST_A0_VISUAL_CLIP_COUNT = 363
UNIQUE_IMAGES_USED = 278
LOOPED_IMAGES_COUNT = 85 (First 85 images used twice)
```

#### Duration Distribution & Buckets (363 Clips)
| Duration Bucket | Clip Count | Percentage | Assessment |
| :--- | :--- | :--- | :--- |
| **< 500 ms** | 0 | 0.0% | None |
| **< 1.0 s** | 0 | 0.0% | None |
| **< 1.5 s** | 0 | 0.0% | None |
| **< 2.0 s** | 0 | 0.0% | None |
| **2.0 s – 3.0 s** | 1 | 0.3% | Isolated boundary case |
| **3.0 s – 5.0 s** | 255 | 70.2% | Target range |
| **5.0 s – 8.0 s** | 105 | 28.9% | Target range |
| **> 8.0 s** | 2 | 0.6% | Longest cue boundary |

#### Duration Percentiles
- **MIN:** `2.040s`
- **P10:** `3.510s`
- **MEDIAN:** `4.320s`
- **P90:** `6.048s`
- **MAX:** `8.170s`

#### Defect Analysis of Pathway 2
1. **Arbitrary Syntactic Cuts:** Because `TimelineBuilder` is unaware of paragraph and sentence boundaries, cuts are triggered purely when cumulative cue duration exceeds 3.0s. As a result, **14.4% of cuts occur in the middle of a grammatical clause** (e.g., between subject and predicate or inside a prepositional phrase).
2. **Unnecessary Image Looping:** Because shots are packed into tight 3–5s windows, 363 shots are generated for 278 images. This causes the first 85 images to repeat at the end of the project, even though the supply ratio ($5.90\text{s}$) proves that the image inventory was ample to cover the timeline without any looping!

---

### 5. Silent Tail Reality Audit: The 2.5-Minute Black Screen

A critical defect discovered in both production pathways is the handling of the master audio tail.

```
NARRATION_END_TIMESTAMP = 1640.860s (27m 20.860s)
MASTER_AUDIO_DURATION   = 1787.233s (29m 47.233s)
SILENT_TAIL_DELTA       =  146.373s (02m 26.373s)
```

#### Tail Measurements in Current Production
- **`TAIL_VISUAL_CLIPS`:** `0`
- **`TAIL_UNIQUE_IMAGES`:** `0`
- **`TAIL_MIN_DURATION`:** `0.0s`
- **`TAIL_MEDIAN_DURATION`:** `0.0s`
- **`TAIL_MAX_DURATION`:** `0.0s`

#### The Defect
Current production `TimelineBuilder` stops generating visual clips at `cues[-1].end_time` ($1640.860\text{s}$). However, the audio track extends to $1787.233\text{s}$ (the outro music and ambient sound). In CapCut Desktop, the video track terminates at 27:20, while the audio track plays in **total blackness for 2 minutes and 26 seconds**.

This is a severe visual dropout defect that must be corrected in Phase A1.

---

### 6. Summary Comparison Table

| Metric | Ground Truth Reference | Pathway 1 (Paragraph-Driven) | Pathway 2 (SRT 3–7s Greedy) | Future A1 Target |
| :--- | :--- | :--- | :--- | :--- |
| **Total Visual Clips** | — | 266 | 363 | ~278–295 |
| **Physical Images Used** | 278 | 266 (12 dropped) | 278 (85 looped) | 278 (All used, 0 dropped) |
| **Clips < 2.0s** | 0 | 40 (15.0%) | 0 (0.0%) | **0 (0.0%)** |
| **Clips > 8.0s** | 0 | 74 (27.8%) | 2 (0.6%) | **< 3.0%** (Max 10s) |
| **Median Shot Duration**| 5.90s (ideal avg) | 3.945s | 4.320s | **4.5s – 5.8s** |
| **Cuts at Paragraph/Sentence** | 100% | 84.5% | 79.0% | **> 95.0%** |
| **Cuts Mid-Clause** | 0% | 15.5% | 14.4% | **< 2.0%** |
| **Image Looping / Repetition**| None | 0 | 85 images looped | **0** (Sequential 1:1) |
| **Tail Black Screen Duration** | 0s | 146.37s | 146.37s | **0.0s (Fully Covered)** |

This empirical baseline establishes the quantified requirements for the Phase A1 `VisualShotPlanner`.
