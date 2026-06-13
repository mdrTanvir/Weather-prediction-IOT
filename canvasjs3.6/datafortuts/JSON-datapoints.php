<?php
	error_reporting(E_ALL);						//show any errors if there is any
	ini_set('display_errors', '1');

		header("Content-type: application/json");		// reply with JSON

		$ldrpoints = array();
		
		$high = 0;
		$low = 0;

		$threshhold = 100.0;					// a threshhold corresponding to the sensitivity of the sensor		
		$filename = 'S1 11-11-2019.xml';
		
				
		if(isset($_GET['trh'])) {
			$threshhold = $_GET['trh'];
		}

		if(isset($_GET['file'])) {
			
			if($_GET['file'] == 1) {			//field will be 1		from Sensor 1
				$filename = 'S1 11-11-2019.xml';
			}
			else if($_GET['file'] == 2) {			//field will be 1		from Sensor 1
				$filename = 'S1 25-04-2020.xml';
			}
			else if($_GET['file'] == 3) {			//field will be 2		from Sensor 2
				$filename = 'S2 11-11-2019.xml';
			}
			else if($_GET['file'] == 4) {			//field will be 2		from Sensor 2
				$filename = 'S2 25-04-2020.xml';
			}
		}
		
		$xml_arr = simplexml_load_file($filename);		// get all the current data
		
		foreach($xml_arr->feeds->feed as $r) {		

			$val = 0.0;
			
			if(isset($_GET['file']) && ($_GET['file'] == 3 || $_GET['file'] == 4)) // if field is 2
				$val = strval($r->field2);
			else
				$val = strval($r->field1);
			
			if($val > $threshhold){
				$high++;				// count the high values
			}
			else {
				$low++;					// count the low values
			}
		}
				
		$high = ($high / count($xml_arr->feeds->feed)) * 100;		// convert the high and low count
		$low  = ($low  / count($xml_arr->feeds->feed)) * 100;		// to percentages
		
		
			
		//add the high light values
		array_push($ldrpoints,  array("y" => $high, "legendText" => "High Light", "label" => "High Light"));
		
		//add the low light values
		array_push($ldrpoints,  array("y" => $low , "legendText" => "Low Light", "label" => "Low Light"));

		echo json_encode($ldrpoints, JSON_NUMERIC_CHECK);
?>