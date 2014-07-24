<?php

class SesameStore
{
	private $url;
	private $max_triples;

	private function replace_chunk($graph, $triples)
	{
		$url = $this->url;
		$data = trim(str_replace("\n\n", "\n", implode("\n", $triples)));

		$ch = curl_init();
		$fullurl = $url . "?continueOnError=true&context=" . urlencode("<" . $graph . ">") . "&baseURI=" . urlencode("<" . $graph . ">");
		curl_setopt($ch, CURLOPT_URL, $fullurl);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/x-turtle','Content-Length: ' . strlen($data)));
		curl_setopt($ch, CURLOPT_VERBOSE, 0);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT"); 
		curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
		$r = trim(curl_exec($ch));
		//$err = curl_errno($ch);
		//$cherrmsg = curl_error($ch);
		curl_close($ch);

		return($r);
	}
	
	private function append_chunk($graph, $triples)
	{
		$url = $this->url;
		$data = trim(implode("\n", $triples));
		$ch = curl_init();
		$fullurl = $url . "?continueOnError=true&context=" . urlencode("<" . $graph . ">") . "&baseURI=" . urlencode("<" . $graph . ">");
		curl_setopt($ch, CURLOPT_URL, $fullurl);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/x-turtle','Content-Length: ' . strlen($data)));
		curl_setopt($ch, CURLOPT_VERBOSE, 0);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
		$r = trim(curl_exec($ch));
		//$err = curl_errno($ch);
		//$cherrmsg = curl_error($ch);
		curl_close($ch);

		return($r);
	}
	
	public function replace($graph, $triples_unproc)
	{
		function unspace_uris($triple)
		{
			$trp = $triple;
			$m = array();
			if(preg_match("|<([^<>]*)://([^<>]*) ([^<>]*)>|", $trp, $m) > 0)
			{
				$trp = str_replace($m[0], str_replace(" ", "%20", $m[0]), $trp);
			}
			return($trp);
		}
		
		$triples = array_map('unspace_uris', $triples_unproc);

		//var_dump($triples); exit();

		$r = array();
		$c = count($triples);
		if($c <= $this->max_triples)
		{
			$r[] = $this->replace_chunk($graph, $triples);
			return($r);
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
		return($r);
	}
	
	function __construct($url, $max_triples=10000)
	{
		$this->url = $url;
		$this->max_triples = $max_triples;
	}
}
