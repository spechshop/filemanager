#!/usr/bin/env python3
"""Compare upload finalization with a git revision, using private local fixtures.
Does not measure network transfer or touch the running server.
"""
import argparse
import json
from pathlib import Path
import statistics
import subprocess
import tempfile
import time

ROOT = Path(__file__).resolve().parents[2]
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--baseline', default='HEAD', help='git revision before optimization')
parser.add_argument('--runs', type=int, default=3)
args = parser.parse_args()
if args.runs < 1:
    parser.error('--runs must be positive')
with tempfile.TemporaryDirectory(prefix='fm-upload-bench-') as temporary:
    base = Path(temporary)
    uploads, destination = base / 'uploads', base / 'destination'
    uploads.mkdir()
    destination.mkdir()
    tokens = base / 'tokens.json'
    tokens.write_text(json.dumps({'fixture': {'expire': int(time.time()) + 3600, 'allowedRoots': [str(destination)]}}))
    baseline = base / 'before.py'
    baseline.write_bytes(subprocess.check_output(['git', 'show', args.baseline + ':scripts/security/fs-broker.py'], cwd=ROOT))
    brokers = [('before', baseline), ('after', ROOT / 'scripts/security/fs-broker.py')]
    common = dict(token='fixture', tokenFile=str(tokens), base=str(base), uploadDirectory=str(uploads))
    for scenario, count, size in [('small-files', 100, 4096), ('large-file', 1, 64 * 1024 * 1024)]:
        entries = []
        for index in range(count):
            source = uploads / str(index)
            source.write_bytes(b'x' * size)
            entries.append(dict(source=str(source), target=str(destination / str(index))))
        times = {label: [] for label, _ in brokers}
        # Alternate versions to reduce cache/order bias. Includes helper startup.
        for run in range(args.runs):
            for label, broker in brokers[::1 if run % 2 == 0 else -1]:
                start = time.perf_counter()
                requests = ([dict(op='upload', path=e['source'], target=e['target']) for e in entries]
                            if label == 'before' else [dict(op='upload-many', path='', uploads=entries)])
                for request in requests:
                    result = subprocess.run(['python3', '-I', str(broker)], input=json.dumps({**common, **request}) + '\n', text=True, capture_output=True, check=True)
                    if json.loads(result.stdout).get('ok') is not True:
                        raise RuntimeError(result.stdout)
                times[label].append(time.perf_counter() - start)
                for entry in entries:
                    target = Path(entry['target'])
                    if target.read_bytes() != Path(entry['source']).read_bytes():
                        raise RuntimeError('Upload content mismatch')
                    target.unlink()
        medians = {label: round(statistics.median(values), 4) for label, values in times.items()}
        print(json.dumps(dict(scenario=scenario, files=count, bytes_per_file=size, seconds=medians,
                              speedup=round(medians['before'] / medians['after'], 2))), flush=True)
