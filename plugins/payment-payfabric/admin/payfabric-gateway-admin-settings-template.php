<?php

/**
 * Provide a admin area view for the plugin
 *
 *
 * @since      1.0.0
 *
 * @package    PayFabric_Gateway_Woocommerce
 * @subpackage PayFabric_Gateway_Woocommerce/admin
 */
?>

<?php
if (!defined('ABSPATH')) {
    exit;
}

?>
<h3><?php echo wp_kses_post ( $this->method_title ); ?></h3>

<?php echo (!empty($this->method_description)) ? wp_kses_post ( wpautop($this->method_description) ) : ''; ?>

<table class="form-table">
    <?php $this->generate_settings_html(); ?>
</table>


