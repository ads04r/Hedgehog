<?php

class Hedgehog
{
	private $config;
	private $quiet;
	private $force;
	private $log;
	private $logfh;

	private function getSetting($quill, $setting)
	{
		if(array_key_exists("settings_override", $quill->config))
		{
			if(array_key_exists($setting, $quill->config['settings_override']))
			{
				return($quill->config['settings_override'][$setting]);
			}
		}
		return($this->config->$setting);
	}

	public function publishDataset($dataset)
	{
		$quills_dir_list = explode(":", $this->config->quills_dir);
		$tmp = $this->config->tmp_dir;
		$dumpdate = date("Y-m-d");

		$quills = "";
		foreach($quills_dir_list as $quills_dir)
		{
			$quills_dir_check = rtrim($quills_dir, "/") . "/" . $dataset . "/publish.json";
			if(file_exists($quills_dir_check))
			{
				$quills = $quills_dir;
				break;
			}
		}
		$cfg = rtrim($quills, "/") . "/" . $dataset . "/publish.json";
		if(!(file_exists($cfg)))
		{
			return("Cannot open quill '" . $dataset . "': config not found.");
		}
		try
		{
			if(is_dir($tmp))
			{
				$quill = new Quill($this,$cfg, $tmp);
			}
			else
			{
				$quill = new Quill($this,$cfg);
			}
		}
		catch (Exception $e)
		{
			return("Cannot open quill '" . $dataset . "': " . $e->getMessage());
		}
		
		if(!($this->quiet))
		{
			$this->log_message("Processing quill '" . $dataset . "'\n");
		}
		if(!($this->quiet))
		{
			$this->log_message("  Preparing data for publish\n");
		}
		$errors = $quill->prepare($this->quiet, $this->getSetting($quill, "tools_dir"));
		if($errors > 0)
		{
			return(implode("\n", $quill->errors));
		}
		
		$hash_file = rtrim($this->getSetting($quill, "hashes_dir"), "/") . "/" . $dataset . ".hash";
		$changed = $quill->changedFiles($hash_file);
		if((!($changed)) & (!($this->force)))
		{
			$errors = $quill->runCompletedScripts();
			if($errors > 0)
			{
				return(implode("\n", $quill->errors));
			}
			if(!($this->quiet))
			{
				$this->log_message("  No modifications, publish aborted\n");
			}
			return "";
		}
		
		// Generate the publish chain of events
		
		$dump_root = $this->getSetting($quill, "dumps_dir");
		$dump_base = $this->getSetting($quill, "target_base_url");
		$dump_quill_root = rtrim($dump_root, "/") . "/" . $dataset;
		$global_import_url = $this->getSetting($quill, "import_url");
		$publish_events = $this->getSetting($quill, "publish");
		$legacy_publish_actions = $this->getSetting($quill, "publish_actions");
		if(!(is_array($legacy_publish_actions)))
		{
			if(strlen($legacy_publish_actions) == 0)
			{
				$legacy_publish_actions = array();
			}
			else
			{
				$legacy_publish_actions = explode(" ", $legacy_publish_actions);
			}
		}
		if(!(is_array($publish_events)))
		{
			$publish_events = array();
		}
		
		if((file_exists($dump_root)) & (count($publish_events) == 0))
		{
			$item = array();
			$item['action'] = "dump";
			$item['url'] = $dump_base;
			$item['path'] = $dump_root;
			$publish_events[] = $item;
			
			if(strlen($global_import_url) > 0)
			{
				$item = array();
				if(in_array("sesame", $legacy_publish_actions))
				{
					$item['action'] = "sesame";
				}
				else
				{
					$item['action'] = "4store";
				}
				$item['url'] = $global_import_url;
				$publish_events[] = $item;
			}
		}
		
		// Dump files
		
		foreach($publish_events as $publish_event)
		{
			if(strcmp($publish_event['action'], "dump") != 0)
			{
				continue;
			}
		
			$dump_root = $publish_event['path'];
			$dump_base = $publish_event['url'];
			$dump_quill_root = rtrim($dump_root, "/") . "/" . $dataset;
			$dump = $dump_quill_root . "/" . $dumpdate;
			if(array_key_exists("base", $publish_event))
			{
				$xml_base = $publish_event['base'];
			}
			else
			{
				$xml_base = $this->getSetting($quill, "xml_base");
			}
			foreach($quill->dumps() as $dump_request)
			{
				$ext = strtolower(preg_replace("/^(.*)\.([a-zA-Z0-9]*)$/", "$2", $dump_request));
				$dumpuri = $dump_base . "/" . $dataset . "/" . $dumpdate . "/" . $dataset . "." . $ext;
				if(strcmp($ext, "ttl") == 0)
				{
					$quill->requestRdfDump($dataset . ".ttl", $dumpuri, "turtle");
				}
				if(strcmp($ext, "rdf") == 0)
				{
					$quill->requestRdfDump($dataset . ".rdf", $dumpuri, "rdfxml");
				}
				if(strcmp($ext, "kml") == 0)
				{
					$quill->requestKMLDump($dataset . ".kml", $dumpuri);
				}
				if(strcmp($ext, "ics") == 0)
				{
					$quill->requestICSDump($dataset . ".ics", $dumpuri);
				}
			}
			
			if(!($this->quiet))
			{
				$this->log_message("  Publishing data to " . $dump . "\n");
			}
			$dataset_uri = $xml_base . "/dataset/" . $dataset;
			$errors = $quill->publish($dump, $dump_base . "/" . $dataset . "/" . $dumpdate, $dataset_uri, $this->quiet);
			if($errors > 0)
			{
				return(implode("\n", $quill->errors));
			}
		}
		
		// Publish to triplestore(s)
		
		foreach($publish_events as $publish_event)
		{
			if((strcmp($publish_event['action'], "sesame") != 0) & (strcmp($publish_event['action'], "4store") != 0))
			{
				continue;
			}
			
			if(array_key_exists("base", $publish_event))
			{
				$xml_base = $publish_event['base'];
			}
			else
			{
				$xml_base = $this->getSetting($quill, "xml_base");
			}
			$graph = $xml_base . "/dataset/" . $dataset . "/latest";
			$triplestore_url = $publish_event['url'];
			$triplestore_type = $publish_event['action'];
			
			if(strcmp("sesame", $triplestore_type) == 0)
			{
				if(!($this->quiet))
				{
					$this->log_message("  Importing into Sesame store: " . $graph . "\n");
				}
				$fs = new SesameStore($triplestore_url);
				$err = $fs->replace($graph, $quill->triples());
				if(count($err) > 0)
				{
					$error_text = $err[0];
					if(strlen($error_text) > 0)
					{
						return($error_text);

					}
				}
			}

			if(strcmp("4store", $triplestore_type) == 0)
			{
				if(!($this->quiet))
				{
					$this->log_message("  Importing into 4store: " . $graph . "\n");
				}
				$fs = new FourStore($triplestore_url);
				$err = $fs->replace($graph, $quill->triples());
			}

		}
		
		// Clear old dumps if necessary
		
		foreach($publish_events as $publish_event)
		{
			if(strcmp($publish_event['action'], "dump") != 0)
			{
				continue;
			}
		
			$dump_root = $publish_event['path'];
			
			if(!($this->quiet))
			{
				$this->log_message("  Clearing old dumps\n");
			}
			$quill->garbageCollect(rtrim($dump_root, "/") . "/" . $dataset);
		}

		// Finish up
		
		if(!($this->quiet))
		{
			$this->log_message("  Publish successful.\n");
		}
		
		return "";
	}

	public function log_error( $text )
	{
		global $current_hedgehog_dataset;
		foreach( preg_split( "/\n/", rtrim($text) ) as $line )
		{
			if( $this->logfh )
			{
				fwrite( $this->logfh, date("c")." [$current_hedgehog_dataset] [ERR] ".rtrim($line)."\n" );
			}
			else
			{
				file_put_contents('php://stderr', rtrim($line)."\n");
			}
		}
	}

	public function log_message( $text )
	{
		global $current_hedgehog_dataset;
		foreach( preg_split( "/\n/", rtrim($text) ) as $line )
		{
			if( $this->logfh )
			{
				fwrite( $this->logfh, date("c")." [$current_hedgehog_dataset] [   ] ".rtrim($line)."\n" );
			}
			else
			{
				file_put_contents('php://stdout', rtrim($line)."\n");
			}
		}
	}
	
	function __construct($quiet=false, $force=false, $log=false)
	{
		$this->quiet = $quiet;
		$this->force = $force;
		$this->log = $log;
		$this->config = new HedgehogConfig();
		
		if( $this->log )
		{
			$logfile = $this->config->log_dir."/hedge.log";
			$this->logfh = fopen( $logfile, "a" );
			if( !$this->logfh )
			{
				$this->log_error( "Failed to open log $logfile" );
			}
		}
		
		$arc2path = $this->config->arc2_path;
		$graphitepath = $this->config->graphite_path;
		if((strlen($graphitepath) > 0) & (strlen($arc2path) > 0) & (file_exists($graphitepath)) & (file_exists($arc2path)))
		{
			include_once($arc2path);
			include_once($graphitepath);
		}
	}
	
}
