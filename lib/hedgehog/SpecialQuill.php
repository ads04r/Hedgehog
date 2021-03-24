<?php

include_once(dirname(__FILE__) . "/Quill.php");

class SpecialQuill extends Quill
{
    public $errors;

    public function isValid()
    {
        if(!(array_key_exists("id", $this->config['quill']))) { $this->errors[] = "Unknown quill."; return false; }
        return true;
    }

    function __construct($id)
    {
        include(dirname(dirname(dirname(__FILE__))) . "/var/www/init.php");

        $this->env = array();
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
        $g->t($uri, "dcelements:title", "Vocabulary");
        $g->t($uri, "dcelements:description", "Site vocabulary, described using the W3C RDF Schema and the Web Ontology Language.");

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

        return($g->serialize("NTriples"));
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
        $vocabttl = $this->generateVocabulary();
	$fp = fopen($import_file, "w");
	fwrite($fp, $vocabttl);
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
