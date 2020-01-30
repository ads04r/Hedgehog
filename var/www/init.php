<?php

$page_load_start = microtime(true);
$base_path = dirname(dirname(dirname(__FILE__)));
$lib_path = $base_path . "/lib";
$etc_path = $base_path . "/etc";
$usr_path = $base_path . "/usr";
$var_path = $base_path . "/var";
$www_path = $var_path . "/www";
$config = array();
$js = array();
$css = array();

if(file_exists($lib_path . "/arc2/ARC2.php")) { include_once($lib_path . "/arc2/ARC2.php"); }
if(file_exists($lib_path . "/graphite/Graphite.php")) { include_once($lib_path . "/graphite/Graphite.php"); }
if(file_exists($lib_path . "/valium/valium.php")) { include_once($lib_path . "/valium/valium.php"); }
foreach(array("styles/font-awesome/css/font-awesome.min.css","styles/ionicons/ionicons.min.css","styles/bootstrap/bootstrap.min.css","scripts/adminlte/css/adminlte.min.css","scripts/jquery-mobile/jquery.mobile.theme-1.4.5.min.css") as $file)
{
	if(!(file_exists($www_path . "/" . $file))) { continue; }
	$css[] = "/" . $file;
}
foreach(array("scripts/jquery/jquery.min.js","scripts/popper/popper.min.js","scripts/bootstrap/bootstrap.min.js","scripts/chart/chart.bundle.min.js","scripts/adminlte/js/adminlte.min.js") as $file)
{
	if(!(file_exists($www_path . "/" . $file))) { continue; }
	$js[] = "/" . $file;
}

$dp = opendir($etc_path);
while($file = readdir($dp))
{
    if(preg_match("/\\.json$/", $file) == 0) { continue; }
    $key = strtolower(preg_replace("/\\.json$/", "", $file));
    $value = json_decode(file_get_contents($etc_path . "/" . $file), true);
    if(!(is_array($value))) { continue; }
    $config[$key] = $value;
}
closedir($dp);

$db = False;
if(array_key_exists("database", $config))
{
    $db = new mysqli($config['database']['host'], $config['database']['user'], $config['database']['password'], $config['database']['database'], $config['database']['port']);
}

$css[] = "/styles/hedgehog.css";

