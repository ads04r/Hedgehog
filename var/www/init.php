<?php

$page_load_start = microtime(true);
$base_path = dirname(dirname(dirname(__FILE__)));
$lib_path = $base_path . "/lib";
$etc_path = $base_path . "/etc";
$www_path = $base_path . "/var/www";
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

$f3 = require($lib_path . "/fatfree/lib/base.php");

$f3->set('page_load_start', $page_load_start);
$f3->set('DEBUG', true);
$f3->set('site_title', "Hedgehog");
$f3->set('site_blurb', "RDF Publishing Tool");
$f3->set('page_title', "");
$f3->set('page_data', "");
$f3->set('error_data', array());
$f3->set('page_template', "");
$f3->set('page_content', "");
$f3->set('page_triples', 0);
$f3->set('brand_file', "templates/brand.html");
$f3->set('styles', $css);
$f3->set('scripts', $js);

$f3->route("GET /", function($f3)
{
        date_default_timezone_set("Europe/London");

        $template = new Template();
        $f3->set('page_template', "templates/home.html");
        echo $template->render($f3->get('brand_file'));
});

