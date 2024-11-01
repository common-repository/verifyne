<?php
defined( 'ABSPATH' ) or die( 'No script kiddies please!' );


class Wordpress_Verifyne_API {

	static $VERIFYNE_CA_KEY = NULL;
	static $PROVIDER_KEY = NULL;
	static $VERIFYNE_CA_KEY_HEX = NULL;
	static $PROVIDER_KEY_HEX = NULL;

	/**
	* Fetch the provider key from verifyne server.
	* Verifies the key if libsodium is available.
	* After successful return the key can be read from the global class variables PROVIDER_KEY and PROVIDER_KEY_HEX.
	*
	* @return Returns TRUE if successful, or a WP_Error instance otherwise.
	*/
	static function get_provider_key() {
		if(NULL !== self::$PROVIDER_KEY)
			return TRUE;

		if( !extension_loaded('libsodium') )
			return new WP_Error("verifyne", "libsodium not available");

		# Decode CA key
		self::$VERIFYNE_CA_KEY = base64_decode("i7dbWvlFdHwviUXav7N1Lwoi+DOJDG9SuFHNwP/AUjU=", true);
		if(FALSE === self::$VERIFYNE_CA_KEY)
		    return new WP_Error("verifyne", "Wrong CA key");

		# Invoke API
		$cont = @file_get_contents("https://api.verifyne.me/v1/provider-key");
		if(FALSE === $cont)
		    return new WP_Error("verifyne", "Failed to query verifyne API");

		# Read JSON
		$resp = @json_decode($cont);
		if(NULL === $resp)
		    return new WP_Error("verifyne", "Invalid JSON received");

		# Decode signature
		$sig = base64_decode($resp->content->psig, $strict = true);
		if(FALSE === $sig)
		    return new WP_Error("verifyne", "Decoding signature failed");

		$pkey_b64 = $resp->content->pkey;

		# Verify signature
		if(TRUE !== @\Sodium\crypto_sign_verify_detached($sig, $pkey_b64, self::$VERIFYNE_CA_KEY)){
		    return new WP_Error("verifyne", "Failed to verify provider key");
		}
	    
		# Decode key (dec == 'ed25519:xxxxxxx...')
	    $dec = base64_decode($pkey_b64, $strict = true);
		if(FALSE === $dec)
		    return new WP_Error("verifyne", "Decoding provider key description failed");

	    # Decode raw provider key
	    self::$PROVIDER_KEY = base64_decode(explode(":", $dec, 2)[1], $strict = true);
		if(FALSE === self::$PROVIDER_KEY)
		    return new WP_Error("verifyne", "Decoding provider key");

		self::$PROVIDER_KEY_HEX = @\Sodium\bin2hex(self::$PROVIDER_KEY);
		self::$VERIFYNE_CA_KEY_HEX = @\Sodium\bin2hex(self::$VERIFYNE_CA_KEY);

	    return TRUE;
	}

	/**
	* Request a new ticket from verifyne server.
	* Verifies provider signature if libsodium is available.
	*
	* @return Returns an associative array containing the ticket information, or a WP_Error instance on error.
	*/
	static function get_new_ticket($purpose, $type = "authonly") {
	    # Load parameters
	    $nonce   = uniqid(dechex(mt_rand()));

	    # Prepare parameters
	    $params = "purpose=".urlencode($purpose)."&".
	              "type=$type&".
	              "nonce=".urlencode($nonce);

	    # Invoke API
	    $cont = @file_get_contents("https://api.verifyne.me/v1/new-ticket?".$params);
	    if(FALSE === $cont)
	        return new WP_Error("verifyne", "Failed to query verifyne API");

	    # Read JSON
	    $resp = @json_decode($cont);
	    if(NULL === $resp)
	        return new WP_Error("verifyne", "Invalid JSON received");

	    # Check result
	    if($resp->result->code !== 0)
	        return new WP_Error("verifyne", $resp->result->description);

	    # Print ticket data
	    $qr_url = $resp->content->qr;
	    $ticket = $resp->content->ticket;

	    # Sanity checks
	    if($ticket->purpose !== $purpose || $ticket->nonce !== $nonce)
	      return new WP_Error("verifyne", "Received invalid data from verifyne server");

	  	# We can verify the cryptographic signatures :)
	    if( extension_loaded('libsodium') ) {
	    	# Fetch provider key
	    	$ret = self::get_provider_key();
	    	if( is_wp_error($ret) )
	    		return $ret;
			
			#<type>:<id>:<expires>:<nonce>:<purpose>
			$payload = $ticket->type.":".$ticket->id.":".$ticket->expires.":".$ticket->nonce.":".$ticket->purpose;

			# Decode provider signature
			$signature = base64_decode($ticket->provider_signature, $strict = true);
			if(FALSE === $signature)
				return new WP_Error("verifyne", "Undecodable provider signature");
			
			# Check ticket signature
			if(TRUE !== @\Sodium\crypto_sign_verify_detached($signature, $payload, self::$PROVIDER_KEY))
			    return new WP_Error("verifyne", "Invalid provider signature");

			# Ticket is legit :)
	    }

	    return array(
	    	"ticketid" 	=> $ticket->id,
	    	"purpose" 	=> $purpose,
	    	"nonce" 	=> $nonce,
	    	"qr"		=> $qr_url
	    );

	}


	/**
	* Checks if the ticket has been authenticated.
	* Verifies user signature if libsodium is available.
	*
	* @return Returns an associative array containing user_id and aux data, or a WP_Error instance on error.
	*/
	static function verify_authentication($ticketid, $purpose, $nonce) {

		# Prepare parameters
		$params = "ticket-id=".urlencode($ticketid);

		# Invoke API
		$cont = @file_get_contents("https://api.verifyne.me/v1/ticket-state?".$params);
		if(FALSE === $cont)
		    return new WP_Error("verifyne", "Failed to query verifyne API");

		# Read JSON
		$resp = @json_decode($cont);
		if(NULL === $resp)
		    return new WP_Error("verifyne", "Invalid JSON received");

		# Check result
		if($resp->result->code !== 0)
		    return new WP_Error("verifyne", $resp->result->description);

		# Extract content
		$ticket = $resp->content;

		# Sanity checks
		if($ticket->id !== $ticketid || $ticket->purpose !== $purpose || $ticket->nonce !== $nonce)
		  return new WP_Error("verifyne", "Received invalid data from verifyne server");

		# We can verify the cryptographic signatures :)
		if( extension_loaded('libsodium') ) {
		  	#<provider_signature>:<type>:<id>:<expires>:<nonce>:<purpose>
		  	$payload = $ticket->provider_signature.":".$ticket->type.":".$ticket->id.":".$ticket->expires.":".$ticket->nonce.":".$ticket->purpose;

		  	$signature = base64_decode($ticket->user_signature, $strict = true);
			if(FALSE === $signature)
				return new WP_Error("verifyne", "Undecodable user signature");

		  	$user_key = base64_decode($ticket->user_id, $strict = true);
			if(FALSE === $user_key)
				return new WP_Error("verifyne", "Undecodable user key");

			# Check user signature
			if(TRUE !== @\Sodium\crypto_sign_verify_detached($signature, $payload, $user_key))
			    return new WP_Error("verifyne", "Invalid user signature");

			# User signature is legit :)
		}

		return array(
			"userid" => $ticket->user_id,
			"aux"    => $ticket->aux
		);
	}


	/**
	* Dump javascript for polling the ticket status via AJAX
	*
	* The two redirection URLs are esc_js escaped before they are insertet as JavaScript
	*
	* @param ticketid The verifyne ticket ID
	* @param authenticated_redir URL to redirect to when ticket becomes authenticated
	* @param expired_redir URL to redirect to when ticket has expired
	*/
	static function print_javascript_logic($ticketid, $authenticated_redir, $expired_redir) {
		# Write some variables
		?>
		<script>
			/* The ticket id of which to poll the status */
			verifyneTicketID="<?php echo $ticketid; ?>";
			/* Where to go after a ticket has beeen authenticated */
			verifyneRedirectForVerification = '<?php echo $authenticated_redir; ?>';
			/* Where to go after a ticket has expired */
			verifyneRedirectForNewTicket    = '<?php echo $expired_redir; ?>';
		</script>
		<?php
		# Insert JavaScript code
		wp_enqueue_script('vf_javascript_logic', plugin_dir_url( __FILE__ ) . 'vf_javascript_logic.js', array("jquery"));
	}


} // CLASS

