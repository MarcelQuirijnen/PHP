<?php
///////////////////////////////////////
// What  : Consumer Edge developer application homework
// Athour: Marcel Quirijnen
//         quirijnen.marcel@gmail.com
// Usage : php carvana_cli.php         --> no params, retrieve the first page
//         php carvana_cli.php -p3     --> retrieve the 3rd page
//         php carvana_cli.php -a      --> retrieve the whole inventory
//         php carvana_cli.php -a -p3  --> retrieve the 3rd page, ignore -a param
///////////////////////////////////////

declare( strict_types = 1 );
require_once __DIR__.'/config.php';


///////////////////////////////////////
// Fetch a single page of vehicles
// return an array of vehicle records
///////////////////////////////////////
function fetch_carvana_inventory_by_page( int $page_id, DOMXPath $xp ) : array
{
    if (!isset( $page_id ) || $xp === null) {
        return [];
    }
    $vehicle = array();

    // filter out json linked data records
    $jsonScripts = $xp->query( '//script[@type="application/ld+json"]' );
    
    if ( $jsonScripts->length < 1 ) {
        //die( "Error: No 'script' node found -> No cars for sale or sold out." );
        return [];
    } else {
        foreach( $jsonScripts as $i => $node ) {
            $car = json_decode( $node->nodeValue );
            // 'make' can be 'Mercedez-Benz', hence \w+ does not suffice
            // 'model' is anything after 'make', hence '.*', can be empty, 'C-Class' or any multi-word
            preg_match( '/(\d+) ([a-zA-Z\-]+) (.*)/', $car->name, $matches );
            $vehicle['make']       = $matches[2];
            $vehicle['model']      = $matches[3];
            $vehicle['vehicle_id'] = $car->sku;
            $vehicle['vin']        = $car->vehicleIdentificationNumber;
            $vehicle['mileage']    = $car->mileageFromOdometer;
            $vehicle['price']      = $car->offers->price;
        }
    }

    return $vehicle;
}


///////////////////////////////////////
// Persist a single vehicle record to the database
// Return a boolean indicating success or failure
///////////////////////////////////////
function save_carvana_inventory( array $vehicle, mysqli $db ) : bool
{
    if ( empty( $vehicle ) || $db === null ) {
        return false;
    }

    $columns = implode( ", ", array_keys( $vehicle ) );
    $escaped_values = array_map( array( $db, 'real_escape_string' ), array_values( $vehicle ) );
    $values  = "'" . implode( "', '", $escaped_values ) . "'";
    $sql = "INSERT INTO `carvana` ($columns) VALUES ($values)";
    //echo $sql."\n";    
    return $db->query( $sql );
}

///////////////////////////////////////
// Peel 'Page x of yyyy' from page
// Return yyyy as the total number of pages 
///////////////////////////////////////
function total_inventory_noof_pages( DOMXPath $xp ) : int
{
    $allPages = 0;

    // to fetch the whole inventory, filter out the total noof pages tag
    $totalPages = $xp->query( "//*[contains(@class, 'paginationstyles__PaginationText-mpry3x-5')]" );

    if ( $totalPages->length < 1 ) {
       echo( "Warning: Total number of pages is unknown." );  
       // $allPages = 0
       // no pages will be fetched
    } else {
       preg_match( '/(Page \d+ of) (\d+)/', $totalPages->item(0)->nodeValue, $matches );
       $allPages = $matches[2];
    }

    return (int)$allPages;
}


// get cmdline options if any
// set sensible defaults if none given
$options = getopt( "p::a" );
foreach ( $options as $key => $value ) {
    switch( $key ) {
        case 'a': $a = 1;
                  break;
        case 'p': $p = $value;
                  break;
        default : // No input params given. Default to page 1'
                  $p = 1;
                  break;
    }
}

// a specified page gets precedence over whole inventory
// no page specified == whole inventory if -a param is given
// if no params given, default to retrieve page 1
if ( isset( $p ) && is_numeric( $p ) && $p > 0 ) {
   $PAGE = (int)$p;
} else {
    if ( isset( $a ) ) {
        $ALL = 1;
    } else {
        $PAGE = 1;
    }
}

// connect to database, set error reporting before making connection
mysqli_report( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT );  
$db = new mysqli( $db_host, $db_username, $db_passwd, $db_database );
if ( $db->connect_errno ) {
    throw new RuntimeException( 'Database connection error: ' . $db->connect_error );
}

// update URL with optional cmdline value
$url .= '?page='.$PAGE;

$dom  = new DOMDocument();

// disable standard libxml errors and enable user error handling
libxml_use_internal_errors( true );

$dom->loadHTML( file_get_contents( $url ) );
$xpath = new DOMXpath( $dom );

$pages = ($ALL) ? total_inventory_noof_pages( $xpath ) : $PAGE;

for ( $i = $PAGE; $i <= $pages; $i++ ) {
    save_carvana_inventory( fetch_carvana_inventory_by_page( $i, $xpath ), $db );
}

// close connections
$db->close();
