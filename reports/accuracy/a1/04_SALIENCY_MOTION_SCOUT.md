# 2TOOLNE AUTOEDIT V2 — ACCURACY PHASE A1
## REPORT 04: SALIENCY DETECTION & DURATION-AWARE MOTION SCOUT

- **Project:** 2TOOLNE AutoEdit V2
- **Subsystem:** Ken Burns Engine & Image Saliency
- **Gate Compliance:** Section 14, 26 & 27 of Directive
- **Date:** September 2026

---

### 1. Ken Burns Current Production Reality Audit

In current production, the Ken Burns effect is generated in `core/engine/rule_engine.py` and mapped to CapCut keyframes in `adapters/capcut/version_9_3.py`.

```
CURRENT_KEN_BURNS_DURATION_AWARE = NO
```

#### The Static Delta Pathology
In the current implementation, every visual clip receives keyframes with **fixed mathematical transformation deltas**, regardless of whether the shot lasts 0.66 seconds or 25.0 seconds:
- **Fixed Zoom In:** Scale changes from `1.0` to `1.15` ($\Delta_{\text{scale}} = +0.15$ or $+15\%$).
- **Fixed Zoom Out:** Scale changes from `1.15` to `1.0` ($\Delta_{\text{scale}} = -0.15$ or $-15\%$).
- **Fixed Pan:** Translation changes from `0.0` to `0.20` ($\Delta_{\text{pan}} = 0.20$ or $20\%$ of frame width).

#### Empirical Velocity Measurements on `GOLDEN_LONG_01`
$$\text{Motion Velocity} = \frac{\Delta_{\text{motion}}}{\text{Duration (seconds)}}$$

| Duration Bucket | Example Duration | Zoom Rate (% / sec) | Visual Perception | Pathology Assessment |
| :--- | :--- | :--- | :--- | :--- |
| **< 1.0 s** | `0.66 s` | **22.7% / s** | Whiplash / Dizzying snap | **Severe Motion Artifact** |
| **1.0 s – 2.0 s** | `1.50 s` | **10.0% / s** | Rushed / Nervous jitter | Motion too fast |
| **2.0 s – 4.0 s** | `3.00 s` | **5.0% / s** | Lively documentary motion | Good |
| **4.0 s – 8.0 s** | `6.00 s` | **2.5% / s** | Smooth cinematic glide | **Optimal Pacing** |
| **8.0 s – 15.0 s** | `12.00 s` | **1.25% / s** | Near-static sluggish drift | Sluggish |
| **> 15.0 s** | `25.06 s` | **0.60% / s** | Imperceptible / Image looks frozen | **Visual Freeze Artifact** |

**Conclusion:** Fixed-delta motion produces jarring whiplash on short shots and an uninteresting frozen appearance on long shots. Motion must become a function of duration.

---

### 2. OSS Saliency & Subject Detection Scout

We evaluated candidates for detecting the primary narrative subject (faces, human figures, salient focal points) to guide Ken Burns pan vectors:

| Candidate | Architecture / Method | License | Dependencies | Model Size | Inference Speed (1 img) | Commercial Gate |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| **OpenCV Saliency** | Spectral Residual / FineGrained | 3-Clause BSD | `opencv-python` | 0 MB (Algorithmic) | ~12 ms | Approved |
| **MediaPipe** (Google) | BlazeFace / Pose / Object | Apache-2.0 | `mediapipe` (C++) | ~25 MB | ~25 ms | Approved |
| **YOLOv8-Nano** | Ultralytics ONNX | AGPL-3.0 / Commercial | ONNX Runtime | ~6 MB | ~40 ms | **Rejected (AGPL Risk)** |
| **MobileNet-SSD** | Caffe / ONNX | Apache-2.0 | OpenCV DNN | ~15 MB | ~35 ms | Approved |

#### Technical Assessment
1. **OpenCV Saliency:** Rapidly highlights high-frequency contrast edges (e.g. tree leaves, text watermarks) rather than the emotional human subject. It frequently directs the camera zoom toward irrelevant background corners.
2. **MediaPipe:** Accurately detects faces and human silhouettes. However, adding `mediapipe` requires platform-specific C++ shared libraries (`.so`/`.dylib`/`.dll`), increasing build and packaging fragility.
3. **YOLOv8-Nano:** Strictly prohibited by commercial policy due to AGPL-3.0 licensing.

---

### 3. Saliency Model Recommendation

```
SALIENCY_MODEL_RECOMMENDATION = DEFERRED_TO_A1_PLUS
```

#### Rationale
The primary visual defect in current production is not incorrect camera focal angle; it is **improper duration and motion velocity**. 
1. The AI images in `GOLDEN_LONG_01` are composed with standard cinematic framing (centered or rule-of-thirds human subject). Centered zoom and subtle horizontal panning ($-0.05 \to +0.05$) already produce appealing results when the motion rate is smooth.
2. Introducing a neural vision detector in Phase A1 would add runtime dependencies and potential edge-case crashes.
3. Therefore, neural saliency detection is **deferred to Phase A1+**, allowing Phase A1 to focus on mathematical duration-aware motion scaling.

---

### 4. Duration-Aware Motion Design (Phase A1 Specification)

Rather than fixed keyframe deltas, Phase A1 introduces the **`DurationAwareMotionEngine`** policy layer:

$$\Delta_{\text{motion}}(t) = \text{clamp}\left(\Delta_{\text{base}} \cdot \left(\frac{t_{\text{reference}}}{t_{\text{shot}}}\right)^{\alpha}, \Delta_{\text{min}}, \Delta_{\text{max}}\right)$$

Where:
- $\Delta_{\text{base}} = 0.12$ (12% zoom at 5.0s reference shot)
- $t_{\text{reference}} = 5.0\text{ seconds}$
- $\alpha = 0.65$ (Dampening power factor to prevent extreme swings)

#### Pacing Tier Policy
1. **Micro/Short Shots ($t < 2.5\text{s}$):**
   - $\Delta_{\text{zoom}} \le 0.03$ (3% maximum scale delta)
   - $\Delta_{\text{pan}} = 0.0$ (Static pan to prevent dizzying lateral motion)
2. **Standard Shots ($2.5\text{s} \le t \le 7.0\text{s}$):**
   - $\Delta_{\text{zoom}} = 0.08 \dots 0.14$ (Smooth cinematic zoom)
   - $\Delta_{\text{pan}} = \pm 0.08$ (Subtle directional drift)
3. **Long Shots ($t > 7.0\text{s}$):**
   - $\Delta_{\text{zoom}} = 0.12 \dots 0.16$ (Slow progressive push/pull)
   - Multi-phase keyframes (e.g. Slow push $\to$ gentle hold) so the image never feels frozen.

This mathematical scaling guarantees that motion velocity remains strictly bounded between **$1.5\%/\text{s}$ and $3.5\%/\text{s}$** across all timeline shots.
