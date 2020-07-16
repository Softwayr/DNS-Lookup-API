<?php

/************************/
/*    DNS LOOKUP API    */
/*     by Softwayr      */
/*   www.softwayr.com   */
/************************/

// Set default timezone to Europe/London.
date_default_timezone_set("Europe/London");

/**
 *		Calculate the difference between two dates in minutes.
 *		@param DateTime $date1 The first date as a DateTime instance.
 *		@param DateTime $date2 The second date as a DateTime instance.
 *		@return int The calculated minutes as an Integer.
 */
function diffInMinutes( DateTime $date1, DateTime $date2 ) {
	// Calculate the difference between the two dates as a DateTime instance.
	// Save DateTime difference instance to $diff variable.
	$diff = $date1->diff( $date2 );
	// Holding variable for minutes calculated.
	$minutes = 0;
	// If the difference in days is greater than a day,
	// calculate appropriate minutes and add to $minutes variable.
	if( $diff->days > 0 ) $minutes += $diff->days * 24 * 60;
	// If the difference in hours is greater than an hour
	// calculate appropriate minutes and add to $minutes variable.
	if( $diff->h > 0 ) $minutes += $diff->h;
	// If the difference in minutes is greater than a minute,
	// calculate appropriate minutes and add to $minutes variable.
	if( $diff->i > 0 ) $minutes += $diff->i;
	// Return calculated minutes to caller.
	return $minutes;
}

/**
 *		Lookup the DNS records for the given domain (also looks at www subdomain).
 *		DNS Record types for given domain are limited to
 *		SOA, NS, A, AAAA, CNAME, MX, and TXT.
 *		DNS Record types for www subdomain of given domain are limited to
 *		A, AAAA, and CNAME.
 *		@param String $domain The domain to perform the lookup for.
 *		@return Array|boolean An array containing the result, or false on failure.
 */
function lookup( String $domain ) {
	// Perform the lookup for the given domain. Look for SOA, NS, A, AAAA, CNAME, MX, and TXT records.
	$result1 = dns_get_record( $domain, DNS_SOA + DNS_NS + DNS_A + DNS_AAAA + DNS_CNAME + DNS_MX + DNS_TXT );
	// Perform an additional lookup for the www subdomain of the given domain.
	// Look for A, AAAA, and CNAME records.
	$result2 = dns_get_record( "www." . $domain, DNS_A + DNS_AAAA + DNS_CNAME );
	// Check if any DNS Records were found.
	if( $result1 !== FALSE && is_array( $result1 ) && !empty( $result1 ) && count( $result1 ) > 0 ):
		// Merge both lookups (domain and www subdomain) into one array.
    	$result = array_merge( $result1, $result2 );
    	// Sort that array in ascending order.
    	sort( $result );
    	// Return the array.
    	return $result;
	else:
		// No records were found, return false.
		return false;
	endif;
}

/**
 *		Retrieve a cached set of results for the given domain if exists,
 *		otherwise lookup the records as they are now in the Domain Name System and
 *		cache it.
 *		@param String $domain The domain to retrieve the DNS records for.
 *		@param boolean $update_cache (Optional) Specify whether to update the cache.
 *		@return array|boolean An array of results or false on failure.
 */
function cache( String $domain, $update_cache = false ) {
	// Ensure update cache is a boolean or change to false.
	$update_cache = is_bool( $update_cache ) ? $update_cache : false;
	
	// Try to create the SQLite database for storing cached results.
	try {
		// New PDO instance for SQLite database.
		$PDO = new PDO('sqlite:dns_cache.sqlite');
		// Execute a database query to create a table with appropriate columns.
		$PDO->exec('CREATE TABLE dns_cache(domain TEXT PRIMARY KEY, data TEXT, last_updated TIMESTAMP)');
		
		// Prepare to select a row from the database for the given domain.
		$select = $PDO->prepare("SELECT * FROM dns_cache WHERE domain=:domain");
		// Execute the above query, replacing the placeholder with the given domain.
		$select->execute([':domain' => $domain]);
		// Fetch all found rows as an Associative Array.
		$results = $select->fetchAll(PDO::FETCH_ASSOC);
		
		// Check that only one row was found in the database.
		if( count( $results ) == 1 ) {
			// Go one level deeper into the results array since we only have one row.
			$result = $results[0];
			// Create a DateTime instance from the cached Last Updated time.
			$cache_last_updated = new DateTime( $result['last_updated'] );
			// Create a DateTime instance of the current date and time.
			$now = new DateTime();
			// Calculate difference between cache last updated and current time.
			$time_since_update = $cache_last_updated->diff( $now );
			// Check if a cache update has been requested and if five minutes has
			// elapsed since last cache update.
			if( ( $update_cache && $time_since_update->format('%i') >= 5 ) || $time_since_update->format('%a') >= 1 ) {
				// Prepare to delete old cache record from database
				$query = $PDO->prepare("DELETE FROM dns_cache WHERE domain=:domain");
				// Execute above query replacing placeholder with given domain.
				$query->execute([':domain' => $domain]);
				// Restart function from beginning again, and return the results.
				return cache( $domain );
			}
			// Calculate difference in minutes since last update.
			$minutes_since_update = diffInMinutes( $cache_last_updated, $now );
			// Add to Result Array the minutes remaining until manual update can be
			// requested if minutes since last update are less than five.
			if( $minutes_since_update <= 5 ) {
				$result['minutes_till_manual_update'] = 6 - $minutes_since_update;
			}
			// Add to Result Array the hours remaining until auto update.
			if( $time_since_update->h < 24 ) {
				$result['hours_till_auto_update'] = 24 - $time_since_update->h;
			}
			
			// Return the Result Array as JavaScript Object Notation (JSON).
			return json_encode( $result );
		} else {
			// No cache was found. Perform a fresh DNS lookup of the given domain.
			$dns = lookup( $domain );
			
			// Check if any DNS records were found.
			if( $dns !== FALSE ) {
				// Encode the DNS result array as JavaScript Object Notation (JSON).
				$data = json_encode( $dns );
				// Prepare to insert the DNS result into database.
				$insert = $PDO->prepare("INSERT INTO dns_cache (domain, data, last_updated) VALUES(:domain, :data, datetime('now'))");
				// Execute the above query replacing the placeholders for the given
				// domain, and the DNS result data.
				$insert->execute([':domain' => $domain, ':data' => $data]);
				
				// Cache saved, now time to check for and use it. "From the Top!"
				return cache( $domain );
			} else {
				// No DNS records found, return a JSON encoded array with an error
				// stating no records were found, and a generated readable message.
				return json_encode( ['type' => 'error', 'code' => 'DnsNotFound', 'message' => 'Sorry, there was a problem looking up the DNS for "' . $domain . '". Please try a different domain or retry later.'] );
			}
		}
	// For some reason, a PDO Instance could not be created. Silently log this.
	} catch (PDOException $pdoex) {
		error_log( "PDOException: " . $pdoex->getMessage() );
	}
	// If we got this far, assume failure and return false.
	return false;
}

// Retrieve the domain for lookup from the GET request.
$domain = isset( $_GET['domain'] ) ? $_GET['domain'] : "";
// Retrieve whether to update the cache from the GET request.
$update = isset( $_GET['update'] ) ? $_GET['update'] : "";

// Set the content type header to text/plain.
header("Content-Type: text/plain");

// Check if a domain has been specified for lookup.
if( $domain ) {
	// Check if cache is to be updated.
	if( $update == "update" ) {
		// Perform lookup and clear the cache if we can.
		echo cache( $domain, true );
	} else {
		// Retrieve the cache.
		echo cache( $domain );
	}
// No domain specified.
} else {
	// Output a JSON encoded array with an error stating no domain provided
	// and a generated readable message.
	$output = ['type' => 'error', 'code' => 'MissingDomainParameter', 'message' => 'Domain parameter must be provided.'];
	echo json_encode( $output );
}