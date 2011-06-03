<?php
declare(encoding="UTF-8");

/**
 *	@copyright 2010 YoHOLLA International Inc.. All rights are reserved.
 *	@author CJ Holmes <cj.holmes@yoholla.com>
 *	@package Y
 */


/**
 *	A caching system, based on memcached
 *
 *	This API provides several cache-related services:
 *	- Generates cache keys, which prevents name collision
 *	- Can tell you what all the keys are for a given subject
 *	- Get/Set a single key for a given subject
 *	- Get/Set multiple keys for a given subject efficiently
 *	- Flush keys for a given subject
 *	- Keeps a local copy of all retrieved cache items, in case they are needed again during the same process
 *
 *	Using the API is easy.  To retrieve something from cache, and write it back out:
 *	<code>
 *	$x = Y_Cache::get('jones', Y_Cache::GROUPS_KEY);
 *	if($x === null) {
 *		$x = prepareGroupList('jones');
 *		Y_Cache::set('jones', Y_Cache::GROUPS_KEY, $x);
 *	}
 *	</code>
 *
 *	The subject names should not contain the '/' character.  Other than that, they can be any
 *	string.
 *
 *	You should only use the keys that are defined by Y_Cache.  This isn't so much for the collision
 *	avoidance (although that's nice) but because we would like to be able to flush all keys for
 *	a given subject sometimes.  We can only do that if we know of all the keys for a given
 *	subject.
 *
 *	We expect the memcached settings to be available in the yoholla.ini file as a comma-separated
 *	list of IP:port pairs. Like so:
 *	<code>
 *		[Directory]
 *		memcached="127.0.0.1:11211,127.0.0.2:11212"
 *	</code>
 */
class Y_Cache {

	const ZONES = 'zones';
	const FLIPPED_ZONES = 'flipped-zones';
	const FRIENDS = 'friends';
	const NAMED_FRIENDS = 'named-friends';
	const RATE_COUNT = 'ratings';
	const RATE_SCORE = 'rating-score';
	const IMAGES = 'images';
	const PAD = 'pad';
	const TEST = 'unit-test';
	
	/**
	 *	Get the cached data for the given subject and key.
	 *
	 *	If you want to get George's friends from the cache, you ask for:
	 *	<code>
	 *		$data = Y_Cache::get('george', Y_Cache::FRIENDS);
	 *	</code>
	 *
	 *	Like memcached, we return false when a key is not available in the cache.
	 *
	 *	@param string $subject The person/thing that is the subject of the data
	 *	@param string $key The specific piece of data you want
	 *	@return mixed The desired data, or NULL if it wasn't available
	 */
	static public function get($subject, $key) {
		$cache = self::singleton();
		$key = self::generateKey($subject, $key);

		if (!isset(self::$local[$key])) {
			self::$local[$key] = $cache->get($key);
			Y_Log::debug('cache get remote %s', $key);
		} else
			Y_Log::debug('cache get local  %s', $key);
		
		return self::$local[$key];
	}
	
	/**
	 *	Gets cached data, but only if it exists locally.
	 *
	 *	Use get/setLocal() when you want to store things globally by key, but you
	 *	don't want them pushed out to the remote cache.  This data disappears at the
	 *	end of each execution of PHP.
	 *
	 *	@param string $subject The person/thing that is the subject of the data
	 *	@param string $key The specific piece of data you want
	 *	@return mixed The desired data, or NULL if it wasn't available
	 */
	static public function getLocal($subject, $key) {
		$key = self::generateKey($subject, $key);

		if (!isset(self::$local[$key]))
			self::$local[$key] = false;
		
		return self::$local[$key];
	}

	/**
	 *	Get mulitple keys at once
	 *
	 *	This implementation doesn't just call get() multiple times, because
	 *	memcached (upon which this is based) provides some super-secret awesomesauce for
	 *	bulk retrieval.  
	 *
	 *	@param string $subject The person/thing about which you want more knowledge
	 *	@param array $keys The keys of the data to retrieve.
	 *	@return mixed The desired data, in a keyed array.
	 */
	static public function getMulti($subject, $keys) {
		$cache = self::singleton();
		$fetchArray = array();
		$retval = array();
		
		foreach($keys as $oneKey) {
			$genKey = self::generateKey($subject, $oneKey);
			if(isset(self::$local[$genKey])) {
				$retval[$oneKey] = self::$local[$genKey];
				Y_Log::debug('cache get local  %s', $genKey);
			} else
				$fetchArray[] = $genKey;
		}
		
		// Things not found in the local cache are looked up, copied, and returned
		if(!empty($fetchArray)) {
			$moreStuff = $cache->getMulti( $fetchArray );
			if(!empty($moreStuff)) {
				foreach($moreStuff as $genKey=>$value) {
					self::$local[$genKey] = $value;
					list($subject,$key) = self::parseKey($genKey);
					$retval[$key] = $value;
					Y_Log::debug('cache get remote %s', $genKey);
				}
			}
		}
		
		return $retval;
	}
	
	/**
	 *	Retrieves data for all the known keys for a given subject
	 *
	 *	@param string $subject The person/item you want to know about.
	 */
	static public function getAll($subject) {
		$retval = array();
		$reflection = new ReflectionClass('Y_Cache');
		$keyConsts = $reflection->getConstants();
		return self::getMulti($subject, array_values($keyConsts));
	}
	
	/**
	 *	Change the value of the given bit of data
	 *
	 *	@param string $subject The person/thing that is the subject of the data
	 *	@param string $key The specific piece of data you want to change
	 *	@param string $value The new value of the data
	 *	@param integer $timeout Defaults to one hour
	 *	@param boolean True if the set operation succeeded.
	 */
	static public function set($subject, $key, $value, $timeout=-1) {
		$cache = self::singleton();
		$key = self::generateKey($subject, $key);

		if($timeout == -1 || !is_int($timeout))
			$timeout = self::$timeout;
		
		self::$local[$key] = $value;

		Y_Log::debug('cache set %s', $key);
		return $cache->set($key, $value, $timeout);
	}

	/**
	 *	Set data for a given key, but only locally.
	 *
	 *	@see getLocal()
	 *	@param string $subject The person/thing that is the subject of the data
	 *	@param string $key The specific piece of data you want to change
	 *	@param string $value The new value of the data
	 *	@return boolean Always true
	 */
	static public function setLocal($subject, $key, $value) {
		$key = self::generateKey($subject, $key);

		self::$local[$key] = $value;

		Y_Log::debug('cache set local %s', $key);
		return true;
	}

	/**
	 *	Set a bunch of data all at once.
	 *
	 *	Not sure if this is really helpful.  But I've included it
	 *	for the sake of symmetry with getMulti().
	 *
	 *	@param string $subject The person/thing that is the subject of the data
	 *	@param array $values Key/Value pairs in an associative array
	 *	@param int $timeout The timeout value to use for the data
	 *	@return int The number of items successfully set.
	 */
	static public function setMulti($subject, $values, $timeout=-1) {
		$retval = 0;
		
		foreach($values as $key=>$value)
			if( self::set($subject, $key, $value, $timeout) )
				$retval++;

		return $retval;
	}
	
	/**
	 *	Increments the given data by the desired amount
	 *
	 *	@param string $subject The person/thing that is the subject of the data
	 *	@param string $key The specific piece of data you want to change
	 *	@param string $offset How much to increment the data by
	 *	@param int The new value, or false if the key didn't exist
	 */
	static public function increment($subject, $key, $offset = 1) {
		$cache = self::singleton();
		$key = self::generateKey($subject, $key);
		
		Y_Log::debug('cache increment %s by %d', $key, $offset);
		unset(self::$local[$key]);
		return $cache->increment($key, $offset);
	}
	
	/**
	 *	Decrements the given data by the desired amount
	 *
	 *	@param string $subject The person/thing that is the subject of the data
	 *	@param string $key The specific piece of data you want to change
	 *	@param string $offset How much to decrement the data by
	 *	@param int The new value, or false if the key didn't exist
	 */
	static public function decrement($subject, $key, $offset = 1) {
		$cache = self::singleton();
		$key = self::generateKey($subject, $key);
		
		Y_Log::debug('cache increment %s by %d', $key, $offset);
		unset(self::$local[$key]);
		return $cache->decrement($key, $offset);
	}
	
	/**
	 *	Remove the indicated data from the cache
	 *
	 *	@param string $subject The subject of the data to be deleted
	 *	@param string $key Bit of data to be removed from cache
	 */
	static public function delete($subject, $key) {
		$cache = self::singleton();
		$key = self::generateKey($subject, $key);
		unset(self::$local[$key]);
		$cache->delete($key);
		Y_Log::debug('cache delete %s', $key);
	}
	
	/**
	 *	Delete multiple keys for the same subject
	 *
	 *	@param string $subject The subject of the data to be deleted
	 *	@param array $keys The keys you want to delete
	 */
	static public function deleteMulti($subject, $keys) {
		foreach($keys as $oneKey)
			self::delete($subject, $oneKey);
	}
	
	/**
	 *	Flushes all the keys associated with a particular subject
	 *
	 *	This only flushes the keys which are Y_Cache constants.
	 *
	 *	@param string $subject The person/item that should no longer have stuff in the cache
	 */
	static public function deleteAll($subject) {
		$reflection = new ReflectionClass('Y_Cache');
		$keyConsts = $reflection->getConstants();
		self::deleteMulti($subject, array_values($keyConsts));
	}
	
	/**
	 *	Delete all local copies of all data
	 *
	 *	This is just for debugging purposes.  If you provide both a $subject
	 *	and a $key, then that specific piece of data is removed from the local cache.
	 *	If either parameter is null then the entire local cache is erased.
	 *
	 *	@param string $subject The subject of the data to be deleted
	 *	@param array $keys The keys you want to delete
	 */
	static public function deleteLocal($subject=null, $key=null) {
		if($subject!==null && $key!==null) {
			$key = self::generateKey($subject, $key);
			unset(self::$local[$key]);
			Y_Log::debug('cache delete local %s', $key);
		} else
			self::$local = array();
	}
	
	/**
	 *	A no-muss, no-fuss way to get a valid memcached object that is initialized and ready to go.
	 *
	 *	Normally, you want to use the static {@link get()} and {@link set()} methods, but if you
	 *	have some specialized need not covered by this API, then you can use singleton() to get
	 *	a ready-to-use memcached instance.
	 *
	 *
	 *	<code>
	 *		$cache = Y_Cache::singleton();
	 *		$cache->set($key, $value);
	 *	</code>
	 *	@return Memcached A valid cache object.
	 */
	static public function singleton() {
		if (self::$singleton === null)
			self::$singleton = self::createCache();
		return self::$singleton;
	}

	/**
	 *	Returns the comma-separated list of servers from the config file.
	 *
	 *	This is principally used by Y_Session to initialize the memcached session storage.
	 *	There is no other known reason to use this.
	 *
	 *	@return string A comma-separated list of ip:port pairs.
	 */
	static public function getServers() {
		self::loadSettings();
		return self::$servers;
	}

	/**
	 *	Return a usable memcached object.
	 *
	 *	Typically, you should
	 *	use {@link Y_Cache::singleton()}.  But if for some reason you want a separate cache
	 *	object, you can use this function instead.
	 *
	 *	@return Memcached A valid cache object.
	 */
	static public function createCache() {
		$retval = null;
		$finalList = array();

		$serverString = self::getServers();

		/*	We need to parse the comma-separated ip:port list into an array of
			( (ip,port), (ip,port) )
		*/
		$serverPairs = explode(',', $serverString);
		foreach($serverPairs as $onePair) {
			$onePair = trim($onePair);
			list($ip, $port) = explode(':', $onePair);
			$finalList[] = array($ip, $port);
			Y_Log::debug('Added the memcached server (%s, %s)', $ip, $port);
		}
		$retval = new Memcached();
		$retval->addServers($finalList);

		return $retval;
	}

	/**#@+
	 *	@access private
	 */

	static private function loadSettings() {
		if (self::$servers === null) {
			self::$servers = '127.0.0.1:11211';
			$dir = Y::getConfig('Directory');
			if(isset($dir['memcached']))
				self::$servers = $dir['memcached'];
			else
				Y_Log::warn('Defaulting to %s', self::$servers);
		}
	}

	static private function generateKey($subject, $key) { return "$subject/$key"; }
	static private function parseKey($key) { return explode('/', $key, 2); }
	
	static private $singleton = null;
	static private $servers = null;
	static private $timeout = 3600;
	static private $local = array();
	
	/**#@-*/
}
