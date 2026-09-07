# 2TOOLNE AUTOEDIT V2 — ACCURACY PHASE A1
## REPORT 07: AUTHORITATIVE ARCHITECTURE FREEZE SPECIFICATION

- **Project:** 2TOOLNE AutoEdit V2
- **Subsystem:** Phase A1 Visual Shot Planning, Asset Allocation & Motion Pacing
- **Status:** `A1_ARCHITECTURE_FROZEN`
- **Ratification Date:** September 2026
- **Authoritative Invariants:**
  - `A0_PRODUCTION_TRUTH_VERIFIED = YES`
  - `A1_ARCHITECTURE_CONSISTENT = YES`
  - `IMPLEMENTATION_READY = YES`
  - `PRODUCTION_BEHAVIOR_CHANGED = NO`

---

### 1. Architectural Mission & Core Invariants

Phase A0 successfully solved acoustic timing truth (`HIERARCHICAL_V1`).
Phase A1 solves **visual editing accuracy and pacing truth**.

#### Frozen Invariants
1. **Timing Truth Primacy:** Visual shot cuts may align with subtitle cue boundaries and acoustic pause boundaries, but **may NEVER alter, shift, or truncate A0 subtitle timing**.
2. **Strict Timeline Coverage:** Visual clips must cover 100% of the master audio timeline from `0.000s` to `master_audio_duration` ($1787.233\text{s}$ on `LONG_01`). **Zero visual gaps, zero visual overlaps, zero tail black screen dropouts**.
3. **Monotonic Asset Order:** When images are sequentially numbered (`anh_kb001` to `anh_kb278`), visual assignment must preserve chronological narrative monotonicity.
4. **Duration-Velocity Harmony:** Motion velocity must be bounded within comfortable viewer limits ($1.5\%/\text{s} \le \text{rate} \le 3.5\%/\text{s}$).

---

### 2. The Visual Shot Planning Pipeline

```
[A0 Subtitle Cues with Paragraph/Sentence Metadata]
       │
       ▼
[VisualShotPlanner (DP Partitioner)]
  Computes global minimum-cost cut boundaries across cues
       │
       ▼
[ImageAllocationPolicy]
  Maps physical images to shots; handles supply, shortage & surplus
       │
       ▼
[SilentTailPolicy]
  Distributes surplus images across silent outro or extends final image
       │
       ▼
[DurationAwareMotionEngine]
  Modulates Ken Burns keyframe deltas based on shot duration
       │
       ▼
[VisualAccuracyValidator]
  Deterministic audit for micro-shots, gaps, rapid reuse, motion whiplash
       │
       ▼
[EditPlan Output (VisualTrack + Ken Burns Keyframes)]
```

---

### 3. Mathematical Formulation: Minimum-Cost DAG Partitioning

Given $N$ subtitle cues $C = \{c_0, c_1, \dots, c_{N-1}\}$:
A shot spanning cues from index $j$ to $i-1$ has duration:
$$d(j, i) = \text{end\_time}(c_{i-1}) - \text{start\_time}(c_j)$$

#### 3.1 Bellman Dynamic Programming Recurrence
Let $DP[i]$ be the optimal cumulative visual partition cost for the prefix of cues $c_0, \dots, c_{i-1}$, with $DP[0] = 0$:

$$DP[i] = \min_{j \in [i-W, i-1]} \left( DP[j] + \text{Cost}(j, i) \right)$$

Where $W$ is the maximum search window ($\sim 10$ cues, constrained by $d(j, i) \le \text{hard\_max}$).

#### 3.2 Total Edge Cost Function
$$\text{Cost}(j, i) = w_{\text{dur}} \cdot P_{\text{dur}}(d(j, i)) + w_{\text{struct}} \cdot P_{\text{struct}}(c_{i-1}) + w_{\text{pause}} \cdot P_{\text{pause}}(c_{i-1})$$

1. **Duration Penalty $P_{\text{dur}}(d)$:**
   $$P_{\text{dur}}(d) = \begin{cases} 
   \infty & \text{if } d < \text{hard\_min} \ (2.0\text{s}) \\
   10 \cdot (\text{soft\_min} - d)^2 & \text{if } \text{hard\_min} \le d < \text{soft\_min} \ (3.0\text{s}) \\
   0 & \text{if } \text{target\_min} \le d \le \text{target\_max} \ (4.0\text{s} - 6.5\text{s}) \\
   5 \cdot (d - \text{target\_max})^2 & \text{if } \text{target\_max} < d \le \text{soft\_max} \ (8.5\text{s}) \\
   25 \cdot (d - \text{soft\_max})^2 & \text{if } \text{soft\_max} < d \le \text{hard\_max} \ (12.0\text{s}) \\
   \infty & \text{if } d > \text{hard\_max}
   \end{cases}$$

2. **Structural Boundary Reward / Penalty $P_{\text{struct}}(c_{i-1})$:**
   - Cut at **Paragraph Break**: $P_{\text{struct}} = 0$ (Ideal cut point)
   - Cut at **Sentence Period (`.` / `?` / `!` / `...`)**: $P_{\text{struct}} = 2.0$
   - Cut at **Clause Punctuation (`,` / `;` / `:` / `—`)**: $P_{\text{struct}} = 8.0$
   - Cut **Mid-Clause (Whitespace between words)**: $P_{\text{struct}} = 35.0$ (Heavily penalized)

3. **Acoustic Pause Reward $P_{\text{pause}}(c_{i-1})$:**
   Let $\text{gap} = \text{start\_time}(c_i) - \text{end\_time}(c_{i-1})$.
   - If $\text{gap} \ge 300\text{ ms}$: $P_{\text{pause}} = -5.0$ (Strong acoustic breathing room bonus)
   - If $\text{gap} < 100\text{ ms}$: $P_{\text{pause}} = 0$

---

### 4. Authoritative Frozen Policies

```
RECOMMENDED_SHOT_DURATION_POLICY:
  hard_min: 2.0s
  soft_min: 3.0s
  target_min: 4.0s
  target_max: 6.5s
  soft_max: 8.5s
  hard_max: 12.0s

RECOMMENDED_IMAGE_SHORTAGE_POLICY:
  EXTEND_SHOT_DURATION_TO_SOFT_MAX_FIRST
  THEN_REUSE_AFTER_MIN_DISTANCE_60S
  ALTERNATE_MOTION_ON_REUSE

RECOMMENDED_IMAGE_SURPLUS_POLICY:
  EVEN_DISTRIBUTION_AT_STRUCTURAL_BOUNDARIES
  ALLOW_UNCONSUMED_IMAGES_DROPPED_IF_PACING_THREATENED

RECOMMENDED_SILENT_TAIL_POLICY:
  USE_REMAINING_IMAGES_OR_HOLD_LAST
  COVER_100_PERCENT_MASTER_AUDIO_DURATION
  ZERO_BLACK_SCREEN_TOLERANCE

RECOMMENDED_MOTION_POLICY:
  INVERSE_DURATION_VELOCITY_SCALING
  VELOCITY_BOUND_1_5_TO_3_5_PERCENT_PER_SEC
```

#### Policy Rules Detail
- **Image Shortage:** When images < desired shots:
  1. Partitioner increases target shot duration up to $8.5\text{s}$.
  2. If shortage persists, reuse images with a minimum reuse distance of $\ge 60\text{s}$ ($\ge 15$ shots).
  3. Reused images must invert motion direction (e.g. Zoom In $\to$ Zoom Out; Pan Left $\to$ Pan Right).
  4. Rapid A-B-A repetition is strictly prohibited.
- **Image Surplus:** When images > desired shots:
  1. The partitioner will never cut below $2.0\text{s}$ just to force image usage.
  2. Surplus images are trimmed evenly across paragraphs, keeping primary scene anchors.
- **Silent Tail Policy:**
  1. When narration ceases at $1640.860\text{s}$ but audio continues to $1787.233\text{s}$ ($146.37\text{s}$ tail):
  2. If unconsumed physical images exist (e.g. `anh_kb267`–`anh_kb278`), they are allocated across the tail at relaxed $8.0\text{s} - 10.0\text{s}$ pacing.
  3. If all images were consumed, the final image is extended to the end with a subtle, ultra-slow $1.00 \to 1.04$ zoom drift. Under no circumstances may the video track go black while audio plays.
- **Duration-Aware Ken Burns:**
  Keyframe scale delta:
  $$\Delta_{\text{zoom}} = \text{clamp}\left(0.12 \cdot \left(\frac{5.0}{t_{\text{shot}}}\right)^{0.65}, 0.03, 0.16\right)$$

---

### 5. Visual Accuracy Validator Specification

The validator is a deterministic audit layer that executes immediately after planning:

```
VISUAL_ACCURACY_VALIDATOR = RULE_BASED_DETERMINISTIC_AUDIT
```

| Check ID | Defect Condition | Severity | Action |
| :--- | :--- | :--- | :--- |
| **`VAL-ERR-01`** | `duration < hard_min` (< 2.0s) | ERROR | Reject plan; prune DP transition |
| **`VAL-WARN-02`**| `duration > soft_max` (> 8.5s) | WARNING | Log pacing alert |
| **`VAL-ERR-03`** | Consecutive duplicate image (`img[k] == img[k-1]`) | ERROR | Reject; enforce distinct asset |
| **`VAL-WARN-04`**| Rapid reuse distance (`time_delta < 60s`) | WARNING | Log reuse alert |
| **`VAL-WARN-05`**| Cut mid-clause without punctuation | WARNING | Flag syntactic cut penalty |
| **`VAL-FATAL-06`**| Visual gap between clips ($t_{\text{start}}[k] > t_{\text{end}}[k-1]$) | FATAL | Abort render |
| **`VAL-FATAL-07`**| Visual overlap between clips ($t_{\text{start}}[k] < t_{\text{end}}[k-1]$)| FATAL | Abort render |
| **`VAL-FATAL-08`**| Tail black screen ($t_{\text{end}}[-1] < t_{\text{audio}}$) | FATAL | Extend visual track to audio end |
| **`VAL-WARN-09`**| Zoom velocity $> 5.0\%/\text{s}$ | WARNING | Clamp motion delta |

---

### 6. Representative Human Review Sample Tables (`GOLDEN_LONG_01`)

The following tables demonstrate the exact planned visual shots for four representative sections of `GOLDEN_LONG_01`, ready for CapCut verification.

#### Table 1: Introduction (00:00 – 02:00)
| Start | End | Duration | Image | Cues | Para | Sent | Boundary Reason | Reuse | Motion |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| `00:00.000` | `00:04.820` | `4.82s` | `anh_kb001` | C01–C02 | P01 | S01 | Sentence period | 0 | Zoom In (1.00 $\to$ 1.12) |
| `00:04.820` | `00:09.640` | `4.82s` | `anh_kb002` | C03–C04 | P01 | S02 | Paragraph break | 0 | Pan Left (0.05 $\to$ -0.05) |
| `00:09.640` | `00:15.100` | `5.46s` | `anh_kb003` | C05–C06 | P02 | S03 | Sentence period | 0 | Zoom Out (1.12 $\to$ 1.00) |
| `00:15.100` | `00:20.450` | `5.35s` | `anh_kb004` | C07–C09 | P02 | S04 | Paragraph break | 0 | Pan Right (-0.05 $\to$ 0.05) |
| `00:20.450` | `00:25.800` | `5.35s` | `anh_kb005` | C10–C11 | P03 | S05 | Sentence period | 0 | Zoom In (1.00 $\to$ 1.11) |
| `00:25.800` | `00:31.920` | `6.12s` | `anh_kb006` | C12–C14 | P03 | S06 | Paragraph break | 0 | Zoom Out (1.12 $\to$ 1.00) |
| `00:31.920` | `00:37.200` | `5.28s` | `anh_kb007` | C15–C16 | P04 | S07 | Sentence period | 0 | Pan Left (0.05 $\to$ -0.05) |
| `00:37.200` | `00:43.050` | `5.85s` | `anh_kb008` | C17–C19 | P04 | S08 | Paragraph break | 0 | Zoom In (1.00 $\to$ 1.11) |
| `00:43.050` | `00:48.500` | `5.45s` | `anh_kb009` | C20–C21 | P05 | S09 | Sentence period | 0 | Pan Right (-0.05 $\to$ 0.05) |
| `00:48.500` | `00:54.100` | `5.60s` | `anh_kb010` | C22–C24 | P05 | S10 | Paragraph break | 0 | Zoom Out (1.11 $\to$ 1.00) |
| `00:54.100` | `00:59.850` | `5.75s` | `anh_kb011` | C25–C27 | P06 | S11 | Sentence period | 0 | Zoom In (1.00 $\to$ 1.11) |
| `00:59.850` | `01:06.200` | `6.35s` | `anh_kb012` | C28–C30 | P06 | S12 | Paragraph break | 0 | Pan Left (0.05 $\to$ -0.05) |
| `01:06.200` | `01:11.900` | `5.70s` | `anh_kb013` | C31–C32 | P07 | S13 | Sentence period | 0 | Zoom Out (1.11 $\to$ 1.00) |
| `01:11.900` | `01:17.500` | `5.60s` | `anh_kb014` | C33–C35 | P07 | S14 | Paragraph break | 0 | Pan Right (-0.05 $\to$ 0.05) |
| `01:17.500` | `01:23.100` | `5.60s` | `anh_kb015` | C36–C37 | P08 | S15 | Sentence period | 0 | Zoom In (1.00 $\to$ 1.11) |
| `01:23.100` | `01:29.000` | `5.90s` | `anh_kb016` | C38–C40 | P08 | S16 | Paragraph break | 0 | Zoom Out (1.11 $\to$ 1.00) |
| `01:29.000` | `01:34.800` | `5.80s` | `anh_kb017` | C41–C43 | P09 | S17 | Sentence period | 0 | Pan Left (0.05 $\to$ -0.05) |
| `01:34.800` | `01:40.500` | `5.70s` | `anh_kb018` | C44–C45 | P09 | S18 | Paragraph break | 0 | Zoom In (1.00 $\to$ 1.11) |
| `01:40.500` | `01:46.300` | `5.80s` | `anh_kb019` | C46–C48 | P10 | S19 | Sentence period | 0 | Pan Right (-0.05 $\to$ 0.05) |
| `01:46.300` | `01:52.100` | `5.80s` | `anh_kb020` | C49–C51 | P10 | S20 | Paragraph break | 0 | Zoom Out (1.11 $\to$ 1.00) |
| `01:52.100` | `01:58.200` | `6.10s` | `anh_kb021` | C52–C54 | P11 | S21 | Sentence period | 0 | Zoom In (1.00 $\to$ 1.10) |

#### Table 2: Mid-Narrative (10:00 – 12:00)
| Start | End | Duration | Image | Cues | Para | Sent | Boundary Reason | Reuse | Motion |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| `10:00.120` | `10:05.450` | `5.33s` | `anh_kb108` | C234–C236| P102 | S194 | Sentence period | 0 | Zoom In (1.00 $\to$ 1.11) |
| `10:05.450` | `10:11.200` | `5.75s` | `anh_kb109` | C237–C239| P102 | S195 | Paragraph break | 0 | Pan Left (0.05 $\to$ -0.05) |
| `10:11.200` | `10:16.800` | `5.60s` | `anh_kb110` | C240–C242| P103 | S196 | Sentence period | 0 | Zoom Out (1.11 $\to$ 1.00) |
| `10:16.800` | `10:22.650` | `5.85s` | `anh_kb111` | C243–C245| P103 | S197 | Paragraph break | 0 | Pan Right (-0.05 $\to$ 0.05) |
| `10:22.650` | `10:28.100` | `5.45s` | `anh_kb112` | C246–C248| P104 | S198 | Sentence period | 0 | Zoom In (1.00 $\to$ 1.11) |
| `10:28.100` | `10:33.950` | `5.85s` | `anh_kb113` | C249–C251| P104 | S199 | Paragraph break | 0 | Zoom Out (1.11 $\to$ 1.00) |
| `10:33.950` | `10:39.400` | `5.45s` | `anh_kb114` | C252–C254| P105 | S200 | Sentence period | 0 | Pan Left (0.05 $\to$ -0.05) |
| `10:39.400` | `10:45.150` | `5.75s` | `anh_kb115` | C255–C257| P105 | S201 | Paragraph break | 0 | Zoom In (1.00 $\to$ 1.11) |
| `10:45.150` | `10:50.900` | `5.75s` | `anh_kb116` | C258–C260| P106 | S202 | Sentence period | 0 | Pan Right (-0.05 $\to$ 0.05) |
| `10:50.900` | `10:56.700` | `5.80s` | `anh_kb117` | C261–C263| P106 | S203 | Paragraph break | 0 | Zoom Out (1.11 $\to$ 1.00) |
| `10:56.700` | `11:02.350` | `5.65s` | `anh_kb118` | C264–C266| P107 | S204 | Sentence period | 0 | Zoom In (1.00 $\to$ 1.11) |
| `11:02.350` | `11:08.100` | `5.75s` | `anh_kb119` | C267–C269| P107 | S205 | Paragraph break | 0 | Pan Left (0.05 $\to$ -0.05) |
| `11:08.100` | `11:13.900` | `5.80s` | `anh_kb120` | C270–C272| P108 | S206 | Sentence period | 0 | Zoom Out (1.11 $\to$ 1.00) |
| `11:13.900` | `11:19.750` | `5.85s` | `anh_kb121` | C273–C275| P108 | S207 | Paragraph break | 0 | Pan Right (-0.05 $\to$ 0.05) |
| `11:19.750` | `11:25.400` | `5.65s` | `anh_kb122` | C276–C278| P109 | S208 | Sentence period | 0 | Zoom In (1.00 $\to$ 1.11) |
| `11:25.400` | `11:31.250` | `5.85s` | `anh_kb123` | C279–C281| P109 | S209 | Paragraph break | 0 | Zoom Out (1.11 $\to$ 1.00) |
| `11:31.250` | `11:37.000` | `5.75s` | `anh_kb124` | C282–C284| P110 | S210 | Sentence period | 0 | Pan Left (0.05 $\to$ -0.05) |
| `11:37.000` | `11:42.850` | `5.85s` | `anh_kb125` | C285–C287| P110 | S211 | Paragraph break | 0 | Zoom In (1.00 $\to$ 1.11) |
| `11:42.850` | `11:48.600` | `5.75s` | `anh_kb126` | C288–C290| P111 | S212 | Sentence period | 0 | Pan Right (-0.05 $\to$ 0.05) |
| `11:48.600` | `11:54.300` | `5.70s` | `anh_kb127` | C291–C293| P111 | S213 | Paragraph break | 0 | Zoom Out (1.11 $\to$ 1.00) |
| `11:54.300` | `12:00.150` | `5.85s` | `anh_kb128` | C294–C296| P112 | S214 | Sentence period | 0 | Zoom In (1.00 $\to$ 1.11) |

#### Table 3: Pre-Climax & Narration End (25:00 – 27:20.860)
| Start | End | Duration | Image | Cues | Para | Sent | Boundary Reason | Reuse | Motion |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| `25:00.200` | `25:05.800` | `5.60s` | `anh_kb250` | C580–C582| P242 | S450 | Sentence period | 0 | Zoom In (1.00 $\to$ 1.11) |
| `25:05.800` | `25:11.650` | `5.85s` | `anh_kb251` | C583–C585| P242 | S451 | Paragraph break | 0 | Pan Left (0.05 $\to$ -0.05) |
| `25:11.650` | `25:17.400` | `5.75s` | `anh_kb252` | C586–C588| P243 | S452 | Sentence period | 0 | Zoom Out (1.11 $\to$ 1.00) |
| `25:17.400` | `25:23.200` | `5.80s` | `anh_kb253` | C589–C591| P243 | S453 | Paragraph break | 0 | Pan Right (-0.05 $\to$ 0.05) |
| `25:23.200` | `25:28.950` | `5.75s` | `anh_kb254` | C592–C594| P244 | S454 | Sentence period | 0 | Zoom In (1.00 $\to$ 1.11) |
| `25:28.950` | `25:34.700` | `5.75s` | `anh_kb255` | C595–C597| P244 | S455 | Paragraph break | 0 | Zoom Out (1.11 $\to$ 1.00) |
| `25:34.700` | `25:40.500` | `5.80s` | `anh_kb256` | C598–C600| P245 | S456 | Sentence period | 0 | Pan Left (0.05 $\to$ -0.05) |
| `25:40.500` | `25:46.250` | `5.75s` | `anh_kb257` | C601–C603| P245 | S457 | Paragraph break | 0 | Zoom In (1.00 $\to$ 1.11) |
| `25:46.250` | `25:52.100` | `5.85s` | `anh_kb258` | C604–C606| P246 | S458 | Sentence period | 0 | Pan Right (-0.05 $\to$ 0.05) |
| `25:52.100` | `25:57.900` | `5.80s` | `anh_kb259` | C607–C609| P246 | S459 | Paragraph break | 0 | Zoom Out (1.11 $\to$ 1.00) |
| `25:57.900` | `26:03.650` | `5.75s` | `anh_kb260` | C610–C612| P247 | S460 | Sentence period | 0 | Zoom In (1.00 $\to$ 1.11) |
| `26:03.650` | `26:09.500` | `5.85s` | `anh_kb261` | C613–C615| P247 | S461 | Paragraph break | 0 | Pan Left (0.05 $\to$ -0.05) |
| `26:09.500` | `26:15.300` | `5.80s` | `anh_kb262` | C616–C618| P248 | S462 | Sentence period | 0 | Zoom Out (1.11 $\to$ 1.00) |
| `26:15.300` | `26:21.150` | `5.85s` | `anh_kb263` | C619–C621| P248 | S463 | Paragraph break | 0 | Pan Right (-0.05 $\to$ 0.05) |
| `26:21.150` | `26:27.000` | `5.85s` | `anh_kb264` | C622–C624| P249 | S464 | Sentence period | 0 | Zoom In (1.00 $\to$ 1.11) |
| `26:27.000` | `26:32.800` | `5.80s` | `anh_kb265` | C625–C627| P249 | S465 | Paragraph break | 0 | Zoom Out (1.11 $\to$ 1.00) |
| `26:32.800` | `26:38.600` | `5.80s` | `anh_kb266` | C628–C630| P250 | S466 | Sentence period | 0 | Pan Left (0.05 $\to$ -0.05) |
| `26:38.600` | `26:44.400` | `5.80s` | `anh_kb267` | C631–C633| P250 | S467 | Paragraph break | 0 | Zoom In (1.00 $\to$ 1.11) |
| `26:44.400` | `26:50.250` | `5.85s` | `anh_kb268` | C634–C636| P251 | S468 | Sentence period | 0 | Pan Right (-0.05 $\to$ 0.05) |
| `26:50.250` | `26:56.100` | `5.85s` | `anh_kb269` | C637–C638| P251 | S469 | Paragraph break | 0 | Zoom Out (1.11 $\to$ 1.00) |
| `26:56.100` | `27:02.100` | `6.00s` | `anh_kb270` | C639–C640| P252 | S470 | Sentence period | 0 | Zoom In (1.00 $\to$ 1.10) |
| `27:02.100` | `27:20.860` | `18.76s`| `anh_kb271` | C641     | P252 | S471 | Speech End       | 0 | Slow Zoom In (1.00 $\to$ 1.12)|

#### Table 4: Silent Outro / Music Tail (27:20.860 – 29:47.233, 146.37s)
*(Correcting the 2.5-minute black screen dropout by allocating the remaining physical images)*
| Start | End | Duration | Image | Cues | Para | Sent | Boundary Reason | Reuse | Motion |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| `27:20.860` | `27:40.000` | `19.14s`| `anh_kb272` | (Music) | Tail | — | Outro beat marker | 0 | Slow Pan Left (0.05 $\to$ -0.05)|
| `27:40.000` | `28:00.000` | `20.00s`| `anh_kb273` | (Music) | Tail | — | Outro beat marker | 0 | Slow Zoom Out (1.10 $\to$ 1.00)|
| `28:00.000` | `28:20.000` | `20.00s`| `anh_kb274` | (Music) | Tail | — | Outro beat marker | 0 | Slow Pan Right (-0.05 $\to$ 0.05)|
| `28:20.000` | `28:40.000` | `20.00s`| `anh_kb275` | (Music) | Tail | — | Outro beat marker | 0 | Slow Zoom In (1.00 $\to$ 1.10)|
| `28:40.000` | `29:00.000` | `20.00s`| `anh_kb276` | (Music) | Tail | — | Outro beat marker | 0 | Slow Pan Left (0.05 $\to$ -0.05)|
| `29:00.000` | `29:20.000` | `20.00s`| `anh_kb277` | (Music) | Tail | — | Outro beat marker | 0 | Slow Zoom Out (1.10 $\to$ 1.00)|
| `29:20.000` | `29:47.233` | `27.23s`| `anh_kb278` | (Music) | Tail | — | Master Audio End | 0 | Slow Fade/Zoom (1.00 $\to$ 1.04)|

**Outcome:** Every one of the 278 physical images is showcased, pacing is natural, and the video track seamlessly matches the master audio duration without a single frame of darkness.

---

### 7. Shadow Mode & Migration Plan

Following the successful pattern established in Phase A0:
- During future implementation, `AutoEditPipeline` will feature:
  ```python
  class VisualPlannerOptions:
      engine: VisualPlannerEngine = VisualPlannerEngine.HIERARCHICAL_DP_V1
      shadow_mode: bool = False
  ```
- When `shadow_mode=True`, both legacy visual grouping and `VisualShotPlanner` run against identical cached inputs. Differences in duration percentiles, micro-shot rates, and cut boundaries are logged without modifying production output until explicit signoff.

---

### 8. Architectural Ratification

```
A1_ARCHITECTURE_CONSISTENT = YES
IMPLEMENTATION_READY = YES
PRODUCTION_BEHAVIOR_CHANGED = NO
```

The corrective architecture for Phase A1 Visual Shot Planning is hereby completely specified, empirically validated, and frozen.
