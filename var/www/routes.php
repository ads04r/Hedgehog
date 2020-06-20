<?php

$f3->route("GET /", function($f3)
{
        date_default_timezone_set("Europe/London");

        $template = new Template();
        $f3->set('page_template', "templates/pages/dashboard.html");
        echo $template->render($f3->get('brand_file'));
});

$f3->route("GET /datasets.html", function($f3)
{
        date_default_timezone_set("Europe/London");
        $db = $f3->get('database');
        
        $data = array();
        if($db)
        {
            $res = $db->query("SELECT * FROM quills ORDER BY id ASC");
            while($row = $res->fetch_assoc())
            {
                $data[] = $row;
            }
            $res->close();
        }

        $template = new Template();
        $f3->set('page_title', "Datasets");
        $f3->set('page_template', "templates/pages/datasets.html");
        $f3->set('page_data', $data);
        echo $template->render($f3->get('brand_file'));
});

$f3->route("GET /templates.html", function($f3)
{
        date_default_timezone_set("Europe/London");
        $db = $f3->get('database');
        
        $data = array();
        if($db)
        {
            $res = $db->query("SELECT class AS id, CONCAT(prefix.uri, uris.name) AS uri, template FROM templates, uris, prefix WHERE uris.id=templates.class AND uris.prefix=prefix.id ORDER BY uri ASC;");
            while($row = $res->fetch_assoc())
            {
                $data[] = $row;
            }
            $res->close();
        }

        $template = new Template();
        $f3->set('page_title', "Class Templates");
        $f3->set('page_template', "templates/pages/templates.html");
        $f3->set('page_data', $data);
        echo $template->render($f3->get('brand_file'));
});

$f3->route("GET /datasets/@id.html", function($f3, $params)
{
        date_default_timezone_set("Europe/London");
        $db = $f3->get('database');

	$query = "SELECT * FROM quills WHERE id='" . $db->escape_string($params['id']) . "';";
	$data = array();
        $res = $db->query($query);
        if($row = $res->fetch_assoc())
        {
            $data = $row;
        }
        $res->close();

	$query = "select * from (select COUNT(DISTINCT s) as subjects from triples where quill='" . $db->escape_string($params['id']) . "') as x, (select COUNT(*) as triples from triples where quill='" . $db->escape_string($params['id']) . "') as y";
	$data['stats'] = array();
        $res = $db->query($query);
        if($row = $res->fetch_assoc())
        {
            $data['stats'] = $row;
        }
        $res->close();

	$query = "select distinct po.uri, uo.name from triples, uris as up, uris as uo, prefix as pp, prefix as po where quill='" . $db->escape_string($params['id']) . "' and p=up.id and o=uo.id and up.prefix=pp.id and uo.prefix=po.id and pp.uri='http://www.w3.org/1999/02/22-rdf-syntax-ns#' and up.name='type' order by uri, name";
	$data['stats']['classes'] = array();
        $res = $db->query($query);
        while($row = $res->fetch_assoc())
        {
            $data['stats']['classes'][] = $row['uri'] . $row['name'];
        }
        $res->close();

	$query = "select distinct * from (select distinct prefix.* from triples, uris, prefix where quill='" . $db->escape_string($params['id']) . "' and s = uris.id and uris.prefix=prefix.id and prefix.prefix<>'' union select distinct prefix.* from triples, uris, prefix where quill='" . $db->escape_string($params['id']) . "' and p = uris.id and uris.prefix=prefix.id and prefix.prefix<>'' union select distinct prefix.* from triples, uris, prefix where quill='" . $db->escape_string($params['id']) . "' and o = uris.id and uris.prefix=prefix.id and prefix.prefix<>'') as x order by uri ASC";
	$data['stats']['vocabularies'] = array();
        $res = $db->query($query);
        while($row = $res->fetch_assoc())
        {
            $data['stats']['vocabularies'][] = $row['uri'] . $row['name'];
        }
        $res->close();

        $template = new Template();
        $f3->set('page_title', $params['id']);
        $f3->set('page_class', "Datasets");
        $f3->set('page_template', "templates/pages/dataset.html");
        $f3->set('page_data', $data);
        echo $template->render($f3->get('brand_file'));
});

