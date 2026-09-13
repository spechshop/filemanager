<?php
chdir($argv[1]);
include 'libspech/plugins/autoloader.php';require 'vendor/autoload.php';include 'plugins/autoload.php';
$root=$argv[1];
$source=file_get_contents($root.'/plugins/Start/server/server.php');
preg_match('/Timer::tick\(1000, (function \(\) \{.*?\n        \})\);/s',$source,$m);
$callback=str_replace('__DIR__',var_export($root.'/plugins/Start/server',true),$m[1]);
$callback=str_replace('\\plugins\\Database\\call::data()', '\\probeTokens()', $callback);
$callback=str_replace('\\plugins\\Request\\controller::listPages()', '\\probePages()', $callback);
$callback=str_replace('\\plugins\\Utils\\cache\\bufferPages::get(', '\\probeBuffer(', $callback);
function probeTokens(){ $GLOBALS['counts']['tokens_reads']++;return \plugins\Database\call::data(); }
function probePages(){ $GLOBALS['counts']['directory_scans']++;return \plugins\Request\controller::listPages(); }
function probeBuffer($name,$dir){$GLOBALS['counts']['buffer_calls']++;$result=\plugins\Utils\cache\bufferPages::get($name,$dir);if($result==='what?')$GLOBALS['counts']['missing_buffer_path']++;else $GLOBALS['counts']['templates_prepared']++;return $result;}
$callback=str_replace('cache::global()', '\\plugins\\Start\\cache::global()',$callback);
$fn=eval('return '.$callback.';');
Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
Swoole\Coroutine\run(function()use($fn){
for($i=0;$i<120;$i++){
$GLOBALS['counts']=['tokens_reads'=>0,'directory_scans'=>0,'buffer_calls'=>0,'missing_buffer_path'=>0,'templates_prepared'=>0];$t=hrtime(true);$r=getrusage();$fn();$s=getrusage();
$cpu=($s['ru_utime.tv_sec']-$r['ru_utime.tv_sec']+$s['ru_stime.tv_sec']-$r['ru_stime.tv_sec'])*1e3+($s['ru_utime.tv_usec']-$r['ru_utime.tv_usec']+$s['ru_stime.tv_usec']-$r['ru_stime.tv_usec'])/1e3;
echo json_encode(['iteration'=>$i,'wall_ms'=>(hrtime(true)-$t)/1e6,'cpu_ms'=>$cpu]+$GLOBALS['counts']),"\n";
}
});
