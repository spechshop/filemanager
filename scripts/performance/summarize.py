"""Export measured samples, per-PID distributions and request results without service logs."""
import csv
import gzip
import json
import os
from pathlib import Path
import statistics
import sys

base = Path(os.environ['FILEMANAGER_BENCH'])
dest = Path(sys.argv[1])
dest.mkdir(parents=True, exist_ok=True)
excluded_path = base / 'excluded-processes.json'
excluded = json.loads(excluded_path.read_text()) if excluded_path.exists() else {}
def percentile(values, fraction):
    return sorted(values)[int((len(values) - 1) * fraction)]
def role(row, processes):
    cmd = row['cmd'].strip()
    def is_middleware_process(process):
        process_cmd = process.get('cmd', '').strip()
        return 'middleware.php' in process_cmd and not process_cmd.startswith('/bin/bash')
    if 'middleware.php' in cmd and not cmd.startswith('/bin/bash'):
        parent = processes.get(str(row['ppid']), {})
        if not is_middleware_process(parent):
            return 'master/reactor'
        grandparent = processes.get(str(parent['ppid']), {})
        return 'worker' if is_middleware_process(grandparent) else 'manager'
    if 'server.php --fix' in cmd: return 'supervisor'
    if cmd == 'node pty.js': return 'PTY Node'
    if cmd == 'php pty.php': return 'PTY PHP'
    if cmd == 'node codex-agent.js': return 'Codex bridge'
    if ' app-server ' in cmd: return 'Codex launcher' if cmd.startswith('node ') else 'Codex native'
    if cmd == 'bash': return 'shell'
    return 'instrumentation'

summaries = {}
requests = {}
with gzip.open(dest / 'samples.csv.gz', 'wt', newline='') as output:
    fields = ['scenario', 't', 'pid', 'ppid', 'role', 'included', 'cpu', 'ticks', 'rss_kb', 'threads', 'fds', 'state', 'cmd']
    writer = csv.DictWriter(output, fieldnames=fields)
    writer.writeheader()
    for path in sorted(base.glob('*.json')):
        try: data = json.loads(path.read_text())
        except (ValueError, OSError): continue
        if not isinstance(data, list) or not data or 'ticks' not in data[0]:
            if isinstance(data, dict): requests[path.name] = data
            continue
        processes = {r['pid']: r for r in data}
        groups = {}
        totals = {}
        for row in data:
            kind = role(row, processes)
            include = kind != 'instrumentation' and row['pid'] not in excluded
            writer.writerow({'scenario': path.stem, 'role': kind, 'included': int(include), **row})
            if include:
                groups.setdefault(row['pid'], []).append(row)
                totals[row['t']] = totals.get(row['t'], 0) + row['cpu']
        per_pid = []
        for pid, rows in groups.items():
            cpu = [r['cpu'] for r in rows]
            per_pid.append({'pid': pid, 'role': role(rows[-1], processes), 'cmd': rows[-1]['cmd'],
                'samples': len(rows), 'cpu_mean': statistics.mean(cpu),
                'cpu_p95': percentile(cpu, .95), 'cpu_p99': percentile(cpu, .99),
                **{name: [min(r[name] for r in rows), max(r[name] for r in rows)] for name in ['fds', 'threads', 'rss_kb']}})
        summaries[path.stem] = {'seconds': max(r['t'] for r in data), 'processes': per_pid,
            'cpu_sum_of_pid_means': sum(p['cpu_mean'] for p in per_pid),
            'cpu_sample_p95': percentile(list(totals.values()), .95),
            'cpu_sample_p99': percentile(list(totals.values()), .99)}
for path in sorted(base.glob('*.client')):
    try: requests[path.name] = json.loads(path.read_text())
    except ValueError: pass
(dest / 'cpu-summary.json').write_text(json.dumps(summaries, indent=2) + '\n')
(dest / 'requests-and-checks.json').write_text(json.dumps(requests, indent=2) + '\n')
(dest / 'excluded-processes.json').write_text(json.dumps(excluded, indent=2) + '\n')
print(f'{len(summaries)} measured windows exported to {dest}')
