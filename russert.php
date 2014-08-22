<?php
PHP_SAPI == 'cli' or die();
require("config.php");
require("source.php");
new Russert();

class Russert {
	private $db;
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
		
		$single_source = "";
		
		// Check custom command-line parameters.
		if (!empty($_SERVER['argv']) && count($_SERVER['argv'] > 1)) {
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
		
		$sources = array();
		
		// Load all available sources if the single source mode isn't on.
		if ($single_source) {
			$sources[] = $single_source;
		}
		else {
			$sources = $this->getSources();
		}
		
		if (!$sources) {
			$this->log("No sources found.");
			die();
		}
		
		$this->handleSources($sources);
		
		// Output RSS files.
		$this->handleRssFiles($sources);
	}
	
	
	/**
	 * Do these when we are quitting.
	 **/
	
	function __destruct() {
		// Kill database connection.
		unset($this->db);
		
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
	 *
	 * @return Mixed Array of sources or FALSE if nothing found.
	 */
	
	function getSources() {
		$files = glob(SOURCE_FOLDER . "/*.php");
		
		if ($files) {
			foreach ($files as &$file) {
				$file = end(explode("/", $file));
				$file = reset(explode(".php", $file));
			}

			return $files;
		}
		
		return FALSE;
	}
	
	
	/**
	 * Load a source based on its class name.
	 *
	 * @return Boolean True if loading was successful, false if not.
	 */
	
	function loadSource($name) {
		$filename = SOURCE_FOLDER . "/" . $name . ".php";
		
		if (file_exists($filename)) {
			require_once($filename);
			
			return TRUE;
		}
		
		return FALSE;
	}
	
	
	/**
	 * Handles all sources.
	 *
	 * @return Boolean True/false on success/fail.
	 */
	
	function handleSources(array $sources) {
		foreach ($sources as $source) {
			// Take only the base name.
			$class_name = end(explode("/", $source));
			$class_name = reset(explode(".php", $class_name));
			
			if ($this->loadSource($class_name)) {
				// Create the object.
				$object = new $class_name;
				
				if ($this->handleCargo($object)) {
					return TRUE;
				}
			}
			else {
				$this->log("Loading source " . $class_name . "failed.");
			}
		}
		
		return FALSE;
	}
	
	
	/**
	 * Handles individual set of cargo coming from the source.
	 */
	
	function handleCargo($source) {
		$cargo = $source->getCargo();
		
		if ($cargo) {
			foreach ($cargo as $item) {
				// Try getting the same item from the database.
				if ($this->itemExists($item)) {
					$this->log("Item " . $item['guid'] . " from source " . $source->getSourceName() . " already exists, skipping.");
				}
				else {
					// Insert the item.
					$this->log("Item " . $item['guid'] . " found, saving.");
					$this->saveItem($item, $source->getSourceName());
				}
			}
		}
		else {
			$this->log("Couldn't get any items from " . $source->getSourceName());
		}
	}
	
	
	/**
	 * Handles RSS files to the disk.
	 *
	 * @return Boolean True on success, false on fail.
	 */
	
	function handleRssFiles($source_names) {
		// Get different sources.
		if ($source_names) {
			foreach ($source_names as $source_name) {
				if ($this->loadSource($source_name)) {
					$source_object = new $source_name;

					$items = $this->getLatestItemsBySourceName($source_object->name);

					if ($items) {
						$this->log("Generating RSS feed for " . $source_name);
						$this->saveRssFile($items, $source_object);
					}
				}
				else {
					$this->log("Loading source " . $source_name . " failed.");
					break;
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
	
	function saveRssFile(array $items, $source) {
		ob_start();
		require("rss.tpl.php");
		$html = ob_get_contents();
		ob_end_clean();
		
		if (file_put_contents(RSS_FOLDER . "/" . $source->name . ".xml", $html)) {
			return TRUE;
		}
		
		return FALSE;
	}
	
	
	/**
	 * Check if item exists.
	 * @param Array $item Item array.
	 *
	 * @return Boolean True if the item exists, false if it doesn't.
	 */
	
	function itemExists($item) {
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
	 * @return Mixed Item array or FALSE
	 */
	
	function getItemByGuid($guid) {
		$collection = $this->db->item;
		
		if ($collection && $guid) {
			$item = $collection->findOne(array('guid' => $guid));
			
			if ($item) {
				return $item;
			}
		}
		
		return FALSE;
	}
	
	
	/**
	 * Returns an array of latest items by source.
	 * @param String $source_name Name of the source.
	 * @param Integer $limit How many to return at most.
	 *
	 * @return Mixed Array of items or false if nothing found.
	 */
	function getLatestItemsBySourceName($source_name, $limit = 20) {
		$collection = $this->db->item;
		
		if ($collection && $source_name) {
			$cursor = $collection->find(array('source' => $source_name))->sort(array('seen' => -1))->limit($limit);
			
			if ($cursor) {
				$items = array();
				
				foreach ($cursor as $item) {
					$items[] = $item;
				}
				
				if ($items) {
					return $items;
				}
			}
		}
		
		return FALSE;
	}
	
	
	/**
	 * Save item.
	 * @param Array $item Item array to be saved.
	 * @param String $source_name The source name.
	 *
	 * @return Mixed The item array on success or false on fail.
	 */
	
	function saveItem($item, $source_name) {
		// Validate the item.
		if ($this->isItemValid($item)) {
			// Set date.
			$item['seen'] = new MongoDate();

			// Add source.
			$item['source'] = $source_name;

			// Save.
			$collection = $this->db->item;

			if ($collection) {
				$collection->save($item, array('w' => 1));

				return $item;
			}
		}
		
		return FALSE;
	}
	
	
	/**
	 * A simple item validator.
	 * @param Array $item Item to be validated.
	 *
	 * @return Boolean True if the item is valid, false if not.
	 */
	
	function isItemValid($item) {
		$keys = array("title", "link", "guid");
		
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
	 **/	
	
	function setLock() {
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
	
	function isLocked() {
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
	
	function freeLock() {
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
	 * Connects to MongoDB and sets $this->db.
	 *
	 * @return Boolean True/false.
	 **/
	
	function connectMongo() {
		if ($connection = new Mongo("mongodb://" . MONGODB_HOST)) {
			if ($this->db = $connection->selectDB("russert")) {
				return TRUE;
			}
		}
		
		return FALSE;
	}


	/**
	 * Handles encountered errors by printing them out and mailing them.
	 **/
	 
	function handleErrors() {
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
	 **/
	 
	function sendMails(array $mails) {
		if ($mails && !DEBUG_MODE) {
			$this->log("Sending " . count($mails) . " errors(s) to " . REPORT_EMAIL . ".");
			$message = "";
			
			foreach ($mails as $mail) {
				$message .= $mail . "\n";
			}
			
			$user = exec("whoami");
			$host = exec("hostname");
			$from = $user . "@" . $host;
			
			@mail(REPORT_EMAIL, "Critical Russert error(s)", $message, $from);
		}
	}
	
	
	/**
	 * A simple log function that will also log to Witness in the future.
	 * @param String $message A message to be logged.
	 * @param Boolean $bad If this is bad or not. Bad things are mailed and printed out after the script shuts down.
	 **/
	
	function log($message, $bad = FALSE) {
		echo date('c') . ": " . $message . "\n";
		
		if ($bad) {
			// Add the message to errors.
			$this->errors[] = $message;
		}
	}	
}
