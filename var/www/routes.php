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

$f3->route("GET /datasets/@id.html", function($f3, $params)
{
        date_default_timezone_set("Europe/London");

        $template = new Template();
        $f3->set('page_title', $params['id']);
        $f3->set('page_class', "Datasets");
        $f3->set('page_template', "templates/pages/datasets.html");
        echo $template->render($f3->get('brand_file'));
});

