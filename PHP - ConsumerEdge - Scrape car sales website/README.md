

You are tasked with a simple data collection project on Carvana.com.

    Please complete the following code skeleton. Adjust the methods if necessary to support your approach.
    You may use built-in or external libraries to solve the problem. This process should run from the commmand-line
    You only need to fetch a single page of vehicle results. e.g., go to https://www.carvana.com/cars and click [Next] page -- Notice new inventory loads with without refreshing the page
    Create a simple MySQL schema for storing these records and include the necessary create table(s) DDL. We are interested in the following fields: vehicle_id, vin, make, model, mileage, price
    How would you extend this to fetch all the vehicle inventory? (Code is not necessary)

<?php
declare(strict_types = 1);

function fetch_carvana_inventory_by_page(int $page_id) : array
{
    // Fetch a single page of vehicles
    // return an array of vehicle records
    return [];
}

function save_carvana_inventory(array $vehicle, mysqli $db) : bool
{
    // Persist a single vehicle record to the database
    // Return a boolean indicating success or failure
    return true;
}

// connect to database
try {
  $db = new PDO("mysql:host=$db_host;dbname=$db_database", $db_username, $db_password);
  // set the PDO error mode to exception
  $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  echo "Connected successfully";
} catch(PDOException $e) {
  echo "Connection failed: " . $e->getMessage();
  exit 1;
}

foreach (fetch_carvana_inventory_by_page(2) as $vehicle)
{
    save_carvana_inventory($vehicle, $db);
}

