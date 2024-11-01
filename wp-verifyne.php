<?php
defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

require_once "vf_verifyne_api.php";
require_once "vf_login_page.php";
require_once "vf_user_profile.php";
require_once "vf_admin_settings.php";

/*
Plugin Name: Verifyne
Plugin URI: https://github.com/sruester/wp-verifyne
Description: A WordPress plugin that allows users to login with verifyne.
Version: 0.3.4
Author: Stefan Ruester
Author URI: https://verifyne.me
License: GPLv3
*/

class Wordpress_Verifyne_Plugin {

    const PLUGIN_VERSION_NUMBER = 5;

    #############################################################################
    # VARIABLES
    #############################################################################

    private $login_page_handler;
    private $userprofile_handler;
    private $admin_settings;

    #############################################################################
    # CONSTRUCTOR /  SINGLETON
    #############################################################################

    private static $instance = NULL;

    public static function get_instance() {
        if(NULL === self::$instance) {
            self::$instance = new self;
        }
        return self::$instance;
    }

    function __construct() {
        # Load instances
        $this->login_page_handler  = new Wordpress_Verifyne_Loginpage_Handler();
        $this->userprofile_handler = new Wordpress_Verifyne_Userprofile_Handler();
        $this->admin_settings      = new Wordpress_Verifyne_Adminsettings(plugin_basename(__FILE__));
        # Register hooks
        add_action( 'init', array( $this, 'set_verifyne_cookie' ));
    }

    #############################################################################
    # METHODS
    #############################################################################


    /**
    * Set session cookie
    */
    function set_verifyne_cookie() {
        global $pagenow;

        # Only set session cookie on profile and login page
        if( $pagenow === "profile.php" || $pagenow === "wp-login.php" ) {
            # Check if session is set
            if( isset($_COOKIE["vf_session_id"]) )
                return;
            # Generate a new session ID
            $vf_session_id = '';
            for ( $i = 0; $i < 32; $i++ ) {
                $vf_session_id .= chr(wp_rand(65, 90)); # A-Z
            }
            # Set cookie
            setcookie("vf_session_id", $vf_session_id);
        }
        # Remove cookie on all other pages
        else {
            if( ! isset($_COOKIE["vf_session_id"]) )
                return;
            # Delete cookie
            unset($_COOKIE["vf_session_id"]);
            setcookie("vf_session_id", "", time() - 86400);
            return;
        }
    }

} // CLASS

Wordpress_Verifyne_Plugin::get_instance();
