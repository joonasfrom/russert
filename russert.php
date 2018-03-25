<?php
PHP_SAPI == 'cli' or die();
require_once("config.php");

require_once __DIR__ . "/vendor/autoload.php";

new Russert();

class Russert {
	private $connection;
	private $collection;
	private $errors;
	private $locked;
	
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
				}
				elseif ($argument == "--help" || $argument == "-h") {
					echo "Russert RSS file generator\n";
					echo "Usage: php russert.php [OPTIONS]\n";
					echo "    --source=SOURCE NAME   Only process a single source.\n";
					echo "-h, --help                 Display this message\n";
					die();
				}
			}
		}
		
		$sources = [];
		
		// Load all available sources if the single source mode isn't on.
		if ($single_source) {
			$sources = $this->getSources($single_source);
		}
		else {
			$sources = $this->getSources();
		}
		
		if (!$sources) {
			$this->log("No sources found.");
			die();
		}
		
		// Handle the sources.
		$this->handleSources($sources);
		
		// Output RSS files.
		$this->handleRssFiles($sources);
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
	 * Returns a list of sources in the folder.
	 * @param String $name A name filter for the query.
	 *
	 * @return Array Array of sources found.
	 */
	
	function getSources(string $name = "") : array {
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
	 * Handles all sources.
	 *
	 * @return Boolean True/false on success/fail.
	 */
	
	function handleSources(array $sources) : bool {
		foreach ($sources as $source) {
			$classname = $source;
			
			// Create the object.
			$source = new $source;
			
			if ($this->handleCargo($source)) {
				return TRUE;
			}
		}
		
		return FALSE;
	}
	
	
	/**
	 * Handles individual set of cargo coming from the source.
	 */
	
	function handleCargo($source) : void {
		try {
			$cargo = $source->getCargo();
		
			if ($cargo) {
				foreach ($cargo as $item) {
					// Try getting the same item from the database.
					if ($this->itemExists($item)) {
						$this->log("Item " . $item['guid'] . " from source " . $source->getSourceName() . " already exists, skipping.");
					}
					else {
						// Insert the item.
						$this->log("Item " . $item['guid'] . " found from source " . $source->getSourceName() . ", saving.");
						$this->saveItem($item, $source->getSourceName());
					}
				}
			}
			else {
				$this->log("Couldn't get any items from " . $source->getSourceName());
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
	
	function handleRssFiles($sources) : bool {
		if ($sources) {
			foreach ($sources as $source) {
				$source_object = new $source;

				$items = $this->getLatestItemsBySourceName($source_object->name);

				if ($items) {
					$this->log("Generating RSS feed for {$source_object->name}");
					$this->saveRssFile($items, $source_object);
				}
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
		
		if (!DEBUG_MODE) {
			if (file_put_contents(RSS_FOLDER . "/" . $source->name . ".xml", $html)) {
				return TRUE;
			}
		}
		else {
			$this->log("Would save RSS file for {$source->name}.");
		}
		
		return FALSE;
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
			$existing = $this->getItemByGuid($guid);
			
			if ($existing) {
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
	 * @param String $source_name Name of the source.
	 * @param Integer $limit How many to return at most.
	 *
	 * @return Mixed Array of items or false if nothing found.
	 */
	function getLatestItemsBySourceName(string $source_name, int $limit = 20) : array {
		$items = [];
		
		if ($this->collection && $source_name) {
			$cursor = $this->collection->find(array('source' => $source_name), array("sort" => array('seen' => -1), "limit" => $limit));
			
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
	 * @param String $source_name The source name.
	 *
	 * @return Boolean True or false on success/fail.
	 */
	
	function saveItem(array $item, string $source_name) : bool {
		// Validate the item.
		if ($this->isItemValid($item) && !DEBUG_MODE) {
			// Set date.
			$item['seen'] = new MongoDB\BSON\UTCDateTime(round(microtime(TRUE) * 1000));

			// Add source.
			$item['source'] = $source_name;

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
