<?php
defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

class Wordpress_Verifyne_Userprofile_Handler {


    function __construct() {
        add_action( 'show_user_profile', array( $this, 'on_profile_load' ));
    }


    /**
    * Injects verifyne area into profile page (for connecting a user's profile with a verifyne identity)
    */
    function on_profile_load() {
 
        # Load CSS
        wp_enqueue_style('wp-verifyne-style', plugin_dir_url( __FILE__ ) . 'wp-verifyne.css', array());

        echo "<h3 id='verifyne'>Login using verifyne</h3>";
        echo "<table name='verifyne' class='form-table'><tr valign='top'><th scope='row'>Associated verifyne ID</th><td>";

        # Get the action from the URL
        $action = $_GET["action"];

        # Someone clicked the verifyne button
        if("vf_register" === $action) {
            $ret = $this->handle_register_request();
        }

        # Someone scanned the QR code and needs to be verified
        elseif("vf_verify" === $action) {
            $ret = $this->handle_verify_request();
        }

        # user wants to remove the association
        elseif("vf_remove" === $action) {
            $ret = $this->handle_remove_request();
        }

        # Initial state. Display the button and wait for someone to click it
        else {
            $ret = $this->show_verifyne_status();
        }

        if( is_wp_error( $ret )) {
            echo "<b>Error: ".$ret->get_error_message()."</b>";
        }
        else {
            echo $ret;
        }
        echo "</td></tr></table>";
    }


    /**
    * Dump the current verifyne status
    */
    function show_verifyne_status() {
        $vf_id = $this->get_linked_verifyne_id();

        if( is_wp_error( $vf_id ) )
            return $vf_id;

        # There is a verifyne ID associated
        if( strlen($vf_id) > 0 ) {
            $ret = "<span>".$vf_id."&nbsp;</span>
                    <a href='?action=vf_remove#verifyne'><span class='button-secondary'>Remove</span></a><br>
                    <span class='description'>A verifyne ID is linked with your account.</span>";
        }
        # No verifyne ID is currently associated with the account
        else {
            $ret = "<span>Your account is not linked to a verifyne ID</span>
                    <a href='?action=vf_register#verifyne'><span class='button-secondary'>Connect</span></a><br>
                    <span class='description'>Click here to associate a verifyne ID with your wordpress account</span>";
        }
        return $ret;
    }


    # When parameter verifyne_action is 'vf_remove'
    function handle_remove_request() {

        $ret = $this->delete_linked_verifyne_id();

        if( is_wp_error( $ret ) )
            return $ret;

        return "<span>The link with your verifyne ID has been removed.</span>
                <a href='?action=vf_register#verifyne'><span class='button-secondary'>Connect</span></a><br>
                <span class='description'>Click here to associate a verifyne ID with your wordpress account</span>";
    }


    # When parameter verifyne_action is 'vf_register'
    function handle_register_request() {
        # Get session ID
        if( !isset($_COOKIE["vf_session_id"]) )
            return new WP_Error("verifyne", "No active session. Please try again.");

        # Construct purpose
        global $current_user;
        get_currentuserinfo();

        $blog_title = get_bloginfo('name');
        $username = $current_user->user_login;
        $purpose    = "Connect identity with Wordpress account: ".$username."@".$blog_title;

        # Load a new QR code and inject the javascript logic
        $ticket_data = Wordpress_Verifyne_API::get_new_ticket($purpose);

        # Check for errors
        if( is_wp_error($ticket_data) )
            return $ticket_data;

        $tid = $ticket_data["ticketid"];

        # Store session id as transient, valid for 15 minutes
        set_transient( $tid, array(
            "session" => $_COOKIE["vf_session_id"],
            "ticket"  => $ticket_data, 900 ));

        # Show QR image
        print "<div class='verifyne-qr-container'><img class='verifyne-qr-image' src='".$ticket_data["qr"]."'></div>";
        # Show status DIV
        print "<div id='verifyne-state-div'>Ready</div>";

        # Which page to load if the ticket has been authenticated
        $authenticated_redir = add_query_arg( array( "action" => "vf_verify", "ticketid" => urlencode($tid) ) );
        # When a ticket expired we just call vf_show again to generate a new ticket
        $expired_redir       = add_query_arg( "action", "vf_register" );
        # Inject JavaScript logic
        Wordpress_Verifyne_API::print_javascript_logic($ticket_data["ticketid"], $authenticated_redir, $expired_redir);
    }


    /**
    * Verify the user authentication
    */
    function handle_verify_request() {
        # Get session ID
        if( !isset($_COOKIE["vf_session_id"]) )
            return new WP_Error("verifyne", "No active session. Please try again.");

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

        print("<div id='verifyne-state-div'>Authenticated:<br>".$verifyne_user_id."</div>");

        $this->set_linked_verifyne_id($verifyne_user_id);

        print("<script>location.replace(window.location.origin + window.location.pathname + '#verifyne')</script>");
    }


    #############################################################################
    # ASSOCIATION RELATED FUNCTIONS
    #############################################################################


    function get_linked_verifyne_id() {
        # Sanity check
        if ( ! is_user_logged_in() ) {
            return new WP_Error("verifyne", "No user logged in");
        }

        global $current_user;
        get_currentuserinfo();

        return get_user_meta( $current_user->ID, 'verifyne_user_id', true);
    }


    function delete_linked_verifyne_id() {
        # Sanity check
        if ( ! is_user_logged_in() ) {
            return new WP_Error("verifyne", "No user logged in");
        }

        # Get current user info
        global $current_user;
        get_currentuserinfo();

        # Check if account is alread linked to a verifyne id
        if( strlen($this->get_linked_verifyne_id()) > 0 ) {
            delete_user_meta($current_user->ID, 'verifyne_user_id');
        }
        return TRUE;
    }


    function set_linked_verifyne_id($verifyne_user_id) {
        # Sanity check
        if ( ! is_user_logged_in() ) {
            return new WP_Error("verifyne", "No user logged in");
        }

        # Get current user info
        global $current_user;
        get_currentuserinfo();

        # Delete old value if existent
        $this->delete_linked_verifyne_id();

        # Check if account is alread linked to a verifyne id
        add_user_meta( $current_user->ID, 'verifyne_user_id', $verifyne_user_id, true);
    }

} // CLASS

