<?php
$root=$argv[1];chdir($root);
class ProbeTimer { public static function tick($ms,$callback) { $callback(); } }
function probeHash($algorithm,$path){$GLOBALS['hashes']++;return hash_file($algorithm,$path);}
$source=file_get_contents($root.'/plugins/Start/server/server.php');
$source=str_replace('class server','class scannerProbe',$source);
$source=str_replace('Timer::tick(', '\\ProbeTimer::tick(', $source);
$source=str_replace('foreach ($Iterator as $path) {','foreach ($Iterator as $path) { $GLOBALS["visited"]++;',$source);
$source=str_replace('hash_file(', '\\probeHash(', $source);
$source=str_replace("dirname(__DIR__, 2) . '/configInterface.json'",var_export($root.'/plugins/configInterface.json',true),$source);
eval(substr($source,5));
require $root.'/plugins/Start/server/tableServer.php';
$GLOBALS['allowObservable']=json_decode($argv[2]??'[]',true);
$server=new Swoole\Http\Server('127.0.0.1',0);$table=new plugins\Start\tableServer();
if (getenv('BENCH_HOOKS')) Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
$run=function()use($server,$table,$root){
chdir($root);
$rounds=(int)($GLOBALS['argv'][3]??10);
for($i=0;$i<$rounds;$i++){
 clearstatcache();$GLOBALS['visited']=0;$GLOBALS['hashes']=0;$t=hrtime(true);$r=getrusage();
 plugins\Start\scannerProbe::tick($server,10000,$table);
 $s=getrusage();$cpu=($s['ru_utime.tv_sec']-$r['ru_utime.tv_sec']+$s['ru_stime.tv_sec']-$r['ru_stime.tv_sec'])*1e3+($s['ru_utime.tv_usec']-$r['ru_utime.tv_usec']+$s['ru_stime.tv_usec']-$r['ru_stime.tv_usec'])/1e3;
 echo json_encode(['iteration'=>$i,'visited'=>$GLOBALS['visited'],'hashes'=>$GLOBALS['hashes'],'wall_ms'=>(hrtime(true)-$t)/1e6,'cpu_ms'=>$cpu]),"\n";
}

};
if(getenv('BENCH_HOOKS')) \Swoole\Coroutine\run($run); else $run();
