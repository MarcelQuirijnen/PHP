<?php
require_once PROJECT_ROOT_PATH . "/Model/Database.php";

class UrlModel extends Database
{

    private $conn;
    private $db_table = "urls";

    public $id;
    public $url;
    public $created;


    public function __construct($db)
    {
        $this->conn = $db;
    }

    // create new url
    public function insertUrl() 
    {
        $sql = "INSERT INTO ". $this->db_table . " (id, url, created) VALUES ( null, :url, :created)";

        $stmt = $this->conn->prepare( $sql );

        // sanitize
        $this->url     = htmlspecialchars( strip_tags( $this->url ) );
        $this->created = htmlspecialchars( strip_tags( $this->created ) );
    
        // bind data
        $stmt->bindParam( ":url", $this->url );
        $stmt->bindParam( ":created", $this->created );
    
        if ( $stmt->execute() ) {
           return true;
        }

        return false;
    }

    // get all urls
    public function getUrls()
    {
        $sql  = "SELECT url, created FROM " . $this->db_table;
        $stmt = $this->conn->prepare( $sql );
        $stmt->execute();
        return $stmt;
    }

    public function deleteUrl() 
    {
        $sql  = "DELETE FROM " . $this->db_table . " WHERE id = ?";
        $stmt = $this->conn->prepare( $sql );
    
        $this->id = htmlspecialchars( strip_tags( $this->id ) );
        $stmt->bindParam( ":id", $this->id );
    
        if ( $stmt->execute() ) {
            return true;
        }

        return false;
    }

    public function updateUrl()
    {
        $sql = "UPDATE ". $this->db_table .
               "SET
                    url = :url, 
                    created = :created
                WHERE 
                    id = :id";
    
        $stmt = $this->conn->prepare( $sql );

        // sanitize
        $this->url     = htmlspecialchars( strip_tags( $this->url ) );
        $this->created = htmlspecialchars( strip_tags( $this->created ) );

        // bind data
        $stmt->bindParam( ":url",     $this->url );
        $stmt->bindParam( ":created", $this->created );
        $stmt->bindParam( ":id",      $this->id );

        if ( $stmt->execute() ) {
            return true;
        }
        
        return false;

    }
    
    // get single url
    public function readUrl() 
    {
        $sql = "SELECT url, created FROM " . $this->db_table . " WHERE id = :id";
        $stmt = $this->conn->prepare( $sql );

        $stmt->bindParam( ":id", $this->id );
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
        $this->url = $row['url'];
        $this->created = $row['created'];

    }
    
}