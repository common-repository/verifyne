<?php

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

/**
* Handler verifyne integration in admin site
*/
class Wordpress_Verifyne_Adminsettings {
    
    const REQUIRED_CAPABILITY = "manage_options";
    
    function __construct($plugin_base_name) {
        add_action( 'admin_menu', array($this, 'register_menu') );
        add_action( 'admin_init', array($this, 'page_init') );
        # Adds "Settings" link to plugin listing
        add_filter( 'plugin_action_links_' . $plugin_base_name, array($this, 'add_action_links') );
    }

   
    /**
    * Add "Settings" link in plugin listing
    */
    function add_action_links ( $links ) {
        $mylinks = array(
            '<a href="' . admin_url( 'options-general.php?page=verifyne' ) . '">Settings</a>',
        );
        return array_merge( $links, $mylinks );
    }


    /**
    * Register the verifyne settings page
    */
    function register_menu() {
        add_options_page('Verifyne Settings', 'Verifyne', self::REQUIRED_CAPABILITY, 'verifyne', array($this, 'print_settings_page_content') );
    }
    

    /**
    * Generate verifyne settings page content
    */
    function print_settings_page_content() {
        if ( !current_user_can( self::REQUIRED_CAPABILITY ) )  {
            wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
        }
        # Encapsulate everything within this wrapper tag
        echo '<div class="wrap">';
        
        # Settings
        echo '<h2>Verifyne Settings</h2>';
        echo '<form method="post" action="options.php">';
        settings_fields( 'vf_option_group' );   
        do_settings_sections( 'vf-basic-settings') ;
        submit_button(); 
        echo '</form>';
        
        # Info table
        Wordpress_Verifyne_API::get_provider_key();
        echo "<h3>Information</h3>";
        echo "
        <table name='vf_info' class='form-table'>
            <tr valign='top'>
                <th scope='row'>Plugin version number</th>
                <td>".Wordpress_Verifyne_Plugin::PLUGIN_VERSION_NUMBER."</td>
            </tr>
            <tr valign='top'>
                <th scope='row'>Sodium library available</th>
                <td>". (extension_loaded('libsodium') ? "YES" : "no") ."</td>
            </tr>
            <tr valign='top'>
                <th scope='row'>Verifyne CA Key</th>
                <td>". (NULL === Wordpress_Verifyne_API::$VERIFYNE_CA_KEY_HEX ? "n/a" : Wordpress_Verifyne_API::$VERIFYNE_CA_KEY_HEX) ."</td>
            </tr>
            <tr valign='top'>
                <th scope='row'>Verifyne Provider Key</th>
                <td>". (NULL === Wordpress_Verifyne_API::$PROVIDER_KEY_HEX ? "n/a" : Wordpress_Verifyne_API::$PROVIDER_KEY_HEX) ."</td>
            </tr>
        </table>";
        
        echo '</div>';	// wrapper tag
    }


    /**
    * Initialize verifyne settings page
    */
    function page_init() {
        register_setting(
            'vf_option_group', 			 # Option group
            'vf_only_two_factor',		 # Option name
            array( $this, 'sanitize' )	 # Sanitize
        );
    
        add_settings_section(
            'setting_section_id', 	     # Section-ID
            '', 		                 # Title
            array( $this, 'print_section_info' ), # Callback
            'vf-basic-settings' 	     # Page
        );  
        
        add_settings_field(
            'id_only_twofactor',	     # ID
            'Only two-factor', 	         # Title 
            array( $this, 'option_two_factor_callback' ), # Callback
            'vf-basic-settings',	     # Page
            'setting_section_id' 	     # Section-ID
        );      
    
    }
    

    /**
    * Sanitize each setting field as needed
    *
    * @param array $input Contains all settings fields as array keys
    */
    public function sanitize( $input )
    {
        if( strlen($input) > 0)
            $new_input = "yes";
        else
            $new_input = "no";
        
        return $new_input;
    }


    /** 
    * Print the Section text
    */
    public function print_section_info()
    {
        //print 'Enter your settings below:';
    }


    /** 
    * Get the settings option array and print one of its values
    */
    public function option_two_factor_callback()
    {
        $val = get_option("vf_only_two_factor", "no");
        $state = $val === "yes" ? "checked" : "";
        echo "<input type='checkbox' id='id_only_twofactor' name='vf_only_two_factor' $state disabled />";
        echo "<span>Enable this and users must always also provide their password to login <b>(This option will be avialable in a future version)</b></span>";
    }


} //CLASS
