<?
include_once 'siteconfig.php';
//$siteurl='http://foreclosures.dailydata.com/';
$siteurl = 'http://localhost/';
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="en">
<html>
<head>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<meta http-equiv="Content-language" content="en">
<meta name="author" content="Marcel Quirijnen">
<meta name="company" content="Little Sugar Creek Technology Services, Inc.">

<style type="text/css"><!--
#map { 
  width: 100%; 
  height: 700px; 
  border: 1px solid #000; 
} 
</style>

<title>Daily Record - Newsstands and Racks</title>
</head>
<body>
<?
   $sql = sprintf("SELECT * FROM newsstands");
   $doit = mysql_query($sql, $link);
   $num_rows = mysql_num_rows($doit);
   while ($row = mysql_fetch_array($doit)) { 
      //"Little Rock Post Office","600 E. Capitol","72202","Little Rock","AR","Pulaski County","","34.74412","-92.26473"
      $location[$row['id']] = $row['location'];
      $address[$row['id']] = $row['address'];
      $zip[$row['id']] = $row['zip'];
      $city[$row['id']] = $row['city'];
      $state[$row['id']] = $row['state'];
      $county[$row['id']] = $row['county'];
      $contact[$row['id']] = $row['contact'];
      $lat[$row['id']] = $row['latitude'];
      $long[$row['id']] = $row['longitude'];
   }
?>
<center><h3>The Daily Record, Inc. newsstands & racks</h3></center>

<div id="map"></div> 
<img src="/portfolio/images/blank_green.png" alt="" border="0"> Pulaski county &nbsp;&nbsp;&nbsp;&nbsp;
<img src="/portfolio/images/blank_orange.png" alt="" border="0"> Lonoke county &nbsp;&nbsp;&nbsp;&nbsp;
<img src="/portfolio/images/blank_blue.png" alt="" border="0"> Saline county &nbsp;&nbsp;&nbsp;&nbsp;
<img src="/portfolio/images/blank_red.png" alt="" border="0"> Faulkner county<br> 
    
<script type="text/javascript" src="http://maps.google.com/maps/api/js?sensor=false"></script> 
<script type="text/javascript">
(function() { 
  window.onload = function() { 
 
    // Creating an object literal containing the properties  
    // we want to pass to the map   
    var options = { 
      zoom: 10, 
      center: new google.maps.LatLng(34.83306, -92.23158), 
      mapTypeId: google.maps.MapTypeId.ROADMAP, 
      scaleControl: true,
      streetViewControl: true
    }; 
 
    // Creating the map   
    var newsstands = new google.maps.Map(document.getElementById('map'), options); 
    
    // Creating a LatLngBounds object 
    var bounds = new google.maps.LatLngBounds();
    
    // Creating an array that will contain the coordinates  
    // of newspaper stands + Home base
    var places = []; 
    var lats  = new Array("<?php echo implode("\",\"", $lat);?>"); 
    var longs = new Array("<?php echo implode("\",\"", $long);?>");
    var counties = new Array("<?php echo implode("\",\"", $county);?>");
    var addresses = new Array("<?php echo implode("\",\"", $address);?>");
    var zips = new Array("<?php echo implode("\",\"", $zip);?>");
    var cities = new Array("<?php echo implode("\",\"", $city);?>");
    var states = new Array("<?php echo implode("\",\"", $state);?>");
    var contacts = new Array("<?php echo implode("\",\"", $contact);?>");
    
    for ( var i=0, len=lats.length; i<len; ++i ) {  
       places.push(new google.maps.LatLng(lats[i], longs[i])); 
    }  
    
    // default icon = Pulaski county
    var icon_marker = "<? echo $siteurl; ?>" + 'portfolio/images/blank_green.png';
    var title_marker;
    
    // Looping through the places array
    for (var i = 0; i < places.length; i++) { 
      title_marker = 'Newsstand ' + i;
      if (counties[i] == 'Lonoke County') {
         icon_marker = "<? echo $siteurl; ?>" + 'portfolio/images/blank_orange.png';
      } else if (counties[i] == 'Saline County') {
         icon_marker = "<? echo $siteurl; ?>" + 'portfolio/images/blank_blue.png';
      } else if (counties[i] == 'Faulkner County') {
         icon_marker = "<? echo $siteurl; ?>" + 'portfolio/images/blank_red.png';
      }     
      var newsstand = new google.maps.Marker({ 
        position: places[i],  
        map: newsstands, 
        icon: icon_marker,
        title: title_marker 
      }); 
    
      (function(i, newsstand) { 
          google.maps.event.addListener(newsstand, 'click', function() {          
          if (!infowindow) { 
            var infowindow = new google.maps.InfoWindow(); 
          } 
          var info_str = addresses[i] + '<br>' + cities[i] + ', ' + states[i] + ' ' + zips[i];
          var contact_str = '';
          if (contacts[i].length) {
             contact_str = '<br><strong>Contact info :</strong> ' + contacts[i];
          }
          //alert(info_str);
          
          // Setting the content of the InfoWindow 
          infowindow.setContent(info_str + contact_str); 
 
          // Tying the InfoWindow to the marker  
          infowindow.open(newsstands, newsstand); 
           
        }); 
 
      })(i, newsstand);
    
      bounds.extend(places[i]);
    } 
    
    // Adding a HOME marker to the map 
    var marker = new google.maps.Marker({ 
      position: new google.maps.LatLng(34.74740, -92.28095), 
      map: newsstands, 
      title: 'The Daily Record, Inc.', 
      icon: "<? echo $siteurl; ?>" + 'portfolio/images/tower_logo.png' 
    }); 
 
    // Creating an InfoWindow with content
    var dr_infowindow = new google.maps.InfoWindow({ 
      content: '<strong>The Daily Record, Inc.</strong><br>300 S. Izard St.<br>Little Rock, AR 72201' 
    }); 
     
    // Adding a click event to the marker 
    google.maps.event.addListener(marker, 'click', function() { 
      // Calling the open method of the infoWindow 
      dr_infowindow.open(newsstands, marker); 
    }); 
    
    // Adjusting the map to new bounding box 
    newsstands.fitBounds(bounds);
    
  }; 
})(); 

</script> 
</body> 
</html> 
