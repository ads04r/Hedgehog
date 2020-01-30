<?php

class Echidna
{
    private $config;
    private $db;
    private $id;
    private $env;
    
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
    
    function idtouri($id)
    {
        $ret = 0;
        $query = "SELECT CONCAT(prefix.uri, uris.name) as uri FROM uris, prefix WHERE uris.prefix=prefix.id AND uris.id='" . ((int) $id) . "';";
        $res = $this->db->query($query);
        if($row = $res->fetch_assoc())
        {
            $ret = $row['uri'];
        }
        $res->free();

        return($ret);
    }
    
    function export()
    {
        $triples = array();
        foreach($this->subjects() as $rootid)
        {
            print($this->idtouri($rootid) . "\n");
        }
        return(count($this->errors));
    }
    
    function __construct($type_uri)
    {
        include(dirname(dirname(dirname(__FILE__))) . "/var/www/init.php");

        $this->env = array();
        $this->env["HEDGEHOG_CONFIG_ARC2_PATH"] = $lib_path . "/arc2/ARC2.php";
        $this->env["HEDGEHOG_CONFIG_GRAPHITE_PATH"] = $lib_path . "/graphite/Graphite.php";
        foreach($_SERVER as $k => $v)
        {
            if(is_string($v)) { $this->env[$k] = $v; }
        }

        $this->errors = array();
        $this->id = 0;
        $query = "SELECT uris.id FROM uris, prefix WHERE uris.prefix=prefix.id AND (CONCAT(prefix.uri, uris.name)='" . $db->escape_string($type_uri) . "' OR CONCAT(prefix.prefix, ':', uris.name)='" . $db->escape_string($type_uri) . "');";
        $res = $db->query($query);
        if($row = $res->fetch_assoc())
        {
            $this->id = $row['id'];
        }
        $res->free();

        $config['template'] = array();
        $query = "SELECT * FROM templates WHERE class='" . $db->escape_string($this->id) . "';";
        $res = $db->query($query);
        if($row = $res->fetch_assoc())
        {
            $config['template'] = explode("\n", $row['template']);
        }
        $res->free();
        
        $this->config = $config;
        $this->db = $db;
    }
}