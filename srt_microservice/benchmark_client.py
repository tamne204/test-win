"""
CLI Benchmark Tool for SRT Forced Alignment Microservice
Evaluates latency, throughput, Real-Time Factor (RTF), and VPS concurrency.
"""

import os
import sys
import time
import argparse
import requests
from concurrent.futures import ThreadPoolExecutor, as_completed


def run_single_benchmark(server_url: str, audio_path: str, script_text: str, req_id: int):
    t0 = time.time()
    try:
        with open(audio_path, 'rb') as f_audio:
            files = {'audio': (os.path.basename(audio_path), f_audio, 'audio/wav')}
            data = {'script_text': script_text, 'language': 'auto', 'engine': 'whisperx'}
            
            resp = requests.post(f"{server_url.rstrip('/')}/api/v1/align", files=files, data=data, timeout=120)
            
        t1 = time.time()
        elapsed = round(t1 - t0, 3)
        
        if resp.status_code == 200:
            res_json = resp.json()
            dur = res_json.get('audio_duration_sec', 0)
            rtf = res_json.get('real_time_factor', 'N/A')
            count = res_json.get('count', 0)
            server_ram = res_json.get('server_benchmark', {}).get('ram_used_mb', 'N/A')
            return {
                'id': req_id,
                'status': '✅ PASS',
                'audio_dur': f"{dur:.1f}s",
                'latency': f"{elapsed:.2f}s",
                'rtf': rtf,
                'cues_matched': count,
                'server_ram': f"{server_ram}MB"
            }
        else:
            return {
                'id': req_id,
                'status': f"❌ FAIL ({resp.status_code})",
                'audio_dur': '-',
                'latency': f"{elapsed:.2f}s",
                'rtf': '-',
                'cues_matched': 0,
                'server_ram': '-'
            }
    except Exception as e:
        return {
            'id': req_id,
            'status': f"❌ ERR ({str(e)[:15]})",
            'audio_dur': '-',
            'latency': f"{round(time.time()-t0, 2)}s",
            'rtf': '-',
            'cues_matched': 0,
            'server_ram': '-'
        }


def main():
    parser = argparse.ArgumentParser(description="Benchmark SRT Microservice Performance")
    parser.add_argument("--server", default="http://127.0.0.1:8000", help="Microservice API URL")
    parser.add_argument("--audio", required=True, help="Path to audio file (.wav/.mp3)")
    parser.add_argument("--script", required=True, help="Path to text script (.txt) or string")
    parser.add_argument("--concurrency", type=int, default=1, help="Number of concurrent requests")
    args = parser.parse_args()

    # Read script
    if os.path.isfile(args.script):
        with open(args.script, 'r', encoding='utf-8') as f:
            script_text = f.read().strip()
    else:
        script_text = args.script.strip()

    if not os.path.isfile(args.audio):
        print(f"❌ Error: Audio file '{args.audio}' not found.")
        sys.exit(1)

    print("=========================================================================")
    print(f"🚀 BENCHMARKING SRT MICROSERVICE: {args.server}")
    print(f"🎯 Audio: {args.audio} | Concurrency: {args.concurrency}")
    print("=========================================================================")

    t_start = time.time()
    results = []
    with ThreadPoolExecutor(max_workers=args.concurrency) as executor:
        futures = [
            executor.submit(run_single_benchmark, args.server, args.audio, script_text, i + 1)
            for i in range(args.concurrency)
        ]
        for f in as_completed(futures):
            results.append(f.result())

    t_total = round(time.time() - t_start, 2)

    # Print Table
    results.sort(key=lambda x: x['id'])
    print(f"\n{'Req ID':<8} {'Status':<15} {'Audio Len':<12} {'Latency':<12} {'Speedup (RTF)':<18} {'Cues':<8} {'Server RAM'}")
    print("-" * 85)
    for r in results:
        print(f"{r['id']:<8} {r['status']:<15} {r['audio_dur']:<12} {r['latency']:<12} {r['rtf']:<18} {r['cues_matched']:<8} {r['server_ram']}")

    print("-" * 85)
    passed = sum(1 for r in results if 'PASS' in r['status'])
    print(f"📊 SUMMARY: {passed}/{len(results)} Passed in {t_total}s (Throughput: {round(len(results)/t_total, 2)} req/s)")
    print("=========================================================================\n")


if __name__ == "__main__":
    main()
