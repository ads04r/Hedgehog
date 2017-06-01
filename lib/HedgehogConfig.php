<?php

class HedgehogConfig extends ArrayObject
{
	private $global_settings;

	/* The constructor loads all the values into memory. If called with an argument,
	   the argument is the path name of the settings files. Otherwise it checks the
	   environment variable 'HEDGEHOG_CONFIG', then the path '../settings' relative
	   to the running script. If no settings directory can be found, the object is
	   still created but contains no data.
	*/
	function __construct($settings_path="")
	{
		if(strlen($settings_path) == 0)
		{
			@$settings_path = $_SERVER['HEDGEHOG_CONFIG'];
		}
		if(strlen($settings_path) == 0)
		{
			$parse = explode("/", rtrim(__FILE__, "/"));
			$c = count($parse);
			if($c > 2)
			{
				$parse[($c - 1)] = "";
				$parse[($c - 2)] = "settings";
				$settings_path = rtrim(implode("/", $parse), "/");
			}
		}
		$r = array();
		if(is_dir($settings_path))
		{
			if($handle = opendir($settings_path))
			{
				while (false !== ($entry = readdir($handle)))
				{
					$full_path = $settings_path . "/" . $entry;
					if(is_dir($full_path))
					{
						continue;
					}

					if(preg_match("/\\.template$/", $full_path) > 0) { continue; }

					$json = json_decode(file_get_contents($full_path), true);
					if(is_array($json))
					{
						foreach($json as $k => $v)
						{
							$r[$k] = $v;
						}

						continue;
					}

					$lines = file($full_path);
					foreach($lines as $line)
					{
						$settingline = trim($line);
						if(strlen($settingline) <= 0)
						{
							continue;
						}
						if(strcmp(substr($settingline, 0, 1), "#") != 0)
						{
							$settingarray = explode(" ", $settingline, 2);
							if(count($settingarray) == 2)
							{
								$k = $settingarray[0];
								$r[$k] = $settingarray[1];
							}
						}
						
					}
				}
				closedir($handle);
			}		
		}
		$this->global_settings = $r;
	}
	
	function __get($key)
	{
		if(array_key_exists($key, $this->global_settings))
		{
			return($this->global_settings[$key]);
		}
		else
		{
			return("");
		}
	}
	
	function __set($key, $value)
	{
		$this->global_settings[$key] = $value;
	}
	
	public function __isset($name)
	{
		return true;
	}
}
