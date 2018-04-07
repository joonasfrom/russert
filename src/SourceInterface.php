<?php
namespace Russert;

/**
 * Interface for sources. Source files must be named EXACTLY like the class inside the file.
 * 
 */

interface SourceInterface {
	public function getName();
	public function getClassName();
	public function getCargo();
	public function getDescription();
}
