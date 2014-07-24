<?php

/* Class: 'Quill'
-----------------
The Quill class is effectively a dataset generation package. It handles the
publishing and, in many cases, the creation of a dataset. On creation it
requires the passing of a 'publish.json' file (although it doesn't require
this particular filename, just in case we decide to change it in future
versions.) Optionally, it can be passed the name of a directory for storing
temporary files, if this is omitted it defaults to '/tmp'.

The class's workflow is as follows:

1. Creation
2. Prepare
3. Request outputs / misc
4. Publish
5. Destruction

Once the class is created, there is not much that can be done with it, other
than reading configuration files, checking entries are valid, etc. In order
to actually do anything useful, the 'prepare' method must be called. This
method actually goes through the process of downloading any necessary files,
creating temporary directories and doing everything necessary to produce
triples, without actually creating any.

Quill takes two arguments, 'quiet' and 'tools_path'. Both have defaults; quiet
defaults to 'false' and 'tools_path' defaults to '/usr/bin'. If 'quiet' is
set to true, the class will not display any output to STDOUT. 'tools_path'
is where the class will look for things like Rapper and Grinder, although
it's smart enough to check the PATH system variable if it can't find them
there. Of course, if the dataset doesn't require any tools, this is all
irelevant. The 'prepare' method returns an integer relating to the number of
problems it encountered during its tasks. More details can be accessed from
the public property 'errors'. Publishing should cease if any errors are present.

After 'prepare' has been called, additional publish actions that might affect
the resulting data should be called. The only one at present is
'requestRdfDump', which instructs the class to create a dump in a particular
format on publish. Three formats are supported; rdfxml, turtle and ntriples.
The 'filename' argument is required, this is the file to which the dump will
be written.

TODO: Other formats, eg RSS and ICS

Once all this has been done, the 'publish' method should be called. This
basically does all the triple generation, dump file generation and the pushing
to a directory. It takes two arguments ('dumppath' and 'remotepath') and one
optional argument ('quiet', for supressing STDOUT). 'dumppath' is the
directory on the local drive to copy all the files (generally should be
within a world-readable 'htdocs' directory) and 'remotepath' is how the
directory is to be called by web users (should begin with http://).

Additionally, once a publish has taken place, the 'triples' property is
available. This returns all the triples generated as part of the dataset,
as an array of strings. Each string is a valid one-line ntriples document.
This may be imported into a triplestore using the 'FourStore' object. */

class Quill
{
	const HEDGEHOG_VERSION = "1.0";
	const HEDGEHOG_URL = "http://data.southampton.ac.uk/";

	private $hopper_path;
	private $triples_file;
	private $quill_path;
	private $extra_triples;
	private $dumpfiles;

	public $errors;
	public $config;
	
	public $hash_check_result;
	public $hash_checked;
	public $provenance;

	public function dumps()
	{
		$prop = $this->config['properties'];
		if(array_key_exists("accessurl", $prop))
		{
			return($prop['accessurl']);
		}
		return array();
	}
	
	public function garbageCollect($dumps_dir) // Function to remove old versions of datasets
	{
		$policy = $this->config['retention'];
		if(!(is_dir($dumps_dir)))
		{
			return;
		}
		if(!(array_key_exists("type", $policy)))
		{
			return;
		}
		$type = $policy['type'];
		if((strcmp($type, "keeplast") != 0) & (strcmp($type, "keeprecent") != 0))
		{
			return;
		}
		if((array_key_exists("cutoff", $policy)))
		{
			$cutoff = (int) $policy['cutoff'];
		}
		else
		{
			$cutoff = 0;
		}
		if((array_key_exists("action", $policy)))
		{
			$action = $policy['action'];
		}
		else
		{
			$action = "delete";
		}
		$dumps = array();
		if ($handle = opendir($dumps_dir)) {
			while (false !== ($entry = readdir($handle))) {
				$fullpath = rtrim($dumps_dir, "/") . "/" . $entry;
				if((!(is_dir($fullpath))) | (preg_match("/^[0-9][0-9][0-9][0-9]\\-[0-9][0-9]\\-[0-9][0-9]$/", $entry) == 0))
				{
					continue;
				}
				$dumps[] = $fullpath;
			}
			closedir($handle);
		}
		rsort($dumps);
		$i = 0;
		$daysago = strtotime(date("Y-m-d") . " 00:00:00") - (86400 * $cutoff);
		foreach($dumps as $fullpath)
		{
			$date = strtotime(preg_replace("|^(.*)/([^/]+)$|", "$2", $fullpath) . " 00:00:00");
			$i++;
			if(($i <= $cutoff) & (strcmp($type, "keeprecent") == 0))
			{
				continue;
			}
			if(($date >= $daysago) & (strcmp($type, "keeplast") == 0))
			{
				continue;
			}
			if(strcmp($action, "delete") == 0)
			{
				$this->deltree($fullpath);
			}
		}
		
		return;
	}

	// Define an archiving function for use by garbageCollect. This will hopefully be
	// replaced one day.
	
	private function archive($path)
	{
		$fullpath = rtrim($path, "/");
		if(!(is_dir($fullpath)))
		{
			return "Path " . $fullpath . " doesn't exist";
		}
		if(file_exists($fullpath . ".tgz"))
		{
			return "Archive file " . $fullpath . ".tgz already exists";
		}
		chdir($fullpath);
		$tarfile = preg_replace("|^(.*)/([^/]+)$|", "$2", $fullpath) . ".tgz";
		$command = "tar cvzf ../" . $tarfile . " *";
		$this->externalScript($command);
		chdir("..");
		if(!(file_exists($fullpath . ".tgz")))
		{
			return "Archive file " . $fullpath . ".tgz not created for an unknown reason";
		}
		$this->deltree($fullpath);
	}
	
	// Define a few file-related functions so we don't have to keep calling the shell.

	private function deltree($dir) // Delete a non-empty directory
	{
		if(is_dir($dir))
		{
			if ($handle = opendir($dir))
			{
				while (false !== ($entry = readdir($handle)))
				{
					if(!(is_dir($entry)))
					{
						unlink($dir . "/" . $entry);
					}
					else
					{
						if((!(strcmp($entry, ".."))) & (!(strcmp($entry, "."))))
						{
							$this->deltree($dir . "/" . $entry);
						}
					}
				}
				closedir($handle);
			}
		}
		rmdir($dir);
	}

	private function dupdir($src, $dst) // Duplicate a directory (non-recursive)
	{
		if(!(is_dir($dst)))
		{
			mkdir($dst, 0755, true);
		}
		if(is_dir($src))
		{
			if ($handle = opendir($src))
			{
				while (false !== ($entry = readdir($handle)))
				{
					if(!(is_dir($src . "/" . $entry)))
					{
						$file_perms = fileperms($src . "/" . $entry);
						copy($src . "/" . $entry, $dst . "/" . $entry);
						chmod($dst . "/" . $entry, $file_perms);
					}
				}
				closedir($handle);
			}
		}
	}
	
	private function ensureDirExists($path)
	/*
		I've written this function, despite the fact that it duplicates the behaviour of
		mkdir([path], [perm], true) - the reason being that different versions of PHP
		behave in different ways. To ensure consistency, I've written my own version of
		the function.
	*/
	{
		$patharr = explode("/", $path);
		$rpath = "";
		foreach($patharr as $dir)
		{
			$ddir = trim($dir);
			if(strlen($ddir) == 0)
			{
				continue;
			}
			$rpath = $rpath .= "/" . $ddir;
			if(is_dir($rpath))
			{
				continue;
			}
			mkdir($rpath, 0755);
		}

		return(is_dir($path));
	}

	private function wget($url, $file)
	{

		if(function_exists("curl_init"))
		{
			$ch = curl_init();
			$timeout = 5;
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($ch, CURLOPT_USERAGENT, "Hedgehog/" . self::HEDGEHOG_VERSION . " (" . self::HEDGEHOG_URL . ")");
			curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
			$data = curl_exec($ch);
			$error = curl_errno($ch);
			$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
			if(($error == 0) & ($httpCode < 400))
			{
				$fp = fopen($file, "w");
				fwrite($fp, $data);
				fclose($fp);
			}
			curl_close($ch);
		}
		else
		{
			// Included for portability, some PHP installs aren't compiled with cURL
			if(@$data = file_get_contents($url))
			{
				$fp = fopen($file, "w");
				fwrite($fp, $data);
				fclose($fp);
			}
			$httpCode = 0;
		}
		return($httpCode);
	}

	// A function to handle external processes better than shell_exec.

	private function externalScript($command_line)
	{
		$descriptorspec = array(
			0 => array("pipe", "r"),  // stdin
			1 => array("pipe", "w"),  // stdout
			2 => array("pipe", "w"),  // stderr
		);

		$process = proc_open($command_line, $descriptorspec, $pipes);
		$stdout = stream_get_contents($pipes[1]);
		fclose($pipes[1]);
		$stderr = stream_get_contents($pipes[2]);
		fclose($pipes[2]);
		$ret = proc_close($process);

		$r = array();
		$r['code'] = $ret;
		$r['stdout'] = $stdout;
		$r['stderr'] = $stderr;

		return($r);
	}
	
	private function loadSettingsFile($dataset_cfg)
	{
		// Check to see if the config file is valid
		@$json_obj = json_decode(implode("", file($dataset_cfg)), true);
		if(!(is_array($json_obj))) {
			$json_obj = array();
		}
		if( json_last_error() == JSON_ERROR_DEPTH )
		{
			$this->errors[] =  'Maximum stack depth exceeded in JSON';
		}
		if( json_last_error() == JSON_ERROR_CTRL_CHAR )
		{
			$this->errors[] = 'Unexpected control character found in JSON';
		}
		if( json_last_error() == JSON_ERROR_SYNTAX )
		{
			$this->errors[] = 'Syntax error, malformed JSON';
		}
		if(count($this->errors) == 0)
		{
			// only look for errors inside the JSON if the JSON was parsed OK
			if(!(array_key_exists("properties", $json_obj)))
			{
				$this->errors[] = "Missing 'properties' section in publish.json";
			}
			if((!(array_key_exists("additional_triples", $json_obj))) & (!(array_key_exists("triples", $json_obj))))
			{
				$this->errors[] = "Missing 'additional_triples' section in publish.json";
			}
			if(!(array_key_exists("downloads", $json_obj)))
			{
				$this->errors[] = "Missing 'downloads' section in publish.json";
			}
			if(!(array_key_exists("tools", $json_obj)))
			{
				$this->errors[] = "Missing 'tools' section in publish.json";
			}
			if(!(array_key_exists("files", $json_obj)))
			{
				$this->errors[] = "Missing 'files' section in publish.json";
			}
			if(!(array_key_exists("commands", $json_obj)))
			{
				$this->errors[] = "Missing 'commands' section in publish.json";
			}
		}
		
		$errors = count($this->errors);
		if($errors > 0)
		{
			return $errors; // May as well quit here, we can't continue if we have errors.
		}
		
		// Check that all the necessary files exist
		$parse = explode("/", $dataset_cfg);
		$parse[(count($parse) - 1)] = "";
		$dataset_path = rtrim(implode("/", $parse), "/");
		$files_list = $json_obj['files'];
		@chdir($dataset_path);
		foreach($files_list as $file_req)
		{
			if(!(file_exists($file_req)))
			{
				$this->errors[] = "Missing file '" . $file_req . "', defined in publish.json";
			}
		}
		
		// Check that the dataset actually has an import instruction
		$properties_list = $json_obj['properties'];
		if(!(array_key_exists("import_file", $properties_list)))
		{
			$this->errors[] = "Missing import instruction in publish.json";
		}

		$errors = count($this->errors);
		if($errors == 0)
		{
			$this->config = $json_obj;
		}
		return($errors);
	}
	
	public function prepare($quiet = false, $tools_path = "/usr/bin")
	{
		// Copy quill directory to the hopper
		$this->dupdir($this->quill_path, $this->hopper_path);
		if(!(is_dir($this->hopper_path)))
		{
			$this->errors[] = "Could not create hopper directory " . $this->hopper_path;
			$errors = count($this->errors);
			return($errors);
		}
		
		// Download necessary files
		chdir($this->hopper_path);
		$downloads = $this->config['downloads'];
		foreach($downloads as $download)
		{
			if((array_key_exists("localfile", $download)) & (array_key_exists("download", $download)))
			{
				$this->provenance->logDownloadStart($download['download'], $download['localfile']);
				$httpCode = $this->wget($download['download'], $download['localfile']);
				if(!(file_exists($download['localfile'])))
				{
					$this->errors[] = "Error " . $httpCode . ", could not download " . $download['download'];
				}
				$this->provenance->logDownloadEnd($download['download'], $download['localfile']);
			}
		}
		$errors = count($this->errors);
		if($errors > 0)
		{
			return($errors);
		}
		
		// Install necessary tools
		chdir($this->hopper_path);
		$tools = $this->config['tools'];
		$path = explode(":", $_SERVER['PATH']);
		foreach($tools as $tool)
		{
			$toolpath = $tools_path . "/" . $tool;
			if(!(file_exists($toolpath)))
			{
				foreach($path as $dir)
				{
					$toolpath = $dir . "/" . $tool;
					if(file_exists($toolpath))
					{
						break;
					}
				}
			}
			if(!(file_exists($toolpath)))
			{
				$this->errors[] = "Could not locate tool " . $tool;
				$errors = count($this->errors);
				return($errors);
			}
			$file_perms = fileperms($toolpath);
			copy($toolpath, $this->hopper_path . "/" . $tool);
			chmod($this->hopper_path . "/" . $tool, $file_perms);
			if(!(file_exists($this->hopper_path . "/" . $tool)))
			{
				$this->errors[] = "Could not copy tool " . $tool;
				$errors = count($this->errors);
				return($errors);
			}
		}
		
		// Run prepare scripts
		chdir($this->hopper_path);
		$commands = $this->config['commands'];
		if(array_key_exists("prepare", $commands))
		{
			foreach($commands['prepare'] as $command)
			{
				$file_output = $this->externalScript($command);
				if($file_output['code'] != 0)
				{
					$this->errors[] = "Attempt to run command failed: " . $command . "\n" . $file_output['stderr'];
					$errors = count($this->errors);
					return($errors);
				}
				if((!($quiet)) & (strlen($file_output['stdout']) > 0))
				{
					$stdout = trim($file_output['stdout']);
					if(strlen($stdout > 0))
					{
						print("    " . str_replace("\n", "\n    ", $stdout) . "\n");
					}
				}
				$stderr = trim($file_output['stderr']);
				if((!($quiet)) & (strlen($stderr) > 0))
				{
					display_error("    " . str_replace("\n", "\n    ", $stderr));
				}
			}
		}
		
		// Check all necessary files exist
		chdir($this->hopper_path);
		$files = $this->config['files'];
		foreach($files as $file)
		{
			if(!(file_exists($file)))
			{
				$this->errors[] = "Could not find required file '" . $file . "'";
			}
		}

		$this->hash_checked = false;
		
		// Count errors and exit
		$errors = count($this->errors);
		return($errors);
	}
	
	public function publish($dumppath, $remotepath, $dataset_uri, $quiet = false)
	{
		// Set up some variables to start.
		if(array_key_exists("min_triples", $this->config['properties']))
		{
			$min_triples = $this->config['properties']['min_triples'];
		}
		else
		{
			$min_triples = 0;
		}
		if(array_key_exists("warn_triples", $this->config['properties']))
		{
			$warn_triples = $this->config['properties']['warn_triples'];
		}
		else
		{
			$warn_triples = 0;
		}

		// Check the current contents of the hopper so we can tell which command did what
		$current_hopper = array();
		if ($fh = opendir($this->hopper_path))
		{
			while (false !== ($entry = readdir($fh)))
			{
				if(!(is_dir($this->hopper_path . "/" . $entry)))
				{
					$current_hopper[] = $entry;
				}
			}
			closedir($fh);
		}
		
		// First run the 'publish' scripts, if any.
		chdir($this->hopper_path);
		$commands = $this->config['commands'];
		if(array_key_exists("import", $commands))
		{
			foreach($commands['import'] as $command)
			{
				$file_output = $this->externalScript($command);
				if($file_output['code'] != 0)
				{
					$this->errors[] = "Attempt to run command failed: " . $command . "\n" . $file_output['stderr'];
					$errors = count($this->errors);
					return($errors);
				}
				if((!($quiet)) & (strlen($file_output['stdout']) > 0))
				{
					$stdout = trim($file_output['stdout']);
					if(strlen($stdout) > 0)
					{
						print("    " . str_replace("\n", "\n    ", $stdout) . "\n");
					}
				}
				$stderr = trim($file_output['stderr']);
				if((!($quiet)) & (strlen($file_output['stderr']) > 0))
				{
					display_error("    " . str_replace("\n", "\n    ", $stderr));
				}
				if ($fh = opendir($this->hopper_path))
				{
					while (false !== ($entry = readdir($fh)))
					{
						if(!(is_dir($this->hopper_path . "/" . $entry)))
						{
							if(!(in_array($entry, $current_hopper)))
							{
								if(count($this->config['downloads']) == 1)
								{
									$fromuri = $this->config['downloads'][0]['localfile'];
								}
								else
								{
									$fromuri = $this->config['properties']['import_file'];
									foreach($current_hopper as $sourcefile)
									{
										if(strlen(stristr($command, $sourcefile)) > 0)
										{
											$fromuri = $sourcefile;
										}
									}
								}
								$this->provenance->logConvert($fromuri, $entry, $current_hopper);
								$current_hopper[] = $entry;
							}
						}
					}
					closedir($fh);
				}

				
			}
		}
		
		// Check for presence of import file
		chdir($this->hopper_path);
		$import_file = $this->config['properties']['import_file'];
		if(!(file_exists($import_file)))
		{
			$this->errors[] = "Import file '" . $import_file . "' not found.";
			$errors = count($this->errors);
			return($errors);
		}

		// Find the path to Rapper, if it exists
		chdir($this->hopper_path);
		$path = explode(":", $_SERVER['PATH']);
		$rapper = "";
		foreach($path as $dir)
		{
			$testpath = rtrim($dir, "/") . "/rapper";
			if(file_exists($testpath))
			{
				$rapper = $testpath;
				break;
			}
		}
		if(strlen($rapper) == 0)
		{
			$this->errors[] = "Cannot find Rapper in the path.";
			$errors = count($this->errors);
			return($errors);
		}
		
		// Generate triples
		chdir($this->hopper_path);
		$cmd_line = $rapper . " -g -o ntriples " . $import_file . " > " . $this->triples_file;
		$triplecount = 0;
		$ret_val = $this->externalScript($cmd_line);
		$this->provenance->logConvert($import_file, $this->triples_file, array());
		// Get the number of triples - bit hacky but does the job until I can think of something better.
		// We could count the lines in the ntriples file, but that would take far too much time with massive datasets.
		$m = array();
		if(preg_match("/returned ([0-9]+) triples/", $ret_val['stderr'], $m) > 0)
		{
			$triplecount = (int) $m[1];
		}
		// Now use the same method to get the name of the parser used.
		$m = array();
		$ntriples_file_format = "";
		if(preg_match("/parser name \'([a-zA-Z0-9]+)\'/", $ret_val['stderr'], $m) > 0)
		{
			$ntriples_file_format = strtolower($m[1]);
			$this->provenance->fileType($import_file, $ntriples_file_format);
		}
		
		// If rapper returns a nonzero value, quit
		if($ret_val['code'] != 0)
		{
			$this->errors[] = "Error generating triples.\n    " . str_replace("\n", "\n    ", trim($ret_val['stderr']));
			$errors = count($this->errors);
			return($errors);
		}

		// If not enough triples were generated, something is wrong and we need to stop here.
		if($triplecount <= $min_triples)
		{
			$this->errors[] = "Error generating triples.\n    " . str_replace("\n", "\n    ", trim($ret_val['stderr']));
			$errors = count($this->errors);
			return($errors);
		}

		if($triplecount <= $warn_triples)
		{
			display_error("WARNING: Only " . $triplecount . " triples generated!");
		}
		
		// Delete '.private' files
		chdir($this->hopper_path);
		$files = glob('./*.private');
		foreach($files as $file){ 
			if(is_file($file))
			{
				unlink($file);
			}
		}
		
		// Add metadata
		foreach($this->dumpfiles as $dumpfile)
		{
			$uri = $dumpfile['uri'];
			if(strlen($uri) == 0)
			{
				continue;
			}
			$ttl = $this->dumpFileMetadata($uri);
			$ttl[] = "<" . $uri . "> <http://purl.org/dc/terms/isPartOf> <" . $dataset_uri . "> .";
			$fp = fopen($this->triples_file, "a");
			$triplecount = $triplecount + count($ttl);
			foreach($ttl as $triple)
			{
				fwrite($fp, $triple . "\n");
			}
			fclose($fp);
		}
		
		// Add provenance
		$ttl = $this->provenance->generateProvenance($this->hopper_path, $remotepath);
		//print(implode("\n", $ttl) . "\n");
		$fp = fopen($this->triples_file, "a");
		$triplecount = $triplecount + count($ttl);
		foreach($ttl as $triple)
		{
			fwrite($fp, $triple . "\n");
		}
		fclose($fp);

		// Add statistics (eg triple count)
		$ttl = array();
		$ttl[] = "<" . $dataset_uri . "> <http://rdfs.org/ns/void#triples> \"" . ($triplecount + 1) . "\"^^<http://www.w3.org/2001/XMLSchema#NonNegativeInteger> ."; // IMPORTANT! ALWAYS do this last, or the triple count will be wrong!
		$fp = fopen($this->triples_file, "a");
		foreach($ttl as $triple)
		{
			fwrite($fp, $triple . "\n");
		}
		fclose($fp);
		
		// Perform dumps

		foreach($this->dumpfiles as $dumpfile)
		{
			$localfile = $dumpfile['filename'];
			$format = $dumpfile['format'];
			if(strcmp($format, "kml") == 0)
			{
				$this->dumpKml($localfile);
				continue;
			}
			if(strcmp($format, "ics") == 0)
			{
				$this->dumpIcs($localfile);
				continue;
			}
			if(!((strcmp($format, "turtle") == 0) | (strcmp($format, "rdfxml") == 0)))
			{
				continue;
			}
			$triples_filename = preg_replace("|^(.*)/([^/]+)$|", "$2", $this->triples_file);
			$rdffile = $this->hopper_path . "/" . preg_replace("|^(.*)/([^/]+)$|", "$2", $localfile);
			$cmd_line = $rapper . " -i ntriples -o " . $format . " " . $this->triples_file . " | sed 's#file:///" . trim($this->hopper_path, "/") . "#.#' > " . $rdffile;
			$ret_val = $this->externalScript($cmd_line);
			if(!(file_exists($rdffile)))
			{
				$this->errors[] = "Error creating dump file " . $rdffile;
				$errors = count($this->errors);
				return($errors);
			}
		}
		
		// Copy contents of the hopper to the dumps path
		$this->ensureDirExists($dumppath);
		$this->deltree($dumppath);
		if(!($this->ensureDirExists($dumppath)))
		{
			$this->errors[] = "Error creating/clearing directory " . $dumppath . " - check file permissions.";
			$errors = count($this->errors);
			return($errors);
		}
		$this->dupdir($this->hopper_path, $dumppath);
		
		// Perform completion scripts, if requested.
		$this->runCompletedScripts();
		
		return(count($this->errors));
	}

	private function dumpKml($filename)
	{
		$kmlfile = $this->hopper_path . "/" . $filename;
		$g = new Graphite();
		$g->load($this->triples_file);
		$fp = fopen($kmlfile, "w");
		fwrite($fp, $g->toKml());
		fclose($fp);
	}
	
	private function dumpIcs($filename)
	{
		$icsfile = $this->hopper_path . "/" . $filename;
		$g = new Graphite();
		$g->load($this->triples_file);
		$fp = fopen($icsfile, "w");
		fwrite($fp, $g->toIcs());
		fclose($fp);
	}
	
	public function runCompletedScripts()
	{
		chdir($this->hopper_path);
		$commands = $this->config['commands'];
		if(array_key_exists("completed", $commands))
		{
			foreach($commands['completed'] as $command)
			{
				$file_output = $this->externalScript($command);
				if($file_output['code'] != 0)
				{
					$this->errors[] = "Attempt to run command failed: " . $command . "\n" . $file_output['stderr'];
					$errors = count($this->errors);
					return($errors);
				}
			}
		}
		return count($this->errors);
	}
	
	private function dumpFileMetadata($uri)
	{
		$cfg_properties = $this->config['properties'];
		$ttl = array();
		if(array_key_exists('title', $cfg_properties))
		{
			$ttl[] = "<" . $uri . "> <http://purl.org/dc/terms/title> \"" . str_replace("\"", "\\\"", $cfg_properties['title']) . "\" .";
		}
		if(array_key_exists('description', $cfg_properties))
		{
			$ttl[] = "<" . $uri . "> <http://purl.org/dc/terms/description> \"" . str_replace("\"", "\\\"", $cfg_properties['description']) . "\" .";
		}
		if(array_key_exists('stars', $cfg_properties))
		{
			$ttl[] = "<" . $uri . "> <http://purl.org/dc/terms/conformsTo> <http://purl.org/openorg/opendata-" . $cfg_properties['stars'] . "-star> .";
		}
		if(array_key_exists('license', $cfg_properties))
		{
			$licenses = $cfg_properties['license'];
			if(is_array($licenses))
			{
				foreach($licenses as $license)
				{
					$ttl[] = "<" . $uri . "> <http://purl.org/dc/terms/license> <" . $license . "> .";
				}
			}
		}
		if(array_key_exists('publisheruri', $cfg_properties))
		{
			$ttl[] = "<" . $uri . "> <http://purl.org/dc/terms/publisher> <" . $cfg_properties['publisheruri'] . "> .";
			if(array_key_exists('publishername', $cfg_properties))
			{
				$ttl[] = "<" . $cfg_properties['publisheruri'] . "> <http://www.w3.org/2000/01/rdf-schema#label> \"" . str_replace("\"", "\\\"", $cfg_properties['publishername']) . "\" .";
			}

		}
		if(array_key_exists('corrections', $cfg_properties))
		{
			if(preg_match("/.*@.*/", $cfg_properties['corrections']) > 0)
			{
				$ttl[] = "<" . $uri . "> <http://purl.org/openorg/corrections> <mailto:" . $cfg_properties['corrections'] . "> .";
			}
			else
			{
				$ttl[] = "<" . $uri . "> <http://purl.org/openorg/corrections> <" . $cfg_properties['corrections'] . "> .";
			}
		}
		if(array_key_exists('endpoint', $cfg_properties))
		{
			$endpoints = $cfg_properties['endpoint'];
			if(is_array($endpoints))
			{
				foreach($endpoints as $endpoint)
				{
					$ttl[] = "<" . $uri . "> <http://rdfs.org/ns/void#sparqlEndpoint> <" . $endpoint . "> .";
				}
			}
		}
		if(array_key_exists('authority', $cfg_properties))
		{
			if($cfg_properties['authority'])
			{
				$ttl[] = "<" . $uri . "> <http://www.w3.org/1999/02/22-rdf-syntax-ns#type> <http://purl.org/openorg/AuthoritativeDataset> .";
			}
			else
			{
				$ttl[] = "<" . $uri . "> <http://www.w3.org/1999/02/22-rdf-syntax-ns#type> <http://purl.org/openorg/NonAuthoritativeDataset> .";
			}
		}
		return($ttl);
	}

	public function requestRdfDump($filename, $uri="", $format="turtle")
	{
		$item = array();
		$item['filename'] = preg_replace("|^(.*)/([^/]+)$|", "$2", $filename);
		$item['uri'] = $uri;
		$item['format'] = $format;
		$this->dumpfiles[] = $item;

		$rdffile = $this->hopper_path . "/" . $filename;
		$import_file = preg_replace("|^(.*)/([^/]+)$|", "$2", $this->config['properties']['import_file']);
		if(!(file_exists($rdffile)))
		{
			$this->provenance->logConvert($import_file, $filename, array(), $format);
		}
	}
	
	public function requestKMLDump($filename, $uri="")
	{
		if(!(class_exists("Graphite")))
		{
			$this->errors[] = "Cannot create KML files - Graphite not registered.";
			$errors = count($this->errors);
			return;
		}
	
		$item = array();
		$item['filename'] = preg_replace("|^(.*)/([^/]+)$|", "$2", $filename);
		$item['uri'] = $uri;
		$item['format'] = "kml";
		$this->dumpfiles[] = $item;

		$rdffile = $this->hopper_path . "/" . $filename;
		$import_file = preg_replace("|^(.*)/([^/]+)$|", "$2", $this->config['properties']['import_file']);
		if(!(file_exists($rdffile)))
		{
			$this->provenance->logConvert($import_file, $filename, array(), "kml");
		}
	}

	public function requestICSDump($filename, $uri="")
	{
		if(!(class_exists("Graphite")))
		{
			$this->errors[] = "Cannot create ICS files - Graphite not registered.";
			$errors = count($this->errors);
			return;
		}
	
		$item = array();
		$item['filename'] = preg_replace("|^(.*)/([^/]+)$|", "$2", $filename);
		$item['uri'] = $uri;
		$item['format'] = "ics";
		$this->dumpfiles[] = $item;

		$rdffile = $this->hopper_path . "/" . $filename;
		$import_file = preg_replace("|^(.*)/([^/]+)$|", "$2", $this->config['properties']['import_file']);
		if(!(file_exists($rdffile)))
		{
			$this->provenance->logConvert($import_file, $filename, array(), "ics");
		}
	}

	// The following three functions handle hash checks. The hashCheck function isn't
	// public because it has side effects, so it may return different results if
	// called twice during the same process, which we don't really want. The
	// changedFiles function remedies this by caching the value returned by
	// hashCheck so it's only called once.

	public function changedFiles($hash_file)
	{
		$check = false;
		@$check = $this->config['properties']['check_hashes'];
		if(!($check))
		{
			return true;
		}
		if(!($this->hash_checked))
		{
			$this->hash_check_result = $this->hashCheck($hash_file);
			$this->hash_checked = true;
		}
		return($this->hash_check_result);
	}
	
	private function writeHashFile($hash_file)
	{
		if(!($handle = opendir($this->hopper_path)))
		{
			return false;
		}
		$fp = fopen($hash_file, "w");
		while (false !== ($entry = readdir($handle)))
		{
			if(is_dir(rtrim($this->hopper_path, "/") . "/" . $entry))
			{
				continue;
			}
			$new_hash = md5_file(rtrim($this->hopper_path, "/") . "/" . $entry);
			fwrite($fp, $new_hash . " " . $entry . "\n");
		}
		fclose($fp);
	}
	
	private function hashCheck($hash_file)
	{
		// Load previous hashes
		$old_hashes = array();
		if(!(file_exists($hash_file)))
		{
			// Hash file doesn't exist, this must be the first publish.
			$this->writeHashFile($hash_file);
			return true;
		}
		$f = file($hash_file);
		foreach($f as $l)
		{
				if(strlen($l) > 0)
				{
						$a = explode(" ", $l, 2);
						$k = trim($a[1]);
						$v = trim($a[0]);
						$old_hashes[$k] = $v;
				}
		}
		$handle = opendir($this->hopper_path);
		while (false !== ($entry = readdir($handle)))
		{
			if(is_dir(rtrim($this->hopper_path, "/") . "/" . $entry))
			{
				continue;
			}
			if(!(array_key_exists($entry, $old_hashes)))
			{
				// New file found, return true.
				$this->writeHashFile($hash_file);
				return true;
			}
			$hash = md5_file(rtrim($this->hopper_path, "/") . "/" . $entry);
			if(strcmp($hash, $old_hashes[$entry]) != 0)
			{
				// File changed, return true.
				$this->writeHashFile($hash_file);
				return true;
			}
		}
		
		return false;
	}

	public function triples()
	{
		if(strlen($this->triples_file) == 0)
		{
			return array();
		}
		if(!(file_exists($this->triples_file)))
		{
			return array();
		}
		return(file($this->triples_file));
	}

	function __construct($cfg_file, $tmp_dir="/tmp")
	{
		$this->provenance = new Provenance();
		$this->extra_triples = array();
		$this->dumpfiles = array();
		$this->quill_path = preg_replace("|/[^/]*$|", "", $cfg_file);
		$this->errors = array();
		$this->hash_checked = false;
		$this->hash_check_result = false;
		if(($this->loadSettingsFile($cfg_file)) > 0)
		{
			throw new Exception(implode(", ", $this->errors));
		}

		$uid = getmypid();
		$parse = explode("/", $cfg_file);
		$c = count($parse);
		$dataset = "data";
		if((strcmp($parse[($c - 1)], "publish.json") == 0) & ($c >= 2))
		{
			$dataset = $parse[($c - 2)];
		}
		$this->hopper_path = rtrim($tmp_dir, "/") . "/" . $dataset . ".new." . $uid;
		$this->triples_file = rtrim($tmp_dir, "/") . "/" . $dataset . "-full.nt." . $uid;
		//$this->triples_file = $this->hopper_path . "/" . $dataset . ".nt";
		if(!($this->ensureDirExists($this->hopper_path))) {
			throw new Exception("Cannot create temporary directory for processing");
		}
	}
	
	function __destruct()
	{
		// Perform clear up - remove temporary files.
		$this->deltree($this->hopper_path);
		if(file_exists($this->triples_file))
		{
			unlink($this->triples_file);
		}
	}
}
