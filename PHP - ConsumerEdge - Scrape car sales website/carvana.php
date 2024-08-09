<?php
require_once __DIR__.'/config.php';

$dom  = new DOMDocument();
// disable standard libxml errors and enable user error handling
libxml_use_internal_errors( 1 );

$dom->loadHTML( file_get_contents( $url ) );
$xpath = new DOMXpath( $dom );

// filter out json linked data records
$jsonScripts = $xpath->query( '//script[@type="application/ld+json"]' );

// to fetch the whole inventory, // filter out the total noof pages span
$totalPages = $xpath->query("//*[contains(@class, 'paginationstyles__PaginationText-mpry3x-5')]");
if ( $totalPages->length < 1 ) {
   echo( "Warning: Total number of pages is unknown" );
} else {
   preg_match('/(Pages \d+ of) (\d+)/', $totalPages->item(0)->nodeValue, $matches);
   $totalPages = $matches[2];
}

//$noofCars = $jsonScripts->length;

if ( $jsonScripts->length < 1 ) {
    die( "Error: No 'script' node found -> No cars for sale or sold out." );
} else {
    //vehicle_id, vin, make, model, mileage, price
    // $car = json_decode(trim($jsonScripts->item(0)->nodeValue));
    // //print_r($car);
    // preg_match('/(\d+) (\w+) (\w+)/', $car->name, $matches);
    // echo 'make:', $matches[2], "\n";
    // echo 'year:', $matches[1], "\n";
    // echo 'model:', $matches[3], "\n";
    // echo 'price:', $car->offers->price, "\n";
    // echo "-------------------------\n";
    // $car = json_decode(trim($jsonScripts->item(1)->nodeValue));
    // preg_match('/(\d+) (\w+) (\w+)/', $car->name, $matches);
    // echo 'make:', $matches[2], "\n";
    // echo 'year:', $matches[1], "\n";
    // echo 'model:', $matches[3], "\n";
    // echo 'price:', $car->offers->price, "\n";

    // echo "-------------------------\n";
    foreach( $jsonScripts as $i => $node ) {
        //echo $i,"\t",print_r($node->nodeValue);
        echo "Car $i\n";
        $car = json_decode( $node->nodeValue );
        // 'make' can be 'Mercedez-Benz', hence \w+ does not suffice
        // 'model' is anything after 'make', hence '.*', can be nothing, 'C-Class' or any multi-word
        preg_match('/(\d+) ([a-zA-Z\-]+) (.*)/', $car->name, $matches);
        echo 'make:', $matches[2], "\t", $car->name, "\n";
        // echo 'year:', $matches[1], "\n";
        echo 'model:', $matches[3], "\n";
        echo 'id:', $car->sku, "\n";
        echo 'vin:', $car->vehicleIdentificationNumber, "\n";
        echo 'mileage:', $car->mileageFromOdometer, "\n";
        echo 'price:', $car->offers->price, "\n";
        echo "-------------------------\n";
    }
}
