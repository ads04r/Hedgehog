<?php

function splitCamelCase($input)
{
    return preg_split(
        '/(^[^A-Z]+|[A-Z][^A-Z]+)/',
        $input,
        -1, /* no limit for replacement count */
        PREG_SPLIT_NO_EMPTY /*don't return empty elements*/
            | PREG_SPLIT_DELIM_CAPTURE /*don't strip anything from output array*/
    );
}

$f3->route("GET /", function($f3)
{
        date_default_timezone_set("Europe/London");

        $template = new Template();
        $f3->set('page_template', "templates/pages/dashboard.html");
        echo $template->render($f3->get('brand_file'));
});

$f3->route("POST /settings.html", function($f3)
{
	$next_url = "/";
	$data = $_POST;
	if(array_key_exists("url", $data))
	{
		$next_url = $data['url'];
		unset($data['url']);
	}
        $db = $f3->get('database');
	if($db)
	{
		foreach($data as $k => $v)
		{
			$query = "INSERT INTO settings (`key`, `value`) values ('" . $db->escape_string($k) . "', '" . $db->escape_string($v) . "') ON DUPLICATE KEY UPDATE `value`='" . $db->escape_string($v) . "';";
			$db->query($query);
		}
	}

	$f3->reroute($next_url);
});

$f3->route("POST /vocabulary/@id.html", function($f3, $params)
{
	$data = $_POST;
	$data['id'] = $params['id'];

	if(array_key_exists("id", $data) && array_key_exists("vocab-description", $data) && array_key_exists("vocab-label", $data))
	{

	        $db = $f3->get('database');
		if($db)
		{
			$query = "INSERT INTO vocabulary (uri, label, description) ";
			$query .= "VALUES ('" . $db->escape_string($data['id']) . "', '" . $db->escape_string($data['vocab-label']) . "', '" . $db->escape_string($data['vocab-description']) . "') ";
			$query .= "ON DUPLICATE KEY UPDATE label='" . $db->escape_string($data['vocab-label']) . "', description='" . $db->escape_string($data['vocab-description']) . "';";
			$db->query($query);
		}

		$f3->reroute("/vocabulary.html");

	} else {

		$f3->error(404);

	}
});

$f3->route("GET /virtual.html", function($f3, $params)
{
	$data = array("vdataset_vocabulary" => "", "vdataset_datacatalog" => "");
        $db = $f3->get('database');
	if($db)
	{
		$query = "SELECT * FROM settings WHERE `key` LIKE 'vdataset_%';";
		$res = $db->query($query);
		while($row = $res->fetch_assoc())
		{
			$data[$row['key']] = $row['value'];
		}
		$res->close();
	}

        $template = new Template();
        $f3->set('page_title', "Virtual Datasets");
	$f3->set('page_class', "Settings");
        $f3->set('page_template', "templates/pages/virtual.html");
        $f3->set('page_data', $data);
        echo $template->render($f3->get('brand_file'));
});

$f3->route("GET /vocabulary/@id.html", function($f3, $params)
{
        date_default_timezone_set("Europe/London");
        $db = $f3->get('database');

	$query = "SELECT uris.*, prefix.uri as prefix FROM uris, prefix WHERE uris.prefix=prefix.id AND uris.id='" . $db->escape_string($params['id']) . "';";
	$data = array("name"=>$params['id']);
        $res = $db->query($query);
        if($row = $res->fetch_assoc())
        {
            $data = $row;
            $data['uri'] = $data['prefix'] . $data['name'];
        }
        $res->close();

	$query = "SELECT * FROM vocabulary WHERE uri='" . $db->escape_string($params['id']) . "';";
        $res = $db->query($query);
        if($row = $res->fetch_assoc())
        {
            $data['label'] = $row['label'];
            $data['description'] = $row['description'];
        }
        $res->close();

	if(!(array_key_exists("label", $data))) { $data['label'] = ucfirst(trim(implode(" ", splitCamelCase($data['name'])))); }

	$title = $data['label'];

        $template = new Template();
        $f3->set('page_title', $title);
	$f3->set('page_class', "Vocabulary");
        $f3->set('page_template', "templates/pages/vocabularyitem.html");
        $f3->set('page_data', $data);
        echo $template->render($f3->get('brand_file'));
});

$f3->route("GET /vocabulary.html", function($f3)
{
        date_default_timezone_set("Europe/London");
        $db = $f3->get('database');

	$vid = "vocabulary";
	$query = "SELECT `value` FROM settings WHERE `key`='vdataset_vocabulary';";
	$res = $db->query($query);
	if($row = $res->fetch_assoc())
	{
		$vid = $row['value'];
	}
	$res->free();

	$vocab = array();

        $data = array("id" => "", "uri" => 0, "uris" => array(), "classes" => array(), "properties" => array());
        if($db)
        {
            $res = $db->query("SELECT value FROM settings WHERE `key`='vocabulary_id';");
            if($row = $res->fetch_assoc())
            {
                $data["id"] = $row['value'];
            }
            $res->close();

            $res = $db->query("SELECT value FROM settings WHERE `key`='vocabulary_uri';");
            if($row = $res->fetch_assoc())
            {
                $data["uri"] = $row['value'];
            }
            $res->close();

            $res = $db->query("SELECT vocabulary.* FROM uris, vocabulary WHERE vocabulary.uri=uris.id AND prefix='" . $data['uri'] . "'");
            while($row = $res->fetch_assoc())
            {
		$id = $row['uri'];
		$vocab[$id] = $row;
            }
            $res->close();

            $res = $db->query("SELECT id, prefix, uri FROM prefix WHERE prefix<>'';");
            while($row = $res->fetch_assoc())
            {
                $data["uris"][] = $row;
            }
            $res->close();

            $property_ids = array();
            $res = $db->query("SELECT DISTINCT uris.* FROM triples, uris WHERE triples.quill<>'" . $db->escape_string($vid) . "' AND triples.p=uris.id AND uris.prefix='" . $data['uri'] . "' ORDER BY name ASC");
            while($row = $res->fetch_assoc())
            {
		$id = $row['id'];
                if(!(in_array($id, $property_ids))) { $property_ids[] = $id; }
		if(array_key_exists($id, $vocab))
		{
			$row['label'] = $vocab[$id]['label'];
			$row['description'] = "" . $vocab[$id]['description'];
		} else {
			$row['label'] = ucfirst(trim(implode(" ", splitCamelCase($row['name']))));
			$row['description'] = "";
		}
                $data["properties"][] = $row;
            }
            $res->close();

            $res = $db->query("SELECT DISTINCT uris.* FROM triples, uris WHERE triples.quill<>'" . $db->escape_string($vid) . "' AND (triples.s=uris.id OR triples.o=uris.id) AND uris.prefix='" . $data['uri'] . "' ORDER BY name ASC");
            while($row = $res->fetch_assoc())
            {
		$id = $row['id'];
                if(in_array($id, $property_ids)) { continue; }
		if(array_key_exists($id, $vocab))
		{
			$row['label'] = $vocab[$id]['label'];
			$row['description'] = "" . $vocab[$id]['description'];
		} else {
			$row['label'] = trim(implode(" ", splitCamelCase($row['name'])));
			$row['description'] = "";
		}
                $data["classes"][] = $row;
            }
            $res->close();
        }

        $template = new Template();
        $f3->set('page_title', "Vocabulary");
        $f3->set('page_template', "templates/pages/vocabulary.html");
        $f3->set('page_data', $data);
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

$f3->route("GET /templates/@id.html", function($f3, $params)
{
        date_default_timezone_set("Europe/London");
        $db = $f3->get('database');

	$query = "SELECT prefix.prefix, prefix.uri, uris.name, templates.template FROM uris, prefix, templates WHERE class='" . $db->escape_string($params['id']) . "' and templates.class=uris.id and uris.prefix=prefix.id;";
	$data = array();
        $res = $db->query($query);
        if($row = $res->fetch_assoc())
        {
            $data = $row;
        }
        $res->close();

	$title = $data['uri'] . $data['name'];
	if(strlen($data['prefix']) > 0) { $title = $data['prefix'] . ":" . $data['name']; }

        $template = new Template();
        $f3->set('page_title', $title);
        $f3->set('page_class', "Class Templates");
        $f3->set('page_template', "templates/pages/template.html");
        $f3->set('page_data', $data);
        echo $template->render($f3->get('brand_file'));
});

$f3->route("POST /templates/@id.html", function($f3, $params)
{
        $db = $f3->get('database');

	$url = "/templates/" . $params['id'] . ".html";
	$query = "UPDATE templates SET template='" . $db->escape_string($_POST['template']) . "' WHERE class='" . $db->escape_string($params['id']) . "';";
	$db->query($query);

	$f3->reroute($url);
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

	$query = "select distinct prefix.uri, uris.name from triples, uris, prefix where quill='" . $db->escape_string($params['id']) . "' and triples.p=uris.id and uris.prefix=prefix.id order by uri, name";
	$data['stats']['properties'] = array();
        $res = $db->query($query);
        while($row = $res->fetch_assoc())
        {
            $data['stats']['properties'][] = $row['uri'] . $row['name'];
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

