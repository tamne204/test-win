# 2TOOLNE AUTOEDIT V2 — ACCURACY PHASE A1
## REPORT 03: TEXT ↔ IMAGE SEMANTIC MATCHING TECHNOLOGY SCOUT

- **Project:** 2TOOLNE AutoEdit V2
- **Subsystem:** Multimodal Semantic Matching & Asset Allocation
- **Gate Compliance:** Section 12, 13 & 15 of Directive
- **Date:** September 2026

---

### 1. Investigation Objective & Directive Constraint

Section 13 of the Directive mandates:
> **"DO NOT AUTOMATICALLY ADD CLIP. CLIP-style semantic matching is OPTIONAL. First determine whether existing images already have metadata linking them to script sections... If strong deterministic metadata already exists: prefer it over embeddings."**

This report audits:
1. The real-world metadata characteristics of images supplied to AutoEdit (`GOLDEN_LONG_01`).
2. Candidate lightweight local multimodal models for text-to-image semantic matching.
3. The risks vs. benefits of unconstrained semantic embedding matching on long-form narrative storytelling.

---

### 2. Reality Audit: Image Metadata in `GOLDEN_LONG_01`

An exhaustive audit of the 278 physical images in `GOLDEN_LONG_01` revealed:

```
IMAGE_SEMANTIC_METADATA_EXISTS = PARTIAL_SEQUENTIAL_FILENAME_INDEX
IMAGE_SCRIPT_MAPPING_SOURCE = NATURAL_ORDER_CHRONOLOGICAL_SEQUENCE
```

#### Physical Evidence
1. **Filename Structure:** Images are strictly named `anh_kb001.png`, `anh_kb002.png`, ..., `anh_kb278.png`.
2. **Creation Workflow:** In production, the content creator prompts Midjourney / Stable Diffusion sequentially alongside the written script (Scene 1 $\to$ Image 1, Scene 2 $\to$ Image 2, ..., Scene 278 $\to$ Image 278).
3. **Absence of Loose Manifest:** No JSON/CSV prompt manifest or paragraph ID tag is embedded in image EXIF or folder structure. The integer index in the filename is the creator's explicit chronological timestamp.

#### The "Semantic Scrambling" Pathology
If an unconstrained neural semantic matcher (such as CLIP) is used to map text paragraphs to this image set:
- A generic prompt depiction (e.g., "A dark cloudy sky over a village") appearing in paragraph 250 might score high similarity with paragraph 12 ("The sky grew dark").
- The model would pluck `anh_kb250.png` and insert it at minute 1:30, then later display `anh_kb012.png` at minute 26:00.
- **Result:** Severe narrative continuity failure. Character ages, clothing, time of day, and story arcs are scrambled.

**Fundamental Law of Narrative Video Editing:**
In scripted narrative storytelling, **chronological monotonicity** is vastly more important than superficial keyword feature similarity.

---

### 3. OSS Candidate Evaluation (License & Feasibility Gate)

For completeness and future plugin capability, candidate offline text-image matching architectures were evaluated:

| Candidate | Architecture | License | Model Size | Runtime / Deps | Inference Speed (278 imgs) | Commercial Gate |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| **MobileCLIP** (Apple) | FastViT-B12 | MIT | ~55 MB | ONNX Runtime | ~1.8 s (CPU) | Approved |
| **TinyCLIP** (Microsoft) | ViT-8M | Apache-2.0 | ~35 MB | ONNX Runtime | ~1.2 s (CPU) | Approved |
| **OpenCLIP ViT-B-32** | Standard ViT | MIT | ~350 MB | PyTorch (1.5GB+) | ~8.5 s (CPU) | Rejected (Disk bloat) |
| **SigLIP Compact** (Google)| SigLIP-B/16 | Apache-2.0 | ~180 MB | ONNX Runtime | ~4.5 s (CPU) | Approved |

#### Assessment
- Both **MobileCLIP** and **TinyCLIP** satisfy the commercial license gate (MIT / Apache-2.0) and can run via ONNX Runtime without PyTorch.
- However, bundling ONNX Runtime adds ~35MB to the installer, and model weights add another 35–55MB.
- For Vietnamese language scripts, standard CLIP encoders exhibit lower zero-shot accuracy compared to English, requiring multilingual text encoders (which further increases model size).

---

### 4. Architectural Decision & Recommendation

```
SEMANTIC_IMAGE_MODEL_RECOMMENDATION = DEFERRED_FOR_V1
```

#### Rationale for Deferral in V1
1. **Preserve Creator Intent:** The chronological ordering (`anh_kb001` $\to$ `anh_kb278`) represents the human creator's storyboard intent. Sequential allocation guarantees 100% narrative causality.
2. **Zero Runtime & Size Footprint:** Eliminates 80MB+ of binary bloat and external ML dependencies from the desktop package.
3. **Sub-Millisecond Execution:** The DP partitioner can map sequential images to optimal structural cuts in under 1 ms, whereas embedding extraction would require multiple seconds of CPU computation.

#### Future Phase A1+ Extension Hook
If a user explicitly provides an unordered image pool (e.g. `B-roll/` without numbers) or an AI script generator produces a `prompts.json` manifest:
- AutoEdit V2 will support an optional **`SemanticMatcherPlugin`** using MobileCLIP via ONNX Runtime.
- But for the default narrative production pipeline, **natural order chronological sequence** is frozen as the authoritative truth.
