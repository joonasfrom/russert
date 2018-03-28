<?php
namespace Russert;

use Russert\SourceInterface;

/**
 * Source class for shared functionalities.
 */

abstract class Source implements SourceInterface {
	protected $name = "";
	protected $link = "";
	protected $description = "";
	protected $items = [];

	/**
	 * Constructor.
	 */
	function __construct() {
		// ":D"
	}
	
	
	/**
	 * Get the name of the source. This is in pretty format
	 * @return String The pretty name of the source.
	 * @author Joonas Kokko
	 */
	public function getName() {
		return $this->name;
	}
	
	
	/**
	 * Set name.
	 *
	 * @param string $name Source name.
	 * @return void
	 * @author Joonas Kokko
	 */
	public function setName($name) {
		$this->name = $name;
	}
	
	
	/**
	 * Return the class name of the object. This is only for convenience because we also need to strip the namespace away.
	 *
	 * @return String Name of the class.
	 * @author Joonas Kokko
	 */
	public function getClassName() {
		// https://coderwall.com/p/cpxxxw/php-get-class-name-without-namespace
		$name = (new \ReflectionClass($this))->getShortName();
		return $name;
	}
	
	
	/**
	 * Get link.
	 *
	 * @return String The link.
	 * @author Joonas Kokko
	 */
	public function getLink() {
		return $this->link;
	}
	
	
	/**
	 * Set link.
	 *
	 * @param string $link The link.
	 * @return void
	 * @author Joonas Kokko
	 */
	public function setLink($link) {
		$this->link = $link;
	}
	
	
	/**
	 * Get description
	 *
	 * @return String Description of the source.
	 * @author Joonas Kokko
	 */
	public function getDescription() {
		return $this->description;
	}
	
	
	/**
	 * Returns the HTML DOM.
	 *
	 * @return Mixed DOMDocument on success, false on fail.
	 */
	
	public function getHtmlDom() {
		$html = @file_get_contents($this->getLink());
		
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