<?php

class FourStore
{
	private $url;
	private $max_triples;

	private function replace_chunk($graph, $triples)
	{
		$url = $this->url;
		$data = implode("\n", $triples);
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url . $graph);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/x-turtle','Content-Length: ' . strlen($data)));
		curl_setopt($ch, CURLOPT_VERBOSE, 0);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT"); 
		curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
		$r = trim(curl_exec($ch));
		curl_close($ch);
		
		return($r);
	}
	
	private function append_chunk($graph, $triples)
	{
		$url = $this->url;
		$data = implode("\n", $triples);
		$postdata = array('graph' => $graph, 'data' => $data, 'mime-type' => 'application/x-turtle');
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/x-www-form-urlencoded'));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postdata));
		$r = trim(curl_exec($ch));
		curl_close($ch);	
		
		return($r);
	}
	
	public function replace($graph, $triples)
	{
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
