<?php

class Echidna
{
    private $config;
    private $db;
    private $id;
    private $uri;
    private $env;
    
    private $cache;
    
    public $errors;

    function subjects($class="")
    {
        $query = "SELECT DISTINCT triples.s FROM triples, uris as pred_uris, prefix as pred_prefix, uris as obj_uris, prefix as obj_prefix WHERE o=obj_uris.id and obj_uris.prefix=obj_prefix.id and p=pred_uris.id and pred_uris.prefix=pred_prefix.id AND (CONCAT(obj_prefix.uri, obj_uris.name)='" . $this->db->escape_string($class) . "' OR CONCAT(obj_prefix.prefix, ':', obj_uris.name)='" . $this->db->escape_string($class) . "') AND CONCAT(pred_prefix.uri, pred_uris.name)='http://www.w3.org/1999/02/22-rdf-syntax-ns#type';";        
        if(strlen($class) == 0) { $query = "SELECT DISTINCT triples.s FROM triples, uris, prefix WHERE o='" . $this->id . "' AND p=uris.id AND uris.prefix=prefix.id AND CONCAT(prefix.uri, uris.name)='http://www.w3.org/1999/02/22-rdf-syntax-ns#type';"; }
        $ret = array();
        $res = $this->db->query($query);
        while($row = $res->fetch_assoc())
        {
            $ret[] = (int) $row['s'];
        }
        $res->free();

        return($ret);
    }
    
    private function idtouri($id)
    {
        if(array_key_exists("_" . $id, $this->cache)) { return($this->cache["_" . $id]); }
    
        $ret = "";
        $query = "SELECT CONCAT(prefix.uri, uris.name) AS uri FROM uris, prefix WHERE uris.prefix=prefix.id AND uris.id='" . ((int) $id) . "';";
        $res = $this->db->query($query);
        if($row = $res->fetch_assoc())
        {
            $ret = $row['uri'];
        }
        $res->free();

        if(strlen($ret) > 0)
        {
            $this->cache["_" . $id] = $ret;
            $this->cache[$ret] = $id;
        }
        return($ret);
    }
    
    private function uritoid($uri)
    {
        if(array_key_exists($uri, $this->cache)) { return($this->cache[$uri]); }

        $ret = 0;
        $query = "SELECT uris.id FROM uris, prefix WHERE uris.prefix=prefix.id AND BINARY (CONCAT(prefix.uri, uris.name)='" . $this->db->escape_string($uri) . "' OR BINARY CONCAT(prefix.prefix, ':', uris.name)='" . $this->db->escape_string($uri) . "');";
        $res = $this->db->query($query);
        if($row = $res->fetch_assoc())
        {
            $ret = (int) $row['id'];
        }
        $res->free();

        if($ret > 0)
        {
            $this->cache[$uri] = $ret;
        }
        return($ret);
    }
    
    private function graphite()
    {
        $g = new Graphite();
        $query = "SELECT prefix, uri FROM prefix WHERE prefix<>'';";
        $res = $this->db->query($query);
        while($row = $res->fetch_assoc())
        {
            $g->ns($row['prefix'], $row['uri']);
        }
        $res->free();
        return($g);
    }
    
    function export($dump_path)
    {
        foreach($this->subjects() as $rootid)
        {
            $g = $this->graphite();
            $triples = array();
            $uri = $this->idtouri($rootid);

            $md5 = md5($uri);
            $export_path = $dump_path . "/" . substr($md5, 0, 1);
            $export_file = $export_path . "/" . $md5 . ".ttl";
            error_log($export_file . ": " . $uri);

            foreach($this->_get_template($uri) as $rawxpath)
            {
                $xpath = trim($rawxpath);
                if(strlen($xpath) == 0) { continue; }
		$pathlist = explode("/", $xpath);
                foreach($this->get_triples($uri, $pathlist) as $triple)
                {
                    $triples[] = $triple;
                }
            }
            foreach($triples as $triple)
            {

                if((strlen($triple['o_text']) == 0) && (strlen($triple['o_type']) == 0))
                {
                    $g->t($triple['s'], $triple['p'], $triple['o']);
                }
                else {
                    if(strlen($triple['o_type']) == 0) { $triple['o_type'] = 'literal'; }
                    $g->t($triple['s'], $triple['p'], $triple['o_text'], $triple['o_type']);
                }
            }

            if(!(file_exists($export_path))) { mkdir($export_path, 0755, true); }
            $turtle = $g->serialize("Turtle");

            if(file_exists($export_file))
            {
                $old_content = file_get_contents($export_file);
                if(strcmp($old_content, $turtle) == 0) { continue; }
            }

            $fp = fopen($export_file, "w");
            fwrite($fp, $turtle);
            fclose($fp);
        }
        
        return(count($this->errors));
    }
    
    private function get_triples($root_uri, $path)
    {
        if(!(is_array($path))) { return(array()); }
        if(count($path) == 0) { return(array()); }
        
        $root_id = $this->uritoid($root_uri);
        $stack = array();
        $ret = array();
        foreach($path as $step)
        {
            $direction = 1;
            $item = trim($step);
            if(strcmp(substr($step, 0, 1), "-") == 0)
            {
                $direction = -1;
                $item = trim(substr($step, 1));
            }
            if(strcmp($item, "*") == 0)
            {
                $item_id = 0;
            } else {
                $item_id = $this->uritoid($item);
            }
            $stack[] = $item_id * $direction;
        }
        
        $ret = array();
        $query = "SELECT DISTINCT s, p, o, o_text, o_type FROM triples WHERE s='" . $root_id . "';";
        $res = $this->db->query($query);
        while(false != ($row = $res->fetch_assoc()))
        {
            $item = array();
            $item['s'] = $this->idtouri((int) $row['s']);
            $item['p'] = $this->idtouri((int) $row['p']);
            $item['o'] = "" . $row['o'];
            $item['o_text'] = "" . $row['o_text'];
            $item['o_type'] = "" . $row['o_type'];
            if(strlen($item['o']) > 0) { $item['o'] = $this->idtouri((int) $item['o']); }
            if(strlen($item['o_type']) > 0) { $item['o_type'] = $this->idtouri((int) $item['o_type']); }
            $ret[] = $item;
        }
        $res->free();
        
        foreach($this->_get_triples($root_id, $stack) as $row)
        {
            $item = array();
            $item['s'] = $this->idtouri((int) $row['s']);
            $item['p'] = $this->idtouri((int) $row['p']);
            $item['o'] = "" . $row['o'];
            $item['o_text'] = "" . $row['o_text'];
            $item['o_type'] = "" . $row['o_type'];
            if(strlen($item['o']) > 0) { $item['o'] = $this->idtouri((int) $item['o']); }
            if(strlen($item['o_type']) > 0) { $item['o_type'] = $this->idtouri((int) $item['o']); }
            $ret[] = $item;
        }
        return($ret);
    }
    
    private function _get_triples($root_id, $path)
    {
        if(count($path) == 0) { return(array()); }
        
        $pred_id = $path[0];
        $next_path = array_slice($path, 1);
        $next_calls = array();
        $ret = array();
        
        if($pred_id >= 0)
        {
            $query = "SELECT DISTINCT s, p, o, o_text, o_type FROM triples WHERE s='" . $root_id . "' AND p='" . $pred_id . "';";
            if($pred_id == 0) { $query = "SELECT DISTINCT s, p, o, o_text, o_type FROM triples WHERE s='" . $root_id . "';"; }
            $res = $this->db->query($query);
            while(false != ($row = $res->fetch_assoc()))
            {
                $ret[] = $row;
                $o = (int) $row['o'];
                if($o > 0) { $next_calls[] = $o; }
            }
            $res->free();
        }
        else
        {
            $query = "SELECT DISTINCT s, p, o, o_text, o_type FROM triples WHERE o='" . $root_id . "' AND p='" . (0 - $pred_id) . "';";
            $res = $this->db->query($query);
            while(false != ($row = $res->fetch_assoc()))
            {
                $ret[] = $row;
                $s = (int) $row['s'];
                if($s > 0) { $next_calls[] = $s; }
            }
            $res->free();
        }
        
        foreach($next_calls as $id)
        {
            foreach($this->_get_triples($id, $next_path) as $row)
            {
                $ret[] = $row;
            }
        }
        
        return($ret);
    }
    private function _get_template($identifier)
    {
        $id = (int) $identifier;
        if(is_string($identifier)) { $id = $this->uritoid($identifier); }
        $type_id = $this->uritoid("http://www.w3.org/1999/02/22-rdf-syntax-ns#type");

        $ret = array();
        $query = "SELECT template FROM triples, templates WHERE o=templates.class AND s='" . $id . "' AND p='" . $type_id . "';";
        $res = $this->db->query($query);
        while($row = $res->fetch_assoc())
        {
            foreach(explode("\n", $row['template']) as $path)
            {
                if(in_array($path, $ret)) { continue; }
                $ret[] = $path;
            }
        }
        $res->free();

        return($ret);
    }
    
    function __construct($type_uri)
    {
        include(dirname(dirname(dirname(__FILE__))) . "/var/www/init.php");

        $this->cache = array();
        $this->db = $db;
        $this->env = array();
        $this->env["HEDGEHOG_CONFIG_ARC2_PATH"] = $lib_path . "/arc2/ARC2.php";
        $this->env["HEDGEHOG_CONFIG_GRAPHITE_PATH"] = $lib_path . "/graphite/Graphite.php";
        foreach($_SERVER as $k => $v)
        {
            if(is_string($v)) { $this->env[$k] = $v; }
        }

        $this->errors = array();
        $this->id = 0;
        $query = "SELECT uris.id FROM uris, prefix WHERE uris.prefix=prefix.id AND (BINARY CONCAT(prefix.uri, uris.name)='" . $this->db->escape_string($type_uri) . "' OR BINARY CONCAT(prefix.prefix, ':', uris.name)='" . $this->db->escape_string($type_uri) . "');";
        $res = $this->db->query($query);
        if($row = $res->fetch_assoc())
        {
            $this->id = (int) $row['id'];
            $this->uri = $this->idtouri($this->id);
        }
        $res->free();
/*
        $config['templates'] = array();
        $query = "SELECT * FROM templates;";
        $res = $this->db->query($query);
        if($row = $res->fetch_assoc())
        {
            $id = (int) $row['class'];
            $config['templates'][$id] = explode("\n", $row['template']);
        }
        $res->free();
*/
        $this->config = $config;
    }
}
