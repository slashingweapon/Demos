$(document).ready(function() {

	$(".mapArea").click(areaClick);
	$(".mapDefaultArea").click(defaultAreaClick);
	$(".mapMenu").change(areaMenuChange);
	
	/*	In the use case where someone used maps.php?location=darwin, we're going to have the
		php-generated content present.  But we don't want that if JavaScript is running.  Instead,
		we remove the PHP-generated things and then simulate a click on the gChosenLocation area of 
		the map.
	*/
	$(".mapSelectionBounds").remove();
	$(".mapLocation").remove();

	if (gChosenLocation) {
		$("#area_"+gChosenLocation).click();
	}
});

/**
 *	This is the click handler for the areas on the map.  Each area tag has an ID, which indicates
 *	the specific area that was clicked.  From that ID we extract the data for that location from
 *	the gLocations object.  Then we highlight the relevant area on the map, and build information
 *	display.
 *
 */
function areaClick(evt) {
	evt.preventDefault();

	// the id of the area element should be 'area_<index>'
	var index = $(this).attr('id').substr(5);
	var loc = findLocationByIndex( index );
	
	// get rid the current location information, if it is present.
	clearLocation();
	
	// The map coordinate data is usually encoded as strings, so we have to parse the numbers
	// and do some math to find out our border boundaries.  We put the information about the
	// location to the right of the location on the map, with the tops of both regions aligned.
	var positionTop = parseInt(loc.bounds.top);
	var positionLeft = parseInt(loc.bounds.left) + parseInt(loc.bounds.width);
	var positionWidth = parseInt(loc.bounds.width);
	var positionHeight = parseInt(loc.bounds.height);
	
	// build, position, hide, tabbulate, and then append the location information
	
	var locDescription = buildHtml(loc);
	$(locDescription).css( {
		top:  positionTop  + 'px', 
		left: positionLeft + 'px'
	} );
	$(locDescription).hide();
	setupTabs(locDescription);
	$('.mapContainer').append(locDescription);
	
	// build, position, hide, tabbulate, and then append the pin graphic.
	// We know the pin is 40x40, and the tip of the pin is in the center of the graphic.
	// Do the math appropriately.
	
	var pin = getPinNode('pin.gif', 'locationPin');
	var pinWidth = 40;
	var pinHeight = 40;
	var pinTop = Math.round(parseInt(loc.bounds.top) + (parseInt(loc.bounds.height)/2) - (pinHeight/2));
	var pinLeft = Math.round(parseInt(loc.bounds.left) + (parseInt(loc.bounds.width)/2) - (pinWidth/2));
	$(pin).css( {
		top: pinTop + 'px',
		left: pinLeft + 'px'
	} );
	$(pin).hide();
	$('.mapContainer').append(pin);

	// Now fade in both the info div and the pin.	
	$(pin).fadeIn()
	$(locDescription).fadeIn();
	
}

/*	If you click on the map, but outside any defined areas, we just clear the current location 
 *	information.
 */
function defaultAreaClick(evt) {
	evt.preventDefault();
	clearLocation();
}

/*	There are two menus: one to pick a location by building name, and another to pick a location
 *	by Service/Department.  Both of these menus use as their values the ID of the location.
 *	When one of these menus is changed, we generate a click event on the corresponding map area.
 *
 */
function areaMenuChange(evt) {
	evt.preventDefault();

	/*	From the changed selection list, grab the value of the selected item.  Turn that into
		the selector for the corresponding area, and 'click' it.
	*/
	var location = $(this).find(":selected").attr('value');
	if (location) {
		$("#area_"+location).click();
	}
}

/*	To clear the location information currently diplayed, just find elements with the relevant
 *	classes, and call remove().
 */
function clearLocation() {
	$('.locationInformation').remove();
	$('.locationPin').remove();
}

/*	Search through our array of locations for the location whose 'index' value is equal to 
 *	indexName.
 */
function findLocationByIndex(indexName) {
	var retval = null;
	var locationArray = gLocations.location;
	
	for (var idx=0; idx<locationArray.length; idx++) {
		if (locationArray[idx].index == indexName) {
			retval = locationArray[idx];
			break;
		}
	}
	return retval;
}

/*	
	The locationInformation div is set up to be a tabbed entity.  So we need to generate something
	like this:	
*/
function buildHtml(loc) {
	var template = new String(
	'	<ul class="tab-nav">\n' +
	'		<li><a href="#locationGeneral">General</a></li>\n' +
	'		<li><a href="#locationServices">Departments</a></li>\n' +
	'		<li><a href="#locationWiFi">WiFi</a></li>\n' +
	'	</ul>\n' +
	'	<div class="tab-content-frame">\n' +
	'		<div id="locationGeneral" class="tab-content">\n' +
	'			<h3 class="locationName">%name%</h3>\n' +
	'			<img src="%image%"/>\n' +
	'			<p class="locationDescription">%description%</p>\n' +
	'			<p class="locationDirections">%directions%</p>\n' +
	'			<p class="locationContact"><a href="contact.php?location=%index%">Contact us</a> about this location.</p>\n' +
	'		</div>\n' +
	'		<div id="locationServices" class="tab-content">\n' +
	'			<h3 class="locationName">Departments in %name%</h3>\n' +
	'			<ul class="locationServices">\n' +
	'			\n' +
	'			</ul>\n' +
	'		</div>\n' +
	'		<div id="locationWiFi" class="tab-content">\n' +
	'			<h3>WiFi Access in %name%</h3>\n' +
	'		</div>\n' +
	'	</div>\n'
	);
	
	var retval = document.createElement('div');
	retval.className = "locationInformation tab";
	retval.id = "locationInformation";

	template = template.replace(/%index%/g, loc.index || " " );
	template = template.replace(/%name%/g, loc.name || " ");
	template = template.replace(/%image%/g, loc.image || " ");
	template = template.replace(/%description%/g, loc.description || " ");
	template = template.replace(/%directions%/g, loc.directions || " ");
	retval.innerHTML = template;
	
	// now build the list of services.  An oddity of our data provider is that it can generate
	// an empty object, a single item, or an array.  This is a side-effect of our shortcuts in
	// PHP using SimpleXML for parsing and json_encode for encoding.
	if (loc.service && !jQuery.isEmptyObject(loc.service)) {
		// if the services is just one item, make it into an array
		if(!jQuery.isArray(loc.service)) {
			loc.service = [loc.service];
		}
		
		var listText = "";
		for (var idx=0; idx<loc.service.length; idx++) {
			$(retval).find('.locationServices').append( "		<li>" + loc.service[idx] + "</li>\n");
		}		
	}
	
	// similarly, build the list of WiFi maps
	if (loc.wifi && !jQuery.isEmptyObject(loc.wifi)) {
		// make sure it is an array
		if(!jQuery.isArray(loc.wifi)) {
			loc.wifi = [loc.wifi];
		}
		var listText = "";
		for (var idx=0; idx<loc.wifi.length; idx++) {
			var itemText = '<p><a href="%image%" target="_new"><img src="%image%" title="%title%" alt="%title%" class="locationWiFiMap"/></a></p>';
			itemText = itemText.replace(/%image%/g, loc.wifi[idx].image || "");
			itemText = itemText.replace(/%title%/g, loc.wifi[idx].title || "");
			$(retval).find('#locationWiFi').append(itemText);
		}
	} else {
		$(retval).find('#locationWiFi').append('<p><em>No WiFi maps available</em></p>');
	}
	
	return retval;
}

// Checks locThing to make sure it exists and isn't an empty object, then appends it to the parent
// as a node of the desired type, with the desired className (if Provided)
function appendNodeFromLocationThing(parent, locThing, elementType, className) {
	var node = null;
	
	if (locThing && !jQuery.isEmptyObject(locThing)) {
		node = document.createElement(elementType);
		node.innerHTML = locThing;
		if (className) { node.className = className; }
		parent.appendChild(node);
	}
	
	return node;
}

// Returns an image node that points to the indicated source
function getPinNode(src, className) {
	var node = document.createElement('img');
	node.src = src;
	if (className)
		node.className = className;
	return node;
}

/**
 *	We elected not to use the jQuery tab system, because that usually involved invoking the rather
 *	extensive jquery-ui CSS stylesheets.  We kept it simple, instead.
 */
function setupTabs(node) {
	var tabDiv = $(node);
	
	tabDiv.find(".tab-content").hide(); //Hide all content
	tabDiv.find("ul.tab-nav li:first").addClass("current").show(); //Activate first tab
	tabDiv.find(".tab-content:first").show(); //Show first tab content
	
	tabDiv.find("ul.tab-nav li").click(function(evt) {
		var target = $(evt.target);

		tabDiv.find("ul.tab-nav li").removeClass("current"); //Remove any "active" class
		$(this).addClass("current");
		tabDiv.find(".tab-content").hide(); //Hide all tab content
		var activeTab = $(this).find("a").attr("href"); //Find the rel attribute value to identify the active tab + content
		$(activeTab).fadeIn(); //Fade in the active content
		return false;
	});
}
