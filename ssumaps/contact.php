<?php

/**
 *	Campus Map Feedback Form for SSU
 *
 *	I have done very little here, other than provide a place where SSU can put their standard 
 *	contact form, and shown how to extract the name of the map location that incited the comment.
 *	
 *	@author	CJ Holmes
 */

include_once("map_context.php");
$context = new MapContext('locations.xml');

if ($context->location)
	$locationName = (string) $context->location->name;
else
	$locationName = 'Unspecified';
?>

<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
	<title>Campus Map Feedback</title>
</head>
<body>
	
	<form>
		<input type="text" name="locationName" value="<?php echo $locationName; ?>"/>
	</form>
</body>
</html>
