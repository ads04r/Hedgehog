<?php

include_once(dirname(__FILE__) . "/Quill.php");

class SpecialQuill extends Quill
{
    public $errors;
    private $cache;

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

    public function isValid()
    {
        if(!(array_key_exists("id", $this->config['quill']))) { $this->errors[] = "Unknown quill."; return false; }
        return true;
    }

    function __construct($id)
    {
        include(dirname(dirname(dirname(__FILE__))) . "/var/www/init.php");

        $this->env = array();
        $this->cache = array();
        $this->env["HEDGEHOG_CONFIG_ARC2_PATH"] = $lib_path . "/arc2/ARC2.php";
        $this->env["HEDGEHOG_CONFIG_GRAPHITE_PATH"] = $lib_path . "/graphite/Graphite.php";
        $this->env["HEDGEHOG_CONFIG_LIB_PATH"] = $lib_path;
        foreach($_SERVER as $k => $v)
        {
            if(is_string($v)) { $this->env[$k] = $v; }
        }
        
        $this->id = $id;
        $this->errors = array();
        $config['quill'] = array();
        $query = "SELECT * FROM settings WHERE `key` LIKE 'vdataset_%' AND value='" . $db->escape_string($id) . "';";
        $res = $db->query($query);
        if($row = $res->fetch_assoc())
        {
            $config['quill']['id'] = $id;
            $config['quill']['type'] = $row['key'];
        }
        $res->free();
        
        $config['paths'] = array();
        $query = "SELECT * FROM settings WHERE `key`='hopper_path' OR `key`='incoming_path' OR `key`='tools_path';";
        $res = $db->query($query);
        while($row = $res->fetch_assoc())
        {
            $key = $row['key'];
            $config['paths'][$key] = $row['value'];
        }
        $res->free();
        
        if(!(array_key_exists("hopper_path", $config['paths']))) { $config['paths']['hopper_path'] = "/tmp/hedgehog"; }
        if(!(array_key_exists("incoming_path", $config['paths']))) { $config['paths']['incoming_path'] = $var_path . "/incoming"; }
        if(!(array_key_exists("tools_path", $config['paths']))) { $config['paths']['tools_path'] = $usr_path . "/tools"; }
        
        $this->hopper_path = $config['paths']['hopper_path'] . "/" . $this->id . "." . getmypid();
        $this->config = $config;
        $this->db = $db;
        
        if(!(file_exists($this->hopper_path)))
        {
            mkdir($this->hopper_path, 0755, true);
        }
    }
    
    function generateRangeDomain()
    {
        $range = $this->uritoid("http://www.w3.org/2000/01/rdf-schema#range");
        $domain = $this->uritoid("http://www.w3.org/2000/01/rdf-schema#domain");
        $type = $this->uritoid("http://www.w3.org/1999/02/22-rdf-syntax-ns#type");

        if(($range == 0) || ($domain == 0)) { return; }

        $query = "insert ignore into vocabulary_links (s, p, o) (select distinct ontology.id as s, '" . $range . "' as p, types.type as o from triples, (select uris.* from uris, prefix where prefix.prefix='flarp' and uris.prefix=prefix.id) as ontology, (select distinct uris.id as uri, types.id as type from triples, uris, uris as types where triples.p='" . $type . "' and uris.id=triples.s and types.id=triples.o) as types where triples.p=ontology.id and triples.o=types.uri)";
	$this->db->query($query);

        $query = "insert ignore into vocabulary_links (s, p, o) (select distinct ontology.id as s, '" . $domain . "' as p, types.type as o from triples, (select uris.* from uris, prefix where prefix.prefix='flarp' and uris.prefix=prefix.id) as ontology, (select distinct uris.id as uri, types.id as type from triples, uris, uris as types where triples.p='" . $type . "' and uris.id=triples.s and types.id=triples.o) as types where triples.p=ontology.id and triples.s=types.uri)";
	$this->db->query($query);
    }
    
    function generateVocabulary()
    {
        $g = new Graphite();

        $g->ns("owl", "http://www.w3.org/2002/07/owl#");
        $g->ns("dct", "http://purl.org/dc/terms/");
        $g->ns("dcelements", "http://purl.org/dc/elements/1.1/");
        $g->ns("void", "http://rdfs.org/ns/void#");

        $query = "SELECT prefix.uri, prefix.id FROM prefix, settings WHERE settings.key='vocabulary_uri' AND settings.value=prefix.id;";
        $res = $this->db->query($query);
        if($row = $res->fetch_assoc())
        {
            $id = $row['id'];
            $uri = $row['uri'];
        }
        $res->free();

        $g->t($uri, "rdf:type", "owl:Ontology");
        $g->t($uri, "rdfs:label", "Vocabulary");
        $g->t($uri, "dcelements:title", "Vocabulary", "literal");
        $g->t($uri, "dcelements:description", "Site vocabulary, described using the W3C RDF Schema and the Web Ontology Language.", "literal");

        $query = "SELECT DISTINCT uris.* FROM triples, uris WHERE (triples.s=uris.id OR triples.o=uris.id) AND uris.prefix='" . $id . "' ORDER BY name ASC";
        $res = $this->db->query($query);
        while($row = $res->fetch_assoc())
        {
            $class_uri = $uri . $row['name'];
            $g->t($class_uri, "rdfs:isDefinedBy", $uri);
            $g->t($class_uri, "rdf:type", "rdfs:Class");
            $g->t($class_uri, "rdf:type", "owl:Class");
        }
        $res->free();

        $query = "SELECT DISTINCT uris.* FROM triples, uris WHERE triples.p=uris.id AND uris.prefix='" . $id . "' ORDER BY name ASC";
        $res = $this->db->query($query);
        while($row = $res->fetch_assoc())
        {
            $class_uri = $uri . $row['name'];
            $g->t($class_uri, "rdfs:isDefinedBy", $uri);
            $g->t($class_uri, "rdf:type", "owl:ObjectProperty");
        }
        $res->free();

        $query = "SELECT name, label, description FROM vocabulary, uris WHERE uris.id=vocabulary.uri AND uris.prefix='" . $id . "';";
        $res = $this->db->query($query);
        while($row = $res->fetch_assoc())
        {
            $class_uri = $uri . $row['name'];
            $g->t($class_uri, "rdfs:label", $row['label'], "literal");
            $g->t($class_uri, "dcelements:title", $row['label'], "literal");
            if(strlen($row['description']) > 0) { $g->t($class_uri, "dcelements:description", $row['description'], "literal"); }
        }
        $res->free();

        $query = "select distinct CONCAT(sprefix.uri, suris.name) as s, CONCAT(pprefix.uri, puris.name) as p, CONCAT(oprefix.uri, ouris.name) as o from (select vocabulary_links.* from vocabulary_links, triples, uris where triples.p=vocabulary_links.s and triples.p=uris.id and uris.prefix='" . $id . "') as vocabulary_links, uris as suris, uris as puris, uris as ouris, prefix as sprefix, prefix as pprefix, prefix as oprefix where suris.prefix=sprefix.id and ouris.prefix=oprefix.id and puris.prefix=pprefix.id and s=suris.id and p=puris.id and o=ouris.id order by s ASC";
        $res = $this->db->query($query);
        while($row = $res->fetch_assoc())
        {
            $g->t($row['s'], $row['p'], $row['o']);
        }
        $res->free();

        return($g);
    }
    
    function publish($force=false)
    {
        $hopper_path = $this->hopper_path;
        if(!(file_exists($hopper_path)))
        {
            mkdir($hopper_path, 0755, true);
        }
        chdir($hopper_path);

	$import_file = $hopper_path . "/vocabulary.nt";
        $this->generateRangeDomain();
        $vocab = $this->generateVocabulary();
	$fp = fopen($import_file, "w");
	fwrite($fp, $vocab->serialize("NTriples"));
	fclose($fp);
	$fp = fopen($hopper_path . "/vocabulary.ttl", "w");
	fwrite($fp, $vocab->serialize("Turtle"));
	fclose($fp);
	$fp = fopen($hopper_path . "/vocabulary.rdf", "w");
	fwrite($fp, $vocab->serialize("RDFXML"));
	fclose($fp);

        if(count($this->errors) > 0) { return(count($this->errors)); } // Exit here if there are issues
        
        $this->importFile($import_file);
        
        if(count($this->errors) > 0) { return(count($this->errors)); } // Exit here if there are issues
        
        $query = "SELECT * FROM exports";
        $res = $this->db->query($query);
        while($row = $res->fetch_assoc())
        {
            $export_config = json_decode($row['config'], true);
            $action_type = $export_config['action'];
            
            if(strcmp($action_type, "dump") == 0)
            {
                $dump_path = $export_config['path'];
                $dump_path = str_replace("%DATE%", date("Y-m-d"), $dump_path);
                $dump_path = str_replace("%QUILL%", $this->id, $dump_path);

	        if(!(file_exists($dump_path)))
	        {
	            mkdir($dump_path, 0755, true);
	        }
                $dp = opendir($hopper_path);
                while($fn = readdir($dp))
                {
                    if(strcmp(substr($fn, 0, 1), ".") == 0) { continue; }
                    if(preg_match("/\\.private$/", $fn) > 0) { continue; }
                    copy($hopper_path . "/" . $fn, $dump_path . "/" . $fn);
                    chmod($dump_path . "/" . $fn, fileperms($hopper_path . "/" . $fn));
                }
            }
        }
        $res->free();
        
        $query = "UPDATE quills SET last_publish=NOW() WHERE id='" . $this->db->escape_string($this->id) . "';";
        $this->db->query($query);
        
        return(count($this->errors));
    }
}
