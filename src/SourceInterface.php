<?php
namespace Russert;

/**
 * Interface for sources. Source files must be named EXACTLY like the class inside the file.
 * 
 */

interface SourceInterface {
	public function getSourceName();
	public function getCargo();
}