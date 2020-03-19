<?php

class Quill
{
    private $config;
    private $db;
    private $id;
    private $env;
    private $hopper_path;
    
    public $errors;

    private function importFile($filename)
    {
        $query = "DELETE FROM triples WHERE quill='" . $this->db->escape_string($this->id) . "';";
        $this->db->query($query);
    
        $g = new Graphite();
        $g->load($filename);
        foreach($g->allSubjects() as $res)
        {
            $subject_uri = "" . $res;
            $subject_id = $this->getUriId($subject_uri);
            foreach($res->relations() as $rel)
            {
                $predicate_uri = "" . $rel;
                $predicate_id = $this->getUriId($predicate_uri);
                foreach($res->all($predicate_uri) as $object)
                {
                    $object_type_uri = "" . $object->datatype();
                    $object_type_id = 0;
                    $object_id = 0;
                    if(strlen($object_type_uri) > 0)
                    {
                        if(preg_match("#^([a-zA-Z0-9]+)://(.*)$#", $object_type_uri) > 0)
                        {
                            $object_type_id = $this->getUriId($object_type_uri);
                        }
                    }
                    
                    if($object_type_id == 0)
                    {
                        $object_uri = "" . $object;
                        if(preg_match("#^([a-zA-Z0-9]+)://(.*)$#", $object_uri) > 0)
                        {
                            $object_id = $this->getUriId($object_uri);
                        }
                    }
                    
                    if($object_id > 0)
                    {
                        $query = "INSERT INTO triples (s, p, o, quill) VALUES ('" . $subject_id . "', '" . $predicate_id . "', '" . $object_id . "', '" . $this->db->escape_string($this->id) . "');";
                    }
                    else
                    {
                        $query = "INSERT INTO triples (s, p, o_text, o_type, quill) VALUES ('" . $subject_id . "', '" . $predicate_id . "', '" . $this->db->escape_string("" . $object) . "', '" . $object_type_id . "', '" . $this->db->escape_string($this->id) . "');";
                    }
                    
                    $this->db->query($query);
                }
            }
        }
    }
    
    private function getPrefixId($uri)
    {
        $query = "SELECT id FROM prefix WHERE uri='" . $this->db->escape_string($uri) . "';";
        $res = $this->db->query($query);
        $ret = 0;
        if($row = $res->fetch_assoc())
        {
            $ret = (int) $row['id'];
        }
        $res->free();
        
        if($ret > 0) { return($ret); }
        
        $query = "INSERT INTO prefix (prefix, uri, label) VALUES ('', '" . $this->db->escape_string($uri) . "', '');";
        if($res = $this->db->query($query)) { $ret = $this->db->insert_id; }
        
        return($ret);
    }
    
    private function getUriId($uri)
    {
        $m = array();
        preg_match("|^(.*)([/#])([^/#]*.)$|", $uri, $m);
        $prefix = $m[1] . $m[2];
        $name = $m[3];
        if(preg_match("|://$|", $prefix) > 0) { $prefix = $prefix . $name; $name = ""; }
        $prefix_id = $this->getPrefixId($prefix);
        
        $query = "SELECT id FROM uris WHERE prefix='" . $prefix_id . "' AND name='" . $this->db->escape_string($name) . "';";
        $res = $this->db->query($query);
        $ret = 0;
        if($row = $res->fetch_assoc())
        {
            $ret = (int) $row['id'];
        }
        $res->free();
        
        if($ret > 0) { return($ret); }
        
        $query = "INSERT INTO uris (prefix, name) VALUES ('" . $prefix_id . "', '" . $this->db->escape_string($name) . "');";
        if($res = $this->db->query($query)) { $ret = $this->db->insert_id; }
        
        return($ret);
    }
    
    private function externalScript($command_line)
    {
	$ret = 1;
	$stdout = "";
	$stderr = "";

	$out = array();
	$retcode = 0;
	exec($command_line . " 2> /dev/null", $out, $ret);
	$stdout = implode("\n", $out);

        return(array(
            "code" => $ret,
            "stdout" => $stdout,
            "stderr" => $stderr
        ));
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
        $query = "SELECT * FROM quills WHERE id='" . $db->escape_string($id) . "';";
        $res = $db->query($query);
        if($row = $res->fetch_assoc())
        {
            $config['quill'] = $row;
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
    
    function publish($force=false)
    {
        $hopper_path = $this->hopper_path;
        $quill_path = $this->config['quill']['path'];
        if(!(file_exists($hopper_path)))
        {
            mkdir($hopper_path, 0755, true);
        }
        if(is_dir($quill_path))
        {
            $dp = opendir($quill_path);
            while($fn = readdir($dp))
            {
                if(strcmp(substr($fn, 0, 1), ".") == 0) { continue; }
                copy($quill_path . "/" . $fn, $hopper_path . "/" . $fn);
                chmod($hopper_path . "/" . $fn, fileperms($quill_path . "/" . $fn));
            }
        } else {
            // It's a ZIP file
        }

        $publish_file = $hopper_path . "/publish.json";
        $info = json_decode(file_get_contents($publish_file), true);
        if(!(is_array($info))) { $info = array(); }
        
        if(array_key_exists("tools", $info))
        {
            foreach($info['tools'] as $tool)
            {
                copy($this->config['paths']['tools_path'] . "/" . $tool, $hopper_path . "/" . $tool);
                chmod($hopper_path . "/" . $tool, fileperms($this->config['paths']['tools_path'] . "/" . $tool));
            }
        }
        
        if(array_key_exists("incoming", $info))
        {
            foreach($info['incoming'] as $file)
            {
                copy($this->config['paths']['incoming_path'] . "/" . $file, $hopper_path . "/" . $file);
                chmod($hopper_path . "/" . $file, fileperms($this->config['paths']['incoming_path'] . "/" . $file));
            }
        }
        
        if(array_key_exists("downloads", $info))
        {
            foreach($info['downloads'] as $download)
            {
                $fp = fopen($hopper_path . "/" . $download['localfile'], "w");
                fwrite($fp, file_get_contents($download['download']));
                fclose($fp);
            }
        }
        
        chdir($hopper_path);
        
        $commands = $info['commands'];
        if(array_key_exists("prepare", $commands))
        {
            foreach($commands['prepare'] as $command)
            {
                $file_output = $this->externalScript($command);
                if($file_output['code'] != 0)
                {
                    $this->errors[] = "Attempt to run command failed: " . $command . "\n" . $file_output['stderr'];
                }
            }
        }
        
        chdir($hopper_path);
        
        foreach($info['files'] as $file)
        {
            if(file_exists($hopper_path . "/" . $file)) { continue; }
            $this->errors[] = "Could not find a required file: " . $file;
        }
        
        if(count($this->errors) > 0) { return(count($this->errors)); } // Exit here if there are issues
        
        chdir($hopper_path);
        
        $commands = $info['commands'];
        if(array_key_exists("import", $commands))
        {
            foreach($commands['import'] as $command)
            {
                $file_output = $this->externalScript($command);
                if($file_output['code'] != 0)
                {
                    $this->errors[] = "Attempt to run command failed: " . $command . "\n" . $file_output['stderr'];
                }
            }
        }

        if(count($this->errors) > 0) { return(count($this->errors)); } // Exit here if there are issues
        
        $import_file = "";
        if(array_key_exists("import_file", $info)) { $import_file = $info['import_file']; }
        if(array_key_exists("import_file", $info['properties'])) { $import_file = $info['properties']['import_file']; }
        if(!(file_exists($import_file))) { $this->errors[] = "Could not find import file: " . $import_file; }

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
