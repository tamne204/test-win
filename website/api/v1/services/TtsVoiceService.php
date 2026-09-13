<?php
/**
 * 2TOOLNE TTS — VOICE PROFILES SERVICE
 * Manages preset voices, cloned voice profiles, and reference audio streaming.
 */

declare(strict_types=1);

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../storage/CryptoService.php';
require_once __DIR__ . '/../storage/GoogleDriveStorageAdapter.php';
require_once __DIR__ . '/../storage/CurlHelper.php';

class TtsVoiceService {

    /**
     * Engine capability registry.
     */
    public static function getEngineCapabilities(): array {
        return [
            'QWEN3_TTS' => [
                'name' => 'Qwen3-TTS (Alibaba Qwen)',
                'languages' => ['en', 'ja', 'ko', 'zh', 'de', 'fr', 'ru', 'pt', 'es', 'it'],
                'engine_supported_languages' => ['en', 'ja', 'ko', 'zh', 'de', 'fr', 'ru', 'pt', 'es', 'it'],
                'product_enabled_languages' => ['en', 'ja', 'ko'],
                'voice_clone' => true,
                'custom_voice' => true,
                'official_speakers' => ['Aiden', 'Dylan', 'Eric', 'Ono_Anna', 'Ryan', 'Serena', 'Sohee', 'Uncle_Fu', 'Vivian'],
                'voice_types' => ['QWEN_BUILTIN', 'SYSTEM_CLONED', 'CLONED'],
                'status' => 'ACTIVE',
            ],
            'VOXCPM' => [
                'name' => 'VoxCPM (Vietnamese Dedicated Engine)',
                'languages' => ['vi'],
                'engine_supported_languages' => ['vi'],
                'product_enabled_languages' => ['vi'],
                'voice_clone' => true,
                'custom_voice' => false,
                'voice_types' => ['SYSTEM_CLONED', 'CLONED'],
                'status' => 'PLANNED',
            ],
            'SYNTHETIC' => [
                'name' => 'Synthetic / Mock Engine (Testing & Development)',
                'languages' => ['en', 'ja', 'ko', 'vi', 'zh'],
                'engine_supported_languages' => ['en', 'ja', 'ko', 'vi', 'zh'],
                'product_enabled_languages' => ['en', 'ja', 'ko'],
                'voice_clone' => true,
                'custom_voice' => true,
                'official_speakers' => ['Aiden', 'Dylan', 'Eric', 'Ono_Anna', 'Ryan', 'Serena', 'Sohee', 'Uncle_Fu', 'Vivian', 'Sarah', 'Alex', 'Sakura', 'Kenji', 'Jiwoo', 'Minho', 'Lan', 'Nam'],
                'voice_types' => ['QWEN_BUILTIN', 'SYSTEM_CLONED', 'CLONED'],
                'status' => 'ACTIVE',
            ],
            'product_enabled_languages' => ['en', 'ja', 'ko'],
            'engine_supported_languages' => ['en', 'ja', 'ko', 'zh', 'de', 'fr', 'ru', 'pt', 'es', 'it'],
            'active_languages' => ['en', 'ja', 'ko'],
            'coming_soon_languages' => ['vi'],
        ];
    }

    /**
     * Check if a system voice reference file exists and is active in cloud_files.
     */
    public static function isSystemReferenceActive(string $fileId): bool {
        if (empty($fileId)) {
            return false;
        }
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare('
                SELECT cf.id, cf.status, cf.mime_type
                FROM cloud_files cf
                WHERE cf.id = ? AND cf.deleted_at IS NULL
                LIMIT 1
            ');
            $stmt->execute([$fileId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return false;
            }
            if ($row['status'] !== 'ACTIVE') {
                return false;
            }
            $mime = strtolower($row['mime_type'] ?? '');
            if (strpos($mime, 'audio/') !== 0 && !in_array($mime, ['application/ogg', 'application/x-wav'], true)) {
                return false;
            }
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Presets: categorized into QWEN_BUILTIN and SYSTEM_CLONED.
     */
    public static function getPresetVoices(?string $language = null): array {
        $presets = [
            // =================================================================
            // 1. QWEN_BUILTIN (Official Qwen3-TTS CustomVoice 9 Speakers)
            // =================================================================
            [
                'id' => 'preset_en_ryan',
                'aliases' => ['ryan'],
                'name' => 'Ryan',
                'display_name' => 'Ryan (Official Qwen)',
                'voice_type' => 'QWEN_BUILTIN',
                'engine' => 'QWEN3_TTS',
                'engine_voice_key' => 'Ryan',
                'language' => 'en',
                'primary_language' => 'en',
                'supported_languages' => ['en', 'zh', 'ja', 'ko'],
                'gender' => 'male',
                'type' => 'preset',
                'preview_url' => '/api/v1/tts/voices/preset_en_ryan/preview',
                'description' => 'Official Qwen3-TTS dynamic English male voice',
                'status' => 'ACTIVE',
            ],
            [
                'id' => 'preset_en_aiden',
                'aliases' => ['aiden'],
                'name' => 'Aiden',
                'display_name' => 'Aiden (Official Qwen)',
                'voice_type' => 'QWEN_BUILTIN',
                'engine' => 'QWEN3_TTS',
                'engine_voice_key' => 'Aiden',
                'language' => 'en',
                'primary_language' => 'en',
                'supported_languages' => ['en', 'zh', 'ja', 'ko'],
                'gender' => 'male',
                'type' => 'preset',
                'preview_url' => '/api/v1/tts/voices/preset_en_aiden/preview',
                'description' => 'Official Qwen3-TTS warm English male voice',
                'status' => 'ACTIVE',
            ],
            [
                'id' => 'preset_ja_ono_anna',
                'aliases' => ['ono_anna', 'anna'],
                'name' => 'Ono Anna',
                'display_name' => 'Ono Anna (Official Qwen)',
                'voice_type' => 'QWEN_BUILTIN',
                'engine' => 'QWEN3_TTS',
                'engine_voice_key' => 'Ono_Anna',
                'language' => 'ja',
                'primary_language' => 'ja',
                'supported_languages' => ['ja', 'en', 'zh'],
                'gender' => 'female',
                'type' => 'preset',
                'preview_url' => '/api/v1/tts/voices/preset_ja_ono_anna/preview',
                'description' => 'Official Qwen3-TTS expressive Japanese female voice',
                'status' => 'ACTIVE',
            ],
            [
                'id' => 'preset_ko_sohee',
                'aliases' => ['sohee'],
                'name' => 'Sohee',
                'display_name' => 'Sohee (Official Qwen)',
                'voice_type' => 'QWEN_BUILTIN',
                'engine' => 'QWEN3_TTS',
                'engine_voice_key' => 'Sohee',
                'language' => 'ko',
                'primary_language' => 'ko',
                'supported_languages' => ['ko', 'en', 'zh'],
                'gender' => 'female',
                'type' => 'preset',
                'preview_url' => '/api/v1/tts/voices/preset_ko_sohee/preview',
                'description' => 'Official Qwen3-TTS clear Korean female voice',
                'status' => 'ACTIVE',
            ],
            [
                'id' => 'preset_zh_vivian',
                'aliases' => ['vivian'],
                'name' => 'Vivian',
                'display_name' => 'Vivian (Official Qwen)',
                'voice_type' => 'QWEN_BUILTIN',
                'engine' => 'QWEN3_TTS',
                'engine_voice_key' => 'Vivian',
                'language' => 'zh',
                'primary_language' => 'zh',
                'supported_languages' => ['zh', 'en'],
                'gender' => 'female',
                'type' => 'preset',
                'preview_url' => '/api/v1/tts/voices/preset_zh_vivian/preview',
                'description' => 'Official Qwen3-TTS standard Chinese female voice',
                'status' => 'ACTIVE',
            ],

            // =================================================================
            // 2. SYSTEM_CLONED (2TOOLNE Brand Voices with System Reference Audio)
            // =================================================================
            [
                'id' => 'preset_en_sarah',
                'aliases' => ['sarah', 'preset_en_female_1'],
                'name' => 'Sarah',
                'display_name' => 'Sarah (Brand Voice)',
                'voice_type' => 'SYSTEM_CLONED',
                'engine' => 'QWEN3_TTS',
                'engine_voice_key' => 'cf_sys_ref_sarah',
                'system_voice_reference_id' => 'cf_sys_ref_sarah',
                'system_voice_reference_file_id' => 'cf_sys_ref_sarah',
                'language' => 'en',
                'primary_language' => 'en',
                'supported_languages' => ['en'],
                'gender' => 'female',
                'type' => 'preset',
                'preview_url' => '/api/v1/tts/voices/preset_en_sarah/preview',
                'description' => 'Natural, clear and friendly English female brand voice',
                'status' => 'ACTIVE',
            ],
            [
                'id' => 'preset_en_alex',
                'aliases' => ['alex', 'preset_en_male_1'],
                'name' => 'Alex',
                'display_name' => 'Alex (Brand Voice)',
                'voice_type' => 'SYSTEM_CLONED',
                'engine' => 'QWEN3_TTS',
                'engine_voice_key' => 'cf_sys_ref_alex',
                'system_voice_reference_id' => 'cf_sys_ref_alex',
                'system_voice_reference_file_id' => 'cf_sys_ref_alex',
                'language' => 'en',
                'primary_language' => 'en',
                'supported_languages' => ['en'],
                'gender' => 'male',
                'type' => 'preset',
                'preview_url' => '/api/v1/tts/voices/preset_en_alex/preview',
                'description' => 'Authoritative, calm and versatile English male brand voice',
                'status' => 'ACTIVE',
            ],
            [
                'id' => 'preset_ja_sakura',
                'aliases' => ['sakura', 'preset_ja_female_1'],
                'name' => 'Sakura',
                'display_name' => 'Sakura (Brand Voice)',
                'voice_type' => 'SYSTEM_CLONED',
                'engine' => 'QWEN3_TTS',
                'engine_voice_key' => 'cf_sys_ref_sakura',
                'system_voice_reference_id' => 'cf_sys_ref_sakura',
                'system_voice_reference_file_id' => 'cf_sys_ref_sakura',
                'language' => 'ja',
                'primary_language' => 'ja',
                'supported_languages' => ['ja'],
                'gender' => 'female',
                'type' => 'preset',
                'preview_url' => '/api/v1/tts/voices/preset_ja_sakura/preview',
                'description' => 'Expressive and warm Japanese female brand voice',
                'status' => 'ACTIVE',
            ],
            [
                'id' => 'preset_ja_kenji',
                'aliases' => ['kenji', 'preset_ja_male_1'],
                'name' => 'Kenji',
                'display_name' => 'Kenji (Brand Voice)',
                'voice_type' => 'SYSTEM_CLONED',
                'engine' => 'QWEN3_TTS',
                'engine_voice_key' => 'cf_sys_ref_kenji',
                'system_voice_reference_id' => 'cf_sys_ref_kenji',
                'system_voice_reference_file_id' => 'cf_sys_ref_kenji',
                'language' => 'ja',
                'primary_language' => 'ja',
                'supported_languages' => ['ja'],
                'gender' => 'male',
                'type' => 'preset',
                'preview_url' => '/api/v1/tts/voices/preset_ja_kenji/preview',
                'description' => 'Gentle and natural Japanese male brand voice',
                'status' => 'ACTIVE',
            ],
            [
                'id' => 'preset_ko_jiwoo',
                'aliases' => ['jiwoo', 'preset_ko_female_1'],
                'name' => 'Jiwoo',
                'display_name' => 'Jiwoo (Brand Voice)',
                'voice_type' => 'SYSTEM_CLONED',
                'engine' => 'QWEN3_TTS',
                'engine_voice_key' => 'cf_sys_ref_jiwoo',
                'system_voice_reference_id' => 'cf_sys_ref_jiwoo',
                'system_voice_reference_file_id' => 'cf_sys_ref_jiwoo',
                'language' => 'ko',
                'primary_language' => 'ko',
                'supported_languages' => ['ko'],
                'gender' => 'female',
                'type' => 'preset',
                'preview_url' => '/api/v1/tts/voices/preset_ko_jiwoo/preview',
                'description' => 'Bright and modern Korean female brand voice',
                'status' => 'ACTIVE',
            ],
            [
                'id' => 'preset_ko_minho',
                'aliases' => ['minho', 'preset_ko_male_1'],
                'name' => 'Minho',
                'display_name' => 'Minho (Brand Voice)',
                'voice_type' => 'SYSTEM_CLONED',
                'engine' => 'QWEN3_TTS',
                'engine_voice_key' => 'cf_sys_ref_minho',
                'system_voice_reference_id' => 'cf_sys_ref_minho',
                'system_voice_reference_file_id' => 'cf_sys_ref_minho',
                'language' => 'ko',
                'primary_language' => 'ko',
                'supported_languages' => ['ko'],
                'gender' => 'male',
                'type' => 'preset',
                'preview_url' => '/api/v1/tts/voices/preset_ko_minho/preview',
                'description' => 'Polite and clear Korean male brand voice',
                'status' => 'ACTIVE',
            ],
            [
                'id' => 'preset_vi_lan',
                'aliases' => ['lan', 'preset_vi_female_1'],
                'name' => 'Lan',
                'display_name' => 'Lan (Brand Voice)',
                'voice_type' => 'SYSTEM_CLONED',
                'engine' => 'VOXCPM',
                'engine_voice_key' => 'cf_sys_ref_lan',
                'system_voice_reference_id' => 'cf_sys_ref_lan',
                'system_voice_reference_file_id' => 'cf_sys_ref_lan',
                'language' => 'vi',
                'primary_language' => 'vi',
                'supported_languages' => ['vi'],
                'gender' => 'female',
                'type' => 'preset',
                'preview_url' => '/api/v1/tts/voices/preset_vi_lan/preview',
                'description' => 'Northern Vietnamese female brand voice (Planned Dedicated Engine - Coming Soon)',
                'status' => 'COMING_SOON',
            ],
            [
                'id' => 'preset_vi_nam',
                'aliases' => ['nam', 'preset_vi_male_1'],
                'name' => 'Nam',
                'display_name' => 'Nam (Brand Voice)',
                'voice_type' => 'SYSTEM_CLONED',
                'engine' => 'VOXCPM',
                'engine_voice_key' => 'cf_sys_ref_nam',
                'system_voice_reference_id' => 'cf_sys_ref_nam',
                'system_voice_reference_file_id' => 'cf_sys_ref_nam',
                'language' => 'vi',
                'primary_language' => 'vi',
                'supported_languages' => ['vi'],
                'gender' => 'male',
                'type' => 'preset',
                'preview_url' => '/api/v1/tts/voices/preset_vi_nam/preview',
                'description' => 'Southern Vietnamese male brand voice (Planned Dedicated Engine - Coming Soon)',
                'status' => 'COMING_SOON',
            ],
        ];

        // System Voice Record Verification:
        // For each SYSTEM_CLONED voice, verify that its reference audio file is present & ACTIVE in cloud_files.
        // If not uploaded yet, set status to NOT_READY so brand voice is never exposed as ACTIVE without authorized reference audio.
        foreach ($presets as &$p) {
            if (($p['voice_type'] ?? '') === 'SYSTEM_CLONED' && ($p['status'] ?? '') !== 'COMING_SOON') {
                $refFileId = $p['system_voice_reference_file_id'] ?? ($p['system_voice_reference_id'] ?? '');
                if (empty($refFileId) || !self::isSystemReferenceActive($refFileId)) {
                    $p['status'] = 'NOT_READY';
                }
            }
        }
        unset($p);

        if (!empty($language)) {
            $presets = array_values(array_filter($presets, function($v) use ($language) {
                return strtolower($v['primary_language']) === strtolower($language);
            }));
        }

        return $presets;
    }

    /**
     * Format a voice object for public client response (hiding internal keys & reference file IDs).
     */
    public static function formatPublicVoice(array $v): array {
        $voiceId = $v['id'] ?? ($v['voice_id'] ?? '');
        $isCloned = (($v['type'] ?? '') === 'cloned' || ($v['voice_type'] ?? '') === 'CLONED' || ($v['type'] ?? '') === 'CUSTOM');
        $previewUrl = "/api/v1/tts/voices/{$voiceId}/preview";
        $refLang = $v['reference_language'] ?? ($v['primary_language'] ?? ($v['language'] ?? 'en'));

        return [
            'voice_id' => $voiceId,
            'id' => $voiceId,
            'name' => $v['name'] ?? '',
            'display_name' => $v['display_name'] ?? ($v['name'] ?? ''),
            'type' => $v['type'] ?? 'preset',
            'voice_type' => $v['voice_type'] ?? ($isCloned ? 'CLONED' : 'QWEN_BUILTIN'),
            'language' => $v['language'] ?? ($v['primary_language'] ?? 'en'),
            'reference_language' => $refLang,
            'supported_languages' => $v['supported_languages'] ?? ($isCloned ? ['en', 'ja', 'ko'] : [$v['language'] ?? 'en']),
            'supported_target_languages' => $v['supported_target_languages'] ?? ($isCloned ? ['en', 'ja', 'ko'] : [$v['language'] ?? 'en']),
            'cross_language_verified' => $v['cross_language_verified'] ?? ($isCloned ? false : true),
            'clone_mode' => $v['clone_mode'] ?? (!empty($v['reference_text']) ? 'ICL' : ($isCloned ? 'X_VECTOR_ONLY' : null)),
            'gender' => $v['gender'] ?? null,
            'engine' => $v['engine'] ?? 'QWEN3_TTS',
            'preview_url' => $previewUrl,
            'description' => $v['description'] ?? null,
            'status' => $v['status'] ?? 'ACTIVE',
            'created_at' => $v['created_at'] ?? null,
        ];
    }

    public static function listVoices(string $userId, ?string $language = null): array {
        $db = Database::getConnection();
        $rawPresets = self::getPresetVoices($language);

        $sql = 'SELECT id, user_id, cloud_space_id, name, engine, primary_language, reference_file_id, reference_text, reference_checksum_sha256, status, created_at, updated_at
                FROM tts_voice_profiles
                WHERE user_id = ? AND status = "ACTIVE"';
        $params = [$userId];

        if (!empty($language)) {
            // For cloned voices, if language is one of supported target languages ('en', 'ja', 'ko'), include it
            $sql .= ' AND (primary_language = ? OR ? IN ("en", "ja", "ko"))';
            $params[] = $language;
            $params[] = $language;
        }

        $sql .= ' ORDER BY created_at DESC';

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rawCustom = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $customVoices = [];
        foreach ($rawCustom as $row) {
            $cloneMode = !empty($row['reference_text']) ? 'ICL' : 'X_VECTOR_ONLY';
            $customVoices[] = self::formatPublicVoice([
                'id' => $row['id'],
                'type' => 'cloned',
                'voice_type' => 'CLONED',
                'name' => $row['name'],
                'display_name' => $row['name'],
                'language' => $row['primary_language'] ?: 'en',
                'primary_language' => $row['primary_language'] ?: 'en',
                'reference_language' => $row['primary_language'] ?: 'en',
                'supported_languages' => ['en', 'ja', 'ko'],
                'supported_target_languages' => ['en', 'ja', 'ko'],
                'cross_language_verified' => false,
                'clone_mode' => $cloneMode,
                'engine' => $row['engine'] ?: 'QWEN3_TTS',
                'preview_url' => "/api/v1/tts/voices/{$row['id']}/preview",
                'status' => 'ACTIVE',
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
            ]);
        }

        $formattedPresets = array_map([self::class, 'formatPublicVoice'], $rawPresets);
        $unified = array_merge($formattedPresets, $customVoices);

        return [
            'presets' => $formattedPresets,
            'custom' => $customVoices,
            'all' => $unified,
            'data' => $unified,
        ];
    }

    public static function resolveVoice(string $voiceId, ?string $userId = null): array {
        $voiceId = trim($voiceId);
        if (empty($voiceId)) {
            throw new InvalidArgumentException('voice_id is required', 400);
        }

        // 1. Check presets by canonical ID, name, or alias (case-insensitive)
        $lowerVoiceId = strtolower($voiceId);
        foreach (self::getPresetVoices() as $p) {
            $lowerAliases = isset($p['aliases']) ? array_map('strtolower', $p['aliases']) : [];
            if (strtolower($p['id']) === $lowerVoiceId || strtolower($p['name']) === $lowerVoiceId || in_array($lowerVoiceId, $lowerAliases, true)) {
                return [
                    'id' => $p['id'],
                    'canonical_id' => $p['id'],
                    'type' => 'preset',
                    'voice_type' => $p['voice_type'],
                    'name' => $p['name'],
                    'display_name' => $p['display_name'],
                    'engine_voice_key' => $p['engine_voice_key'],
                    'system_voice_reference_id' => $p['system_voice_reference_id'] ?? null,
                    'system_voice_reference_file_id' => $p['system_voice_reference_file_id'] ?? ($p['system_voice_reference_id'] ?? null),
                    'language' => $p['language'],
                    'primary_language' => $p['primary_language'],
                    'supported_languages' => $p['supported_languages'],
                    'clone_mode' => ($p['voice_type'] === 'SYSTEM_CLONED' ? 'ICL' : null),
                    'x_vector_only_mode' => false,
                    'engine' => $p['engine'],
                    'preview_url' => $p['preview_url'] ?? null,
                    'status' => $p['status'] ?? 'ACTIVE',
                    'is_preset' => true,
                    'voice_profile_id' => $p['id'],
                ];
            }
        }

        // 2. Check custom cloned voice profile
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT * FROM tts_voice_profiles WHERE id = ? AND status = "ACTIVE" LIMIT 1');
        $stmt->execute([$voiceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            if ($userId !== null && (string)$row['user_id'] !== (string)$userId) {
                // Check if user is admin or authorized
                $uStmt = $db->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
                $uStmt->execute([$userId]);
                $uRole = $uStmt->fetchColumn();
                if (!in_array($uRole, ['admin', 'super_admin'], true)) {
                    throw new RuntimeException("You do not have permission to use voice profile '{$voiceId}'", 403);
                }
            }

            $cloneMode = !empty($row['reference_text']) ? 'ICL' : 'X_VECTOR_ONLY';
            return [
                'id' => $row['id'],
                'canonical_id' => $row['id'],
                'type' => 'cloned',
                'voice_type' => 'CLONED',
                'name' => $row['name'],
                'display_name' => $row['name'],
                'engine_voice_key' => $row['id'],
                'system_voice_reference_id' => null,
                'system_voice_reference_file_id' => null,
                'language' => $row['primary_language'] ?: 'en',
                'reference_language' => $row['primary_language'] ?: 'en',
                'primary_language' => $row['primary_language'] ?: 'en',
                'supported_languages' => ['en', 'ja', 'ko'],
                'supported_target_languages' => ['en', 'ja', 'ko'],
                'cross_language_verified' => false,
                'clone_mode' => $cloneMode,
                'x_vector_only_mode' => ($cloneMode === 'X_VECTOR_ONLY'),
                'engine' => $row['engine'] ?: 'QWEN3_TTS',
                'preview_url' => "/api/v1/tts/voices/{$row['id']}/preview",
                'is_preset' => false,
                'voice_profile_id' => $row['id'],
                'reference_file_id' => $row['reference_file_id'],
                'reference_text' => $row['reference_text'],
                'reference_checksum_sha256' => $row['reference_checksum_sha256'],
            ];
        }

        throw new InvalidArgumentException("Voice not found: {$voiceId}", 404);
    }

    public static function createVoiceProfile(string $userId, array $data): array {
        $name = trim($data['name'] ?? '');
        $refFileId = trim($data['reference_file_id'] ?? ($data['reference_cloud_file_id'] ?? ''));
        $refText = trim($data['reference_text'] ?? '');
        $refLang = strtolower(trim($data['reference_language'] ?? ($data['primary_language'] ?? ($data['language'] ?? 'en'))));
        $targetLang = !empty($data['preferred_target_language']) ? strtolower(trim($data['preferred_target_language'])) : null;

        // Server-resolved engine: client cannot pick engine arbitrarily
        $engine = 'QWEN3_TTS';
        if (!empty($data['engine']) && strtoupper(trim($data['engine'])) !== 'QWEN3_TTS') {
            throw new InvalidArgumentException("Engine mismatch: voice cloning resolves to QWEN3_TTS, but '{$data['engine']}' was requested. Client cannot override engine.");
        }

        if (empty($name)) {
            throw new InvalidArgumentException('Voice profile name is required');
        }
        if (empty($refFileId)) {
            throw new InvalidArgumentException('reference_file_id is required');
        }

        // Semantic contract check: Qwen3-TTS cannot target Vietnamese
        if ($refLang === 'vi' || $targetLang === 'vi') {
            throw new InvalidArgumentException("DISABLED_FOR_QWEN: Qwen3-TTS engine cannot target Vietnamese ('vi')");
        }

        // Determine clone mode: ICL (with transcript) vs X_VECTOR_ONLY (transcript-less)
        $cloneMode = !empty($data['clone_mode']) ? strtoupper(trim($data['clone_mode'])) : (!empty($refText) ? 'ICL' : 'X_VECTOR_ONLY');
        if (!in_array($cloneMode, ['ICL', 'X_VECTOR_ONLY'], true)) {
            throw new InvalidArgumentException("Invalid clone_mode: '{$cloneMode}'. Allowed values: ICL, X_VECTOR_ONLY");
        }
        if ($cloneMode === 'ICL' && empty($refText)) {
            throw new InvalidArgumentException("clone_mode ICL requires non-empty reference_text");
        }

        $db = Database::getConnection();

        // Check reference file in cloud_files
        $stmt = $db->prepare('SELECT id, cloud_space_id, created_by_user_id, filename, extension, mime_type, size_bytes, checksum_sha256, status
                               FROM cloud_files WHERE id = ? AND deleted_at IS NULL LIMIT 1');
        $stmt->execute([$refFileId]);
        $file = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$file) {
            throw new RuntimeException('Reference audio file not found');
        }
        if ($file['status'] !== 'ACTIVE') {
            throw new RuntimeException('Reference audio file is not ready or pending upload');
        }

        // Validate audio format
        $validAudioExts = ['wav', 'mp3', 'm4a', 'aac', 'ogg', 'flac'];
        $ext = strtolower($file['extension'] ?? pathinfo($file['filename'], PATHINFO_EXTENSION));
        $isAudioMime = (strpos($file['mime_type'] ?? '', 'audio/') === 0 || in_array($ext, $validAudioExts, true));
        if (!$isAudioMime) {
            throw new RuntimeException('Reference file must be an audio file (wav, mp3, m4a, ogg, flac)');
        }

        // Validate file size (max 25MB for reference audio snippet)
        if ((int)$file['size_bytes'] > 25 * 1024 * 1024) {
            throw new RuntimeException('Reference audio file exceeds maximum allowed size of 25MB');
        }

        $spaceId = $file['cloud_space_id'];
        $voiceId = 'voice_' . bin2hex(random_bytes(10));
        $checksum = $file['checksum_sha256'] ?? null;

        $ins = $db->prepare('
            INSERT INTO tts_voice_profiles (
                id, user_id, cloud_space_id, name, engine, primary_language,
                reference_file_id, reference_text, reference_checksum_sha256, status, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "ACTIVE", NOW(), NOW())
        ');
        $ins->execute([
            $voiceId, $userId, $spaceId, $name, $engine, $refLang,
            $refFileId, $refText ?: null, $checksum
        ]);

        return [
            'id' => $voiceId,
            'voice_id' => $voiceId,
            'user_id' => $userId,
            'cloud_space_id' => $spaceId,
            'name' => $name,
            'engine' => $engine,
            'reference_language' => $refLang,
            'primary_language' => $refLang,
            'supported_languages' => ['en', 'ja', 'ko'],
            'supported_target_languages' => ['en', 'ja', 'ko'],
            'cross_language_verified' => false,
            'clone_mode' => $cloneMode,
            'x_vector_only_mode' => ($cloneMode === 'X_VECTOR_ONLY'),
            'preview_url' => "/api/v1/tts/voices/{$voiceId}/preview",
            'status' => 'ACTIVE',
            'type' => 'CUSTOM',
            'voice_type' => 'CLONED',
            'created_at' => date('Y-m-d H:i:s'),
        ];
    }

    public static function getVoiceProfile(string $voiceId, ?string $userId = null): ?array {
        if (strpos($voiceId, 'preset_') === 0) {
            $presets = self::getPresetVoices();
            foreach ($presets as $p) {
                if ($p['id'] === $voiceId || (isset($p['aliases']) && in_array($voiceId, $p['aliases'], true))) {
                    return $p;
                }
            }
            return null;
        }

        $db = Database::getConnection();
        $sql = 'SELECT * FROM tts_voice_profiles WHERE id = ? LIMIT 1';
        $stmt = $db->prepare($sql);
        $stmt->execute([$voiceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        if ($userId !== null && (string)$row['user_id'] !== (string)$userId) {
            return null;
        }

        $cloneMode = !empty($row['reference_text']) ? 'ICL' : 'X_VECTOR_ONLY';
        $row['type'] = 'cloned';
        $row['voice_type'] = 'CLONED';
        $row['engine_voice_key'] = $row['id'];
        $row['system_voice_reference_id'] = null;
        $row['system_voice_reference_file_id'] = null;
        $row['reference_language'] = $row['primary_language'] ?: 'en';
        $row['supported_languages'] = ['en', 'ja', 'ko'];
        $row['supported_target_languages'] = ['en', 'ja', 'ko'];
        $row['cross_language_verified'] = false;
        $row['clone_mode'] = $cloneMode;
        $row['x_vector_only_mode'] = ($cloneMode === 'X_VECTOR_ONLY');
        $row['preview_url'] = "/api/v1/tts/voices/{$row['id']}/preview";
        return $row;
    }

    public static function getVoiceDetails(string $voiceId): array {
        return self::resolveVoice($voiceId);
    }

    public static function deleteVoiceProfile(string $voiceId, string $userId): bool {
        $db = Database::getConnection();
        // Check if voice profile is being used by any active TTS jobs
        $chkActive = $db->prepare("
            SELECT id FROM tts_jobs
            WHERE voice_profile_id = ? AND status IN ('QUEUED', 'CLAIMED', 'PREPARING', 'GENERATING', 'POST_PROCESSING', 'UPLOADING')
            LIMIT 1
        ");
        $chkActive->execute([$voiceId]);
        if ($chkActive->fetch()) {
            throw new RuntimeException("Cannot delete voice profile while it is being used by an active TTS job.", 409);
        }

        $stmt = $db->prepare('UPDATE tts_voice_profiles SET status = "DELETED", updated_at = NOW() WHERE id = ? AND user_id = ?');
        $stmt->execute([$voiceId, $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Preview streaming endpoint that enforces voice authorization and hides internal storage keys.
     */
    public static function streamVoicePreview(string $voiceId, ?string $userId = null): void {
        $voiceId = trim($voiceId);
        if (empty($voiceId)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'voice_id is required']);
            exit;
        }

        // Preset voices are public previews
        if (strpos($voiceId, 'preset_') === 0) {
            self::streamReferenceAudio($voiceId);
            return;
        }

        // Custom cloned voice: verify ownership
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT * FROM tts_voice_profiles WHERE id = ? AND status = "ACTIVE" LIMIT 1');
        $stmt->execute([$voiceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Voice profile not found']);
            exit;
        }

        if ($userId !== null && (string)$row['user_id'] !== (string)$userId) {
            // Check if admin
            $uStmt = $db->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
            $uStmt->execute([$userId]);
            $uRole = $uStmt->fetchColumn();
            if (!in_array($uRole, ['admin', 'super_admin'], true)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Access denied to voice preview']);
                exit;
            }
        }

        self::streamReferenceAudio($voiceId);
    }

    public static function streamReferenceAudio(string $voiceId): void {
        $voice = self::resolveVoice($voiceId);

        // Case 0: QWEN_BUILTIN official presets preview
        if (($voice['voice_type'] ?? '') === 'QWEN_BUILTIN') {
            $speaker = strtolower($voice['name'] ?? '');
            $samplePath = __DIR__ . '/../../../samples/voices/' . $speaker . '.mp3';
            if (file_exists($samplePath)) {
                header("Content-Type: audio/mpeg");
                header("Content-Disposition: inline; filename=\"{$voice['id']}_preview.mp3\"");
                header("Content-Length: " . filesize($samplePath));
                readfile($samplePath);
                exit;
            }
            // Synthetic tone fallback for testing / dev
            header("Content-Type: audio/wav");
            header("Content-Disposition: inline; filename=\"{$voice['id']}_preview.wav\"");
            $dummyWav = self::generateReferenceWavSample();
            header("Content-Length: " . strlen($dummyWav));
            echo $dummyWav;
            exit;
        }

        // Case 1: SYSTEM_CLONED preset (Cloud V2 system-owned storage, zero-byte hosting)
        if (($voice['voice_type'] ?? '') === 'SYSTEM_CLONED') {
            $sysRefFileId = $voice['system_voice_reference_file_id'] ?? ($voice['system_voice_reference_id'] ?? 'cf_sys_ref_' . $voice['id']);

            header("Content-Type: audio/wav");
            header("Content-Disposition: inline; filename=\"{$voice['id']}_reference.wav\"");
            header("X-Voice-Type: SYSTEM_CLONED");
            header("X-Reference-Source: 2TOOLNE_CLOUD_V2");

            $db = Database::getConnection();
            $stmt = $db->prepare('
                SELECT cf.*, sa.encrypted_credentials, sa.provider, sa.status as sa_status
                FROM cloud_files cf
                LEFT JOIN storage_accounts sa ON cf.storage_account_id = sa.id
                WHERE cf.id = ? AND cf.status = "ACTIVE" AND cf.deleted_at IS NULL
                LIMIT 1
            ');
            $stmt->execute([$sysRefFileId]);
            $file = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($file && !empty($file['encrypted_credentials'])) {
                try {
                    $credentials = CryptoService::decryptJson($file['encrypted_credentials']);
                    $adapter = new GoogleDriveStorageAdapter();
                    $token = $adapter->getValidAccessToken($credentials);

                    $streamUrl = "https://www.googleapis.com/drive/v3/files/" . urlencode($file['provider_file_id']) . "?alt=media";
                    header("Content-Length: " . ((int)$file['size_bytes']));
                    header("X-Reference-Checksum: " . ($file['checksum_sha256'] ?? ''));

                    $ch = curl_init($streamUrl);
                    curl_setopt_array($ch, [
                        CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$token}"],
                        CURLOPT_RETURNTRANSFER => false,
                        CURLOPT_FOLLOWLOCATION => true,
                        CURLOPT_TIMEOUT        => 60,
                        CURLOPT_SSL_VERIFYPEER => true,
                    ]);
                    CurlHelper::applySslOptions($ch);
                    curl_exec($ch);
                    curl_close($ch);
                    exit;
                } catch (Throwable $e) {
                    // fall through
                }
            }

            // Zero-Byte Shared Hosting Fallback: valid synthetic 24kHz tone for dev/test environment
            $dummyWav = self::generateReferenceWavSample();
            header("Content-Length: " . strlen($dummyWav));
            header("X-Reference-Checksum: " . hash('sha256', $dummyWav));
            echo $dummyWav;
            exit;
        }

        // Case 2: User CLONED voice profile (Stream from Cloud Storage)
        if (empty($voice['reference_file_id'])) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Voice profile has no reference audio']);
            exit;
        }

        $db = Database::getConnection();
        $stmt = $db->prepare('
            SELECT cf.*, sa.encrypted_credentials, sa.provider, sa.status as sa_status
            FROM cloud_files cf
            LEFT JOIN storage_accounts sa ON cf.storage_account_id = sa.id
            WHERE cf.id = ? AND cf.status = "ACTIVE" AND cf.deleted_at IS NULL
            LIMIT 1
        ');
        $stmt->execute([$voice['reference_file_id']]);
        $file = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$file) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Reference audio cloud file not found']);
            exit;
        }

        $filename = $file['filename'];
        $mimeType = $file['mime_type'] ?: 'audio/wav';
        $size = (int)$file['size_bytes'];

        if (!empty($file['encrypted_credentials'])) {
            try {
                $credentials = CryptoService::decryptJson($file['encrypted_credentials']);
                $adapter = new GoogleDriveStorageAdapter();
                $token = $adapter->getValidAccessToken($credentials);

                $streamUrl = "https://www.googleapis.com/drive/v3/files/" . urlencode($file['provider_file_id']) . "?alt=media";

                header("Content-Type: {$mimeType}");
                header("Content-Disposition: inline; filename=\"{$filename}\"");
                header("Content-Length: {$size}");
                header("X-Reference-Checksum: " . ($file['checksum_sha256'] ?? ''));

                $ch = curl_init($streamUrl);
                curl_setopt_array($ch, [
                    CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$token}"],
                    CURLOPT_RETURNTRANSFER => false,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_TIMEOUT        => 60,
                    CURLOPT_SSL_VERIFYPEER => true,
                ]);
                CurlHelper::applySslOptions($ch);
                curl_exec($ch);
                curl_close($ch);
                exit;
            } catch (Throwable $e) {
                // fall through
            }
        }

        // Fallback for local dev/testing
        header("Content-Type: {$mimeType}");
        header("Content-Disposition: inline; filename=\"{$filename}\"");
        header("X-Reference-Checksum: " . ($file['checksum_sha256'] ?? ''));
        $dummyWav = self::generateReferenceWavSample();
        header("Content-Length: " . strlen($dummyWav));
        echo $dummyWav;
        exit;
    }

    /**
     * Generate synthetic valid 24kHz 16-bit mono PCM WAV bytes for dev/test reference audio.
     */
    public static function generateReferenceWavSample(): string {
        $sampleRate = 24000;
        $numSamples = 12000; // 0.5s
        $data = '';
        for ($i = 0; $i < $numSamples; $i++) {
            $val = (int)(sin(2 * M_PI * 440 * $i / $sampleRate) * 16000);
            $data .= pack('s', $val);
        }
        $dataSize = strlen($data);
        $header = 'RIFF' . pack('V', 36 + $dataSize) . 'WAVEfmt ' . pack('V', 16)
                . pack('v', 1) . pack('v', 1) . pack('V', $sampleRate)
                . pack('V', $sampleRate * 2) . pack('v', 2) . pack('v', 16)
                . 'data' . pack('V', $dataSize);
        return $header . $data;
    }
}
