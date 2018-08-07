<?php

class MySQLStore
{
	private $db;
	private $max_triples;

	private function uri_id($uri)
	{
		$m = array();
		if(preg_match("#^(.*)(/|\\#)([^/\\#]*)$#", $uri, $m) == 0) { return(0); } // Not a valid URI, return 0.

		$prefix = $m[1] . $m[2];
		$suffix = $m[3];
		$prefix_id = 0;

		$query = "select * from prefix where uri='" . $this->db->escape_string($prefix) . "';";
		$res = $this->db->query($query);
		if($row = $res->fetch_assoc()) { $prefix_id = (int) $row['id']; }
		$res->free();

		if($prefix_id == 0)
		{
			$query = "insert into prefix (uri, prefix, label) values ('" . $this->db->escape_string($prefix) . "', '', '');";
			$this->db->query($query);
			$prefix_id = (int) $this->db->insert_id;
		}

		if($prefix_id == 0) { return(0); } // Something went wrong. Return 0.

		$ret = 0;

		$query = "select * from uris where prefix='" . $prefix_id . "' and name='" . $this->db->escape_string($suffix) . "';";
		$res = $this->db->query($query);
		if($row = $res->fetch_assoc()) { $ret = (int) $row['id']; }
		$res->free();

		if($ret > 0) { return($ret); }

		$query = "insert into uris (prefix, name) values ('" . $prefix_id . "', '" . $this->db->escape_string($suffix) . "');";
		$this->db->query($query);
		$ret = (int) $this->db->insert_id;

		return($ret);
	}

	private function replace_chunk($graph, $triples)
	{
		$graph_id = $this->uri_id($graph);
		$query = "delete from triples where graph='" . $graph_id . "';";
		$this->db->query($query);

		return($this->append_chunk($graph, $triples));
	}

	private function append_chunk($graph, $triples)
	{
		$graph_id = $this->uri_id($graph);

		$g = new Graphite();
		foreach($triples as $ttl)
		{
			$g->addTurtle("http://foo.baa/", $ttl);
		}
		foreach($g->allSubjects() as $res)
		{
			$uri = "" . $res;
			$subject = $this->uri_id($uri);

			foreach($res->relations() as $rel)
			{
				$p_uri = "" . $rel;
				$predicate = $this->uri_id($p_uri);

				foreach($res->all($p_uri) as $obj)
				{
					$dt = $obj->datatype();
					if($dt)
					{
						$type_id = $this->uri_id("" . $dt);
						$query = "insert into triples (graph, s, p, ot, od) values ('" . $graph_id . "', '" . $subject . "', '" . $predicate . "', '" . $type_id . "', '" . $obj . "');";
						$this->db->query($query);
						continue;
					}

					$data = "" . $obj;
					$object_id = 0;

					if(preg_match("/^([a-z]+):/", $data) > 0)
					{
						$object_id = $this->uri_id($obj);
						$data = "";
					}

					$query = "insert into triples (graph, s, p, ot, od) values ('" . $graph_id . "', '" . $subject . "', '" . $predicate . "', '" . $object_id . "', '" . $data . "');";
					$this->db->query($query);
				}
			}
		}
	}

	public function replace($graph, $triples)
	{
		$c = count($triples);
		if($c <= $this->max_triples)
		{
			$r[] = $this->replace_chunk($graph, $triples);
			return(array());
		}
		$i = $this->max_triples;
		$chunk = array_slice($triples, 0, $this->max_triples);
		$r[] = $this->replace_chunk($graph, $chunk);
		while($i < $c)
		{
			$chunk = array_slice($triples, $i, $this->max_triples);
			$r[] = $this->append_chunk($graph, $chunk);
			$i = $i + $this->max_triples;
		}
		return(array());
	}
	
	function __construct($host, $database, $username, $password)
	{
		$this->max_triples = 5000;
		$this->db = new mysqli($host, $username, $password, $database);
	}
}
