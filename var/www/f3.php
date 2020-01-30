<?php

$f3 = require($lib_path . "/fatfree/lib/base.php");

$f3->set('page_load_start', $page_load_start);
$f3->set('DEBUG', true);
$f3->set('site_title', "Hedgehog");
$f3->set('site_blurb', "RDF Publishing Tool");
$f3->set('site_config', $config);
$f3->set('page_title', "");
$f3->set('page_class', "");
$f3->set('page_data', "");
$f3->set('error_data', array());
$f3->set('page_template', "");
$f3->set('page_content', "");
$f3->set('page_triples', 0);
$f3->set('database', $db);
$f3->set('brand_file', "templates/brand.html");
$f3->set('styles', $css);
$f3->set('scripts', $js);

