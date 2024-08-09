<?php
 	require_once("Rest.inc.php");
	
	class API extends REST {
	
		public $data = "";
		
		const DB_SERVER = "localhost";
		const DB_USER = "features";
		const DB_PASSWORD = "features";
		const DB = "Features";

		private $db = NULL;
		private $conn = NULL;

		public function __construct(){
			parent::__construct();				// Init parent contructor
			$this->dbConnect();					// Initiate Database connection
		}
		
		/*
		 *  Connect to Database
        */
        // using mysqli
        private function dbConnect() {
            try {
                $this->conn = new mysqli(self::DB_SERVER, self::DB_USER, self::DB_PASSWORD, self::DB);
            } catch (mysqli_sql_exception $exception) {
                echo "Mysqli Connection error: " . $exception->getMessage();
            }
            return $this->conn;
        }
        /*
        // using PDO
        public function dbConnect() {
            try {
                $this->conn = new PDO("mysql:host=" . self::DB_SERVER . ";dbname=" . self::DB, self::DB_USER, self::DB_PASSWORD);
            } catch (PDOException $exception){
                echo "MySQL PDO Connection error: " . $exception->getMessage();
            }
            return $this->conn;
        }
        */

		/*
		 * Dynmically call the method based on the query string
		 */
		public function processApi() {
			$func = strtolower( trim( str_replace("/", "", $_REQUEST['x']) ) );
			if ((int)method_exists($this, $func) > 0)
				$this->$func();
			else
				$this->response('', 404); // If the method not exist with in this class "Page not found".
		}
		
		private function requests() {
			if ($this->get_request_method() != "GET") {
				$this->response('', 406);
			}
			$query = "SELECT id, Title, Description, Client, ClientPriority, TargetDate, Url, ProductArea, created FROM ClientRequest";
			$r = $this->conn->query($query) or die($this->conn->error.__LINE__);

			if ($r->num_rows > 0) {
				$result = array();
				while ($row = $r->fetch_assoc()) {
					$result[] = $row;
				}
				$this->response($this->json($result), 200); // send user details
			}
			$this->response('', 204);	// If no records "No Content" status
		}

		private function request() {
			if ($this->get_request_method() != "GET") {
				$this->response('', 406);
			}
			$id = (int)$this->_request['id'];
			if ($id > 0) {
				$query = "SELECT id, Title, Description, Client, ClientPriority, TargetDate, Url, ProductArea, created FROM ClientRequest WHERE id=$id";
				$r = $this->conn->query($query) or die($this->conn->error.__LINE__);
				if ($r->num_rows > 0) {
					$result = $r->fetch_assoc();	
					$this->response($this->json($result), 200); // send user details
				}
			}
			$this->response('', 204);	// If no records "No Content" status
		}
		
		private function insertRequest() {
			if ($this->get_request_method() != "POST") {
				$this->response('', 406);
			}

			$request = json_decode(file_get_contents("php://input"), true);
			$column_names = array('Title', 'Desciption', 'Client', 'ClientPriority', 'TargetDate', 'Url', 'ProductArea');
			$keys = array_keys($request);
			$columns = '';
			$values = '';
			foreach ($column_names as $desired_key) { // Check the feature request received. If blank insert blank into the array.
			   if (!in_array($desired_key, $keys)) {
			   		$$desired_key = '';
			   } else {
					$$desired_key = $request[$desired_key];
               }
			   $columns .= $desired_key.',';
               $values .= "'".$$desired_key."',";
               $columns .= 'created,';
               $values .= "'".now()."'";
			}
			$query = "INSERT INTO Request (".trim($columns, ',').") VALUES(".trim($values, ',').")";
			if (!empty($request)) {
				$r = $this->conn->query($query) or die($this->conn->error.__LINE__);
				$success = array('status' => "Success", "msg" => "Feature Request created successfully.", "data" => $request);
				$this->response($this->json($success), 200);
			} else
				$this->response('', 204);	//"No Content" status
		}

		private function updateRequest() {
			if ($this->get_request_method() != "POST") {
				$this->response('', 406);
			}
			$request = json_decode(file_get_contents("php://input"), true);
			$id = (int)$request['id'];
            $column_names = array('Title', 'Desciption', 'Client', 'ClientPriority', 'TargetDate', 'Url', 'ProductArea');
			$keys = array_keys($request['request']);
			$columns = '';
			$values = '';
			foreach ($column_names as $desired_key) { // Check the customer received. If key does not exist, insert blank into the array.
			   if (!in_array($desired_key, $keys)) {
			   		$$desired_key = '';
			   } else {
					$$desired_key = $request['request'][$desired_key];
               }
			   $columns .= $desired_key."='".$$desired_key."',";
			}
			$query = "UPDATE Request SET ".trim($columns, ',')." WHERE id=$id";
			if (!empty($request)) {
				$r = $this->conn->query($query) or die($this->conn->error.__LINE__);
				$success = array('status' => "Success", "msg" => "Feaure request ".$id." updated successfully.", "data" => $request);
				$this->response($this->json($success), 200);
			} else
				$this->response('', 204);	// "No Content" status
		}
		
		private function deleteRequest() {
			if ($this->get_request_method() != "DELETE") {
				$this->response('', 406);
			}
			$id = (int)$this->_request['id'];
			if ($id > 0) {
				$query="DELETE FROM Request WHERE id = $id";
				$r = $this->conn->query($query) or die($this->conn->error.__LINE__);
				$success = array('status' => "Success", "msg" => "Successfully deleted one feature request.");
				$this->response($this->json($success), 200);
			} else
				$this->response('', 204);	// If no records "No Content" status
		}
		
		/*
		 *	Encode array into JSON
		*/
		private function json($data) {
			if (is_array($data)) {
				return json_encode($data);
			}
		}
	}
	
	// Initiiate Library
	
	$api = new API;
	$api->processApi();
?>