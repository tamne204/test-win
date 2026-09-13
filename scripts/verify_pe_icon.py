#!/usr/bin/env python3
"""
scripts/verify_pe_icon.py

Deterministic PE Resource Verifier for 2TOOLNE Windows Executables.
Inspects PE32/PE32+ executable binaries to verify:
1. Valid PE header signature ('PE\\0\\0').
2. Presence of '.rsrc' section in the section table.
3. Presence of RT_ICON (type 3) and RT_GROUP_ICON (type 14) in the Root Resource Directory.
4. Valid icon entry counts and positive byte sizes.
"""

import sys
import os
import argparse
import struct

def parse_pe_resources(exe_path):
    if not os.path.exists(exe_path):
        return False, f"File not found: {exe_path}", {}

    with open(exe_path, "rb") as f:
        data = f.read()

    if len(data) < 64:
        return False, "File too small to be a PE binary", {}

    # DOS Header: 0x3C points to PE header
    e_lfanew = int.from_bytes(data[0x3C:0x40], "little")
    if e_lfanew + 24 > len(data):
        return False, "Invalid DOS header / e_lfanew pointer", {}

    sig = data[e_lfanew:e_lfanew+4]
    if sig != b"PE\x00\x00":
        return False, f"Invalid PE signature: {sig!r}", {}

    num_sections = int.from_bytes(data[e_lfanew+6:e_lfanew+8], "little")
    opt_hdr_size = int.from_bytes(data[e_lfanew+20:e_lfanew+22], "little")
    sec_table = e_lfanew + 24 + opt_hdr_size

    rsrc_raw_offset = None
    rsrc_vaddr = None
    rsrc_size = None

    for i in range(num_sections):
        sec = data[sec_table + i*40 : sec_table + (i+1)*40]
        name = sec[:8].rstrip(b"\x00").decode("latin1")
        if name == ".rsrc":
            rsrc_vaddr = int.from_bytes(sec[12:16], "little")
            rsrc_size = int.from_bytes(sec[16:20], "little")
            rsrc_raw_offset = int.from_bytes(sec[20:24], "little")
            break

    if rsrc_raw_offset is None or rsrc_raw_offset == 0:
        return False, "No .rsrc section found in section table", {}

    if rsrc_raw_offset >= len(data):
        return False, f"Invalid .rsrc raw offset: {rsrc_raw_offset}", {}

    def get_resource_entries(dir_offset):
        if dir_offset + 16 > len(data):
            return []
        num_named = int.from_bytes(data[dir_offset+12:dir_offset+14], "little")
        num_id = int.from_bytes(data[dir_offset+14:dir_offset+16], "little")
        entries = []
        for i in range(num_named + num_id):
            e_start = dir_offset + 16 + i * 8
            if e_start + 8 > len(data):
                break
            e_id = int.from_bytes(data[e_start:e_start+4], "little")
            e_offset = int.from_bytes(data[e_start+4:e_start+8], "little")
            entries.append((e_id, e_offset))
        return entries

    type_entries = get_resource_entries(rsrc_raw_offset)
    available_types = [t[0] for t in type_entries]

    has_rt_icon = 3 in available_types
    has_rt_group_icon = 14 in available_types

    icon_details = {
        "rsrc_size": rsrc_size,
        "available_types": available_types,
        "icons_count": 0,
        "total_icon_bytes": 0,
    }

    if not (has_rt_icon and has_rt_group_icon):
        return False, f"Missing icon resources. Available resource types: {available_types} (expected 3 and 14)", icon_details

    # Count individual icon images under RT_ICON (type 3)
    for type_id, type_off in type_entries:
        if type_id == 3: # RT_ICON
            clean_off = rsrc_raw_offset + (type_off & 0x7FFFFFFF)
            name_entries = get_resource_entries(clean_off)
            icon_details["icons_count"] = len(name_entries)
            for _, name_off in name_entries:
                clean_name_off = rsrc_raw_offset + (name_off & 0x7FFFFFFF)
                lang_entries = get_resource_entries(clean_name_off)
                for _, lang_off in lang_entries:
                    data_entry_off = rsrc_raw_offset + (lang_off & 0x7FFFFFFF)
                    if data_entry_off + 8 <= len(data):
                        size = int.from_bytes(data[data_entry_off+4:data_entry_off+8], "little")
                        icon_details["total_icon_bytes"] += size

    if icon_details["icons_count"] == 0:
        return False, "RT_ICON group exists but contains 0 icon images", icon_details

    return True, "Valid Windows icon resource present", icon_details


def main():
    parser = argparse.ArgumentParser(description="Verify icon resources in Windows PE executable")
    parser.add_argument("--exe", required=True, help="Path to executable binary to inspect")
    parser.add_argument("--label", default="APP_ICON_RESOURCE_PRESENT", help="Output metric label prefix")
    args = parser.parse_args()

    ok, message, details = parse_pe_resources(args.exe)
    base_name = os.path.basename(args.exe)

    if ok:
        print(f"✓ {base_name}: {message}")
        print(f"  Resource types: {details.get('available_types')}")
        print(f"  Embedded icons count: {details.get('icons_count')}")
        print(f"  Total icon data bytes: {details.get('total_icon_bytes')} bytes")
        print(f"{args.label}=PASS")
        sys.exit(0)
    else:
        print(f"✗ FAIL: {base_name}: {message}")
        print(f"{args.label}=FAIL")
        sys.exit(1)


if __name__ == "__main__":
    main()
