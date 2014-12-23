<?php
/**
 * Interface for sources. Source files must be named EXACTLY like the class inside the file.
 * 
 */

interface SourceInterface {
	public function getSourceName();
	public function getCargo();
}


/**
 * Source class for shared functionalities.
 */

class Source {
	/**
	 * Constructor.
	 */
	
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
	
	
	/**
	 * Return the source name.
	 *
	 * @return String The source name.
	 */
	
	public function getSourceName() {
		return $this->name;
	}
	
	
	/**
	 * Returns the HTML DOM.
	 *
	 * @return Mixed DOMDocument on success, false on fail.
	 */
	
	public function getHtmlDom() {
		$html = @file_get_contents($this->link);
		
		if ($html) {
			$doc = new DOMDocument();
		  $doc->strictErrorChecking = FALSE;
		  @$doc->loadHTML($html);
		
			if ($doc) {
				return $doc;
			}
		}
		
		return FALSE;
	}
}