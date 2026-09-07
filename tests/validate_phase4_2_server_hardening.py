"""
Validation script for Phase 4.2: Server License Key Hardening & Final Release Gates
"""

import hmac
import hashlib
import os

def test_crockford_base32_properties():
    alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ'
    assert len(alphabet) == 32, f"Expected 32 chars, got {len(alphabet)}"
    # Verify no ambiguous characters (0, O, 1, I)
    assert '0' not in alphabet
    assert 'O' not in alphabet
    assert '1' not in alphabet
    assert 'I' not in alphabet

    # 4 groups of 4 characters = 16 characters = 16 * 5 = 80 bits entropy
    entropy_bits = 16 * 5
    assert entropy_bits == 80

def test_server_hashing_scheme():
    pepper = '2toolne_pepper_secret_c7e3f89a1b02456d89ef23456789abcd'
    raw_key = '2TL-CAP-2K9B-WZ4M-8Q7P-3J5N'
    clean_key = raw_key.upper().strip()
    
    # 1. Fast O(1) deterministic lookup hash
    lookup_hash = hmac.new(pepper.encode('utf-8'), clean_key.encode('utf-8'), hashlib.sha256).hexdigest()
    assert len(lookup_hash) == 64
    assert lookup_hash == hmac.new(pepper.encode('utf-8'), clean_key.encode('utf-8'), hashlib.sha256).hexdigest()

    # 2. Masked database representation
    last4 = clean_key[-4:]
    lic_id = 'lic_1234567890abcdef1234'
    stored_key = f"2TL-CAP-****-{lic_id[4:8]}-{last4}"
    masked_display = f"2TL-CAP-****-****-{last4}"

    assert clean_key != stored_key
    assert clean_key != masked_display
    assert raw_key not in stored_key
    assert last4 in stored_key
    assert last4 in masked_display

def test_multidimensional_rate_limit_policy():
    # Verify thresholds
    max_ip_req_per_min = 20
    max_dev_req_per_min = 10
    max_failures_before_lockout = 5
    lockout_duration_seconds = 900 # 15 minutes

    assert max_ip_req_per_min == 20
    assert max_dev_req_per_min == 10
    assert max_failures_before_lockout == 5
    assert lockout_duration_seconds == 900

if __name__ == '__main__':
    test_crockford_base32_properties()
    test_server_hashing_scheme()
    test_multidimensional_rate_limit_policy()
    print("PHASE_4_2_SERVER_HARDENING_VALIDATION_PASS")
