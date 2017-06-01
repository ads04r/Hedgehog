<?php

class RateMyDataset
{
	private $config;

	function extract_metadata($g)
	{
		$cfg = $this->config;

		$subjects = array();
		$predicates = array();
		$objects = array();
		$types = array();

		foreach($g->allSubjects() as $res)
		{
			$uri = "" . $res;
			if(in_array($uri, $subjects)) { continue; }

			$subjects[] = $uri;
			foreach($res->relations() as $rel)
			{
				$uri = "" . $rel;
				if(in_array($uri, $predicates)) { continue; }

				$predicates[] = $uri;
				if(strcmp($uri, "http://www.w3.org/1999/02/22-rdf-syntax-ns#type") == 0) { continue; }
				foreach($res->all($uri) as $obj)
				{
					$type = trim("" . $obj->datatype());
					$obj_txt = trim("" . $obj);
					if(strlen($type) > 0) { continue; }
					if(in_array($obj_txt, $objects)) { continue; }
					if(preg_match("/^([a-zA-Z]+)\\:/", $obj_txt) == 0) { continue; }

					$objects[] = $obj_txt;
				}
			}
		}

		foreach($subjects as $uri)
		{
			$res = $g->resource($uri);
			foreach($res->all("rdf:type") as $type)
			{
				$type_uri = "" . $type;
				if(in_array($type_uri, $types)) { continue; }

				$types[] = $type_uri;
			}
		}

		sort($types);
		sort($subjects);
		sort($predicates);
		sort($objects);

		return(array(
			"types" => $types,
			"predicates" => $predicates,
			"subjects" => $subjects,
			"objects" => $objects
		));
	}


	function extract_vocabularies($uri_list)
	{
		$namespaces = array();
		$prefixes = array();
		foreach($uri_list as $type)
		{
			$ns = $this->get_namespace($type);
			if(strlen($ns) == 0) { continue; }
			if(in_array($ns, $namespaces)) { continue; }

			$namespaces[] = $ns;
		}

		foreach($namespaces as $uri)
		{
			$prefix = $this->get_prefix($uri);
			if(!(array_key_exists("prefix", $prefix))) { continue; }

			$prefixes[] = $prefix;
		}

		return($prefixes);
	}

	function extract_classes($type_list)
	{
		$ret = array();
		foreach($type_list as $uri)
		{
			$g = new Graphite();
			$g->load($uri);
			$res = $g->resource($uri);
			$label = "" . $res->label();
			if(strcmp($label, "[NULL]") == 0)
			{
				$label = preg_replace("|^(.*)([/#])([^/#]+)$|", "$3", $uri);
			}

			$item = array();
			$item['label'] = $label;
			$item['uri'] = $uri;
			$ret[] = $item;
		}
		return($ret);
	}

	function extract_domains($objects)
	{
		$ret = array();
		foreach($objects as $uri)
		{
			$m = array();
			if(preg_match("|://([^/]+)/|", $uri, $m) == 0) { continue; }

			$domain = $m[1];
			if(in_array($domain, $ret)) { continue; }

			$ret[] = $domain;
		}
		return($ret);
	}

	function get_namespace($uri)
	{
		$ns = preg_replace("|^(.+)([/#])([^/#]*)$|", "$1$2", $uri);
		if(strcmp($ns, $uri) == 0) { return(""); }
		return($ns);
	}

	function get_redirect_target($url)
	{
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_HEADER, 1);
		curl_setopt($ch, CURLOPT_NOBODY, 1);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		$headers = curl_exec($ch);
		curl_close($ch);

		if (preg_match('/^Location: (.+)$/im', $headers, $matches)) { return trim($matches[1]); }
		return("");
	}

	function get_prefix($namespace)
	{
		$cfg = $this->config;

		foreach($cfg['namespaces'] as $ns)
		{
			if(strcmp($ns['uri'], $namespace) != 0) { continue; }

			return($ns);
		}

		$prefix = "";
		$url = $this->get_redirect_target("http://prefix.cc/?q=" . urlencode($namespace));
		if(strlen($url) > 0)
		{
			$prefix = preg_replace("|^(.*)/([^/]+)$|", "$2", $url);
		}

		$g = new Graphite();
		$g->load($namespace);
		$res = $g->resource($namespace);
		$label = "" . $res->label();
		if(strcmp($label, "[NULL]") == 0) { $label = ""; }

		$ret = array();
		if(strlen($label) > 0) { $ret['label'] = $label; }
		if(strlen($prefix) > 0) { $ret['prefix'] = $prefix; }
		$ret['uri'] = $namespace;
		return($ret);
	}

	public function rate($uri)
	{
		$g = new Graphite();
		$g->load($uri);
		$ret = $this->extract_metadata($g);
		$ret['vocabularies'] = $this->extract_vocabularies(array_merge($ret['predicates'], $ret['types']));
		$ret['classes'] = $this->extract_classes($ret['types']);
		$ret['link_targets'] = $this->extract_domains($ret['objects']);

		return($ret);
	}

	public function __construct()
	{
		$this->config = array();

		$etc_path = dirname(dirname(__file__)) . "/settings";
		$dp = opendir($etc_path);
		while(false != ($file = readdir($dp)))
		{
			if(preg_match("/\\.json$/", $file) == 0) { continue; }
			$key = preg_replace("/\\.json$/", "", $file);
			$this->config = array_merge($this->config, json_decode(file_get_contents($etc_path . "/" . $file), true));
		}
		closedir($dp);
	}
}
