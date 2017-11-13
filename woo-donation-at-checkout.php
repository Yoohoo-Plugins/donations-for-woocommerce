<?php
/*
Plugin Name: Donations For WooCommerce
Description: Allows users to make a donation at checkout. This donation could go to a charity or cause of some sort. However is handled as an additional upselling product,
Version: 1.2.1
Author: YooHoo Plugins
*/

global $wdac_donations_table, $wdac_donations_reporting, $wpdb;
$wdac_donations_table = $wpdb->prefix . "wdac_donations";
$wdac_donations_reporting = $wpdb->prefix . "wdac_reporting";

function wdac_activate() {
    wdac_donations_database_tables();
    wdac_reporting_database_tables();
}
register_activation_hook( __FILE__, 'wdac_activate' );

function wdac_donations_database_tables(){
    global $wdac_donations_table, $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "
    CREATE TABLE $wdac_donations_table (
      id int(11) NOT NULL AUTO_INCREMENT,
      name varchar(700) NOT NULL,
      content varchar(700) NOT NULL,
      amount decimal(11) NOT NULL,
      image varchar(700),
      PRIMARY KEY  (id)
    ) $charset_collate ;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);

}

function wdac_reporting_database_tables(){
    global $wdac_donations_reporting, $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    $reporting_sql = "
    CREATE TABLE $wdac_donations_reporting (
      id int(11) NOT NULL AUTO_INCREMENT,
      campaign_id int(11) NOT NULL,
      order_id int(11) NOT NULL,
      amount decimal(11) NOT NULL,
      PRIMARY KEY  (id)
    ) $charset_collate ;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($reporting_sql);

    error_log("HELLO WE ACTIVATED");
}

function wdac_admin_menu() {
    $show_in_menu = current_user_can( 'manage_woocommerce' ) ? 'woocommerce' : false;
    $discount_slug = add_submenu_page( $show_in_menu, __( 'Donations' ), __( 'Donations' ), 'manage_woocommerce', 'wdac_primary_settings', 'wdac_settings_area');


    do_action("wdac_admin_menu_below");
}
add_action("admin_menu", "wdac_admin_menu", 99);

function wdac_insert_opt_in_cart_view(){
    $wdac_global_options = wdac_donations_get_global_options();
    $wdac_active_campaign = wdac_get_active_campaign($wdac_global_options);

    if($wdac_active_campaign !== false){
        $content_prepend = "";
        $content_append = "";
        if(isset($wdac_global_options['wdac_use_popup']) && $wdac_global_options['wdac_use_popup'] === '1'){
            //User is using a popup, insert the divs or check for any possible integrations here
            $content_prepend = wdac_get_popup_styles();
            $content_prepend .= "<div class='wdac_prompt_popup_div' style='display:none;'><div class='wdac_prompt_popup_div_close'>x</div>";

            $content_append = "</div>";
            $content_append .= wdac_get_popup_js_script($wdac_global_options);
        }

        $prompt_title = isset($wdac_global_options['wdac_donate_prefix']) ? htmlspecialchars($wdac_global_options['wdac_donate_prefix']) : __("Donate to");

        //Simple fallback to set default override amount to 10
        $forcd_default_amount_query = "";
        if(isset($wdac_global_options['wdac_user_amount']) && $wdac_global_options['wdac_user_amount'] === "1"){
            $forcd_default_amount_query = "&wdac_amount_overide=10";
        }

        $default_force_value = "10";
        if(isset($wdac_global_options['wdac_active_campaign']) && intval($wdac_global_options['wdac_active_campaign']) === -2){
            //User select
            $prompt_title = "<span><strong class='wdac_prompt_title'>" . $prompt_title . "</strong><hr> " . wdac_output_campaign_selector("wdac_custom_campaign_select", $wdac_global_options, true, true) . "</span>";

            $donations = wdac_get_all_donations();
            $is_first = true;
            $first_id = null;

            $description_spans = "";
            $image_elements = "";

            if( $donations !== false ){
                foreach ($donations as $key => $campaign) {
                    $styles = "display:none;";
                    if($is_first){
                        $styles = "display:block";
                        $is_first = false;
                        $first_id = $campaign->id;
                        $default_force_value = $campaign->amount;
                    }
                    if(isset($wdac_global_options['wdac_show_description']) && $wdac_global_options['wdac_show_description'] === "1"){
                        $description_spans .=    "<span class='wdac_description' id='wdac_description_" . $campaign->id  . "' style='" . $styles . "' >" . $campaign->content . "</span>";
                    }

                    if(isset($campaign->image) && $campaign->image !== NULL){
                        if( $image_attributes = wp_get_attachment_image_src( $campaign->image, 'full') ) {
                            $image_elements .= "<img class='wdac_image' id='wdac_image_" . $campaign->id . "' src='" . $image_attributes[0] . "' style='max-height: 20vh; max-width:80%; ".$styles."' />";
                        }
                    }

                }
            }


            $prompt_desc = $prompt_title . $image_elements . $description_spans . wdac_custom_selection_js_script();

            $prompt_button = "<br><br>
                            <div class='wdac_button_container'>
                                <a class='button wdac_button wdac_dynamic_button' href='?wdac_donate_campaign_id="
                                    .$first_id.$forcd_default_amount_query."'>" . __("Donate")
                                ."</a>
                                <a class='button wdac_button wdac_dismiss_button' href='#'>" . __("Close") . "</a>
                            </div>";
        } else {
            //Generic
            $prompt_title .= " " . htmlentities($wdac_active_campaign->name);
            if(isset($wdac_global_options['wdac_user_amount']) && $wdac_global_options['wdac_user_amount'] === "1"){
                $default_force_value = $wdac_active_campaign->amount;
            } else {
                $prompt_title .= " (" . get_woocommerce_currency_symbol()  . sprintf("%.2f", $wdac_active_campaign->amount) . ")";
            }
            $prompt_button = "<a class='button wdac_button wdac_dynamic_button' href='?wdac_donate_campaign_id=".$wdac_active_campaign->id.$forcd_default_amount_query."'>" . esc_attr($prompt_title) . "</a>";

            $prompt_desc = "";

            if(isset($wdac_active_campaign->image) && $wdac_active_campaign->image !== NULL){
                if( $image_attributes = wp_get_attachment_image_src( $wdac_active_campaign->image, 'full') ) {
                    $prompt_desc .= "<img src='" . $image_attributes[0] . "' style='max-width:80%; display:block;' />";
                }
            }

            if(isset($wdac_global_options['wdac_show_description']) && $wdac_global_options['wdac_show_description'] === "1"){
                $prompt_desc = "<span class='wdac_description' style='display:block;'>" . $wdac_active_campaign->content . "</span><br><br>";
            }


        }

        if(isset($wdac_global_options['wdac_user_amount']) && $wdac_global_options['wdac_user_amount'] === "1"){
            //User want to make own amounty
            $prompt_input_field = "<hr><input type='number' value='$default_force_value' id='wdac_amount_overide' >";
            $prompt_desc .= $prompt_input_field . wdac_custom_price_js_script();
        }

        echo apply_filters("wdac_main_content_output_filter", $content_prepend . $prompt_desc . $prompt_button . $content_append);
    }
}
add_action("woocommerce_after_cart_table", "wdac_insert_opt_in_cart_view", 99);
add_action("woocommerce_review_order_after_payment", "wdac_insert_opt_in_cart_view", 99);

function wdac_check_for_donations_before_cart(){

    if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
        return;
    }

    if(isset($_GET['wdac_donate_campaign_id'])){
        $wdac_global_options = wdac_donations_get_global_options();
       $wdac_campaign = wdac_campaign_get_data(intval($_GET['wdac_donate_campaign_id']));

        if($wdac_campaign !== false){
            global $woocommerce;

            $charge_title = isset($wdac_global_options['wdac_donate_prefix']) ? htmlspecialchars($wdac_global_options['wdac_donate_prefix']) : __("Donate to");
            $charge_title .= " " . $wdac_campaign->name;

            $override_value = false;
            if(isset($_GET['wdac_amount_overide'])){
                $override_value = floatval($_GET['wdac_amount_overide']);
                $woocommerce->cart->add_fee( $charge_title, $override_value );
            } else {
                $woocommerce->cart->add_fee( $charge_title, floatval($wdac_campaign->amount) );
            }

            wdac_embed_js_form_manipulators($_GET['wdac_donate_campaign_id'], $override_value);
        }
    }

}
add_action("woocommerce_cart_calculate_fees", "wdac_check_for_donations_before_cart", 5);

function wdac_embed_js_form_manipulators($campaign_id, $override_value = false){
    if( isset($_GET['wc-ajax']) ){
        return;
    }
    ?>
    <script>
    jQuery(document).ready(function(){
        if (typeof wc_checkout_params !== "undefined"){
            wc_checkout_params['wc_ajax_url'] += "&wdac_donate_campaign_id=<?php echo $campaign_id; ?>";
            <?php
            if($override_value !== false){
                ?>
                wc_checkout_params['wc_ajax_url'] += "&wdac_amount_overide=<?php echo $override_value; ?>";

                <?php
            }
            ?>
        }   
        var current_url = jQuery(".wc-proceed-to-checkout a").attr('href');
        jQuery(".wc-proceed-to-checkout a").attr('href', current_url + "?wdac_donate_campaign_id=<?php echo $campaign_id; echo (isset($override_value) && $override_value !== false) ? "&wdac_amount_overide=" . $override_value : ""; ?>");
    });
    </script>
    <?php
}

function wdac_get_popup_js_script($wdac_global_options = false){
    if(!isset($_GET['wdac_donate_campaign_id'])){
        $scripts = "
            <script>
            jQuery(document).ready(function(){
                setTimeout(function(){
                    jQuery('.wdac_prompt_popup_div').fadeIn('fast');
                }, 2000);

                jQuery('.wdac_prompt_popup_div_close, .wdac_dismiss_button').click(function(){
                    jQuery('.wdac_prompt_popup_div').fadeOut();
                });
            });
            </script>";
        return $scripts;
    }

    return "";
}

function wdac_custom_selection_js_script(){
    $scripts = "
        <script>
        jQuery(document).ready(function(){
            jQuery('#wdac_custom_campaign_select').on('change', function(){
                var wdac_selected_val = jQuery(this).val();
                jQuery('.wdac_description').hide();
                jQuery('#wdac_description_' + wdac_selected_val).show();
                jQuery('#wdac_description_' + wdac_selected_val).css('display', 'block');
                jQuery('.wdac_image').hide();
                jQuery('#wdac_image_' + wdac_selected_val).show();
                jQuery('#wdac_image_' + wdac_selected_val).css('display', 'block');
                jQuery('.wdac_dynamic_button').attr('href', '?wdac_donate_campaign_id=' + wdac_selected_val);

                if(jQuery('#wdac_amount_overide').length){
                    jQuery('.wdac_dynamic_button').attr('href', '?wdac_donate_campaign_id=' + wdac_selected_val + '&wdac_amount_overide=10');

                    var wdac_amount = jQuery('#wdac_amount_overide').val();
                    var wdac_href = jQuery('.wdac_dynamic_button').attr('href');
                    var wdac_href_altered = wdac_href.substr(0, wdac_href.indexOf('wdac_amount_overide=') + 20) + wdac_amount;

                    jQuery('.wdac_dynamic_button').attr('href', wdac_href_altered);
                }
            });

            jQuery('.wdac_radio_selector').click(function(){
                var wdac_radio_selection = jQuery(this).val();
                jQuery('#wdac_custom_campaign_select option:selected').attr('selected',null);
                jQuery('#wdac_custom_campaign_select option[value=' + wdac_radio_selection + ']').attr('selected','selected');
                jQuery('#wdac_custom_campaign_select').trigger('change');
            });
        });
        </script>";
    return $scripts;
}

function wdac_custom_price_js_script(){
     $scripts = "
        <script>
        jQuery(document).ready(function(){
            jQuery('#wdac_amount_overide').on('change', function(){
                var wdac_amount = jQuery(this).val();
                var wdac_href = jQuery('.wdac_dynamic_button').attr('href');
                var wdac_href_altered = wdac_href.substr(0, wdac_href.indexOf('wdac_amount_overide=') + 20) + wdac_amount;

                jQuery('.wdac_dynamic_button').attr('href', wdac_href_altered);
            });
        });
        </script>";
    return $scripts;
}



function wdac_get_popup_styles(){
    $styles = "
        <style>
            .wdac_prompt_popup_div{
                position: fixed !important;
                width: 50%;
                right: 0;
                margin: auto;
                left: 0;
                top: 7%;
                background: #fff;
                padding: 20px;
                box-shadow: 0 3px 6px -4px rgba(0,0,0,0.16), 0 3px 6px rgba(0,0,0,0.23);
                z-index: 999;
            }
            .wdac_prompt_popup_div_close {
                cursor: pointer;
                padding: 10px;
                float: right;
                position: relative;
                top: -15px;
                width: 34px;
                text-align: center;
            }

            .wdac_prompt_popup_div .wdac_image{
                margin-right:auto;
                margin-left:auto;
                margin-top:5px;
                margin-bottom:5px;
            }

            .wdac_button_container {
                text-align: center;
            }

            .wdac_prompt_title {
                font-size: 23px;
                display: block;
                text-align: center;
                color: #ED1C24;
                font-weight:800;
            }

            .wdac_description {
                text-align: center;
            }

            @media screen and (max-width: 550px){
                .wdac_prompt_popup_div {
                    width: 90% !important;
                    top: 5% !important;
                }
            }

            @media screen and (max-width: 1100px){
                .wdac_prompt_popup_div .wdac_image{
                    max-height:15vh !important;
                }
            }
        </style>";

    return $styles;
}

function wdac_get_active_campaign($wdac_global_options = false){
    $campaign_details = false;
    if($wdac_global_options === false){
        $wdac_global_options = wdac_donations_get_global_options();
    }

    $active_campaign = intval($wdac_global_options['wdac_active_campaign']);
    if($active_campaign === 0){
        //Disabled - Already set to false here
    } else if($active_campaign === -1 || $active_campaign === -2){
        //Random
        $campaign_details = wdac_get_random_campaign();

    } else {
        //Selected from list
        $campaign_details = wdac_campaign_get_data($active_campaign);
    }

    return $campaign_details;
}

function wdac_settings_area(){
    ?>
    <div class="wrap">
        <h3><?php _e("Donation Settings"); ?> <?php wdac_get_action_buttons(); ?></h3>
        <?php wdac_donations_check_header(); wdac_donations_action_check(); wdac_donations_table(); ?>
        <br>
        <a id='wdac_add_btn' href="admin.php?page=wdac_primary_settings&action=new" class="button button-primary"><?php _e("Add New"); ?></a>
    </div>
    <?php
}

function wdac_get_action_buttons(){
    if(isset($_GET['action']) && ($_GET['action'] === "reporting" || $_GET['action'] === "reset_donation") ){
        echo "<a href='admin.php?page=wdac_primary_settings' class='button' style='position: relative; top: -5px;left:10px;'>" . __("Back") . "</a>";
    } else {
        echo "<a href='admin.php?page=wdac_primary_settings&action=reporting' class='button' style='position: relative; top: -5px;left:10px;'>" . __("Reporting") . "</a>";
    }
}


function wdac_donations_check_header(){
    if(isset($_POST['wdac_donations_new_add'])){
        wdac_campaign_add_new_insert();
    }

    if(isset($_POST['wdac_donations_edit'])){
        wdac_campaign_edit_update();
    }

    wdac_donations_global_options();

}

function wdac_donations_global_options(){
    wdac_donations_global_options_headers();
    $wdac_global_options = wdac_donations_get_global_options();

    ?>
    <form method="POST" action="" class='wdac_global_settings_form'>
        <table class="widefat striped">

            <tr>
                <td style="width:20%">
                    <strong><?php _e("Global Options"); ?></strong>
                </td>
                <td style="width:80%"></td>
            </tr>

            <tr>
                <td>
                    <label><?php _e("Active Donation Campaign"); ?>:</label>
                </td>
                <td>
                    <?php wdac_output_campaign_selector("wdac_active_campaign", $wdac_global_options); ?> <small><?php _e("Note: allowing the user to select their own campaign will showcase a dropdown box where the client can select a campaign of their choice."); ?></small>
                </td>
            </tr>

            <tr>
                <td>
                    <label><?php _e("Donation Prompt"); ?>:</label>
                </td>
                <td>
                    <input type='text' name='wdac_donate_prefix' value='<?php echo(isset($wdac_global_options['wdac_donate_prefix']) ? $wdac_global_options['wdac_donate_prefix'] : __("Donate to") ); ?>' > <small><?php _e("Active campaign title will be appended to this message"); ?></small>
                </td>
            </tr>

            <tr>
                <td>
                    <label><?php _e("Force Radio Buttons For Campaing Selection"); ?>:</label>
                </td>
                <td>
                    <input type='checkbox' name='wdac_force_radio' <?php echo(isset($wdac_global_options['wdac_force_radio']) && $wdac_global_options['wdac_force_radio'] === '1' ? 'checked' : ''); ?> > <small><?php _e("Only applied if user selection is enabled. By default this will be a dropdown menu"); ?></small>
                </td>
            </tr>

            <tr>
                <td>
                    <label><?php _e("Allow Custom Amount"); ?>:</label>
                </td>
                <td>
                    <input type='checkbox' name='wdac_user_amount' <?php echo(isset($wdac_global_options['wdac_user_amount']) && $wdac_global_options['wdac_user_amount'] === '1' ? 'checked' : ''); ?> > <small><?php _e("Allows user to enter their own amount for donation. Note, standard donation amount will be ignored"); ?></small>
                </td>
            </tr>

            <tr>
                <td>
                    <label><?php _e("Show Description When Upselling"); ?>:</label>
                </td>
                <td>
                    <input type='checkbox' name='wdac_show_description' <?php echo(isset($wdac_global_options['wdac_show_description']) && $wdac_global_options['wdac_show_description'] === '1' ? 'checked' : ''); ?> >
                </td>
            </tr>

            <tr>
                <td>
                    <label><?php _e("Use Popup"); ?>: </label>
                </td>
                <td>
                    <input type='checkbox' name='wdac_use_popup' <?php echo(isset($wdac_global_options['wdac_use_popup']) && $wdac_global_options['wdac_use_popup'] === '1' ? 'checked' : ''); ?> >

                    <small><?php _e("When not checked this will be added to checkout forms instead"); ?></small>
                </td>
            </tr>

            <tr>
                <td>
                    <input name="wdac_save_global_options" class="button button-primary" value="<?php _e("Save Global Options"); ?>" type="submit">
                </td>
                <td></td>
            </tr>
        </table>
        <br>
    </form>
    <?php
}

function wdac_donations_global_options_headers(){
    if(isset($_POST['wdac_save_global_options'])){
        //User is trying to save
        $wdac_settings_array = array();
        if(isset($_POST['wdac_active_campaign'])){
            $wdac_settings_array['wdac_active_campaign'] = intval($_POST['wdac_active_campaign']);
        } else {
            $wdac_settings_array['wdac_active_campaign'] = 0; //Disabled
        }

        if(isset($_POST['wdac_user_amount'])){
            $wdac_settings_array['wdac_user_amount'] = '1';
        } else {
            $wdac_settings_array['wdac_user_amount'] = '0';
        }

        if(isset($_POST['wdac_force_radio'])){
            $wdac_settings_array['wdac_force_radio'] = '1';
        } else {
            $wdac_settings_array['wdac_force_radio'] = '0';
        }

        if(isset($_POST['wdac_donate_prefix'])){
            $wdac_settings_array['wdac_donate_prefix'] = esc_attr($_POST['wdac_donate_prefix']);
        } else {
            $wdac_settings_array['wdac_donate_prefix'] = __("Donate to");
        }

        if(isset($_POST['wdac_show_description'])){
            $wdac_settings_array['wdac_show_description'] = '1';
        } else {
            $wdac_settings_array['wdac_show_description'] = '0';
        }

        if(isset($_POST['wdac_use_popup'])){
            $wdac_settings_array['wdac_use_popup'] = '1';
        } else {
            $wdac_settings_array['wdac_use_popup'] = '0';
        }

        $wdac_settings_array = apply_filters("wdac_save_global_options_array_filter", $wdac_settings_array);

        update_option("wdac_donations_global_options", maybe_serialize($wdac_settings_array));
    }
}

function wdac_donations_get_global_options(){
    $wdac_settings = get_option("wdac_donations_global_options", false);
    if($wdac_settings === false){
        //Setup defaults
        $wdac_settings = array();
        $wdac_settings['wdac_active_campaign'] = 0; //Disabled
        $wdac_settings['wdac_donate_prefix'] = __("Donate to");

        $wdac_settings = apply_filters("wdac_donations_global_options_array", $wdac_settings);
    } else {
        $wdac_settings = maybe_unserialize($wdac_settings);
    }
    return $wdac_settings;
}

function wdac_output_campaign_selector($name_id, $global_settings, $user_end = false, $return = false){
    $selected_value = isset($global_settings["wdac_active_campaign"]) ? intval($global_settings["wdac_active_campaign"]) : 0; //Disabled by default

    $force_radio = false;
    if(isset($global_settings['wdac_force_radio']) && $global_settings['wdac_force_radio'] === "1"){
        $force_radio = true;
    }

    $selector = "<select name='$name_id' id='$name_id' value='$selected_value' ". ($force_radio ? "style='display:none;'" : "") .">";

    if(!$user_end){
        $selector .=    "<option value='0' " . ($selected_value === 0 ? "selected" : "") . " >" . __("Disabled") . "</option>";
        $selector .=    "<option value='-1' " . ($selected_value === -1 ? "selected" : "") . " >" . __("Random") . "</option>";
        $selector .=    "<option value='-2' " . ($selected_value === -2 ? "selected" : "") . " >" . __("Allow User to Select Campaign") . "</option>";
    }

    $donations = wdac_get_all_donations();
    if( $donations !== false ){
        foreach ($donations as $key => $campaign) {
            $selector .=    "<option value='" . $campaign->id  . "' " . ($selected_value === intval($campaign->id)  ? "selected" : "") . " >" . $campaign->name . "</option>";
        }
    }


    $selector .= "</select>";

    if($force_radio && $donations !== false && $user_end){
        $is_first = true;
        $radio_selector = "";
        foreach ($donations as $key => $campaign) {
            $checked = "";
            if($is_first){
                $checked = "checked='checked'";
                $is_first = false;
            }
            $radio_selector .=    "<input type='radio' class='wdac_radio_selector' name='wdac_radio_override' value='" . $campaign->id  . "' " . $checked . ">" . $campaign->name . " <br>";
        }

        $selector .= $radio_selector;
    }

    if($return){
        return $selector;
    } else {
        echo $selector;
    }
}

function wdac_donations_table(){

    $donations = wdac_get_all_donations();

    echo '<table class="widefat striped wdac_active_campagins">';

    if( $donations !== false ){
        echo "<tr>";
        echo "<td><strong>" . __("ID") . "</strong></td>";
        echo "<td><strong>" . __("Name") . "</strong></td>";
        echo "<td><strong>" . __("Description") . "</strong></td>";
        echo "<td><strong>" . __("Amount") . "</strong></td>";
        echo "<td><strong>" . __("Image") . "</strong></td>";
        echo "<td><strong>" . __("Actions") . "</strong></td>";
        echo "</tr>";
        foreach ($donations as $key => $campaign) {
            echo "<tr>";
            echo "<td>" . $campaign->id . "</td>";
            echo "<td>" . $campaign->name . "</td>";
            echo "<td>" . $campaign->content . "</td>";
            echo "<td>" . $campaign->amount . "</td>";
            echo "<td>" . (isset($campaign->image) && $campaign->image !== NULL ? wdac_thumb_table_image($campaign->image) : "None" ) . "</td>";
            echo "<td>";

            echo "<a href='admin.php?page=wdac_primary_settings&action=edit&id=".$campaign->id."' class='button'>" . __("Edit") . "</a> ";
            echo "<a href='admin.php?page=wdac_primary_settings&action=delete&id=".$campaign->id."' class='button'>" . __("Delete") . "</a>";

            echo "</td>";
            echo "</tr>";
        }

    } else {
        echo "<tr><td>" . __("No Results Found...") . "</td><td></td></tr>";
    }

    echo '</table>';
}

function wdac_get_all_donations(){
    global $wdac_donations_table, $wpdb;
    $results = $wpdb->get_results( "SELECT * FROM $wdac_donations_table" );

    if(count($results) > 0){
        return $results;
    }

    return false;
}

function wdac_get_all_reports(){
    global $wdac_donations_reporting, $wpdb;
    $results = $wpdb->get_results( "SELECT * FROM $wdac_donations_reporting" );

    if(count($results) > 0){
        return $results;
    }

    return false;
}

function wdac_donations_action_check(){
    if(isset($_GET['action'])){
        if($_GET['action'] === "new"){
            wdac_campaign_action_add_new_form();
            wdac_hide_global_form();
        }
        if($_GET['action'] === "edit" && isset($_GET['id'])){
            $donation_id = intval($_GET['id']);
            wdac_campaign_action_edit_form($donation_id);
            wdac_hide_global_form();
        }
        if($_GET['action'] === "delete" && isset($_GET['id'])){
            $donation_id = intval($_GET['id']);
            wdac_campaign_action_delete_prompt($donation_id);
            wdac_hide_global_form();
        }
        if($_GET['action'] === "confirm_delete" && isset($_GET['id'])){
            $donation_id = intval($_GET['id']);
            wdac_campaign_action_delete_confirm($donation_id);
        }

        if($_GET['action'] === "reporting"){
            wdac_display_reporting_table();
            wdac_hide_global_form();
            wdac_hide_active_campaigns();
        }

        if($_GET['action'] === "reset_donation"){
            if(isset($_GET['id'])){
                //Reset this report
                wdac_reset_reporting_for_campagin($_GET['id']);
            }

            wdac_display_reporting_table();
            wdac_hide_global_form();
            wdac_hide_active_campaigns();
        }


    }
}

function wdac_campaign_action_add_new_form(){
    wdac_inject_media_upload_click_handlers();
    ?>
    <form method="POST" action="">
        <table class="widefat striped">
            <tr>
                <td style="width:30%">
                    <strong><?php _e("New Donation Campaing"); ?></strong>
                </td>
                <td style="width:70%"></td>
            </tr>

            <tr>
                <td>
                    <label><?php _e("Name"); ?>:</label>
                </td>
                <td>
                    <input name="wdac_donations_new_name" type="text" style="width:70%;" <?php echo (isset($_POST['wdac_donations_new_name']) ? "value='" . $_POST["wdac_donations_new_name"] . "'" : ""); ?> >
                </td>
            </tr>

             <tr>
                <td>
                    <label><?php _e("Description"); ?>:</label>
                </td>
                <td>
                    <input name="wdac_donations_new_content" type="text"  id="wdac_donations_new_content" style="width:70%;" <?php echo (isset($_POST['wdac_donations_new_content']) ? "value='" . $_POST["wdac_donations_new_content"] . "'" : ""); ?> >
                </td>
            </tr>

            <tr>
                <td>
                    <label><?php _e("Amount"); ?>:</label>
                </td>
                <td>
                    <input name="wdac_donations_new_amount" type="text" id="wdac_donations_new_amount" <?php echo (isset($_POST['wdac_donations_new_amount']) ? "value='" . $_POST["wdac_donations_new_amount"] . "'" : ""); ?>>
                </td>
            </tr>

            <tr>
                <td>
                    <label><?php _e("Image"); ?>:</label>
                </td>
                <td>
                    <?php echo wdac_image_uploader_field("wdac_donation_image", (isset($_POST['wdac_donation_image']) ? $_POST["wdac_donation_image"] : "") ); ?>
                </td>
            </tr>



            <tr>
                <td>
                    <input name="wdac_donations_new_add" class="button button-primary" value="<?php _e("Add Campaign"); ?>" type="submit">
                    <a href="admin.php?page=wdac_primary_settings" class="button"><?php _e("Close"); ?></a>
                </td>
                <td></td>
            </tr>
        </table>
    </form>
    <br>
    <?php
}

function wdac_campaign_action_edit_form($donation_id){
    wdac_inject_media_upload_click_handlers();
    $campaign_data = wdac_campaign_get_data($donation_id);
    if($campaign_data === false){ return false; }
    ?>
    <form method="POST" action="">
        <input name="wdac_donations_edit_id" type="hidden" value="<?php echo $campaign_data->id; ?>" >
        <table class="widefat striped">
            <tr>
                <td style="width:30%">
                    <strong><?php _e("Edit Donations Campaign"); ?></strong>
                </td>
                <td style="width:70%"></td>
            </tr>

            <tr>
                <td>
                    <label><?php _e("Name"); ?>:</label>
                </td>
                <td>
                    <input name="wdac_donations_edit_name" type="text" style="width:70%;" value="<?php echo $campaign_data->name; ?>" >
                </td>
            </tr>

             <tr>
                <td>
                    <label><?php _e("Content"); ?>:</label>
                </td>
                <td>
                    <input name="wdac_donations_edit_content" id="wdac_donations_edit_content" type="text" style="width:70%;" value="<?php echo $campaign_data->content; ?>" >
                </td>
            </tr>

            <tr>
                <td>
                    <label><?php _e("Amount"); ?>:</label>
                </td>
                <td>
                    <input name="wdac_donations_edit_amount" id="wdac_donations_edit_amount" type="text" value="<?php echo $campaign_data->amount; ?>" >
                </td>
            </tr>

            <tr>
                <td>
                    <label><?php _e("Image"); ?>:</label>
                </td>
                <td>
                    <?php echo wdac_image_uploader_field("wdac_donation_image", (isset($campaign_data->image) && $campaign_data->image !== NULL) ? $campaign_data->image : "" ); ?>
                </td>
            </tr>

            <tr>
                <td>
                    <input name="wdac_donations_edit" class="button button-primary" value="<?php _e("Edit Campaign"); ?>" type="submit">
                    <a href="admin.php?page=wdac_primary_settings" class="button"><?php _e("Close"); ?></a>
                </td>
                <td></td>
            </tr>
        </table>
    </form>
    <br>
    <?php
}

function wdac_campaign_action_delete_prompt($donation_id){
    ?>
    <table class="widefat striped">
        <tr>
            <td style="width:30%">
                <strong><?php _e("Are you sure you want to delete this campaign?"); ?></strong>
            </td>
            <td style="width:70%"></td>
        </tr>
        <tr>
            <td>
                <a href="admin.php?page=wdac_primary_settings&action=confirm_delete&id=<?php echo $donation_id; ?>" class="button"><?php _e("Confirm"); ?></a>
                <a href="admin.php?page=wdac_primary_settings" class="button"><?php _e("Cancel"); ?></a>
            </td>
            <td></td>
        </tr>
    </table>
    <br>
    <?php
}

function wdac_campaign_action_delete_confirm($donation_id){
    global $wdac_donations_table, $wpdb;

    $wpdb->query("DELETE FROM `$wdac_donations_table` WHERE `id` = '$donation_id'");

    echo "<div class='error'><p>".__("Campaign Deleted") . "</p></div>";
}

function wdac_campaign_get_data($donation_id){
    global $wdac_donations_table, $wpdb;
    $results = $wpdb->get_results( "SELECT * FROM $wdac_donations_table WHERE `id` = '$donation_id' LIMIT 1" );

    if(count($results) > 0){
        return $results[0];
    }

    return false;
}

function wdac_get_random_campaign(){
    global $wdac_donations_table, $wpdb;
    $results = $wpdb->get_results( "SELECT * FROM $wdac_donations_table ORDER BY RAND() LIMIT 1" );

    if(count($results) > 0){
        return $results[0];
    }

    return false;
}

function wdac_campaign_add_new_insert(){
    global $wdac_donations_table, $wpdb;
    if(isset($_POST['wdac_donations_new_add'])){
        $new_donation_name = isset($_POST['wdac_donations_new_name']) ? esc_attr($_POST['wdac_donations_new_name']) : false;
        $new_donation_content = isset($_POST['wdac_donations_new_content']) ? esc_attr($_POST['wdac_donations_new_content']) : false;
        $new_donation_amount = isset($_POST['wdac_donations_new_amount']) ? floatval($_POST['wdac_donations_new_amount']) : false;
        $donation_image = isset($_POST['wdac_donation_image']) ? esc_attr($_POST['wdac_donation_image']) : '';

        $error_found = false;
        if(!empty($new_donation_name) && $new_donation_name !== false){
            if(!empty($new_donation_content) && $new_donation_content !== false){

                if(!empty($new_donation_amount) && $new_donation_amount !== false){
                    $insert_custom = $wpdb->query(
                        "INSERT INTO `$wdac_donations_table` SET
                        `name` = '$new_donation_name',
                        `content` = '$new_donation_content',
                        `amount` = '$new_donation_amount',
                        `image` = '$donation_image'
                        "
                    );


                    echo "<div class='updated'><p>".__("Success")."</p></div>";
                    unset($_POST);
                } else {
                    //No donations Color
                    $error_found = __("Please select a donation amount");
                }

            } else {
                //No Content
                $error_found = __("Please enter content for your donation");
            }

        } else {
            //No Name error
            $error_found = __("Please enter a name for your donation");
        }

        if($error_found !== false){
             echo "<div class='error'><p>".__("Error").": ".$error_found."</p></div>";
        }
    }
}

function wdac_campaign_edit_update(){
    global $wdac_donations_table, $wpdb;
    if(isset($_POST['wdac_donations_edit'])){
        $new_donations_id = isset($_POST['wdac_donations_edit_id']) ? esc_attr($_POST['wdac_donations_edit_id']) : false;
        $new_donations_name = isset($_POST['wdac_donations_edit_name']) ? esc_attr($_POST['wdac_donations_edit_name']) : false;
        $new_donations_content = isset($_POST['wdac_donations_edit_content']) ? esc_attr($_POST['wdac_donations_edit_content']) : false;
        $new_donations_amount = isset($_POST['wdac_donations_edit_amount']) ? floatval($_POST['wdac_donations_edit_amount']) : false;
        $donation_image = isset($_POST['wdac_donation_image']) ? esc_attr($_POST['wdac_donation_image']) : '';

        $error_found = false;
        if(!empty($new_donations_name) && $new_donations_name !== false){
            if($new_donations_cat !== false){
                if(!empty($new_donations_content) && $new_donations_content !== false){
                    if(!empty($new_donations_amount) && $new_donations_amount !== false){
                        $insert_custom = $wpdb->query(
                            "UPDATE `$wdac_donations_table` SET
                            `name` = '$new_donations_name',
                            `content` = '$new_donations_content',
                            `amount` = '$new_donations_amount',
                            `image` = '$donation_image'

                            WHERE `id` = '$new_donations_id'
                            "
                        );

                        echo "<div class='updated'><p>".__("Success")."</p></div>";
                        unset($_POST);
                    } else {
                        //No donations Color
                        $error_found = __("Please select a donation amount");
                    }
                } else {
                    //No Content
                    $error_found = __("Please enter content for your donation");
                }
            } else {
                //No Cat
                $error_found = __("Please select a product category for your donation");
            }
        } else {
            //No Name error
            $error_found = __("Please enter a name for your donations");
        }

        if($error_found !== false){
             echo "<div class='error'><p>".__("Error").": ".$error_found."</p></div>";
        }
    }
}

function wdac_hide_global_form(){
    ?>
    <style>
    .wdac_global_settings_form{ display: none; }
    </style>
    <?php
}

function wdac_hide_active_campaigns(){
    ?>
    <style>
    .wdac_active_campagins, #wdac_add_btn{ display: none; }
    </style>
    <?php
}

function wdac_inject_media_upload_click_handlers(){
    ?>
    <script>
        jQuery(function($){
            $('body').on('click', '.wdac_upload_image_button', function(e){
                e.preventDefault();
                    var button = $(this),
                        custom_uploader = wp.media({
                    title: 'Insert image',
                    library : {
                        type : 'image'
                    },
                    button: {
                        text: 'Use this image' // button label text
                    },
                    multiple: false // for multiple image selection set to true
                }).on('select', function() { // it also has "open" and "close" events
                    var attachment = custom_uploader.state().get('selection').first().toJSON();
                    $(button).removeClass('button').html('<img class="true_pre_image" src="' + attachment.url + '" style="max-width:30%;display:block;" />').next().val(attachment.id).next().show();
                })
                .open();
            });

            /*
             * Remove image event
             */
            $('body').on('click', '.wdac_remove_image_button', function(){
                $(this).hide().prev().val('').prev().addClass('button').html('Upload image');
                return false;
            });

        });
    </script>
    <?php
}

function wdac_image_uploader_field( $name, $value = '') {
    $image = ' button">Upload image';
    $image_size = 'full'; // it would be better to use thumbnail size here (150x150 or so)
    $display = 'none'; // display state ot the "Remove image" button

    if( $image_attributes = wp_get_attachment_image_src( $value, $image_size ) ) {
        $image = '"><img src="' . $image_attributes[0] . '" style="max-width:30%;display:block;" />';
        $display = 'inline-block';
    }

    return '
    <div>
        <a href="#" class="wdac_upload_image_button' . $image . '</a>
        <input type="hidden" name="' . $name . '" id="' . $name . '" value="' . $value . '" />
        <a href="#" class="wdac_remove_image_button button" style="display:inline-block;display:' . $display . '">Remove image</a>
    </div>';
}

function wdac_include_media_handlers(){
    if(isset($_GET['page']) && $_GET['page'] === "wdac_primary_settings"){
        if(!did_action('wp_enqueue_media')){
            wp_enqueue_media();
        }
    }
}
add_action('admin_enqueue_scripts', 'wdac_include_media_handlers');

function wdac_thumb_table_image($image_id){
    if( $image_attributes = wp_get_attachment_image_src( $image_id,  $image_size = 'thumbnail') ) {
        return "<img src='" . $image_attributes[0] . "' style='max-width:30px; max-height:30px; />'";
    }
    return "None";
}

function wdac_process_order_donations($order_id) {
    global $wpdb, $wdac_donations_reporting;

    $donated_campaign = get_post_meta( $order_id, 'wdac_campaign_id', true );
    $donation_amount = get_post_meta( $order_id, 'wdac_campaign_amount', true );

    if($donated_campaign !== false && $donation_amount !== false){
        $insert_custom = $wpdb->query(
            "INSERT INTO `$wdac_donations_reporting` SET
            `campaign_id` = '$donated_campaign',
            `order_id` = '$order_id',
            `amount` = '$donation_amount'
            "
        );
    }

    return $order_id;
}
add_action('woocommerce_order_status_completed', 'wdac_process_order_donations', 10, 1);

function wdac_order_placed_post_meta($order_id){
    $ref = $_SERVER['HTTP_REFERER'];

    if(strpos($ref, "?") !== FALSE){
        $ref = substr($ref, strpos($ref, "?") + 1);
        $pairs = explode('&', $ref);
        foreach ($pairs as $i) {
            $current_variable = explode('=', $i);
            if(strpos($ref, "=") !== FALSE){
                if(isset($current_variable[0]) && isset($current_variable[1])){
                    $key = $current_variable[0];
                    $value = $current_variable[1];

                    if($key === "wdac_donate_campaign_id"){
                        update_post_meta( $order_id, 'wdac_campaign_id', intval( $value ) );
                        $current_campaign = wdac_campaign_get_data(intval($value));
                        if($current_campaign !== false){
                            if(isset($current_campaign->amount)){
                                update_post_meta( $order_id, 'wdac_campaign_amount', sanitize_text_field( $current_campaign->amount ) );
                            }
                        }

                    } else if ($key === "wdac_amount_overide"){
                        update_post_meta( $order_id, 'wdac_campaign_amount', sanitize_text_field( $value ) );
                    }
                }
            }
        }
    }

}
add_action("woocommerce_thankyou", "wdac_order_placed_post_meta", 99, 1);

function wdac_donation_meta_box( $post ){
    add_meta_box( 'wdac_donation_meta_box', __( 'Donations Made' ), 'wdac_donation_meta_box_build', false, 'side', 'low' );
}
add_action( 'add_meta_boxes_shop_order', 'wdac_donation_meta_box' );

function wdac_donation_meta_box_build( $post ){
    wp_nonce_field( basename( __FILE__ ), 'wdac_donation_meta_box_nonce' );

    $donated_campaign = get_post_meta( $post->ID, 'wdac_campaign_id', true );

    if($donated_campaign !== false){
        $current_campaign = wdac_campaign_get_data(intval($donated_campaign));
        $donation_amount = get_post_meta( $post->ID, 'wdac_campaign_amount', true );
        $donation_title = isset($current_campaign->name) ? $current_campaign->name : "Deleted (ID: " . $donated_campaign . ")";
        $donation_amount = $donation_amount !== false ? $donation_amount : "Unknown Amount";

        echo "<div class='inside'>";
        echo    "<p>";
        echo        "<strong>" . $donation_title . " - " . get_woocommerce_currency_symbol()  . sprintf("%.2f", $donation_amount)  . "</strong> ";
        echo    "</p>";
        echo    "<small>This is used in Donation At Checkout Reporting</small>";
        echo  "</div>";
    }
}


function wdac_display_reporting_table(){
    $reports = wdac_get_all_reports();

    echo '<table class="widefat striped">';

    if( $reports !== false ){
        echo "<tr>";
        echo "<td><strong>" . __("Campaing ID") . "</strong></td>";
        echo "<td><strong>" . __("Name") . "</strong></td>";
        echo "<td><strong>" . __("Amount") . "</strong></td>";
        echo "<td><strong>" . __("Actions") . "</strong></td>";
        echo "</tr>";

        $totals_array = array();
        foreach ($reports as $key => $report) {
            if(isset($report->campaign_id) && isset($report->amount)){
                if(isset($totals_array[$report->campaign_id])){
                    //Already started calculating
                    $current_amount = floatval($totals_array[$report->campaign_id]);
                    $current_amount += floatval($report->amount);
                    $totals_array[$report->campaign_id] = $current_amount;
                } else {
                    $totals_array[$report->campaign_id] = floatval($report->amount);
                }
            }
        }

        foreach ($totals_array as $camapign_id => $campaign_donations) {
            $current_campaign = wdac_campaign_get_data($camapign_id);
            $current_name = "Unknown Campaign";
            if($current_campaign !== false && isset($current_campaign->name)){
                $current_name = $current_campaign->name;
            }

            echo "<tr>";
            echo "<td>" . $camapign_id . "</td>";
            echo "<td>" . $current_name . "</td>";
            echo "<td>" . get_woocommerce_currency_symbol()  . sprintf("%.2f", $campaign_donations) . "</td>";
            echo "<td>";

            echo "<a href='admin.php?page=wdac_primary_settings&action=reset_donation&id=".$camapign_id."' class='button'>" . __("Reset") . "</a> ";
            echo "</td>";
            echo "</tr>";
        }


    } else {
        echo "<tr><td>" . __("No Results Found...") . "</td><td></td></tr>";
    }

    echo '</table>';
}

function wdac_reset_reporting_for_campagin($for_id){
    global $wdac_donations_reporting, $wpdb;

    $for_id = intval($for_id);

    $wpdb->query("DELETE FROM `$wdac_donations_reporting` WHERE `campaign_id` = '$for_id'");

    echo "<div class='error'><p>".__("Campaign Report Reset") . "</p></div>";
}