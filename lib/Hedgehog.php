<?php

class Hedgehog
{
	public $config;
	private $quiet;
	private $force;
	private $log;
	private $logfh;

	public function getSettings($quill="")
	{
		if(strlen($quill) == 0)
		{
			return($this->config->getSettings());
		}

		$ret = array_merge(
			$this->config->getSettings(),
			$quill
		);
		return($ret);
	}

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

	public function publishDataset($dataset, $republish=false)
	{
		$quills_dir_blob = $this->config->quills_dir;
		if(is_array($quills_dir_blob))
		{
			$quills_dir_list = $quills_dir_blob;
		}
		else
		{
			$quills_dir_list = explode(":", "" . $quills_dir_blob);
		}
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

		$errors = 0;
		if(!($republish))
		{
			if(!($this->quiet))
			{
				$this->log_message("  Preparing data for publish\n");
			}
			$errors = $quill->prepare($this->quiet, $this->getSetting($quill, "tools_dir"), $this->getSetting($quill, "incoming_dir"));
			if($errors > 0)
			{
				return(implode("\n", $quill->errors));
			}
		}
		
		$hash_file = rtrim($this->getSetting($quill, "hashes_dir"), "/") . "/" . $dataset . ".hash";
		if($republish)
		{
			$changed = true;
		} else {
			$changed = $quill->changedFiles($hash_file);
		}
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
				elseif(in_array("4store", $legacy_publish_actions))
				{
					$item['action'] = "4store";
				}
				else
				{
					$item['action'] = "mysql";
				}
				$item['url'] = $global_import_url;
				$publish_events[] = $item;
			}
		}

		// Dump files

		foreach($publish_events as $publish_event)
		{
			if(strcmp($publish_event['action'], "dump") != 0) { continue; }

			$dump_root = $publish_event['path'];
			$dump_base = $publish_event['url'];
			$dump_quill_root = rtrim($dump_root, "/") . "/" . $dataset;
			$dump = $dump_quill_root . "/" . $dumpdate;
			$smbdump = "";
			$smbcred = "";
			if((array_key_exists("smbcredentials", $publish_event)) && (array_key_exists("smbpath", $publish_event)))
			{
				if((file_exists($publish_event['smbcredentials'])) && (preg_match("|^//|", $publish_event['smbpath']) > 0))
				{
					$smbcred = $publish_event['smbcredentials'];
					$smb_quill_root = rtrim($publish_event['smbpath'], "/") . "/" . $dataset;
					$smbdump = $smb_quill_root . "/" . $dumpdate;
				}
			}
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
			if($republish)
			{
				$errors = $quill->republish($dump, $dump_base . "/" . $dataset . "/" . $dumpdate, $dataset_uri, $this->quiet);
			} else {
				$errors = $quill->publish($dump, $dump_base . "/" . $dataset . "/" . $dumpdate, $dataset_uri, $this->quiet);
			}
			if(($errors == 0) && (strlen($smbdump) > 0))
			{
				$this->log_message("  Publishing data to " . $smbdump . "\n");
				$smbpath = explode("/", ltrim($smbdump, "/"));
				$smb_host = $smbpath[0];
				$smb_point = $smbpath[1];
				$smb_path = implode(array_slice($smbpath, 2), "/");
				$rempath = "";
				foreach(explode("/", $smb_path) as $dirname)
				{
					$rempath = ltrim($rempath . "/" . $dirname, "/");
					$cmd = "smbclient -A " . $smbcred . " //" . $smb_host . "/" . $smb_point . " -c \"mkdir \\\"" . $rempath . "\\\"\" 2> /dev/null";
					shell_exec($cmd);
				}
				$dp = opendir($dump);
				while($file = readdir($dp))
				{
					if(strcmp(substr($file, 0, 1), ".") == 0) { continue; }
					// $this->log_message("    Uploading " . $dump . "/" . $file);
					$cmd = "smbclient -A " . $smbcred . " //" . $smb_host . "/" . $smb_point . " -c \"put \\\"" . $dump . "/" . $file . "\\\" \\\"" . $smb_path . "/" . $file . "\\\"\" 2> /dev/null";
					shell_exec($cmd);
				}
				closedir($dp);

				// WHELK
			}
			if($errors > 0)
			{
				return(implode("\n", $quill->errors));
			}
		}

		// Publish to triplestore(s)

		foreach($publish_events as $publish_event)
		{
			if((strcmp($publish_event['action'], "sesame") != 0) && (strcmp($publish_event['action'], "4store") != 0) && (strcmp($publish_event['action'], "mysql") != 0))
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
			$triplestore_url = "";
			if(array_key_exists("url", $publish_event)) { $triplestore_url = $publish_event['url']; }
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

			if(strcmp("mysql", $triplestore_type) == 0)
			{
				if(!($this->quiet))
				{
					$this->log_message("  Importing into MySQL: " . $graph . "\n");
				}
				$fs = new MySQLStore($publish_event['host'], $publish_event['database'], $publish_event['username'], $publish_event['password']);
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

	public function republishDataset($dataset)
	{
		$this->publishDataset($dataset, true);
	}

	public function log_error( $text )
	{
		global $current_hedgehog_dataset;
		foreach( preg_split( "/\n/", rtrim($text) ) as $line )
		{
			if( $this->logfh )
			{
				fwrite( $this->logfh, date("c")." [$current_hedgehog_dataset][".getmypid()."] *ERROR* ".rtrim($line)."\n" );
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
				fwrite( $this->logfh, date("c")." [$current_hedgehog_dataset][".getmypid()."] ".rtrim($line)."\n" );
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
