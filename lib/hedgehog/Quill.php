<?php

class Quill
{
    private $config;
    private $db;
    private $id;
    private $env;
    
    public $errors;

    private function externalScript($command_line)
    {
        $descriptorspec = array(
            0 => array("pipe", "r"), // stdin
            1 => array("pipe", "w"), // stdout
            2 => array("pipe", "w")  // stderr
        );
        $pipes = array();
        
        if(is_array($this->env))
        {
            $process = proc_open($command_line, $descriptorspec, $pipes, NULL, $this->env);
        }
        else
        {
            $process = proc_open($command_line, $descriptorspec, $pipes);
        }
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $ret = proc_close($process);
        
        return(array(
            "code" => $ret,
            "stdout" => $stdout,
            "stderr" => $stderr
        ));
    }
    
    function __construct($id)
    {
        include_once(dirname(dirname(dirname(__FILE__))) . "/var/www/init.php");

        $this->env = array();
        $this->env["HEDGEHOG_CONFIG_ARC2_PATH"] = $lib_path . "/arc2/ARC2.php";
        $this->env["HEDGEHOG_CONFIG_GRAPHITE_PATH"] = $lib_path . "/graphite/Graphite.php";
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
        
        $this->config = $config;
        $this->db = $db;
    }
    
    function publish($force=false)
    {
        $hopper_path = $this->config['paths']['hopper_path'] . "/" . $this->id . "." . getmypid();
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
        if(array_key_exists("prepare", $commands))
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

        return(count($this->errors));
    }
}
