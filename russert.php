<?php
PHP_SAPI == 'cli' or die();
require_once("config.php");

require_once __DIR__ . "/vendor/autoload.php";

new Russert();

class Russert {
	private $sources;
	private $connection;
	private $collection;
	private $errors;
	private $locked;
	
	// "Force" flags.
	private $generate_rss = FALSE;
	private $generate_html = TRUE;
	
	function __construct() {
		// Set start time.
		$this->start_time = microtime(TRUE);

		// Check lock.
		if ($this->isLocked()) {
			$this->log("Lock is in place.");
			die();
		}

		// Set lock.
		$this->setLock();

		// Connect to MongoDB.
		if (!$this->connectMongo()) {
			$this->log("Couldn't connect to MongoDB.");
			die();
		}
		
		// Set collection.
		if (!$this->collection = new MongoDB\Collection($this->connection, "russert", "item")) {
			$this->log("Collection setting failed.");
			die();
		}
		
		// Set some indexes.
		if (!$this->ensureIndexes()) {
			$this->log("Couldn't create indexes to MongoDB.");
			die();
		}
		
		// Help variable for getting single source name.
		$single_source = "";
		
		// Check custom command-line parameters.
		if (!empty($_SERVER['argv']) && is_array($_SERVER) && count($_SERVER['argv']) > 1) {
			// Unset the first since it's the script name.
			unset($_SERVER['argv'][0]);
			
			foreach ($_SERVER['argv'] as $argument) {
				if (strpos($argument, "--source=") !== FALSE) {
					$single_source = explode("--source=", $argument)[1];
					
					// Skipt index generation.
					$this->generate_html = FALSE;
					continue;
				}
				else if ($argument == "--force-rss") {
					$this->log("Forcing RSS generation.");
					$this->generate_rss = TRUE;
					continue;
				}
				elseif ($argument == "--help" || $argument == "-h") {
					echo "Russert RSS file generator\n";
					echo "Usage: php russert.php [OPTIONS]\n";
					echo "    --source=SOURCE NAME   Only process a single source.\n";
					echo "    --force-rss            Forces regeneration of RSS files.\n";
					echo "-h, --help                 Display this message\n";
					die();
				}
			}
		}
		
		$sources = [];
		
		// Load all available sources if the single source mode isn't on.
		if ($single_source) {
			$sources = $this->getSourceFilenames($single_source);
		}
		else {
			$sources = $this->getSourceFilenames();
		}
		
		if (!$sources) {
			$this->log("No sources found.");
			die();
		}
		
		// Load the source objects to $this->sources.
		$this->loadSources($sources);
		
		// Handle the sources and get updates.
		$this->handleSources();
		
		// Output RSS files.
		$this->handleRssFiles();
		
		// Generate HTML index file but only if not running single source mode.
		$this->handleIndex();
	}
	
	
	/**
	 * Do these when we are quitting.
	 */
	
	function __destruct() {
		// Kill database connection.
		unset($this->connection);
		
		// Handle errors.
		$this->handleErrors();
		
		// Remove lock if it has been set on this run.
		if ($this->locked) {
			$this->freeLock();
		}
		
		// Time.
		$this->end_time = microtime(TRUE);		
		$this->log("Process took " . (round($this->end_time - $this->start_time)) . " seconds.");
		
		$this->log("Bye!");
	}
	
	
	/**
	 * Returns a list of source filenames in the folder.
	 * @param String $name A name filter for the query.
	 *
	 * @return Array Array of sources found.
	 */
	
	function getSourceFilenames(string $name = "") : array {
		$file_query = SOURCE_FOLDER . "/*.php";
		
		if (!empty($name)) {
			$file_query = SOURCE_FOLDER . "/{$name}.php";
		}
		
		$files = glob($file_query);
		$sources = [];
		
		if ($files) {
			foreach ($files as &$file) {
				$file = explode("/", $file);
				$file = end($file);
				$file = explode(".php", $file);
				$file = reset($file);
				
				// See if the class exists.
				// FIXME: Get this from somewhere else?
				$namespace = "\\Russert\\Sources\\";
				
				// Filename here.
				$source = $namespace . $file;
				
				if (class_exists($source)) {
					// These all must come from "Source" class.
					$source_object = new $source;
					
					// FIXME: Get this from somewhere else?
					if (is_subclass_of($source_object, "\\Russert\\Source")) {
						$sources[] = $source;
					}
				}
				else {
					$this->log("Loading source {$source} failed.");
				}
			}
		}
		
		return $sources;
	}
	
	
	/**
	 * Loads the sources into memory.
	 *
	 * @param array $sources Flat list of source names to load. This is because of single source support.
	 * @return void
	 * @author Joonas Kokko
	 */
	function loadSources(array $sources) {
		foreach ($sources as $source) {
			$this->sources[] = new $source;
		}
	}
	
	
	/**
	 * Handles all sources.
	 *
	 * @return Boolean True/false on success/fail.
	 */
	
	function handleSources() : bool {
		foreach ($this->sources as &$source) {
			$this->handleCargo($source);
		}
		
		return FALSE;
	}
	
	
	/**
	 * Handles individual set of cargo coming from the source.
	 */
	
	function handleCargo(object $source) : void {
		$this->log("Checking new items for {$source->getName()}...");
		try {
			$cargo = $source->getCargo();
			
			if (DEBUG_MODE) {
				print_r($cargo);
			}
		
			if ($cargo) {
				foreach ($cargo as $item) {
					// Try getting the same item from the database.
					if ($this->itemExists($item)) {
						if (DEBUG_MODE) {
							$this->log("Item " . $item['guid'] . " from source " . $source->getName() . " already exists, skipping.");
						}
					}
					else {
						// Insert the item.
						$this->log("Item " . $item['guid'] . " found from source " . $source->getName() . ", saving.");
						$this->saveItem($item, $source);
						
						// Tell the source that it got updated so we'll re-generate RSS for it.
						$source->setUpdated(TRUE);
					}
				}
			}
			else {
				$this->log("Couldn't get any items from " . $source->getName());
			}
		}
		catch (Exception $e) {
			$this->log("Something went horribly wrong while trying to get cargo from source.");
		}
	}
	
	
	/**
	 * Handles RSS files to the disk.
	 *
	 * @return Boolean True on success, false on fail.
	 */
	
	function handleRssFiles() : bool {
		if ($this->sources) {
			// Get sources that were updated.
			if (!$this->generate_rss) {
				$sources = array_filter($this->sources, function($o) {
					if ($o->getUpdated()) {
						return TRUE;
					}
				});
			}
			else {
				$sources = $this->sources;
			}
			
			if (!empty($sources)) {
				foreach ($sources as $source) {
					$items = $this->getLatestItemsBySource($source);

					if ($items) {
						$this->log("Generating RSS feed for {$source->getName()}");
						$this->saveRssFile($items, $source);
					}
				}
			}
			else {
				$this->log("No updates to RSS files.");
			}
		}
		
		return FALSE;
	}
	
	
	/**
	 * Saves the RSS file to the disk using items and the source.
	 *
	 * @return Boolean True on success, false on fail.
	 */
	
	function saveRssFile(array $items, object $source) : bool {
		ob_start();
		require("rss.tpl.php");
		$html = ob_get_contents();
		ob_end_clean();
		
		$filename = RSS_FOLDER . "/" . $source->getClassName() . ".xml";
		
		if (!DEBUG_MODE) {
			if (file_put_contents($filename, $html)) {
				$this->log("RSS file saved.");
				return TRUE;
			}
		}
		else {
			$this->log("Would save RSS file to {$filename}.");
		}
		
		return FALSE;
	}
	
	
	/**
	 * Saves index.html into the RSS folder.
	 *
	 * @return void
	 * @author Joonas Kokko
	 */
	function handleIndex() {
		if (!$this->generate_html) {
			$this->log("Skipping index generation due to single source mode.");
			return TRUE;
		}
		
		$this->log("Generating index file.");
		$source_filenames = [];
		
		$visible_sources = array_filter($this->sources, function($o) {
			if ($o->getHidden() == FALSE) {
				return TRUE;
			}
		});
		
		// Get filenames
		if (!empty($visible_sources)) {
			foreach ($visible_sources as $source) {
				$source_filenames[] = $source->getClassName() . ".xml";
			}
			
			$this->saveIndexFile($source_filenames);
			$this->log("Index file updated.");
		}
		else {
			$this->log("No visible souces.");
		}
	}
	
	
	function saveIndexFile(array $source_filenames) : bool {
		ob_start();
		require("index.tpl.php");
		$html = ob_get_contents();
		ob_end_clean();
		
		$filename = RSS_FOLDER . "/" . "index.html";
		
		if (!DEBUG_MODE) {
			if (file_put_contents($filename, $html)) {
				return TRUE;
			}
		}
		else {
			$this->log("Would save index file to {$filename}.");
		}
		
		return TRUE;
	}
	
	
	/**
	 * Check if item exists.
	 * @param Array $item Item array.
	 *
	 * @return Boolean True if the item exists, false if it doesn't.
	 */
	
	function itemExists(array $item) : bool {
		$guid = $item['guid'];
		
		if ($guid) {
			// FIXME
			$existing = (array) $this->getItemByGuid($guid);
			
			if (!empty($existing)) {
				return TRUE;
			}
		}
		else {
			$this->log("No GUID found for " . $item['title']);
		}
		
		return FALSE;
	}
	
	/**
	 * Returns an item from MongoDB by GUID.
	 *
	 * @return Object Item Object.
	 */
	
	function getItemByGuid(string $guid) : object {
		// FIXME: ":D"
		$item = (object) [];
		
		if ($this->collection && $guid) {
			$item = $this->collection->findOne(array('guid' => $guid));
			
			if ($item) {
				return $item;
			}
		}
		
		return (object) []; // ":D" FIXME
	}
	
	
	/**
	 * Returns an array of latest items by source.
	 * @param Object $source Source object..
	 * @param Integer $limit How many to return at most.
	 *
	 * @return Mixed Array of items or false if nothing found.
	 */
	function getLatestItemsBySource(object $source, int $limit = 20) : array {
		$items = [];
		
		if ($this->collection && $source) {
			$cursor = $this->collection->find(array('source' => $source->getClassName()), array("sort" => array('seen' => -1), "limit" => $limit));
			
			if ($cursor) {
				$items = [];
				
				foreach ($cursor as $item) {
					$items[] = $item;
				}
			}
		}
		
		return $items;
	}
	
	
	/**
	 * Save item.
	 * @param Array $item Item array to be saved.
	 * @param Object $source The source object.
	 *
	 * @return Boolean True or false on success/fail.
	 */
	
	function saveItem(array $item, object $source_object) : bool {
		// Validate the item.
		if ($this->isItemValid($item) && !DEBUG_MODE) {
			// Set date.
			$item['seen'] = new MongoDB\BSON\UTCDateTime(round(microtime(TRUE) * 1000));

			// Add source.
			$item['source'] = $source_object->getClassName();

			// Save.
			if ($this->collection && $this->collection->insertOne($item)) {
				return TRUE;
			}
		}
		else if (DEBUG_MODE) {
			// Print the item to the log.
			$this->log("Would insert: " . json_encode($item));
			return TRUE;
		}
		
		return FALSE;
	}
	
	
	/**
	 * A simple item validator.
	 * @param Array $item Item to be validated.
	 *
	 * @return Boolean True if the item is valid, false if not.
	 */
	
	function isItemValid(array $item) : bool {
		$keys = [ "title", "link", "guid" ];
		
		foreach ($keys as $key) {
			if (empty($item[$key])) {
				return FALSE;
			}
		}
		
		return TRUE;
	}
	
	
	/**
	 * Set lock.
	 *
	 * @return Boolean Success/fail.
	 */	
	
	function setLock() : bool {
		// Lock is disabled.
		if (DEBUG_MODE) {
			return TRUE;
		}

		if (file_put_contents(LOCKFILE, "Locked as of " . date('c'))) {
			// This will tell the program that the lock has been set within this run.
			$this->locked = TRUE;
			
			return TRUE;
		}
		else {
			$this->log("Couldn't create lockfile.");
			
			return FALSE;
		}
	}
	
	
	/**
	 * See if we are locked or not.
	 *
	 * @return Boolean True/false.
	 */
	
	function isLocked() : bool {
		// Lock is disabled.
		if (DEBUG_MODE) {
			return FALSE;
		}
		
		if (file_exists(LOCKFILE)) {
			$created = filectime(LOCKFILE);
			$check = time() - (LOCKFILE_MINUTES * 60);
			
			if ($created > $check) {
				return TRUE;
			}
		}
		
		return FALSE;
	}
	
	
	/**
	 * Free the lock.
	 *
	 * @return Boolean True/false.
	 */
	
	function freeLock() : bool {
		if (file_exists(LOCKFILE) && unlink(LOCKFILE)) {
			return TRUE;
		}
		else {
			$this->log("Couldn't remove lockfile. Might be due to the fact that it doesn't exist");
			return FALSE;
		}
	}
	
	
	/* --- Other internals --- */
	
	
	/**
	 * Connects to MongoDB and sets $this->connection.
	 *
	 * @return Boolean True/false.
	 */
	
	function connectMongo() : bool {
		if ($this->connection = new MongoDB\Driver\Manager("mongodb://" . MONGODB_HOST)) {
			return TRUE;
		}
		
		return FALSE;
	}
	
	
	/**
	 * Just a simple function to ensure that we have correct MongoDB indexes in the collection(s).
	 *
	 * @return boolean True/false.
	 * @author Joonas Kokko
	 */
	function ensureIndexes() : bool {
		if ($this->connection && $this->collection) {
			if (!$this->collection->createIndex(array('guid' => 1))) {
				return FALSE;
			}
			
			if (!$this->collection->createIndex(array('source' => 1))) {
				return FALSE;
			}
			
			if (!$this->collection->createIndex(array('seen' => -1))) {
				return FALSE;
			}
			
			return TRUE;
		}
		
		// Argh.
		return FALSE;
	}


	/**
	 * Handles encountered errors by printing them out and mailing them.
	 */
	 
	function handleErrors() : void {
		if ($this->errors) {
			$this->log("Encountered " . count($this->errors) . " serious errors:");
			
			foreach ($this->errors as $error) {
				$this->log($error);
			}
			
			if (REPORT_EMAIL) {
				$this->sendMails($this->errors);
			}
		}
	}
	
	
	/**
	 * Compile one mail from a set of errors.
	 */
	 
	function sendMails(array $mails) : void {
		if ($mails && !DEBUG_MODE) {
			$this->log("Sending " . count($mails) . " errors(s) to " . REPORT_EMAIL . ".");
			$message = "";
			
			foreach ($mails as $mail) {
				$message .= $mail . "\n";
			}
			
			$user = exec("whoami");
			$host = exec("hostname");
			$from = "{$user}@{$host}";
			
			@mail(REPORT_EMAIL, "Critical Russert error(s)", $message, $from);
		}
	}
	
	
	/**
	 * A simple log function
	 * @param String $message A message to be logged.
	 * @param Boolean $bad If this is bad or not. Bad things are mailed and printed out after the script shuts down.
	 */
	
	function log(string $message, bool $bad = FALSE) : void {
		echo date('c') . ": " . $message . "\n";
		
		if ($bad) {
			// Add the message to errors.
			$this->errors[] = $message;
		}
	}	
}
