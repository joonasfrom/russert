<?php
namespace Russert;

use Russert\SourceInterface;

/**
 * Source class for shared functionalities.
 */

abstract class Source implements SourceInterface {
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
	 * Set name.
	 *
	 * @param string $name Source name.
	 * @return void
	 * @author Joonas Kokko
	 */
	function setName($name) {
		$this->name = $name;
	}
	
	
	/**
	 * Set link.
	 *
	 * @param string $link The link.
	 * @return void
	 * @author Joonas Kokko
	 */
	function setLink($link) {
		$this->link = $link;
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
			$doc = new \DOMDocument();
		  $doc->strictErrorChecking = FALSE;
		  @$doc->loadHTML($html);
		
			if ($doc) {
				return $doc;
			}
		}
		
		return FALSE;
	}
}