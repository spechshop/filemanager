import os
import os,time,json,sys,statistics,pathlib
root=sys.argv[1]; duration=float(sys.argv[2]); out=sys.argv[3]; hz=os.sysconf('SC_CLK_TCK')
def snap():
 found={}
 for p in pathlib.Path('/proc').iterdir():
  if not p.name.isdigit():continue
  try:
   cwd=os.readlink(p/'cwd')
   if cwd!=root and not cwd.startswith(root+'/'):continue
   s=(p/'stat').read_text().rsplit(')',1)[1].split();cmd=(p/'cmdline').read_bytes().replace(b'\0',b' ').decode(errors='replace')
   found[p.name]={'ticks':int(s[11])+int(s[12]),'rss_kb':int(s[21])*4,'threads':int(s[17]),'fds':len(list((p/'fd').iterdir())),'state':s[0],'ppid':int(s[1]),'cmd':cmd}
  except (OSError,ValueError):pass
 return found
prev=snap();t0=last=time.monotonic();rows=[]
while time.monotonic()-t0<duration:
 time.sleep(1);now=time.monotonic();cur=snap()
 for pid,v in cur.items():
  if pid in prev:
   rows.append({'t':round(now-t0,3),'pid':pid,'cpu':100*(v['ticks']-prev[pid]['ticks'])/hz/(now-last),**v})
 prev=cur;last=now
pathlib.Path(out).write_text(json.dumps(rows,indent=2))
for pid in sorted(set(r['pid'] for r in rows)):
 a=[r for r in rows if r['pid']==pid];c=sorted(r['cpu'] for r in a)
 print(pid, a[-1]['cmd'], 'n=',len(a),'mean=',round(statistics.mean(c),3),'p95=',round(c[int((len(c)-1)*.95)],3),'p99=',round(c[int((len(c)-1)*.99)],3),'fds=',(min(r['fds'] for r in a),max(r['fds'] for r in a)))
