<?php
defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

/**
* Handle verifyne integration in login page
*/
class Wordpress_Verifyne_Loginpage_Handler {


    function __construct() {
        add_action( 'login_form', array( $this, 'on_login_form_load' ));
    }


    /**
    * Enqueue styles and decide what to do
    *
    * Evaluates "action" parameter.
    */
    function on_login_form_load() {
        # Load CSS
        wp_enqueue_style('wp-verifyne-style', plugin_dir_url( __FILE__ ) . 'wp-verifyne.css', array());
        wp_enqueue_script('jquery');

        # Get action parameter
        $action = $_GET["action"];

        # Someone clicked the verifyne button
        if("vf_show" === $action) {
            $this->handle_show_request();
            return;
        }

        # Someone scanned the QR code and needs to be verified
        if("vf_verify" === $action) {
            $ret = $this->handle_verify_request();
            if( is_wp_error( $ret )) {
                print("<div id='verifyne-state-div' class='verifyne-center'>".$ret->get_error_message()."<br><a href='?action=vf_show'>Try again</a></div>");
            }
            return;
        }

        # Remember redirect_to
        $redir_param = isset($_REQUEST["redirect_to"]) ? "&redirect_to=".$_REQUEST["redirect_to"] : "";

        # Get the button image URL
        $login_img_url = plugins_url( 'vf_button_login.png', __FILE__ );

        # Display the button
        echo "<a href='?action=vf_show".$redir_param."'><div class='verifyne-login-button-container'><img class='verifyne-login-button' src='".$login_img_url."'></div></a>";

    }


    /**
    * Called when the user clicked the verifyne button to show the QR code.
    *
    * @return Nothing. Prints an error message on the page if something goes wrong.
    */
    function handle_show_request() {
        # Get blog title
        $blog_title = get_bloginfo('name');

        # Construct purpose
        $purpose = "Wordpress Login to ".$blog_title;

        # Load a new QR code
        $ticket_data = Wordpress_Verifyne_API::get_new_ticket($purpose);

        # Check for errors
        if( is_wp_error($ticket_data) ) {
            print("<div id='verifyne-state-div' class='verifyne-center'>".$ticket_data->get_error_message()."</div>");
            return;
        }

        $tid = $ticket_data["ticketid"];

        # Store session id as transient, valid for 15 minutes
        set_transient( $tid, array(
            "session" => $_COOKIE["vf_session_id"],
            "ticket"  => $ticket_data, 900 ));

        # Which page to load if the ticket has been authenticated
        $authenticated_redir = add_query_arg( array("action" => "vf_verify", "ticketid" => urlencode($tid)) );
        # When a ticket expired we just call vf_show again to generate a new ticket
        $expired_redir       = add_query_arg( "action", "vf_show" );

        ?>
        <div class='verifyne-qr-container verifyne-center'>
            <img class='verifyne-qr-image' src='<?php echo $ticket_data["qr"] ?>'>
        </div>
        <div id='verifyne-state-div' class='verifyne-center'>Ready</div>
        <?php

        # Inject JavaScript logic
        Wordpress_Verifyne_API::print_javascript_logic($tid, $authenticated_redir, $expired_redir);
    }


    /**
    * User has scanned the ticket. Verify the signature and extract the user_id from the ticket.
    *
    * @return Returns an instance of WP_Error on failure, otherwise reloads page with parameter action set to "vf_login"
    */
    function handle_verify_request() {
        # Get session ID
        if( !isset($_COOKIE["vf_session_id"]) )
            return new WP_Error("verifyne", "No session ID set.");

        # Get ticket ID
        if( !isset($_REQUEST["ticketid"]) )
            return new WP_Error("verifyne", "No ticket ID set.");

        # Read and check session data
        $vf_data = get_transient($_REQUEST["ticketid"]);

        if( $vf_data === false )
            return new WP_Error("verifyne", "Session expired.");

        if( $vf_data["session"] !== $_COOKIE["vf_session_id"])
            return new WP_Error("verifyne", "Invalid session identifier.");

        $ticket_data = $vf_data["ticket"];

        if(!isset($ticket_data["ticketid"])
        || !isset($ticket_data["purpose"])
        || !isset($ticket_data["nonce"])
        )
            return new WP_Error("verifyne", "Wrong session state");

        # Verify authentication
        $ret = Wordpress_Verifyne_API::verify_authentication(
            $ticket_data["ticketid"],
            $ticket_data["purpose"],
            $ticket_data["nonce"]);

        if( is_wp_error($ret) )
            return $ret;

        $verifyne_user_id = $ret["userid"];

        $ret = get_users( array( "meta_key" => "verifyne_user_id", "meta_value" => $verifyne_user_id ) );

        if( !is_array($ret) || sizeof($ret) < 1 || !is_a($ret[0], "WP_User")){
            return new WP_Error("verifyne", "This identity is not registered.");
        }

        if( sizeof($ret) > 1 ){
            return new WP_Error("verifyne", "This identity is registered for multiple accounts.");
        }

        #
        # At this point the user is authenticated.
        #

        # This is the user
        $wpuser = $ret[0];

        $this->log_user_in($wpuser->ID);
    }

    /**
    * Login the user with the user id $wp_user_id
    */
    function log_user_in($wp_user_id) {

        $user = get_user_by( 'id', $wp_user_id ); 

        # Log user in

        if( $user ) {
            wp_set_current_user( $wp_user_id, $user->user_login );
            wp_set_auth_cookie( $wp_user_id );
            do_action( 'wp_login', $user->user_login );

            $redir_param = isset($_REQUEST["redirect_to"]) ? $_REQUEST["redirect_to"] : "";

            if( strlen($redir_param) === 0 )
                $redir_param = get_edit_user_link( $wp_user_id );

            wp_safe_redirect($redir_param);
            exit();
        }
    }


} // CLASS


