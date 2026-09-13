package main

import (
	"crypto/ed25519"
	"crypto/hmac"
	"crypto/rand"
	"crypto/sha256"
	"crypto/subtle"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"runtime"
	"strconv"
	"strings"
	"time"
)

// Canonical Production Ed25519 Public Key (Raw 32 bytes)
// Base64: +KnGR3tLyy0jCKMkFuCgh39QSVyP4NNi2R4EspaIkZE=
// SPKI:   MCowBQYDK2VwAyEA+KnGR3tLyy0jCKMkFuCgh39QSVyP4NNi2R4EspaIkZE=
var CanonicalPublicKeyBytes = []byte{
	0xf8, 0xa9, 0xc6, 0x47, 0x7b, 0x4b, 0xcb, 0x2d,
	0x23, 0x08, 0xa3, 0x24, 0x16, 0xe0, 0xa0, 0x87,
	0x7f, 0x50, 0x49, 0x5c, 0x8f, 0xe0, 0xd3, 0x62,
	0xd9, 0x1e, 0x04, 0xb2, 0x96, 0x88, 0x91, 0x91,
}

const (
	CanonicalPublicKeyB64 = "+KnGR3tLyy0jCKMkFuCgh39QSVyP4NNi2R4EspaIkZE="
	BootstrapSalt         = "2TOOLNE_NATIVE_ROOT_BOOTSTRAP_HANDSHAKE_v2_2026"
	BootstrapTokenTTLMs   = 60000 // 60 seconds
)

// Trusted public keys list supporting seamless key rotation
var TrustedPublicKeys = [][]byte{
	CanonicalPublicKeyBytes,
}

// 8 Critical release files required by 2TOOLNE Desktop architecture
var CriticalReleaseFiles = []string{
	"app.asar",
	"2toolne-runtime.exe",
	"autoedit-core/win-x64/2toolne-core.exe",
	"bin/win-x64/ffmpeg.exe",
	"bin/win-x64/ffprobe.exe",
	"bin/win-x64/CapCutUiProbe.exe",
	"engine/win-x64/realesrgan-ncnn-vulkan.exe",
	"engine/win-x64/vcomp140.dll",
}

// SecurityViolationError captures structured security error information
type SecurityViolationError struct {
	Code    string
	Message string
}

func (e *SecurityViolationError) Error() string {
	return fmt.Sprintf("INTEGRITY_VIOLATION [%s]: %s", e.Code, e.Message)
}

// VerificationResult contains details of a successful verification run
type VerificationResult struct {
	FilesChecked      int
	Version           string
	Timestamp         string
	ManifestSignature string
	BootstrapToken    string
}

// computeHmacSha256 computes HMAC-SHA256 digest
func computeHmacSha256(key, data []byte) []byte {
	h := hmac.New(sha256.New, key)
	h.Write(data)
	return h.Sum(nil)
}

// ComputeFileSha256 computes the SHA-256 hex digest using streamed reads (1MB buffer)
func ComputeFileSha256(filePath string) (string, error) {
	f, err := os.Open(filePath)
	if err != nil {
		return "", err
	}
	defer f.Close()

	hasher := sha256.New()
	buf := make([]byte, 1024*1024)
	if _, err := io.CopyBuffer(hasher, f, buf); err != nil {
		return "", err
	}
	return hex.EncodeToString(hasher.Sum(nil)), nil
}

// GenerateBootstrapToken creates an unforgeable, ephemeral token bound to the signed manifest
func GenerateBootstrapToken(manifestSig string) (string, error) {
	nonceBytes := make([]byte, 16)
	if _, err := rand.Read(nonceBytes); err != nil {
		return "", fmt.Errorf("failed generating cryptographically secure nonce: %w", err)
	}
	nonce := hex.EncodeToString(nonceBytes)
	timestampStr := strconv.FormatInt(time.Now().UnixMilli(), 10)

	derivedKey := computeHmacSha256([]byte(BootstrapSalt), []byte(manifestSig))
	tokenSigBytes := computeHmacSha256(derivedKey, []byte(nonce+"."+timestampStr))
	tokenSig := hex.EncodeToString(tokenSigBytes)

	return fmt.Sprintf("%s.%s.%s", nonce, timestampStr, tokenSig), nil
}

// VerifyBootstrapToken validates a bootstrap token against the manifest signature
func VerifyBootstrapToken(token, manifestSig string, maxAgeMs int64) (bool, error) {
	parts := strings.Split(token, ".")
	if len(parts) != 3 {
		return false, fmt.Errorf("malformed token format (expected nonce.timestamp.sig)")
	}

	nonce := parts[0]
	timestampStr := parts[1]
	providedSig := parts[2]

	if len(nonce) != 32 || len(providedSig) != 64 {
		return false, fmt.Errorf("invalid token component length")
	}

	ts, err := strconv.ParseInt(timestampStr, 10, 64)
	if err != nil {
		return false, fmt.Errorf("invalid timestamp format: %w", err)
	}

	if maxAgeMs > 0 {
		age := time.Now().UnixMilli() - ts
		if age < 0 {
			age = -age
		}
		if age > maxAgeMs {
			return false, fmt.Errorf("token expired (age: %dms, max: %dms)", age, maxAgeMs)
		}
	}

	derivedKey := computeHmacSha256([]byte(BootstrapSalt), []byte(manifestSig))
	expectedSigBytes := computeHmacSha256(derivedKey, []byte(nonce+"."+timestampStr))
	expectedSig := hex.EncodeToString(expectedSigBytes)

	if subtle.ConstantTimeCompare([]byte(providedSig), []byte(expectedSig)) != 1 {
		return false, fmt.Errorf("token signature mismatch")
	}

	return true, nil
}

// CanonicalizeManifestPayload serializes the manifest map without signature into sorted JSON
func CanonicalizeManifestPayload(rawMap map[string]interface{}) ([]byte, error) {
	payload := make(map[string]interface{}, len(rawMap))
	for k, v := range rawMap {
		if k != "signature" {
			payload[k] = v
		}
	}
	// In Go, json.Marshal on map[string]interface{} recursively sorts dictionary keys
	return json.Marshal(payload)
}

// VerifyManifestSignature checks the Ed25519 signature of the manifest
func VerifyManifestSignature(rawMap map[string]interface{}, trustedKeys [][]byte) error {
	sigVal, exists := rawMap["signature"]
	if !exists {
		return fmt.Errorf("manifest has no signature property")
	}

	sigB64, ok := sigVal.(string)
	if !ok || sigB64 == "" {
		return fmt.Errorf("manifest signature is not a valid non-empty string")
	}

	sigBytes, err := base64.StdEncoding.DecodeString(sigB64)
	if err != nil {
		return fmt.Errorf("invalid base64 signature encoding: %w", err)
	}

	if len(sigBytes) != ed25519.SignatureSize {
		return fmt.Errorf("invalid Ed25519 signature length (%d bytes, expected 64)", len(sigBytes))
	}

	canonBytes, err := CanonicalizeManifestPayload(rawMap)
	if err != nil {
		return fmt.Errorf("failed canonicalizing manifest payload: %w", err)
	}

	for _, pubKey := range trustedKeys {
		if len(pubKey) == ed25519.PublicKeySize && ed25519.Verify(pubKey, canonBytes, sigBytes) {
			return nil
		}
	}

	return fmt.Errorf("Ed25519 signature verification failed against all trusted public keys")
}

// VerifyIntegrity performs the full pre-launch cryptographic verification
func VerifyIntegrity(manifestPath, resourcesDir string, trustedKeys [][]byte) (*VerificationResult, error) {
	// 1. Check manifest file presence
	manifestBytes, err := os.ReadFile(manifestPath)
	if err != nil {
		return nil, &SecurityViolationError{
			Code:    "ERR_MISSING_MANIFEST",
			Message: fmt.Sprintf("resources/integrity.manifest.json not found at '%s'", manifestPath),
		}
	}

	// 2. Parse JSON
	var rawMap map[string]interface{}
	if err := json.Unmarshal(manifestBytes, &rawMap); err != nil {
		return nil, &SecurityViolationError{
			Code:    "ERR_CORRUPTED_MANIFEST",
			Message: fmt.Sprintf("Failed to parse integrity manifest JSON: %v", err),
		}
	}

	// 3. Verify digital signature
	if err := VerifyManifestSignature(rawMap, trustedKeys); err != nil {
		return nil, &SecurityViolationError{
			Code:    "ERR_INVALID_SIGNATURE",
			Message: err.Error(),
		}
	}

	sigB64 := rawMap["signature"].(string)
	version, _ := rawMap["version"].(string)
	timestamp, _ := rawMap["timestamp"].(string)

	// 4. Extract files map
	filesRaw, ok := rawMap["files"].(map[string]interface{})
	if !ok || len(filesRaw) == 0 {
		return nil, &SecurityViolationError{
			Code:    "ERR_CORRUPTED_MANIFEST",
			Message: "Manifest does not contain a valid 'files' dictionary",
		}
	}

	// 5. Verify streaming SHA-256 for all listed files
	verifiedCount := 0
	for relPath, hashVal := range filesRaw {
		expectedHash, ok := hashVal.(string)
		if !ok || expectedHash == "" {
			return nil, &SecurityViolationError{
				Code:    "ERR_CORRUPTED_MANIFEST",
				Message: fmt.Sprintf("Invalid expected hash for '%s'", relPath),
			}
		}

		// Enforce Path Traversal Confinement
		cleanRel := filepath.Clean(filepath.FromSlash(relPath))
		if filepath.IsAbs(cleanRel) || strings.HasPrefix(cleanRel, "..") || strings.HasPrefix(cleanRel, string(filepath.Separator)) {
			return nil, &SecurityViolationError{
				Code:    "ERR_PATH_TRAVERSAL",
				Message: fmt.Sprintf("Illegal path traversal detected in manifest: '%s'", relPath),
			}
		}
		fullPath := filepath.Join(resourcesDir, cleanRel)

		if _, err := os.Stat(fullPath); os.IsNotExist(err) {
			// Check parent directory of resources (e.g. appOutDir for 2toolne-runtime.exe)
			parentCandidate := filepath.Join(filepath.Dir(resourcesDir), cleanRel)
			if stat, err2 := os.Stat(parentCandidate); err2 == nil && !stat.IsDir() {
				fullPath = parentCandidate
			} else if runtime.GOOS == "darwin" {
				// macOS app bundle: Contents/MacOS/2toolne-runtime
				macCandidate := filepath.Join(filepath.Dir(resourcesDir), "MacOS", strings.TrimSuffix(cleanRel, ".exe"))
				if stat, err3 := os.Stat(macCandidate); err3 == nil && !stat.IsDir() {
					fullPath = macCandidate
				}
			}
		}

		if _, err := os.Stat(fullPath); os.IsNotExist(err) {
			return nil, &SecurityViolationError{
				Code:    "ERR_MISSING_BINARY",
				Message: fmt.Sprintf("Critical component file missing from package: %s", relPath),
			}
		}

		actualHash, err := ComputeFileSha256(fullPath)
		if err != nil {
			return nil, &SecurityViolationError{
				Code:    "ERR_HASH_FAILED",
				Message: fmt.Sprintf("Failed computing SHA-256 for '%s': %v", relPath, err),
			}
		}

		if !strings.EqualFold(actualHash, expectedHash) {
			return nil, &SecurityViolationError{
				Code:    "ERR_HASH_MISMATCH",
				Message: fmt.Sprintf("SHA-256 mismatch for '%s': expected %s, got %s", relPath, expectedHash, actualHash),
			}
		}

		verifiedCount++
	}

	// Enforce CriticalReleaseFiles Membership: assert that manifest.files contains all 7 critical files
	for _, reqFile := range CriticalReleaseFiles {
		if _, exists := filesRaw[reqFile]; !exists {
			return nil, &SecurityViolationError{
				Code:    "ERR_CORRUPTED_MANIFEST",
				Message: fmt.Sprintf("Critical release file '%s' missing from manifest declaration", reqFile),
			}
		}
	}

	// 6. Generate ephemeral bootstrap handshake token
	token, err := GenerateBootstrapToken(sigB64)
	if err != nil {
		return nil, &SecurityViolationError{
			Code:    "ERR_TOKEN_GENERATION_FAILED",
			Message: fmt.Sprintf("Failed generating bootstrap handshake token: %v", err),
		}
	}

	return &VerificationResult{
		FilesChecked:      verifiedCount,
		Version:           version,
		Timestamp:         timestamp,
		ManifestSignature: sigB64,
		BootstrapToken:    token,
	}, nil
}
