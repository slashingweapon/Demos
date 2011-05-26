<?php

/**
 *	Campus Map for SSU
 *
 *	The basic technique here is to create a MapContext object, and then use its various methods
 *	to fill in the HTML.
 *
 *	@author	CJ Holmes
 */

$time = microtime(true);
include_once("map_context.php");
$context = new MapContext('locations.xml');

?>

<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
	<script type="text/javascript" src="jquery-1.6.1.js"></script>
	<script type="text/javascript" src="map.js"></script>
	<script type="text/javascript">
		gLocations = <?php echo $context->getLocationsJSON(); ?>;
		gChosenLocation = <?php echo json_encode($_GET['location']); ?>;
	</script>

	<link rel="stylesheet" type="text/css" href="map.css" />
	<style type="text/css">
		.mapSelectionBounds {
			position: relative;
			border: 4px solid red;
			<?php echo $context->locationCSSBounds(); ?>
		}
	</style>

	<title>Campus Map<?php echo $context->getSubtitle(); ?></title>
</head>
<body>

	<!-- Location selection form -->
	<form action="maps.php">
		<label for="findLocation">Go to location</label>
		<select type="select" name="location" class="mapMenu" id="findLocationLocation">
			<?php echo $context->getLocationSelectText(3); ?>
		</select>
		<input type="submit" value="Go"/>
	</form>
	
	<!-- Service selection form -->
	<form action="maps.php">
		<label for="findService">Find service</label>
		<select type="select" name="location" class="mapMenu" id="findService">
			<?php echo $context->getServiceSelectText(3); ?>
		</select>
		<input type="submit" value="Go"/>
	</form>
	
	<div class="mapContainer">
		<img class="mapImage" src="map.jpg" usemap="#mapAreas"/>
		<div class="mapSelectionBounds" id="mapSelection"> </div>
		<map name="mapAreas">
			<?php echo $context->getAreaText(3); ?>
			<area shape="default" class="mapDefaultArea"/>
		</map>
	</div>
	
	<div class="mapLocation mapClear">
		<?php echo $context->getLocationText(); ?>
	</div>
	

	<!-- It took <?php echo microtime(true)-$time ?> seconds to render this page. -->
</body>
</html>
