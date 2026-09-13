<?php
$root=$argv[1];$source=file_get_contents($argv[2].'/plugins/Start/server/fileWatcher.php');
$GLOBALS['counts']=['visited'=>0,'hashes'=>0,'directories'=>0];
function probeScandir($path){$GLOBALS['counts']['directories']++;return scandir($path);}
function probeHash($algo,$path){$GLOBALS['counts']['hashes']++;return hash_file($algo,$path);}
$source=str_replace('@scandir(', '\\probeScandir(', $source);
$source=str_replace("            \$path = \$directory . '/' . \$name;", "            \$GLOBALS['counts']['visited']++;\n            \$path = \$directory . '/' . \$name;",$source);
$source=str_replace("@hash_file(","\\probeHash(",$source);
eval(substr($source,5));
Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
Swoole\Coroutine\run(function()use($root){
 $watcher=new plugins\Start\fileWatcher([$root.'/plugins'],$GLOBALS['argv'][3]==='empty'?[]:['php']);
 for($i=0;$i<(int)($GLOBALS['argv'][4]??10);$i++){
  $GLOBALS['counts']=['visited'=>0,'hashes'=>0,'directories'=>0];$t=hrtime(true);$r=getrusage();$changes=$watcher->changes();$s=getrusage();
  $cpu=($s['ru_utime.tv_sec']-$r['ru_utime.tv_sec']+$s['ru_stime.tv_sec']-$r['ru_stime.tv_sec'])*1e3+($s['ru_utime.tv_usec']-$r['ru_utime.tv_usec']+$s['ru_stime.tv_usec']-$r['ru_stime.tv_usec'])/1e3;
  echo json_encode(['iteration'=>$i,'wall_ms'=>(hrtime(true)-$t)/1e6,'cpu_ms'=>$cpu,'changes'=>$changes]+$GLOBALS['counts']),"\n";
 }
});
