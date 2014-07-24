<?php

/* Class: 'Provenance'
----------------------
The Provenance class is designed to keep a log of everything that happens
during a quill's lifespan, and output provenance triples if necessary. The
class currently supports the old Southampton-style provenance method, soon
to be deprecated, and the W3C-compliant 'PROV-O'.

Usage: Once the object is created, every file conversion, download or other
process used within the publish process should call the appropriate method.
Before the files are pushed to their final destination, 'generateProvenance'
should be called in order to generate the necessary provenance triples. */

class Provenance
{
	private $log;
	private $downloads;
	private $filetypes;
	private $creation_time;
	
	private function snipHead($path)
	{
		$parse = explode("/", rtrim($path, "/"));
		$c = count($parse);
		
		return($parse[($c - 1)]);
	}
	
	public function logDownloadStart($url, $localfile="")
	{
		$download_file = $this->snipHead($localfile);
	
		$item = array();
		$item['type'] = 'downloadstart';
		$item['from'] = $url;
		$item['to'] = $download_file;
		$item['time'] = time();
		$this->log[] = $item;
	}

	public function logDownloadEnd($url, $localfile="")
	{
		$download_file = $this->snipHead($localfile);
		
		$item = array();
		$item['type'] = 'downloadend';
		$item['from'] = $url;
		$item['to'] = $download_file;
		$item['time'] = time();
		$this->log[] = $item;
	}

	public function fileType($filename, $format)
	{
		$fn = preg_replace("|^(.*)/([^/]+)$|", "$2", $filename);
		$this->filetypes[$fn] = $format;
	}
	
	public function logConvert($fromuri, $touri, $additionaluris, $targetformat="")
	{
		$fromfile = $this->snipHead($fromuri);
		
		$item = array();
		$item['type'] = 'convert';
		$item['from'] = $fromfile;
		$item['to'] = $this->snipHead($touri);
		$item['additional'] = $additionaluris;
		$item['time'] = time();
		$this->log[] = $item;
		
		if(strlen($targetformat) > 0)
		{
			$fn = preg_replace("|^(.*)/([^/]+)$|", "$2", $touri);
			$this->filetypes[$fn] = $targetformat;
		}
	}

	private function getConvertType($fromuri, $touri)
	{
			$fromfile = trim(preg_replace("|^(.*)/([^/]+)$|", "$2", $fromuri));
			$tofile = trim(preg_replace("|^(.*)/([^/]+)$|", "$2", $touri));
			$fromtype = "";
			$totype = "";
			@$fromtype = $this->filetypes[$fromfile];
			@$totype = $this->filetypes[$tofile];
			
			if((strcmp($fromtype, "ntriples") == 0) & (strcmp($totype, "rdfxml") == 0))
			{
				return("http://id.southampton.ac.uk/ns/ConvertNTriplesToRDFXML");
			}
			
			if((strcmp($fromtype, "ntriples") == 0) & (strcmp($totype, "turtle") == 0))
			{
				return("http://id.southampton.ac.uk/ns/ConvertNTriplesToTurtle");
			}
			
			if((strcmp($fromtype, "turtle") == 0) & (strcmp($totype, "ntriples") == 0))
			{
				return("http://id.southampton.ac.uk/ns/ConvertTurtleToNTriples");
			}
			
			if((strcmp($fromtype, "turtle") == 0) & (strcmp($totype, "rdfxml") == 0))
			{
				return("http://id.southampton.ac.uk/ns/ConvertTurtleToRDFXML");
			}
			
			if((strcmp($fromtype, "rdfxml") == 0) & (strcmp($totype, "turtle") == 0))
			{
				return("http://id.southampton.ac.uk/ns/ConvertRDFXMLToTurtle");
			}
			
			if((strcmp($fromtype, "rdfxml") == 0) & (strcmp($totype, "ntriples") == 0))
			{
				return("http://id.southampton.ac.uk/ns/ConvertRDFXMLToNTriples");
			}
			
			return "";
	}
	
	function generateProvenance($path, $base_url)
	{
		$soton = $this->generateSotonProvenance($path, $base_url);
		$prov = $this->generateProv($path, $base_url);
		return(array_merge($soton, $prov));
	}
	
	private function generateProv($path, $base_url)
	{
		$triples = array();
		$checkpath = rtrim($path, "/");
		foreach($this->log as $event)
		{
			$type = $event['type'];
			$uri = rtrim($base_url, "/") . "/" . $event['to'];
			$localfile = rtrim($path, "/") . "/" . $event['to'];
			$fromuri = "";
			if(strlen($event['from']) > 0)
			{
				if(strlen(stristr($event['from'], "://")) > 0)
				{
					$fromuri = $event['from'];
				}
				else
				{
					$parse = explode("/", rtrim($event['from'], "/"));
					$fromuri = rtrim($base_url, "/") . "/" . $parse[(count($parse) - 1)];
				}
			}
			if((!(file_exists($localfile))) & (!(array_key_exists($event['to'], $this->filetypes))))
			{
				continue;
			}
			switch ($type)
			{
				case "downloadstart":
					$triples[] = "<" . $uri . "#provenance> <http://www.w3.org/ns/prov#startedAtTime> \"" . date("c", $event['time']) . "\"^^<http://www.w3.org/2001/XMLSchema#dateTime> .";
					break;
				case "downloadend":
					$triples[] = "<" . $uri . "#provenance> <http://www.w3.org/ns/prov#endedAtTime> \"" . date("c", $event['time']) . "\"^^<http://www.w3.org/2001/XMLSchema#dateTime> .";
					break;
				case "convert":
					if(count($event['additional']) > 0)
					{
						foreach($event['additional'] as $filename)
						{
							$fileuri = rtrim($base_url, "/") . "/" . $filename;
							$triples[] = "<" . $uri . "#provenance> <http://www.w3.org/ns/prov#used> <" . $fileuri . "> .";
							$triples[] = "<" . $fileuri . "> <http://www.w3.org/1999/02/22-rdf-syntax-ns#type> <http://www.w3.org/ns/prov#Entity> .";
						}
						$triples[] = "<" . $uri . "#provenance> <http://www.w3.org/ns/prov#startedAtTime> \"" . date("c", $this->creation_time) . "\"^^<http://www.w3.org/2001/XMLSchema#dateTime> .";
					}
					else
					{
						$triples[] = "<" . $uri . "#provenance> <http://www.w3.org/ns/prov#startedAtTime> \"" . date("c", $event['time']) . "\"^^<http://www.w3.org/2001/XMLSchema#dateTime> .";
					}
					$triples[] = "<" . $uri . "#provenance> <http://www.w3.org/ns/prov#endedAtTime> \"" . date("c", $event['time']) . "\"^^<http://www.w3.org/2001/XMLSchema#dateTime> .";
					break;
			}
			$triples[] = "<" . $uri . "#provenance> <http://www.w3.org/ns/prov#generated> <" . $uri . "> .";
			$triples[] = "<" . $uri . "> <http://www.w3.org/1999/02/22-rdf-syntax-ns#type> <http://www.w3.org/ns/prov#Entity> .";
			if((strlen($fromuri) > 0) & (strcmp($uri, $fromuri) != 0))
			{
				$triples[] = "<" . $uri . "> <http://www.w3.org/ns/prov#hadPrimarySource> <" . $fromuri . "> .";
				$triples[] = "<" . $uri . "#provenance> <http://www.w3.org/ns/prov#used> <" . $fromuri . "> .";
			}
			$triples[] = "<" . $uri . "#provenance> <http://www.w3.org/1999/02/22-rdf-syntax-ns#type> <http://www.w3.org/ns/prov#Activity> .";
		}
		return(array_unique($triples));
	}
	
	private function generateSotonProvenance($path, $base_url)
	{
		$triples = array();
		$checkpath = rtrim($path, "/");
		foreach($this->log as $event)
		{
			$type = $event['type'];
			$uri = rtrim($base_url, "/") . "/" . $event['to'];
			$localfile = rtrim($path, "/") . "/" . $event['to'];
			$fromuri = "";
			if(strlen($event['from']) > 0)
			{
				if(strlen(stristr($event['from'], "://")) > 0)
				{
					$fromuri = $event['from'];
				}
				else
				{
					$parse = explode("/", rtrim($event['from'], "/"));
					$fromuri = rtrim($base_url, "/") . "/" . $parse[(count($parse) - 1)];
				}
			}
			if((!(file_exists($localfile))) & (!(array_key_exists($event['to'], $this->filetypes))))
			{
				continue;
			}
			$converttype = $this->getConvertType($fromuri, $uri);
			switch ($type)
			{
				case "downloadstart":
					$triples[] = "<" . $uri . "#provenance> <http://purl.org/void/provenance/ns/processType> <http://id.southampton.ac.uk/ns/DownloadViaHTTP> .";
					$triples[] = "<" . $uri . "#provenance> <http://www.w3.org/2006/time#hasBeginning> \"" . date("c", $event['time']) . "\"^^<http://www.w3.org/2001/XMLSchema#dateTime> .";
					break;
				case "downloadend":
					$triples[] = "<" . $uri . "#provenance> <http://purl.org/void/provenance/ns/processType> <http://id.southampton.ac.uk/ns/DownloadViaHTTP> .";
					$triples[] = "<" . $uri . "#provenance> <http://www.w3.org/2006/time#hasEnd> \"" . date("c", $event['time']) . "\"^^<http://www.w3.org/2001/XMLSchema#dateTime> .";
					break;
				case "convert":
					if(count($event['additional']) > 0)
					{
						$triples[] = "<" . $uri . "#provenance> <http://purl.org/void/provenance/ns/processType> <http://id.southampton.ac.uk/ns/ConvertAndPublishDataset> .";
						foreach($event['additional'] as $filename)
						{
							$fileuri = rtrim($base_url, "/") . "/" . $filename;
							$sourcefile = preg_replace("|^(.*)/([^/]+)$|", "$2", $event['from']);
							if(preg_match("|^(.*)/" . $sourcefile . "$|", $fileuri) == 0)
							{
								$triples[] = "<" . $uri . "#provenance> <http://id.southampton.ac.uk/ns/processIncludedFile> <" . $fileuri . "> .";
							}
						}
						$triples[] = "<" . $uri . "#provenance> <http://www.w3.org/2006/time#hasBeginning> \"" . date("c", $this->creation_time) . "\"^^<http://www.w3.org/2001/XMLSchema#dateTime> .";
					}
					else
					{
						if(strlen($converttype) > 0)
						{
							$triples[] = "<" . $uri . "#provenance> <http://purl.org/void/provenance/ns/processType> <" . $converttype . "> .";
						}
						$triples[] = "<" . $uri . "#provenance> <http://www.w3.org/2006/time#hasBeginning> \"" . date("c", $event['time']) . "\"^^<http://www.w3.org/2001/XMLSchema#dateTime> .";
					}
					$triples[] = "<" . $uri . "#provenance> <http://www.w3.org/2006/time#hasEnd> \"" . date("c", $event['time']) . "\"^^<http://www.w3.org/2001/XMLSchema#dateTime> .";
					break;
			}
			$triples[] = "<" . $uri . "#provenance> <http://purl.org/void/provenance/ns/resultingDataset> <" . $uri . "> .";
			if((strlen($fromuri) > 0) & (strcmp($uri, $fromuri) != 0))
			{
				$triples[] = "<" . $uri . "#provenance> <http://purl.org/void/provenance/ns/sourceDataset> <" . $fromuri . "> .";
			}
			$triples[] = "<" . $uri . "#provenance> <http://www.w3.org/1999/02/22-rdf-syntax-ns#type> <http://purl.org/void/provenance/ns/ProvenanceEvent> .";
		}
		return(array_unique($triples));
	}
	
	function __construct()
	{
		$this->creation_time = time();
		$this->downloads = array();
		$this->filetypes = array();
	}
}
