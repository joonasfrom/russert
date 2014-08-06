<?php
/**
 * Interface for sources. Source files must be named EXACTLY like the class inside the file.
 * 
 */
interface SourceInterface {
	public function getSourceName();
	public function getCargo();
	public function validateCargo();
}

class Source {
	function __construct() {
		if (empty($this->name)) {
			die("Missing name.");
		}
		
		if (empty($this->link)) {
			die("Missing link.");
		}
		
		if (empty($this->description)) {
			die("Missing description.");
		}
	}
	
	public function validateCargo() {
		$cargo = $this->getCargo();
		
		// Keys to validate.
		$keys = array("title", "link", "guid");
		
		if ($cargo) {
			foreach ($cargo as $item) {
				foreach ($keys as $key) {
					if (empty($item[$key])) {
						return FALSE;
					}
				}
			}
		}
		
		return TRUE;
	}
	
	public function getSourceName() {
		return $this->name;
	}
	
	public function getHtmlDom() {
		$html = file_get_contents($this->link);
		
		$doc = new DOMDocument();
	  $doc->strictErrorChecking = FALSE;
	  @$doc->loadHTML($html);
		
		if ($doc) {
			return $doc;
		}
		
		return FALSE;
	}
}