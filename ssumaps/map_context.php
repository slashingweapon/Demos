<?php

/**
 *	Set up the map page context object.
 *
 *	The main purpose of this script is to generate a big object, which contains all the
 *	information needed to build the web page.  Some member function build a json-equivalent
 *	string, option lists, and other HTML goodies from the input data.
 *
 *	We use simplexml, which converts an XML document into an object.  Much of the time, you can
 *	use the simplexml object as a normal object, but sometimes it helps to explicitly cast the 
 *	data to strings.
 *
 *	For an example of the XML inputs, see locations.xml in this directory.
 *	
 *	@author		CJ Holmes
 */

/**
 *	Context for all of our map data, and methods to generate the stuff we need.
 *
 */
class MapContext {

	function __construct($filename=null) {
		if (isset($filename) && file_exists($filename)) {
			$this->rawData = simplexml_load_file($filename);
		} else {
			// if there is no data, create a blank location list
			$this->rawData = new stdClass();
			$this->rawData->location = array();
		}
		
		$this->url = $_SERVER['SCRIPT_NAME'];
		
		/*	Set our location according to the query parameters */
		if (isset($_GET['location'])) {
			$this->location = $this->findLocation('index', $_GET['location']);
			$this->index = $_GET['location'];
		}
	}
	
	/**
	 *	Return the location that matches the query.  For example, if you want to know which
	 *	location has url of "http://www.google.com", you would use:
	 *	<code>
	 *		$ctx->findLocation('url', 'http://www.google.com');
	 *	</code>
	 *
	 *	@param string $targetKey Which value in the location to look for
	 *	@param string $targetValue The value to match
	 *	@return SimpleXmlEntity The location entity, or null on failure.
	 */
	function findLocation($targetKey, $targetValue) {
		$retval = null;
		
		foreach ($this->rawData->location as $loc) {
			if (!isset($loc->{$targetKey}))
				;	// key is not in this record.  Continue on
			elseif (is_array($loc->{$targetKey})) {
				// is our value in here somewhere?
				if (in_array($targetValue, $loc->{$targetKey})) {
					$retval = $loc;
					break;
				}
			} elseif ($loc->{$targetKey} == $targetValue) {
				$retval = $loc;
				break;
			}
		}
		
		return $retval;
	}
	
	/**
	 *	If there is a current location, return the CSS boundaries for it.  Otherwise, return
	 *	a style of 'visibility: hidden'.
	 *
	 *	@return string
	 */
	function locationCSSBounds() {
		$retval = "visibility: hidden;";
		
		if (isset($this->location->bounds)) {
			$b = $this->location->bounds;
			$retval = "top: {$b->top}px; left: {$b->left}px; width: {$b->width}px; height: {$b->height}px;\n";
		}
		
		return $retval;
	}
	
	/**
	 *	Returns ": " appended with the name of the current location.  If there is no location,
	 *	return a blank string.
	 *
	 *	The intent is to append this string to the title of the maps page.
	 *
	 *	@return string
	 */
	function getSubtitle() {
		$retval = "";
		
		if (isset($this->location->name))
			$retval = ": " . htmlentities($this->location->name, ENT_QUOTES);
		
		return $retval;
	}
	
	/**
	 *	Create the HTML representation of the area tags, one for each location
	 *
	 *	<code>
	 *		<area shape="rect" coords="0,0,100,100" href="map.php?location=someplace" title="Someplace"/>
	 *		<area shape="rect" coords="100,100,10,10" href="map.php?location=otherplace" title="Other Place"/>
	 *	</code>
	 *
	 *	@return string HTML area tags, suitably indented.
	 */
	function getAreaText($indent=0) {
		$retval = array();
		foreach ($this->rawData->location as $loc) {
			if (isset($loc->bounds->top, $loc->index))
			{
				$title = "";
				$index = urlencode($loc->index);
				$url = $this->url;
				$coordinates = $this->boundsToCoordinates($loc->bounds);

				if (isset($loc->name))
					$title = htmlentities($loc->name, ENT_QUOTES);
				
				$retval[] = <<<AREA
<area class="mapArea" shape="rect" coords="$coordinates" href="$url?location=$index" title="$title" alt="$title" id="area_$index"/>

AREA;
			}
		}
		return implode(str_repeat("\t", $indent), $retval);
	}
	
	/**
	 *	Return an option list of all the locations.
	 *
	 *	Use this to generate an option list for your location selection pull-down.  Each option
	 *	tag has the location's index as its value.
	 *
	 *	@param int $indent How many tabs to indent the output
	 *	@return string A blob of HTML option tags.
	 */
	function getLocationSelectText($indent=0) {
		$locations = $this->buildLocationIndex("name");
		return $this->optionTextForArray($locations, $indent, $this->index);
	}
	
	/**
	 *	Return an option list of all the services
	 *
	 *	Use this to generate an option list for the services selection pull-down menu.  Each 
	 *	option tag has the corresponding location's index as its value.
	 *
	 *	@param int $indent How many tabs to indent the output
	 *	@return string A blob of HTML option tags.
	 */
	function getServiceSelectText($indent=0) {
		$locations = $this->buildLocationIndex("service");		
		return $this->optionTextForArray($locations, $indent);
	}
	
	/**
	 *	Takes an associative array, and outputs <option>...</option> tags.  The array key becomes is
	 *	the option text, and the and the array values become the option value attribute.
	 *
	 *	Example Output:
	 *	<code>
	 *		<option value="asc">Anthropology Studies Center</option>
	 *		<option value="darwin">Darwin Hall</option>
	 *	</code>
	 *	
	 *	@param array $array The associative array from which to build the selection list
	 *	@param int $indent The number of tabs to indent the output HTML
	 */
	function optionTextForArray($array, $indent, $index=null) {
		$retval = array();
		
		// now sort and output
		$order = array_keys($array);
		sort($order, SORT_LOCALE_STRING);
		foreach($order as $name) {
			$safeName = htmlentities($name, ENT_QUOTES);
			$value = $array[$name];
			$selected = (isset($index) && $index==$value)
				? "SELECTED"
				: "" ;
			$retval[] = <<<OPTION
<option value="$value" $selected>$safeName</option>

OPTION;
		}
		
		return implode(str_repeat("\t", $indent), $retval);	
	}
	
	/**
	 *	Build an array that maps the data from some field in the locations, to the location
	 *	indexes.
	 *
	 *	Examples:
	 *	<code>
	 *		// map location names to their indexes
	 *		$this->buildLocationIndex("name");
	 *
	 *		// map all services to their location indexes
	 *		$this->buildLocationIndex("service");
	 *	</code>
	 *
	 *	@param string $field The field name to use as keys in the output array
	 *	@return array An associative array mapping $field=>$index
	 */
	function buildLocationIndex($field) {
		$retval = array();
		
		foreach ($this->rawData->location as $loc) {
			if (isset($loc->{$field}, $loc->index)) {
				$value = $loc->index;
				$keyList = $loc->{$field};

				// now add items to our output array
				foreach ($keyList as $key)
					$retval[(string)$key] = (string)$value;
			}
		}
		
		return $retval;
	}
	
	/**
	 *	Takes left,top,width,height and returns "x1,y1,x2,y2".
	 *
	 *	@param $bounds SimpleXmlEntity Must contain top, left, height, width
	 *	@return string A suitable string for map coordinates, or null if they were invalid.
	 */
	function boundsToCoordinates($bounds) {
		$retval = "0,0,0,0";
		
		if (isset($bounds->top, $bounds->left, $bounds->height, $bounds->width)) {
			$x1 = $bounds->left;
			$y1 = $bounds->top;
			$x2 = $x1 + $bounds->width;
			$y2 = $y1 + $bounds->height;
			
			$retval = "$x1,$y1,$x2,$y2";
		}
		
		return $retval;
	}
	
	/**
	 *	If there is a selected location, return an HTML blob that contains all the displayable
	 *	information about the location.  We concern ourselves only with structure and order ...
	 *	styling is up to the CSS.
	 *
	 *	@return string The HTML blob for the current location.
	 */
	function getLocationText() {
		$retval = "";
		
		if (isset($this->location)) {
			$loc = $this->location; // syntactic convenience
			$name = htmlentities($loc->name, ENT_QUOTES);
			$description = htmlentities($loc->description, ENT_QUOTES);
			$directions = htmlentities($loc->directions, ENT_QUOTES);
			$linkText = htmlentities($loc->linkText, ENT_QUOTES);
			$image = $loc->image;
			$services = "";
			if ($loc->service) {
				foreach($loc->service as $oneService)
					$services .= "			<li>" . htmlentities($oneService, ENT_QUOTES) . "</li>\n";
			} else {
				$services .= "			<li><em>There are no departments located in $name.</em></li>\n";
			}
			
			$wifi = "";
			if ($loc->wifi) {
				foreach($loc->wifi as $oneWifi)
					$wifi .= "			<li><a href='{$oneWifi->image}' target='_new'>{$oneWifi->title} <img class='mapWiFi' src='{$oneWifi->image}' titile='{$oneWifi->title}' alt='{$oneWifi->title}'/></a></li>\n";
			} else {
				$wifi .= "			<li><em>There are no WiFi maps for $name</em></li>\n";
			}
			
			$retval = <<<HTML
			
		<h3>$name</h3>
		<img src="$image" alt="Photo of $name"/>
		<p>Departments/Groups in $name Building</p>
		<ul>
$services
		</ul>
		<p><a href="{$loc->link}">$linkText</a></p>
		<p>WiFi Coverage Maps:</p>
		<ul>
$wifi
		</ul>
		<p><a href="contact.php?location={$loc->index}">contact us</a> about this location.</p>
		
		
HTML;
		}
		
		return $retval;
	}
	
	/**
	 *	Encodes all of the location database as a JSON string and returns it.
	 *
	 *	@return string A JSON-encoded string
	 */
	function getLocationsJSON() {
		return json_encode($this->rawData);
	}
	
	/**
	 *	A SimpleXMLElement that represents all we know about all of our locations.
	 */
	public $rawData;
	
	/**
	 *	The URL to this script, without the search arguments.
	 *	eg: /university/maps/maps.php
	 */
	public $url;
	
	/**
	 *	The SimpleXMLElement that represents the currently selected location.  Is undefined if
	 *	no particular location was selected.
	 */
	public $location;
	
	/**
	 *	The index used in the request's search argument named 'location'.  For example, index would
	 *	be 'darwin' for a URL of /university/maps/maps.php?location=darwin
	 */
	public $index;
}
