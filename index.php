<?php
/*
Script Name: URL Status Checker
Description: A script to check a CSV list of URL's against Googles Safe Browsing Database 
Version Date: 22 January 2016
Version: 1.0
Author: Michael Ryan
Author URI: http://www.dubdubdesign.co.nz
Author Email: torican@gmail.com

Change Log:

22/01/2016	V1.0	Initial Development
*/

/* Start Config */
define("CSV_SOURCE", 					"urls.csv");										// URL CSV Source File
define("CSV_DELIMITER", 			",");														// URL CSV Source File Delimiter

define("URLS_PER_API_POST", 	"500");													// Limit to X url's per API post
define("GOOGLE_API_KEY", 			"");	// Google API Key

define("SEND_TO_EMAIL", 			true);													// Enable/Disable email notification
define("TO_EMAIL", 						"");	// Email notification recipient

define("SEND_TO_SLACK", 			true);													// Enable/Disable slack notification
define("SLACK_CHANNEL", 			"#general");										// Slack Channel
define("SLACK_WEBHOOK_URL", 	"");	// Slack Webhook Url
/* End Config */

$data = csvToArray();
$result = prepareRunGoogle($data);
if(SEND_TO_EMAIL){resultsToEmail($result);}
if(SEND_TO_SLACK){resultsToSlack($result);}

function csvToArray() {
	if(!file_exists(CSV_SOURCE) || !is_readable(CSV_SOURCE)){// Check source and email error if issue occurs
		mail(TO_EMAIL, 'CSV Unreadable', 'Warning - URL Status Checker CSV "'. CSV_SOURCE .'" is unreadable/not found.');
	}

	// Save data to array $data
	$header = NULL;
	$data = array();
	if (($handle = fopen(CSV_SOURCE, 'r')) !== FALSE) {
		while (($row = fgetcsv($handle, 1000, CSV_DELIMITER)) !== FALSE) {
			if(!$header)
				$header = $row;
			else
				$data[] = array_combine($header, $row);
			}
			fclose($handle);
	}
	return($data);
} 

function prepareRunGoogle($data){
	$data = array_chunk($data,URLS_PER_API_POST); // Break data into chunks to process based on API Post max
	$result = array(); //Define results array

	foreach ($data as $chunk) { // For each chunk: 
		$rawdata = count($chunk); // How many items are we looking up this time around?
		foreach($chunk as $item) { // Prepare data for submission
			$rawdata .= "\n";
			$rawdata .= $item['URL'];
		}	
		$response = submitGoogleData($rawdata); // Run the test and get a response	
		$response = explode("\n", $response); // Extract Google data into an array	
		
		foreach($response as $key=>$value) { // Merge Google data with appropriate URL 
			$chunk[$key]['RESPONSE'] = $value;
		}

		foreach($chunk as $item){ // Merge failed results only to $result			
			if(array_key_exists('RESPONSE', $item)&&$item['RESPONSE']!='ok'&&$item['RESPONSE']!=''){				
				$result[] = array(
					"URL" => $item['URL'],
					"RESPONSE" => $item['RESPONSE'],
				);
			}		
		}	
	} 
	return $result;
}

function submitGoogleData($body = false){ //Send $body payload to Google API
	$url = 'https://sb-ssl.google.com/safebrowsing/api/lookup?client=app&key='.GOOGLE_API_KEY.'&appver=1.0&pver=3.1'; //API Call URL
	$options = array(
			CURLOPT_RETURNTRANSFER => true,     // return web page
			CURLOPT_HEADER         => false,    // don't return headers
			CURLOPT_FOLLOWLOCATION => true,     // follow redirects
			CURLOPT_ENCODING       => "",       // handle all encodings
			CURLOPT_USERAGENT      => "spider", // who am i
			CURLOPT_AUTOREFERER    => true,     // set referer on redirect
			CURLOPT_CONNECTTIMEOUT => 120,      // timeout on connect
			CURLOPT_TIMEOUT        => 120,      // timeout on response
			CURLOPT_MAXREDIRS      => 10,       // stop after 10 redirects
			CURLOPT_SSL_VERIFYPEER => false,    // Disabled SSL Cert checks
			CURLOPT_POSTFIELDS		 =>	$body			// Post data
	);
	
	$ch      = curl_init( $url );
	curl_setopt_array( $ch, $options );
	$content = curl_exec( $ch );
	$err     = curl_errno( $ch );
	$errmsg  = curl_error( $ch );
	$header  = curl_getinfo( $ch );
	curl_close( $ch );
	return $content;
}

function resultsToEmail($results = null){ //Send $results to Email
	if($results){
		$headers = "From: " . TO_EMAIL . "\r\n";
		$headers .= "Reply-To: ". TO_EMAIL . "\r\n";
		$headers .= "MIME-Version: 1.0\r\n";
		$headers .= "Content-Type: text/html; charset=ISO-8859-1\r\n";	
		$body = "<h1>WARNING: URL Status Checker - ISSUES DETECTED</h1>";
		$body .= "<p>The following URL's have been detected:</p>";
		$body .= "<table>";
		$body .= "<tr>";
		$body .= "<th>URL</th>";
		$body .= "<th>Response</th>";
		$body .= "</tr>";
		foreach($results as $result){
			$body .= "<tr>";		
			$body .= "<td>".$result['URL']."</td>";		
			$body .= "<td>".$result['RESPONSE']."</td>";		
			$body .= "</tr>";
		}
		$body .= "</table>";
		mail(TO_EMAIL, 'URL Status Checker - ISSUES DETECTED', $body, $headers);
	} 
}

function resultsToSlack($results = null){ //Send $results to Slack Chat
	if($results){	
		$message = "Google Safety Issues Detected:\n";
		foreach($results as $result){
			$message .= "URL: ".$result['URL']." RESPONSE: ".$result['RESPONSE']."\n"; 
		}
		$data = "payload=" . json_encode(array(         
						"channel"       =>  SLACK_CHANNEL,
						"text"          =>  $message,
						"icon_emoji"    =>  ":warning:"
				));
						 		 
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, SLACK_WEBHOOK_URL);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
		curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		$result = curl_exec($ch);		 
		curl_close($ch);
	}
}
?>