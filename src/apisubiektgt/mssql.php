<?php
namespace APISubiektGT;
use Exception;
use APISubiektGT\Logger;

class MSSql{
	
	static private $instance = false;
	static private $comWritesOnly = true;
	static private $allowSqlWriteFallback = false;
	private $conf = array();
	private $conn = false;

	static public function setComWritesOnly($enabled = true, $allowFallback = false)
	{
		self::$comWritesOnly = (bool) $enabled;
		self::$allowSqlWriteFallback = (bool) $allowFallback;
	}

	static public function isComWritesOnly()
	{
		return self::$comWritesOnly;
	}
	
	
	private function __construct($conf = array(),$data_base){ 
		$this->conn = sqlsrv_connect( $data_base, $conf);
		if( $this->conn === false )
		{		     
			$errors = sqlsrv_errors();
		     throw new Exception ("Could not connect. ".$errors[0]['message']);		     
		}
	}
	
	static public function getInstance($conf = array(),$data_base = ''){
		if(false == self::$instance){
			self::$instance = new MSSql($conf,$data_base);
		}
		return self::$instance;
	}
	
	public function query($query){
		if (self::$comWritesOnly && !self::$allowSqlWriteFallback) {
			$normalized = ltrim((string) $query);
			if (preg_match('/^\s*(INSERT|UPDATE|DELETE|MERGE|TRUNCATE|EXEC(?:UTE)?)\b/i', $normalized)) {
				Logger::getInstance()->log(
					'api_error',
					'SQL write blocked (use_com_writes_only): ' . substr($normalized, 0, 200),
					__CLASS__ . '->query',
					__LINE__
				);
				throw new Exception('SQL writes are disabled — use COM/Sfera for document mutations.');
			}
		}

		$data = sqlsrv_query($this->conn ,$query);
		$result = array();   
	
		if($data == false){
			die( print_r( sqlsrv_errors(), true));
		}
		
		while($row = sqlsrv_fetch_array( $data, SQLSRV_FETCH_ASSOC)){
			$result[] = $row;
		}
		
		return $result;
	}	
	
	public function exec($dml,$params = array()){
		if (self::$comWritesOnly && !self::$allowSqlWriteFallback) {
			$normalized = ltrim((string) $dml);
			if (preg_match('/^\s*(INSERT|UPDATE|DELETE|MERGE|TRUNCATE|EXEC(?:UTE)?)\b/i', $normalized)) {
				Logger::getInstance()->log(
					'api_error',
					'SQL exec blocked (use_com_writes_only): ' . substr($normalized, 0, 200),
					__CLASS__ . '->exec',
					__LINE__
				);
				throw new Exception('SQL writes are disabled — use COM/Sfera for document mutations.');
			}
		}

		$stmt = sqlsrv_query( $this->conn, $dml, $params);
		if( $stmt == false){
		     die( print_r( sqlsrv_errors(), true));
		}
		sqlsrv_free_stmt( $stmt);		
	}
		
	public function __destruct(){
		sqlsrv_close($this->conn);	
	}
	
}
?>