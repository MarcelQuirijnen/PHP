<?php

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

include_once '../Model/Database.php';
include_once '../Model/UrlModel.php';

$database = new Database();
$db = $database->getConnection();

$link = new urlModel($db);

//$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
//$uri = explode( '/', $uri );
//$requestMethod = $_SERVER["REQUEST_METHOD"];

$aResponse = array();

if ( getenv('REQUEST_METHOD') == 'POST' ) {

    $link->url = $_POST['url'];
    // $rawData = file_get_contents("php://input");
    // echo '----RawData: '. $rawData."<br>";
    // echo '----url:     '. $link->url."<br>";

    $link->created = date('Y-m-d H:i:s');

    if ( $link->insertUrl() ) {
        $aResponse['status'] = 'success';
        $aResponse['message'] = 'Employee created successfully.';
    } else {
        $aResponse['status']  = 'failure';
        $aResponse['message'] = 'Employee could not be created.';
    }
}

echo json_encode($aResponse);

?>
